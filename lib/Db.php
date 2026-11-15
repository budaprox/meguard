<?php
// MEGuard – SQLite database

declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $dir = meg_data();
        meg_ensure_dir($dir);
        $pdo = new PDO('sqlite:' . meg_path($dir, 'meguard.sqlite'), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 15,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 15000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v >= 3) return;
        if ($v === 2) { self::migrate3($pdo); return; }
        if ($v === 1) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_fail (ip TEXT NOT NULL, ts INTEGER NOT NULL);
                        CREATE INDEX IF NOT EXISTS ix_lf ON login_fail(ip, ts);
                        PRAGMA user_version = 2;");
            self::migrate3($pdo);
            return;
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id          INTEGER PRIMARY KEY,
                ts          INTEGER NOT NULL,
                msgfile     TEXT,
                connector   TEXT,
                ip          TEXT,
                auth        INTEGER DEFAULT 0,
                account     TEXT,
                sender      TEXT,
                from_hdr    TEXT,
                recipients  TEXT,
                subject     TEXT,
                size        INTEGER,
                result      TEXT,      -- pass, whitelisted, blacklisted, autoban, spam-low, spam-medium, spam-high, trusted, outgoing, error, skipped
                action      TEXT,      -- pass, tag, quarantine, delete, monitor
                score       REAL,
                sa_score    REAL,
                details     TEXT,      -- JSON
                duration_ms INTEGER,
                qpath       TEXT,      --  quarantine path (if any)
                released    INTEGER DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS ix_msg_ts ON messages(ts);
            CREATE INDEX IF NOT EXISTS ix_msg_ip ON messages(ip);
            CREATE INDEX IF NOT EXISTS ix_msg_sender ON messages(sender);
            CREATE INDEX IF NOT EXISTS ix_msg_result ON messages(result);

            CREATE TABLE IF NOT EXISTS lists (
                id       INTEGER PRIMARY KEY,
                list     TEXT NOT NULL,   -- white | black
                kind     TEXT NOT NULL,   -- sender | recipient | ip
                value    TEXT NOT NULL,
                comment  TEXT DEFAULT '',
                created  INTEGER,
                hits     INTEGER DEFAULT 0,
                last_hit INTEGER,
                UNIQUE(list, kind, value)
            );

            CREATE TABLE IF NOT EXISTS rules (
                id       INTEGER PRIMARY KEY,
                name     TEXT NOT NULL,
                type     TEXT NOT NULL,   -- text | regex | special
                scope    TEXT NOT NULL,   -- subject | body | headers | all
                expr     TEXT NOT NULL,
                score    REAL NOT NULL,
                enabled  INTEGER DEFAULT 1,
                comment  TEXT DEFAULT '',
                created  INTEGER,
                hits     INTEGER DEFAULT 0,
                last_hit INTEGER
            );

            CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT);
            CREATE TABLE IF NOT EXISTS login_fail (ip TEXT NOT NULL, ts INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS ix_lf ON login_fail(ip, ts);
            PRAGMA user_version = 2;
        ");
        self::migrate3($pdo);
    }

    /** Version 3: automatic (expiring) list entries for the "repeat sender" rule */
    private static function migrate3(PDO $pdo): void
    {
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $cols = array_column($pdo->query('PRAGMA table_info(lists)')->fetchAll(), 'name');
            if (!in_array('expires', $cols, true)) $pdo->exec('ALTER TABLE lists ADD COLUMN expires INTEGER');
            if (!in_array('auto', $cols, true)) $pdo->exec('ALTER TABLE lists ADD COLUMN auto INTEGER DEFAULT 0');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_msg_ip_ts ON messages(ip, ts)');
            $pdo->exec('PRAGMA user_version = 3');
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable) { }
        }
    }

    public static function meta(string $k, ?string $set = null): ?string
    {
        $pdo = self::get();
        if ($set !== null) {
            $pdo->prepare('INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $set]);
            return $set;
        }
        $st = $pdo->prepare('SELECT v FROM meta WHERE k=?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }
}
