<?php

namespace App\Http\Resources;

use App\Models\BlockedIp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $ipBlocked = $this->customer_ip
            ? BlockedIp::where('ip_address', $this->customer_ip)->exists()
            : false;
        // This resource is shared with the customer's /mes-commandes feed, so
        // the internal ZR audit fields (parcel id, sync errors) are gated to
        // admin requests. Customers still get their public trackingNumber.
        $isAdmin = $request->user() instanceof \App\Models\Admin;

        return [
            'id' => (string) $this->id,
            'orderNumber' => $this->order_number,
            'status' => $this->status,
            'paymentMethod' => $this->payment_method,
            'paymentStatus' => $this->payment_status,

            'customer' => [
                'firstName' => $this->customer_first_name,
                'lastName' => $this->customer_last_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
                'customerId' => $this->customer_id ? (string) $this->customer_id : null,
            ],
            'shipping' => [
                'wilayaId' => $this->wilaya_id,
                'wilayaName' => $this->wilaya_name,
                'commune' => $this->commune,
                'address' => $this->address,
                'deliveryType' => $this->delivery_type ?? 'home',
                'notes' => $this->notes,
            ],

            'subtotal' => (int) $this->subtotal,
            'shippingFee' => (int) $this->shipping_fee,
            'total' => (int) $this->total,
            'trackingNumber' => $this->tracking_number,
            'cancellationReason' => $this->cancellation_reason,

            // ZR Express delivery integration (admin-only audit fields).
            'zr' => $this->when($isAdmin, fn () => [
                'parcelId' => $this->zr_parcel_id,
                'state' => $this->zr_state,
                'syncedAt' => $this->zr_synced_at?->toIso8601String(),
                'lastError' => $this->zr_last_error,
            ]),
            'customerIp' => $this->customer_ip,
            'ipBlocked' => $ipBlocked,

            'lines' => $this->whenLoaded('lines', function () use ($base) {
                return $this->lines->map(function ($line) use ($base) {
                    $image = $line->image;
                    if (is_string($image) && str_starts_with($image, '/storage/')) {
                        $image = $base.$image;
                    }
                    return [
                        'id' => (string) $line->id,
                        'productId' => $line->product_id ? (string) $line->product_id : null,
                        'productName' => $line->product_name,
                        'sku' => $line->sku,
                        'image' => $image,
                        'variant' => $line->variant,
                        'quantity' => (int) $line->quantity,
                        'unitPrice' => (int) $line->unit_price,
                        'total' => (int) $line->total,
                    ];
                })->values();
            }, []),

            'statusHistory' => $this->whenLoaded('statusHistory', function () {
                return $this->statusHistory->map(fn ($s) => [
                    'id' => (string) $s->id,
                    'status' => $s->status,
                    'by' => $s->by,
                    'note' => $s->note,
                    'at' => $s->at?->toIso8601String(),
                ])->values();
            }, []),

            'callAttempts' => $this->whenLoaded('callAttempts', function () {
                return $this->callAttempts->map(fn ($c) => [
                    'id' => (string) $c->id,
                    'outcome' => $c->outcome,
                    'by' => $c->by,
                    'note' => $c->note,
                    'at' => $c->at?->toIso8601String(),
                ])->values();
            }, []),

            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
