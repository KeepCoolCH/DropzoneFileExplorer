<?php
require_once 'inc/config.php';
require_once 'login.php';

// --- SESSION START (API + UI) ---
if (
  session_status() !== PHP_SESSION_ACTIVE &&
  !(isset($_GET['action'], $_GET['dl']) && $_GET['action'] === 'download')
) {
  auth_start_session();
}

function require_user_roots(): void {
  if (!auth_is_logged_in()) return;
  if (auth_is_admin()) return;
  if (!user_effective_roots()) {
    throw new RuntimeException('NO_RIGHTS');
  }
}

function auth_is_logged_in(): bool {
  return AUTH_ENABLE && !empty($_SESSION['auth_user']);
}

function auth_is_admin(): bool {
  if (!AUTH_ENABLE || empty($_SESSION['auth_user'])) return false;
  $db = auth_db();
  $u  = $_SESSION['auth_user'];
  return (($db['users'][$u]['role'] ?? '') === 'admin');
}

// -------------------- PUBLIC SHARE --------------------
// --- PUBLIC ZIP DOWNLOAD ---
if (
  isset($_GET['action'], $_GET['dl']) &&
  $_GET['action'] === 'download'
) {
  return;
}
define('IS_PUBLIC_SHARE', isset($_GET['share']));

// --- SHARE STATS ---
function share_total_size(string $shareRootAbs): int {
  ensure_inside_root($shareRootAbs);
  if (is_file($shareRootAbs)) {
    return filesize($shareRootAbs) ?: 0;
  }
  $total = 0;
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
      $shareRootAbs,
      FilesystemIterator::SKIP_DOTS
    )
  );
  foreach ($it as $f) {
    if ($f->isDir()) continue;
    if (!$f->isReadable()) continue;
    if (is_excluded_file($f->getFilename())) continue;
    ensure_inside_share($f->getPathname(), $shareRootAbs);
    $total += $f->getSize();
  }
  return $total;
}

// -------------------- ACTION --------------------
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

// -------------------- USER ACL --------------------
function user_effective_roots(): array {
  if (!auth_is_logged_in()) return [];
  if (auth_is_admin()) return [''];
  $db = auth_db();
  $u  = $_SESSION['auth_user'];
  $info = $db['users'][$u] ?? [];
  $valid = [];
  foreach (($info['folders'] ?? []) as $rel => $mode) {
    $rel = trim(norm_rel($rel), '/');
    if ($rel === '' || str_contains($rel, '..')) continue;
    if (is_dir(abs_path($rel))) {
      $valid[] = $rel;
    }
  }
  return $valid;
}

function user_allowed_roots(): array {
  if (!AUTH_ENABLE || empty($_SESSION['auth_user'])) {
    return [''];
  }
  $db = auth_db();
  $u  = $_SESSION['auth_user'];
  $info = $db['users'][$u] ?? [];
  if (($info['role'] ?? '') === 'admin') {
    return [''];
  }
  $paths = [];
  foreach ((array)($info['folders'] ?? []) as $rel => $mode) {
    $rel = trim(norm_rel($rel), '/');
    if ($rel === '' || str_contains($rel, '..')) continue;
    $paths[] = $rel;
  }
  sort($paths, SORT_STRING);
  $roots = [];
  foreach ($paths as $p) {
    foreach ($roots as $r) {
      if ($p === $r || str_starts_with($p.'/', $r.'/')) {
        continue 2;
      }
    }
    $roots[] = $p;
  }
  return $roots;
}

// -------------------- SAFE AUTH SAVE --------------------
function auth_save_safe(array $db): void {
  $file = AUTH_FILE;
  $tmp  = $file . '.tmp';
  $dir = dirname($file);
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  $json = json_encode(
    $db,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
  );
  if ($json === false) {
    throw new RuntimeException('JSON encode failed');
  }
  file_put_contents($tmp, $json, LOCK_EX);
  rename($tmp, $file);
}

// -------------------- ACL CLEANUP --------------------
function cleanup_dead_user_folders(): array {
  $db = auth_db();
  $removed = [];
  foreach ($db['users'] as $user => &$info) {
    if (($info['role'] ?? '') !== 'user') continue;
    if (empty($info['folders']) || !is_array($info['folders'])) continue;
    foreach ($info['folders'] as $rel => $mode) {
      $rel = trim(norm_rel($rel), '/');
      if ($rel === '' || !is_dir(abs_path($rel))) {
        unset($info['folders'][$rel]);
        $removed[$user][] = $rel;
      }
    }
    if (empty($info['folders'])) {
      unset($info['folders']);
    }
  }
  unset($info);
  if ($removed) {
    auth_save_safe($db);
  }
  return $removed;
}

// -------------------- REVOKE ACLs FOR DELETED PATH --------------------
function revoke_acl_for_deleted_path(string $rel): void {
  $rel = trim(norm_rel($rel), '/');
  if ($rel === '') return;
  $db = auth_db();
  $changed = false;
  foreach ($db['users'] as $user => &$info) {
    if (($info['role'] ?? '') !== 'user') continue;
    if (empty($info['folders']) || !is_array($info['folders'])) continue;
    foreach (array_keys($info['folders']) as $aclPath) {
      $aclPath = trim(norm_rel($aclPath), '/');
      if ($aclPath === $rel || str_starts_with($aclPath . '/', $rel . '/')) {
        unset($info['folders'][$aclPath]);
        $changed = true;
      }
    }
    if (empty($info['folders'])) {
      unset($info['folders']);
    }
  }
  unset($info);
  if ($changed) {
    auth_save_safe($db);
  }
}

function realpath_first_existing(string $path): string|false {
  $path = rtrim($path, DIRECTORY_SEPARATOR);
  while ($path !== '' && $path !== DIRECTORY_SEPARATOR) {
    if (file_exists($path)) {
      return realpath($path);
    }
    $path = dirname($path);
  }
  return false;
}

