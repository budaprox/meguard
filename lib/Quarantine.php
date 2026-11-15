<?php
// MEGuard – quarantine: taking a message out of the queue, putting it back, deleting

declare(strict_types=1);

final class Quarantine
{
    /** Moves the message and its queue file to the quarantine. Returns the quarantine path (message file). */
    public static function put(Message $m): string
    {
        $dir = meg_data('quarantine', date('Ymd'));
        meg_ensure_dir($dir);
        $msgDst = meg_path($dir, $m->id);
        $cmdDst = meg_path($dir, preg_replace('/\.MAI$/i', '.CMD', $m->id));
        // take the queue file out first, so MailEnable does not start delivering
        if (is_file($m->cmdPath) && !@rename($m->cmdPath, $cmdDst)) {
            if (!@copy($m->cmdPath, $cmdDst)) throw new RuntimeException('quarantine: copying the command file failed');
            @unlink($m->cmdPath);
        }
        if (!@rename($m->msgPath, $msgDst)) {
            if (!@copy($m->msgPath, $msgDst)) throw new RuntimeException('quarantine: copying the message failed');
            @unlink($m->msgPath);
        }
        return $msgDst;
    }

    /** Puts the message back into MailEnable's SMTP inbound queue. */
    public static function release(string $qpath): void
    {
        $cfg = meg_config();
        $qpath = self::safePath($qpath);
        $cmdSrc = preg_replace('/\.MAI$/i', '.CMD', $qpath);
        if (!is_file($qpath) || !is_file($cmdSrc)) throw new RuntimeException('The quarantine file was not found.');
        $name = basename($qpath);
        $inbound = meg_path($cfg['queues_dir'], 'SMTP', 'Inbound');
        $msgDst = meg_path($inbound, 'Messages', $name);
        $cmdDst = meg_path($inbound, $name);
        if (is_file($msgDst) || is_file($cmdDst)) throw new RuntimeException('A message with this name is already in the queue.');

        // marker header, so the filter does not hold it again
        $data = (string)file_get_contents($qpath);
        self::spool('ham', $qpath);   // Bayes: this was ham (corrects an earlier spam lesson)
        $data = Engine::STATUS_HEADER . ': released; ' . $cfg['server_name'] . '; ' . date('Y-m-d H:i:s') . "\r\n" . $data;
        // the queue file status starts over
        $cmd = (string)file_get_contents($cmdSrc);
        $cmd = preg_replace('/^Status=.*$/m', 'Status=Unsent', $cmd);

        // the message first, then the queue file (as MailEnable documents it)
        if (file_put_contents($msgDst, $data) === false) throw new RuntimeException('Write error: ' . $msgDst);
        if (file_put_contents($cmdDst, $cmd) === false) { @unlink($msgDst); throw new RuntimeException('Write error: ' . $cmdDst); }
        @unlink($qpath);
        @unlink($cmdSrc);
    }

    public static function delete(string $qpath): void
    {
        $qpath = self::safePath($qpath);
        self::spool('spam', $qpath);  // Bayes: a quarantined message deleted by hand is certainly spam
        @unlink($qpath);
        @unlink(preg_replace('/\.MAI$/i', '.CMD', $qpath));
    }

    /** The whole message (read-only, for display) */
    public static function read(string $qpath, int $limit = 200000): ?string
    {
        $qpath = self::safePath($qpath);
        if (!is_file($qpath)) return null;
        $fh = fopen($qpath, 'rb');
        $d = (string)fread($fh, $limit);
        fclose($fh);
        return $d;
    }

    /** Sets a copy aside for Bayes training (tools\bayes-learn.php); at most 5000 files may wait */
    public static function spool(string $kind, string $file): void
    {
        try {
            $dir = meg_data('learn', $kind);
            meg_ensure_dir($dir);
            if (count(glob(meg_path($dir, '*')) ?: []) >= 5000) return;
            @copy($file, meg_path($dir, date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.eml'));
        } catch (Throwable) { /* not critical */ }
    }

    /** Only paths inside the quarantine folder are accepted */
    private static function safePath(string $p): string
    {
        $base = realpath(meg_data('quarantine'));
        $real = realpath($p);
        if ($base === false || $real === false || !str_starts_with(strtolower($real), strtolower($base . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Invalid quarantine path.');
        }
        return $real;
    }

    /** Clean up old quarantine and log entries */
    public static function cleanup(): array
    {
        $cfg = meg_config();
        $removed = 0;
        $limit = date('Ymd', time() - 86400 * (int)$cfg['quarantine_days']);
        foreach (glob(meg_path(meg_data('quarantine'), '*'), GLOB_ONLYDIR) ?: [] as $d) {
            if (basename($d) < $limit) {
                foreach (glob(meg_path($d, '*')) ?: [] as $f) { @unlink($f); $removed++; }
                @rmdir($d);
            }
        }
        $db = Db::get();
        $st = $db->prepare('DELETE FROM messages WHERE ts < ?');
        $st->execute([time() - 86400 * (int)$cfg['log_days']]);
        return ['files' => $removed, 'rows' => $st->rowCount()];
    }
}
