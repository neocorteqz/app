<?php
namespace App\Controllers;
use DB;

class Backups {
    private function server(int $id): array {
        $s = DB::one('SELECT * FROM servers WHERE id=?', [$id]);
        if (!$s) { http_response_code(404); \view('errors/404'); exit; }
        return $s;
    }
    private function applyRetention(int $serverId, int $keep): void {
        $keep = max(1, $keep);
        $olds = DB::all('SELECT id, path FROM backups WHERE server_id=? AND status IN ("completed","restored") ORDER BY id DESC LIMIT 1000 OFFSET ?', [$serverId, $keep]);
        foreach ($olds as $old) {
            if ($old['path'] && is_file($old['path'])) @unlink($old['path']);
            DB::q('DELETE FROM backups WHERE id=?', [$old['id']]);
        }
    }
    private function removeTree(string $path): void {
        if (!is_dir($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) $this->removeTree($child);
            else @unlink($child);
        }
        @rmdir($path);
    }
    private function validateArchive(string $archive): ?string {
        exec('tar -tzf ' . escapeshellarg($archive) . ' 2>&1', $members, $listCode);
        if ($listCode !== 0) return 'Backup archive is corrupt or unreadable.';
        foreach ($members as $member) {
            $member = trim($member);
            if (str_starts_with($member, '/') || in_array('..', explode('/', $member), true)) {
                return 'Backup contains an unsafe path and cannot be restored.';
            }
        }
        exec('tar -tvzf ' . escapeshellarg($archive) . ' 2>&1', $details, $detailCode);
        if ($detailCode !== 0) return 'Backup archive could not be fully verified.';
        foreach ($details as $entry) {
            if (preg_match('/^[lh]/', $entry)) return 'Backup contains links and cannot be restored safely.';
        }
        return null;
    }
    public function index(int $id) {
        \require_server_permission($id, 'view_backups');
        $s = $this->server($id);
        $sched = DB::one('SELECT id, server_id, interval_minutes, retention, storage, s3_bucket, s3_endpoint, s3_access_key, enabled, last_run FROM backup_schedules WHERE server_id=?', [$id]) ?: [
            'interval_minutes'=>1440,'retention'=>7,'storage'=>'local','enabled'=>0,
            's3_bucket'=>'','s3_endpoint'=>'','s3_access_key'=>'','s3_secret_key'=>'',
            'last_run'=>null,
        ];
        $backups = DB::all('SELECT * FROM backups WHERE server_id=? ORDER BY id DESC LIMIT 30', [$id]);
        \view('backups/index', ['title'=>'Backups — '.$s['name'],'s'=>$s,'sched'=>$sched,'backups'=>$backups]);
    }

