# 🔐 OAuth Relay Proxy

**Universal Google OAuth Relay** — Satu endpoint untuk meneruskan callback Google OAuth ke banyak aplikasi di server manapun.

---

## 📋 Masalah yang Diselesaikan

Google OAuth **mengharuskan** redirect URI yang terdaftar di Google Console. Masalahnya:

- Aplikasi diakses dari banyak hostname (`localhost`, `cepad`, `192.168.x.x`, dll)
- Hostname lokal/private **tidak bisa didaftarkan** di Google Console
- Setiap app punya callback URL berbeda

**Solusi:** Satu relay di domain publik yang meneruskan auth code ke app mana saja.

---

## 🔄 Cara Kerja

```
┌─────────────┐    ┌──────────────────┐    ┌─────────────────┐    ┌─────────────┐
│  User klik  │───▶│  Google OAuth     │───▶│  OAuth Relay    │───▶│  App Asal   │
│  Login      │    │  (accounts.       │    │  (domain publik)│    │  (cepad/    │
│  Google     │    │   google.com)     │    │                 │    │   localhost) │
└─────────────┘    └──────────────────┘    └─────────────────┘    └─────────────┘
                          │                        │
                    redirect_uri =           Baca state →
                    oauth-relay URL          redirect ke
                                             return_url + code
```

### Alur Detail

1. **User** klik tombol "Login dengan Google" di aplikasi (misal `http://cepad/e-praja/`)
2. **Aplikasi** redirect ke Google OAuth dengan parameter:
   - `redirect_uri` = URL relay ini (yang terdaftar di Google Console)
   - `state` = berisi `return_url` (callback asli di app) + `csrf_token`
3. **Google** menampilkan halaman login/pilih akun
4. **Google** redirect ke relay ini dengan `?code=AUTH_CODE&state=xxx`
5. **Relay** decode `state`, validasi host tujuan, lalu redirect ke app asal:
   `http://cepad/e-praja/auth/google/callback?code=AUTH_CODE&csrf_token=xxx`
6. **Aplikasi** terima code, tukar ke token, ambil data user, login selesai ✅

---

## 🏗️ Struktur `state` Parameter

Aplikasi mengirim `state` dalam format **base64-encoded JSON**:

```json
{
  "return_url": "http://cepad/e-praja/auth/google/callback",
  "csrf_token": "random_hex_32_char",
  "app_name": "E-PRAJA"
}
```

| Field | Keterangan |
|---|---|
| `return_url` | URL callback lengkap di app asal. Relay akan redirect ke sini. |
| `csrf_token` | Token anti-CSRF, diverifikasi oleh app saat menerima callback. |
| `app_name` | Nama app (opsional), untuk keperluan logging di relay. |

---

## 🗄️ Integration Database (`oauth_db`)

OAuth Relay terhubung ke database `oauth_db` untuk manajemen terpusat:

### 1. `allowed_hosts` (Whitelist Host Target)
Host yang diizinkan melakukan redirect. Disimpan di DB dengan fallback array jika DB down.
```sql
SELECT * FROM oauth_db.allowed_hosts WHERE is_active = 1;
```

### 2. `allowed_users` (Whitelist User / Email)
Membatasi user mana saja yang boleh login Google:
- Jika tabel ini **kosong** (0 entri active), semua user ber-email Google diizinkan.
- Jika tabel ini **diisi**, hanya email yang terdaftar di `allowed_users` (`is_active = 1`) yang diizinkan login.

Daftarkan user baru via SQL atau via helper:
```php
OAuthDB::registerAllowedUser('user@sinjaikab.go.id', 'Nama Pengguna', 'user');
```

### 3. `access_logs` (Pencatatan Audit Log)
Semua aktivitas penerusan relay (`RELAY`) dan login user (`LOGIN`) dicatat ke `oauth_db.access_logs` mencakup:
- `event_type`: `RELAY` / `LOGIN`
- `status`: `OK` / `BLOCKED` / `USER_BLOCKED`
- `app_name`: Nama aplikasi pengakses (misal `E-PRAJA`)
- `user_email` & `user_name`: Email dan nama Google user
- `return_host` & `return_url`: Host dan URL tujuan redirect
- `ip_address` & `user_agent`: IP pengakses & browser User Agent
- `created_at`: Timestamp transaksi

---

## 🔒 Keamanan

### Whitelist Host
Relay **hanya** meneruskan ke host yang terdaftar di `oauth_db.allowed_hosts` (atau fallback array di [db.php](db.php)). Host yang tidak terdaftar akan ditolak (HTTP 403).

### Whitelist Path
Selain host, relay juga memvalidasi path callback. Hanya path yang mengandung pattern yang diizinkan yang akan diteruskan.

### CSRF Protection
Token CSRF dikirim via `state` dan diverifikasi oleh app saat menerima callback. Relay hanya meneruskan, tidak memverifikasi — karena **hanya app yang tahu session token-nya**.

### Logging
Semua request dicatat ke tabel `oauth_db.access_logs` dan file log fallback:
```
writable/logs/oauth-relay-YYYY-MM.log
```

