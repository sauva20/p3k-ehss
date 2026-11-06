<?php
// api.php - Native REST API Backend (Pengganti Google Apps Script .gs)
header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

function sendResponse(array $response): void
{
    echo http_build_query($response);
}

session_start();
require_once __DIR__ . '/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        actor_name VARCHAR(100) NOT NULL,
        actor_role VARCHAR(50) NOT NULL,
        action VARCHAR(100) NOT NULL,
        item_box VARCHAR(100) DEFAULT NULL,
        quantity INT DEFAULT NULL,
        details TEXT NOT NULL,
        INDEX idx_activity_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$quantityColumn = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'activity_logs'
      AND COLUMN_NAME = 'quantity'
");
$quantityColumn->execute();
if ((int)$quantityColumn->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE activity_logs ADD quantity INT DEFAULT NULL AFTER item_box");
}

function logActivity(PDO $pdo, string $action, string $details, ?string $itemBox = null, ?int $quantity = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (created_at, actor_name, actor_role, action, item_box, quantity, details)
        VALUES (NOW(), ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        !empty($_SESSION['username']) ? $_SESSION['username'] : 'System',
        !empty($_SESSION['role']) ? $_SESSION['role'] : 'system',
        $action,
        $itemBox,
        $quantity,
        $details,
    ]);
}

function isCurrentAdmin(PDO $pdo): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $currentRole = $stmt->fetchColumn();

    return $currentRole === 'admin';
}

// Terima input form-urlencoded dari fetch atau POST biasa
$input = $_POST;
$action = $_GET['action'] ?? ($input['action'] ?? '');

