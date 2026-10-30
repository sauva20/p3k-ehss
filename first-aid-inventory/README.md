# First Aid Inventory System

Aplikasi inventaris kotak P3K berbasis PHP, MySQL, HTML, CSS, dan JavaScript.

## Setup lokal

1. Buat database MySQL bernama `first_aid_db` dan import dump database yang disimpan secara lokal. Dump database tidak disertakan di repository karena berisi data aplikasi dan kredensial bawaan.
2. Salin `config.example.php` menjadi `config.php`.
3. Isi kredensial database dan secret reCAPTCHA di `config.php`. File tersebut dikecualikan dari Git.
4. Jalankan proyek lewat Apache/Laragon dan buka `http://localhost/p3k-ehss/first-aid-inventory/`.

Jangan gunakan atau publikasikan kredensial bawaan. Buat password baru untuk akun aplikasi dan gunakan secret reCAPTCHA yang sudah dirotasi.
