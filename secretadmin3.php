<?php
session_start();

// Ensure username is a string to avoid trim(array) errors
$username = (string)($_SESSION['username'] ?? '');
$username = trim($username);
if ($username === '') {
    header('Location: login.php');
    exit;
}

// Load admin list from admin.json
$admin_users = [];
if (file_exists('admin.json')) {
    $adm = json_decode(file_get_contents('admin.json'), true);
    if (is_array($adm)) {
        if (isset($adm['admins']) && is_array($adm['admins'])) $admin_users = $adm['admins'];
        else $admin_users = $adm;
    }
}

// Load admin session list from admin_session.json
$admin_sessions = [];
if (file_exists('admin_session.json')) {
    $as = json_decode(file_get_contents('admin_session.json'), true);
    if (is_array($as)) {
        if (isset($as['admins']) && is_array($as['admins'])) $admin_sessions = $as['admins'];
        else $admin_sessions = $as;
    }
}

// helper: flatten nested arrays into string values
if (!function_exists('__flatten_strings')) {
    function __flatten_strings(array $arr) {
        $out = [];
        foreach ($arr as $v) {
            if (is_array($v)) {
                $out = array_merge($out, __flatten_strings($v));
            } elseif (is_scalar($v)) {
                $out[] = (string)$v;
            }
        }
        return $out;
    }
}

// normalize lists safely
$admin_users    = array_values(array_unique(__flatten_strings($admin_users)));
$admin_sessions = array_values(array_unique(__flatten_strings($admin_sessions)));

$admin_users    = array_map('strtolower', array_map('trim', $admin_users));
$admin_sessions = array_map('strtolower', array_map('trim', $admin_sessions));

$normalized_username = strtolower($username);

// Allow only if user exists in BOTH admin.json and admin_session.json
if (in_array($normalized_username, $admin_users, true) && in_array($normalized_username, $admin_sessions, true)) {
    // allowed: proceed
} else {
    header("Location: index.php");
    exit;
}

// Check if user is logged in and is admin
if (!isset($_SESSION['ghostlan_admin']) || !$_SESSION['ghostlan_admin'] ||
    !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header("Location: index.php");
    exit;
}

// Additional security check: verify session expiration (for admin only)
$session_file = 'session.txt';
if (file_exists($session_file)) {
    $session_time = trim(file_get_contents($session_file));
    if (!isset($_SESSION['login_time']) || $_SESSION['login_time'] < $session_time) {
        session_unset();
        session_destroy();
        header("Location: index.php");
        exit;
    }
}

$error_message = '';
$success_message = '';

