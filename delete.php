<?php
session_start();

// Check if user is admin
function isAdmin() {
    if (!file_exists('session.json')) return false;
    
    $json_content = file_get_contents('session.json');
    $sessions = json_decode($json_content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($sessions)) return false;
    
    $session_id = session_id();
    foreach ($sessions as $session) {
        if (isset($session['session_id']) && $session['session_id'] === $session_id) {
            return isset($session['is_admin']) && $session['is_admin'] === true;
        }
    }
    return false;
}

if (!isAdmin()) {
    http_response_code(403);
    die("Forbidden: Admin access required");
}

$results = [];

// 1. Clear chatlogs.json
$chatlogs_file = 'chatlogs.json';
$empty_array = [];

if (file_put_contents($chatlogs_file, json_encode($empty_array, JSON_PRETTY_PRINT)) !== false) {
    $results[] = "Chat logs cleared";
} else {
    http_response_code(500);
    echo "Error clearing chat logs";
    exit;
}

// 2. Clear uploads folder (excluding .htaccess)
$uploads_folder = 'uploads';
$files_deleted = 0;
$errors = [];

$excluded_files = ['.htaccess'];

if (is_dir($uploads_folder)) {
    $files = array_diff(scandir($uploads_folder), array('.', '..'));
    
    foreach ($files as $file) {
        $file_path = $uploads_folder . '/' . $file;
        
        if (in_array($file, $excluded_files)) {
            continue;
        }
        
        if (is_file($file_path)) {
            if (unlink($file_path)) {
                $files_deleted++;
            } else {
                $errors[] = "Failed to delete: $file";
            }
        }
    }
    
    if ($files_deleted > 0) {
        $results[] = "$files_deleted uploaded file(s) deleted";
    } else {
        $results[] = "No uploaded files found";
    }
} else {
    $results[] = "Uploads folder does not exist";
}

// 3. Clear reactions.json
$reactions_file = 'reactions.json';
if (file_put_contents($reactions_file, json_encode((object)[], JSON_PRETTY_PRINT)) !== false) {
    $results[] = "Reactions cleared";
} else {
    $errors[] = "Failed to clear reactions.json";
}

// 4. Clear seen.json
$seen_file = 'seen.json';
if (file_put_contents($seen_file, json_encode([], JSON_PRETTY_PRINT)) !== false) {
    $results[] = "Seen records cleared";
} else {
    $errors[] = "Failed to clear seen.json";
}

// Display results
echo implode(" | ", $results);

if (!empty($errors)) {
    echo "<br>Errors: " . implode(", ", $errors);
}
?>