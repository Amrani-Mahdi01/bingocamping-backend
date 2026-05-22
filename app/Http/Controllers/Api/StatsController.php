<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /**
     * GET /api/admin/stats/dashboard
     *
     * Returns:
     *  - kpis: today/week/month revenue + order counts + AOV
     *  - revenueLast30: [{date, revenue, orders}] for the last 30 days
     *  - statusBreakdown: { status: count }
     *  - topProducts: 5 products with the most lines in the last 30 days
     */
    public function dashboard(): JsonResponse
    {
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $week = $now->copy()->subDays(6)->startOfDay();
        $month = $now->copy()->subDays(29)->startOfDay();

        $kpiQuery = fn (Carbon $from) => Order::query()
            ->where('created_at', '>=', $from)
            ->whereNotIn('status', ['cancelled']);

        $todayRev = (int) $kpiQuery($today)->sum('total');
        $todayCount = $kpiQuery($today)->count();
        $weekRev = (int) $kpiQuery($week)->sum('total');
        $weekCount = $kpiQuery($week)->count();
        $monthRev = (int) $kpiQuery($month)->sum('total');
        $monthCount = $kpiQuery($month)->count();
        $aov = $monthCount > 0 ? (int) round($monthRev / $monthCount) : 0;

        $pending = Order::where('status', 'pending')->count();

        // Daily revenue + order count for the last 30 days, zero-filled.
        // Use the query builder directly so the result is a plain stdClass
        // collection. Eloquent's `pluck(null, 'd')` returns inconsistent
        // shapes on aggregate selects (sometimes null values), which is
        // what triggered the "Undefined property: stdClass::$" crash.
        $rows = DB::table('orders')
            ->where('created_at', '>=', $month)
            ->whereNotIn('status', ['cancelled'])
            ->select(
                DB::raw('DATE(created_at) as d'),
                DB::raw('SUM(total) as revenue'),
                DB::raw('COUNT(*) as orders_count'),
            )
            ->groupBy('d')
            ->get();

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r->d] = $r;
        }

        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->format('Y-m-d');
            $row = $byDay[$date] ?? null;
            $series[] = [
                'date' => $date,
                'revenue' => $row ? (int) $row->revenue : 0,
                'orders' => $row ? (int) $row->orders_count : 0,
            ];
        }

        $statusBreakdown = DB::table('orders')
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $topProducts = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.created_at', '>=', $month)
            ->whereNotIn('orders.status', ['cancelled'])
            ->select(
                'order_lines.product_id',
                DB::raw('MAX(order_lines.product_name) as name'),
                DB::raw('MAX(order_lines.image) as image'),
                DB::raw('SUM(order_lines.quantity) as units'),
                DB::raw('SUM(order_lines.total) as revenue'),
            )
            ->groupBy('order_lines.product_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $base = rtrim((string) config('app.url'), '/');
        $topProducts = $topProducts->map(function ($r) use ($base) {
            $img = $r->image;
            if (is_string($img) && str_starts_with($img, '/storage/')) {
                $img = $base.$img;
            }
            return [
                'productId' => $r->product_id ? (string) $r->product_id : null,
                'name' => $r->name,
                'image' => $img,
                'units' => (int) $r->units,
                'revenue' => (int) $r->revenue,
            ];
        });

        // Revenue + order count per wilaya for the geo heat-grid.
        $byWilaya = DB::table('orders')
            ->where('created_at', '>=', $month)
            ->whereNotIn('status', ['cancelled'])
            ->select(
                'wilaya_id',
                'wilaya_name',
                DB::raw('SUM(total) as revenue'),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('AVG(total) as avg_basket'),
            )
            ->groupBy('wilaya_id', 'wilaya_name')
            ->get()
            ->map(fn ($r) => [
                'wilayaCode' => (string) $r->wilaya_id,
                'wilayaName' => (string) $r->wilaya_name,
                'revenue' => (int) $r->revenue,
                'orderCount' => (int) $r->order_count,
                'averageBasket' => (int) round((float) $r->avg_basket),
            ]);

        // Simple status funnel: pending → confirmed → shipped → delivered.
        $statusCounts = $statusBreakdown->all();
        $pendingC = (int) ($statusCounts['pending'] ?? 0);
        $confirmedC = (int) ($statusCounts['confirmed'] ?? 0);
        $preparingC = (int) ($statusCounts['preparing'] ?? 0);
        $shippedC = (int) ($statusCounts['shipped'] ?? 0);
        $deliveredC = (int) ($statusCounts['delivered'] ?? 0);
        // Cumulative-down funnel: a delivered order was confirmed + shipped
        // earlier, so we sum the downstream states upward.
        $funnel = [
            ['label' => 'Reçues', 'value' => $pendingC + $confirmedC + $preparingC + $shippedC + $deliveredC],
            ['label' => 'Confirmées', 'value' => $confirmedC + $preparingC + $shippedC + $deliveredC],
            ['label' => 'Expédiées', 'value' => $shippedC + $deliveredC],
            ['label' => 'Livrées', 'value' => $deliveredC],
        ];

        return response()->json([
            'kpis' => [
                'todayRevenue' => $todayRev,
                'todayOrders' => $todayCount,
                'weekRevenue' => $weekRev,
                'weekOrders' => $weekCount,
                'monthRevenue' => $monthRev,
                'monthOrders' => $monthCount,
                'averageOrderValue' => $aov,
                'pendingOrders' => $pending,
                'totalCustomers' => DB::table('orders')->distinct('customer_phone')->count('customer_phone'),
                'totalProducts' => Product::where('is_active', true)->count(),
            ],
            // Force collections to JSON arrays so empty results serialize
            // as `[]` (iterable) rather than `{}` (object) on the client.
            'revenueLast30' => array_values($series),
            'statusBreakdown' => (object) $statusBreakdown->all(),
            'topProducts' => $topProducts->values()->all(),
            'byWilaya' => $byWilaya->values()->all(),
            'funnel' => array_values($funnel),
        ]);
    }
}
