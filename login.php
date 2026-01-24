<?php
require_once 'inc/config.php';

// -------------------- AUTH (session login) --------------------
function auth_dir(): string {
  $d = dirname(AUTH_FILE);
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}

function auth_db(): array {
  if (!is_file(AUTH_FILE)) return [];
  $j = json_decode((string)@file_get_contents(AUTH_FILE), true);
  return is_array($j) ? $j : [];
}

function auth_save(array $db): void {
  auth_dir();
  @file_put_contents(
    AUTH_FILE,
    json_encode($db, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),
    LOCK_EX
  );
}

function auth_has_account(): bool {
  $db = auth_db();
  return !empty($db['users']) && is_array($db['users']) && count($db['users']) > 0;
}

function auth_start_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $secure =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
    session_name(AUTH_COOKIE_NAME);
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'httponly' => true,
      'secure' => $secure,
      'samesite' => 'Lax',
    ]);
  session_start();
}

function csrf_token(): string {
  auth_start_session();
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['_csrf'];
}

function csrf_check(?string $t): bool {
  auth_start_session();
  return isset($_SESSION['_csrf']) && is_string($t) && hash_equals($_SESSION['_csrf'], $t);
}

function auth_is_logged_in(): bool {
  auth_start_session();
  return !empty($_SESSION['auth_ok']) && $_SESSION['auth_ok'] === true;
}

function auth_current_user(): string {
  auth_start_session();
  return (string)($_SESSION['auth_user'] ?? '');
}

function auth_is_admin(): bool {
  if (!auth_is_logged_in()) return false;
  $u = auth_current_user();
  if ($u === '') return false;
  $db = auth_db();
  $entry = $db['users'][$u] ?? null;
  if (!is_array($entry)) return false;
  return (($entry['role'] ?? '') === 'admin');
}

