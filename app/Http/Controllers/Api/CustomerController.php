<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * GET /api/admin/customers
     *
     * Aggregates guest-checkout customers from the orders table — we key by
     * phone number (the most reliable identifier for an Algerian COD shop)
     * and roll up total spent, order count, last order date, last wilaya
     * for the list view. Once authenticated customers exist they can be
     * UNIONed in here too.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $sub = DB::table('orders')
            ->selectRaw('
                customer_phone as phone,
                MAX(customer_first_name) as first_name,
                MAX(customer_last_name) as last_name,
                MAX(customer_email) as email,
                COUNT(*) as order_count,
                SUM(total) as total_spent,
                MAX(created_at) as last_order_at,
                MAX(wilaya_id) as wilaya_id,
                MAX(wilaya_name) as wilaya_name
            ')
            ->groupBy('customer_phone');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $sub->where(function ($q) use ($like) {
                $q->where('customer_phone', 'like', $like)
                  ->orWhere('customer_first_name', 'like', $like)
                  ->orWhere('customer_last_name', 'like', $like)
                  ->orWhere('customer_email', 'like', $like);
            });
        }

        $rows = $sub->orderByDesc('last_order_at')->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'phone' => $r->phone,
                'firstName' => $r->first_name,
                'lastName' => $r->last_name,
                'email' => $r->email,
                'orderCount' => (int) $r->order_count,
                'totalSpent' => (int) $r->total_spent,
                'lastOrderAt' => $r->last_order_at,
                'wilayaId' => $r->wilaya_id,
                'wilayaName' => $r->wilaya_name,
            ])->values(),
            'meta' => [
                'total' => $rows->count(),
            ],
        ]);
    }

    /**
     * GET /api/admin/customers/{phone}
     * Phone-keyed lookup with all their orders.
     */
    public function show(string $phone): JsonResponse
    {
        $orders = Order::query()
            ->where('customer_phone', $phone)
            ->orderByDesc('created_at')
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $first = $orders->first();
        return response()->json([
            'data' => [
                'phone' => $first->customer_phone,
                'firstName' => $first->customer_first_name,
                'lastName' => $first->customer_last_name,
                'email' => $first->customer_email,
                'orderCount' => $orders->count(),
                'totalSpent' => (int) $orders->sum('total'),
                'lastOrderAt' => $orders->first()?->created_at?->toIso8601String(),
                'firstOrderAt' => $orders->last()?->created_at?->toIso8601String(),
                'wilayaId' => $first->wilaya_id,
                'wilayaName' => $first->wilaya_name,
                'orders' => $orders->map(fn ($o) => [
                    'id' => (string) $o->id,
                    'orderNumber' => $o->order_number,
                    'status' => $o->status,
                    'total' => (int) $o->total,
                    'createdAt' => $o->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }
}