function ensure_inside_allowed_roots(string $abs): void {
  if (auth_is_admin()) return;
  $rootAbs = realpath(ROOT_DIR);
  if ($rootAbs === false) {
    jsend(['ok'=>false,'error'=>'Root missing'], 500);
  }
  $abs = realpath($abs);
  if ($abs === false) {
    jsend(['ok'=>false,'error'=>'Invalid path'], 400);
  }
  $rootAbs = rtrim($rootAbs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
  $abs     = rtrim($abs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
  if (strpos($abs, $rootAbs) !== 0) {
    jsend(['ok'=>false,'error'=>'Access denied'], 403);
  }
  $rel = norm_rel(substr($abs, strlen($rootAbs)));
  foreach (user_allowed_roots() as $rootRel) {
    $rootRel = rtrim($rootRel, '/');
    if ($rel === $rootRel) {
      return;
    }
    if (str_starts_with($rel.'/', $rootRel.'/')) {
      return;
    }
  }
  jsend(['ok'=>false,'error'=>'Access denied'], 403);
}

function ensure_write_allowed(string $rel): void {
  if (!AUTH_ENABLE || empty($_SESSION['auth_user'])) return;
  $db = auth_db();
  $u  = $_SESSION['auth_user'];
  $info = $db['users'][$u] ?? [];
  if (($info['role'] ?? '') === 'admin') return;
  $rel = trim(norm_rel($rel), '/');
  foreach (($info['folders'] ?? []) as $path => $mode) {
    $path = trim($path, '/');
    if ($mode === 'write' && ($rel === $path || str_starts_with($rel.'/', $path.'/'))) {
      return;
    }
  }
  jsend(['ok'=>false,'error'=>'Write access denied'], 403);
}

function ensure_target_inside_allowed_roots(string $absTarget): void {
  if (auth_is_admin()) return;
  $root = realpath(ROOT_DIR);
  if ($root === false) {
    jsend(['ok'=>false,'error'=>'Root missing'], 500);
  }
  $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
  $probe = $absTarget;
  while (true) {
    if (file_exists($probe)) {
      ensure_inside_allowed_roots($probe);
      return;
    }
    $parent = dirname($probe);
    if ($parent === $probe) break;
    $parentReal = realpath($parent);
    if ($parentReal !== false) {
      $parentReal = rtrim($parentReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
      if (strpos($parentReal, $root) !== 0) {
        jsend(['ok'=>false,'error'=>'Invalid path'], 400);
      }
    }
    $probe = $parent;
  }
  jsend(['ok'=>false,'error'=>'Invalid path'], 400);
}

// -------------------- JSON --------------------
function jsend(array $data, int $code = 200): void {
  while (ob_get_level()) ob_end_clean();
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

// -------------------- FILE EXCLUDES --------------------
const FILE_EXCLUDES = [
    '.DS_Store',
    '__MACOSX',
    'Thumbs.db',
];

const FILE_EXCLUDE_PREFIXES = [
    '._',
];

function is_excluded_file(string $name): bool {
    if (in_array($name, FILE_EXCLUDES, true)) {
        return true;
    }
    foreach (FILE_EXCLUDE_PREFIXES as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return true;
        }
    }
    return false;
}

function norm_rel(string $rel): string {
  $rel = str_replace("\0", '', $rel);
  $rel = str_replace('\\', '/', $rel);
  $rel = preg_replace('~/{2,}~', '/', $rel);
  $rel = trim($rel);
  if ($rel === '' || $rel === '.') return '';
  $rel = ltrim($rel, '/');
  $parts = [];
  foreach (explode('/', $rel) as $p) {
    if ($p === '' || $p === '.') continue;
    if ($p === '..') { array_pop($parts); continue; }
    $parts[] = $p;
  }
  return implode('/', $parts);
}

function abs_path(string $rel): string {
  $rel = norm_rel($rel);
  return rtrim(ROOT_DIR, '/\\') . DIRECTORY_SEPARATOR . ($rel === '' ? '' : str_replace('/', DIRECTORY_SEPARATOR, $rel));
}

function ensure_inside_root(string $abs): void {
  $root = realpath(ROOT_DIR);
  if ($root === false) jsend(['ok'=>false,'error'=>'Root missing'], 500);
  $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
  $real = realpath($abs);
  if ($real !== false) {
    $real = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($real, $root) !== 0) jsend(['ok'=>false,'error'=>'Path outside root'], 400);
    return;
  }
  $probe = $abs;
  for ($i = 0; $i < 50; $i++) {
    $probe = dirname($probe);
    if ($probe === '' || $probe === '.' || $probe === DIRECTORY_SEPARATOR) break;
    $pReal = realpath($probe);
    if ($pReal !== false) {
      $pReal = rtrim($pReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
      if (strpos($pReal, $root) !== 0) jsend(['ok'=>false,'error'=>'Path outside root'], 400);
      return;
    }
  }
  jsend(['ok'=>false,'error'=>'Invalid path parent'], 400);
}

function is_hidden(string $name): bool {
  return $name !== '' && $name[0] === '.';
}

function list_dir(string $rel): array {
  if ($rel === '' && !auth_is_admin()) {
    return [];
  }
  $abs = abs_path($rel);
  if (!is_dir($abs)) jsend(['ok'=>false,'error'=>'Not a directory'], 400);
  ensure_inside_allowed_roots($abs);
  $items = [];
  $dh = opendir($abs);
  if (!$dh) jsend(['ok'=>false,'error'=>'Cannot open dir'], 500);
  while (($name = readdir($dh)) !== false) {
    if ($name === '.' || $name === '..') continue;
    if (is_excluded_file($name)) continue;
    $p = $abs . DIRECTORY_SEPARATOR . $name;
    $isDir = is_dir($p);
    $stat = @stat($p);
    $items[] = [
      'name' => $name,
      'path' => norm_rel(($rel === '' ? '' : $rel.'/') . $name),
      'type' => $isDir ? 'dir' : 'file',
      'size' => $isDir ? 0 : (int)($stat['size'] ?? 0),
      'mtime'=> (int)($stat['mtime'] ?? 0),
    ];
  }
  closedir($dh);
  usort($items, function($a, $b){
    if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
    return strnatcasecmp($a['name'], $b['name']);
  });
  return $items;
}

function build_tree(string $rel = ''): array {
  $abs = abs_path($rel);
  if (!is_dir($abs)) return [];
  if ($rel === '' && !auth_is_admin()) {
    return [];
  }
  ensure_inside_allowed_roots($abs);
  $nodes = [];
  $dh = @opendir($abs);
  if (!$dh) return [];
  while (($name = readdir($dh)) !== false) {
    if ($name === '.' || $name === '..') continue;
    if (is_excluded_file($name)) continue;
    $p = $abs . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($p)) continue;
    $childRel = norm_rel(($rel === '' ? '' : $rel.'/') . $name);
    $nodes[] = [
      'name' => $name,
      'path' => $childRel,
      'children' => [],
      'hasChildren' => has_dir_children($childRel),
    ];
  }
  closedir($dh);
  usort($nodes, fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
  return $nodes;
}

function has_dir_children(string $rel): bool {
  $abs = abs_path($rel);
  if (!is_dir($abs)) return false;
  $dh = @opendir($abs);
  if (!$dh) return false;
  while (($name = readdir($dh)) !== false) {
    if ($name === '.' || $name === '..') continue;
    if (is_excluded_file($name)) continue;
    if (is_dir($abs . DIRECTORY_SEPARATOR . $name)) { closedir($dh); return true; }
  }
  closedir($dh);
  return false;
}

function rrmdir(string $dir): bool {
  if (!is_dir($dir)) return false;
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($it as $file) {
    $path = $file->getPathname();
    if ($file->isDir()) {
      if (!@rmdir($path)) return false;
    } else {
      if (!@unlink($path)) return false;
    }
  }
  return @rmdir($dir);
}

function tmp_gc(int $maxAge = 3600): void {
  if (!is_dir(FILE_TMP_DIR)) return;
  foreach (glob(FILE_TMP_DIR . '/*') ?: [] as $p) {
    if (!file_exists($p)) continue;
    if (time() - filemtime($p) > $maxAge) {
      if (is_dir($p)) {
        rrmdir($p);
      } else {
        @unlink($p);
      }
    }
  }
}

function rrcopy(string $src, string $dst): void {
  if (is_dir($src)) {
    @mkdir($dst, 0775, true);
    $it = new DirectoryIterator($src);
    foreach ($it as $f) {
      if ($f->isDot()) continue;
      if (is_excluded_file($f->getFilename())) continue;
      rrcopy($src . DIRECTORY_SEPARATOR . $f->getFilename(), $dst . DIRECTORY_SEPARATOR . $f->getFilename());
    }
  } else {
    @mkdir(dirname($dst), 0775, true);
    if (!@copy($src, $dst)) jsend(['ok'=>false,'error'=>'Copy failed'], 500);
  }
}

function unique_name(string $dirAbs, string $baseName): string {
  $baseName = trim($baseName);
  if ($baseName === '') $baseName = 'file';
  $p = pathinfo($baseName);
  $name = $p['filename'] ?? $baseName;
  $ext  = isset($p['extension']) ? '.'.$p['extension'] : '';
  $candidate = $name . $ext;
  $i = 1;
  while (file_exists($dirAbs . DIRECTORY_SEPARATOR . $candidate)) {
    $candidate = $name . " ($i)" . $ext;
    $i++;
    if ($i > 9999) break;
  }
  return $candidate;
}

function token(int $len = 18): string {
  return rtrim(strtr(base64_encode(random_bytes($len)), '+/', '-_'), '=');
}

function shares_db_path(): string { return STORAGE_DIR . '/shares/shares.json'; }
function shares_db(): array {
  $p = shares_db_path();
  if (!is_file($p)) return [];
  $j = json_decode((string)@file_get_contents($p), true);
  return is_array($j) ? $j : [];
}

function shares_save(array $db): void {
  @file_put_contents(shares_db_path(), json_encode($db, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
}

// -------------------- SHARE DB MIGRATION --------------------
if (AUTH_ENABLE) {
  $db = shares_db();
  $changed = false;
  foreach ($db as &$e) {
    if (!array_key_exists('owner', $e)) {
      $e['owner'] = null;
      $changed = true;
    }
  }
  unset($e);
  if ($changed) {
    shares_save($db);
  }
}

function downloads_db_path(): string {
  return STORAGE_DIR . '/tmp/downloads.json';
}

function downloads_db(): array {
  $p = downloads_db_path();
  if (!is_file($p)) return [];
  $j = json_decode((string)@file_get_contents($p), true);
  return is_array($j) ? $j : [];
}

function downloads_save(array $db): void {
  $dir = dirname(downloads_db_path());
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents(downloads_db_path(), json_encode($db, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
}

function downloads_gc(int $maxAgeSeconds = 6*3600): void {
  $db = downloads_db();
  if (!$db) return;
  $now = time();
  $changed = false;
  foreach ($db as $tok => $e) {
    $created = (int)($e['created'] ?? 0);
    $tmp = (string)($e['tmp'] ?? '');
    if ($created <= 0 || ($now - $created) > $maxAgeSeconds) {
      if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
      unset($db[$tok]);
      $changed = true;
    }
  }
  if ($changed) downloads_save($db);
}

function detect_mime(string $abs): string {
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  if (!$finfo) return 'application/octet-stream';
  $m = finfo_file($finfo, $abs);
  return $m ?: 'application/octet-stream';
}

function chunk_dir(string $uploadId): string {
  $uploadId = preg_replace('~[^a-zA-Z0-9_\-\.]~', '', $uploadId);
  return STORAGE_DIR . '/chunks/' . $uploadId;
}

function read_json_body(): array {
  $raw = (string)file_get_contents('php://input');
  if ($raw === '') return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

// ---------- SHARE DOWNLOAD PREPARED (FILE RESPONSE) ----------
if (
  isset($_GET['share']) &&
  $action === 'shareDownloadPrepared' &&
  isset($_GET['token'])
) {
  $dl = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['token']);
  if ($dl === '') exit;

  $db = downloads_db();
  if (!isset($db[$dl])) { http_response_code(404); exit; }

  $entry = $db[$dl];
  $tmp   = (string)$entry['tmp'];
  $name  = (string)($entry['name'] ?? 'download.zip');

  if (!is_file($tmp)) { http_response_code(404); exit; }

  while (ob_get_level()) ob_end_clean();
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="'.rawurlencode($name).'"');
  header('Content-Length: '.filesize($tmp));
  header('X-Accel-Buffering: no');

  readfile($tmp);
  @unlink($tmp);

  unset($db[$dl]);
  downloads_save($db);
  exit;
}

// -------------------- SHARE API --------------------
//---------- SHARE PREVIEW ----------
if (
  isset($_GET['share'], $_GET['api']) &&
  $action === 'sharePreview'
) {
  $t = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['share']);
  $db = shares_db();
  if (!isset($db[$t]['path'])) exit;
  $sharedRel = norm_rel($db[$t]['path']);
  $shareRoot = abs_path($sharedRel);
  ensure_inside_root($shareRoot);
  $rel = norm_rel((string)($_GET['path'] ?? ''));
  $abs = is_file($shareRoot)
    ? $shareRoot
    : realpath($shareRoot . '/' . $rel);
  if (!$abs || !is_file($abs)) exit;
  ensure_inside_share($abs, $shareRoot);
  $mime = detect_mime($abs);
  header('Content-Type: '.$mime);
  header('Content-Length: '.filesize($abs));
  header('Content-Disposition: inline; filename="'.rawurlencode(basename($abs)).'"');
  readfile($abs);
  exit;
}

//---------- ZIP CREATE JOB ----------
function zip_create_job(array $absPaths, string $label, string $baseDir, array $extraExcludes = []): array
{
    ignore_user_abort(true);
    set_time_limit(0);

    $extraExcludes = array_values(array_filter($extraExcludes, function ($p) {
        $p = trim((string)$p);
        return
            $p !== '' &&
            !str_contains($p, '..') &&
            !str_starts_with($p, '/') &&
            !str_contains($p, "\0");
    }));

    $allExcludes = array_values(array_unique(array_merge(
        FILE_EXCLUDES,
        array_map(fn($p) => rtrim($p, '/'), $extraExcludes)
    )));

    $token  = 'zip_' . bin2hex(random_bytes(8));
    $tmpDir = FILE_TMP_DIR;
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);

    $zipPath = $tmpDir . '/' . $token . '.zip';

    $baseDir = rtrim(realpath($baseDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    $cmd = 'cd ' . escapeshellarg($baseDir) . ' && zip -r -0 ' .
           escapeshellarg($zipPath);

    foreach ($absPaths as $abs) {
        $abs = realpath($abs);
        if (!$abs) continue;

        $rel = substr($abs, strlen($baseDir));
        if ($rel !== '') {
            $cmd .= ' ' . escapeshellarg($rel);
        }
    }

    foreach ($allExcludes as $ex) {
        $cmd .= ' -x ' . escapeshellarg("*/$ex");
        $cmd .= ' -x ' . escapeshellarg("*/$ex/*");
    }

    foreach (FILE_EXCLUDE_PREFIXES as $pre) {
        $cmd .= ' -x ' . escapeshellarg("*/$pre*");
    }
    
    $cmd .= ' 2>&1';

    exec($cmd, $out, $rc);

    if ($rc !== 0 || !is_file($zipPath)) {
        @unlink($zipPath);
        throw new RuntimeException(
            "ZIP failed\nCMD: $cmd\nOUT:\n" . implode("\n", $out)
        );
    }

    return [
        'token' => $token,
        'file'  => $zipPath,
        'name'  => $label . '.zip',
    ];
}

function ensure_inside_share(string $abs, string $shareRoot): void {
  $real = realpath($abs);
  $root = realpath($shareRoot);
  if ($real === false || $root === false) {
    jsend(['ok'=>false,'error'=>'Invalid path'], 400);
  }
  $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
  if (strpos($real . DIRECTORY_SEPARATOR, $root) !== 0) {
    jsend(['ok'=>false,'error'=>'Path outside share'], 403);
  }
}

if (isset($_GET['share'], $_GET['api'])) {

  // ---------- READ TEXT (SHARE) ----------
  if (
    isset($_GET['share'], $_GET['api']) &&
    $action === 'readText'
  ) {
    $body = read_json_body();
    $t  = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['share']);
    $db = shares_db();
    if (!isset($db[$t]['path'])) {
      jsend(['ok'=>false,'error'=>'Invalid share'], 404);
    }
    $shareRel = norm_rel($db[$t]['path']);
    $shareAbs = abs_path($shareRel);
    ensure_inside_root($shareAbs);
    $rel = norm_rel((string)($body['path'] ?? ''));
    if (is_file($shareAbs)) {
      $abs = $shareAbs;
    } else {
      if ($rel === '') {
        jsend(['ok'=>false,'error'=>'Missing path'], 400);
      }
      $abs = realpath($shareAbs . '/' . $rel);
    }
    if (!$abs || !is_file($abs)) {
      jsend(['ok'=>false,'error'=>'Not found'], 404);
    }
    ensure_inside_share($abs, $shareAbs);

    if (filesize($abs) > SAFE_TEXT_MAX) {
      jsend(['ok'=>false,'error'=>'File too large'], 400);
    }
    $txt = (string)@file_get_contents($abs);
    jsend(['ok'=>true,'text'=>$txt]);
  }

  // ---------- SHARE BROWSE ----------
  if ($action === 'shareBrowse') {
    $t  = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['share']);
    $db = shares_db();
    if (!isset($db[$t]['path'])) jsend(['ok'=>false],404);
    $shared = norm_rel($db[$t]['path']);
    $cwd    = norm_rel((string)($_GET['path'] ?? ''));
    $absShared = abs_path($shared);
    ensure_inside_root($absShared);
    if (!file_exists($absShared)) {
      jsend(['ok'=>true,'items'=>[]]);
    }

    // FILE SHARE
    if (is_file($absShared)) {
      $st = @stat($absShared);
      jsend([
        'ok'=>true,
        'items'=>[[
          'name'=>basename($shared),
          'path'=>basename($shared),
          'type'=>'file',
          'size'  => (int)($st['size'] ?? 0),
          'mtime' => (int)($st['mtime'] ?? 0),
        ]]
      ]);
    }

    // FOLDER SHARE
    $root = $absShared;
    $abs  = rtrim($root,'/') . ($cwd !== '' ? '/'.$cwd : '');
    if (!file_exists($abs) || !is_dir($abs)) {
      jsend(['ok'=>true,'items'=>[]]);
    }
    ensure_inside_root($abs);
    $items = [];
    foreach (scandir($abs) as $n) {
      if ($n==='.' || $n==='..' || is_excluded_file($n)) continue;
      $p = $abs.'/'.$n;
      $isDir = is_dir($p);
      $st = @stat($p);
      $items[] = [
        'name'=>$n,
        'path'=>norm_rel(($cwd!=='' ? $cwd.'/' : '').$n),
        'type'=>is_dir($p)?'dir':'file',
        'size'  => $isDir ? 0 : (int)($st['size'] ?? 0),
        'mtime' => (int)($st['mtime'] ?? 0),
      ];
    }
    jsend(['ok'=>true,'items'=>$items]);
  }

  // ---------- SHARE DOWNLOAD FILE ----------
  if ($action === 'shareDownloadFile') {
    $t  = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['share']);
    $db = shares_db();
    if (!isset($db[$t]['path'])) exit;
    $shared = norm_rel($db[$t]['path']);
    $absShared = abs_path($shared);
    ensure_inside_root($absShared);
    if (is_file($absShared)) {
      $abs = $absShared;
    } else {
      $rel = norm_rel((string)$_GET['path']);
      if ($rel === '') exit;
      $abs = rtrim($absShared,'/').'/'.$rel;
    }
    ensure_inside_root($abs);
    if (!is_file($abs)) exit;
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: '.detect_mime($abs));
    header('Content-Disposition: attachment; filename="'.rawurlencode(basename($abs)).'"');
    header('Content-Length: '.filesize($abs));
    readfile($abs);
    exit;
  }

  // ---------- SHARE DOWNLOAD PREPARE ----------
  if ($action === 'shareDownloadPrepare') {
  $t  = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['share']);
  $db = shares_db();
  if (!isset($db[$t]['path'])) {
      jsend(['ok'=>false,'error'=>'Invalid share'], 404);
  }
  $sharedRel = norm_rel($db[$t]['path']);
  $shareRoot = abs_path($sharedRel);
  ensure_inside_root($shareRoot);
  $cwd = norm_rel((string)($_GET['cwd'] ?? ''));
  $absList = [];
  $pathsIn = $_GET['paths'] ?? $_POST['paths'] ?? [];
  foreach ((array)$pathsIn as $p) {
      $rel = norm_rel(rawurldecode($p));
      if ($rel === '') continue;
      $abs = realpath(
          $shareRoot . '/' . ($cwd !== '' ? $cwd.'/' : '') . $rel
      );
      if ($abs === false) continue;
      ensure_inside_share($abs, $shareRoot);
      $absList[] = $abs;
  }
  if (!$absList) {
      jsend(['ok'=>false,'error'=>'Nothing to zip'], 400);
  }
  $zipBase = $shareRoot . ($cwd !== '' ? '/' . $cwd : '');
  $zipBase = realpath($zipBase);
  if ($zipBase === false || !is_dir($zipBase)) {
      jsend(['ok'=>false,'error'=>'Invalid ZIP base'], 400);
  }
  $res = zip_create_job(
      $absList,
      'download_' . date('Ymd_His'),
      $zipBase
  );
  $db = downloads_db();
  $db[$res['token']] = [
      'tmp'     => $res['file'],
      'name'    => $res['name'],
      'created' => time(),
  ];
  downloads_save($db);
  jsend([
      'ok'    => true,
      'token' => $res['token'],
  ]);
  }
}

if (isset($_GET['share']) && !isset($_GET['api'])) {
  $t = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['share']);
  $db = shares_db();
  $shareRel = norm_rel($db[$t]['path']);
  $shareAbs = abs_path($shareRel);
  ensure_inside_root($shareAbs);
  $shareBytes = share_total_size($shareAbs);
  $shareSizeFormatted = format_bytes($shareBytes);
  if (!isset($db[$t])) {
    http_response_code(404);
    echo 'Share not found';
    exit;
  }
  header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Shared files</title>
<meta name="description" content="Dropzone File Explore is a simple, self-hosted file manager designed for performance, usability and security. It allows you to browse, upload, manage and share files directly in the browser – without a database and without external dependencies.">
<link rel="icon" href="img/favicon.png">
<link rel="apple-touch-icon" href="img/favicon.png">
<link rel="stylesheet" href="css/style.css">
</head>
<body class="share-view">
<div class="app">
  <section>
  </section>
  <section class="card">
    <div class="card-sticky">
      <header>
        <div style="height: 96px;" class="title"><a href="index.php"><img src="img/logo.png" alt="Dropzone File Explorer" width="295"></a></div>
        <div class="row" style="display: block;">
          <div id="shareSize" class="small" style="margin-left: 5px; margin-bottom: 30px;">📦 Total size: <?= htmlspecialchars($shareSizeFormatted) ?></div>
          <button class="btn" onclick="downloadSelected()">⬇ Download selection as ZIP</button>
        </div>
      </header>
    </div>
    <div class="panel-scroll">
      <div id="statusBar" style="padding: 10px; margin-top: 8px; margin-bottom: 8px;">
        <div id="loadingSpinner" style="display:none;"><div class="spinner"></div></div>
        <div id="status" class="small">Ready. Click to preview/download files or select multiple files/folders to download as ZIP.</div>
      </div>
      <div id="list" class="listShare"><span class="small" style="padding: 10px;">Loading…</span></div>
    </div>
  </section>
  <section>
  </section>
</div>
<div id="lb" style="display:none">
  <div class="lb-backdrop"></div>

  <div class="lb-modal" role="dialog" aria-modal="true">
    <div class="lb-top">
      <div class="lb-title" id="lbTitle" style="margin-bottom: 10px;">Preview</div>
      <span class="tag" id="lbKind" style="margin-bottom: 10px;">—</span>
      <div class="row">
        <button class="btn" id="lbOpen">↗ Open</button>
        <button class="btn ok" id="lbSave" style="display:none">💾 Save</button>
        <button class="btn" id="lbPrev">←</button>
        <button class="btn" id="lbNext">→</button>
        <button class="btn danger" id="lbClose">✕</button>
      </div>
    </div>

    <div class="lb-body" id="lbBody"></div>
    <div class="lb-editor" id="lbEditorWrap" style="display:none">
      <textarea class="editor" id="lbEditor" placeholder="Editor…"></textarea>
    </div>
  </div>
</div>
<div class="spacer"></div>
<footer class="share-view"><?= htmlspecialchars(APP_TITLE) ?> V.1.1 © 2026 by Kevin Tobler - <a href='https://kevintobler.ch' target='_blank'>www.kevintobler.ch</a></footer>
<script>
const token = <?= json_encode($t) ?>;
let cwd = null;
let selected = new Set();

window.IS_SHARE_VIEW = true;

function fmtBytes(b) {
  if (!b) return '—';
  const u = ['B','KB','MB','GB','TB'];
  let i = 0;
  while (b >= 1024 && i < u.length - 1) {
    b /= 1024;
    i++;
  }
  return b.toFixed(1) + ' ' + u[i];
}

function fmtDate(ts) {
  if (!ts) return '—';
  return new Date(ts * 1000).toLocaleString('de-CH', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  });
}

function getPreviewUrl(it) {
  if (window.IS_SHARE_VIEW) {
    return `?share=${token}&api=1&action=sharePreview&path=${encodeURIComponent(it.path)}`;
  }
  return API('preview', 'path=' + encodeURIComponent(it.path) + '&inline=1');
}

function setEditorReadonly(on) {
  editor.readOnly = on;
  lbEditor.readOnly = on;

  editor.classList.toggle('readonly', on);
  lbEditor.classList.toggle('readonly', on);
}

function closeLB() {
  const lb = document.getElementById('lb');
  const body = document.getElementById('lbBody');
  body.innerHTML = '';
  body.classList.remove('pdf');
  lb.style.display = 'none';
}

function isPreviewable(name) {
  return /\.(png|jpe?g|gif|webp|svg|dng|heic|tiff|mp3|wav|ogg|m4a|aac|mp4|webm|mov|m4v|pdf|txt|md|json|xml|yml|yaml|ini|log|csv|tsv|php|js|ts|css|html?|py|go|java|c|cpp|h|hpp|sh|zsh|env|sql|xmp)$/i.test(name);
}

function isTextExt(name) {
  return /\.(txt|md|json|xml|yml|yaml|ini|log|csv|tsv|php|js|ts|css|html?|py|go|java|c|cpp|h|hpp|sh|zsh|env|sql|xmp)$/i.test(name);
}

function navigatePreview(dir) {
  if (!items.length) return;
  currentIndex += dir;
  if (currentIndex < 0) currentIndex = 0;
  if (currentIndex >= items.length) currentIndex = items.length - 1;
  openPreview();
}

function openLightboxForItem(it) {
  currentIndex = items.findIndex(x => x.path === it.path);
  if (currentIndex === -1) {
    items = [it];
    currentIndex = 0;
  }
  openPreview();
}

document.addEventListener('keydown', onShareKeydown);

function onShareKeydown(e) {
  if (!window.IS_SHARE_VIEW) return;
  const lb = document.getElementById('lb');
  if (!lb || lb.style.display !== 'block') return;
  const ae = document.activeElement;
  if (ae && (ae.id === 'lbEditor' || ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA')) {
    return;
  }
  if (e.key === 'Escape') {
    e.preventDefault();
    closeLB();
    return;
  }
  if (e.key === 'ArrowLeft') navigatePreview(-1);
  if (e.key === 'ArrowRight') navigatePreview(1);
}

async function openPreview() {
  const it = items[currentIndex];
  if (!it || !isPreviewable(it.name)) return;

  const lb = document.getElementById('lb');
  const body = document.getElementById('lbBody');
  const title = document.getElementById('lbTitle');
  const kind = document.getElementById('lbKind');
  const editorWrap = document.getElementById('lbEditorWrap');
  const ed = document.getElementById('lbEditor');

  /* ---- RESET ---- */
  body.innerHTML = '';
  body.style.display = 'flex';
  body.className = 'lb-body';

  editorWrap.style.display = 'none';

  ed.value = '';
  ed.readOnly = true;
  ed.classList.add('readonly');

  title.textContent = it.name;
  kind.textContent = it.name.split('.').pop().toUpperCase();

  const url = getPreviewUrl(it);
  
  document.getElementById('lbOpen').onclick = () => {
    window.open(url, '_blank');
  };

  // IMAGE
  if (/\.(png|jpe?g|gif|webp|svg|dng|heic|tiff)$/i.test(it.name)) {
    const img = document.createElement('img');
    img.src = url;
    img.style.maxWidth = '100%';
    img.style.maxHeight = '100%';
    body.appendChild(img);
  }

  // PDF
  else if (/\.pdf$/i.test(it.name)) {
    kind.textContent = 'PDF';
    body.classList.add('pdf');

    const iframe = document.createElement('iframe');
    iframe.src = url;
    iframe.style.width = '100%';
    iframe.style.height = '100%';
    iframe.style.border = '0';
    body.appendChild(iframe);
  }

  // VIDEO / AUDIO
  else if (/\.(mp3|wav|ogg|m4a|aac|mp4|webm|mov|m4v)$/i.test(it.name)) {
    const media = document.createElement(
      it.name.match(/\.(mp3|wav)$/i) ? 'audio' : 'video'
    );
    media.src = url;
    media.controls = true;
    media.autoplay = true;
    media.style.maxWidth = '100%';
    body.appendChild(media);
  }

  // TEXT FILES
  else if (isTextExt(it.name)) {
    kind.textContent = 'Text';
    body.style.display = 'none';
    editorWrap.style.display = 'flex';

    ed.value = 'Loading…';
    try {
      const r = await fetch(
        `?share=${token}&api=1&action=readText`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ path: it.path })
        }
      );
      const j = await r.json();
      ed.value = j.text || '';
    } catch {
      ed.value = 'Cannot read file.';
    }
  }

  // Buttons
  document.getElementById('lbPrev').onclick = () => {
    navigatePreview(-1);
  };

  document.getElementById('lbNext').onclick = () => {
    navigatePreview(1);
  };

  document.getElementById('lbClose').onclick = closeLB;
  lb.style.display = 'block';
}

