<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Full-database backup AND restore for the admin dashboard.
 *
 * Two flavours of backup:
 *   1. A direct, never-stored download (GET /backup/database).
 *   2. Server-side snapshots kept in storage/app/backups/ that the admin can
 *      list, download, RESTORE (re-import) or delete from the Paramètres page.
 *
 * The dump is a single self-contained `.sql` file (schema + every row of every
 * table) built in pure PHP via PDO — so it works on Hostinger shared hosting
 * where the `mysqldump`/`mysql` binaries and exec() are unavailable.
 *
 * Driver-aware: prod is MySQL (Hostinger), local dev is SQLite, so table
 * discovery, CREATE-statement reconstruction and restore all branch on the
 * active driver.
 *
 * RESTORE is destructive — it drops & recreates every table. To make it always
 * reversible, restore() first writes an `auto-before-restore-*` snapshot of the
 * current state, so a regretted or failed restore can be undone.
 */
class BackupController extends Controller
{
    /** Folder (under storage/app) where server-side snapshots live. */
    private const STORE_DIR = 'backups';

    /** Filenames we accept — locked down to block any path traversal. */
    private const NAME_PATTERN = '/^[A-Za-z0-9._-]+\.sql$/';

    // ----------------------------------------------------------------------
    // Direct download — streams a dump straight to the browser, never stored.
    // ----------------------------------------------------------------------

    /** GET /api/admin/backup/database */
    public function database(): StreamedResponse
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $dbName = (string) $connection->getDatabaseName();
        $filename = $this->dumpFilename('bingo-backup', $dbName);

        return response()->streamDownload(function () use ($connection, $driver, $dbName) {
            $out = fopen('php://output', 'w');
            $this->writeDump($out, $connection, $driver, $dbName);
            fflush($out);
        }, $filename, [
            'Content-Type' => 'application/sql; charset=utf-8',
            // Stop nginx/Hostinger from buffering the whole stream before sending.
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    // ----------------------------------------------------------------------
    // Server-side snapshots — list / create / download / restore / delete.
    // ----------------------------------------------------------------------

    /** GET /api/admin/backups — list the stored snapshots, newest first. */
    public function index(): JsonResponse
    {
        $dir = $this->backupDir();
        $entries = @scandir($dir) ?: [];

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! str_ends_with($entry, '.sql')) {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($path)) {
                continue;
            }
            $size = (int) filesize($path);
            $mtime = (int) filemtime($path);
            $files[] = [
                'name' => $entry,
                'size' => $size,
                'sizeHuman' => $this->humanSize($size),
                'createdAt' => Carbon::createFromTimestamp($mtime)->toIso8601String(),
                // Snapshots taken automatically before a restore are flagged so
                // the UI can label them differently from manual backups.
                'auto' => str_starts_with($entry, 'auto-'),
                '_ts' => $mtime,
            ];
        }

        usort($files, fn ($a, $b) => $b['_ts'] <=> $a['_ts']);
        $files = array_map(function ($f) {
            unset($f['_ts']);

            return $f;
        }, $files);

        return response()->json(['data' => array_values($files)]);
    }

    /** POST /api/admin/backups — take a fresh snapshot and store it. */
    public function store(): JsonResponse
    {
        $path = $this->generateBackupFile('bingo-backup');

        return response()->json([
            'message' => 'Sauvegarde créée.',
            'data' => $this->fileMeta($path),
        ], 201);
    }

