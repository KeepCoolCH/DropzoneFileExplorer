<?php
require_once 'login.php';

function jsend(array $data, int $code = 200): void {
  while (ob_get_level()) ob_end_clean();
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
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
  $abs = abs_path($rel);
  if (!is_dir($abs)) jsend(['ok'=>false,'error'=>'Not a directory'], 400);
  ensure_inside_root($abs);
  $items = [];
  $dh = opendir($abs);
  if (!$dh) jsend(['ok'=>false,'error'=>'Cannot open dir'], 500);
  while (($name = readdir($dh)) !== false) {
    if ($name === '.' || $name === '..') continue;
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
  ensure_inside_root($abs);
  $nodes = [];
  $dh = @opendir($abs);
  if (!$dh) return [];
  while (($name = readdir($dh)) !== false) {
    if ($name === '.' || $name === '..') continue;
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

function rrcopy(string $src, string $dst): void {
  if (is_dir($src)) {
    @mkdir($dst, 0775, true);
    $it = new DirectoryIterator($src);
    foreach ($it as $f) {
      if ($f->isDot()) continue;
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
  finfo_close($finfo);
  return $m ?: 'application/octet-stream';
}

function zip_add_path_store(ZipArchive $zip, string $absPath, string $zipPath): void {
  if (is_dir($absPath)) {
    $zip->addEmptyDir(rtrim($zipPath, '/') . '/');
    $it = new DirectoryIterator($absPath);
    foreach ($it as $f) {
      if ($f->isDot()) continue;
      zip_add_path_store($zip, $absPath . DIRECTORY_SEPARATOR . $f->getFilename(), rtrim($zipPath,'/') . '/' . $f->getFilename());
    }
  } else {
    $zip->addFile($absPath, $zipPath);
    @ $zip->setCompressionName($zipPath, ZipArchive::CM_STORE);
  }
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

// -------------------- SHARE ROUTE --------------------
if (isset($_GET['share'])) {
  $t = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['share']);
  $db = shares_db();
  if (!isset($db[$t])) {
    http_response_code(404); echo 'Not found'; exit;
  }

  $entry = $db[$t];
  $rel = (string)($entry['path'] ?? '');
  $abs = abs_path($rel);
  ensure_inside_root($abs);

  if (!file_exists($abs)) {
    http_response_code(404); echo 'Gone'; exit;
  }

// If folder => stream zip STORE
if (is_dir($abs)) {
  if (!class_exists('ZipArchive')) { http_response_code(500); echo 'ZipArchive missing'; exit; }
  $name = basename($abs) ?: 'download';
  $tmpDir = STORAGE_DIR . '/tmp';
  if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
  $tmp = $tmpDir . '/sharezip_' . bin2hex(random_bytes(8)) . '.zip';
  $zip = new ZipArchive();
  $rc = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  if ($rc !== true) {
    http_response_code(500);
    echo 'Zip open failed: ' . $rc;
    exit;
  }
  zip_add_path_store($zip, $abs, $name);
  $zip->close();

  if (!is_file($tmp) || filesize($tmp) === 0) {
    @unlink($tmp);
    http_response_code(500);
    echo 'Zip is empty or missing';
    exit;
  }

  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="'.rawurlencode($name).'.zip"');
  header('Content-Length: '.filesize($tmp));
  header('X-Accel-Buffering: no');
  while (ob_get_level()) ob_end_clean();
  readfile($tmp);
  @unlink($tmp);
  exit;
}

  // File => stream file
  $mime = detect_mime($abs);
  header('Content-Type: '.$mime);
  header('Content-Disposition: attachment; filename="'.rawurlencode(basename($abs)).'"');
  header('Content-Length: '.filesize($abs));
  readfile($abs);
  exit;
}

// -------------------- API ROUTES --------------------
$isApi = isset($_GET['api']) || (isset($_POST['api'])) || (isset($_GET['action']) && $_GET['action'] !== '');
if ($isApi) {
  $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
  $ct = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
  $body = [];
  if (stripos($ct, 'application/json') !== false) {
    $body = read_json_body();
  }

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
    auth_save($db);
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
    if (!isset($users[$user])) jsend(['ok'=>true]);
    if (count($users) <= 1) jsend(['ok'=>false,'error'=>'You cannot delete the last user.'], 400);
    unset($users[$user]);
    $db['users'] = $users;
    auth_save($db);
    if (!empty($_SESSION['auth_user']) && $_SESSION['auth_user'] === $user) {
      $_SESSION = [];
      session_destroy();
    }
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
  auth_save($db);
  jsend(['ok'=>true]);
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
  $nodes = build_tree('');
  jsend(['ok'=>true,'nodes'=>$nodes]);
}

if ($action === 'treeChildren') {
  $rel = norm_rel((string)($_GET['path'] ?? $body['path'] ?? ''));
  $nodes = build_tree($rel);
  jsend(['ok'=>true,'path'=>$rel,'nodes'=>$nodes]);
}

// --- MKDIR ---
if ($action === 'mkdir') {
  $parent = norm_rel((string)($body['parent'] ?? ''));
  $name = (string)($body['name'] ?? 'New Folder');
  $name = trim($name);
  if ($name === '' || strpbrk($name, "\\/:*?\"<>|") !== false) jsend(['ok'=>false,'error'=>'Invalid folder name'], 400);

  $absParent = abs_path($parent);
  ensure_inside_root($absParent);
  if (!is_dir($absParent)) jsend(['ok'=>false,'error'=>'Parent not a directory'], 400);

  $abs = $absParent . DIRECTORY_SEPARATOR . $name;
  if (file_exists($abs)) jsend(['ok'=>false,'error'=>'Already exists'], 400);
  if (!@mkdir($abs, 0775, true)) jsend(['ok'=>false,'error'=>'Cannot create folder'], 500);
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
    $abs = abs_path($rel);
    ensure_inside_root($abs);
    if (!file_exists($abs)) continue;
    if (is_dir($abs)) {
      if (!rrmdir($abs)) {
        jsend([
          'ok' => false,
          'error' => 'delete_failed',
          'message' => 'Folder could not be deleted (name too long?)'
        ], 400);
      }
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
  $newName = trim((string)($body['newName'] ?? ''));
  if ($newName === '' || strpbrk($newName, "\\/:*?\"<>|") !== false) jsend(['ok'=>false,'error'=>'Invalid name'], 400);
  $abs = abs_path($rel);
  ensure_inside_root($abs);
  if (!file_exists($abs)) jsend(['ok'=>false,'error'=>'Not found'], 404);
  $dstAbs = dirname($abs) . DIRECTORY_SEPARATOR . $newName;
  ensure_inside_root($dstAbs);
  if (file_exists($dstAbs)) jsend(['ok'=>false,'error'=>'Destination exists'], 400);
  if (!@rename($abs, $dstAbs)) jsend(['ok'=>false,'error'=>'Rename failed'], 500);
  jsend(['ok'=>true]);
}

// --- MOVE ---
if ($action === 'move') {
  $paths = $body['paths'] ?? [];
  $dest = norm_rel((string)($body['dest'] ?? ''));
  if (!is_array($paths) || !$paths) jsend(['ok'=>false,'error'=>'No paths'], 400);
  $destAbs = abs_path($dest);
  ensure_inside_root($destAbs);
  if (!is_dir($destAbs)) jsend(['ok'=>false,'error'=>'Destination is not a folder'], 400);
  foreach ($paths as $rel) {
    $rel = norm_rel((string)$rel);
    $abs = abs_path($rel);
    ensure_inside_root($abs);
    if (!file_exists($abs)) continue;
    $dst = $destAbs . DIRECTORY_SEPARATOR . basename($abs);
    ensure_inside_root($dst);
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
  $destAbs = abs_path($dest);
  ensure_inside_root($destAbs);
  if (!is_dir($destAbs)) jsend(['ok'=>false,'error'=>'Destination is not a folder'], 400);
  foreach ($paths as $rel) {
    $rel = norm_rel((string)$rel);
    $abs = abs_path($rel);
    ensure_inside_root($abs);
    if (!file_exists($abs)) continue;
    $dst = $destAbs . DIRECTORY_SEPARATOR . basename($abs);
    ensure_inside_root($dst);
    if (file_exists($dst)) {
      $dstName = unique_name($destAbs, basename($abs));
      $dst = $destAbs . DIRECTORY_SEPARATOR . $dstName;
    }
    rrcopy($abs, $dst);
  }
  jsend(['ok'=>true]);
}

// --- ZIP CREATE ---
if ($action === 'zipCreate') {
  $paths = $body['paths'] ?? [];
  if (!is_array($paths) || !$paths) jsend(['ok'=>false,'error'=>'No paths'], 400);
  if (!class_exists('ZipArchive')) jsend(['ok'=>false,'error'=>'ZipArchive missing (PHP extension)'], 500);
  $destDir = norm_rel((string)($body['destDir'] ?? ''));
  $destAbs = abs_path($destDir);
  ensure_inside_root($destAbs);
  if (!is_dir($destAbs)) jsend(['ok'=>false,'error'=>'Destination is not a folder'], 400);
  $zipName = (string)($body['name'] ?? 'archive');
  $zipName = trim($zipName);
  $zipName = preg_replace('~[\\\\/:*?"<>|]+~', '_', $zipName);
  if ($zipName === '') $zipName = 'archive';
  if (!preg_match('~\.zip$~i', $zipName)) $zipName .= '.zip';
  $policy = (string)($body['policy'] ?? 'rename');
  if (!in_array($policy, ['overwrite','rename','ask'], true)) $policy = 'rename';
  $finalName = $zipName;
  $finalAbs = $destAbs . DIRECTORY_SEPARATOR . $finalName;
  if (file_exists($finalAbs)) {
    if ($policy === 'overwrite') {
    } elseif ($policy === 'rename') {
      $finalName = unique_name($destAbs, $finalName);
      $finalAbs = $destAbs . DIRECTORY_SEPARATOR . $finalName;
    } else {
      jsend(['ok'=>false,'needsChoice'=>true,'error'=>'ZIP exists'], 409);
    }
  }
  $zip = new ZipArchive();
  $openRes = $zip->open($finalAbs, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  if ($openRes !== true) {
    jsend(['ok'=>false,'error'=>'Zip open failed','detail'=>'ZipArchive::open returned '.$openRes], 500);
  }
  try {
    foreach ($paths as $rel) {
      $rel = norm_rel((string)$rel);
      if ($rel === '') continue;

      $abs = abs_path($rel);
      ensure_inside_root($abs);
      if (!file_exists($abs)) continue;

      $base = basename($abs) ?: 'item';
      zip_add_path_store($zip, $abs, $base);
    }
  } catch (Throwable $e) {
    $zip->close();
    @unlink($finalAbs);
    jsend(['ok'=>false,'error'=>'Zip build failed','detail'=>$e->getMessage()], 500);
  }
  $zip->close();
  if (!is_file($finalAbs) || filesize($finalAbs) === 0) {
    @unlink($finalAbs);
    jsend(['ok'=>false,'error'=>'Zip is empty or missing'], 500);
  }
  $finalRel = norm_rel(($destDir !== '' ? $destDir.'/' : '') . $finalName);
  jsend(['ok'=>true,'path'=>$finalRel]);
}

// --- UNZIP ---
if ($action === 'unzip') {
  if (!class_exists('ZipArchive')) jsend(['ok'=>false,'error'=>'ZipArchive missing'], 500);
  $rel  = norm_rel((string)($body['path'] ?? ''));
  $dest = norm_rel((string)($body['dest'] ?? dirname($rel)));
  $policy = (string)($body['policy'] ?? 'ask');
  if (!in_array($policy, ['ask','overwrite','rename'], true)) $policy = 'ask';
  $abs     = abs_path($rel);
  $destAbs = abs_path($dest);
  ensure_inside_root($abs);
  ensure_inside_root($destAbs);
  if (!is_file($abs)) jsend(['ok'=>false,'error'=>'Not a file'], 400);
  if (!is_dir($destAbs)) {
    if (!@mkdir($destAbs, 0775, true)) jsend(['ok'=>false,'error'=>'Cannot create destination folder'], 500);
  }
  $zip = new ZipArchive();
  if ($zip->open($abs) !== true) jsend(['ok'=>false,'error'=>'Cannot open zip'], 400);

  // -------- Detect single top-level folder --------
  $top = null;
  $singleTop = true;
  for ($i=0; $i<$zip->numFiles; $i++) {
    $st = $zip->statIndex($i);
    $name = (string)($st['name'] ?? '');
    if ($name === '') continue;
    if (str_starts_with($name, '__MACOSX/')) continue;
    $clean = norm_rel($name);
    if ($clean === '') continue;
    $parts = explode('/', $clean);
    $first = $parts[0] ?? '';
    if ($first === '') continue;
    if (count($parts) === 1) {
  if (str_ends_with($name, '/')) {
    if ($top === null) $top = $first;
    else if ($top !== $first) { $singleTop = false; break; }
    continue;
  }
  $singleTop = false;
  break;
}

  if ($top === null) $top = $first;
  else if ($top !== $first) {
    $singleTop = false; break;
  }
}

  // -------- Decide extraction base --------
  $stripPrefix = '';
  $baseDirAbs = $destAbs;
  if ($singleTop && $top !== null) {
    $stripPrefix = $top . '/';
    $folderName = $top;
    $folderAbs  = $destAbs . DIRECTORY_SEPARATOR . $folderName;
    if (file_exists($folderAbs)) {
      if ($policy === 'ask') {
        $zip->close();
        jsend([
          'ok' => false,
          'needsChoice' => true,
          'error' => 'Folder exists',
          'path' => norm_rel(($dest !== '' ? $dest.'/' : '') . $folderName),
        ], 409);
      }
      if ($policy === 'rename') {
        $folderName = unique_name($destAbs, $folderName);
        $folderAbs  = $destAbs . DIRECTORY_SEPARATOR . $folderName;
      }
    }
    if (!is_dir($folderAbs)) {
      if (!@mkdir($folderAbs, 0775, true)) {
        $zip->close();
        jsend(['ok'=>false,'error'=>'Cannot create target folder'], 500);
      }
    }
    $baseDirAbs = $folderAbs;
    ensure_inside_root($baseDirAbs);
  }

  // -------- Safe extraction --------
  for ($i=0; $i<$zip->numFiles; $i++) {
    $st = $zip->statIndex($i);
    $name = (string)($st['name'] ?? '');
    if ($name === '') continue;
    if (str_starts_with($name, '__MACOSX/')) continue;
    $clean = norm_rel($name);
    if ($clean === '') continue;
    $isDir = str_ends_with($name, '/');
    if ($isDir) continue;
    if ($stripPrefix !== '' && str_starts_with($clean, $stripPrefix)) {
      $clean = substr($clean, strlen($stripPrefix));
      $clean = norm_rel($clean);
      if ($clean === '') continue;
    }
    $target = $baseDirAbs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
    ensure_inside_root($target);
    if (file_exists($target)) {
      if ($policy === 'ask') {
        $zip->close();
        jsend([
          'ok' => false,
          'needsChoice' => true,
          'error' => 'File exists',
          'path' => norm_rel(($dest !== '' ? $dest.'/' : '') . ($singleTop && $top ? ($folderName.'/'.$clean) : $clean)),
        ], 409);
      }
      if ($policy === 'rename') {
        $dirAbs = dirname($target);
        $base   = basename($target);
        $newBase = unique_name($dirAbs, $base);
        $target = $dirAbs . DIRECTORY_SEPARATOR . $newBase;
      }
    }
    @mkdir(dirname($target), 0775, true);
    $in = $zip->getStream($st['name']);
    if (!$in) { $zip->close(); jsend(['ok'=>false,'error'=>'Cannot read entry','detail'=>$st['name']], 500); }
    $out = @fopen($target, 'wb');
    if (!$out) { fclose($in); $zip->close(); jsend(['ok'=>false,'error'=>'Cannot write file','detail'=>$clean], 500); }
    while (!feof($in)) {
      $buf = fread($in, 1024 * 1024);
      if ($buf === false) break;
      fwrite($out, $buf);
    }
    fclose($out);
    fclose($in);
  }
  $zip->close();
  jsend([
    'ok'=>true,
    'singleTop'=>($singleTop && $top !== null),
    'folder'=>($singleTop && $top !== null) ? $folderName : null
  ]);
}

// --- PREVIEW ---
if ($action === 'preview') {
  $rel = norm_rel((string)($_GET['path'] ?? ''));
  $abs = abs_path($rel);
  ensure_inside_root($abs);
  if (!is_file($abs)) { http_response_code(404); exit; }
  $mime = detect_mime($abs);
  $inline = (bool)($_GET['inline'] ?? true);
  header('Content-Type: '.$mime);
  header('Content-Length: '.filesize($abs));
  header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.rawurlencode(basename($abs)).'"');
  readfile($abs);
  exit;
}

// --- READ TEXT ---
if ($action === 'readText') {
  $rel = norm_rel((string)($body['path'] ?? ''));
  $abs = abs_path($rel);
  ensure_inside_root($abs);
  if (!is_file($abs)) jsend(['ok'=>false,'error'=>'Not found'], 404);
  $size = filesize($abs);
  if ($size > SAFE_TEXT_MAX) jsend(['ok'=>false,'error'=>'File too large for editor'], 400);
  $txt = (string)@file_get_contents($abs);
  jsend(['ok'=>true,'text'=>$txt]);
}

// --- SAVE TEXT (editor) ---
if ($action === 'saveText') {
  $rel = norm_rel((string)($body['path'] ?? ''));
  $text = (string)($body['text'] ?? '');
  $abs = abs_path($rel);
  ensure_inside_root($abs);
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
  ensure_inside_root($abs);
  if (!file_exists($abs)) jsend(['ok'=>false,'error'=>'Not found'], 404);
  $db = shares_db();
  $t = token();
  $db[$t] = ['path'=>$rel, 'created'=>time()];
  shares_save($db);
  $url = (BASE_URL !== '' ? BASE_URL : (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?')) . '?share=' . $t;
  jsend(['ok'=>true,'token'=>$t,'url'=>$url]);
}

// --- SHARE REVOKE ---
if ($action === 'shareRevoke') {
  $t = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)($body['token'] ?? ''));
  $db = shares_db();
  if (isset($db[$t])) { unset($db[$t]); shares_save($db); }
  jsend(['ok'=>true]);
}

// --- SHARE LIST ---
if ($action === 'shareList') {
  $db = shares_db();
  $base = (BASE_URL !== '' ? BASE_URL : ((isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?')));
  $out = [];
  foreach ($db as $token => $entry) {
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
  ensure_inside_root($destAbs);
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
  ensure_inside_root($destBaseAbs);
  if (!is_dir($destBaseAbs)) jsend(['ok'=>false,'error'=>'Destination missing'], 400);
  $finalDirAbs = $destBaseAbs;
  if ($relPath !== '') {
    $finalDirAbs = $destBaseAbs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    ensure_inside_root($finalDirAbs);
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

// --- DOWNLOAD PREPARE ---
if ($action === 'downloadPrepare') {
  downloads_gc();
$paths = array_values(array_filter(
  (array)($body['paths'] ?? []),
  'is_string'
));
if ($paths) {
  $absList = [];
  foreach ($paths as $p) {
    $r = norm_rel($p);
    $a = abs_path($r);
    ensure_inside_root($a);
    if (file_exists($a)) $absList[] = $a;
  }
  if (!$absList) {
    jsend(['ok'=>false,'error'=>'Nothing to zip'], 400);
  }
} else {
  $rel = norm_rel((string)($body['path'] ?? ''));
  $abs = abs_path($rel);
  ensure_inside_root($abs);
  if (!is_dir($abs)) {
    jsend(['ok'=>true,'kind'=>'file']);
  }
  $absList = [$abs];
}
  if (!class_exists('ZipArchive')) {
    jsend(['ok'=>false,'error'=>'ZipArchive missing'], 500);
  }
if ($paths) {
  $folder = 'selection';
} else {
  $folder = basename($abs) ?: 'folder';
  if ($folder === 'files') {
    $folder = 'dl';
  }
}
  $tmpDir = STORAGE_DIR.'/tmp';
  if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
  $tok = token(18);
  $tmp = "$tmpDir/dl_$tok.zip";
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) {
    jsend(['ok'=>false,'error'=>'Cannot create zip'], 500);
  }
  try {
    $rootName = $paths ? 'selection' : (basename($abs) ?: 'folder');
    foreach ($absList as $a) {
      $name = basename($a);
      if (count($absList) === 1) {
        zip_add_path_store($zip, $a, $name);
      } else {
        zip_add_path_store($zip, $a, 'selection/' . $name);
      }
    }
  } catch (Throwable $e) {
    $zip->close();
    @unlink($tmp);
    jsend(['ok'=>false,'error'=>'Zip build failed','detail'=>$e->getMessage()], 500);
  }
  $zip->close();
  $db = downloads_db();
  $db[$tok] = [
    'tmp' => $tmp,
    'name' => $folder.'_'.bin2hex(random_bytes(6)).'.zip',
    'created' => time(),
  ];
  downloads_save($db);
  jsend(['ok'=>true,'kind'=>'dir','token'=>$tok,'name'=>$db[$tok]['name']]);
}

// --- DOWNLOAD ---
if ($action === 'download') {
$dl = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)($_GET['dl'] ?? ''));
if ($dl !== '') {
  downloads_gc();
  $db = downloads_db();
  if (!isset($db[$dl])) { http_response_code(404); exit; }
  $entry = $db[$dl];
  $tmp = (string)($entry['tmp'] ?? '');
  $name = (string)($entry['name'] ?? 'download.zip');
  if ($tmp === '' || !is_file($tmp)) { http_response_code(404); exit; }
  while (ob_get_level()) { ob_end_clean(); }
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

    if (!class_exists('ZipArchive')) {
    }
    $rel = norm_rel((string)($_GET['path'] ?? ''));
    $abs = abs_path($rel);
    ensure_inside_root($abs);
    if (!file_exists($abs)) {
      http_response_code(404);
      exit;
    }
    while (ob_get_level()) { ob_end_clean(); }

    if (is_dir($abs)) {
      if (!class_exists('ZipArchive')) jsend(['ok'=>false,'error'=>'ZipArchive missing'], 500);
      $folderName = basename($abs) ?: 'folder';
      $tmpDir = STORAGE_DIR . '/tmp';
      if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
      $tmp = $tmpDir . '/folderzip_' . bin2hex(random_bytes(8)) . '.zip';
      $zip = new ZipArchive();
      $rc = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
      if ($rc !== true) jsend(['ok'=>false,'error'=>'Cannot create zip','detail'=>'ZipArchive::open returned '.$rc], 500);
      zip_add_path_store($zip, $abs, $folderName);
      $zip->close();
      header('Content-Type: application/zip');
      header('Content-Disposition: attachment; filename="'.rawurlencode($folderName).'.zip"');
      header('Content-Length: '.filesize($tmp));
      header('X-Accel-Buffering: no');
      readfile($tmp);
      @unlink($tmp);
      exit;
    }

    // --- File => stream as attachment ---
    $mime = detect_mime($abs);
    header('Content-Type: '.$mime);
    header('Content-Disposition: attachment; filename="'.rawurlencode(basename($abs)).'"');
    header('Content-Length: '.filesize($abs));
    header('X-Accel-Buffering: no');
    readfile($abs);
    exit;
  }
  jsend(['ok'=>false,'error'=>'Unknown action'], 400);
}