let items = [];
let currentIndex = -1;

async function load() {
  if (cwd === null) return;
  selected.clear();
  const r = await fetch(`?share=${token}&api=1&action=shareBrowse&path=`+encodeURIComponent(cwd));
  const j = await r.json();
  items = j.items;
  const box = document.getElementById('list');
  box.innerHTML = '';
  box.insertAdjacentHTML('beforeend', `
    <div class="rowShareHeader">
      <div></div>
      <div>Name</div>
      <div>Type</div>
      <div>Size</div>
      <div>Changed</div>
    </div>
  `);
  if (cwd) {
    box.insertAdjacentHTML('beforeend', `
      <div class="rowShareBack">
        <div></div>
        <div>⬆️ go back…</div>
        <div></div><div></div><div></div>
      </div>
    `);
    const back = box.querySelector('.rowShareBack:last-child');
    back.onclick = () => {
      cwd = cwd.split('/').slice(0, -1).join('/');
      load();
    };
  }
  j.items.forEach(it => {
    const row = document.createElement('div');
    row.className = 'rowShare';
    row.innerHTML = `
      <input type="checkbox">
      <div>${it.type === 'dir' ? '📁' : '📄'} ${it.name}</div>
      <div>${it.type}</div>
      <div>${it.type === 'dir' ? '—' : fmtBytes(it.size)}</div>
      <div>${fmtDate(it.mtime)}</div>
    `;
    const cb = row.querySelector('input');
    cb.onclick = e => e.stopPropagation();
    cb.onchange = e => {
      const rel = it.path.split('/').pop();
      e.target.checked ? selected.add(rel) : selected.delete(rel);
    };
    row.onclick = () => {
      if (it.type === 'dir') {
        cwd = it.path;
        load();
        return;
      }
      if (!isPreviewable(it.name)) {
        window.location =
          `?share=${token}&api=1&action=shareDownloadFile&path=${encodeURIComponent(it.path)}`;
        return;
      }
      openLightboxForItem(it);
    };
    box.appendChild(row);
  });
}

