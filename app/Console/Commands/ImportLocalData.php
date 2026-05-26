<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * One-shot data sync from local dev (SQLite) to production (MySQL on
 * Railway). Reads JSON snapshots committed under
 * `database/import-data/` and upserts each row by primary key, so
 * running this multiple times is safe (idempotent).
 *
 * Image assets are bundled under `database/import-data/storage/` and
 * copied into `storage/app/public/` — which on Railway is the mounted
 * Volume, so the files persist across redeploys.
 *
 * Tables are processed in FK-safe order (parents before children).
 * FK checks are disabled during the load so MySQL's strict mode
 * doesn't reject inserts that reference rows still being loaded a
 * couple of iterations down the list.
 *
 * Tables intentionally omitted: orders / order_lines / order_status_history
 * / order_call_attempts (recently wiped), cart_items / favorites /
 * addresses / blocked_ips / blocked_phones / contact_messages /
 * personal_access_tokens (per-customer ephemeral state).
 */
class ImportLocalData extends Command
{
    protected $signature = 'data:load
                            {--fresh : truncate each target table before loading}';

    protected $description = 'Load committed local-dev data into the current database';

    private const TABLES = [
        'brands',
        'categories',
        'wilayas',
        'communes',
        'site_settings',
        'admins',
        'customers',
        'products',
        'product_variants',
        'product_images',
        'banners',
    ];

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();
        $this->line("DB driver: {$driver}");

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            foreach (self::TABLES as $table) {
                $this->loadTable($table);
            }
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        $this->copyStorageAssets();

        $this->info('Done.');
        return self::SUCCESS;
    }

    private function loadTable(string $table): void
    {
        $path = database_path("import-data/{$table}.json");
        if (! is_file($path)) {
            $this->warn("Skip {$table} — no JSON file at {$path}");
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->error("Skip {$table} — JSON did not decode to an array");
            return;
        }

        if (! $this->isTableEmpty($table) && $this->option('fresh')) {
            DB::table($table)->truncate();
            $this->line("  truncated {$table}");
        }

        $inserted = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $existed = DB::table($table)->where('id', $row['id'] ?? null)->exists();
            DB::table($table)->updateOrInsert(['id' => $row['id'] ?? null], $row);
            $existed ? $updated++ : $inserted++;
        }

        $this->info(sprintf(
            '  %s: %d row(s)  [+%d new, %d updated]',
            str_pad($table, 18),
            count($rows),
            $inserted,
            $updated,
        ));
    }

    private function isTableEmpty(string $table): bool
    {
        return DB::table($table)->count() === 0;
    }

    /**
     * Copy bundled `database/import-data/storage/` into the live
     * storage directory. On Railway this directory is the mounted
     * Volume, so the files survive future redeploys.
     */
    private function copyStorageAssets(): void
    {
        $bundle = database_path('import-data/storage');
        if (! is_dir($bundle)) {
            $this->warn('No bundled storage folder — skipping asset copy.');
            return;
        }
        $dest = storage_path('app/public');
        File::ensureDirectoryExists($dest);
        File::copyDirectory($bundle, $dest);
        $this->info("Storage assets copied to {$dest}");
    }
}
