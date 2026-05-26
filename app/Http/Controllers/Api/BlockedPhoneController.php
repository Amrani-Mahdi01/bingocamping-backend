<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedPhone;
use App\Models\Order;
use App\Models\OrderStatusEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin CRUD for the phone blocklist used by OrderController::store().
 * Mirrors BlockedIpController. `index` powers the /admin/blocked-phones
 * page; `destroy` is the "Débloquer" button. New blocks come in from
 * the customer row actions / order detail "Bloquer ce client" buttons,
 * AND from the auto-block rule fired by OrderController::updateStatus
 * when a customer crosses the cancelled/returned threshold.
 */
class BlockedPhoneController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = BlockedPhone::query()
            ->with('blockedBy:id,name,email')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'phoneNumber' => $r->phone_number,
                'reason' => $r->reason,
                'blockGroupId' => $r->block_group_id,
                'blockedAt' => $r->created_at?->toIso8601String(),
                'blockedBy' => $r->blockedBy
                    ? [
                        'id' => $r->blockedBy->id,
                        'name' => $r->blockedBy->name,
                        'email' => $r->blockedBy->email,
                    ]
                    : null,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phoneNumber' => ['required', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $block = BlockedPhone::firstOrCreate(
            ['phone_number' => trim($data['phoneNumber'])],
            [
                'reason' => $data['reason'] ?? null,
                'blocked_by_admin_id' => $request->user()?->id,
            ],
        );

        return response()->json([
            'data' => [
                'id' => $block->id,
                'phoneNumber' => $block->phone_number,
                'reason' => $block->reason,
                'blockGroupId' => $block->block_group_id,
                'blockedAt' => $block->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Unblock + clean slate. Deleting a phone block also flips every
     * `returned` order from this customer to `cancelled`, so the
     * auto-block counter (which only tallies `returned`) starts from
     * zero again. The flip is recorded in OrderStatusEntry per order
     * so the audit history stays accurate. Wrapped in a transaction
     * so the row deletion and the status flips commit together —
     * a half-applied unblock would leave the customer locked out.
     */
    public function destroy(BlockedPhone $blockedPhone): JsonResponse
    {
        $phone = $blockedPhone->phone_number;
        $flippedCount = 0;

        DB::transaction(function () use ($blockedPhone, $phone, &$flippedCount) {
            $blockedPhone->delete();
            $orders = Order::query()
                ->where('customer_phone', $phone)
                ->where('status', 'returned')
                ->get();
            foreach ($orders as $o) {
                $o->update(['status' => 'cancelled']);
                OrderStatusEntry::create([
                    'order_id' => $o->id,
                    'status' => 'cancelled',
                    'by' => 'Système',
                    'note' => 'Statut converti automatiquement lors du déblocage du client.',
                ]);
                $flippedCount++;
            }
        });

        return response()->json([
            'message' => 'Unblocked.',
            'cancelledOrders' => $flippedCount,
        ]);
    }
}
