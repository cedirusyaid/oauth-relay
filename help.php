<?php
/**
 * ============================================================
 * OAUTH RELAY INTEGRATION GUIDE & HELP MANUAL
 * ============================================================
 * Halaman panduan integrasi Google OAuth Relay untuk pengembang
 * aplikasi di lingkungan Pemerintah Kabupaten Sinjai.
 * ============================================================
 */

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$clientId     = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : 'CLIENT_ID_ANDA';
$clientSecret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : 'CLIENT_SECRET_ANDA';
$relayUrl     = defined('GOOGLE_RELAY_URL') ? GOOGLE_RELAY_URL : 'https://apps.sinjaikab.go.id/oauth-relay/';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panduan Integrasi Google OAuth Relay - Pemkab Sinjai</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Highlight.js for Code Highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/atom-one-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"></script>
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: #334155; }
        .hero-section { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #fff; border-radius: 16px; padding: 40px 30px; }
        .card-cred { border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff; }
        .cred-box { background: #f1f5f9; font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.9rem; border-radius: 8px; padding: 10px 14px; word-break: break-all; }
        pre code { border-radius: 10px; font-size: 0.88rem; }
        .step-number { width: 32px; height: 32px; background: #0d6efd; color: #fff; font-weight: 700; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; }
        .copy-btn { font-size: 0.75rem; font-weight: 600; }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="help.php">
            <i class="fa-solid fa-book-open text-primary me-2"></i>OAuth Integration Guide
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-outline-primary btn-sm fw-semibold">
                <i class="fa-solid fa-chart-line me-1"></i> Dashboard Monitoring
            </a>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width: 1000px;">

    <!-- HERO HEADER -->
    <div class="hero-section shadow-sm mb-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <span class="badge bg-primary px-3 py-2 rounded-pill mb-2"><i class="fa-solid fa-shield-halved me-1"></i> Diskominfo Sinjai</span>
                <h2 class="fw-bold mb-2">Panduan Integrasi Google OAuth Relay</h2>
                <p class="text-light opacity-75 mb-0">Satu Relay Proxy OAuth untuk Semua Aplikasi Kepegawaian & Sistem Informasi Pemkab Sinjai.</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <i class="fa-solid fa-key text-primary opacity-25" style="font-size: 5rem;"></i>
            </div>
        </div>
    </div>

    <!-- KREDENSIAL GOLBAL -->
    <div class="card card-cred shadow-sm mb-4">
        <div class="card-header bg-white border-bottom-0 pt-3 px-4">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-key me-2 text-warning"></i>Kredensial OAuth Resmi</h5>
            <small class="text-muted">Gunakan kredensial ini untuk semua aplikasi PHP di lingkungan Pemkab Sinjai.</small>
        </div>
        <div class="card-body px-4 pb-4">
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label fw-bold small text-muted">GOOGLE CLIENT ID</label>
                    <div class="d-flex align-items-center gap-2">
                        <div class="cred-box flex-grow-1 text-primary fw-semibold" id="clientIdText"><?= $clientId ?></div>
                        <button class="btn btn-outline-primary copy-btn" onclick="copyToClipboard('clientIdText', this)">
                            <i class="fa-regular fa-copy me-1"></i> Copy
                        </button>
                    </div>
                </div>

                <div class="col-md-12">
                    <label class="form-label fw-bold small text-muted">GOOGLE CLIENT SECRET</label>
                    <div class="d-flex align-items-center gap-2">
                        <div class="cred-box flex-grow-1 text-danger fw-semibold" id="clientSecretText"><?= $clientSecret ?></div>
                        <button class="btn btn-outline-primary copy-btn" onclick="copyToClipboard('clientSecretText', this)">
                            <i class="fa-regular fa-copy me-1"></i> Copy
                        </button>
                    </div>
                </div>

                <div class="col-md-12">
                    <label class="form-label fw-bold small text-muted">DEFAULT RELAY PROXY URL</label>
                    <div class="d-flex align-items-center gap-2">
                        <div class="cred-box flex-grow-1 text-dark" id="relayUrlText"><?= $relayUrl ?></div>
                        <button class="btn btn-outline-primary copy-btn" onclick="copyToClipboard('relayUrlText', this)">
                            <i class="fa-regular fa-copy me-1"></i> Copy
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ALUR CARA KERJA -->
    <div class="card card-cred shadow-sm mb-4">
        <div class="card-header bg-white border-bottom-0 pt-3 px-4">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-diagram-project me-2 text-info"></i>Cara Kerja OAuth Relay</h5>
        </div>
        <div class="card-body px-4 pb-4">
            <div class="row text-center g-3">
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded-3 h-100">
                        <i class="fa-solid fa-user-gear text-primary fs-3 mb-2"></i>
                        <h6 class="fw-bold mb-1">1. User Klik Login</h6>
                        <small class="text-muted">Aplikasi mengarahkan pengguna ke Google OAuth dengan `return_url` aplikasi Anda.</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded-3 h-100">
                        <i class="fa-brands fa-google text-danger fs-3 mb-2"></i>
                        <h6 class="fw-bold mb-1">2. Google Auth</h6>
                        <small class="text-muted">User login akun Google dan memilih email.</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded-3 h-100">
                        <i class="fa-solid fa-arrows-split-up-and-left text-warning fs-3 mb-2"></i>
                        <h6 class="fw-bold mb-1">3. Relay Proxy</h6>
                        <small class="text-muted">Google callback ke Relay Proxy Publik, lalu Relay meneruskan code ke app Anda.</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded-3 h-100">
                        <i class="fa-solid fa-circle-check text-success fs-3 mb-2"></i>
                        <h6 class="fw-bold mb-1">4. Verifikasi & Login</h6>
                        <small class="text-muted">Aplikasi menerima code, mengambil profil user, dan membuat session login.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- LANGKAH INTEGRASI APLIKASI -->
    <div class="card card-cred shadow-sm mb-4">
        <div class="card-header bg-white border-bottom-0 pt-3 px-4">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-code me-2 text-primary"></i>Langkah Integrasi di Aplikasi (PHP)</h5>
        </div>
        <div class="card-body px-4 pb-4">

            <!-- STEP 1 -->
            <div class="d-flex gap-3 mb-4">
                <div class="step-number">1</div>
                <div class="flex-grow-1">
                    <h6 class="fw-bold mb-1">Require Helper File `GoogleOAuthClient.php`</h6>
                    <p class="text-muted small mb-2">Sertakan file pembantu <code>GoogleOAuthClient.php</code> di aplikasi Anda (lokasi file: <code>/var/www/html/oauth-relay/GoogleOAuthClient.php</code>).</p>
                </div>
            </div>

            <!-- STEP 2 -->
            <div class="d-flex gap-3 mb-4">
                <div class="step-number">2</div>
                <div class="flex-grow-1">
                    <h6 class="fw-bold mb-1">Buat Tombol / Link Login Google (`login.php` / View)</h6>
                    <p class="text-muted small mb-2">Susun URL Login Google menggunakan method <code>getLoginUrl($returnUrl)</code>:</p>

<pre><code class="language-php">&lt;?php
require_once '/var/www/html/oauth-relay/GoogleOAuthClient.php';

// Inisialisasi OAuth Helper
// Args: (Nama Aplikasi, Client ID, Client Secret)
$oauth = new GoogleOAuthClient('E-PRAJA', '<?= $clientId ?>', '<?= $clientSecret ?>');

// URL callback lokal aplikasi Anda (Tempat menerima callback setelah login)
$returnUrl = 'http://cepad/e-praja/auth/google/callback';
$loginUrl  = $oauth->getLoginUrl($returnUrl);
?&gt;

&lt;!-- Tampilkan tombol login di View --&gt;
&lt;a href="&lt;?= htmlspecialchars($loginUrl) ?&gt;" class="btn btn-danger"&gt;
    Login dengan Google
&lt;/a&gt;</code></pre>
                </div>
            </div>

            <!-- STEP 3 -->
            <div class="d-flex gap-3 mb-4">
                <div class="step-number">3</div>
                <div class="flex-grow-1">
                    <h6 class="fw-bold mb-1">Menangani Callback & Profil User (`callback.php` / Controller)</h6>
                    <p class="text-muted small mb-2">Di endpoint callback aplikasi Anda, panggil <code>handleCallback()</code> untuk mendapatkan data user dari Google:</p>

<pre><code class="language-php">&lt;?php
require_once '/var/www/html/oauth-relay/GoogleOAuthClient.php';

$oauth = new GoogleOAuthClient('E-PRAJA', '<?= $clientId ?>', '<?= $clientSecret ?>');

try {
    // Otomatis menukar code, verifikasi CSRF, dan mengambil data profil pengguna
    $user = $oauth->handleCallback();

    if ($user) {
        $email = $user['email'];    // Contoh: ahmad@sinjaikab.go.id
        $nama  = $user['name'];     // Contoh: Ahmad Hidayat
        $foto  = $user['picture'];  // URL Foto Profil Google
        $sub   = $user['sub'];      // Google Unique User ID

        // ----------------------------------------------------
        // LOGIKA LOGIN APLIKASI ANDA:
        // Cari user di DB aplikasi berdasarkan $email / $sub
        // Buat session login aplikasi Anda
        // ----------------------------------------------------
        $_SESSION['logged_in']  = true;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name']  = $nama;

        header('Location: /e-praja/dashboard');
        exit;
    }
} catch (Exception $e) {
    die('Gagal Login Google: ' . htmlspecialchars($e->getMessage()));
}</code></pre>
                </div>
            </div>

            <!-- IMPORTANT NOTE -->
            <div class="alert alert-warning border-0 rounded-3 d-flex align-items-start gap-3 mt-3 mb-0">
                <i class="fa-solid fa-triangle-exclamation text-warning fs-4 mt-1"></i>
                <div>
                    <h6 class="fw-bold mb-1">Penting: Whitelist Host Aplikasi</h6>
                    <small>Hostname / domain tempat aplikasi Anda berjalan (contoh: <code>cepad</code>, <code>cedev</code>, <code>192.168.x.x</code>, <code>app-anda.sinjaikab.go.id</code>) harus terdaftar di whitelist relay proxy. Tambahkan hostname baru melalui halaman <a href="dashboard.php" class="fw-bold text-decoration-none">Dashboard Monitoring</a> pada tab <strong>Whitelist Host</strong>.</small>
                </div>
            </div>

        </div>
    </div>

    <!-- FOOTER -->
    <div class="text-center text-muted small py-3">
        &copy; <?= date('Y') ?> Diskominfo Kabupaten Sinjai &mdash; Universal OAuth Relay Proxy v1.0
    </div>

</div>

<script>
hljs.highlightAll();

function copyToClipboard(elementId, btn) {
    const text = document.getElementById(elementId).innerText.trim();
    navigator.clipboard.writeText(text).then(() => {
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i> Copied!';
        btn.classList.replace('btn-outline-primary', 'btn-success');
        btn.classList.add('text-white');
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.classList.replace('btn-success', 'btn-outline-primary');
            btn.classList.remove('text-white');
        }, 2000);
    });
}
</script>

</body>
</html>
