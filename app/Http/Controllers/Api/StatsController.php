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
    /** Periods the dashboard filter offers, in days. */
    private const RANGES = [7, 30, 90, 365];

    /**
     * GET /api/admin/stats/dashboard?range=7|30|90|365  (default 30)
     *
     * Everything is scoped to the selected window:
     *  - kpis: revenue + delivered orders + AOV (with deltas vs the previous
     *    equal window), conversion rate (delivered / orders received) and return
     *    rate (returned / fulfilled), plus current snapshots (pending, etc.)
     *  - revenueLast30: time series — daily points (<= 90 d) or monthly (1 year)
     *  - statusBreakdown / funnel / topProducts / byWilaya for the window
     */
    public function dashboard(): JsonResponse
    {
        $now = Carbon::now();
        $range = (int) request('range', 30);
        if (! in_array($range, self::RANGES, true)) {
            $range = 30;
        }
        $from = $now->copy()->subDays($range - 1)->startOfDay();
        $prevFrom = $now->copy()->subDays(2 * $range - 1)->startOfDay();

        // Revenue is recognised on delivery only — pending/returned orders must
        // not inflate finance figures. CA reflects the net merchandise value
        // (orders.subtotal), so shipping/delivery fees are excluded:
        // subtotal == total − shipping_fee by construction.
        $deliveredRevenue = fn (Carbon $a, ?Carbon $b = null) => Order::query()
            ->where('status', 'delivered')
            ->where('created_at', '>=', $a)
            ->when($b, fn ($q) => $q->where('created_at', '<', $b));

        $rangeRevenue = (int) $deliveredRevenue($from)->sum('subtotal');
        $rangeOrders = $deliveredRevenue($from)->count();
        $aov = $rangeOrders > 0 ? (int) round($rangeRevenue / $rangeOrders) : 0;
        $prevRevenue = (int) $deliveredRevenue($prevFrom, $from)->sum('subtotal');
        $prevOrders = $deliveredRevenue($prevFrom, $from)->count();

        // Every order placed in the window grouped by status — drives the
        // funnel, the conversion rate and the return rate.
        $statusInRange = DB::table('orders')
            ->where('created_at', '>=', $from)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');
        $sc = fn (string $s) => (int) ($statusInRange[$s] ?? 0);
        $totalInRange = (int) array_sum($statusInRange->all());
        $deliveredC = $sc('delivered');
        $returnedC = $sc('returned');
        $cancelledC = $sc('cancelled');

        // Conversion = delivered / all orders received. Return = returned among
        // the orders that actually reached the customer (delivered + returned).
        $conversionRate = $totalInRange > 0
            ? round($deliveredC / $totalInRange, 4) : 0;
        $returnRate = ($deliveredC + $returnedC) > 0
            ? round($returnedC / ($deliveredC + $returnedC), 4) : 0;

        // Time series: daily for <= 90 days, monthly buckets for a full year so
        // the charts stay readable.
        $series = $range > 90
            ? $this->monthlySeries($now, 12)
            : $this->dailySeries($now, $from, $range);

        // Funnel (cumulative-down): a delivered order was confirmed + shipped
        // earlier, so downstream states sum upward.
        $funnel = [
            ['label' => 'Reçues', 'value' => $sc('pending') + $sc('confirmed') + $sc('preparing') + $sc('shipped') + $deliveredC],
            ['label' => 'Confirmées', 'value' => $sc('confirmed') + $sc('preparing') + $sc('shipped') + $deliveredC],
            ['label' => 'Expédiées', 'value' => $sc('shipped') + $deliveredC],
            ['label' => 'Livrées', 'value' => $deliveredC],
        ];

        return response()->json([
            'kpis' => [
                'rangeDays' => $range,
                'rangeRevenue' => $rangeRevenue,
                'rangeOrders' => $rangeOrders,
                'averageOrderValue' => $aov,
                'prevRevenue' => $prevRevenue,
                'prevOrders' => $prevOrders,
                'conversionRate' => $conversionRate,
                'returnRate' => $returnRate,
                'deliveredOrders' => $deliveredC,
                'returnedOrders' => $returnedC,
                'cancelledOrders' => $cancelledC,
                'totalOrdersInRange' => $totalInRange,
                'pendingOrders' => Order::where('status', 'pending')->count(),
                'totalCustomers' => DB::table('orders')->distinct('customer_phone')->count('customer_phone'),
                'totalProducts' => Product::where('is_active', true)->count(),
                // Back-compat aliases for any caller still reading month*.
                'monthRevenue' => $rangeRevenue,
                'monthOrders' => $rangeOrders,
            ],
            // Keep the key name `revenueLast30` for client back-compat; it now
            // holds the series for the selected window.
            'revenueLast30' => array_values($series),
            'statusBreakdown' => (object) $statusInRange->all(),
            'topProducts' => $this->topProducts($from),
            'topCategories' => $this->topCategories($from),
            'topReturnedProducts' => $this->topReturnedProducts($from),
            'byWilaya' => $this->byWilaya($from),
            'funnel' => array_values($funnel),
        ]);
    }

    /** Daily revenue + order count, zero-filled across the window. */
    private function dailySeries(Carbon $now, Carbon $from, int $range): array
    {
        $rows = DB::table('orders')
            ->where('created_at', '>=', $from)
            ->whereNotIn('status', ['cancelled'])
            ->select(
                DB::raw('DATE(created_at) as d'),
                // CA is merchandise only — exclude shipping_fee (Bug #001).
                DB::raw('SUM(subtotal) as revenue'),
                DB::raw('COUNT(*) as orders_count'),
            )
            ->groupBy('d')
            ->get();
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r->d] = $r;
        }
        $series = [];
        for ($i = $range - 1; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->format('Y-m-d');
            $row = $byDay[$date] ?? null;
            $series[] = [
                'date' => $date,
                'revenue' => $row ? (int) $row->revenue : 0,
                'orders' => $row ? (int) $row->orders_count : 0,
            ];
        }
        return $series;
    }

    /** Monthly revenue + order count, zero-filled across the last N months. */
    private function monthlySeries(Carbon $now, int $months): array
    {
        // Group by the first day of each month. DATE_FORMAT is MySQL-only and
        // throws on SQLite (which the local/dev DB uses) — that crash was why
        // the annual filter never rendered (Bug #002). Pick the right month
        // expression per driver so it works on both engines.
        $driver = DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-01', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m-01')";

        $rows = DB::table('orders')
            ->where('created_at', '>=', $now->copy()->subMonths($months - 1)->startOfMonth())
            ->whereNotIn('status', ['cancelled'])
            ->select(
                DB::raw("$monthExpr as d"),
                // CA is merchandise only — exclude shipping_fee (Bug #001).
                DB::raw('SUM(subtotal) as revenue'),
                DB::raw('COUNT(*) as orders_count'),
            )
            ->groupBy('d')
            ->get();
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[(string) $r->d] = $r;
        }
        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = $now->copy()->subMonths($i)->format('Y-m-01');
            $row = $byKey[$key] ?? null;
            $series[] = [
                'date' => $key,
                'revenue' => $row ? (int) $row->revenue : 0,
                'orders' => $row ? (int) $row->orders_count : 0,
            ];
        }
        return $series;
    }

    /** Top 5 products by revenue within the window. */
    private function topProducts(Carbon $from): array
    {
        $rows = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.created_at', '>=', $from)
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
        return $rows->map(function ($r) use ($base) {
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
        })->values()->all();
    }

    /** Top 8 categories by revenue (what customers are buying) in the window. */
    private function topCategories(Carbon $from): array
    {
        return DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->join('products', 'products.id', '=', 'order_lines.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('orders.created_at', '>=', $from)
            ->whereNotIn('orders.status', ['cancelled'])
            ->select(
                'categories.id as cid',
                DB::raw('MAX(categories.name_fr) as name'),
                DB::raw('SUM(order_lines.quantity) as units'),
                DB::raw('SUM(order_lines.total) as revenue'),
            )
            ->groupBy('categories.id')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name ?: 'Sans catégorie',
                'units' => (int) $r->units,
                'revenue' => (int) $r->revenue,
            ])
            ->all();
    }

    /** Top 8 most-returned products (by units) within the window. */
    private function topReturnedProducts(Carbon $from): array
    {
        return DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.created_at', '>=', $from)
            ->where('orders.status', 'returned')
            ->select(
                'order_lines.product_id',
                DB::raw('MAX(order_lines.product_name) as name'),
                DB::raw('SUM(order_lines.quantity) as units'),
                DB::raw('SUM(order_lines.total) as revenue'),
            )
            ->groupBy('order_lines.product_id')
            ->orderByDesc('units')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'productId' => $r->product_id ? (string) $r->product_id : null,
                'name' => (string) $r->name,
                'units' => (int) $r->units,
                'revenue' => (int) $r->revenue,
            ])
            ->all();
    }

    /** Revenue + order count per wilaya within the window (geo heat-grid). */
    private function byWilaya(Carbon $from): array
    {
        return DB::table('orders')
            ->where('created_at', '>=', $from)
            ->whereNotIn('status', ['cancelled'])
            ->select(
                'wilaya_id',
                'wilaya_name',
                // CA + average basket are merchandise only (Bug #001).
                DB::raw('SUM(subtotal) as revenue'),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('AVG(subtotal) as avg_basket'),
            )
            ->groupBy('wilaya_id', 'wilaya_name')
            ->get()
            ->map(fn ($r) => [
                'wilayaCode' => (string) $r->wilaya_id,
                'wilayaName' => (string) $r->wilaya_name,
                'revenue' => (int) $r->revenue,
                'orderCount' => (int) $r->order_count,
                'averageBasket' => (int) round((float) $r->avg_basket),
            ])
            ->values()
            ->all();
    }
}