    /** GET /api/admin/backups/{name} — download one stored snapshot. */
    public function download(string $name): StreamedResponse
    {
        $path = $this->resolveBackupPath($name);
        $base = basename($path);

        return response()->streamDownload(function () use ($path) {
            $fp = fopen($path, 'rb');
            fpassthru($fp);
            fclose($fp);
        }, $base, [
            'Content-Type' => 'application/sql; charset=utf-8',
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * POST /api/admin/backups/{name}/restore — re-import a snapshot.
     *
     * Destructive: drops & recreates every table from the file. A safety
     * snapshot of the CURRENT state is written first so this is reversible.
     */
    public function restore(string $name): JsonResponse
    {
        $path = $this->resolveBackupPath($name);

        // Snapshot the current state BEFORE we overwrite it.
        $safety = $this->generateBackupFile('auto-before-restore');

        try {
            $result = $this->importSqlFile($path);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => "La restauration a échoué. L'état précédent a été "
                    .'sauvegardé automatiquement : '.basename($safety),
                'error' => $e->getMessage(),
                'safetyBackup' => basename($safety),
            ], 500);
        }

        return response()->json([
            'message' => 'Base de données restaurée avec succès.',
            'data' => [
                'restoredFrom' => basename($path),
                'statements' => $result['statements'],
                'safetyBackup' => basename($safety),
            ],
        ]);
    }

    /** DELETE /api/admin/backups/{name} — remove a stored snapshot. */
    public function destroy(string $name): JsonResponse
    {
        $path = $this->resolveBackupPath($name);
        @unlink($path);

        return response()->json(['message' => 'Sauvegarde supprimée.']);
    }

    // ----------------------------------------------------------------------
    // Dump generation (shared by the direct download and the stored snapshots)
    // ----------------------------------------------------------------------

    /** Write a complete dump of the database to an open stream/file handle. */
    private function writeDump($out, Connection $connection, string $driver, string $dbName): void
    {
        @set_time_limit(0);
        $pdo = $connection->getPdo();
        $tables = $this->tableNames($connection, $driver);
        $isMysql = in_array($driver, ['mysql', 'mariadb'], true);

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
    }

    /** Generate a dump and persist it under storage/app/backups/. */
    private function generateBackupFile(string $prefix): string
    {
        @set_time_limit(0);
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $dbName = (string) $connection->getDatabaseName();

        $dir = $this->backupDir();
        // Drop the .sql extension so we can de-duplicate on the rare
        // same-second collision (manual backup + auto safety snapshot).
        $stem = preg_replace('/\.sql$/', '', $this->dumpFilename($prefix, $dbName));
        $path = $dir.DIRECTORY_SEPARATOR.$stem.'.sql';
        $n = 1;
        while (file_exists($path)) {
            $n++;
            $path = $dir.DIRECTORY_SEPARATOR.$stem."-{$n}.sql";
        }

        $out = @fopen($path, 'w');
        if ($out === false) {
            abort(500, "Impossible d'écrire le fichier de sauvegarde sur le serveur.");
        }
        try {
            $this->writeDump($out, $connection, $driver, $dbName);
        } finally {
            fclose($out);
        }

        return $path;
    }

    // ----------------------------------------------------------------------
    // Restore (re-import a .sql file)
    // ----------------------------------------------------------------------

    /**
     * Execute every statement in a dump file against the live connection.
     *
     * @return array{statements:int}
     */
    private function importSqlFile(string $path): array
    {
        @set_time_limit(0);
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $pdo = $connection->getPdo();
        $isSqlite = $driver === 'sqlite';

        $sql = @file_get_contents($path);
        if ($sql === false) {
            abort(500, 'Lecture du fichier de sauvegarde impossible.');
        }

        $statements = $this->splitSqlStatements($sql);
        $executed = 0;

        if ($isSqlite) {
            // SQLite: FK enforcement must be toggled OUTSIDE a transaction, and
            // its DDL is transactional — so wrap the whole import for atomicity.
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->beginTransaction();
            try {
                foreach ($statements as $stmt) {
                    $this->execOrFail($pdo, $stmt);
                    $executed++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->exec('PRAGMA foreign_keys = ON');
                throw $e;
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            // MySQL/MariaDB: the dump brackets its data with
            // SET FOREIGN_KEY_CHECKS=0/1. DDL auto-commits, so a transaction
            // can't protect us — the pre-restore safety snapshot does.
            foreach ($statements as $stmt) {
                $this->execOrFail($pdo, $stmt);
                $executed++;
            }
        }

        return ['statements' => $executed];
    }

    private function execOrFail(PDO $pdo, string $statement): void
    {
        if ($pdo->exec($statement) === false) {
            $info = $pdo->errorInfo();
            throw new \RuntimeException('Échec SQL : '.($info[2] ?? 'erreur inconnue'));
        }
    }

    /**
     * Split a dump into individual statements.
     *
     * Quote-aware so values containing ';', newlines, '--' or comment markers
     * never split a statement mid-way. Handles single-quoted strings with BOTH
     * backslash escapes (MySQL's PDO::quote) and '' doubling (SQLite), backtick
     * identifiers, double-dash line comments and slash-star block comments.
     *
     * @return array<int,string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $len = strlen($sql);
        $buf = '';
        $i = 0;
        $inSingle = false;
        $inBacktick = false;

        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inSingle) {
                $buf .= $ch;
                if ($ch === '\\' && $next !== '') {        // backslash escape
                    $buf .= $next;
                    $i += 2;
                    continue;
                }
                if ($ch === "'") {
                    if ($next === "'") {                    // doubled '' escape
                        $buf .= $next;
                        $i += 2;
                        continue;
                    }
                    $inSingle = false;                     // closing quote
                }
                $i++;
                continue;
            }

            if ($inBacktick) {
                $buf .= $ch;
                if ($ch === '`') {
                    if ($next === '`') {
                        $buf .= $next;
                        $i += 2;
                        continue;
                    }
                    $inBacktick = false;
                }
                $i++;
                continue;
            }

            // Outside any string/identifier ----------------------------------
            if ($ch === '-' && $next === '-') {            // line comment
                $j = $i + 2;
                while ($j < $len && $sql[$j] !== "\n") {
                    $j++;
                }
                $i = $j;                                   // leave the \n
                continue;
            }
            if ($ch === '/' && $next === '*') {            // block comment
                $j = $i + 2;
                while ($j < $len && ! ($sql[$j] === '*' && ($sql[$j + 1] ?? '') === '/')) {
                    $j++;
                }
                $i = $j + 2;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                $buf .= $ch;
                $i++;
                continue;
            }
            if ($ch === '`') {
                $inBacktick = true;
                $buf .= $ch;
                $i++;
                continue;
            }
            if ($ch === ';') {                             // statement boundary
                $trimmed = trim($buf);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buf = '';
                $i++;
                continue;
            }

            $buf .= $ch;
            $i++;
        }

        $tail = trim($buf);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    // ----------------------------------------------------------------------
    // Per-table dump helpers
    // ----------------------------------------------------------------------

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

    // ----------------------------------------------------------------------
    // Small utilities
    // ----------------------------------------------------------------------

    private function backupDir(): string
    {
        $dir = storage_path('app'.DIRECTORY_SEPARATOR.self::STORE_DIR);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Validate a requested filename and resolve it inside the backups folder. */
    private function resolveBackupPath(string $name): string
    {
        $base = basename($name);                       // strip any directory part
        if (! preg_match(self::NAME_PATTERN, $base)) {
            abort(404);
        }
        $path = $this->backupDir().DIRECTORY_SEPARATOR.$base;
        if (! is_file($path)) {
            abort(404, 'Sauvegarde introuvable.');
        }

        return $path;
    }

    private function dumpFilename(string $prefix, string $dbName): string
    {
        $label = preg_replace('/[^A-Za-z0-9_.-]/', '', basename($dbName)) ?: 'database';
        $stamp = now()->format('Y-m-d_His');

        return "{$prefix}-{$label}-{$stamp}.sql";
    }

    /** @return array{name:string,size:int,sizeHuman:string,createdAt:string,auto:bool} */
    private function fileMeta(string $path): array
    {
        $name = basename($path);
        $size = (int) filesize($path);

        return [
            'name' => $name,
            'size' => $size,
            'sizeHuman' => $this->humanSize($size),
            'createdAt' => Carbon::createFromTimestamp((int) filemtime($path))->toIso8601String(),
            'auto' => str_starts_with($name, 'auto-'),
        ];
    }

    private function humanSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $n = (float) $bytes;
        $i = 0;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($n, 1)).' '.$units[$i];
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