    public function saveSchedule(int $id) {
        \check_csrf(); \require_server_permission($id, 'manage_backups');
        $s = $this->server($id);
        $existing = DB::one('SELECT s3_secret_key FROM backup_schedules WHERE server_id=?', [$id]);
        $postedSecret = (string)($_POST['s3_secret_key'] ?? '');
        $data = [
            'server_id' => $id,
            'interval_minutes' => max(5, (int)($_POST['interval_minutes'] ?? 1440)),
            'retention' => max(1, (int)($_POST['retention'] ?? 7)),
            'storage' => ($_POST['storage'] ?? 'local') === 's3' ? 's3' : 'local',
            's3_bucket' => trim($_POST['s3_bucket'] ?? ''),
            's3_endpoint' => trim($_POST['s3_endpoint'] ?? ''),
            's3_access_key' => trim($_POST['s3_access_key'] ?? ''),
            's3_secret_key' => $postedSecret !== '' ? $postedSecret : ($existing['s3_secret_key'] ?? ''),
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
        ];
        DB::q('INSERT INTO backup_schedules (server_id, interval_minutes, retention, storage, s3_bucket, s3_endpoint, s3_access_key, s3_secret_key, enabled)
               VALUES (?,?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE interval_minutes=VALUES(interval_minutes), retention=VALUES(retention),
               storage=VALUES(storage), s3_bucket=VALUES(s3_bucket), s3_endpoint=VALUES(s3_endpoint),
               s3_access_key=VALUES(s3_access_key), s3_secret_key=VALUES(s3_secret_key), enabled=VALUES(enabled)',
            [$id, $data['interval_minutes'], $data['retention'], $data['storage'],
             $data['s3_bucket'], $data['s3_endpoint'], $data['s3_access_key'], $data['s3_secret_key'], $data['enabled']]);
        \log_activity('save-backup-schedule','server:'.$s['name']);
        \flash('success','Backup schedule saved.');
        \redirect("/servers/$id/backups");
    }

    public function runNow(int $id) {
        \check_csrf(); \require_server_permission($id, 'manage_backups');
        $s = $this->server($id);
        $wd = $s['work_dir'] ?: "/var/lib/apexnode/servers/$id";
        if (!is_dir($wd)) @mkdir($wd, 0755, true);
        $out_dir = "/var/lib/apexnode/backups/$id";
        @mkdir($out_dir, 0755, true);
        $stamp = date('Ymd-His');
        $name = "backup-{$stamp}.tar.gz";
        $out = "$out_dir/$name";
        $bid = DB::insert('backups', ['server_id'=>$id,'name'=>$name,'path'=>$out,'status'=>'running','storage'=>'local']);
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, "[backup] Manual snapshot → $name", 'system']);
        $cmd = sprintf('tar -czf %s -C %s . 2>&1', escapeshellarg($out), escapeshellarg($wd));
        exec($cmd, $lines, $rc);
        if ($rc !== 0) {
            DB::q('UPDATE backups SET status="failed", error=?, completed_at=NOW() WHERE id=?', [substr(implode(' ',$lines),0,900), $bid]);
            DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, "[backup] FAILED", 'error']);
            \flash('error','Backup failed: '.substr(implode(' ',$lines),0,200));
        } else {
            DB::q('UPDATE backups SET status="completed", size_bytes=?, completed_at=NOW() WHERE id=?', [filesize($out) ?: 0, $bid]);
            DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, "[backup] Completed", 'system']);
            \log_activity('run-backup','server:'.$s['name'],$name);
            $schedule = DB::one('SELECT retention FROM backup_schedules WHERE server_id=?', [$id]);
            $this->applyRetention($id, (int)($schedule['retention'] ?? 7));
            \flash('success','Backup created.');
        }
        \redirect("/servers/$id/backups");
    }

    public function restore(int $id) {
        \check_csrf(); \require_server_permission($id, 'manage_backups');
        $s = $this->server($id);
        if ($s['status'] !== 'offline') {
            \flash('error','Stop the server before restoring a backup.');
            \redirect("/servers/$id/backups");
        }
        $statusCheck = curl_init("http://127.0.0.1:8001/api/daemon/status/$id");
        curl_setopt_array($statusCheck, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>3]);
        $daemonStatus = curl_exec($statusCheck);
        curl_close($statusCheck);
        if (is_string($daemonStatus) && !empty(json_decode($daemonStatus, true)['running'])) {
            \flash('error','Stop the running server process before restoring a backup.');
            \redirect("/servers/$id/backups");
        }
        if (($_POST['confirm_restore'] ?? '') !== 'RESTORE') {
            \flash('error','Type RESTORE to confirm replacing this server’s files.');
            \redirect("/servers/$id/backups");
        }
        $bid = (int)($_POST['backup_id'] ?? 0);
        $b = DB::one('SELECT * FROM backups WHERE id=? AND server_id=?', [$bid, $id]);
        $backupRoot = realpath("/var/lib/apexnode/backups/$id");
        $archive = $b && $b['storage'] === 'local' ? realpath($b['path']) : false;
        if (!$b || !$backupRoot || !$archive || !str_starts_with($archive, $backupRoot . DIRECTORY_SEPARATOR) || !is_file($archive)) {
            \flash('error','Local backup is missing or outside the server backup directory.');
            \redirect("/servers/$id/backups");
        }
        if ($problem = $this->validateArchive($archive)) {
            \flash('error',$problem);
            \redirect("/servers/$id/backups");
        }
        $wd = $s['work_dir'] ?: "/var/lib/apexnode/servers/$id";
        $serversRoot = realpath('/var/lib/apexnode/servers');
        $parent = dirname($wd);
        if (!$serversRoot || !str_starts_with($wd, $serversRoot . DIRECTORY_SEPARATOR) || $wd === $serversRoot) {
            \flash('error','Server working directory is outside the managed server root.');
            \redirect("/servers/$id/backups");
        }
        @mkdir($parent, 0755, true);
        $stage = $parent . '/.restore-' . $id . '-' . bin2hex(random_bytes(6));
        $previous = $parent . '/.previous-' . $id . '-' . bin2hex(random_bytes(6));
        @mkdir($stage, 0755, true);
        exec(sprintf('tar --no-same-owner --no-same-permissions -xzf %s -C %s 2>&1', escapeshellarg($archive), escapeshellarg($stage)), $out, $rc);
        if ($rc !== 0) {
            $this->removeTree($stage);
            \flash('error','Restore extraction failed: '.substr(implode(' ',$out),0,200));
            \redirect("/servers/$id/backups");
        }
        $movedCurrent = !file_exists($wd) || rename($wd, $previous);
        $installedStage = $movedCurrent && rename($stage, $wd);
        if ($installedStage) {
            if (file_exists($previous)) $this->removeTree($previous);
            DB::q('UPDATE backups SET status="restored" WHERE id=?', [$bid]);
            DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, "[backup] Restored from ".$b['name'], 'system']);
            \log_activity('restore-backup','server:'.$s['name'],$b['name']);
            \flash('success','Restored — restart the server to use the new files.');
        } else {
            if (file_exists($previous) && !file_exists($wd)) @rename($previous, $wd);
            $this->removeTree($stage);
            \flash('error','Restore failed while replacing the server files; the previous files were preserved.');
        }
        \redirect("/servers/$id/backups");
    }

    public function delete(int $id) {
        \check_csrf(); \require_server_permission($id, 'manage_backups');
        $s = $this->server($id);
        $bid = (int)($_POST['backup_id'] ?? 0);
        $b = DB::one('SELECT * FROM backups WHERE id=? AND server_id=?', [$bid, $id]);
        if ($b) {
            if ($b['path'] && file_exists($b['path'])) @unlink($b['path']);
            DB::q('DELETE FROM backups WHERE id=?', [$bid]);
        }
        \flash('success','Backup deleted.');
        \redirect("/servers/$id/backups");
    }

    public function download(int $id) {
        \require_server_permission($id, 'view_backups');
        $bid = (int)($_GET['backup_id'] ?? 0);
        $b = DB::one('SELECT * FROM backups WHERE id=? AND server_id=?', [$bid, $id]);
        if (!$b || !file_exists($b['path'])) { http_response_code(404); echo 'Not found'; return; }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="'.basename($b['path']).'"');
        readfile($b['path']);
    }
}
