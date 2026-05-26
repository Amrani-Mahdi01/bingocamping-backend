<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin CRUD for the IP blocklist used by OrderController::store().
 * `index` powers the /admin/blocked-ips page; `destroy` is the
 * "Débloquer" button. New blocks come in via
 * CustomerController::blockIp from the customer row actions.
 */
class BlockedIpController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = BlockedIp::query()
            ->with('blockedBy:id,name,email')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'ipAddress' => $r->ip_address,
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
            'ipAddress' => ['required', 'ip', 'max:45'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $block = BlockedIp::firstOrCreate(
            ['ip_address' => $data['ipAddress']],
            [
                'reason' => $data['reason'] ?? null,
                'blocked_by_admin_id' => $request->user()?->id,
            ],
        );

        return response()->json([
            'data' => [
                'id' => $block->id,
                'ipAddress' => $block->ip_address,
                'reason' => $block->reason,
                'blockGroupId' => $block->block_group_id,
                'blockedAt' => $block->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function destroy(BlockedIp $blockedIp): JsonResponse
    {
        $blockedIp->delete();
        return response()->json(['message' => 'Unblocked.']);
    }
}
