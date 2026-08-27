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

## 🔒 Keamanan

### Whitelist Host
Relay **hanya** meneruskan ke host yang terdaftar di array `$allowedHosts`. Host yang tidak terdaftar akan ditolak (HTTP 403).

```php
$allowedHosts = [
    'localhost',
    '127.0.0.1',
    'cepad',
    'cepad.tailb17b07.ts.net',
    '100.122.111.21',
    'apps.sinjaikab.go.id',
];
```

### Whitelist Path
Selain host, relay juga memvalidasi path callback. Hanya path yang mengandung pattern yang diizinkan yang akan diteruskan.

```php
$allowedPathPatterns = [
    '/auth/google/callback',
    '/oauth/callback',
    '/auth/callback',
];
```

### CSRF Protection
Token CSRF dikirim via `state` dan diverifikasi oleh app saat menerima callback. Relay hanya meneruskan, tidak memverifikasi — karena **hanya app yang tahu session token-nya**.

### Logging
Semua request (berhasil maupun ditolak) dicatat di:
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

## 🛠️ Integrasi di Aplikasi

### Yang dibutuhkan di setiap app:

1. **Saat user klik Login Google**, redirect ke Google OAuth:
   ```
   https://accounts.google.com/o/oauth2/v2/auth?
     client_id=CLIENT_ID&
     redirect_uri=https://DOMAIN-PUBLIK.com/oauth-relay/&
     response_type=code&
     scope=openid email profile&
     state=BASE64_JSON_STATE&
     prompt=select_account
   ```

2. **Di controller callback** (`/auth/google/callback`):
   - Terima `code` dan `csrf_token` dari query string
   - Verifikasi CSRF token dengan session
   - Tukar `code` ke access token via Google Token API
   - Ambil user info via Google UserInfo API
   - Proses login/register

### Contoh state encoding (PHP):
```php
$state = base64_encode(json_encode([
    'return_url' => base_url('auth/google/callback'),
    'csrf_token' => bin2hex(random_bytes(16)),
    'app_name'   => 'Nama Aplikasi',
]));
```

---

## 📁 Struktur File

```
oauth-relay/
├── index.php       ← Script relay utama
├── README.md       ← Dokumentasi ini
└── writable/
    └── logs/       ← Log file (auto-created)
        └── oauth-relay-2026-08.log
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

Semua app pakai **1 Client ID** dan **1 relay** yang sama. Cukup tambahkan hostname baru ke `$allowedHosts` jika ada server/app baru.

---

## 📝 Lisensi

Internal Diskominfo Sinjai — Muhammad Rusyaid, S.Kom., M.Si.