// Read currently blocked files from .htaccess
$current_files = [];
$htfile = '.htaccess';
if (file_exists($htfile)) {
    $ht = file_get_contents($htfile);
    // Match: <Files "filename"> ... Require all denied ... </Files>
    if (preg_match_all('/<Files\s+"([^"]+)"\s*>\s*Require all denied\s*<\/Files>/i', $ht, $m)) {
        $current_files = $m[1];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret_key      = trim($_POST['secret_key']      ?? '');
    $block_files     = trim($_POST['block_files']     ?? '');
    $unblock_files   = trim($_POST['unblock_files']   ?? '');

    if (empty($secret_key) || (empty($block_files) && empty($unblock_files))) {
        $error_message = "Secret key and at least one of block/unblock are required";
    } else {
        if (file_exists('secret.json')) {
            $secret_data   = json_decode(file_get_contents('secret.json'), true);
            $correct_secret = trim((string)($secret_data['secret_key'] ?? ''));

            if ($secret_key === $correct_secret) {
                // Parse a comma/space/semicolon separated list of filenames
                $parse_filenames = function($raw) {
                    $parts = preg_split('/[\s,;]+/', $raw);
                    $out = [];
                    foreach ($parts as $p) {
                        $p = trim($p);
                        // Allow only safe filename characters
                        if ($p !== '' && preg_match('/^[a-zA-Z0-9._\-]+$/', $p)) {
                            $out[] = $p;
                        }
                    }
                    return array_values(array_unique($out));
                };

                $existing = file_exists($htfile) ? file_get_contents($htfile) : '';
                $merged   = $current_files;

                // Add files to block
                if (!empty($block_files)) {
                    $adds = $parse_filenames($block_files);
                    foreach ($adds as $a) {
                        if (!in_array($a, $merged, true)) $merged[] = $a;
                    }
                }

                // Remove files to unblock
                if (!empty($unblock_files)) {
                    $removes = $parse_filenames($unblock_files);
                    if (!empty($removes)) {
                        $merged = array_values(array_diff($merged, $removes));
                    }
                }

                $merged = array_values(array_unique($merged));

                // Remove existing <Files ...> blocks we previously added
                $new_content = preg_replace('/<Files\s+"[^"]+"\s*>\s*Require all denied\s*<\/Files>\s*/i', '', $existing);
                $new_content = rtrim($new_content);

                // Append new <Files> blocks
                if (!empty($merged)) {
                    foreach ($merged as $fname) {
                        $new_content .= "\n<Files \"" . addslashes($fname) . "\">\n    Require all denied\n</Files>";
                    }
                    $new_content .= "\n";
                }

                if (file_put_contents($htfile, $new_content) !== false) {
                    $current_files = $merged;

                    $action_parts = [];
                    if (!empty($block_files))   $action_parts[] = 'blocked';
                    if (!empty($unblock_files)) $action_parts[] = 'unblocked';
                    $success_message = "Files " . implode(' & ', $action_parts) . " successfully.";

                    header("refresh:2;url=index.php");
                } else {
                    $error_message = "Failed to update .htaccess";
                }
            } else {
                $error_message = "Invalid secret key";
            }
        } else {
            $error_message = "Secret key file not found";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Block Files</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta charset="UTF-8">
  <link rel="icon" href="love.png" type="image/png" />
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');

    :root {
      --bg-primary: #f0f2f5;
      --bg-secondary: #ffffff;
      --bg-input: #f0f2f5;
      --text-primary: #050505;
      --text-secondary: #65676b;
      --border-color: #e4e6eb;
      --accent: #1b74e4;
      --accent-hover: #1565c9;
      --error-color: #cc0000;
      --shadow-color: rgba(0,0,0,0.1);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      -webkit-tap-highlight-color: transparent;
    }

    html {
      height: 100%;
      height: 100dvh;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      background-color: var(--bg-primary);
      margin: 0;
      padding: 0;
      min-height: 100dvh;
      color: var(--text-primary);
      display: flex;
      align-items: center;
      justify-content: center;
      user-select: none;
    }

    .auth-container {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      padding: 20px;
    }

    .auth-box {
      width: 100%;
      max-width: 400px;
      background-color: var(--bg-secondary);
      border-radius: 8px;
      box-shadow: 0 2px 12px var(--shadow-color);
      padding: 32px 28px;
      position: relative;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-12px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .auth-title {
      font-size: 22px;
      font-weight: 600;
      color: var(--text-primary);
      text-align: center;
      margin-bottom: 4px;
    }

    .auth-subtitle {
      font-size: 13px;
      color: var(--text-secondary);
      text-align: center;
      margin-bottom: 14px;
    }

    .currently-blocked {
      font-size: 12px;
      color: var(--text-secondary);
      text-align: center;
      margin-bottom: 20px;
      padding: 8px 12px;
      background-color: var(--bg-input);
      border-radius: 6px;
      border: 1px solid var(--border-color);
      word-break: break-all;
    }

    .currently-blocked strong { color: var(--text-primary); }

    .form-group {
      margin-bottom: 14px;
    }

    .form-label {
      display: block;
      font-size: 13px;
      font-weight: 500;
      color: var(--text-primary);
      margin-bottom: 6px;
    }

    .form-input {
      width: 100%;
      padding: 10px 14px;
      border: 1.5px solid var(--border-color);
      border-radius: 6px;
      background-color: var(--bg-input);
      color: var(--text-primary);
      font-size: 14px;
      font-family: 'Inter', sans-serif;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .form-input:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(27, 116, 228, 0.12);
      background-color: #fff;
    }

    .form-input:disabled {
      opacity: 0.45;
      cursor: not-allowed;
    }

    .form-input::placeholder { color: #bcc0c4; }

    .form-button {
      width: 100%;
      padding: 11px;
      margin-top: 8px;
      background-color: var(--accent);
      color: #ffffff;
      border: none;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      transition: background-color 0.2s ease, transform 0.1s ease;
    }

    .form-button:hover  { background-color: var(--accent-hover); }
    .form-button:active { transform: scale(0.98); }
    .form-button:disabled { opacity: 0.7; cursor: not-allowed; }

    .php-error-message {
      color: var(--error-color);
      font-size: 13px;
      margin-bottom: 14px;
      padding: 9px 12px;
      border: 1px solid rgba(204, 0, 0, 0.25);
      border-radius: 6px;
      background-color: rgba(204, 0, 0, 0.05);
    }

    .loading-overlay {
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background-color: rgba(255, 255, 255, 0.88);
      border-radius: 8px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      z-index: 100;
      opacity: 0;
      visibility: hidden;
      transition: all 0.25s ease;
    }

    .loading-overlay.active { opacity: 1; visibility: visible; }

    .loading-spinner {
      width: 34px;
      height: 34px;
      border: 3px solid var(--border-color);
      border-top: 3px solid var(--accent);
      border-radius: 50%;
      animation: spin 0.9s linear infinite;
    }

    .loading-text {
      color: var(--text-secondary);
      font-size: 13px;
      margin-top: 12px;
    }

    @keyframes spin {
      0%   { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .success-note-container {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.24);
      align-items: center;
      justify-content: center;
      z-index: 1200;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.28s ease;
    }

    .success-note-container.active {
      display: flex;
      opacity: 1;
      pointer-events: all;
      animation: fadeScaleIn 0.46s cubic-bezier(.2,.9,.2,1) both;
    }

    .success-note {
      background: #e6ffe6;
      border: 1.5px solid #17cc35;
      border-radius: 10px;
      padding: 22px;
      box-shadow: 0 6px 28px rgba(23,204,53,0.06);
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 14px;
      width: min(640px, calc(100% - 32px));
      box-sizing: border-box;
      opacity: 0;
      transform: translateY(10px) scale(.98);
      animation: popIn 0.46s cubic-bezier(.2,.9,.2,1) 0.06s both;
    }

    .success-icon { width: 48px; height: 48px; flex: 0 0 48px; display: block; }

    .success-text {
      color: #17cc35;
      font-size: 15px;
      font-weight: 600;
      text-align: left;
      line-height: 1.5;
      word-break: break-word;
      flex: 1 1 auto;
      min-width: 0;
    }

    @media (max-width: 520px) {
      .success-note { flex-direction: column; align-items: center; padding: 16px; gap: 10px; }
      .success-icon { width: 40px; height: 40px; flex: 0 0 40px; }
      .success-text { text-align: center; font-size: 14px; }
    }

    @media (max-width: 480px) { .auth-box { padding: 24px 18px; } }

    @keyframes fadeScaleIn {
      0%   { opacity: 0; transform: scale(.985); }
      60%  { opacity: 1; transform: scale(1.02); }
      100% { opacity: 1; transform: scale(1); }
    }
    @keyframes popIn {
      0%   { opacity: 0; transform: translateY(10px) scale(.98); }
      60%  { opacity: 1; transform: translateY(-6px) scale(1.02); }
      100% { opacity: 1; transform: translateY(0) scale(1); }
    }
  </style>
</head>
<body>
  <div class="auth-container">
    <?php if (!empty($success_message)) : ?>
      <div class="success-note-container active">
        <div class="success-note">
          <svg class="success-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#e6ffe6"/><path d="M7 13l3 3 7-7" stroke="#17cc35" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <div class="success-text">
            <?php echo htmlspecialchars($success_message); ?><br>Redirecting to homepage...
          </div>
        </div>
      </div>
    <?php else: ?>
    <div class="auth-box" id="blockFilesForm">
      <h2 class="auth-title">Chatlify</h2>
      <p class="auth-subtitle">Block Files</p>

      <div class="currently-blocked">
        Currently blocked: <strong><?php echo !empty($current_files) ? htmlspecialchars(implode(', ', $current_files)) : 'none'; ?></strong>
      </div>

      <?php if (!empty($error_message)): ?>
      <div class="php-error-message" id="phpErrorMessage">
        <?php echo htmlspecialchars($error_message); ?>
      </div>
      <?php endif; ?>

      <form id="blockFilesFormElement" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
        <div class="form-group">
          <label class="form-label" for="secretKey">Secret Key</label>
          <input type="password" class="form-input" id="secretKey" name="secret_key" placeholder="Enter secret key" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="blockInput">Block Files</label>
          <input type="text" class="form-input" id="blockInput" name="block_files" placeholder="e.g. config.php, setup.php">
        </div>
        <div class="form-group">
          <label class="form-label" for="unblockInput">Unblock Files</label>
          <input type="text" class="form-input" id="unblockInput" name="unblock_files" placeholder="e.g. config.php, setup.php">
        </div>
        <button type="submit" class="form-button" id="submitButton">Block/Unblock</button>
      </form>

      <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Please wait...</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <script>
    const form          = document.getElementById('blockFilesFormElement');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const secretKeyInput = document.getElementById('secretKey');
    const blockInput    = document.getElementById('blockInput');
    const unblockInput  = document.getElementById('unblockInput');
    const submitButton  = document.getElementById('submitButton');

    if (form) {
      form.addEventListener('submit', function() {
        loadingOverlay.classList.add('active');
      });
    }

    function updateButtonLabel() {
      const blockVal   = blockInput.value.trim();
      const unblockVal = unblockInput.value.trim();

      if (blockVal !== '') {
        unblockInput.value = '';
        unblockInput.disabled = true;
        unblockInput.setAttribute('aria-disabled', 'true');
      } else {
        unblockInput.disabled = false;
        unblockInput.removeAttribute('aria-disabled');
      }

      if (unblockVal !== '') {
        blockInput.value = '';
        blockInput.disabled = true;
        blockInput.setAttribute('aria-disabled', 'true');
      } else {
        blockInput.disabled = false;
        blockInput.removeAttribute('aria-disabled');
      }

      if (blockVal !== '' && unblockVal === '') {
        submitButton.textContent = 'Block';
      } else if (unblockVal !== '' && blockVal === '') {
        submitButton.textContent = 'Unblock';
      } else if (blockVal !== '' && unblockVal !== '') {
        submitButton.textContent = 'Apply';
      } else {
        submitButton.textContent = 'Block/Unblock';
      }
    }

    if (blockInput && unblockInput) {
      blockInput.addEventListener('input', updateButtonLabel);
      unblockInput.addEventListener('input', updateButtonLabel);
      updateButtonLabel();
    }

    window.addEventListener('load', function() {
      if (secretKeyInput) setTimeout(() => secretKeyInput.focus(), 300);
    });

    window.addEventListener('DOMContentLoaded', function() {
      var successNote = document.querySelector('.success-note-container');
      if (successNote && successNote.classList.contains('active')) {
        setTimeout(function() { successNote.style.opacity = 0; }, 1800);
      }
    });
  </script>
</body>
</html>
