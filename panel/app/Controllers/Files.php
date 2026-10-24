<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * File Manager — real file system under the configured ApexNode state directory.
 *
 * All operations are constrained to the server work_dir via realpath comparison.
 * Uploads are size- and extension-limited; executable/script extensions are
 * refused so uploaded content cannot be interpreted by a web server.
 */
class Files
{
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024; // 100 MB
    private const BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'pht', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'cmd', 'com'];

    /**
     * Fetch a server row or emit 404 and stop.
     *
     * @param int $id Server id.
     * @return array The server row.
     */
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

    /**
     * Resolve and validate the server's working directory, caching it on the row.
     *
     * @param array $s The server row.
     * @return string The real, contained working directory path.
     */
    private function base(array $s): string
    {
        $rootPath = \apex_state_root() . '/servers';
        $base     = $s['work_dir'] ?: ($rootPath . '/' . (int) $s['id']);
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $root      = realpath($rootPath);
        $resolved  = realpath($base);
        if (!$root || !$resolved || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            http_response_code(500);
            die('Server files are outside the managed root.');
        }
        if (($s['work_dir'] ?? '') !== $resolved) {
            DB::q('UPDATE servers SET work_dir=? WHERE id=?', [$resolved, $s['id']]);
        }
        return $resolved;
    }

    /**
     * Resolve a relative path inside the base directory and confirm it stays
     * within the managed root, even through symlinks.
     *
     * @param string $base The managed base directory.
     * @param string $rel  A relative path (may be empty).
     * @return string|null The absolute path inside base, or null when unsafe.
     */
    private function resolve(string $base, string $rel): ?string
    {
        $base  = rtrim($base, DIRECTORY_SEPARATOR);
        $rel   = ltrim($rel, '/');
        $target = $base . '/' . $rel;

        // Collapse . and .. segments.
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
        $abs     = '/' . implode('/', $parts);
        $prefix  = $base . DIRECTORY_SEPARATOR;
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
        $resolvedRoot   = realpath($base);
        $resolvedProbe  = realpath($probe);
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

    /**
     * List the contents of a directory inside a server's files.
     *
     * @param int $id Server id.
     * @return void
     */
    public function index(int $id): void
    {
        \require_server_permission($id, 'view_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $path = (string) ($_GET['path'] ?? '');
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
                'name' => $f,
                'is_dir' => is_dir($full),
                'size' => is_file($full) ? filesize($full) : 0,
                'mtime' => filemtime($full),
                'rel' => ltrim(($path ? "$path/" : '') . $f, '/'),
            ];
        }
        usort($items, fn($a, $b) => ($b['is_dir'] <=> $a['is_dir']) ?: strcmp($a['name'], $b['name']));
        \view('files/index', ['title' => 'Files — ' . $s['name'], 's' => $s, 'items' => $items, 'path' => $path, 'base' => $base]);
    }

    /**
     * Render the file editor for a single file.
     *
     * @param int $id Server id.
     * @return void
     */
    public function edit(int $id): void
    {
        \require_server_permission($id, 'view_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = (string) ($_GET['path'] ?? '');
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
        \view('files/edit', ['title' => 'Edit ' . basename($rel), 's' => $s, 'rel' => $rel, 'content' => $content]);
    }

    /**
     * Save (overwrite) a file's contents.
     *
     * @param int $id Server id.
     * @return void
     */
    public function save(int $id): void
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = (string) ($_POST['path'] ?? '');
        $abs = $this->resolve($base, $rel);
        if (!$abs) {
            \flash('error', 'Invalid path.');
            \redirect("/servers/$id/files");
        }
        @mkdir(dirname($abs), 0755, true);
        file_put_contents($abs, (string) ($_POST['content'] ?? ''));
        \log_activity('edit-file', 'server:' . $s['name'], $rel);
        \flash('success', 'Saved ' . basename($rel));
        \redirect("/servers/$id/files/edit?path=" . urlencode($rel));
    }

    /**
     * Create a directory inside the server files.
     *
     * @param int $id Server id.
     * @return void
     */
    public function mkdir(int $id): void
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = trim((string) ($_POST['path'] ?? ''), '/');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || preg_match('#[/\\\\]#', $name)) {
            \flash('error', 'Invalid folder name.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        $abs = $this->resolve($base, ($rel ? "$rel/" : '') . $name);
        if ($abs) {
            @mkdir($abs, 0755, true);
        }
        \redirect("/servers/$id/files?path=" . urlencode($rel));
    }

    /**
     * Create an empty file inside the server files.
     *
     * @param int $id Server id.
     * @return void
     */
    public function touch(int $id): void
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = trim((string) ($_POST['path'] ?? ''), '/');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || preg_match('#[/\\\\]#', $name)) {
            \flash('error', 'Invalid file name.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        $abs = $this->resolve($base, ($rel ? "$rel/" : '') . $name);
        if ($abs && !file_exists($abs)) {
            file_put_contents($abs, '');
        }
        \redirect("/servers/$id/files/edit?path=" . urlencode(($rel ? "$rel/" : '') . $name));
    }

    /**
     * Delete a file or directory inside the server files.
     *
     * @param int $id Server id.
     * @return void
     */
    public function delete(int $id): void
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = (string) ($_POST['path'] ?? '');
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

    /**
     * Upload a file into the server files.
     *
     * Refuses empty uploads, hidden files, oversized files, and executable or
     * script extensions so uploaded content cannot be served or executed.
     *
     * @param int $id Server id.
     * @return void
     */
    public function upload(int $id): void
    {
        \check_csrf();
        \require_server_permission($id, 'manage_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = trim((string) ($_POST['path'] ?? ''), '/');
        $absDir = $this->resolve($base, $rel);
        if (!$absDir || !is_dir($absDir)) {
            \flash('error', 'Invalid target folder.');
            \redirect("/servers/$id/files");
        }
        if (empty($_FILES['file']['name']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
            \flash('error', 'No file uploaded.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        $safeName = basename($_FILES['file']['name']);
        if ($safeName === '' || preg_match('#^\.#', $safeName)) {
            \flash('error', 'Hidden files not allowed.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            \flash('error', 'File type "' . $ext . '" is not allowed for upload.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        if ((int) $_FILES['file']['size'] > self::MAX_UPLOAD_BYTES) {
            \flash('error', 'File exceeds the ' . (self::MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB upload limit.');
            \redirect("/servers/$id/files?path=" . urlencode($rel));
        }
        move_uploaded_file($_FILES['file']['tmp_name'], "$absDir/$safeName");
        \log_activity('upload-file', 'server:' . $s['name'], $rel . '/' . $safeName);
        \flash('success', 'Uploaded ' . $safeName);
        \redirect("/servers/$id/files?path=" . urlencode($rel));
    }

    /**
     * Download a file from the server files.
     *
     * The Content-Disposition filename is sanitised so it cannot inject
     * response headers.
     *
     * @param int $id Server id.
     * @return void
     */
    public function download(int $id): void
    {
        \require_server_permission($id, 'view_files');
        $s = $this->server($id);
        $base = $this->base($s);
        $rel = (string) ($_GET['path'] ?? '');
        $abs = $this->resolve($base, $rel);
        if (!$abs || !is_file($abs)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $filename = preg_replace('/[\r\n]+/', '', basename($abs));
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
    }

    /**
     * Recursively remove a directory tree.
     *
     * @param string $dir Directory to remove.
     * @return void
     */
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
