<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Backfill: flip is_active=false on every tracked, no-backorder
 * product currently at stock <= 0 and still marked active. Lets
 * the new Product::saving hook take effect across the existing
 * catalogue without waiting for each product to be saved again.
 *
 * Safe to run repeatedly — idempotent.
 */
class DeactivateOutOfStock extends Command
{
    protected $signature = 'products:deactivate-out-of-stock';

    protected $description = 'Set is_active=false on tracked products with stock <= 0';

    public function handle(): int
    {
        $rows = Product::query()
            ->where('track_stock', true)
            ->where('allow_backorder', false)
            ->where('stock', '<=', 0)
            ->where('is_active', true)
            ->get();

        foreach ($rows as $p) {
            $p->is_active = false;
            $p->save();
            $this->line("  deactivated {$p->slug} (stock {$p->stock})");
        }

        $this->info(sprintf('Done — %d product(s) deactivated.', $rows->count()));
        return self::SUCCESS;
    }
}
