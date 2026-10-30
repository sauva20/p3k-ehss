<?php
// Salin file ini menjadi config.php, lalu isi nilai lokal yang benar.
$db_host = 'localhost';
$db_port = '3306';
$db_name = 'first_aid_db';
$db_user = 'root';
$db_pass = 'CHANGE_ME';
$recaptcha_secret = 'PUT_RECAPTCHA_SECRET_HERE';

try {
    $pdo = new PDO(
        "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Check local config.php settings.',
    ]);
    exit;
}