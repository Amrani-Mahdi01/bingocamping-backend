<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\VerifiesRecaptcha;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\BlockedIp;
use App\Models\BlockedPhone;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderCallAttempt;
use App\Models\OrderLine;
use App\Models\OrderStatusEntry;
use App\Models\Product;
use App\Models\Wilaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    use VerifiesRecaptcha;

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
        $clientIp = $request->ip();
        // Optional customer auth — the /api/orders route is public so
        // guest checkout still works, but if the storefront sent the
        // customer's Sanctum token we attribute the order to them.
        // Lets /admin/customers tell apart "Compte" from "Invité" AND
        // lets /mes-commandes find the customer's history.
        //
        // We can't use auth('customer')->user() here because the
        // `customer` guard is session-based (config/auth.php) — the
        // storefront sends a Sanctum bearer token instead. So resolve
        // the token manually via Sanctum's PersonalAccessToken model,
        // same pattern OrderController::stream uses for admin SSE auth.
        $customerId = null;
        if ($bearer = $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($bearer);
            $tokenable = $accessToken?->tokenable;
            if ($tokenable instanceof Customer) {
                $customerId = $tokenable->id;
            }
        }

        // Blocklist gate — refuse before any other work (DB scan,
        // recaptcha round-trip, product/wilaya lookups) so a blocked
        // visitor gets a uniform 403 with minimal load. We check BOTH
        // the IP list AND the phone list: VPN/proxy use bypasses IP
        // blocks, but the phone number on the order tends to stay
        // the same across attempts, so phone blocking is the durable
        // signal. Either match is enough to refuse.
        $phone = trim((string) ($data['customer']['phone'] ?? ''));
        $ipBlocked = $clientIp
            ? BlockedIp::where('ip_address', $clientIp)->exists()
            : false;
        $phoneBlocked = $phone !== ''
            ? BlockedPhone::where('phone_number', $phone)->exists()
            : false;
        if ($ipBlocked || $phoneBlocked) {
            return response()->json([
                'message' => "Votre commande ne peut pas être traitée. Contactez le support.",
            ], 403);
        }

        // Bot gate — only enforced when RECAPTCHA_SECRET_KEY is set.
        if (! $this->verifyRecaptcha($data['recaptchaToken'] ?? null, $clientIp)) {
            return response()->json([
                'message' => "Vérification anti-robot échouée. Veuillez réessayer.",
                'errors' => [
                    'recaptchaToken' => ["Vérification anti-robot échouée."],
                ],
            ], 422);
        }

        $wilaya = Wilaya::find($data['shipping']['wilayaId']);
        if (! $wilaya) {
            return response()->json(['message' => 'Invalid wilaya.'], 422);
        }

        $slugs = array_map(fn ($l) => $l['productSlug'], $data['lines']);
        $products = Product::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->get()
            ->keyBy('slug');

        foreach ($data['lines'] as $line) {
            if (! $products->has($line['productSlug'])) {
                return response()->json([
                    'message' => "Un des produits n'est plus disponible.",
                ], 422);
            }
        }

        $order = DB::transaction(function () use ($data, $products, $wilaya, $clientIp, $customerId) {
            $subtotal = 0;
            $linesPayload = [];
            foreach ($data['lines'] as $line) {
                $p = $products->get($line['productSlug']);
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
                'customer_id' => $customerId,
                'customer_first_name' => trim($data['customer']['firstName']),
                'customer_last_name' => trim($data['customer']['lastName']),
                'customer_phone' => trim($data['customer']['phone']),
                'customer_email' => $data['customer']['email'] ?? null,
                'customer_ip' => $clientIp,
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

        // Single blocklist pluck so the row map below stays O(n) instead
        // of firing one BlockedIp::exists() per order.
        $blockedSet = BlockedIp::query()->pluck('ip_address')->flip();

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
                'customerIp' => $o->customer_ip,
                'ipBlocked' => $o->customer_ip
                    ? $blockedSet->has($o->customer_ip)
                    : false,
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

        $oldStatus = $order->status;
        $newStatus = $data['status'];

        DB::transaction(function () use ($order, $data, $byLabel, $oldStatus, $newStatus) {
            $order->update(['status' => $newStatus]);
            OrderStatusEntry::create([
                'order_id' => $order->id,
                'status' => $newStatus,
                'by' => $byLabel,
                'note' => $data['note'] ?? null,
            ]);
            // Inventory move lives inside the same transaction so the
            // status flip and the stock change commit together — a
            // half-applied confirmation would leave stock out of sync
            // with order reality forever.
            $this->adjustStockForStatusChange($order, $oldStatus, $newStatus);
        });

        // Auto-block rule: once a phone has accumulated 3+ orders in
        // the `returned` status, both the phone AND the most-recent
        // IP get added to the blocklists. Returned (not cancelled) is
        // the abuse signal — cancellations often happen for legitimate
        // reasons (customer changed their mind, admin couldn't reach
        // them by phone) and shouldn't penalise the customer; returns
        // mean the customer accepted the COD, took delivery, then
        // refused the package — that's the fake-order pattern.
        //
        // Runs outside the transaction so the status update itself
        // is never blocked by blocklist write failures — worst case
        // we miss one auto-block, the admin can still block manually.
        $this->maybeAutoBlock($order, $admin);

        return new OrderResource(
            $order->fresh(['lines', 'statusHistory', 'callAttempts'])
        );
    }

    /**
     * Status values that mean "stock is committed to this order" —
     * confirmée + the three fulfilment states. Every other status
     * (pending / cancelled / returned) means stock is released and
     * available to other customers. Crossing the bucket boundary
     * triggers a decrement / increment on each line's product.
     */
    private const RESERVED_STATUSES = ['confirmed', 'preparing', 'shipped', 'delivered'];

    private function statusReservesStock(string $status): bool
    {
        return in_array($status, self::RESERVED_STATUSES, true);
    }

    /**
     * Move stock for each line of this order when the status flip
     * crosses the reserved↔released boundary. No-op when the new
     * status sits in the same bucket as the old one (e.g.
     * preparing → shipped: both already reserved → no change).
     *
     * Skips products with `track_stock = false` (infinite-stock
     * items like services or made-to-order) and lines whose
     * product_id is null (product deleted after the order — we
     * can't safely guess which row to adjust).
     *
     * Negative stock is allowed on decrement: it signals "oversold,
     * the admin shouldn't have confirmed this many" and surfaces
     * via the existing stockStatus() = out_of_stock check. Better
     * to flag the problem than silently clamp at zero.
     */
    private function adjustStockForStatusChange(
        Order $order,
        string $oldStatus,
        string $newStatus,
    ): void {
        if ($oldStatus === $newStatus) return;
        $wasReserved = $this->statusReservesStock($oldStatus);
        $isReserved = $this->statusReservesStock($newStatus);
        if ($wasReserved === $isReserved) return;

        $lines = $order->lines()
            ->whereNotNull('product_id')
            ->get(['id', 'product_id', 'quantity']);

        foreach ($lines as $line) {
            $product = Product::find($line->product_id);
            if (! $product || ! $product->track_stock) continue;
            $qty = (int) $line->quantity;
            if ($qty <= 0) continue;
            if ($isReserved) {
                $product->decrement('stock', $qty);
            } else {
                $product->increment('stock', $qty);
            }
        }
    }

    /**
     * Check whether the customer behind this order has crossed the
     * abuse threshold (3+ orders in `returned` status). If so,
     * idempotently add their phone + last-known IP to the blocklists.
     * firstOrCreate keeps repeated triggers cheap.
     */
    private function maybeAutoBlock(Order $order, $admin): void
    {
        if ($order->status !== 'returned') {
            return;
        }
        $phone = trim((string) $order->customer_phone);
        if ($phone === '') return;

        $badCount = Order::query()
            ->where('customer_phone', $phone)
            ->where('status', 'returned')
            ->count();
        if ($badCount < 3) return;

        $reason = "Auto-bloqué : {$badCount} commandes retournées";
        $adminId = $admin?->id;
        // Shared group id so the /admin/blocked-ips list can render
        // the phone + IP pair as a single row with one "Débloquer"
        // button that lifts both. Stable across re-blocks: same phone
        // always uses the same group_id, so a future re-block of the
        // same customer still groups under one row.
        $groupId = 'phone:'.$phone;

        BlockedPhone::firstOrCreate(
            ['phone_number' => $phone],
            [
                'reason' => $reason,
                'block_group_id' => $groupId,
                'blocked_by_admin_id' => $adminId,
            ],
        );

        // Also block the IP attached to THIS order (most recent
        // touchpoint). Older orders may carry different IPs from
        // earlier attempts, but the freshest one is the most useful
        // signal for stopping the immediate next attempt.
        if ($order->customer_ip) {
            BlockedIp::firstOrCreate(
                ['ip_address' => $order->customer_ip],
                [
                    'reason' => $reason,
                    'block_group_id' => $groupId,
                    'blocked_by_admin_id' => $adminId,
                ],
            );
        }
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

    /**
     * GET /api/admin/orders/stream?token=<sanctum-token>
     *
     * Server-Sent Events feed of the pending-orders count. Replaces
     * polling so the admin tab title + sidebar badge update instantly
     * even when the tab has been heavily throttled by the browser
     * (e.g. customer watching YouTube in another tab).
     *
     * Auth: token must be passed as a query param — EventSource on the
     * client can't send custom headers, so the standard `auth:sanctum`
     * middleware can't sit in front of this route. We validate against
     * personal_access_tokens manually here.
     *
     * The connection auto-closes after ~2 s. EventSource on the
     * client reconnects immediately and the cycle repeats — this is
     * effectively long-polling over SSE. The short window keeps the
     * dev server responsive on Windows (PHP's built-in server is
     * single-threaded there, so longer connections would block other
     * admin/customer requests for the connection duration). In prod
     * behind PHP-FPM this constraint doesn't apply, but the 2 s ceiling
     * still bounds worker pool exhaustion under heavy admin load.
     */
    public function stream(Request $request): StreamedResponse
    {
        $token = (string) $request->query('token', '');
        $accessToken = $token !== '' ? PersonalAccessToken::findToken($token) : null;
        $admin = $accessToken?->tokenable;
        $isAdmin = $admin instanceof \App\Models\Admin;

        return new StreamedResponse(function () use ($isAdmin) {
            // Allow the stream to outlive the default PHP request limit.
            @set_time_limit(0);
            // Disable output buffering so events flush immediately.
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            // If auth failed, emit an error event and close. EventSource
            // will reconnect; if the token's still bad it'll just keep
            // closing — caller should give up on persistent 401s.
            if (! $isAdmin) {
                echo "event: error\n";
                echo "data: ".json_encode(['message' => 'Unauthenticated'])."\n\n";
                @flush();
                return;
            }

            $startUs = (int) (microtime(true) * 1_000_000);
            $maxDurationUs = 2_000_000; // 2 s — short window, fast reconnect
            $pollIntervalUs = 250_000;  // 0.25 s — ~4 DB checks/connection
            $lastCount = -1;

            while (((int) (microtime(true) * 1_000_000)) - $startUs < $maxDurationUs) {
                if (connection_aborted()) {
                    return;
                }
                try {
                    $count = Order::where('status', 'pending')->count();
                } catch (\Throwable $e) {
                    Log::warning('SSE pending-count query failed', [
                        'error' => $e->getMessage(),
                    ]);
                    $count = $lastCount;
                }

                // Emit on the first iteration AND on every change so
                // the client gets the current value the moment it
                // connects (then only deltas after).
                if ($count !== $lastCount) {
                    echo "data: ".json_encode(['pending' => $count])."\n\n";
                    @flush();
                    $lastCount = $count;
                }

                usleep($pollIntervalUs);
            }

            // Signal a graceful close so the client knows to reconnect.
            echo "event: close\n";
            echo "data: bye\n\n";
            @flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            // Disables nginx buffering — required for SSE to stream
            // chunks to the client instead of waiting for connection
            // close. Harmless when not behind nginx.
            'X-Accel-Buffering' => 'no',
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