---

## ⚙️ Setup Google Console

1. Buka [Google Cloud Console → Credentials](https://console.cloud.google.com/apis/credentials)
2. Buat **OAuth 2.0 Client ID** (tipe: Web Application)
3. Tambahkan **Authorized redirect URI**:
   ```
   https://DOMAIN-PUBLIK.com/oauth-relay/
   ```
   > Hanya URI ini yang perlu didaftarkan. Tidak perlu daftarkan localhost/cepad.
4. Catat **Client ID** dan **Client Secret**

---

## 🛠️ Integrasi di Aplikasi (Menggunakan `GoogleOAuthClient.php`)

Telah disediakan helper class [GoogleOAuthClient.php](GoogleOAuthClient.php) agar aplikasi PHP dapat terintegrasi dengan sangat mudah.

### 1. Menampilkan Tombol Login (`login.php` / View App)
```php
require_once '/var/www/html/oauth-relay/GoogleOAuthClient.php';

// Inisialisasi helper (Nama Aplikasi, Client ID, Client Secret)
$oauth = new GoogleOAuthClient('E-PRAJA', 'CLIENT_ID_ANDA', 'CLIENT_SECRET_ANDA');

// URL callback lokal aplikasi tempat menerima login
$returnUrl = 'http://cepad/e-praja/auth/google/callback';
$loginUrl  = $oauth->getLoginUrl($returnUrl);
?>

<a href="<?= htmlspecialchars($loginUrl) ?>">Login dengan Google</a>
```

### 2. Menangani Respon Callback (`callback.php` / Controller App)
```php
require_once '/var/www/html/oauth-relay/GoogleOAuthClient.php';

$oauth = new GoogleOAuthClient('E-PRAJA', 'CLIENT_ID_ANDA', 'CLIENT_SECRET_ANDA');

try {
    // Ambil profil pengguna dari Google (otomatis verifikasi CSRF & tukar token)
    $user = $oauth->handleCallback();

    if ($user) {
        $email = $user['email'];
        $nama  = $user['name'];
        $foto  = $user['picture'];
        $sub   = $user['sub']; // ID unik Google

        // Lakukan login session pada aplikasi Anda
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name']  = $nama;

        header('Location: /e-praja/dashboard');
        exit;
    }
} catch (Exception $e) {
    die('Gagal Login Google: ' . $e->getMessage());
}
```

---

## 🚀 Migrasi Database (`oauth_db`)

Untuk memasang atau menginisialisasi database `oauth_db` pada server baru, telah disediakan 2 cara migrasi:

### Cara 1: Otonom via Script PHP CLI / Browser
Jalankan command berikut di terminal server:
```bash
php migrate.php
```
Atau buka via browser: `http://cepad/oauth-relay/migrate.php`

### Cara 2: Import Manual File SQL (`schema.sql`)
```bash
mysql -u root -p < schema.sql
```

---

## 📁 Struktur File

```
oauth-relay/
├── index.php               ← Script relay utama di server publik
├── db.php                  ← Helper DB & koneksi oauth_db
├── dashboard.php           ← Dashboard Admin & Monitoring statistik Native PHP
├── help.php                ← Halaman manual & panduan integrasi OAuth
├── schema.sql              ← Schema SQL migrasi database & seed data
├── migrate.php             ← Script eksekusi migrasi database otomatis
├── GoogleOAuthClient.php   ← Helper class untuk dipakai oleh aplikasi
├── config.example.php      ← Template file konfigurasi kredensial
├── push.sh                 ← Script auto-commit & push standar Sinjai v2.6
├── README.md               ← Dokumentasi ini
└── writable/
    └── logs/               ← Log file (auto-created)
        └── oauth-relay-YYYY-MM.log
```

---

## 🌐 Contoh Multi-App

```
                    ┌─────────────────────────────┐
                    │   Google OAuth Console       │
                    │   Redirect URI:              │
                    │   https://domain.com/        │
                    │          oauth-relay/         │
                    └──────────────┬───────────────┘
                                   │
                    ┌──────────────▼───────────────┐
                    │   oauth-relay/index.php       │
                    │   (domain publik)             │
                    └──┬──────────┬──────────┬─────┘
                       │          │          │
              ┌────────▼──┐ ┌────▼─────┐ ┌──▼────────┐
              │  E-PRAJA  │ │ SIPARDI  │ │  App ke-N  │
              │  /e-praja │ │ /sipardi │ │  /app-xxx  │
              │  /auth/   │ │ /auth/   │ │  /oauth/   │
              │  google/  │ │ google/  │ │  callback  │
              │  callback │ │ callback │ │            │
              └───────────┘ └──────────┘ └───────────┘
```

Semua app pakai **1 Client ID** dan **1 relay** yang sama. Cukup tambahkan hostname baru ke `$allowedHosts` di `index.php` jika ada server/app baru.

---

## 📝 Lisensi

Internal Diskominfo Sinjai — Muhammad Rusyaid, S.Kom., M.Si.

