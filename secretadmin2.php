<?php
session_start();

// --- REPLACED AUTHENTICATION LOGIC ---
// Require a logged-in username; otherwise send to login
// ensure username is treated as a string to avoid trim(array) errors
$username = (string)($_SESSION['username'] ?? '');
$username = trim($username);
if ($username === '') {
    header('Location: login.php');
    exit;
}

// Load admin users
$admin_users = [];
if (file_exists('admin.json')) {
    $adm = json_decode(file_get_contents('admin.json'), true);
    if (is_array($adm)) {
        if (isset($adm['admins']) && is_array($adm['admins'])) $admin_users = $adm['admins'];
        else $admin_users = $adm;
    }
}

// Load regular users (explicitly deny them)
$regular_users = [];
if (file_exists('users.json')) {
    $usr = json_decode(file_get_contents('users.json'), true);
    if (is_array($usr)) {
        if (isset($usr['users']) && is_array($usr['users'])) $regular_users = $usr['users'];
        else $regular_users = $usr;
    }
}

// --- REPLACED NORMALIZATION LOGIC ---
// helper: recursively collect scalar values from arrays and cast to strings
$flatten_strings = function ($arr) {
    $out = [];
    if (!is_array($arr)) return $out;
    foreach ($arr as $v) {
        if (is_array($v)) {
            $out = array_merge($out, $this($v)); // placeholder, will be replaced below
        } elseif (is_scalar($v)) {
            $out[] = (string)$v;
        }
    }
    return $out;
};

// Implement recursion without using $this in closure (compatibility)
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

// normalize lists safely
$admin_users = array_values(array_unique(__flatten_strings($admin_users)));
$admin_users = array_map('strtolower', array_map('trim', $admin_users));

$regular_users = array_values(array_unique(__flatten_strings($regular_users)));
$regular_users = array_map('strtolower', array_map('trim', $regular_users));

$normalized_username = strtolower(trim((string)$username));

// Allow only admin.json users; if user is in regular users or not in admins, redirect to index
if (in_array($normalized_username, $admin_users, true)) {
    // allowed: proceed
} else {
    // explicitly deny regular users and all non-admins
    header('Location: index.php');
    exit;
}

$error_message = '';
$success_message = '';

