<?php
/**
 * Dropzone File Explorer
 *
 * Developed by Kevin Tobler
 * www.kevintobler.ch
 */

declare(strict_types=1);
require_once 'inc/config.php';
require_once 'inc/functions.php';

$uiError = null;

try {
  require_user_roots();
}
catch (RuntimeException $e) {
  if ($e->getMessage() === 'NO_RIGHTS') {
    $uiError = 'No folder permissions assigned. Please contact the administrator.';
  } else {
    throw $e;
  }
}

if (
    isset($_GET['action'], $_GET['dl']) &&
    $_GET['action'] === 'download'
) {
    $token = $_GET['dl'];
    $db = downloads_db();
    if (!isset($db[$token])) {
        http_response_code(403);
        exit('Invalid or expired download token');
    }
    $entry = $db[$token];
    $tmp   = $entry['tmp'];
    $name  = $entry['name'];
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.rawurlencode($name).'"');
    header('Content-Length: '.filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    unset($db[$token]);
    downloads_save($db);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(function($no, $str, $file, $line) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode([
    'ok' => false,
    'error' => 'Internal server error',
  ]);
  exit;
});

set_exception_handler(function(Throwable $e) {
  if (!headers_sent() && isset($_GET['api'])) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => false,
      'error' => 'Internal server error',
    ]);
  } else {
    http_response_code(500);
    echo '<h1>Internal error</h1>';
  }
  exit;
});

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

$https =
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
  || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
  || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

