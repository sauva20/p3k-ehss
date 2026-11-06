<?php
// Salin file ini menjadi config.php. Atur APP_ENV dan DB_* di environment

$app_env = strtolower((string)(getenv('APP_ENV') ?: 'production'));
$is_local = $app_env === 'local';

$db_host = getenv('DB_HOST');
$db_port = getenv('DB_PORT');
$db_name = getenv('DB_NAME');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$recaptcha_secret = getenv('RECAPTCHA_SECRET');

if ($is_local) {
    $db_host = $db_host !== false ? $db_host : 'localhost';
    $db_port = $db_port !== false ? $db_port : '3306';
    $db_name = $db_name !== false ? $db_name : 'first_aid_db';
    $db_user = $db_user !== false ? $db_user : 'root';
    $db_pass = $db_pass !== false ? $db_pass : '';
    $recaptcha_secret = $recaptcha_secret !== false ? $recaptcha_secret : 'PUT_RECAPTCHA_SECRET_HERE';
} else {
    $required_settings = [
        'DB_HOST' => $db_host,
        'DB_PORT' => $db_port,
        'DB_NAME' => $db_name,
        'DB_USER' => $db_user,
        'DB_PASS' => $db_pass,
        'RECAPTCHA_SECRET' => $recaptcha_secret,
    ];
    $missing_settings = [];

    foreach ($required_settings as $name => $value) {
        if ($value === false || $value === '') {
            $missing_settings[] = $name;
        }
    }

    if ($missing_settings !== []) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Konfigurasi server belum lengkap. Atur environment variable: ' . implode(', ', $missing_settings),
        ]);
        exit;
    }
}

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
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database gagal. Periksa environment variable DB_* di konfigurasi server.',
    ]);
    exit;
}