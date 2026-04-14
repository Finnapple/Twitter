<?php
session_start();
header('Content-Type: application/json');

// Validate session from session.json (consistent with rest of app)
function isValidSession() {
    // 1. PHP session still alive — no file I/O needed
    if (isset($_SESSION['ghostlan_admin']) && $_SESSION['ghostlan_admin'] === true) {
        return true;
    }

    // 2. PHP session gone — check remember_token cookie or session_id against session.json
    if (!file_exists('session.json')) return false;

    $json_content = file_get_contents('session.json');
    $sessions = json_decode($json_content, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($sessions)) return false;

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
                $_SESSION['ghostlan_admin'] = true;
                $_SESSION['name']           = $session['name']     ?? '';
                $_SESSION['username']       = $session['username'] ?? '';
                return true;
            }
            return false;
        }
    }
    return false;
}

if (!isValidSession()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Release session lock immediately — upload.php can take a long time for large files.
// Without this, send.php and load.php are queued behind this session lock the entire
// time the upload is running, causing chat send/poll delays.
session_write_close();

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$chatFile = __DIR__ . '/chatlogs.json';

// Load existing chat logs
$chatLogs = [];
if (file_exists($chatFile)) {
    $chatLogs = json_decode(file_get_contents($chatFile), true);
    if (!is_array($chatLogs)) $chatLogs = [];
}

$response = ['success' => false, 'uploaded' => [], 'errors' => []];

if (!empty($_FILES['files']['name'][0])) {
    $total = count($_FILES['files']['name']);
    $uploader = isset($_POST['uploader']) && trim($_POST['uploader']) !== '' 
                ? trim($_POST['uploader']) 
                : (isset($_SESSION['name']) ? $_SESSION['name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown'));

    for ($i = 0; $i < $total; $i++) {
        $tmpName = $_FILES['files']['tmp_name'][$i];
        $name = basename($_FILES['files']['name'][$i]);
        $error = $_FILES['files']['error'][$i];

        if ($error === UPLOAD_ERR_OK && is_uploaded_file($tmpName)) {
            $target = $uploadDir . $name;

            // Prevent overwrite
            if (file_exists($target)) {
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $base = pathinfo($name, PATHINFO_FILENAME);
                $name = $base . '_' . time() . ($ext ? ".{$ext}" : '');
                $target = $uploadDir . $name;
            }

            if (move_uploaded_file($tmpName, $target)) {
                $response['uploaded'][] = $name;

                // Log upload as a chat message in chatlogs.json
                $dt = new DateTime();
                $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                $chatLogs[] = [
                    'id' => 'msg_' . uniqid(),
                    'sender' => $uploader,
                    'message' => $name,
                    'timestamp' => $dt->format('Y-m-d H:i:s.u'),
                    'type' => 'upload'
                ];

            } else {
                $response['errors'][] = "Failed to move $name.";
            }
        } else {
            $response['errors'][] = "Error uploading $name (error code $error).";
        }
    }

    // Save chat logs (uploads are recorded there)
    // Trim to 100 messages max — remove oldest first, delete upload files too
    if (count($chatLogs) > 100) {
        $trimmed = array_slice($chatLogs, 0, count($chatLogs) - 100);
        foreach ($trimmed as $old) {
            if (($old['type'] ?? '') === 'upload') {
                $filePath = $uploadDir . $old['message'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
        }
        $chatLogs = array_slice($chatLogs, -100);
    }
    file_put_contents($chatFile, json_encode($chatLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $response['success'] = count($response['uploaded']) > 0;
} else {
    $response['errors'][] = 'No files uploaded.';
}

echo json_encode($response);
?>