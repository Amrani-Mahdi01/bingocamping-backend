<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\VerifiesRecaptcha;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public submit + admin CRUD for the /contact inbox.
 *
 * The public POST route reuses the shared VerifiesRecaptcha trait,
 * so a missing/invalid token rejects with the same 422 shape the
 * checkout uses — the storefront can surface the error in the same
 * inline-error slot.
 */
class ContactMessageController extends Controller
{
    use VerifiesRecaptcha;

    /**
     * POST /api/contact — public. Stores the message and captures
     * the submitter IP for traceability (matches what we do on order
     * creation). Returns 201 with the new row so the storefront can
     * confirm success.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191'],
            'subject' => ['required', 'string', 'max:191'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'recaptcha_token' => ['nullable', 'string'],
        ]);

        if (! $this->verifyRecaptcha($data['recaptcha_token'] ?? null, $request->ip())) {
            return response()->json([
                'message' => "Vérification anti-robot échouée. Veuillez réessayer.",
                'errors' => [
                    'recaptcha' => ["Vérification anti-robot échouée."],
                ],
            ], 422);
        }

        $msg = ContactMessage::create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'subject' => trim($data['subject']),
            'message' => trim($data['message']),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'data' => $this->serialize($msg),
        ], 201);
    }

    /** GET /api/admin/contact-messages — newest first, capped at 500. */
    public function index(): JsonResponse
    {
        $rows = ContactMessage::query()
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($m) => $this->serialize($m))->values(),
            'meta' => [
                'unread' => ContactMessage::where('is_read', false)->count(),
            ],
        ]);
    }

    /** PATCH /api/admin/contact-messages/{message} — toggle read state. */
    public function update(Request $request, ContactMessage $message): JsonResponse
    {
        $data = $request->validate([
            'isRead' => ['required', 'boolean'],
        ]);
        $message->update([
            'is_read' => $data['isRead'],
            'read_at' => $data['isRead'] ? now() : null,
        ]);
        return response()->json(['data' => $this->serialize($message)]);
    }

    /** DELETE /api/admin/contact-messages/{message} */
    public function destroy(ContactMessage $message): JsonResponse
    {
        $message->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function serialize(ContactMessage $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'email' => $m->email,
            'subject' => $m->subject,
            'message' => $m->message,
            'ip' => $m->ip,
            'isRead' => (bool) $m->is_read,
            'readAt' => $m->read_at?->toIso8601String(),
            'createdAt' => $m->created_at?->toIso8601String(),
        ];
    }
}