function showSpinner() {
  document.getElementById('loadingSpinner').style.display = 'flex';
}

function hideSpinner() {
  document.getElementById('loadingSpinner').style.display = 'none';
}

async function downloadSelected() {
  if (!selected.size) {
    alert('Select at least one item.');
    return;
  }
  const elStatus = document.getElementById('status');
  const btn = document.querySelector('button');
  try {
    showSpinner();
    elStatus.textContent = 'Creating ZIP…';
    btn.disabled = true;
    const p = Array.from(selected)
      .map(p => 'paths[]=' + encodeURIComponent(p))
      .join('&');
    const r = await fetch(
      `?share=${token}&api=1&action=shareDownloadPrepare`
      + `&cwd=${encodeURIComponent(cwd)}`
      + `&${p}`
    );
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Failed to prepare ZIP');
    window.location =
      `?share=${token}&api=1&action=shareDownloadPrepared&token=${j.token}`;
    elStatus.textContent = 'Download starting…';
  } catch (err) {
    elStatus.textContent = 'Error creating ZIP.';
    alert(err.message || err);
  } finally {
    setTimeout(() => {
      hideSpinner();
      elStatus.textContent = 'Ready. Click to preview/download files or select multiple files/folders to download as ZIP.';
      btn.disabled = false;
    }, 1200);
  }
}
(async () => {
  cwd = '';
  await load();
})();
</script>
</body>
</html>
<?php
  exit;
}

// --------------------  ROOT STATS --------------------
if ($action === 'rootStats') {
  $bytes = get_root_total_size();
  jsend([
    'ok' => true,
    'bytes' => $bytes,
    'formatted' => format_bytes($bytes),
  ]);
}

