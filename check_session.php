<?php
session_start();

function isValidSession() {
    // 1. PHP session still alive — but verify remember_token still exists in session.json
    // (another device may have logged in and wiped it)
    if (isset($_SESSION['ghostlan_admin']) && $_SESSION['ghostlan_admin'] === true) {
        $remember_token = isset($_COOKIE['remember_token']) ? $_COOKIE['remember_token'] : null;
        if ($remember_token && file_exists('session.json')) {
            $sessions = json_decode(file_get_contents('session.json'), true);
            if (is_array($sessions)) {
                foreach ($sessions as $session) {
                    if (isset($session['remember_token']) && $session['remember_token'] === $remember_token) {
                        if (isset($session['expires_at']) && time() < $session['expires_at']) {
                            return ['valid' => true];
                        }
                        return ['valid' => false, 'reason' => 'expired'];
                    }
                }
                // Token not found — another device logged in and replaced the session
                return ['valid' => false, 'reason' => 'kicked'];
            }
        }
        // No remember_token cookie — rely on PHP session alone
        return ['valid' => true];
    }

    // 2. PHP session gone — check remember_token cookie against session.json
    if (!file_exists('session.json')) return ['valid' => false, 'reason' => 'no_session'];

    $json_content = file_get_contents('session.json');
    $sessions = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($sessions)) {
        return ['valid' => false, 'reason' => 'no_session'];
    }

    $session_id     = session_id();
    $remember_token = isset($_COOKIE['remember_token']) ? $_COOKIE['remember_token'] : null;

    foreach ($sessions as $session) {
        $matched = false;

        if ($remember_token && isset($session['remember_token']) && $session['remember_token'] === $remember_token) {
            $matched = true;
        } elseif (isset($session['session_id']) && $session['session_id'] === $session_id) {
            $matched = true;
        }

        if ($matched) {
            if (isset($session['expires_at']) && time() < $session['expires_at']) {
                // Restore PHP session
                $_SESSION['ghostlan_admin'] = true;
                $_SESSION['username']       = $session['username']   ?? '';
                $_SESSION['name']           = $session['name']       ?? '';
                $_SESSION['is_admin']       = $session['is_admin']   ?? false;
                $_SESSION['login_time']     = $session['login_time'] ?? time();
                return ['valid' => true];
            }
            return ['valid' => false, 'reason' => 'expired'];
        }
    }

    // No match — kicked by new login on another device
    return ['valid' => false, 'reason' => 'kicked'];
}

header('Content-Type: application/json');
echo json_encode(isValidSession());
?>