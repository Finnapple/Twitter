<?php
// validate_secret.php
session_start();

// Check if user is logged in
if (!isset($_SESSION['ghostlan_admin']) || !$_SESSION['ghostlan_admin']) {
    echo json_encode(['valid' => false]);
    exit();
}

// Get POST data
$input_secret = $_POST['secretKey'] ?? '';

// Read secret from secret.json
$secret_file = "secret.json";
if (!file_exists($secret_file)) {
    echo json_encode(['valid' => false]);
    exit();
}

$json_content = file_get_contents($secret_file);
$secret_data = json_decode($json_content, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($secret_data['secret_key'])) {
    echo json_encode(['valid' => false]);
    exit();
}

// Compare secrets
$is_valid = hash_equals($secret_data['secret_key'], $input_secret);

echo json_encode(['valid' => $is_valid]);
?>