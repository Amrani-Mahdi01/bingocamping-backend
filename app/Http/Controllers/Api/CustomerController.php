<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\BlockedIp;
use App\Models\BlockedPhone;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        // Optional ?type=registered|guest filter for the dashboard.
        // Anything else (including "all" or empty) returns both.
        $typeFilter = (string) $request->query('type', '');

        // ── Source 1: aggregated orders, grouped by customer_phone ──
        //
        // Correlated subquery picks the IP of the customer's most
        // recent order. MAX(CASE...) gives us a portable BOOL_OR
        // since SQLite doesn't have one.
        $latestIpSub = "(SELECT o2.customer_ip FROM orders o2 ".
            "WHERE o2.customer_phone = orders.customer_phone ".
            "AND o2.customer_ip IS NOT NULL ".
            "ORDER BY o2.created_at DESC LIMIT 1) as latest_ip";
        $hasAccountSub = "MAX(CASE WHEN orders.customer_id IS NOT NULL ".
            "THEN 1 ELSE 0 END) as has_account";

        $orderRows = DB::table('orders')
            ->selectRaw('
                customer_phone as phone,
                MAX(customer_first_name) as first_name,
                MAX(customer_last_name) as last_name,
                MAX(customer_email) as email,
                COUNT(*) as order_count,
                SUM(total) as total_spent,
                MAX(created_at) as last_order_at,
                MAX(wilaya_id) as wilaya_id,
                MAX(wilaya_name) as wilaya_name,
                '.$latestIpSub.',
                '.$hasAccountSub.'
            ')
            ->groupBy('customer_phone')
            ->get()
            ->keyBy('phone');

        // ── Source 2: every registered customer (even with no
        //    orders yet) so the dashboard sees signups too. ──
        $registered = Customer::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'phone'])
            ->get();

        // ── Merge — registered customer fills in name/email when
        //    the orders row didn't have one; rows with no matching
        //    orders get inserted with zeroed totals.
        //
        //    Phone may be empty on a registered customer who signed up
        //    without filling in their phone field — we still want them
        //    to appear in /admin/customers/accounts, so we synthesize
        //    a unique map key from their customer ID in that case. The
        //    `customerId` field on each row lets the frontend pick a
        //    stable React key + route admin actions for phone-less rows.
        $merged = [];
        foreach ($orderRows as $phone => $r) {
            $merged[$phone] = [
                'phone' => $phone,
                'customerId' => null,
                'firstName' => $r->first_name,
                'lastName' => $r->last_name,
                'email' => $r->email,
                'orderCount' => (int) $r->order_count,
                'totalSpent' => (int) $r->total_spent,
                'lastOrderAt' => $r->last_order_at,
                'wilayaId' => $r->wilaya_id,
                'wilayaName' => $r->wilaya_name,
                'latestIp' => $r->latest_ip,
                'isRegistered' => (bool) $r->has_account,
            ];
        }
        foreach ($registered as $c) {
            $phone = trim((string) ($c->phone ?? ''));
            // Use the phone as the merge key when present (so we attach
            // to any matching order rollup), otherwise synthesize one
            // from the customer ID so phone-less accounts still appear.
            $key = $phone !== '' ? $phone : 'customer:'.$c->id;
            if (isset($merged[$key])) {
                // Trust the registered profile over the snapshot for
                // contact fields (the customer may have updated their
                // name/email since their guest checkout).
                $merged[$key]['firstName'] = $c->first_name ?? $merged[$key]['firstName'];
                $merged[$key]['lastName'] = $c->last_name ?? $merged[$key]['lastName'];
                $merged[$key]['email'] = $c->email ?? $merged[$key]['email'];
                $merged[$key]['customerId'] = $c->id;
                $merged[$key]['isRegistered'] = true;
            } else {
                $merged[$key] = [
                    'phone' => $phone, // empty string is fine for display
                    'customerId' => $c->id,
                    'firstName' => $c->first_name ?? '',
                    'lastName' => $c->last_name ?? '',
                    'email' => $c->email,
                    'orderCount' => 0,
                    'totalSpent' => 0,
                    'lastOrderAt' => null,
                    'wilayaId' => null,
                    'wilayaName' => null,
                    'latestIp' => null,
                    'isRegistered' => true,
                ];
            }
        }

        $data = collect(array_values($merged));

        // ── Search (post-merge so it covers registered-only rows
        //    too) — same fields the SQL search used before. ──
        if ($search !== '') {
            $like = mb_strtolower($search);
            $data = $data->filter(function ($r) use ($like) {
                return str_contains(mb_strtolower((string) $r['phone']), $like)
                    || str_contains(mb_strtolower((string) $r['firstName']), $like)
                    || str_contains(mb_strtolower((string) $r['lastName']), $like)
                    || str_contains(mb_strtolower((string) ($r['email'] ?? '')), $like);
            });
        }

        if ($typeFilter === 'registered') {
            $data = $data->filter(fn ($r) => $r['isRegistered']);
        } elseif ($typeFilter === 'guest') {
            $data = $data->filter(fn ($r) => ! $r['isRegistered']);
        }

        // Tag the blocklist match late so we only hit BlockedIp once.
        $blockedSet = BlockedIp::query()->pluck('ip_address')->flip();
        $data = $data->map(function ($r) use ($blockedSet) {
            $r['ipBlocked'] = $r['latestIp']
                ? $blockedSet->has($r['latestIp'])
                : false;
            return $r;
        });

        // Sort: most recent order first, then registered-but-no-order
        // rows (lastOrderAt=null) at the bottom. Cap at 200.
        $data = $data
            ->sortByDesc(fn ($r) => $r['lastOrderAt'] ?? '0')
            ->take(200)
            ->values();

        return response()->json([
            'data' => $data->values(),
            'meta' => [
                'total' => $data->count(),
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
        $registered = Customer::query()
            ->where('phone', $phone)
            ->first();

        // Neither orders nor a registered account → genuine 404.
        if ($orders->isEmpty() && ! $registered) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $first = $orders->first();
        $latestIp = $orders->pluck('customer_ip')->filter()->first();
        $ipBlocked = $latestIp
            ? BlockedIp::where('ip_address', $latestIp)->exists()
            : false;

        // Profile fields: prefer the registered customer's current
        // info (they may have updated their name/email), fall back to
        // the order snapshot, then to empty.
        $firstName = $registered?->first_name ?? $first?->customer_first_name ?? '';
        $lastName = $registered?->last_name ?? $first?->customer_last_name ?? '';
        $email = $registered?->email ?? $first?->customer_email;

        return response()->json([
            'data' => [
                'phone' => $phone,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email,
                'orderCount' => $orders->count(),
                'totalSpent' => (int) $orders->sum('total'),
                'lastOrderAt' => $orders->first()?->created_at?->toIso8601String(),
                'firstOrderAt' => $orders->last()?->created_at?->toIso8601String(),
                'wilayaId' => $first?->wilaya_id,
                'wilayaName' => $first?->wilaya_name,
                'latestIp' => $latestIp,
                'ipBlocked' => $ipBlocked,
                'isRegistered' => $registered !== null
                    || $orders->whereNotNull('customer_id')->isNotEmpty(),
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

    /**
     * POST /api/admin/customers/{phone}/promote
     *
     * Create a new Admin row for this customer. Admins and customers
     * are separate identity systems (different tables, different
     * Sanctum guards), so "promote" means: take this person's contact
     * info and bootstrap an admin login they can use at /admin/login.
     *
     * Body: { name, email, password }. The acting admin enters the
     * password manually and shares it out-of-band (WhatsApp etc.).
     */
    public function promote(Request $request, string $phone): JsonResponse
    {
        // Look up the matching Customer row (if any) so we can reuse
        // its password hash. Falls back to the order-snapshot info
        // when this phone has only placed guest checkouts.
        $customer = Customer::query()->where('phone', $phone)->first();
        $hasOrders = Order::query()->where('customer_phone', $phone)->exists();
        if (! $customer && ! $hasOrders) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        return $this->createAdminFromRequest($request, $customer);
    }

    /**
     * POST /api/admin/customers/by-id/{customer}/promote
     *
     * Same as promote() but addressed by the customer's id rather
     * than their phone. Used for registered customers who signed up
     * without entering a phone (the phone-route segment can't be
     * empty, so the frontend falls back to this).
     */
    public function promoteById(Request $request, Customer $customer): JsonResponse
    {
        return $this->createAdminFromRequest($request, $customer);
    }

    /**
     * Shared admin-creation logic for both promote routes.
     *
     * When a $customer record exists, we reuse their password hash so
     * the user keeps using their existing storefront credentials to
     * log into the admin too — and the customer-login response is
     * what then hands them an admin token (see CustomerAuthController).
     * Guest-only promotions still require the admin to type a fresh
     * password since there's no existing hash to reuse.
     */
    private function createAdminFromRequest(
        Request $request,
        ?Customer $customer,
    ): JsonResponse {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:160',
                Rule::unique('admins', 'email'),
            ],
            'role' => ['nullable', 'string', 'in:owner,admin,staff'],
        ];
        // Password is only required when we don't have a customer hash
        // to copy from. The frontend hides the input when isRegistered.
        if (! $customer) {
            $rules['password'] = ['required', 'string', 'min:8', 'max:120'];
        } else {
            $rules['password'] = ['nullable', 'string', 'min:8', 'max:120'];
        }

        $data = $request->validate($rules);

        $passwordHash = $customer
            ? $customer->password // already hashed; the cast detects this
            : $data['password'];

        $admin = Admin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $passwordHash,
            'role' => $data['role'] ?? 'admin',
            'is_active' => true,
        ]);

        return response()->json([
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                // Tells the frontend "we copied the customer's hash;
                // no out-of-band password sharing required".
                'sharesCustomerPassword' => $customer !== null,
            ],
        ], 201);
    }

    /**
     * POST /api/admin/customers/{phone}/block-ip
     *
     * Block this customer at BOTH signals: their phone number AND the
     * IP of their most recent order. Phone is the durable identifier
     * (VPN/proxy can swap IPs but the abuser tends to keep their
     * number); IP catches the immediate next attempt. firstOrCreate
     * keeps the call idempotent on each list independently — already-
     * blocked rows are returned unchanged.
     *
     * The endpoint name is "block-ip" for backwards compatibility
     * with anything that calls it, but the behaviour now covers both.
     */
    public function blockIp(Request $request, string $phone): JsonResponse
    {
        // Existence check covers both guest-with-orders and
        // registered-no-orders, like promote().
        $exists = Order::query()->where('customer_phone', $phone)->exists()
            || Customer::query()->where('phone', $phone)->exists();
        if (! $exists) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $latestIp = Order::query()
            ->where('customer_phone', $phone)
            ->whereNotNull('customer_ip')
            ->orderByDesc('created_at')
            ->value('customer_ip');

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $reason = $data['reason'] ?? null;
        $adminId = $request->user()?->id;
        // Phone-keyed group id pairs the two rows in the unified
        // blacklist UI — see OrderController::maybeAutoBlock() for
        // the rationale. Stable across re-blocks of the same phone.
        $groupId = 'phone:'.$phone;

        $phoneBlock = BlockedPhone::firstOrCreate(
            ['phone_number' => $phone],
            [
                'reason' => $reason,
                'block_group_id' => $groupId,
                'blocked_by_admin_id' => $adminId,
            ],
        );

        $ipBlock = $latestIp
            ? BlockedIp::firstOrCreate(
                ['ip_address' => $latestIp],
                [
                    'reason' => $reason,
                    'block_group_id' => $groupId,
                    'blocked_by_admin_id' => $adminId,
                ],
            )
            : null;

        return response()->json([
            'data' => [
                'phone' => [
                    'id' => $phoneBlock->id,
                    'phoneNumber' => $phoneBlock->phone_number,
                    'reason' => $phoneBlock->reason,
                    'blockedAt' => $phoneBlock->created_at?->toIso8601String(),
                ],
                'ip' => $ipBlock ? [
                    'id' => $ipBlock->id,
                    'ipAddress' => $ipBlock->ip_address,
                    'reason' => $ipBlock->reason,
                    'blockedAt' => $ipBlock->created_at?->toIso8601String(),
                ] : null,
            ],
        ], 201);
    }
}
