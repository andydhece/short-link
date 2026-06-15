# Product Requirement Document (PRD)
## Aplikasi Pemendek URL Kustom Modern & Analitik

---

## 1. Pendahuluan & Ringkasan Proyek
Dokumen ini menjelaskan kebutuhan produk untuk membangun aplikasi **Pemendek URL Kustom Modern** yang dirancang sebagai pengganti YOURLS. Aplikasi ini akan mengutamakan performa tinggi (latensi rendah), antarmuka admin yang premium (UI/UX modern), manajemen banyak pengguna (multi-tenant), serta analitik statistik real-time yang detail.

---

## 2. Tujuan Utama
1. **Kecepatan Pengalihan (Redirect)**: Melakukan pengalihan dari URL pendek ke URL panjang dalam waktu kurang dari 10ms menggunakan caching.
2. **Dashboard Premium**: Menyajikan dasbor admin yang estetis dengan visualisasi grafik yang interaktif.
3. **Skalabilitas**: Mampu menangani ribuan request secara bersamaan tanpa membebani database utama.
4. **Manajemen Pengguna Terpusat**: Mendukung multi-user dengan hak akses yang teratur.

---

## 3. Arsitektur & Teknologi yang Direkomendasikan
* **Frontend**: React.js / Next.js, TailwindCSS (desain responsif, mode gelap/terang), Recharts (untuk grafik analitik).
* **Backend**: Go (Golang) / Node.js (Fastify/NestJS) - dipilih karena performa konkurensinya tinggi.
* **Database & Cache**:
  * **PostgreSQL**: Untuk menyimpan relasi data pengguna, link, dan konfigurasi secara persisten.
  * **Redis**: Digunakan untuk menyimpan cache URL guna proses pengalihan instan dan manajemen *rate limiting* (anti-flood).
* **Kontainer**: Docker & Docker Compose untuk kemudahan deployment.

---

## 4. Kebutuhan Fungsional (Fitur Utama)

### A. Autentikasi & Manajemen Pengguna
1. **Registrasi & Login**: Login menggunakan email/password kustom.
2. **Integrasi SSO (Opsional)**: Mendukung OAuth2 (Login dengan Google, GitHub, atau SSO Internal Instansi).
3. **Role-Based Access Control (RBAC)**:
   * **Super Admin**: Dapat mengelola semua link, konfigurasi sistem global, mengaktifkan/menonaktifkan user lain, dan melihat statistik sistem secara menyeluruh.
   * **User/Staff**: Hanya dapat mengelola, membuat, dan melihat statistik dari link yang mereka buat sendiri.

### B. Mesin Pemendek URL (Shortener Engine)
1. **Input URL**: Form input URL panjang dengan validasi format.
2. **Metode Pembuatan Slug**:
   * **Acak (Auto-generated)**: Menghasilkan slug acak dengan panjang karakter yang bisa disesuaikan (default 5 karakter, misal: `/aB7x9`).
   * **Kustom (Custom Keyword)**: Pengguna dapat menentukan slug sendiri (misal: `/pendaftaran-2026`). Karakter yang diperbolehkan adalah huruf, angka, dan tanda hubung (`-`).
3. **Sensor Kata (Word Blacklist)**: Sistem menyaring kata-kata kasar/tidak pantas agar tidak secara acak terpilih menjadi slug.
4. **Pengalihan Cepat (Redirect)**:
   * Menggunakan HTTP Status **301 (Moved Permanently)** untuk SEO, atau **302 (Found)** untuk tautan dinamis yang sering berubah.
   * Jika slug tidak ditemukan di Redis Cache, sistem akan mencari di database relasional, memasukkannya ke cache, lalu melakukan redirect. Jika tetap tidak ada, arahkan ke halaman 404 kustom yang elegan.

### C. Sistem Analitik & Log (Real-time Analytics)
Setiap kali pengalihan URL terjadi, sistem akan mencatat metadata pengunjung secara asinkron (tidak memblokir proses redirect):
1. **Metrik Utama**:
   * Jumlah Total Klik (Unique clicks vs Total clicks).
   * Rincian klik per hari/minggu/bulan (Histori).
2. **Analisis Geografis**:
   * Deteksi Negara dan Kota pengunjung berdasarkan alamat IP (menggunakan database GeoIP seperti MaxMind atau layanan API gratis).
3. **Informasi Teknis**:
   * Browser yang digunakan (Chrome, Firefox, Safari, dll.).
   * Sistem Operasi (Windows, macOS, Android, iOS, Linux).
   * Jenis Perangkat (Desktop, Mobile, Tablet).
4. **Analisis Perujuk (Referrer)**:
   * Dari mana pengunjung mengeklik tautan tersebut (Tautan Langsung, Facebook, Twitter, WhatsApp, Google Search, dll.).

### D. Dashboard Manajemen Admin
1. **CRUD Link**: Membuat, membaca, mengedit link tujuan, dan menghapus link yang ada.
2. **Pencarian & Filter Tingkat Lanjut**:
   * Kolom pencarian instan berdasarkan judul, URL panjang, atau slug.
   * Filter berdasarkan tanggal pembuatan, jumlah klik terbanyak, atau status link.
3. **Visualisasi Data**:
   * Grafik garis untuk klik harian.
   * Grafik lingkaran untuk browser terpopuler, OS, dan negara perujuk.
4. **Ekspor Data**: Fitur mengunduh data statistik link dalam format CSV atau Excel.

### E. Integrasi API (REST API)
Aplikasi menyediakan endpoint API terautentikasi (menggunakan API Key atau JWT Token):
* `POST /api/v1/shorten` : Membuat shortlink baru.
* `GET /api/v1/expand/{slug}` : Mengambil URL panjang asli.
* `GET /api/v1/stats/{slug}` : Mengambil data analitik untuk slug tertentu.

---

## 5. Kebutuhan Non-Fungsional (Performance & Security)
1. **Performance**:
   * *Response Time* pengalihan tautan di bawah 15ms.
   * Server database dioptimasi dengan indeks pada kolom `slug` dan `created_at`.
2. **Security**:
   * Proteksi penuh dari serangan brute force login dan spamming link (*Rate Limiting* berbasis alamat IP pengunjung menggunakan Redis).
   * Kredensial password dienkripsi dengan algoritma aman seperti **BCrypt** atau **Argon2id**.
   * Seluruh komunikasi wajib menggunakan **HTTPS**.
3. **High Availability**:
   * Penyimpanan database harus dipisahkan dalam folder volume persisten (seperti folder `dc` pada setup docker saat ini) agar data aman meskipun kontainer dibuat ulang.
