<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportLocalData extends Command
{
    protected $signature = 'data:dump';

    protected $description = 'Dump local dev tables to database/import-data/*.json for data:load';

    private const TABLES = [
        'brands',
        'categories',
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
        $dir = database_path('import-data');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach (self::TABLES as $table) {
            $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
            $path = "{$dir}/{$table}.json";
            file_put_contents(
                $path,
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            );
            $this->info(sprintf('  %s: %d row(s) → %s', str_pad($table, 18), count($rows), $path));
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
