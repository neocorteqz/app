<?php

namespace App\Controllers;

use DB;

/**
 * File Manager — real file system under the configured ApexNode state directory.
 * All operations are constrained to the server work_dir via realpath comparison.
 */
class Files
{
    private function server(int $id): array
    {
        $s = DB::one('SELECT * FROM servers WHERE id=?', [$id]);
        if (!$s) {
            http_response_code(404);
            \view('errors/404');
            exit;
        }
        return $s;
    }
    private function base(array $s): string
    {
        $rootPath = \apex_state_root() . '/servers';
        $base = $s['work_dir'] ?: ($rootPath . '/' . (int)$s['id']);
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $root = realpath($rootPath);
        $resolved = realpath($base);
        if (!$root || !$resolved || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            http_response_code(500);
            die('Server files are outside the managed root.');
        }
        if (($s['work_dir'] ?? '') !== $resolved) {
            DB::q('UPDATE servers SET work_dir=? WHERE id=?', [$resolved, $s['id']]);
        }
        return $resolved;
    }
    private function resolve(string $base, string $rel): ?string
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR);
        $rel = ltrim($rel, '/');
        $target = $base . '/' . $rel;
        // Normalise
        $parts = [];
        foreach (explode('/', $target) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $p;
        }
        $abs = '/' . implode('/', $parts);
        $prefix = $base . DIRECTORY_SEPARATOR;
        if ($abs !== $base && !str_starts_with($abs, $prefix)) {
            return null;
        }

        $probe = $abs;
        while ($probe !== $base && !file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return null;
            }
            $probe = $parent;
        }
        $resolvedRoot = realpath($base);
        $resolvedProbe = realpath($probe);
        if (!$resolvedRoot || !$resolvedProbe || ($resolvedProbe !== $resolvedRoot && !str_starts_with($resolvedProbe, $resolvedRoot . DIRECTORY_SEPARATOR))) {
            return null;
        }
        if ((file_exists($abs) || is_link($abs))) {
            $resolvedTarget = realpath($abs);
            if (!$resolvedTarget || ($resolvedTarget !== $resolvedRoot && !str_starts_with($resolvedTarget, $resolvedRoot . DIRECTORY_SEPARATOR))) {
                return null;
            }
        }
        return $abs;
    }

    public function index(int $id)
    {
        \require_server_permission($id, 'view_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $path = $_GET['path'] ?? '';
        $abs = $this->resolve($base, $path);
        if (!$abs || !is_dir($abs)) {
            \flash('error', 'Invalid path.');
            \redirect("/servers/$id/files");
        }
        $items = [];
        foreach (scandir($abs) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = "$abs/$f";
            $items[] = [
                'name' => $f, 'is_dir' => is_dir($full),
                'size' => is_file($full) ? filesize($full) : 0,
                'mtime' => filemtime($full),
                'rel' => ltrim(($path ? "$path/" : '') . $f, '/'),
            ];
        }
        usort($items, fn($a, $b) => ($b['is_dir'] <=> $a['is_dir']) ?: strcmp($a['name'], $b['name']));
        \view('files/index', ['title' => 'Files — ' . $s['name'],'s' => $s,'items' => $items,'path' => $path,'base' => $base]);
    }

    public function edit(int $id)
    {
        \require_server_permission($id, 'view_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = $_GET['path'] ?? '';
        $abs = $this->resolve($base, $rel);
        if (!$abs || !is_file($abs)) {
            \flash('error', 'File not found.');
            \redirect("/servers/$id/files");
        }
        $size = filesize($abs);
        if ($size > 512 * 1024) {
            \flash('error', 'File is too large to edit (>512KB).');
            \redirect("/servers/$id/files?path=" . dirname($rel));
        }
        $content = file_get_contents($abs);
        \view('files/edit', ['title' => 'Edit ' . basename($rel),'s' => $s,'rel' => $rel,'content' => $content]);
    }

    public function save(int $id)
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = $_POST['path'] ?? '';
        $abs = $this->resolve($base, $rel);
        if (!$abs) {
            \flash('error', 'Invalid path.');
            \redirect("/servers/$id/files");
        }
        @mkdir(dirname($abs), 0755, true);
        file_put_contents($abs, $_POST['content'] ?? '');
        \log_activity('edit-file', 'server:' . $s['name'], $rel);
        \flash('success', 'Saved ' . basename($rel));
        \redirect("/servers/$id/files/edit?path=" . urlencode($rel));
    }

    public function mkdir(int $id)
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = trim($_POST['path'] ?? '', '/');
        $name = trim($_POST['name'] ?? '');
        if (!$name || preg_match('#[/\\\\]#', $name)) {
            \flash('error', 'Invalid folder name.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        $abs = $this->resolve($base, ($rel ? "$rel/" : '') . $name);
        if ($abs) {
            @mkdir($abs, 0755, true);
        }
        \redirect("/servers/$id/files?path=" . urlencode($rel));
    }

    public function touch(int $id)
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = trim($_POST['path'] ?? '', '/');
        $name = trim($_POST['name'] ?? '');
        if (!$name || preg_match('#[/\\\\]#', $name)) {
            \flash('error', 'Invalid file name.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        $abs = $this->resolve($base, ($rel ? "$rel/" : '') . $name);
        if ($abs && !file_exists($abs)) {
            file_put_contents($abs, '');
        }
        \redirect("/servers/$id/files/edit?path=" . urlencode(($rel ? "$rel/" : '') . $name));
    }

    public function delete(int $id)
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = $_POST['path'] ?? '';
        $abs = $this->resolve($base, $rel);
        if ($abs && $abs !== $base) {
            if (is_dir($abs)) {
                $this->rrmdir($abs);
            } elseif (is_file($abs)) {
                @unlink($abs);
            }
        }
        \flash('success', 'Deleted.');
        \redirect("/servers/$id/files?path=" . urlencode(dirname($rel)));
    }

    public function upload(int $id)
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = trim($_POST['path'] ?? '', '/');
        $abs_dir = $this->resolve($base, $rel);
        if (!$abs_dir || !is_dir($abs_dir)) {
            \flash('error', 'Invalid target folder.');
            \redirect("/servers/$id/files");
        }
        if (empty($_FILES['file']['name'])) {
            \flash('error', 'No file uploaded.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        $safe_name = basename($_FILES['file']['name']);
        if (preg_match('#^\.#', $safe_name)) {
            \flash('error', 'Hidden files not allowed.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        move_uploaded_file($_FILES['file']['tmp_name'], "$abs_dir/$safe_name");
        \log_activity('upload-file', 'server:' . $s['name'], $rel . '/' . $safe_name);
        \flash('success', 'Uploaded ' . $safe_name);
        \redirect("/servers/$id/files?path=" . urlencode($rel));
    }

    public function download(int $id)
    {
        \require_server_permission($id, 'view_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = $_GET['path'] ?? '';
        $abs = $this->resolve($base, $rel);
        if (!$abs || !is_file($abs)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
        readfile($abs);
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "$dir/$f";
            if (is_link($p)) {
                @unlink($p);
            } elseif (is_dir($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
