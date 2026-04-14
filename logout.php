<?php
session_start();

// Remove session from session.json by both session_id AND remember_token
function removeSessionFromJSON($session_id, $remember_token = null) {
    if (!file_exists('session.json')) return;

    $json_content = file_get_contents('session.json');
    $sessions = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($sessions)) return;

    $updated_sessions = array_filter($sessions, function($session) use ($session_id, $remember_token) {
        // Remove if session_id matches
        if ($session_id && isset($session['session_id']) && $session['session_id'] === $session_id) {
            return false;
        }
        // Also remove if remember_token matches (covers mobile where session_id may differ)
        if ($remember_token && isset($session['remember_token']) && $session['remember_token'] === $remember_token) {
            return false;
        }
        return true;
    });

    file_put_contents('session.json', json_encode(array_values($updated_sessions), JSON_PRETTY_PRINT));
}

$remember_token = isset($_COOKIE['remember_token']) ? $_COOKIE['remember_token'] : null;

// Remove from session.json
removeSessionFromJSON(session_id(), $remember_token);

// Clear the remember_token cookie
setcookie('remember_token', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Destroy PHP session
session_destroy();

// Redirect to login
header("Location: login.php");
exit();
?>