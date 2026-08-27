<?php
/**
 * ============================================================
 * UNIVERSAL GOOGLE OAUTH RELAY PROXY
 * ============================================================
 * Satu relay untuk semua aplikasi di server ini.
 *
 * URL: http://cepad/oauth-relay.php
 *      http://DOMAIN-PUBLIK/oauth-relay.php
 *
 * Alur:
 * 1. App redirect user ke Google OAuth, state berisi return_url
 * 2. Google callback ke file ini: ?code=xxx&state=xxx
 * 3. File ini decode state, validasi host, redirect ke app asal
 * ============================================================
 */

require_once __DIR__ . '/db.php';

// ── WHITELIST: Host yang diizinkan sebagai tujuan redirect (Dari Database oauth_db + Fallback)
$allowedHosts = OAuthDB::getAllowedHosts();

// ── WHITELIST: Path callback yang diizinkan (kosongkan = semua path OK)
$allowedPathPatterns = [
    '/auth/google/callback',
    '/oauth/callback',
    '/auth/callback',
    '/dashboard.php',
    '/oauth-relay/dashboard.php',
];

// ── SECURITY HEADERS ────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// ── 1. Handle error dari Google (user cancel, dll) ──────────
if (!empty($_GET['error'])) {
    $stateData = !empty($_GET['state']) ? json_decode(base64_decode($_GET['state']), true) : null;
    $returnUrl = $stateData['return_url'] ?? null;

    if ($returnUrl) {
        $sep = (strpos($returnUrl, '?') !== false) ? '&' : '?';
        header('Location: ' . $returnUrl . $sep . 'error=' . urlencode($_GET['error']));
        exit;
    }

    http_response_code(400);
    die(renderPage('⚠️ Login Dibatalkan', 'Proses login Google dibatalkan oleh pengguna.', 'warning'));
}

// ── 2. Validasi parameter wajib ─────────────────────────────
if (empty($_GET['code']) || empty($_GET['state'])) {
    http_response_code(400);
    die(renderPage(
        '🔐 OAuth Relay Proxy',
        'Endpoint ini menerima callback dari Google OAuth dan meneruskan ke aplikasi asal.<br><br>'
        . '<small style="color:#999">Jangan akses halaman ini secara langsung.</small>',
        'info'
    ));
}

// ── 3. Decode state ─────────────────────────────────────────
$stateData = json_decode(base64_decode($_GET['state']), true);

if (!$stateData || empty($stateData['return_url'])) {
    http_response_code(400);
    die(renderPage('❌ State Tidak Valid', 'Parameter state tidak dapat diproses.', 'error'));
}

$returnUrl = $stateData['return_url'];
$csrfToken = $stateData['csrf_token'] ?? '';
$appName   = $stateData['app_name']   ?? 'Unknown';

// ── 4. Validasi host tujuan ─────────────────────────────────
$parsedUrl  = parse_url($returnUrl);
$returnHost = $parsedUrl['host'] ?? '';
$returnPath = $parsedUrl['path'] ?? '';

if (!in_array($returnHost, $allowedHosts, true)) {
    http_response_code(403);
    logRelay('BLOCKED', $appName, $returnHost);
    die(renderPage(
        '🚫 Host Tidak Diizinkan',
        'Host <strong>' . htmlspecialchars($returnHost) . '</strong> tidak terdaftar di whitelist relay.',
        'error'
    ));
}

// ── 5. Validasi path callback ───────────────────────────────
if (!empty($allowedPathPatterns)) {
    $pathValid = false;
    foreach ($allowedPathPatterns as $pattern) {
        if (strpos($returnPath, $pattern) !== false) {
            $pathValid = true;
            break;
        }
    }
    if (!$pathValid) {
        http_response_code(403);
        logRelay('PATH_BLOCKED', $appName, $returnHost, $returnPath);
        die(renderPage('🚫 Path Tidak Diizinkan', 'Path <strong>' . htmlspecialchars($returnPath) . '</strong> tidak valid.', 'error'));
    }
}

// ── 6. Log & redirect ke aplikasi asal ──────────────────────
logRelay('OK', $appName, $returnHost);

$separator   = (strpos($returnUrl, '?') !== false) ? '&' : '?';
$redirectUrl = $returnUrl . $separator . http_build_query([
    'code'       => $_GET['code'],
    'csrf_token' => $csrfToken,
]);

header('Location: ' . $redirectUrl);
exit;

// ═════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═════════════════════════════════════════════════════════════

function logRelay($status, $appName, $host, $extra = '')
{
    OAuthDB::logAccess('RELAY', $status, $appName, null, null, null, $host, $extra);
}

function renderPage($title, $message, $type = 'info')
{
    $colors = [
        'error'   => ['bg' => '#fef2f2', 'border' => '#fca5a5', 'text' => '#991b1b'],
        'warning' => ['bg' => '#fffbeb', 'border' => '#fcd34d', 'text' => '#92400e'],
        'info'    => ['bg' => '#eff6ff', 'border' => '#93c5fd', 'text' => '#1e40af'],
    ];
    $c = $colors[$type] ?? $colors['info'];

    return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; background: #f8fafc; padding: 20px;
        }
        .card {
            background: #fff; border-radius: 16px; padding: 48px 40px;
            max-width: 440px; width: 100%;
            box-shadow: 0 4px 24px rgba(0,0,0,.06);
            text-align: center;
        }
        .badge {
            display: inline-block; padding: 6px 16px; border-radius: 20px;
            font-size: .8rem; font-weight: 600; margin-bottom: 20px;
            background: {$c['bg']}; color: {$c['text']}; border: 1px solid {$c['border']};
        }
        h2 { font-size: 1.3rem; color: #1e293b; margin-bottom: 12px; }
        p  { color: #64748b; font-size: .95rem; line-height: 1.6; }
        .footer { margin-top: 32px; font-size: .75rem; color: #cbd5e1; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">OAuth Relay Proxy</div>
        <h2>{$title}</h2>
        <p>{$message}</p>
        <div class="footer">Diskominfo Sinjai &mdash; OAuth Relay v1.0</div>
    </div>
</body>
</html>
HTML;
}
