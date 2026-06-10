<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Full-database backup for the admin dashboard.
 *
 * Streams a single self-contained `.sql` dump (schema + every row of every
 * table) that can be re-imported anywhere — phpMyAdmin on Hostinger, a local
 * MySQL, etc. Built in pure PHP via PDO so it works on shared hosting where
 * the `mysqldump` binary / exec() are unavailable.
 *
 * Driver-aware: prod is MySQL (Hostinger), local dev is SQLite, so the table
 * discovery + CREATE-statement reconstruction branches on the active driver.
 * Output is streamed (row cursor + php://output) so a large orders table
 * never has to fit in memory at once.
 */
class BackupController extends Controller
{
    /**
     * GET /api/admin/backup/database
     *
     * Returns a downloadable SQL dump of the entire database.
     */
    public function database(): StreamedResponse
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $pdo = $connection->getPdo();
        $dbName = (string) $connection->getDatabaseName();

        $tables = $this->tableNames($connection, $driver);

        $stamp = now()->format('Y-m-d_His');
        $label = preg_replace('/[^A-Za-z0-9_.-]/', '', basename($dbName)) ?: 'database';
        $filename = "bingo-backup-{$label}-{$stamp}.sql";

        $isMysql = in_array($driver, ['mysql', 'mariadb'], true);

        return response()->streamDownload(function () use ($connection, $driver, $pdo, $dbName, $tables, $isMysql) {
            @set_time_limit(0);
            $out = fopen('php://output', 'w');

            $this->line($out, '-- BINGO — sauvegarde complète de la base de données');
            $this->line($out, '-- Database : '.$dbName);
            $this->line($out, '-- Driver   : '.$driver);
            $this->line($out, '-- Généré le : '.now()->toDateTimeString());
            $this->line($out, '-- Tables    : '.count($tables));
            $this->line($out, '');

            if ($isMysql) {
                $this->line($out, 'SET NAMES utf8mb4;');
                $this->line($out, 'SET FOREIGN_KEY_CHECKS=0;');
            } else {
                $this->line($out, 'PRAGMA foreign_keys=OFF;');
            }
            $this->line($out, '');

            foreach ($tables as $table) {
                $this->dumpTable($out, $connection, $driver, $pdo, $table);
                fflush($out);
            }

            if ($isMysql) {
                $this->line($out, 'SET FOREIGN_KEY_CHECKS=1;');
            } else {
                $this->line($out, 'PRAGMA foreign_keys=ON;');
            }
            $this->line($out, '');
            $this->line($out, '-- Fin de la sauvegarde');

            fflush($out);
        }, $filename, [
            'Content-Type' => 'application/sql; charset=utf-8',
            // Stop nginx/Hostinger from buffering the whole stream before sending.
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Every base table in the current database, in a stable order.
     *
     * @return array<int,string>
     */
    private function tableNames(Connection $connection, string $driver): array
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return array_map(
                fn ($row) => array_values((array) $row)[0],
                $connection->select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')
            );
        }

        // SQLite — skip the engine's internal bookkeeping tables.
        $rows = $connection->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' "
            ."AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        return array_map(fn ($row) => $row->name, $rows);
    }

    private function dumpTable($out, Connection $connection, string $driver, PDO $pdo, string $table): void
    {
        $q = $this->quoteIdent($table);

        $this->line($out, '-- ------------------------------------------------------------');
        $this->line($out, "-- Table : {$table}");
        $this->line($out, '-- ------------------------------------------------------------');
        $this->line($out, "DROP TABLE IF EXISTS {$q};");

        $create = $this->createStatement($connection, $driver, $table);
        if ($create !== null) {
            $this->line($out, rtrim($create, "; \n").';');
        }
        $this->line($out, '');

        $columns = null;
        $count = 0;
        foreach ($connection->table($table)->cursor() as $record) {
            $row = (array) $record;
            if ($columns === null) {
                $columns = array_keys($row);
            }
            $cols = implode(', ', array_map([$this, 'quoteIdent'], $columns));
            $vals = implode(', ', array_map(
                fn ($col) => $this->quoteValue($pdo, $row[$col] ?? null),
                $columns
            ));
            $this->line($out, "INSERT INTO {$q} ({$cols}) VALUES ({$vals});");
            if (++$count % 200 === 0) {
                fflush($out);
            }
        }

        $this->line($out, "-- {$count} ligne(s)");
        $this->line($out, '');
    }

    /** The original CREATE TABLE statement for a table, or null if unavailable. */
    private function createStatement(Connection $connection, string $driver, string $table): ?string
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = (array) $connection->select('SHOW CREATE TABLE '.$this->quoteIdent($table))[0];
            // MySQL keys the column "Create Table"; MariaDB matches.
            return $row['Create Table'] ?? array_values($row)[1] ?? null;
        }

        $rows = $connection->select(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );

        return $rows[0]->sql ?? null;
    }

    /** Backtick-quote an identifier (works for both MySQL and SQLite). */
    private function quoteIdent(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /** SQL literal for a single cell value. */
    private function quoteValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $quoted = $pdo->quote((string) $value);

        // PDO::quote can return false on drivers that don't support it — fall
        // back to a conservative manual escape so the dump never breaks.
        return $quoted === false
            ? "'".str_replace("'", "''", (string) $value)."'"
            : $quoted;
    }

    private function line($out, string $text): void
    {
        fwrite($out, $text."\n");
    }
}