// -------------------- ROOT FILESIZE --------------------
function dir_total_size(string $rel = ''): int {
    $abs = abs_path($rel);
    if (!is_dir($abs)) {
        return 0;
    }
    if ($rel === '' && !auth_is_admin()) {
      return 0;
    }
    if (!auth_is_admin()) {
      ensure_inside_allowed_roots($abs);
    }
    $total = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $abs,
            FilesystemIterator::SKIP_DOTS
        )
    );
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        if (!$file->isReadable()) continue;
        if (is_excluded_file($file->getFilename())) continue;
        $total += $file->getSize();
    }
    return $total;
}

function format_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return sprintf('%.1f %s', $bytes, $units[$i]);
}

function get_root_total_size(): int {
  if (auth_is_admin()) {
    return dir_total_size('');
  }
  $total = 0;
  foreach (user_allowed_roots() as $relRoot) {
    $total += dir_total_size($relRoot);
  }
  return $total;
}

// -------------------- API ROUTES --------------------
$isApi =
  !isset($_GET['share']) &&
  (isset($_GET['api']) || isset($_POST['api']) || ($action !== ''));
if ($isApi) {
  tmp_gc(3600);
  $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
  $body = read_json_body();

// --- WHOAMI (current session user) ---
if ($action === 'whoami') {
  if (!AUTH_ENABLE) jsend(['ok'=>false,'error'=>'Auth disabled'], 400);
  if (!auth_is_logged_in()) jsend(['ok'=>false,'error'=>'Auth required'], 401);

  jsend([
    'ok' => true,
    'user' => (string)($_SESSION['auth_user'] ?? ''),
    'isAdmin' => auth_is_admin(),
  ]);
}

// --- USER LIST ---
if ($action === 'userList') {
  if (!auth_is_admin()) jsend(['ok'=>false,'error'=>'Forbidden'], 403);
  if (!AUTH_ENABLE) jsend(['ok'=>false,'error'=>'Auth disabled'], 400);
  if (!auth_is_logged_in()) jsend(['ok'=>false,'error'=>'Auth required'], 401);
  $db = auth_db();
  $users = (array)($db['users'] ?? []);
  $out = [];
  foreach ($users as $u => $info) {
    $out[] = [
      'user' => (string)$u,
      'created' => (int)($info['created'] ?? 0),
      'role'    => (string)($info['role'] ?? 'user'),
      'folders' => (array)($info['folders'] ?? []),
    ];
  }
  usort($out, fn($a,$b)=>strcmp($a['user'],$b['user']));
  jsend(['ok'=>true,'users'=>$out, 'me'=>(string)($_SESSION['auth_user'] ?? '')]);
}

  // --- USER ADD ---
  if ($action === 'userAdd') {
    if (!auth_is_admin()) jsend(['ok'=>false,'error'=>'Forbidden'], 403);
    if (!AUTH_ENABLE) jsend(['ok'=>false,'error'=>'Auth disabled'], 400);
    if (!auth_is_logged_in()) jsend(['ok'=>false,'error'=>'Auth required'], 401);
    $user = trim((string)($body['user'] ?? ''));
    $pass = (string)($body['pass'] ?? '');
    $pass2 = (string)($body['pass2'] ?? '');
    if ($user === '' || strlen($user) < 3) jsend(['ok'=>false,'error'=>'Username too short (minimum 3 characters).'], 400);
    if (strpbrk($user, "\\/:*?\"<>| \t\r\n") !== false) jsend(['ok'=>false,'error'=>'Username contains invalid characters.'], 400);
    if ($pass === '' || strlen($pass) < 8) jsend(['ok'=>false,'error'=>'Password too short (minimum 8 characters).'], 400);
    if ($pass !== $pass2) jsend(['ok'=>false,'error'=>'Passwords do not match.'], 400);
    $db = auth_db();
    if (!isset($db['users']) || !is_array($db['users'])) $db['users'] = [];
    if (isset($db['users'][$user])) jsend(['ok'=>false,'error'=>'User already exists.'], 400);
    $db['users'][$user] = [
      'hash' => password_hash($pass, PASSWORD_DEFAULT),
      'created' => time(),
      'role' => 'user',
    ];
    auth_save_safe($db);
    jsend(['ok'=>true]);
  }

  // --- USER DELETE ---
  if ($action === 'userDelete') {
    if (!auth_is_admin()) jsend(['ok'=>false,'error'=>'Forbidden'], 403);
    if (!AUTH_ENABLE) jsend(['ok'=>false,'error'=>'Auth disabled'], 400);
    if (!auth_is_logged_in()) jsend(['ok'=>false,'error'=>'Auth required'], 401);
    $user = trim((string)($body['user'] ?? ''));
    if ($user === '') jsend(['ok'=>false,'error'=>'Missing user'], 400);
    $db = auth_db();
    $users = (array)($db['users'] ?? []);
    if (!isset($users[$user])) {
      jsend(['ok'=>true]);
    }
    if (($users[$user]['role'] ?? '') === 'admin') {
      jsend(['ok'=>false,'error'=>'Admin user cannot be deleted.'], 400);
    }
    if (count($users) <= 1) {
      jsend(['ok'=>false,'error'=>'You cannot delete the last user.'], 400);
    }
    unset($users[$user]);
    $db['users'] = $users;
    auth_save_safe($db);
    jsend(['ok'=>true]);
  }

// --- USER PASSWORD CHANGE (admin any, user only self) ---
if ($action === 'userPw') {
  if (!AUTH_ENABLE) jsend(['ok'=>false,'error'=>'Auth disabled'], 400);
  if (!auth_is_logged_in()) jsend(['ok'=>false,'error'=>'Auth required'], 401);
  $user = trim((string)($body['user'] ?? ''));
  $pass = (string)($body['pass'] ?? '');
  $pass2 = (string)($body['pass2'] ?? '');
  if ($user === '') jsend(['ok'=>false,'error'=>'Missing user'], 400);
  $me = (string)($_SESSION['auth_user'] ?? '');
  if (!auth_is_admin() && $user !== $me) {
    jsend(['ok'=>false,'error'=>'Forbidden'], 403);
  }
  if ($pass === '' || strlen($pass) < 8) jsend(['ok'=>false,'error'=>'Password too short (minimum 8 characters).'], 400);
  if ($pass !== $pass2) jsend(['ok'=>false,'error'=>'Passwords do not match.'], 400);
  $db = auth_db();
  if (!isset($db['users']) || !is_array($db['users'])) $db['users'] = [];
  if (!isset($db['users'][$user])) jsend(['ok'=>false,'error'=>'User not found.'], 404);
  $db['users'][$user]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
  auth_save_safe($db);
  jsend(['ok'=>true]);
}

// --- USER UPDATE ---
if ($action === 'userUpdate') {
  if (!auth_is_admin()) jsend(['ok'=>false,'error'=>'Forbidden'], 403);
  $user = (string)($body['user'] ?? '');
  $folders = (array)($body['folders'] ?? []);
  $clean = [];
  foreach ($folders as $path => $mode) {
    $path = trim(norm_rel($path), '/');
    if ($path === '' || str_contains($path, '..')) continue;
    if (!in_array($mode, ['read','write'], true)) continue;
    if (!is_dir(abs_path($path))) continue;
    $clean[$path] = $mode;
  }
  $db = auth_db();
  $db['users'][$user]['folders'] = $clean;
  auth_save_safe($db);
  jsend(['ok'=>true]);
}

// --- USER FOLDER SET ---
if ($action === 'userFolderSet') {
  if (!auth_is_admin()) jsend(['ok'=>false,'error'=>'Forbidden'], 403);
  $user = (string)$body['user'];
  $path = trim(norm_rel((string)$body['path']), '/');
  $mode = (string)$body['mode'];
  if ($path === '' || str_contains($path, '..')) {
    jsend(['ok'=>false,'error'=>'Invalid path'], 400);
  }
  if (!in_array($mode, ['read','write'], true)) {
    jsend(['ok'=>false,'error'=>'Invalid mode'], 400);
  }
if (!is_dir(abs_path($path))) {
    jsend(['ok'=>false,'error'=>'Folder does not exist'], 400);
  }
  $db = auth_db();
  $db['users'][$user]['folders'][$path] = $mode;
  auth_save_safe($db);
  jsend(['ok'=>true]);
}

// --- USER FOLDER REMOVE ---
if ($action === 'userFolderRemove') {
  if (!auth_is_admin()) jsend(['ok'=>false,'error'=>'Forbidden'], 403);
  $user = (string)$body['user'];
  $path = trim(norm_rel((string)$body['path']), '/');
  $db = auth_db();
  unset($db['users'][$user]['folders'][$path]);
  auth_save_safe($db);
  jsend(['ok'=>true]);
}

// --- ACL CLEANUP ---
if ($action === 'aclCleanup') {
  if (!auth_is_admin()) {
    jsend(['ok'=>false,'error'=>'Forbidden'], 403);
  }
  $removed = cleanup_dead_user_folders();
  jsend([
    'ok' => true,
    'usersAffected' => count($removed),
    'removed' => $removed,
  ]);
}

if (session_status() === PHP_SESSION_ACTIVE) {
  session_write_close();
}

// --- LIST ---
if ($action === 'list') {
  $rel = (string)($_GET['path'] ?? $body['path'] ?? '');
  $rel = norm_rel($rel);
  $items = list_dir($rel);
  jsend(['ok'=>true,'path'=>$rel,'items'=>$items]);
}

// --- TREE ROOT / CHILDREN ---
if ($action === 'treeRoot') {
  if (auth_is_admin()) {
    jsend(['ok'=>true,'nodes'=>build_tree('')]);
  }
  $nodes = [];
  foreach (user_allowed_roots() as $rel) {
    if ($rel === '') continue;
    $nodes[] = [
      'name' => basename($rel),
      'path' => $rel,
      'hasChildren' => has_dir_children($rel),
    ];
  }
  jsend(['ok'=>true,'nodes'=>$nodes]);
}

if ($action === 'treeChildren') {
  $rel = norm_rel((string)($_GET['path'] ?? $body['path'] ?? ''));
  if ($rel === '' && !auth_is_admin()) {
    jsend(['ok'=>true,'path'=>$rel,'nodes'=>[]]);
  }
  $nodes = build_tree($rel);
  jsend(['ok'=>true,'path'=>$rel,'nodes'=>$nodes]);
}

if ($action === 'folderList') {
  if (!auth_is_admin()) jsend(['ok'=>false,'error'=>'Forbidden'], 403);
  $nodes = build_tree('');
  jsend(['ok'=>true,'nodes'=>$nodes]);
}

// --- MKDIR ---
if ($action === 'mkdir') {
  $parent = norm_rel((string)($body['parent'] ?? ''));
  if ($parent === '' && !auth_is_admin()) {
    jsend(['ok'=>false,'error'=>'Invalid parent'], 403);
  }
  ensure_write_allowed($parent);
  $name = trim((string)($body['name'] ?? 'New Folder'));
  if ($name === '' || strpbrk($name, "\\/:*?\"<>|") !== false) {
    jsend(['ok'=>false,'error'=>'Invalid folder name'], 400);
  }
  $absParent = abs_path($parent);
  if (!is_dir($absParent)) {
    jsend(['ok'=>false,'error'=>'Parent not found'], 400);
  }
  ensure_inside_allowed_roots($absParent);
  $abs = $absParent . DIRECTORY_SEPARATOR . $name;
  ensure_target_inside_allowed_roots($abs);
  if (file_exists($abs)) {
    jsend(['ok'=>false,'error'=>'Already exists'], 400);
  }
  if (!@mkdir($abs, 0775, true)) {
    jsend(['ok'=>false,'error'=>'Cannot create folder'], 500);
  }
  jsend(['ok'=>true]);
}

// --- DELETE (files/folders) ---
if ($action === 'delete') {
  $paths = $body['paths'] ?? [];
  if (!is_array($paths) || !$paths) {
    jsend(['ok'=>false,'error'=>'No paths'], 400);
  }
  foreach ($paths as $rel) {
    $rel = norm_rel((string)$rel);
    $parent = norm_rel(dirname($rel));
    ensure_write_allowed($parent);
    $abs = abs_path($rel);
    ensure_inside_allowed_roots($abs);
    if (!file_exists($abs)) continue;
    if (is_dir($abs)) {
      if (!rrmdir($abs)) {
        jsend([
          'ok' => false,
          'error' => 'delete_failed',
          'message' => 'Folder could not be deleted (name too long?)'
        ], 400);
      }
      revoke_acl_for_deleted_path($rel);
    } else {
      if (!@unlink($abs)) {
        jsend([
          'ok' => false,
          'error' => 'delete_failed',
          'message' => 'File could not be deleted'
        ], 400);
      }
    }
  }
  jsend(['ok'=>true]);
}

// --- RENAME ---
if ($action === 'rename') {
  $rel = norm_rel((string)($body['path'] ?? ''));
  $parent = norm_rel(dirname($rel));
  ensure_write_allowed($parent);
  $newName = trim((string)($body['newName'] ?? ''));
  if ($newName === '' || strpbrk($newName, "\\/:*?\"<>|") !== false) jsend(['ok'=>false,'error'=>'Invalid name'], 400);
  $abs = abs_path($rel);
  ensure_inside_allowed_roots($abs);
  if (!file_exists($abs)) jsend(['ok'=>false,'error'=>'Not found'], 404);
  $dstAbs = dirname($abs) . DIRECTORY_SEPARATOR . $newName;
  if (file_exists($dstAbs)) jsend(['ok'=>false,'error'=>'Destination exists'], 400);
  if (!@rename($abs, $dstAbs)) jsend(['ok'=>false,'error'=>'Rename failed'], 500);
  jsend(['ok'=>true]);
}

// --- MOVE ---
if ($action === 'move') {
  $paths = $body['paths'] ?? [];
  $dest = norm_rel((string)($body['dest'] ?? ''));
  if (!is_array($paths) || !$paths) jsend(['ok'=>false,'error'=>'No paths'], 400);
  ensure_write_allowed($dest);
  $destAbs = abs_path($dest);
  ensure_inside_allowed_roots($destAbs);
  if (!is_dir($destAbs)) jsend(['ok'=>false,'error'=>'Destination is not a folder'], 400);
  foreach ($paths as $rel) {
    $rel = norm_rel((string)$rel);
    $abs = abs_path($rel);
    ensure_inside_allowed_roots($abs);
    if (!file_exists($abs)) continue;
    if ($dest === $rel) {
      jsend(['ok'=>false,'error'=>'Source and destination are the same'], 400);
    }
    $dst = $destAbs . DIRECTORY_SEPARATOR . basename($abs);
    if (is_dir($abs)) {
      $realA = realpath($abs);
      $realD = realpath($destAbs);
      if ($realA && $realD && strpos($realD . DIRECTORY_SEPARATOR, $realA . DIRECTORY_SEPARATOR) === 0) {
        jsend(['ok'=>false,'error'=>'Cannot move folder into itself'], 400);
      }
    }
    if (!@rename($abs, $dst)) jsend(['ok'=>false,'error'=>'Move failed'], 500);
  }
  jsend(['ok'=>true]);
}

// --- COPY ---
if ($action === 'copy') {
  $paths = $body['paths'] ?? [];
  $dest = norm_rel((string)($body['dest'] ?? ''));
  if (!is_array($paths) || !$paths) jsend(['ok'=>false,'error'=>'No paths'], 400);
  ensure_write_allowed($dest);
  $destAbs = abs_path($dest);
  ensure_inside_allowed_roots($destAbs);
  if (!is_dir($destAbs)) jsend(['ok'=>false,'error'=>'Destination is not a folder'], 400);
  foreach ($paths as $rel) {
    $rel = norm_rel((string)$rel);
    $abs = abs_path($rel);
    ensure_inside_allowed_roots($abs);
    if (!file_exists($abs)) continue;
    if ($dest === $rel) {
      jsend(['ok'=>false,'error'=>'Source and destination are the same'], 400);
    }
    if (is_dir($abs)) {
      $realA = realpath($abs);
      $realD = realpath($destAbs);
      if ($realA && $realD && strpos($realD . DIRECTORY_SEPARATOR, $realA . DIRECTORY_SEPARATOR) === 0) {
        jsend(['ok'=>false,'error'=>'Cannot copy folder into itself'], 400);
      }
    }
    $dst = $destAbs . DIRECTORY_SEPARATOR . basename($abs);
    if (file_exists($dst)) {
      $dstName = unique_name($destAbs, basename($abs));
      $dst = $destAbs . DIRECTORY_SEPARATOR . $dstName;
    }
    rrcopy($abs, $dst);
  }
  jsend(['ok'=>true]);
}

// --- PREVIEW / DIRECT DOWNLOAD ---
if ($action === 'preview') {
    $rel = norm_rel((string)($_GET['path'] ?? ''));
    if ($rel === '') {
        http_response_code(400);
        exit('Invalid path');
    }
    $abs = realpath(abs_path($rel));
    if (!$abs || !is_file($abs)) {
        http_response_code(404);
        exit('File not found');
    }
    ensure_inside_allowed_roots($abs);
    $inline = isset($_GET['inline']) && $_GET['inline'] == '1';
    $mime = mime_content_type($abs) ?: 'application/octet-stream';
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    if (!$inline) {
        header('Content-Disposition: attachment; filename="' . rawurlencode(basename($abs)) . '"');
    }
    readfile($abs);
    exit;
}

// --- DOWNLOAD ---
if ($action === 'download' && !isset($_GET['dl'])) {
  if (!auth_is_logged_in()) {
    jsend(['ok'=>false,'error'=>'Auth required'], 401);
  }
  $cwd   = norm_rel((string)($body['cwd'] ?? ''));
  $paths = (array)($body['paths'] ?? []);
  if (!$paths) {
    jsend(['ok'=>false,'error'=>'Nothing selected'], 400);
  }
  $zipBase = abs_path($cwd);
  if (!is_dir($zipBase)) {
    jsend(['ok'=>false,'error'=>'Invalid directory'], 400);
  }
  ensure_inside_allowed_roots($zipBase);
  $absList = [];
  foreach ($paths as $p) {
    $rel = norm_rel((string)$p);
    if ($rel === '') continue;
    $abs = realpath($zipBase . '/' . $rel);
    if ($abs === false) {
      $abs = realpath(abs_path($rel));
    }
    if ($abs === false) continue;
    ensure_inside_allowed_roots($abs);
    $absList[] = $abs;
  }
  if (!$absList) {
    jsend(['ok'=>false,'error'=>'Nothing to zip'], 400);
  }
  $label = $cwd !== '' ? basename($cwd) : 'download';
  $res = zip_create_job(
    $absList,
    $label . '_' . date('Ymd_His'),
    $zipBase
  );
  $db = downloads_db();
  $db[$res['token']] = [
    'tmp'     => $res['file'],
    'name'    => $res['name'],
    'created' => time(),
  ];
  downloads_save($db);
  jsend([
    'ok'    => true,
    'token' => $res['token'],
  ]);
}

// --- ZIP CREATE ---
if ($action === 'zipCreate') {
  $policy = (string)($body['policy'] ?? 'ask');
  if (!in_array($policy, ['ask','overwrite','rename'], true)) {
    $policy = 'ask';
  }
  if (!auth_is_logged_in()) {
    jsend(['ok'=>false,'error'=>'Auth required'], 401);
  }
  $paths = (array)($body['paths'] ?? []);
  if (!$paths) {
    jsend(['ok'=>false,'error'=>'Nothing selected'], 400);
  }
  $absList = [];
  foreach ($paths as $p) {
    $rel = norm_rel((string)$p);
    if ($rel === '') continue;
    $abs = realpath(abs_path($rel));
    if ($abs === false) continue;
    ensure_inside_allowed_roots($abs);
    $absList[] = $abs;
  }
  if (!$absList) {
    jsend(['ok'=>false,'error'=>'Nothing to zip'], 400);
  }
  $firstAbs = $absList[0];
  $zipBase  = dirname($firstAbs);
  if (!is_dir($zipBase)) {
    jsend(['ok'=>false,'error'=>'Invalid ZIP base'], 400);
  }
  ensure_inside_allowed_roots($zipBase);
  if (!empty($body['name'])) {
    $name = trim((string)$body['name']);
  } else {
    if (count($absList) === 1) {
      $name = basename($firstAbs);
    } else {
      $name = 'selection';
    }
  }
  $name = preg_replace('~[^a-zA-Z0-9._() -]~', '_', $name);
  if (!str_ends_with(strtolower($name), '.zip')) {
    $name .= '.zip';
  }
  $label = preg_replace('~\.zip$~i', '', $name);
  try {
    $res = zip_create_job(
      $absList,
      $label,
      $zipBase
    );
  } catch (Throwable $e) {
    jsend(['ok'=>false,'error'=>'ZIP failed','detail'=>$e->getMessage()], 500);
  }
  $finalPath = $zipBase . DIRECTORY_SEPARATOR . $res['name'];
  if (file_exists($finalPath)) {
    if ($policy === 'ask') {
      @unlink($res['file']);
      jsend([
        'ok' => false,
        'needsChoice' => true,
        'error' => 'ZIP already exists',
        'path' => norm_rel(substr($finalPath, strlen(realpath(ROOT_DIR)) + 1))
      ], 409);
    }
    if ($policy === 'rename') {
      $newName   = unique_name($zipBase, basename($finalPath));
      $finalPath = $zipBase . DIRECTORY_SEPARATOR . $newName;
    }
    if ($policy === 'overwrite') {
      @unlink($finalPath);
    }
  }
  if (!@rename($res['file'], $finalPath)) {
    @unlink($res['file']);
    jsend(['ok'=>false,'error'=>'Cannot move ZIP'], 500);
  }
  jsend([
    'ok'   => true,
    'path' => norm_rel(substr($finalPath, strlen(realpath(ROOT_DIR)) + 1))
  ]);
}

// --- UNZIP ---
if ($action === 'unzip') {
  $zipRel = norm_rel((string)($body['path'] ?? ''));
  $dest   = norm_rel((string)($body['dest'] ?? dirname($zipRel)));
  $policy = (string)($body['policy'] ?? 'ask');
  if (!in_array($policy, ['ask','overwrite','rename'], true)) {
    $policy = 'ask';
  }
  $zipAbs  = abs_path($zipRel);
  $destAbs = abs_path($dest);
  ensure_inside_root($zipAbs);
  if (!is_file($zipAbs)) {
    jsend(['ok'=>false,'error'=>'Not a file'], 400);
  }
  ensure_write_allowed($dest);
  if (!is_dir($destAbs) && !@mkdir($destAbs, 0775, true)) {
    jsend(['ok'=>false,'error'=>'Cannot create destination'], 500);
  }
  ensure_inside_allowed_roots($destAbs);
  $tmpBase = $destAbs . '/._unzip_tmp_' . bin2hex(random_bytes(6));
  if (!mkdir($tmpBase, 0775, true)) {
    jsend(['ok'=>false,'error'=>'Cannot create temp folder'], 500);
  }
  $cmdList = 'unzip -Z1 ' . escapeshellarg($zipAbs) . ' 2>&1';
  exec($cmdList, $entries, $rc);
  if ($rc !== 0 || !$entries) {
    rrmdir($tmpBase);
    jsend(['ok'=>false,'error'=>'ZIP listing failed'], 400);
  }
  foreach ($entries as $name) {
    $name = trim(str_replace('\\','/',$name));
    if (
      $name === '' ||
      $name[0] === '/' ||
      str_contains($name, '../') ||
      preg_match('~^[a-zA-Z]:/~', $name)
    ) {
      rrmdir($tmpBase);
      jsend(['ok'=>false,'error'=>'Unsafe ZIP paths detected'], 400);
    }
  }
  $cmd = 'unzip -o ' . escapeshellarg($zipAbs)
       . ' -d ' . escapeshellarg($tmpBase) . ' 2>&1';
  exec($cmd, $out, $rc);
  if ($rc !== 0) {
    rrmdir($tmpBase);
    jsend(['ok'=>false,'error'=>'Unzip failed','detail'=>implode("\n",$out)], 500);
  }
  $topItems = [];
  foreach (scandir($tmpBase) as $i) {
    if ($i === '.' || $i === '..') continue;
    if (is_excluded_file($i)) continue;
    $topItems[] = $i;
  }
  if (!$topItems) {
    rrmdir($tmpBase);
    jsend(['ok'=>true,'empty'=>true]);
  }
  foreach ($topItems as $item) {
    $src = $tmpBase . '/' . $item;
    $dst = $destAbs . '/' . $item;
    if (file_exists($dst)) {
      if ($policy === 'ask') {
        rrmdir($tmpBase);
        jsend([
          'ok'=>false,
          'needsChoice'=>true,
          'path'=>norm_rel($dest.'/'.$item)
        ], 409);
      }
      if ($policy === 'rename') {
        $dst = $destAbs . '/' . unique_name($destAbs, $item);
      }
      if ($policy === 'overwrite') {
        rrmdir($dst);
      }
    }
    if (!@rename($src, $dst)) {
      rrmdir($tmpBase);
      jsend(['ok'=>false,'error'=>'Move failed (rename error)'], 500);
    }
  }
  rmdir($tmpBase);
  jsend(['ok'=>true]);
}

// --- READ TEXT ---
if ($action === 'readText') {
  if (defined('IS_PUBLIC_SHARE') && IS_PUBLIC_SHARE) {
    if (!isset($_GET['share'])) {
      jsend(['ok'=>false,'error'=>'Invalid share'], 403);
    }
    $t  = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['share']);
    $db = shares_db();
    if (!isset($db[$t]['path'])) {
      jsend(['ok'=>false,'error'=>'Invalid share'], 404);
    }
    $shareRootRel = norm_rel($db[$t]['path']);
    $shareRootAbs = abs_path($shareRootRel);
    ensure_inside_root($shareRootAbs);
    $rel = norm_rel((string)($body['path'] ?? ''));
    if (is_file($shareRootAbs)) {
      $abs = $shareRootAbs;
    } else {
      if ($rel === '') {
        jsend(['ok'=>false,'error'=>'Missing path'], 400);
      }
      $abs = realpath($shareRootAbs . '/' . $rel);
    }
    if (!$abs || !is_file($abs)) {
      jsend(['ok'=>false,'error'=>'Not found'], 404);
    }
    ensure_inside_share($abs, $shareRootAbs);
  }
  else {
    $rel = norm_rel((string)($body['path'] ?? ''));
    $abs = abs_path($rel);
    ensure_inside_allowed_roots($abs);
  }
  if (!is_file($abs)) {
    jsend(['ok'=>false,'error'=>'Not found'], 404);
  }
  if (filesize($abs) > SAFE_TEXT_MAX) {
    jsend(['ok'=>false,'error'=>'File too large'], 400);
  }
  $txt = (string)@file_get_contents($abs);
  jsend(['ok'=>true,'text'=>$txt]);
}