switch ($action) {

    // 1. LOGIN
    case 'login':
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
        $captchaToken = trim($input['captchaToken'] ?? '');

        if ($captchaToken === '') {
            sendResponse(['success' => false, 'message' => 'Please complete the reCAPTCHA check']);
            exit;
        }

        $verifyContext = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query([
                    'secret' => $recaptcha_secret,
                    'response' => $captchaToken,
                ]),
                'timeout' => 8,
            ],
        ]);
        $verifyResponse = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $verifyContext);
        $verification = $verifyResponse !== false ? json_decode($verifyResponse, true) : null;

        if (!is_array($verification) || empty($verification['success'])) {
            sendResponse(['success' => false, 'message' => 'reCAPTCHA verification failed. Please try again']);
            exit;
        }

        if ($username === '' || $password === '') {
            sendResponse(['success' => false, 'message' => 'Username dan password wajib diisi']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            $isValid = false;
            // Cek password hash atau plaintext (kompatibilitas migrasi awal)
            if (password_verify($password, $user['password']) || $password === $user['password']) {
                $isValid = true;
                // Upgrade otomatis ke bcrypt hash jika masih plaintext
                if ($password === $user['password']) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmtUp = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmtUp->execute([$newHash, $user['id']]);
                }
            }

            if ($isValid) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = strtolower(trim($user['role']));
                logActivity($pdo, 'Login', 'User berhasil login.');

                sendResponse([
                    'success'  => true,
                    'role'     => $_SESSION['role'],
                    'username' => $user['username']
                ]);
                exit;
            }
        }

        sendResponse(['success' => false, 'message' => 'Username atau password salah']);
        break;

    case 'getSession':
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            sendResponse(['success' => false, 'message' => 'Sesi login tidak ditemukan.']);
            break;
        }

        sendResponse([
            'success' => true,
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
        ]);
        break;

    case 'logout':
        if (!empty($_SESSION['username'])) {
            logActivity($pdo, 'Logout', 'User berhasil logout.');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        sendResponse(['success' => true]);
        break;

    case 'getActivityLog':
        if (!isCurrentAdmin($pdo)) {
            http_response_code(403);
            sendResponse(['success' => false, 'message' => 'Hanya admin yang dapat melihat log activity.']);
            break;
        }

        $stmt = $pdo->query("
            SELECT
                `Tanggal`,
                `User`,
                `Role`,
                `Aksi`,
                `Kode`,
                `Jumlah`,
                `Detail`,
                `Sumber`
            FROM (
                SELECT
                    tanggal AS `Tanggal`,
                    user AS `User`,
                    '' AS `Role`,
                    aksi AS `Aksi`,
                    kotak AS `Kode`,
                    jumlah AS `Jumlah`,
                    '' AS `Detail`,
                    'Transaksi stok' AS `Sumber`
                FROM transaksi

                UNION ALL

                SELECT
                    created_at AS `Tanggal`,
                    actor_name AS `User`,
                    actor_role AS `Role`,
                    action AS `Aksi`,
                    item_box AS `Kode`,
                    quantity AS `Jumlah`,
                    details AS `Detail`,
                    'Aktivitas' AS `Sumber`
                FROM activity_logs
                WHERE action NOT IN ('tambah', 'kurang')
            ) AS activity_history
            ORDER BY `Tanggal` DESC
        ");
        sendResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'getUsers':
        if (!isCurrentAdmin($pdo)) {
            http_response_code(403);
            sendResponse(['success' => false, 'message' => 'Hanya admin yang dapat mengelola akun.']);
            break;
        }

        $stmt = $pdo->query("SELECT id, username, role FROM users ORDER BY username ASC");
        sendResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'createUser':
        if (!isCurrentAdmin($pdo)) {
            http_response_code(403);
            sendResponse(['success' => false, 'message' => 'Hanya admin yang dapat mengelola akun.']);
            break;
        }

        $newUsername = trim($input['username'] ?? '');
        $newPassword = $input['password'] ?? '';
        $newRole = strtolower(trim($input['role'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $newUsername)) {
            sendResponse(['success' => false, 'message' => 'Username harus 3-100 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.']);
            break;
        }
        if (!is_string($newPassword) || strlen($newPassword) < 8) {
            sendResponse(['success' => false, 'message' => 'Password minimal 8 karakter.']);
            break;
        }
        if (!in_array($newRole, ['admin', 'user'], true)) {
            sendResponse(['success' => false, 'message' => 'Role harus admin atau user.']);
            break;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$newUsername, password_hash($newPassword, PASSWORD_DEFAULT), $newRole]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                sendResponse(['success' => false, 'message' => 'Username tersebut sudah digunakan.']);
                break;
            }
            throw $error;
        }

        logActivity($pdo, 'Create user', "Username: {$newUsername}; role: {$newRole}.");
        sendResponse(['success' => true, 'message' => 'Akun berhasil dibuat.']);
        break;

    case 'deleteUser':
        if (!isCurrentAdmin($pdo)) {
            http_response_code(403);
            sendResponse(['success' => false, 'message' => 'Hanya admin yang dapat mengelola akun.']);
            break;
        }

        $targetId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$targetId || $targetId < 1) {
            sendResponse(['success' => false, 'message' => 'ID akun tidak valid.']);
            break;
        }
        if ($targetId === (int)$_SESSION['user_id']) {
            sendResponse(['success' => false, 'message' => 'Akun yang sedang digunakan tidak dapat dihapus.']);
            break;
        }

        $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            sendResponse(['success' => false, 'message' => 'Akun tidak ditemukan.']);
            break;
        }

        if ($targetUser['role'] === 'admin') {
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                sendResponse(['success' => false, 'message' => 'Akun admin terakhir tidak dapat dihapus.']);
                break;
            }
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        logActivity($pdo, 'Delete user', "Username: {$targetUser['username']}; role: {$targetUser['role']}.");
        sendResponse(['success' => true, 'message' => 'Akun berhasil dihapus.']);
        break;

    // 2. GET BARANG (Format array 2D identik dengan getValues() Google Sheets)
    case 'getBarang':
        $stmt = $pdo->query("SELECT id, kotak, nama, standar, stok, pic, lokasi FROM barang ORDER BY id ASC");
        $rows = $stmt->fetchAll();

        // Row 0 adalah header tabel, identik dengan format Google Sheets
        $data = [
            ["Box Number", "Item Name", "Standard Stock", "Current Stock", "PIC", "Location", "ID"]
        ];

        foreach ($rows as $row) {
            $data[] = [
                (string)$row['kotak'],
                (string)$row['nama'],
                (int)$row['standar'],
                (int)$row['stok'],
                (string)$row['pic'],
                (string)$row['lokasi'],
                (int)$row['id'] // Index 6 untuk primary key ID database
            ];
        }

        sendResponse(['success' => true, 'data' => $data]);
        break;

    // 3. UPDATE STOK
    case 'updateStokByIndex':
        $id = $input['id'] ?? null;
        $jumlah = (int)($input['jumlah'] ?? 0);
        $aksi = trim($input['aksi'] ?? '');
        $user = $_SESSION['username'] ?? 'System';

        if (!$id || $jumlah <= 0) {
            sendResponse(['success' => false, 'message' => 'Jumlah harus angka dan > 0']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT kotak, stok FROM barang WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item) {
            sendResponse(['success' => false, 'message' => 'Data barang tidak ditemukan']);
            exit;
        }

        $stokSekarang = (int)$item['stok'];
        if ($aksi === 'tambah') {
            $stokSekarang += $jumlah;
        } elseif ($aksi === 'kurang') {
            if ($jumlah > $stokSekarang) {
                sendResponse(['success' => false, 'message' => '❌ Stok tidak cukup!']);
                exit;
            }
            $stokSekarang -= $jumlah;
        } else {
            sendResponse(['success' => false, 'message' => 'Aksi tidak valid']);
            exit;
        }

        // Simpan stok baru
        $upStmt = $pdo->prepare("UPDATE barang SET stok = ?, updated_at = NOW() WHERE id = ?");
        $upStmt->execute([$stokSekarang, $id]);

        // Catat riwayat log transaksi
        $logStmt = $pdo->prepare("INSERT INTO transaksi (tanggal, created_at, kotak, aksi, jumlah, user) VALUES (NOW(), NOW(), ?, ?, ?, ?)");
        $logStmt->execute([$item['kotak'], $aksi, $jumlah, $user]);
        logActivity(
            $pdo,
            $aksi,
            "Jumlah: {$jumlah}; stok setelah perubahan: {$stokSekarang}.",
            $item['kotak'],
            $jumlah
        );

        sendResponse(['success' => true, 'message' => '✅ Data berhasil disimpan']);
        break;

    // 4. TAMBAH BARANG
    case 'tambahBarang':
        $kotak = trim($input['kotak'] ?? '');
        $nama = trim($input['nama'] ?? '');
        $standar = (int)($input['standar'] ?? 0);
        $stok = (int)($input['stok'] ?? 0);
        $pic = trim($input['pic'] ?? '');
        $lokasi = trim($input['lokasi'] ?? '');

        if ($kotak === '' || $nama === '' || $pic === '' || $lokasi === '' || $stok < 0) {
            sendResponse(['success' => false, 'message' => 'Semua kolom wajib diisi dan stok harus >= 0']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO barang (kotak, nama, standar, stok, pic, lokasi, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$kotak, $nama, $standar, $stok, $pic, $lokasi]);
        logActivity(
            $pdo,
            'Add item',
            "Nama: {$nama}; stok: {$stok}; PIC: {$pic}; lokasi: {$lokasi}.",
            $kotak
        );

        sendResponse(['success' => true, 'message' => '✅ Data saved successfully.', 'id' => $pdo->lastInsertId()]);
        break;

    // 5. EDIT BARANG
    case 'editBarang':
        $id = $input['id'] ?? null;
        $kotak = trim($input['kotak'] ?? '');
        $nama = trim($input['nama'] ?? '');
        $stok = (int)($input['stok'] ?? 0);
        $pic = trim($input['pic'] ?? '');
        $lokasi = trim($input['lokasi'] ?? '');

        if (!$id || $kotak === '' || $nama === '' || $pic === '' || $lokasi === '' || $stok < 0) {
            sendResponse(['success' => false, 'message' => 'Data tidak lengkap untuk update']);
            exit;
        }

        $beforeStmt = $pdo->prepare("SELECT kotak, nama, stok, pic, lokasi FROM barang WHERE id = ?");
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch();
        if (!$before) {
            sendResponse(['success' => false, 'message' => 'Data barang tidak ditemukan']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE barang SET kotak = ?, nama = ?, stok = ?, pic = ?, lokasi = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$kotak, $nama, $stok, $pic, $lokasi, $id]);
        logActivity(
            $pdo,
            'Edit item',
            "Kotak: {$before['kotak']} -> {$kotak}; nama: {$before['nama']} -> {$nama}; stok: {$before['stok']} -> {$stok}; PIC: {$pic}; lokasi: {$lokasi}.",
            $kotak
        );

        sendResponse(['success' => true, 'message' => '✅ Item updated successfully.']);
        break;

    // 6. HAPUS BARANG
    case 'hapusBarang':
        $id = $input['id'] ?? null;
        if (!$id) {
            sendResponse(['success' => false, 'message' => 'ID tidak ditemukan']);
            exit;
        }

        $itemStmt = $pdo->prepare("SELECT kotak, nama FROM barang WHERE id = ?");
        $itemStmt->execute([$id]);
        $item = $itemStmt->fetch();
        if (!$item) {
            sendResponse(['success' => false, 'message' => 'Data barang tidak ditemukan']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM barang WHERE id = ?");
        $stmt->execute([$id]);
        logActivity($pdo, 'Delete item', "Nama: {$item['nama']}.", $item['kotak']);

        sendResponse(['success' => true, 'message' => '✅ Item deleted successfully.']);
        break;

    // 7. BULK REPLACE (Mass Find & Replace)
    case 'bulkReplace':
        $findText = trim($input['findText'] ?? '');
        $replaceText = trim($input['replaceText'] ?? '');

        if ($findText === '') {
            sendResponse(['success' => false, 'message' => 'Teks cari tidak boleh kosong']);
            exit;
        }

        if (strtolower($findText) === strtolower($replaceText)) {
            sendResponse(['success' => false, 'message' => 'Teks cari dan pengganti tidak boleh sama, baik huruf besar/kecilnya.']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE barang 
            SET 
                kotak = REPLACE(kotak, ?, ?),
                nama = REPLACE(nama, ?, ?),
                pic = REPLACE(pic, ?, ?),
                lokasi = REPLACE(lokasi, ?, ?),
                updated_at = NOW()
            WHERE 
                kotak LIKE ? OR 
                nama LIKE ? OR 
                pic LIKE ? OR 
                lokasi LIKE ?
        ");

        $likeText = '%' . $findText . '%';
        $stmt->execute([
            $findText,
            $replaceText,
            $findText,
            $replaceText,
            $findText,
            $replaceText,
            $findText,
            $replaceText,
            $likeText,
            $likeText,
            $likeText,
            $likeText,
        ]);
        logActivity(
            $pdo,
            'Bulk replace',
            "Teks '{$findText}' diganti menjadi '{$replaceText}' pada {$stmt->rowCount()} barang."
        );

        sendResponse([
            'success' => true,
            'message' => '✅ Mass update completed.',
            'totalUpdate' => $stmt->rowCount()
        ]);
        break;

    default:
        sendResponse(['success' => false, 'message' => 'Action tidak dikenali']);
        break;
}