if ($https) {
  header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

require_once 'login.php';

if (
  !isset($_GET['share']) &&
  !(isset($_GET['action'], $_GET['dl']) && $_GET['action'] === 'download')
) {
  auth_require_or_render_login();
}

$rootTotalSize = format_bytes(dir_total_size(''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=0.6">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars(APP_TITLE) ?></title>
  <meta name="description" content="Dropzone File Explore is a simple, self-hosted file manager designed for performance, usability and security. It allows you to browse, upload, manage and share files directly in the browser – without a database and without external dependencies.">
  <link rel="icon" href="img/favicon.png">
  <link rel="apple-touch-icon" href="img/favicon.png">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
  <div class="app">
    <!-- LEFT: TREE -->
    <section class="card">
      <div class="card-sticky">
      <header>
        <div style="height: 96px;"><a href="index.php"><img src="img/logo.png" alt="Dropzone File Explorer" width="295"></a></div>
      </header>
      <div class="panel-fixed">
      <?php if (AUTH_ENABLE && !auth_is_admin()): ?><button class="btn" id="btnMyPw">🔑 Change Password</button><?php endif; ?>
      <?php if (AUTH_ENABLE && auth_is_admin()): ?><button class="btn secondary" id="btnUsers">👤 Manage Users</button><?php endif; ?>
      <?php if (AUTH_ENABLE): ?><button class="btn danger" onclick="location.href='?logout=1'">🚪 Logout</button><?php endif; ?>
      <hr>
      <div id="rootSize" class="small" style="margin-left: 5px;">📦 Storage used: <?= htmlspecialchars($rootTotalSize) ?></div>
      <?php if (!empty($uiError)): ?>
        <div class="small" style="margin-left: 5px;">⚠️ <?= htmlspecialchars($uiError) ?></div>
      <?php endif; ?>
      <hr>
      <button class="btn primary" id="btnRefreshTree"><b>↻ Reload</b></button>
      </div>
      </div>
      <div class="panel-scroll-tree">
      <div class="tree" id="tree"></div>
      </div>
    </section>

    <!-- CENTER: LIST -->
    <section class="card">
    <div class="card-sticky">
    <header>
      <div class="header-col">
        <div style="min-width:240px">
          <div class="crumbs" id="crumbs"></div>
          <div class="small" id="pathInfo" style="margin-top: 8px;"></div>
        </div>
        <div class="row header-actions">
          <button class="btn primary" id="btnUpload">⬆ Upload<b>•</b><b>Shift + Click</b>for folders</button>
          <button class="btn primary" id="btnDownload">⬇ Download</button>
          <button class="btn" id="btnNewFolder">📁 New Folder</button>
          <button class="btn" id="btnRename">✏️ Rename</button>
          <button class="btn" id="btnCopy">📄 Copy</button>
          <button class="btn" id="btnMove">📦 Move</button>
          <button class="btn danger" id="btnDelete">🗑 Delete</button>
          <button class="btn" id="btnZip">🗜 Create ZIP</button>
          <button class="btn" id="btnUnzip">📦 Extract ZIP</button>
          <button class="btn" id="btnShare">🔗 Create Share</button>
          <button class="btn" id="btnShares">🔗 Manage Shares</button>
        </div>
      </div>
    </header>
    <div class="panel-fixed">
      <div class="hideDropzone">
        <div class="dropzone" id="dropzone">
          <div>
            <div style="font-weight:700">Drag & Drop Upload</div>
            <div class="small">Drag files/folders here or click <span class="kbd">Upload-Button</span>. Resumable chunk upload.</div>
          </div>
          <div class="row">
            <span class="tag" id="selTag">0 selected</span>
            <button class="btn" id="btnPause" style="display:none">⏸ Pause</button>
            <button class="btn danger" id="btnCancel" style="display:none">✖ Cancel</button>
          </div>
        </div>
      </div>

        <div id="uploads" style="display:grid; gap:10px;"></div>

        <div id="statusBar">
        <div id="loadingSpinner" style="display:none;"><div class="spinner"></div></div>
        <div class="small" id="status">Ready.</div>
        </div>

        <div style="margin-top: 8px; margin-bottom: 8px;">
        <input type="text" id="search" placeholder="Search in folder… (name contains)">
        </div>
        </div>
      </div>
        <div class="panel-scroll">
        <table class="list" id="list">
          <thead>
            <tr>
              <th style="width:40%" data-sort="name">Name</th>
              <th data-sort="type">Type</th>
              <th data-sort="size">Size</th>
              <th data-sort="changed">Changed</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        </div>
        <input type="file" id="fileInput" multiple style="display:none">
        <input type="file" id="dirInput" webkitdirectory directory multiple style="display:none">
    </section>

    <!-- RIGHT: PREVIEW + EDITOR -->
    <section class="card">
      <div class="card-sticky">
      <header>
        <div class="title">Preview & Editor</div>
        <div class="row">
          <button class="btn ok" id="btnSave">💾 Save</button>
        </div>
      </header>
      <div class="panel-fixed">
        <div class="previewBox">
          <div class="previewTop">
            <div>
              <div style="font-weight:700" id="pvTitle">Nothing selected</div>
              <div class="small" id="pvMeta"></div>
            </div>
            <div class="row">
              <span class="tag" id="pvKind">—</span>
              <button class="btn" id="pvOpen" style="display:none">↗ Open</button>
            </div>
          </div>
          <div id="pvBody" style="padding:10px">
            <div class="small muted">Select a file to view a preview. Text files can be edited in the editor.</div>
          </div>
        </div>

        <textarea class="editor" id="editor" placeholder="Editor… (only Text-Files)" style="margin-top:10px"></textarea>
        <div class="small muted" style="padding:10px 0px 10px 0px">
          Tips: Multi-select with <span class="kbd">⌘</span> / <span class="kbd">Ctrl</span>. Double-click folders to open.
        </div>
      </div>
      </div>
    </section>
  </div>

<div id="ctxMenu" class="ctx" style="display:none">
  <button data-act="open">📂 Open</button>
  <button data-act="download">⬇ Download</button>
  <div class="sep"></div>
  <button data-act="rename">✏️ Rename</button>
  <button data-act="copy">📄 Copy…</button>
  <button data-act="move">📦 Move…</button>
  <button data-act="delete" class="danger">🗑 Delete</button>
  <div class="sep"></div>
  <button data-act="zip">🗜 Create ZIP</button>
  <button data-act="unzip">📦 Extract ZIP</button>
  <button data-act="share">🔗 Create Share-Link</button>
</div>

<div id="sharePanel" class="card" style="
  position:fixed;
  inset:0;
  display:none;
  place-items:center;
  background:rgba(0,0,0,.45);
  z-index:10000;
">
  <div style="width:680px; max-width:92vw" class="card">
    <header>
      <div class="title">🔗 Share-Links</div>
      <button class="btn" id="btnCloseShares">✕</button>
    </header>
    <div class="pad" id="shareListBox" style="display:grid; gap:10px"></div>
  </div>
</div>

<div id="userPanel" class="card" style="
  position:fixed;
  inset:0;
  display:none;
  place-items:center;
  background:rgba(0,0,0,.45);
  z-index:10002;
">
  <div style="width:760px; max-width:92vw" class="card">
    <header>
      <div class="title">👤 User management</div>
      <button class="btn danger" id="btnCloseUsers">✕</button>
    </header>

    <div class="pad" style="display:grid; gap:12px">
      <!-- Add user -->
      <div class="card" style="border-radius:14px">
        <header>
          <div class="title">➕ New User</div>
          <div class="small muted">Password minimum 8 characters</div>
        </header>
        <div class="pad" style="display:grid; gap:10px">
          <div class="row">
            <div style="flex:1">
              <div class="small" style="margin-bottom:6px">Username</div>
              <input type="text" id="uNewUser" placeholder="e.g. user" minlength="3">
            </div>
            <div style="flex:1">
              <div class="small" style="margin-bottom:6px">Password</div>
              <input type="password" id="uNewPass" placeholder="••••••••" minlength="8">
            </div>
            <div style="flex:1">
              <div class="small" style="margin-bottom:6px">Repeat Password</div>
              <input type="password" id="uNewPass2" placeholder="••••••••" minlength="8">
            </div>
          </div>
          <div class="row" style="justify-content:flex-end">
            <button class="btn primary" id="btnUserAdd">Create User</button>
          </div>
        </div>
      </div>

      <!-- List -->
      <div class="card" style="border-radius:14px">
        <header>
          <div class="title">📋 Existing Users</div>
          <button class="btn" id="btnUsersRefresh">↻</button>
        </header>
        <div class="pad" id="userListBox" style="display:grid; gap:10px"></div>
      </div>
    </div>
  </div>
</div>

<div id="folderPicker" class="card" style="
  position:fixed;
  inset:0;
  display:none;
  place-items:center;
  background:rgba(0,0,0,.45);
  z-index:10001;
">
  <div style="width:720px; max-width:94vw; max-height:84vh;" class="card">
    <header>
      <div class="title" id="fpTitle">📦 Select destination folder</div>
      <button class="btn" id="fpClose">✕</button>
    </header>

    <div class="pad" style="display:grid; gap:10px; max-height:calc(84vh - 54px); overflow:auto;">
      <div class="small muted">
        Click a folder, then confirm below.
      </div>

      <div id="fpTree" style="display:grid; gap:6px;"></div>

      <div class="row" style="justify-content:space-between; align-items:center; margin-top:8px;">
        <div class="tag" id="fpSelected">Destination: /</div>
        <div class="row">
          <button class="btn" id="fpCancel">Cancel</button>
          <button class="btn primary" id="fpOk">Confirm</button>
        </div>
      </div>
    </div>
  </div>
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
<div class="toast" id="toast"></div>
<footer><?= htmlspecialchars(APP_TITLE) ?> V.1.2 © 2026 by Kevin Tobler - <a href='https://kevintobler.ch' target='_blank'>www.kevintobler.ch</a></footer>
<script>
  window.APP_CONFIG = { CHUNK_SIZE: <?= (int)CHUNK_SIZE_DEFAULT ?> };
  window.IS_ADMIN = <?= auth_is_admin() ? 'true' : 'false' ?>;
  window.IS_SHARE_VIEW = <?= defined('IS_PUBLIC_SHARE') && IS_PUBLIC_SHARE ? 'true' : 'false' ?>;
</script>
<script src="js/main.js?v=<?= (int)@filemtime(__DIR__ . '/js/main.js') ?>" defer></script>
</body>
</html>
