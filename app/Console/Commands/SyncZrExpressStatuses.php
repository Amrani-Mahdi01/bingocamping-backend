<?php

namespace App\Console\Commands;

use App\Services\ZrExpress\ZrExpressService;
use App\Services\ZrExpress\ZrOrderSync;
use Illuminate\Console\Command;

/**
 * ZR-03 — poll ZR Express for the current state of every active parcel and
 * mirror it onto the local order status. Scheduled in routes/console.php and
 * also runnable on demand. No-op (and no API calls) when the integration is
 * disabled or unconfigured, so it's safe to leave scheduled before the live
 * account is connected.
 */
class SyncZrExpressStatuses extends Command
{
    protected $signature = 'zr:sync-statuses {--limit=500 : Max parcels to poll this run}';

    protected $description = 'Sync order statuses from ZR Express delivery states';

    public function handle(ZrExpressService $zr, ZrOrderSync $sync): int
    {
        if (! $zr->enabled()) {
            $this->info('ZR Express disabled or not configured — nothing to sync.');
            return self::SUCCESS;
        }

        $summary = $sync->syncActive((int) $this->option('limit'));

        foreach ($summary['details'] as $d) {
            if (! empty($d['error'])) {
                $this->warn("  {$d['orderNumber']}: ERROR — {$d['error']}");
            } elseif (! empty($d['changed'])) {
                $this->line("  {$d['orderNumber']}: {$d['zrState']} → {$d['status']}");
            }
        }

        $this->info(sprintf(
            'Done — checked %d, updated %d, errors %d.',
            $summary['checked'],
            $summary['updated'],
            $summary['errors'],
        ));

        return self::SUCCESS;
    }
}
