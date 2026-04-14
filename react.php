<?php
session_start();

// Validate session
function isValidSession() {
    if (isset($_SESSION['ghostlan_admin']) && $_SESSION['ghostlan_admin'] === true) {
        return true;
    }
    if (!file_exists('session.json')) return false;
    $sessions = json_decode(file_get_contents('session.json'), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($sessions)) return false;
    $session_id     = session_id();
    $remember_token = $_COOKIE['remember_token'] ?? null;
    foreach ($sessions as $session) {
        $matched = false;
        if ($remember_token && isset($session['remember_token']) && $session['remember_token'] === $remember_token) {
            $matched = true;
        } elseif (isset($session['session_id']) && $session['session_id'] === $session_id) {
            $matched = true;
        }
        if ($matched) {
            if (isset($session['expires_at']) && time() < $session['expires_at']) {
                $_SESSION['ghostlan_admin'] = true;
                $_SESSION['name']           = $session['name'] ?? '';
                return true;
            }
            return false;
        }
    }
    return false;
}

if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

session_write_close();

header('Content-Type: application/json');

// -- Admin: clear ALL reactions (called after admin clears the chat) --
if (($_POST['action'] ?? '') === 'clear_all') {
    // Session already validated above - no extra secret needed
    $reactionsFile = __DIR__ . '/reactions.json';
    file_put_contents($reactionsFile, '{}');
    echo json_encode(['ok' => true, 'action' => 'cleared']);
    exit;
}

$msg_id  = trim($_POST['msg_id']  ?? '');
$emoji   = trim($_POST['emoji']   ?? '');
$reactor = trim($_POST['reactor'] ?? '');

// Only allow these 4 emojis
$allowed = ['😆', '❤️', '😡', '🥺'];

if (!$msg_id || !$emoji || !$reactor || !in_array($emoji, $allowed)) {
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$reactionsFile = __DIR__ . '/reactions.json';

// ── File locking to prevent race conditions ──
$fp = fopen($reactionsFile, file_exists($reactionsFile) ? 'r+' : 'w+');
if (!$fp) {
    echo json_encode(['error' => 'Cannot open reactions file']);
    exit;
}

flock($fp, LOCK_EX);

// Read existing reactions
$content   = stream_get_contents($fp);
$reactions = [];
if ($content) {
    $decoded = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $reactions = $decoded;
    }
}

// ── One-reaction-per-user rule ──
// 1. Find if this reactor already has a reaction on this message
$prevEmoji = null;
foreach ($allowed as $em) {
    if (isset($reactions[$msg_id][$em]) && in_array($reactor, $reactions[$msg_id][$em])) {
        $prevEmoji = $em;
        break;
    }
}

if ($prevEmoji !== null) {
    // Remove the previous reaction regardless
    $reactions[$msg_id][$prevEmoji] = array_values(
        array_filter($reactions[$msg_id][$prevEmoji], fn($u) => $u !== $reactor)
    );
    if (empty($reactions[$msg_id][$prevEmoji])) {
        unset($reactions[$msg_id][$prevEmoji]);
    }
}

// 2. If same emoji as before → toggle OFF (already removed above, done)
//    If different emoji (or no previous) → add the new one
if ($prevEmoji !== $emoji) {
    if (!isset($reactions[$msg_id][$emoji])) {
        $reactions[$msg_id][$emoji] = [];
    }
    if (!in_array($reactor, $reactions[$msg_id][$emoji])) {
        $reactions[$msg_id][$emoji][] = $reactor;
    }
}

// Clean up empty msg entries
if (isset($reactions[$msg_id]) && empty($reactions[$msg_id])) {
    unset($reactions[$msg_id]);
}

// Write back
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($reactions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true, 'action' => $prevEmoji === $emoji ? 'removed' : ($prevEmoji ? 'replaced' : 'added')]);