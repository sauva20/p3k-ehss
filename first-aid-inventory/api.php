<?php
// api.php - Native REST API Backend (Pengganti Google Apps Script .gs)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/config.php';

// Tangkap input JSON Body (fetch) atau POST biasa
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? $_POST;
$action = $_GET['action'] ?? ($input['action'] ?? '');

switch ($action) {

    // 1. LOGIN
    case 'login':
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
        $captchaToken = trim($input['captchaToken'] ?? '');

        if ($captchaToken === '') {
            echo json_encode(['success' => false, 'message' => 'Please complete the reCAPTCHA check']);
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
            echo json_encode(['success' => false, 'message' => 'reCAPTCHA verification failed. Please try again']);
            exit;
        }

        if ($username === '' || $password === '') {
            echo json_encode(['success' => false, 'message' => 'Username dan password wajib diisi']);
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
                echo json_encode([
                    'success'  => true,
                    'role'     => strtolower(trim($user['role'])),
                    'username' => $user['username']
                ]);
                exit;
            }
        }

        echo json_encode(['success' => false, 'message' => 'Username atau password salah']);
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

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // 3. UPDATE STOK
    case 'updateStokByIndex':
        $id = $input['id'] ?? null;
        $jumlah = (int)($input['jumlah'] ?? 0);
        $aksi = trim($input['aksi'] ?? '');
        $user = trim($input['user'] ?? 'System');

        if (!$id || $jumlah <= 0) {
            echo json_encode(['success' => false, 'message' => 'Jumlah harus angka dan > 0']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT kotak, stok FROM barang WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Data barang tidak ditemukan']);
            exit;
        }

        $stokSekarang = (int)$item['stok'];
        if ($aksi === 'tambah') {
            $stokSekarang += $jumlah;
        } elseif ($aksi === 'kurang') {
            if ($jumlah > $stokSekarang) {
                echo json_encode(['success' => false, 'message' => '❌ Stok tidak cukup!']);
                exit;
            }
            $stokSekarang -= $jumlah;
        } else {
            echo json_encode(['success' => false, 'message' => 'Aksi tidak valid']);
            exit;
        }

        // Simpan stok baru
        $upStmt = $pdo->prepare("UPDATE barang SET stok = ? WHERE id = ?");
        $upStmt->execute([$stokSekarang, $id]);

        // Catat riwayat log transaksi
        $logStmt = $pdo->prepare("INSERT INTO transaksi (tanggal, kotak, aksi, jumlah, user) VALUES (NOW(), ?, ?, ?, ?)");
        $logStmt->execute([$item['kotak'], $aksi, $jumlah, $user]);

        echo json_encode(['success' => true, 'message' => '✅ Data berhasil disimpan']);
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
            echo json_encode(['success' => false, 'message' => 'Semua kolom wajib diisi dan stok harus >= 0']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO barang (kotak, nama, standar, stok, pic, lokasi) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$kotak, $nama, $standar, $stok, $pic, $lokasi]);

        echo json_encode(['success' => true, 'message' => '✅ Data saved successfully.', 'id' => $pdo->lastInsertId()]);
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
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap untuk update']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE barang SET kotak = ?, nama = ?, stok = ?, pic = ?, lokasi = ? WHERE id = ?");
        $stmt->execute([$kotak, $nama, $stok, $pic, $lokasi, $id]);

        echo json_encode(['success' => true, 'message' => '✅ Item updated successfully.']);
        break;

    // 6. HAPUS BARANG
    case 'hapusBarang':
        $id = $input['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak ditemukan']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM barang WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => '✅ Item deleted successfully.']);
        break;

    // 7. BULK REPLACE (Mass Find & Replace)
    case 'bulkReplace':
        $findText = trim($input['findText'] ?? '');
        $replaceText = trim($input['replaceText'] ?? '');

        if ($findText === '') {
            echo json_encode(['success' => false, 'message' => 'Teks cari tidak boleh kosong']);
            exit;
        }

        if (strtolower($findText) === strtolower($replaceText)) {
            echo json_encode(['success' => false, 'message' => 'Teks cari dan pengganti tidak boleh sama, baik huruf besar/kecilnya.']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE barang 
            SET 
                kotak = REPLACE(kotak, ?, ?),
                nama = REPLACE(nama, ?, ?),
                pic = REPLACE(pic, ?, ?),
                lokasi = REPLACE(lokasi, ?, ?)
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

        echo json_encode([
            'success' => true,
            'message' => '✅ Mass update completed.',
            'totalUpdate' => $stmt->rowCount()
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Action tidak dikenali']);
        break;
}