// NEW: read current blocked extensions from .htaccess
$current_exts = [];
$htfile = '.htaccess';
if (file_exists($htfile)) {
	$ht = file_get_contents($htfile);
	if (preg_match('/<FilesMatch\s+"\\\\\.\(([^)]+)\)\\$"\s*>/i', $ht, $m)) {
		$raw = $m[1];
		$parts = array_filter(array_map('trim', explode('|', $raw)));
		$current_exts = $parts;
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret_key = trim($_POST['secret_key'] ?? '');
    $block_extension = trim($_POST['block_extension'] ?? '');
    // NEW: accept unblock input
    $unblock_extension = trim($_POST['unblock_extension'] ?? '');

    // Validate inputs: require secret key and at least one action
    if (empty($secret_key) || (empty($block_extension) && empty($unblock_extension))) {
        $error_message = "Secret key and at least one of block/unblock are required";
    } else {
        // NEW: Read secret key from secret.json
        if (file_exists('secret.json')) {
            $secret_data = json_decode(file_get_contents('secret.json'), true);
            $correct_secret = $secret_data['secret_key'] ?? '';
            
            if ($secret_key === $correct_secret) {
                // load existing htaccess content and current list
                $existing = file_exists($htfile) ? file_get_contents($htfile) : '';
                $merged = $current_exts;

                // helper to parse and sanitize lists
                $parse_list = function($raw) {
                    $parts = preg_split('/[\s,;|]+/', $raw);
                    $out = [];
                    foreach ($parts as $p) {
                        $p = ltrim($p, '.');
                        $clean = strtolower(preg_replace('/[^a-z0-9]/', '', $p));
                        if ($clean !== '') $out[] = $clean;
                    }
                    return array_values(array_unique($out));
                };

                // additions
                if (!empty($block_extension)) {
                    $adds = $parse_list($block_extension);
                    foreach ($adds as $a) {
                        if (!in_array($a, $merged, true)) $merged[] = $a;
                    }
                }

                // removals
                if (!empty($unblock_extension)) {
                    $removes = $parse_list($unblock_extension);
                    if (!empty($removes)) {
                        $merged = array_values(array_diff($merged, $removes));
                    }
                }

                // ensure unique/order
                $merged = array_values(array_unique($merged));

                // build new .htaccess content
                if (empty($merged)) {
                    // remove existing FilesMatch block entirely if present
                    if (preg_match('/<FilesMatch\s+"\\\\\.\(([^)]+)\)\\$"\s*>.*?<\/FilesMatch>\s*/si', $existing)) {
                        $new_content = preg_replace('/<FilesMatch\s+"\\\\\.\(([^)]+)\)\\$"\s*>.*?<\/FilesMatch>\s*/si', '', $existing, 1);
                    } else {
                        $new_content = $existing;
                    }
                } else {
                    $pattern = implode('|', array_map(function($v){ return preg_quote($v, '/'); }, $merged));
                    if (preg_match('/<FilesMatch\s+"\\\\\.\(([^)]+)\)\\$"\s*>/i', $existing)) {
                        $new_header = '<FilesMatch "\\.(' . $pattern . ')$">';
                        $new_content = preg_replace('/<FilesMatch\s+"\\\\\.\(([^)]+)\)\\$"\s*>/i', $new_header, $existing, 1);
                    } else {
                        $append_block = "\n<FilesMatch \"\\.($pattern)$\">\n    Require all denied\n</FilesMatch>\n";
                        $new_content = rtrim($existing) . "\n\n" . $append_block;
                    }
                }

                if (file_put_contents($htfile, $new_content) !== false) {
                    // update current_exts for immediate display
                    $current_exts = $merged;

                    // no logout / no session invalidation
                    $action_parts = [];
                    if (!empty($block_extension)) $action_parts[] = 'added';
                    if (!empty($unblock_extension)) $action_parts[] = 'removed';
                    $success_message = "Extensions updated in .htaccess (" . implode(' & ', $action_parts) . ").";

                    // Redirect back to index (no logout)
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
  <title>GhostLAN — Block Extensions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta charset="UTF-8">
  <link rel="icon" href="ghost.png" type="image/png" />
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

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      background-color: var(--bg-primary);
      height: 100vh;
      margin: 0;
      padding: 0;
      overflow: auto;
      color: var(--text-primary);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .auth-container {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      min-height: 100%;
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
      margin: auto;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-12px); }
      to { opacity: 1; transform: translateY(0); }
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

    .form-input::placeholder {
      color: #bcc0c4;
    }

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

    .form-button:hover { background-color: var(--accent-hover); }
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
      0% { transform: rotate(0deg); }
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
      0% { opacity: 0; transform: scale(.985); }
      60% { opacity: 1; transform: scale(1.02); }
      100% { opacity: 1; transform: scale(1); }
    }
    @keyframes popIn {
      0% { opacity: 0; transform: translateY(10px) scale(.98); }
      60% { opacity: 1; transform: translateY(-6px) scale(1.02); }
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
    <div class="auth-box" id="changePasswordForm">
      <h2 class="auth-title">GhostLAN</h2>
      <p class="auth-subtitle">Block File Extensions</p>

      <div class="currently-blocked">
        Currently blocked: <strong><?php echo !empty($current_exts) ? htmlspecialchars(implode(', ', $current_exts)) : 'none'; ?></strong>
      </div>

      <?php if (!empty($error_message)): ?>
      <div class="php-error-message" id="phpErrorMessage">
        <?php echo htmlspecialchars($error_message); ?>
      </div>
      <?php endif; ?>

      <form id="changePasswordFormElement" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
        <div class="form-group">
          <label class="form-label" for="secretKey">Secret Key</label>
          <input type="password" class="form-input" id="secretKey" name="secret_key" placeholder="Enter secret key" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="blockInput">Block Extension</label>
          <input type="text" class="form-input" id="blockInput" name="block_extension" placeholder="e.g. php, exe">
        </div>
        <div class="form-group">
          <label class="form-label" for="unblockInput">Unblock Extension</label>
          <input type="text" class="form-input" id="unblockInput" name="unblock_extension" placeholder="e.g. pdf, jpg">
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
    const form = document.getElementById('changePasswordFormElement');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const secretKeyInput = document.getElementById('secretKey');
    const blockInput = document.getElementById('blockInput');
    const unblockInput = document.getElementById('unblockInput');
    const submitButton = document.getElementById('submitButton');

    if (form) {
      form.addEventListener('submit', function() {
        loadingOverlay.classList.add('active');
      });
    }

    function updateButtonLabel() {
      const blockVal = blockInput.value.trim();
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