// --- SAVE TEXT (editor) ---
if ($action === 'saveText') {
  $parent = norm_rel((string)($body['parent'] ?? ''));
  ensure_write_allowed($parent);
  $rel = norm_rel((string)($body['path'] ?? ''));
  $text = (string)($body['text'] ?? '');
  $abs = abs_path($rel);
  ensure_inside_allowed_roots($abs);
  if (!is_file($abs)) jsend(['ok'=>false,'error'=>'Not found'], 404);
  if (strlen($text) > SAFE_TEXT_MAX) jsend(['ok'=>false,'error'=>'Too large'], 400);
  if (@file_put_contents($abs, $text, LOCK_EX) === false) jsend(['ok'=>false,'error'=>'Save failed'], 500);
  jsend(['ok'=>true]);
}

// --- SHARE CREATE ---
if ($action === 'shareCreate') {
  $paths = $body['paths'] ?? [];
  if (!is_array($paths) || !$paths) jsend(['ok'=>false,'error'=>'No paths'], 400);
  if (count($paths) !== 1) jsend(['ok'=>false,'error'=>'Select exactly 1 item for share'], 400);
  $rel = norm_rel((string)$paths[0]);
  $abs = abs_path($rel);
  ensure_inside_allowed_roots($abs);
  if (!file_exists($abs)) jsend(['ok'=>false,'error'=>'Not found'], 404);
  $db = shares_db();
  $t = token();
  $db[$t] = [
    'path'    => $rel,
    'created' => time(),
    'owner'   => AUTH_ENABLE ? ($_SESSION['auth_user'] ?? null) : null,
  ];
  shares_save($db);
  $scheme =
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
  || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
  || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    ? 'https'
    : 'http';
  $base = (BASE_URL !== '')
    ? rtrim(BASE_URL, '/')
    : $scheme . '://' . $_SERVER['HTTP_HOST'];
  $url = $base . strtok($_SERVER['REQUEST_URI'], '?') . '?share=' . $t;
  jsend(['ok'=>true,'token'=>$t,'url'=>$url]);
}

