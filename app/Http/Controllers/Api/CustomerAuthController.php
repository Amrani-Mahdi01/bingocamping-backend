<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\VerifiesRecaptcha;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    use VerifiesRecaptcha;

    /**
     * POST /api/auth/register
     * Creates a customer account + returns a fresh API token.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name'       => ['required', 'string', 'max:80'],
            'last_name'        => ['required', 'string', 'max:80'],
            'email'            => ['required', 'string', 'email', 'max:191', Rule::unique('customers', 'email')],
            'phone'            => ['nullable', 'string', 'max:32'],
            'password'         => ['required', 'string', 'min:8', 'max:191'],
            'recaptcha_token'  => ['nullable', 'string'],
        ]);

        // Bot gate — only enforced when RECAPTCHA_SECRET_KEY is set
        // server-side. Same pattern + error shape as OrderController so
        // the frontend can surface the failure under a single `recaptcha`
        // form-error key.
        if (! $this->verifyRecaptcha($data['recaptcha_token'] ?? null, $request->ip())) {
            return response()->json([
                'message' => "Vérification anti-robot échouée. Veuillez réessayer.",
                'errors' => [
                    'recaptcha' => ["Vérification anti-robot échouée."],
                ],
            ], 422);
        }

        $customer = Customer::create([
            'first_name' => trim($data['first_name']),
            'last_name'  => trim($data['last_name']),
            'email'      => strtolower(trim($data['email'])),
            'phone'      => isset($data['phone']) ? trim($data['phone']) : '',
            'password'   => $data['password'], // hashed via model cast
        ]);

        $token = $customer->createToken('customer-session', ['customer']);

        return response()->json(array_merge(
            [
                'token' => $token->plainTextToken,
                'customer' => $this->serialize($customer),
            ],
            // Cover the niche case where a brand-new customer's email
            // already matches an active admin row (e.g. owner registers
            // as a customer to test the storefront). Same dual-token
            // payload as login() so the frontend treats both paths
            // identically.
            $this->maybeAdminPayload($customer->email, $data['password']),
        ), 201);
    }

    /**
     * POST /api/auth/login
     * Validates credentials + returns a fresh API token.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $customer = Customer::where('email', strtolower(trim($data['email'])))->first();

        if (! $customer || ! Hash::check($data['password'], $customer->password)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants invalides.'],
            ]);
        }

        // Single fresh token per session — drop older customer-session tokens.
        $customer->tokens()->where('name', 'customer-session')->delete();
        $token = $customer->createToken('customer-session', ['customer']);

        return response()->json(array_merge(
            [
                'token' => $token->plainTextToken,
                'customer' => $this->serialize($customer),
            ],
            // Dual-identity: when this email is ALSO an active admin
            // (e.g. promoted from /admin/customers), and the same
            // password matches the admin's hash, hand the storefront
            // an admin token too — the user gets the dashboard link
            // without a second login.
            $this->maybeAdminPayload($customer->email, $data['password']),
        ));
    }

    /**
     * POST /api/auth/logout
     * Revokes the bearer token used to make the request.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * GET /api/auth/me
     * Returns the authenticated customer's profile, plus a fresh admin
     * token + admin payload when this customer has been paired to an
     * active admin AFTER the current session was created (i.e. promoted
     * from /admin/customers while logged in). Without this catch-up,
     * the storefront would only learn about the new admin identity on
     * the next full login. Pairing is detected by matching email + a
     * byte-equal password hash — promotions reuse the customer hash
     * (see CustomerController::createAdminFromRequest), so identical
     * hashes mean "same password" without needing the plaintext.
     */
    public function me(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json(array_merge(
            ['customer' => $this->serialize($customer)],
            $this->maybeAdminPayloadByHash($customer),
        ));
    }

    /**
     * GET /api/auth/orders
     *
     * Returns the authenticated customer's full order history, newest
     * first, with line items + status history eager-loaded so the
     * /mes-commandes page can render the whole list in one request.
     * Customers typically have a handful of orders; pagination would
     * add complexity without benefit at this scale.
     */
    public function orders(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with(['lines.variantRef', 'statusHistory'])
            ->orderByDesc('created_at')
            ->get();

        return OrderResource::collection($orders);
    }

    private function serialize(Customer $customer): array
    {
        return [
            'id'          => $customer->id,
            'firstName'   => $customer->first_name,
            'lastName'    => $customer->last_name,
            'email'       => $customer->email,
            'phone'       => $customer->phone,
            'wilayaId'    => $customer->wilaya_id,
            'orderCount'  => (int) $customer->order_count,
            'totalSpent'  => (int) $customer->total_spent,
            'lastOrderAt' => $customer->last_order_at?->toIso8601String(),
        ];
    }

    /**
     * If an active Admin exists for the same email AND the provided
     * password matches their hash, return ['admin_token' => ..., 'admin' => ...]
     * so the storefront login response gives the user both identities
     * at once. Otherwise returns an empty array (silent no-op).
     */
    private function maybeAdminPayload(string $email, string $password): array
    {
        $admin = Admin::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if (! $admin || ! Hash::check($password, $admin->password)) {
            return [];
        }

        return $this->buildAdminPayload($admin);
    }

    /**
     * Hash-equality variant for /me: we don't have the plaintext password
     * at session-resume time, so we compare the admin's stored hash with
     * the customer's stored hash. They match iff the admin row was
     * promoted from this customer (same bcrypt hash copied through).
     * Returns an empty array when no pairing is detected.
     */
    private function maybeAdminPayloadByHash(Customer $customer): array
    {
        $admin = Admin::query()
            ->where('email', $customer->email)
            ->where('is_active', true)
            ->first();

        // hash_equals guards against timing leaks even though the values
        // already live server-side — cheap habit when comparing secrets.
        if (! $admin || ! hash_equals((string) $admin->password, (string) $customer->password)) {
            return [];
        }

        return $this->buildAdminPayload($admin);
    }

    /**
     * Mint a fresh admin-session token + serialise the admin profile.
     * Shared by both /login (post-Hash::check) and /me (post-hash-equals)
     * so the response shape stays identical.
     */
    private function buildAdminPayload(Admin $admin): array
    {
        // Drop older admin-session tokens — single-device admin sessions
        // mirror the customer-session policy at /login.
        $admin->tokens()->where('name', 'admin-session')->delete();
        $adminToken = $admin->createToken('admin-session');

        return [
            'admin_token' => $adminToken->plainTextToken,
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'isActive' => (bool) $admin->is_active,
                'lastSeenAt' => $admin->last_seen_at?->toIso8601String(),
            ],
        ];
    }
}
