<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderCallAttempt;
use App\Models\OrderLine;
use App\Models\OrderStatusEntry;
use App\Models\Product;
use App\Models\Wilaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * POST /api/orders — public (guest checkout).
     *
     * Snapshots product data onto order_lines so editing a product later
     * doesn't mutate historical orders. Computes shipping from the wilaya
     * + sums totals server-side; client-provided totals are ignored.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $wilaya = Wilaya::find($data['shipping']['wilayaId']);
        if (! $wilaya) {
            return response()->json(['message' => 'Invalid wilaya.'], 422);
        }

        $productIds = array_map(fn ($l) => $l['productId'], $data['lines']);
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach ($data['lines'] as $line) {
            if (! $products->has($line['productId'])) {
                return response()->json([
                    'message' => "Un des produits n'est plus disponible.",
                ], 422);
            }
        }

        $order = DB::transaction(function () use ($data, $products, $wilaya) {
            $subtotal = 0;
            $linesPayload = [];
            foreach ($data['lines'] as $line) {
                $p = $products->get($line['productId']);
                $qty = (int) $line['quantity'];
                $unit = (int) $p->price;
                $total = $unit * $qty;
                $subtotal += $total;

                $image = null;
                $primary = $p->images()->where('is_primary', true)->first()
                    ?? $p->images()->orderBy('display_order')->first();
                if ($primary) {
                    $image = $primary->url;
                }

                $linesPayload[] = [
                    'product_id' => $p->id,
                    'product_name' => $p->name_fr,
                    'sku' => $p->sku,
                    'image' => $image,
                    'variant' => $line['variant'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'total' => $total,
                ];
            }

            $shippingFee = (int) $wilaya->shipping_price;
            $total = $subtotal + $shippingFee;

            $orderNumber = self::nextOrderNumber();

            $order = Order::create([
                'order_number' => $orderNumber,
                'customer_id' => null,
                'customer_first_name' => trim($data['customer']['firstName']),
                'customer_last_name' => trim($data['customer']['lastName']),
                'customer_phone' => trim($data['customer']['phone']),
                'customer_email' => $data['customer']['email'] ?? null,
                'wilaya_id' => $wilaya->id,
                'wilaya_name' => $wilaya->name_fr,
                'commune' => trim($data['shipping']['commune']),
                'address' => $data['shipping']['address'] ?? null,
                'notes' => $data['shipping']['notes'] ?? null,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'total' => $total,
                'status' => 'pending',
                'payment_method' => 'cod',
                'payment_status' => 'pending',
            ]);

            foreach ($linesPayload as $row) {
                OrderLine::create(array_merge($row, ['order_id' => $order->id]));
            }

            OrderStatusEntry::create([
                'order_id' => $order->id,
                'status' => 'pending',
                'by' => 'Système',
                'note' => 'Commande créée par le client',
            ]);

            // Bump every product's sold_count by the line quantity so the
            // dashboard statistics + best-sellers reflect new orders.
            foreach ($linesPayload as $row) {
                Product::where('id', $row['product_id'])
                    ->increment('sold_count', $row['quantity']);
            }

            return $order;
        });

        return (new OrderResource(
            $order->load(['lines', 'statusHistory', 'callAttempts'])
        ))->response()->setStatusCode(201);
    }

    /** GET /api/admin/orders — paginated listing for the dashboard. */
    public function indexAdmin(Request $request): JsonResponse
    {
        $q = Order::query()->orderByDesc('created_at');

        if ($search = trim((string) $request->query('q', ''))) {
            $like = '%'.$search.'%';
            $q->where(function ($qq) use ($like) {
                $qq->where('order_number', 'like', $like)
                   ->orWhere('customer_first_name', 'like', $like)
                   ->orWhere('customer_last_name', 'like', $like)
                   ->orWhere('customer_phone', 'like', $like);
            });
        }
        if ($status = $request->query('status')) {
            if ($status !== 'all') {
                $q->where('status', $status);
            }
        }
        if ($wilayaId = $request->query('wilayaId')) {
            $q->where('wilaya_id', $wilayaId);
        }

        $perPage = min((int) $request->query('perPage', 25), 100);
        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn ($o) => [
                'id' => (string) $o->id,
                'orderNumber' => $o->order_number,
                'status' => $o->status,
                'customer' => [
                    'firstName' => $o->customer_first_name,
                    'lastName' => $o->customer_last_name,
                    'phone' => $o->customer_phone,
                ],
                'wilayaId' => $o->wilaya_id,
                'wilayaName' => $o->wilaya_name,
                'commune' => $o->commune,
                'total' => (int) $o->total,
                'createdAt' => $o->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    /** GET /api/admin/orders/{order} */
    public function show(Order $order): OrderResource
    {
        return new OrderResource(
            $order->load(['lines', 'statusHistory', 'callAttempts'])
        );
    }

    /**
     * PATCH /api/admin/orders/{order}/status
     * Body: { status: <one of Order::STATUSES>, note?: string }
     */
    public function updateStatus(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $admin = $request->user();
        $byLabel = method_exists($admin, 'getAttribute')
            ? ($admin->getAttribute('name') ?: $admin->getAttribute('email') ?: 'Admin')
            : 'Admin';

        DB::transaction(function () use ($order, $data, $byLabel) {
            $order->update(['status' => $data['status']]);
            OrderStatusEntry::create([
                'order_id' => $order->id,
                'status' => $data['status'],
                'by' => $byLabel,
                'note' => $data['note'] ?? null,
            ]);
        });

        return new OrderResource(
            $order->fresh(['lines', 'statusHistory', 'callAttempts'])
        );
    }

    /** POST /api/admin/orders/{order}/calls — log a customer-call attempt. */
    public function logCall(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:answered,no_answer,wrong_number,declined'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $admin = $request->user();
        $byLabel = method_exists($admin, 'getAttribute')
            ? ($admin->getAttribute('name') ?: $admin->getAttribute('email') ?: 'Admin')
            : 'Admin';

        OrderCallAttempt::create([
            'order_id' => $order->id,
            'outcome' => $data['outcome'],
            'by' => $byLabel,
            'note' => $data['note'] ?? null,
        ]);

        return new OrderResource(
            $order->fresh(['lines', 'statusHistory', 'callAttempts'])
        );
    }

    /** DELETE /api/admin/orders/{order} */
    public function destroy(Order $order): JsonResponse
    {
        $order->delete();
        return response()->json(['message' => 'Order deleted.']);
    }

    /**
     * GET /api/admin/orders/pending-count
     * Used by the dashboard polling for the red dot + tab title.
     */
    public function pendingCount(): JsonResponse
    {
        return response()->json([
            'pending' => Order::where('status', 'pending')->count(),
        ]);
    }

    /** Generate the next sequential order number, e.g. BIN-2026-00042. */
    private static function nextOrderNumber(): string
    {
        $year = now()->format('Y');
        $count = Order::whereYear('created_at', $year)->count() + 1;
        return sprintf('BIN-%s-%05d', $year, $count);
    }
}