// --- SHARE REVOKE ---
if ($action === 'shareRevoke') {
  $t = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)($body['token'] ?? ''));
  $db = shares_db();
  if (!isset($db[$t])) jsend(['ok'=>true]);
  $entry = $db[$t];
  $me = AUTH_ENABLE ? ($_SESSION['auth_user'] ?? null) : null;
  if (AUTH_ENABLE && (($entry['owner'] ?? null) !== $me)) {
    jsend(['ok'=>false,'error'=>'Forbidden'], 403);
  }
  unset($db[$t]);
  shares_save($db);
  jsend(['ok'=>true]);
}

// --- SHARE LIST ---
if ($action === 'shareList') {
  $db = shares_db();
  $scheme =
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
  || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
  || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    ? 'https'
    : 'http';
  $base = (BASE_URL !== '')
    ? rtrim(BASE_URL, '/')
    : $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
  $out = [];
  $me = AUTH_ENABLE ? ($_SESSION['auth_user'] ?? null) : null;
  $isAdmin = auth_is_admin();
  foreach ($db as $token => $entry) {
    if (AUTH_ENABLE) {
      if (!isset($entry['owner']) || $entry['owner'] !== $me) {
        continue;
      }
    }
    $rel = norm_rel((string)($entry['path'] ?? ''));
    $exists = false;
    $type = 'file';
    if ($rel === '') {
      $exists = true;
      $type = 'dir';
    } else {
      $abs = abs_path($rel);
      $exists = file_exists($abs);
      $type = ($exists && is_dir($abs)) ? 'dir' : 'file';
    }
    $out[] = [
      'token'   => (string)$token,
      'path'    => $rel,
      'exists'  => $exists,
      'type'    => $type,
      'created' => (int)($entry['created'] ?? 0),
      'url'     => $base . '?share=' . $token
    ];
  }
  usort($out, fn($a,$b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
  jsend(['ok'=>true,'shares'=>$out]);
}

// -------------------- RESUMABLE CHUNK UPLOAD --------------------
if ($action === 'uploadInit') {
  $destDir = norm_rel((string)($body['destDir'] ?? ''));
  $fileName = trim((string)($body['fileName'] ?? 'file'));
  $fileSize = (int)($body['fileSize'] ?? 0);
  $lastMod  = (string)($body['lastModified'] ?? '');
  $relativePath = (string)($body['relativePath'] ?? '');
  $policy = (string)($body['policy'] ?? 'ask');
  if ($fileSize < 0 || $fileSize > MAX_UPLOAD_SIZE) jsend(['ok'=>false,'error'=>'Invalid size'], 400);
  if ($fileName === '') $fileName = 'file';
  $destAbs = abs_path($destDir);
  ensure_inside_allowed_roots($destAbs);
  if (!is_dir($destAbs)) jsend(['ok'=>false,'error'=>'Destination folder missing'], 400);
  $seed = $destDir . '|' . $relativePath . '|' . $fileName . '|' . $fileSize . '|' . $lastMod;
  $uploadId = rtrim(strtr(base64_encode(hash('sha256', $seed, true)), '+/', '-_'), '=');
  $udir = chunk_dir($uploadId);
  if (!is_dir($udir)) @mkdir($udir, 0775, true);
  $meta = [
    'uploadId' => $uploadId,
    'destDir' => $destDir,
    'relativePath' => norm_rel($relativePath),
    'fileName' => $fileName,
    'fileSize' => $fileSize,
    'lastModified' => $lastMod,
    'policy' => in_array($policy, ['ask','overwrite','rename'], true) ? $policy : 'ask',
    'created' => time(),
    'updated' => time(),
  ];
  @file_put_contents($udir.'/meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
  jsend(['ok'=>true,'uploadId'=>$uploadId]);
}
if ($action === 'uploadStatus') {
  $uploadId = preg_replace('~[^a-zA-Z0-9_\-\.]~', '', (string)($_GET['uploadId'] ?? $body['uploadId'] ?? ''));
  $udir = chunk_dir($uploadId);
  if (!is_dir($udir)) jsend(['ok'=>true,'exists'=>false,'chunks'=>[]]);
  $metaPath = $udir.'/meta.json';
  $meta = is_file($metaPath) ? json_decode((string)@file_get_contents($metaPath), true) : null;
  if (!is_array($meta)) $meta = [];
  $chunks = [];
  foreach (glob($udir.'/chunk_*.part') ?: [] as $f) {
    if (preg_match('~chunk_(\d+)\.part$~', $f, $m)) $chunks[] = (int)$m[1];
  }
  sort($chunks);
  jsend(['ok'=>true,'exists'=>true,'meta'=>$meta,'chunks'=>$chunks]);
}

if ($action === 'uploadChunk') {
  $uploadId = preg_replace('~[^a-zA-Z0-9_\-\.]~', '', (string)($_POST['uploadId'] ?? $_GET['uploadId'] ?? ''));
  $index = (int)($_POST['index'] ?? $_GET['index'] ?? -1);
  $total = (int)($_POST['total'] ?? $_GET['total'] ?? 0);
  if ($uploadId === '' || $index < 0 || $total <= 0) {
  jsend([
    'ok'=>false,
    'error'=>'Bad params',
    'debug'=>[
      'uploadId'=>$uploadId,
      'index'=>$index,
      'total'=>$total,
      'post_keys'=>array_keys($_POST ?? []),
      'has_file'=>isset($_FILES['chunk']),
      'file_err'=>$_FILES['chunk']['error'] ?? null,
    ]
  ], 400);
}

if (!isset($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  jsend(['ok'=>false,'error'=>'Missing chunk'], 400);
}

$udir = chunk_dir($uploadId);
if (!is_dir($udir)) jsend(['ok'=>false,'error'=>'Unknown uploadId'], 404);
  $tmp = (string)$_FILES['chunk']['tmp_name'];
  $dst = $udir . '/chunk_' . $index . '.part';
  @mkdir(dirname($dst), 0775, true);
  $saved = false;
  $detail = null;
  if (@move_uploaded_file($tmp, $dst)) {
    $saved = true;
  } else {
    $err = error_get_last();
    $detail = $err['message'] ?? 'move_uploaded_file failed';
    if (@is_readable($tmp)) {
      if (@rename($tmp, $dst) || @copy($tmp, $dst)) {
        @unlink($tmp);
        $saved = true;
      }
    }
  }
  if (!$saved) {
    jsend(['ok'=>false,'error'=>'Save chunk failed','detail'=>$detail], 500);
  }
  $metaPath = $udir.'/meta.json';
  if (is_file($metaPath)) {
    $meta = json_decode((string)@file_get_contents($metaPath), true);
    if (is_array($meta)) {
      $meta['updated'] = time();
      $meta['total'] = $total;
      @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
    }
  }
  jsend(['ok'=>true]);
}

if ($action === 'uploadFinalize') {
  ignore_user_abort(true);
  set_time_limit(0);
  $uploadId = preg_replace('~[^a-zA-Z0-9_\-\.]~', '', (string)($_POST['uploadId'] ?? $body['uploadId'] ?? ''));
  $overwrite = (string)($_POST['policy'] ?? $body['policy'] ?? 'ask');
  if (!in_array($overwrite, ['ask','overwrite','rename'], true)) $overwrite = 'ask';
  if ($uploadId === '') jsend(['ok'=>false,'error'=>'Missing uploadId'], 400);
  $udir = chunk_dir($uploadId);
  if (!is_dir($udir)) jsend(['ok'=>false,'error'=>'Unknown uploadId'], 404);
  $metaPath = $udir.'/meta.json';
  $meta = is_file($metaPath) ? json_decode((string)@file_get_contents($metaPath), true) : null;
  if (!is_array($meta)) jsend(['ok'=>false,'error'=>'Missing meta'], 400);
  $destDir = norm_rel((string)($meta['destDir'] ?? ''));
  $relPath = norm_rel((string)($meta['relativePath'] ?? ''));
  $fileName = (string)($meta['fileName'] ?? 'file');
  $fileSize = (int)($meta['fileSize'] ?? 0);
  $destBaseAbs = abs_path($destDir);
  ensure_inside_allowed_roots($destBaseAbs);
  if (!is_dir($destBaseAbs)) jsend(['ok'=>false,'error'=>'Destination missing'], 400);
  $finalDirAbs = $destBaseAbs;
  if ($relPath !== '') {
    $finalDirAbs = $destBaseAbs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    ensure_inside_allowed_roots($finalDirAbs);
    @mkdir($finalDirAbs, 0775, true);
  }
  $finalName = $fileName;
  $finalAbs = $finalDirAbs . DIRECTORY_SEPARATOR . $finalName;
  if (file_exists($finalAbs)) {
    if ($overwrite === 'overwrite') {
    } else if ($overwrite === 'rename') {
      $finalName = unique_name($finalDirAbs, $finalName);
      $finalAbs = $finalDirAbs . DIRECTORY_SEPARATOR . $finalName;
    } else {
      jsend(['ok'=>false,'needsChoice'=>true,'error'=>'File exists'], 409);
    }
  }
  $out = @fopen($finalAbs, 'c+b');
  if (!$out) jsend(['ok'=>false,'error'=>'Cannot open output'], 500);
  if (!@flock($out, LOCK_EX)) { fclose($out); jsend(['ok'=>false,'error'=>'Lock failed'], 500); }
  @ftruncate($out, 0);
  $chunks = [];
  foreach (glob($udir.'/chunk_*.part') ?: [] as $f) {
    if (preg_match('~chunk_(\d+)\.part$~', $f, $m)) $chunks[(int)$m[1]] = $f;
  }
  if (!$chunks) { @flock($out, LOCK_UN); fclose($out); jsend(['ok'=>false,'error'=>'No chunks'], 400); }
  ksort($chunks);
  $written = 0;
  foreach ($chunks as $idx => $path) {
    $in = @fopen($path, 'rb');
    if (!$in) { @flock($out, LOCK_UN); fclose($out); jsend(['ok'=>false,'error'=>"Missing chunk $idx"], 400); }
    while (!feof($in)) {
      $buf = fread($in, 8 * 1024 * 1024);
      if ($buf === false) break;
      $w = fwrite($out, $buf);
      if ($w === false) { fclose($in); @flock($out, LOCK_UN); fclose($out); jsend(['ok'=>false,'error'=>'Write failed'], 500); }
      $written += $w;
    }
    fclose($in);
  }
  @fflush($out);
  @flock($out, LOCK_UN);
  fclose($out);
  if ($fileSize > 0 && $written !== $fileSize) {
    jsend(['ok'=>false,'error'=>"Size mismatch ($written != $fileSize)"], 500);
  }
  rrmdir($udir);
  $finalRel = norm_rel($destDir . '/' . ($relPath !== '' ? $relPath.'/' : '') . $finalName);
  jsend(['ok'=>true,'path'=>$finalRel]);
}

if ($action === 'uploadAbort') {
  $uploadId = preg_replace('~[^a-zA-Z0-9_\-\.]~', '', (string)($body['uploadId'] ?? ''));
  if ($uploadId === '') jsend(['ok'=>false,'error'=>'Missing uploadId'], 400);
  $udir = chunk_dir($uploadId);
  if (is_dir($udir)) {
    rrmdir($udir);
  }
  jsend(['ok'=>true]);
}
jsend(['ok'=>false,'error'=>'Unknown action'], 400);
}
