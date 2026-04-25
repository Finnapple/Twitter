<?php
session_start();

// Check if session is valid — supports PHP session_id AND persistent remember_token cookie
// (remember_token survives browser close/minimize on Android & iOS)
function isValidSession() {
    // 1. PHP session still alive — no file I/O needed
    if (isset($_SESSION['ghostlan_admin']) && $_SESSION['ghostlan_admin'] === true) {
        return true;
    }

    // 2. PHP session gone (mobile browser killed it) — check remember_token cookie
    if (!file_exists('session.json')) return false;

    $json_content = file_get_contents('session.json');
    $sessions = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($sessions)) return false;

    $session_id     = session_id();
    $remember_token = isset($_COOKIE['remember_token']) ? $_COOKIE['remember_token'] : null;

    foreach ($sessions as $session) {
        $matched = false;

        // Prefer remember_token match (persists across browser restarts)
        if ($remember_token && isset($session['remember_token']) && $session['remember_token'] === $remember_token) {
            $matched = true;
        }
        // Fallback: PHP session_id match
        elseif (isset($session['session_id']) && $session['session_id'] === $session_id) {
            $matched = true;
        }

        if ($matched) {
            if (isset($session['expires_at']) && time() < $session['expires_at']) {
                // Restore PHP session so subsequent requests skip file I/O
                $_SESSION['ghostlan_admin'] = true;
                $_SESSION['username']       = $session['username']   ?? '';
                $_SESSION['name']           = $session['name']       ?? '';
                $_SESSION['is_admin']       = $session['is_admin']   ?? false;
                $_SESSION['login_time']     = $session['login_time'] ?? time();
                return true;
            }
            return false; // matched but expired
        }
    }
    return false;
}

if (!isValidSession()) {
    http_response_code(401);
    die("Unauthorized");
}

// Release session lock immediately — allows concurrent requests (upload, load, send)
// to run in parallel without queuing behind each other.
session_write_close();

$chatlogs_file = 'chatlogs.json';
$uploads_dir = __DIR__ . '/uploads/';

// Load existing chatlogs
$messages = [];
if (file_exists($chatlogs_file)) {
    $json_content = file_get_contents($chatlogs_file);
    $messages = json_decode($json_content, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($messages)) {
        $messages = [];
    }
}


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST["name"] ?? '');
    $message = trim($_POST["message"] ?? '');
    $uploaded_file = trim($_POST["uploaded_file"] ?? ''); // optional: file name being sent

    if (empty($name)) {
        http_response_code(400);
        die("Name is required");
    }

    // Add text message if provided
    if (!empty($message)) {
        $dt = new DateTime();
        $dt->setTimezone(new DateTimeZone('Asia/Manila'));
        $messages[] = [
            'id' => 'msg_' . uniqid(),
            'sender' => $name,
            'message' => $message,
            'timestamp' => $dt->format('Y-m-d H:i:s.u'), // microseconds for ordering
            'type' => 'text'
        ];
    }

    // Add uploaded file if provided and file exists in uploads folder
    if (!empty($uploaded_file)) {
        $filePath = $uploads_dir . $uploaded_file;
        if (file_exists($filePath)) {
            $dt = new DateTime();
            $dt->setTimezone(new DateTimeZone('Asia/Manila'));
            $messages[] = [
                'id' => 'msg_' . uniqid(),
                'sender' => $name,
                'message' => $uploaded_file,
                'timestamp' => $dt->format('Y-m-d H:i:s.u'),
                'type' => 'upload'
            ];
        }
    }

    // Sort all messages by timestamp ascending
    usort($messages, function($a, $b) {
        return strtotime($a['timestamp']) <=> strtotime($b['timestamp']);
    });

    // Trim to 5000 messages max — remove oldest first, delete upload files too
    if (count($messages) > 5000) {
        $trimmed = array_slice($messages, 0, count($messages) - 5000);
        foreach ($trimmed as $old) {
            if (($old['type'] ?? '') === 'upload') {
                $filePath = $uploads_dir . $old['message'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
        }
        $messages = array_slice($messages, -5000);
    }

    // Save back to JSON
    if (file_put_contents($chatlogs_file, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        echo "Message sent";
    } else {
        http_response_code(500);
        echo "Error saving message";
    }
}
?>
