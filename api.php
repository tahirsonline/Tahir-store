<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Simple health check: opening /api.php in a browser must respond immediately.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(true, ['status' => 'online', 'service' => 'Tahir Online Hostinger API']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Only POST is allowed.');
}

$raw = file_get_contents('php://input');
$req = json_decode($raw ?: '', true);
if (!is_array($req)) respond(false, null, 'Invalid JSON request.');

$action = strtolower(trim((string)($req['action'] ?? '')));
$path = trim((string)($req['path'] ?? ''), '/');
$data = $req['data'] ?? null;

$allowedRoot = 'tahirOnlineStore';
$parts = $path === '' ? [] : explode('/', $path);
$parts = array_values(array_filter(array_map(function($v) {
    return rawurldecode((string)$v);
}, $parts), function($v) { return $v !== ''; }));

if (!$parts || $parts[0] !== $allowedRoot) {
    respond(false, null, 'Invalid database path.');
}

$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$imageDir = $storageDir . DIRECTORY_SEPARATOR . 'images';
$storageFile = $storageDir . DIRECTORY_SEPARATOR . 'store.json';

if (!is_dir($storageDir) && !@mkdir($storageDir, 0755, true)) {
    respond(false, null, 'Could not create data folder. Check Hostinger permissions.');
}
if (!is_dir($imageDir) && !@mkdir($imageDir, 0755, true)) {
    respond(false, null, 'Could not create data/images folder. Check Hostinger permissions.');
}
if (!file_exists($storageFile)) {
    if (@file_put_contents($storageFile, '{}', LOCK_EX) === false) {
        respond(false, null, 'Could not create store.json. Check Hostinger permissions.');
    }
}

$fp = @fopen($storageFile, 'c+');
if (!$fp) respond(false, null, 'Storage file could not be opened. Check Hostinger permissions.');

$exclusive = in_array($action, ['put','post','patch','delete'], true);
if (!flock($fp, $exclusive ? LOCK_EX : LOCK_SH)) {
    fclose($fp);
    respond(false, null, 'Database is busy. Please try again.');
}

rewind($fp);
$content = stream_get_contents($fp);
$db = json_decode($content ?: '{}', true);
if (!is_array($db)) $db = [];

try {
    switch ($action) {
        case 'get':
            $value = get_path($db, $parts);
            close_lock($fp);
            respond(true, $value);

        case 'put':
            $data = store_images($data, $imageDir);
            set_path($db, $parts, $data);
            save_db($fp, $db);
            $value = get_path($db, $parts);
            close_lock($fp);
            respond(true, $value);

        case 'patch':
            $data = store_images($data, $imageDir);
            $current = get_path($db, $parts);
            if (!is_array($current)) $current = [];
            if (!is_array($data)) $data = [];
            $merged = array_merge($current, $data);
            set_path($db, $parts, $merged);
            save_db($fp, $db);
            close_lock($fp);
            respond(true, $merged);

        case 'post':
            $data = store_images($data, $imageDir);
            $current = get_path($db, $parts);
            if (!is_array($current)) $current = [];
            $key = 'rec_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
            $current[$key] = $data;
            set_path($db, $parts, $current);
            save_db($fp, $db);
            close_lock($fp);
            respond(true, ['name' => $key, 'data' => $data]);

        case 'delete':
            delete_path($db, $parts);
            save_db($fp, $db);
            close_lock($fp);
            respond(true, true);

        default:
            close_lock($fp);
            respond(false, null, 'Unknown database action.');
    }
} catch (Throwable $e) {
    close_lock($fp);
    respond(false, null, 'Server error: ' . $e->getMessage());
}

function store_images($value, $imageDir) {
    if (is_array($value)) {
        foreach ($value as $k => $v) $value[$k] = store_images($v, $imageDir);
        return $value;
    }
    if (!is_string($value) || strpos($value, 'data:image/') !== 0) return $value;

    if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.*)$#s', $value, $m)) return $value;
    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, ['jpg','png','webp','gif'], true)) return $value;

    $binary = base64_decode($m[2], true);
    if ($binary === false || strlen($binary) > 8 * 1024 * 1024) return $value;

    $name = 'img_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $file = $imageDir . DIRECTORY_SEPARATOR . $name;
    if (@file_put_contents($file, $binary, LOCK_EX) === false) return $value;

    return 'data/images/' . $name;
}

function get_path($root, $parts) {
    $cur = $root;
    foreach ($parts as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) return null;
        $cur = $cur[$part];
    }
    return $cur;
}

function set_path(&$root, $parts, $value) {
    $cur =& $root;
    foreach ($parts as $part) {
        if (!isset($cur[$part]) || !is_array($cur[$part])) $cur[$part] = [];
        $cur =& $cur[$part];
    }
    $cur = $value;
}

function delete_path(&$root, $parts) {
    if (count($parts) === 1) {
        unset($root[$parts[0]]);
        return;
    }
    $cur =& $root;
    $last = array_pop($parts);
    foreach ($parts as $part) {
        if (!isset($cur[$part]) || !is_array($cur[$part])) return;
        $cur =& $cur[$part];
    }
    unset($cur[$last]);
}

function save_db($fp, $db) {
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new Exception('Could not encode database.');
    if (ftruncate($fp, 0) === false) throw new Exception('Could not truncate database.');
    rewind($fp);
    if (fwrite($fp, $json) === false) throw new Exception('Could not write database.');
    fflush($fp);
}

function close_lock($fp) {
    @flock($fp, LOCK_UN);
    @fclose($fp);
}

function respond($ok, $data = null, $error = null) {
    http_response_code($ok ? 200 : 400);
    echo json_encode([
        'ok' => (bool)$ok,
        'data' => $data,
        'error' => $error
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