function auth_require_or_render_login(): void {
  if (!AUTH_ENABLE) return;
  auth_start_session();
  if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header('Location: index.php');
    exit;
  }
  if (auth_is_logged_in()) return;
  $isSetup = !auth_has_account();
  $err = '';
  $okMsg = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    if (!csrf_check($_POST['csrf'] ?? null)) {
      $err = 'Invalid session (CSRF). Please reload the page.';
    } else {
      $action = (string)($_POST['auth_action'] ?? '');
      if ($action === 'setup') {
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');
        $pass2 = (string)($_POST['pass2'] ?? '');
        if ($user === '' || strlen($user) < 3) $err = 'Username too short (minimum 3 characters).';
        elseif (strpbrk($user, "\\/:*?\"<>| \t\r\n") !== false) $err = 'Username contains invalid characters.';
        elseif ($pass === '' || strlen($pass) < 8) $err = 'Password too short (minimum 8 characters).';
        elseif ($pass !== $pass2) $err = 'Passwords do not match.';
        else {
          $db = auth_db();
          if (!isset($db['users']) || !is_array($db['users'])) $db['users'] = [];
          if (count($db['users']) > 0) {
            $err = 'Setup already completed. Please log in.';
          } else {
            $db['users'][$user] = [
              'hash' => password_hash($pass, PASSWORD_DEFAULT),
              'created' => time(),
              'role' => 'admin',
            ];
            auth_save($db);
            $_SESSION['auth_ok'] = true;
            $_SESSION['auth_user'] = $user;
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
          }
        }
      } elseif ($action === 'login') {
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');
        $db = auth_db();
        $users = (array)($db['users'] ?? []);
        $entry = $users[$user] ?? null;
        if (!is_array($entry) || empty($entry['hash']) || !is_string($entry['hash'])) {
          $err = 'Login failed.';
        } elseif (!password_verify($pass, $entry['hash'])) {
          $err = 'Login failed.';
        } else {
          $_SESSION['auth_ok'] = true;
          $_SESSION['auth_user'] = $user;
          session_regenerate_id(true);
          header('Location: index.php');
          exit;
        }
      }
    }
  }

  $isApi = isset($_GET['api']) || (isset($_POST['api'])) || (isset($_GET['action']) && $_GET['action'] !== '');
  if ($isApi) {
    jsend(['ok'=>false,'error'=>'Auth required.'], 401);
  }
  $title = htmlspecialchars(APP_TITLE, ENT_QUOTES, 'UTF-8');
  $csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
  $hErr = $err ? '<div style="margin-top:10px; padding:10px 12px; border-radius:14px; border:1px solid rgba(255,90,122,.55); background:rgba(255,90,122,.12); color:#ffd7df; font-size:13px;">'.htmlspecialchars($err).'</div>' : '';
  $hOk  = $okMsg ? '<div style="margin-top:10px; padding:10px 12px; border-radius:14px; border:1px solid rgba(40,209,124,.55); background:rgba(40,209,124,.10); color:#d8ffe9; font-size:13px;">'.htmlspecialchars($okMsg).'</div>' : '';
  $heading = $isSetup ? '👤 Create account' : '🔐 Login';
  $sub = $isSetup
    ? 'First launch: Create your admin account.'
    : 'Please log in to continue.';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $title ?> — Login</title>
  <meta name="description" content="Dropzone File Explore is a simple, self-hosted file manager designed for performance, usability and security. It allows you to browse, upload, manage and share files directly in the browser – without a database and without external dependencies.">
  <link rel="icon" href="img/favicon.png">
  <link rel="apple-touch-icon" href="img/favicon.png">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
  <div class="pad">
    <section class="card">
      <header>
        <div style="height: 88px;"><a href="index.php"><img src="img/logo.png" alt="Dropzone File Explorer" width="295"></a></div>
      </header>
    </section>
  </div>
  <div class="pad">
    <div class="card">
      <header>
        <div class="title"><?= htmlspecialchars(APP_TITLE) ?></div>
        <div class="small"><?= htmlspecialchars($heading) ?></div>
      </header>
      <div class="pad">
        <div style="font-weight:800; font-size:18px"><?= htmlspecialchars($heading) ?></div>
        <div class="small"><?= htmlspecialchars($sub) ?></div>
        <?= $hErr ?>
        <?= $hOk ?>
        <form method="post" autocomplete="off" style="display:grid; gap:10px; margin-top:6px">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="auth_action" value="<?= $isSetup ? 'setup' : 'login' ?>">
          <div>
            <div class="small" style="margin-bottom:6px">Username</div>
            <input type="text" name="user" required minlength="3" placeholder="username">
          </div>
          <div>
            <div class="small" style="margin-bottom:6px">Password</div>
            <input type="password" name="pass" required minlength="8" placeholder="••••••••">
          </div>
          <?php if ($isSetup): ?>
          <div>
            <div class="small" style="margin-bottom:6px">Repeat Password</div>
            <input type="password" name="pass2" required minlength="8" placeholder="••••••••">
          </div>
          <?php endif; ?>
          <div class="row" style="justify-content:space-between; margin-top:4px">
            <div class="small">
              <?= $isSetup ? 'Tip: Password must be at least 8 characters.' : '' ?>
            </div>
            <button class="btn primary" type="submit"><?= $isSetup ? 'Create account' : 'Login' ?></button>
          </div>
        </form>
        <div class="sep"></div>
        <div class="small">
          Note: Login credentials are stored in <code style="color:var(--muted)"><?= htmlspecialchars(basename(AUTH_FILE)) ?></code>.
        </div>
      </div>
    </div>
  </div>
<footer><?= htmlspecialchars(APP_TITLE) ?> V.1.0 © 2026 von Kevin Tobler - <a href='https://kevintobler.ch' target='_blank'>www.kevintobler.ch</a></footer>
</body>
</html>
<?php
  exit;
}
auth_require_or_render_login();