# First Aid Inventory System (B. Braun) - Native MySQL Edition

Aplikasi inventaris kotak P3K (First Aid) berbasis Web Native (PHP + MySQL + HTML/CSS/JS).

---

## 📁 Struktur File

- **[`database.sql`](file:///c:/laragon/www/APARB.Braun/first-aid-inventory/database.sql)**: Skema MySQL lengkap berisi 480 item barang, 117 riwayat transaksi, dan akun pengguna.
- **[`config.php`](config.php)**: Konfigurasi koneksi database MySQL dan reCAPTCHA menggunakan PDO.
- **[`api.php`](file:///c:/laragon/www/APARB.Braun/first-aid-inventory/api.php)**: REST API backend native pengganti file `.gs` Google Apps Script.
- **[`index.html`](file:///c:/laragon/www/APARB.Braun/first-aid-inventory/index.html)**: Frontend antarmuka pengguna (Dashboard, Chart.js, Input, Form Edit, Modal Qty).

---

## 🔑 Akun Login Default

Sesuai data dari spreadsheet:
| Username | Password | Role | Keterangan |
| :--- | :--- | :--- | :--- |
| **`sarianid`** | `ehss01` | **Admin** | Akses penuh: Dashboard, Input, Edit, Delete, Bulk Replace |
| **`firstaid`** | `p3k123` | **User** | Akses First Aid Team: Input dan penyesuaian stok (+/-) |

---

## 🚀 Cara Menjalankan di Laragon / XAMPP

1. Buka **phpMyAdmin** (`http://localhost/phpmyadmin`).
2. Buat database baru bernama `first_aid_db` (atau langsung import file [`database.sql`](file:///c:/laragon/www/APARB.Braun/first-aid-inventory/database.sql)).
3. Buka browser dan akses folder proyek ini, misalnya:
   - Jika dijalankan lewat Laragon: `http://localhost/APARB.Braun/first-aid-inventory/`
   - Atau buat symlink / copy folder `first-aid-inventory` langsung ke `c:\laragon\www\first-aid-inventory\` untuk akses via `http://first-aid-inventory.test`.
