<?php
session_start();

// ─── Rate Limiting ────────────────────────────────────────────────────────────
// Tracks failed login attempts per IP. Block starts at 10 mins and multiplies
// with each successive block. Duration is never shown to the user.
$rl_file      = 'rate_limit.json';
$rl_lock_file = 'rate_limit.json.lock';
$rl_max_attempts = 5;
$rl_base_block   = 600; // 10 minutes in seconds

function rl_get_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function rl_load(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function rl_save(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

$client_ip  = rl_get_ip();
$rl_blocked = false;

$rl_lock_handle = fopen($rl_lock_file, 'w');
if ($rl_lock_handle && flock($rl_lock_handle, LOCK_EX)) {
    try {
        $rl_data   = rl_load($rl_file);
        $ip_record = $rl_data[$client_ip] ?? [
            'attempts'      => 0,
            'block_count'   => 0,
            'blocked_until' => 0,
            'first_attempt' => time(),
        ];

        $now = time();

        // Check if currently blocked
        if ($ip_record['blocked_until'] > $now) {
            $rl_blocked = true;
        }

        // On POST failure we'll increment — handled below after auth check.
        // Store reference so the POST handler can write back.
        $rl_data[$client_ip] = $ip_record;
        rl_save($rl_file, $rl_data);
    } finally {
        flock($rl_lock_handle, LOCK_UN);
        fclose($rl_lock_handle);
    }
}
// ─── End Rate Limiting Setup ──────────────────────────────────────────────────

// Initialize variables
$login_error = "";

// Show blocked error before processing form
if ($rl_blocked) {
    $login_error = "Too many failed login attempts. Please try again later.";
}

// Process login form
if ($_SERVER["REQUEST_METHOD"] == "POST" && !$rl_blocked) {
    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);
    
    // Validate input
    if (empty($username) || empty($password)) {
        $login_error = "Both username and password are required";
    } else {
        // Try to read from both users.json and admin.json files
        $login_successful = false;
        $user_name = "";
        $is_admin = false;
        $user_email = "";
        $user_role = 'user';
        
        // Define JSON files to check
        $files_to_check = ["users.json", "admin.json"];
        
        foreach ($files_to_check as $file) {
            if (file_exists($file)) {
                // Acquire lock for reading user database
                $lock_file = $file . '.lock';
                $lock_handle = fopen($lock_file, 'w');
                if ($lock_handle && flock($lock_handle, LOCK_SH)) { // Shared lock for reading
                    try {
                        // Read and decode JSON file
                        $json_content = file_get_contents($file);
                        $users_data = json_decode($json_content, true);
                        
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($users_data)) {
                            // If JSON is invalid or empty, skip this file
                            continue;
                        }
                        
                        // Check each user in the JSON array
                        foreach ($users_data as $user) {
                            // Check if user array has required fields
                            if (isset($user['username']) && isset($user['password'])) {
                                $file_username = trim($user['username']);
                                $file_password = trim($user['password']);
                                
                                // Get name if available, otherwise use username
                                $file_name = isset($user['name']) ? trim($user['name']) : $file_username;
                                
                                if ($file_username === $username && $file_password === $password) {
                                    // Valid login found
                                    $login_successful = true;
                                    $user_name = $file_name;
                                    $is_admin = ($file == "admin.json"); // Check if from admin.json
                                    
                                    // Store additional user data if available
                                    if (isset($user['email'])) {
                                        $user_email = trim($user['email']);
                                    }
                                    
                                    // Store user role if available
                                    $user_role = isset($user['role']) ? trim($user['role']) : ($is_admin ? 'admin' : 'user');
                                    
                                    break 2; // Break out of both loops
                                }
                            }
                        }
                    } finally {
                        // Release lock
                        flock($lock_handle, LOCK_UN);
                        fclose($lock_handle);
                    }
                } else {
                    // Failed to acquire lock
                    error_log("Failed to acquire lock for $file");
                    continue;
                }
            }
        }
        
        if ($login_successful) {
            // Reset rate-limit record on successful login
            $rl_lock_handle2 = fopen($rl_lock_file, 'w');
            if ($rl_lock_handle2 && flock($rl_lock_handle2, LOCK_EX)) {
                try {
                    $rl_data2 = rl_load($rl_file);
                    unset($rl_data2[$client_ip]);
                    rl_save($rl_file, $rl_data2);
                } finally {
                    flock($rl_lock_handle2, LOCK_UN);
                    fclose($rl_lock_handle2);
                }
            }
            // Store in PHP session (for backward compatibility)
            $_SESSION['ghostlan_admin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['name'] = $user_name;
            $_SESSION['is_admin'] = $is_admin;
            $_SESSION['login_time'] = time();
            
            // Create or update session.json with exclusive lock
            $session_file = 'session.json';
            $session_lock_file = 'session.json.lock';
            
            $session_lock_handle = fopen($session_lock_file, 'w');
            if ($session_lock_handle && flock($session_lock_handle, LOCK_EX)) { // Exclusive lock for writing
                try {
                    $sessions = [];
                    
                    if (file_exists($session_file)) {
                        $json_content = file_get_contents($session_file);
                        $sessions = json_decode($json_content, true);
                        
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($sessions)) {
                            $sessions = [];
                        }
                    }
                    
                    // Remove any existing session for this user
                    $session_id = session_id();
                    $sessions = array_filter($sessions, function($session) use ($username) {
                        return !isset($session['username']) || $session['username'] !== $username;
                    });
                    $sessions = array_values($sessions);
                    
                    // Generate persistent remember_token — survives browser close/restart
                    $remember_token = bin2hex(random_bytes(32));
                    $expires_at = time() + (30 * 24 * 60 * 60); // 30 days

                    // Create new session entry
                    $new_session = [
                        'session_id'     => $session_id,
                        'remember_token' => $remember_token,
                        'username'       => $username,
                        'name'           => $user_name,
                        'email'          => $user_email,
                        'is_admin'       => $is_admin,
                        'role'           => $user_role,
                        'login_time'     => time(),
                        'expires_at'     => $expires_at,
                        'created_at'     => date('Y-m-d H:i:s')
                    ];
                    
                    $sessions[] = $new_session;
                    
                    // Save to session.json
                    $json_data = json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    file_put_contents($session_file, $json_data, LOCK_EX);

                    // Set persistent remember_token cookie — expires in 30 days, survives browser close
                    setcookie('remember_token', $remember_token, [
                        'expires'  => $expires_at,
                        'path'     => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    
                    // If this login is from admin.json, also record to admin_session.json
                    if ($is_admin) {
                        $admin_session_file = 'admin_session.json';
                        $admin_session_lock_file = 'admin_session.json.lock';
                        
                        $admin_lock_handle = fopen($admin_session_lock_file, 'w');
                        if ($admin_lock_handle && flock($admin_lock_handle, LOCK_EX)) {
                            try {
                                $admin_sessions = [];
                                
                                if (file_exists($admin_session_file)) {
                                    $json_content = file_get_contents($admin_session_file);
                                    $admin_sessions = json_decode($json_content, true);
                                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($admin_sessions)) {
                                        $admin_sessions = [];
                                    }
                                }
                                
                                // Remove any existing admin session for this user
                                $admin_sessions = array_filter($admin_sessions, function($s) use ($username) {
                                    return !isset($s['username']) || $s['username'] !== $username;
                                });
                                $admin_sessions = array_values($admin_sessions);
                                
                                // Reuse the $new_session entry created above for admin session
                                $admin_sessions[] = $new_session;
                                
                                // Save to admin_session.json
                                $admin_json_data = json_encode($admin_sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                                file_put_contents($admin_session_file, $admin_json_data, LOCK_EX);
                            } finally {
                                flock($admin_lock_handle, LOCK_UN);
                                fclose($admin_lock_handle);
                            }
                        }
                    }
                } finally {
                    // Release session lock
                    flock($session_lock_handle, LOCK_UN);
                    fclose($session_lock_handle);
                }
            } else {
                // Failed to acquire lock for session file
                $login_error = "System busy. Please try again.";
                // Still set session for user since we authenticated them
            }
            
            // Redirect to main page
            header("Location: index.php");
            exit;
        } else {
            // Check if files exist
            $files_exist = false;
            foreach ($files_to_check as $file) {
                if (file_exists($file)) {
                    $files_exist = true;
                    break;
                }
            }
            
            if (!$files_exist) {
                $login_error = "Error: User database not found";
            } else {
                $login_error = "Invalid username or password";
            }

            // ── Record failed attempt ─────────────────────────────────────────
            $rl_lock_handle3 = fopen($rl_lock_file, 'w');
            if ($rl_lock_handle3 && flock($rl_lock_handle3, LOCK_EX)) {
                try {
                    $rl_data3  = rl_load($rl_file);
                    $ip_rec    = $rl_data3[$client_ip] ?? [
                        'attempts'      => 0,
                        'block_count'   => 0,
                        'blocked_until' => 0,
                        'first_attempt' => time(),
                    ];

                    $ip_rec['attempts']++;

                    if ($ip_rec['attempts'] >= $rl_max_attempts) {
                        // Escalate: 10min * 2^block_count (10, 20, 40, 80 … mins)
                        $multiplier            = pow(2, $ip_rec['block_count']);
                        $block_duration        = $rl_base_block * $multiplier;
                        $ip_rec['blocked_until'] = time() + $block_duration;
                        $ip_rec['block_count']++;
                        $ip_rec['attempts']    = 0; // reset counter for next window
                    }

                    $rl_data3[$client_ip] = $ip_rec;
                    rl_save($rl_file, $rl_data3);
                } finally {
                    flock($rl_lock_handle3, LOCK_UN);
                    fclose($rl_lock_handle3);
                }
            }
            // ── End record failed attempt ─────────────────────────────────────
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Twitter</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
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
      --border-input: #e4e6eb;
      --accent: #1b74e4;
      --accent-hover: #1669c1;
      --error-color: #cc0000;
      --shadow-color: rgba(0,0,0,0.1);
    }

    html[data-theme="dark"],
    html[data-theme="dark"] body,
    [data-theme="dark"] {
      --bg-primary: #f0f2f5;
      --bg-secondary: #ffffff;
      --bg-input: #f0f2f5;
      --text-primary: #050505;
      --text-secondary: #65676b;
      --border-color: #e4e6eb;
      --border-input: #e4e6eb;
      --accent: #1b74e4;
      --accent-hover: #1669c1;
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
      transition: background-color 0.3s ease, color 0.2s ease;
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
      border-radius: 12px;
      box-shadow: 0 2px 16px var(--shadow-color);
      padding: 32px 28px;
      position: relative;
      border: 1px solid var(--border-color);
      animation: fadeSlideIn 0.3s cubic-bezier(0.34, 1.2, 0.64, 1) both;
      transition: background-color 0.3s ease, border-color 0.3s ease;
    }

    @keyframes fadeSlideIn {
      from { opacity: 0; transform: translateY(-12px) scale(0.98); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .auth-title {
      font-size: 22px;
      font-weight: 600;
      color: var(--accent);
      margin: 0 0 4px;
      text-align: center;
    }

    .auth-subtitle {
      font-size: 13px;
      color: var(--text-secondary);
      text-align: center;
      margin-bottom: 24px;
      transition: color 0.3s ease;
    }

    .form-group {
      margin-bottom: 14px;
    }

    .form-label {
      display: block;
      font-size: 13px;
      font-weight: 500;
      color: var(--text-primary);
      margin-bottom: 6px;
      transition: color 0.3s ease;
    }

    .input-wrapper {
      position: relative;
    }

    .form-input {
      width: 100%;
      padding: 10px 14px;
      border: 1.5px solid var(--border-input);
      border-radius: 8px;
      background-color: var(--bg-input);
      color: var(--text-primary);
      font-size: 14px;
      font-family: 'Inter', sans-serif;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.3s ease;
    }

    .form-input:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(27, 116, 228, 0.14);
    }

    .form-input::placeholder { color: #bcc0c4; }

    /* password toggle */
    .pw-toggle {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      padding: 0;
      display: flex;
      align-items: center;
    }

    .pw-toggle svg {
      width: 17px;
      height: 17px;
      fill: var(--text-secondary);
      transition: fill 0.2s ease;
    }

    .pw-toggle:hover svg { fill: var(--accent); }

    .form-button {
      width: 100%;
      padding: 11px;
      margin-top: 8px;
      background-color: var(--accent);
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      transition: background-color 0.2s ease, transform 0.1s ease;
    }

    .form-button:hover  { background-color: var(--accent-hover); }
    .form-button:active { transform: scale(0.98); }
    .form-button:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }

    .php-error-message {
      font-size: 13px;
      margin-bottom: 16px;
      padding: 10px 13px;
      border-radius: 8px;
      color: var(--error-color);
      border: 1px solid rgba(204, 0, 0, 0.2);
      background-color: rgba(204, 0, 0, 0.06);
    }

    html[data-theme="dark"] .php-error-message {
      border-color: rgba(255, 107, 107, 0.25);
      background-color: rgba(255, 107, 107, 0.08);
    }

    .divider {
      margin: 18px 0 14px;
      border: none;
      border-top: 1px solid var(--border-color);
      transition: border-color 0.3s ease;
    }

    .register-link {
      text-align: center;
      font-size: 13px;
      color: var(--text-secondary);
    }

    .register-link a {
      color: var(--accent);
      text-decoration: none;
      font-weight: 600;
    }

    .register-link a:hover { text-decoration: underline; }

    .loading-overlay {
      position: absolute;
      inset: 0;
      background-color: rgba(255,255,255,0.88);
      border-radius: 12px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      z-index: 100;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.25s ease, visibility 0.25s ease;
    }

    html[data-theme="dark"] .loading-overlay {
      background-color: rgba(45,45,45,0.88);
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

    .retry-button {
      background-color: transparent;
      color: var(--accent);
      border: 1.5px solid var(--accent);
      padding: 5px 14px;
      margin-top: 8px;
      font-size: 12px;
      border-radius: 6px;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      font-weight: 500;
      transition: background 0.2s ease, color 0.2s ease;
    }

    .retry-button:hover { background-color: var(--accent); color: #fff; }

    @media (max-width: 480px) {
      .auth-box { padding: 24px 18px; }
    }
  </style>
</head>
<body>
  <div class="auth-container">
    <div class="auth-box">

      <h2 class="auth-title">Twitter</h2>
      <p class="auth-subtitle">Sign in to your account</p>

      <?php if (!empty($login_error)): ?>
      <div class="php-error-message" id="phpErrorMessage">
        <?php echo htmlspecialchars($login_error); ?>
      </div>
      <?php else: ?>
      <div class="php-error-message" id="phpErrorMessage" style="display:none;"></div>
      <?php endif; ?>

      <form id="loginFormElement" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
        <div class="form-group">
          <label class="form-label" for="loginUsername">Username</label>
          <input type="text" class="form-input" id="loginUsername" name="username"
            placeholder="Enter your username" required autocomplete="username">
        </div>
        <div class="form-group">
          <label class="form-label" for="loginPassword">Password</label>
          <div class="input-wrapper">
            <input type="password" class="form-input" id="loginPassword" name="password"
              placeholder="Enter your password" required autocomplete="current-password" style="padding-right:40px;">
            <button type="button" class="pw-toggle" id="pwToggle" tabindex="-1" aria-label="Show/hide password">
              <svg id="eyeIcon" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
            </button>
          </div>
        </div>
        <button type="submit" class="form-button" id="loginButton">Log In</button>
      </form>

      <hr class="divider">

      <div class="register-link">
        Don't have an account? <a href="register.php">Register here</a>
      </div>

      <div class="loading-overlay" id="loginLoading">
        <div class="loading-spinner"></div>
        <div class="loading-text">Authenticating...</div>
      </div>
    </div>
  </div>

  <script>
    const loginForm     = document.getElementById('loginFormElement');
    const loginUsername = document.getElementById('loginUsername');
    const loginButton   = document.getElementById('loginButton');
    const loginLoading  = document.getElementById('loginLoading');
    const phpError      = document.getElementById('phpErrorMessage');
    const pwToggle      = document.getElementById('pwToggle');
    const loginPassword = document.getElementById('loginPassword');
    const eyeIcon       = document.getElementById('eyeIcon');

    // Password show/hide
    var pwVisible = false;
    pwToggle.addEventListener('click', function() {
      pwVisible = !pwVisible;
      loginPassword.type = pwVisible ? 'text' : 'password';
      eyeIcon.innerHTML = pwVisible
        ? '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.8 11.8 0 0 0 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78 3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>'
        : '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
    });

    // Form submit
    loginForm.addEventListener('submit', function() {
      loginLoading.classList.add('active');
      loginButton.disabled = true;
    });

    // Auto-focus
    window.addEventListener('load', function() {
      setTimeout(() => loginUsername.focus(), 200);
    });

    // Retry button for "System busy"
    window.addEventListener('DOMContentLoaded', function() {
      const errorText = phpError.textContent.trim();
      if (errorText.includes('System busy')) {
        const retryBtn = document.createElement('button');
        retryBtn.className = 'retry-button';
        retryBtn.textContent = 'Retry Login';
        retryBtn.style.display = 'block';
        retryBtn.style.margin = '8px auto 0';
        retryBtn.onclick = function() { loginForm.submit(); };
        phpError.appendChild(document.createElement('br'));
        phpError.appendChild(retryBtn);
      }
    });
  </script>
</body>
</html>
