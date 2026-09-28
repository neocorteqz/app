<?php
/**
 * ApexNode Backup Runner
 * - Iterates enabled backup_schedules whose (last_run + interval) is due
 * - tars the server work_dir into /var/lib/apexnode/backups/{server_id}/
 * - Optional S3 upload via `aws` CLI (if configured + installed)
 * - Applies retention (deletes old backups beyond N)
 * Runs continuously with a 30s sleep; managed by supervisor.
 */
require_once __DIR__ . '/../app/DB.php';
$config = require __DIR__ . '/../config/config.php';
$STATE_ROOT = rtrim((string)($config['state_root'] ?? '/var/lib/apexnode'), '/');

$BACKUP_ROOT = $STATE_ROOT . '/backups';
@mkdir($BACKUP_ROOT, 0755, true);

function log_line(string $sid, string $line, string $level = 'system'): void {
    DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$sid, $line, $level]);
}

function run_backup(array $schedule, array $server): void {
    global $BACKUP_ROOT, $STATE_ROOT;
    $sid = (int)$server['id'];
    $wd = $server['work_dir'] ?: "$STATE_ROOT/servers/$sid";
    if (!is_dir($wd)) { @mkdir($wd, 0755, true); }
    $stamp = date('Ymd-His');
    $name = "backup-{$stamp}.tar.gz";
    $out_dir = "$BACKUP_ROOT/$sid";
    @mkdir($out_dir, 0755, true);
    $out_path = "$out_dir/$name";

    $bid = DB::insert('backups', [
        'server_id' => $sid, 'name' => $name, 'path' => $out_path,
        'storage' => $schedule['storage'] ?: 'local', 'status' => 'running',
    ]);
    log_line($sid, "[backup] Snapshotting → $name", 'system');

    $cmd = sprintf('tar -czf %s -C %s . 2>&1', escapeshellarg($out_path), escapeshellarg($wd));
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        DB::q('UPDATE backups SET status="failed", error=?, completed_at=NOW() WHERE id=?',
            [substr(implode("\n", $out), 0, 900), $bid]);
        log_line($sid, "[backup] FAILED: ".substr(implode(' ', $out), 0, 200), 'error');
        return;
    }
    $size = filesize($out_path) ?: 0;

    // Optional S3 upload
    $remote = null;
    if (($schedule['storage'] ?? 'local') === 's3'
        && $schedule['s3_bucket'] && $schedule['s3_access_key'] && $schedule['s3_secret_key']) {
        $env = [
            'AWS_ACCESS_KEY_ID=' . escapeshellarg($schedule['s3_access_key']),
            'AWS_SECRET_ACCESS_KEY=' . escapeshellarg($schedule['s3_secret_key']),
        ];
        $endpoint = $schedule['s3_endpoint'] ? '--endpoint-url ' . escapeshellarg($schedule['s3_endpoint']) : '';
        $s3_key = "apexnode/{$sid}/{$name}";
        $push = sprintf(
            '%s aws s3 cp %s s3://%s/%s %s 2>&1',
            implode(' ', $env), escapeshellarg($out_path),
            escapeshellarg($schedule['s3_bucket']), escapeshellarg($s3_key), $endpoint
        );
        exec($push, $out2, $rc2);
        if ($rc2 === 0) {
            $remote = "s3://{$schedule['s3_bucket']}/{$s3_key}";
            @unlink($out_path); // free local space after remote copy
        } else {
            log_line($sid, "[backup] S3 upload failed: " . substr(implode(' ', $out2), 0, 200), 'warn');
        }
    }

    DB::q('UPDATE backups SET status="completed", size_bytes=?, remote_url=?, completed_at=NOW() WHERE id=?',
        [$size, $remote, $bid]);
    log_line($sid, "[backup] Completed ".round($size/1024/1024, 2)."MB", 'system');

    // Retention: keep newest N
    $keep = (int)($schedule['retention'] ?: 7);
    $olds = DB::all('SELECT id, path, remote_url FROM backups WHERE server_id=? AND status IN ("completed","restored") ORDER BY id DESC LIMIT 100 OFFSET ?', [$sid, $keep]);
    foreach ($olds as $o) {
        if (!empty($o['remote_url']) && str_starts_with($o['remote_url'], 's3://') && !empty($schedule['s3_bucket']) && !empty($schedule['s3_access_key']) && !empty($schedule['s3_secret_key'])) {
            $env = 'AWS_ACCESS_KEY_ID=' . escapeshellarg($schedule['s3_access_key']) . ' AWS_SECRET_ACCESS_KEY=' . escapeshellarg($schedule['s3_secret_key']);
            $endpoint = $schedule['s3_endpoint'] ? '--endpoint-url ' . escapeshellarg($schedule['s3_endpoint']) : '';
            exec($env . ' aws s3 rm ' . escapeshellarg($o['remote_url']) . ' ' . $endpoint . ' 2>&1', $deleteOutput, $deleteCode);
            if ($deleteCode !== 0) {
                log_line($sid, '[backup] Retention could not remove remote snapshot ' . basename($o['remote_url']), 'warn');
                continue;
            }
        }
        if ($o['path'] && file_exists($o['path'])) @unlink($o['path']);
        DB::q('DELETE FROM backups WHERE id=?', [$o['id']]);
    }
}

echo "[backup-runner] booted at " . date('c') . "\n";
while (true) {
    try {
        $due = DB::all(
            'SELECT bs.*, s.name AS server_name, s.work_dir, s.id AS sid
             FROM backup_schedules bs
             JOIN servers s ON s.id = bs.server_id
             WHERE bs.enabled = 1
               AND (bs.last_run IS NULL OR bs.last_run < DATE_SUB(NOW(), INTERVAL bs.interval_minutes MINUTE))'
        );
        foreach ($due as $d) {
            $server = DB::one('SELECT * FROM servers WHERE id=?', [$d['sid']]);
            if ($server) {
                run_backup($d, $server);
                DB::q('UPDATE backup_schedules SET last_run=NOW() WHERE id=?', [$d['id']]);
            }
        }
    } catch (Throwable $e) {
        echo "[backup-runner] err: " . $e->getMessage() . "\n";
    }
    sleep(30);
}
