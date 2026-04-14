<?php
session_start();

// Initialize variables
$register_error = "";
$register_success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $name = trim($_POST['name'] ?? '');
    
    // Validate input
    if (empty($username) || empty($password) || empty($name)) {
        $register_error = "All fields are required";
    } elseif (strlen($password) < 6) {
        $register_error = "Password must be at least 6 characters long";
    } elseif (strlen($username) < 3) {
        $register_error = "Username must be at least 3 characters long";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $register_error = "Username can only contain letters, numbers, and underscores";
    } else {
        // Check if users.json exists
        $user_file = 'users.json';
        
        // Initialize users array
        $users = [];
        
        // Read existing users if file exists
        if (file_exists($user_file)) {
            $json_content = file_get_contents($user_file);
            $users = json_decode($json_content, true);
            
            // Check if JSON is valid
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($users)) {
                $users = []; // Reset to empty array if invalid
            }
        }
        
        // Check if username already exists
        $username_exists = false;
        foreach ($users as $user) {
            if (isset($user['username']) && trim($user['username']) === $username) {
                $username_exists = true;
                break;
            }
        }
        
        if ($username_exists) {
            $register_error = "Username already exists";
        }
        
        // If no error, register the user
        if (empty($register_error)) {
            // Generate a unique ID
            $new_id = uniqid('user_', true);
            
            // Create new user array
            $new_user = [
                'id' => $new_id,
                'username' => $username,
                'password' => $password, // Note: In production, you should hash this!
                'name' => $name,
                'role' => 'user',
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 'active'
            ];
            
            // Add new user to users array
            $users[] = $new_user;
            
            // Convert to JSON with pretty print for readability
            $json_data = json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            // Save to users.json file
            if (file_put_contents($user_file, $json_data) !== false) {
                $register_success = "Registration successful! You can now login.";
                
                // Clear form fields
                $username = $password = $name = '';
            } else {
                $register_error = "Error saving user data. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Chatlify</title>
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
      --success-color: #006600;
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
      --success-color: #006600;
      --shadow-color: rgba(0,0,0,0.1);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

    html { height: 100%; height: 100dvh; }

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

    .auth-title { font-size: 22px; font-weight: 600; color: var(--accent); text-align: center; margin: 0 0 4px; }
    .auth-subtitle { font-size: 13px; color: var(--text-secondary); text-align: center; margin-bottom: 24px; }
    .form-group { margin-bottom: 14px; }

    .form-label {
      display: block; font-size: 13px; font-weight: 500;
      color: var(--text-primary); margin-bottom: 6px; transition: color 0.3s ease;
    }

    .input-wrapper { position: relative; }

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

    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; padding: 0; display: flex; align-items: center;
    }
    .pw-toggle svg { width: 17px; height: 17px; fill: var(--text-secondary); transition: fill 0.2s ease; }
    .pw-toggle:hover svg { fill: var(--accent); }

    .form-hint { font-size: 11px; color: var(--text-secondary); margin-top: 5px; }

    .form-button {
      width: 100%; padding: 11px; margin-top: 8px;
      background-color: var(--accent); color: #ffffff; border: none; border-radius: 8px;
      font-size: 14px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;
      transition: background-color 0.2s ease, transform 0.1s ease;
    }
    .form-button:hover  { background-color: var(--accent-hover); }
    .form-button:active { transform: scale(0.98); }
    .form-button:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }

    .php-error-message {
      font-size: 13px; margin-bottom: 14px; padding: 10px 13px; border-radius: 8px;
      color: var(--error-color); border: 1px solid rgba(204,0,0,0.2); background-color: rgba(204,0,0,0.06);
    }
    html[data-theme="dark"] .php-error-message {
      border-color: rgba(255,107,107,0.25); background-color: rgba(255,107,107,0.08);
    }

    .php-success-message {
      font-size: 13px; margin-bottom: 14px; padding: 10px 13px; border-radius: 8px;
      color: var(--success-color); border: 1px solid rgba(0,102,0,0.2); background-color: rgba(0,102,0,0.06);
    }
    html[data-theme="dark"] .php-success-message {
      border-color: rgba(76,175,80,0.25); background-color: rgba(76,175,80,0.08);
    }

    .divider { margin: 18px 0 14px; border: none; border-top: 1px solid var(--border-color); transition: border-color 0.3s ease; }

    .auth-footer { text-align: center; font-size: 13px; color: var(--text-secondary); }
    .auth-switch { color: var(--accent); text-decoration: none; font-weight: 600; }
    .auth-switch:hover { text-decoration: underline; }

    .loading-overlay {
      position: absolute; inset: 0; background-color: rgba(255,255,255,0.88);
      border-radius: 12px; display: flex; flex-direction: column; align-items: center;
      justify-content: center; z-index: 100; opacity: 0; visibility: hidden;
      transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    html[data-theme="dark"] .loading-overlay { background-color: rgba(45,45,45,0.88); }
    .loading-overlay.active { opacity: 1; visibility: visible; }

    .loading-spinner {
      width: 34px; height: 34px;
      border: 3px solid var(--border-color); border-top: 3px solid var(--accent);
      border-radius: 50%; animation: spin 0.9s linear infinite;
    }
    .loading-text { color: var(--text-secondary); font-size: 13px; margin-top: 12px; }

    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

    @media (max-width: 480px) { .auth-box { padding: 24px 18px; } }
  </style>
</head>
<body>
  <div class="auth-container">
    <div class="auth-box">
      <h2 class="auth-title">Chatlify</h2>
      <p class="auth-subtitle">Create your account</p>
      <?php if (!empty($register_error)): ?>
      <div class="php-error-message" id="phpErrorMessage"><?php echo htmlspecialchars($register_error); ?></div>
      <?php else: ?>
      <div class="php-error-message" id="phpErrorMessage" style="display:none;"></div>
      <?php endif; ?>

      <?php if (!empty($register_success)): ?>
      <div class="php-success-message" id="phpSuccessMessage"><?php echo htmlspecialchars($register_success); ?></div>
      <?php else: ?>
      <div class="php-success-message" id="phpSuccessMessage" style="display:none;"></div>
      <?php endif; ?>

      <form id="registerFormElement" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
        <div class="form-group">
          <label class="form-label" for="name">Full Name</label>
          <input type="text" class="form-input" id="name" name="name"
            placeholder="Enter your full name"
            value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>"
            required autocomplete="name">
        </div>
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input type="text" class="form-input" id="username" name="username"
            placeholder="Choose a username"
            value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>"
            required autocomplete="username">
          <div class="form-hint">3-20 characters, letters, numbers, and underscores only</div>
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <div class="input-wrapper">
            <input type="password" class="form-input" id="password" name="password"
              placeholder="Create a password" required autocomplete="new-password" style="padding-right:40px;">
            <button type="button" class="pw-toggle" id="pwToggle" tabindex="-1" aria-label="Show/hide password">
              <svg id="eyeIcon" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
            </button>
          </div>
          <div class="form-hint">At least 6 characters long</div>
        </div>
        <button type="submit" class="form-button" id="registerButton">Create Account</button>
      </form>

      <hr class="divider">

      <div class="auth-footer">
        Already have an account? <a href="login.php" class="auth-switch">Log in here</a>
      </div>

      <div class="loading-overlay" id="registerLoading">
        <div class="loading-spinner"></div>
        <div class="loading-text">Creating account...</div>
      </div>
    </div>
  </div>

  <script>
    const registerForm    = document.getElementById('registerFormElement');
    const usernameInput   = document.getElementById('username');
    const passwordInput   = document.getElementById('password');
    const nameInput       = document.getElementById('name');
    const registerLoading = document.getElementById('registerLoading');
    const registerButton  = document.getElementById('registerButton');
    const errorDiv        = document.getElementById('phpErrorMessage');
    const successDiv      = document.getElementById('phpSuccessMessage');
    const pwToggle        = document.getElementById('pwToggle');
    const eyeIcon         = document.getElementById('eyeIcon');

    var pwVisible = false;
    pwToggle.addEventListener('click', function() {
      pwVisible = !pwVisible;
      passwordInput.type = pwVisible ? 'text' : 'password';
      eyeIcon.innerHTML = pwVisible
        ? '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.8 11.8 0 0 0 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78 3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>'
        : '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
    });

    registerForm.addEventListener('submit', function(e) {
      const password = passwordInput.value;
      const username = usernameInput.value;
      const usernameRegex = /^[a-zA-Z0-9_]+$/;

      if (username.length < 3) {
        e.preventDefault();
        showError('Username must be at least 3 characters long!');
        usernameInput.focus();
        return;
      }
      if (!usernameRegex.test(username)) {
        e.preventDefault();
        showError('Username can only contain letters, numbers, and underscores!');
        usernameInput.focus();
        return;
      }
      if (password.length < 6) {
        e.preventDefault();
        showError('Password must be at least 6 characters long!');
        passwordInput.focus();
        return;
      }

      registerLoading.classList.add('active');
      registerButton.disabled = true;
    });

    function showError(msg) {
      errorDiv.textContent = msg;
      errorDiv.style.display = 'block';
      successDiv.style.display = 'none';
    }

    window.addEventListener('load', function() {
      setTimeout(function() { nameInput.focus(); }, 200);
      if (successDiv && successDiv.style.display !== 'none') {
        setTimeout(function() { successDiv.style.display = 'none'; }, 5000);
      }
    });

    [nameInput, usernameInput, passwordInput].forEach(function(input) {
      input.addEventListener('input', function() {
        if (errorDiv.style.display !== 'none') errorDiv.style.display = 'none';
      });
    });

    usernameInput.addEventListener('input', function() {
      const val = this.value;
      const usernameRegex = /^[a-zA-Z0-9_]+$/;
      if (val && !usernameRegex.test(val)) {
        this.style.borderColor = '#cc0000';
      } else {
        this.style.borderColor = val ? 'var(--accent)' : 'var(--border-input)';
      }
    });
  </script>
</body>
</html>