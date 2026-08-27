<?php
/**
 * ============================================================
 * GOOGLE OAUTH CLIENT HELPER
 * ============================================================
 * Class pembantu untuk memudahkan integrasi Google OAuth Relay
 * pada aplikasi PHP (E-PRAJA, SIPARDI, SIMPEG, dll).
 * 
 * Penggunaan Singkat:
 * 
 * 1. Inisialisasi Kredensial:
 *    require_once 'GoogleOAuthClient.php';
 *    $oauth = new GoogleOAuthClient('NamaAplikasi', $clientId, $clientSecret);
 * 
 * 2. Generate Link Login:
 *    $loginUrl = $oauth->getLoginUrl('http://cepad/e-praja/auth/google/callback');
 * 
 * 3. Handle Callback:
 *    $userData = $oauth->handleCallback();
 *    // $userData berisi: email, name, picture, sub, dll.
 * ============================================================
 */

class GoogleOAuthClient
{
    private string $clientId;
    private string $clientSecret;
    private string $relayUrl;
    private string $appName;

    /**
     * Constructor
     * 
     * @param string $appName Nama aplikasi (untuk identifikasi & log)
     * @param string|null $clientId Client ID Google OAuth
     * @param string|null $clientSecret Client Secret Google OAuth
     * @param string|null $relayUrl Opsional, default URL relay publik
     */
    public function __construct(
        string $appName = 'Aplikasi',
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $relayUrl = null
    ) {
        if (file_exists(__DIR__ . '/config.php')) {
            require_once __DIR__ . '/config.php';
        }

        $this->appName      = $appName;
        $this->clientId     = $clientId     ?? (defined('GOOGLE_CLIENT_ID')     ? GOOGLE_CLIENT_ID     : (getenv('GOOGLE_CLIENT_ID')     ?: ''));
        $this->clientSecret = $clientSecret ?? (defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : (getenv('GOOGLE_CLIENT_SECRET') ?: ''));
        $this->relayUrl     = $relayUrl     ?? (defined('GOOGLE_RELAY_URL')     ? GOOGLE_RELAY_URL     : (getenv('GOOGLE_RELAY_URL')     ?: 'https://apps.sinjaikab.go.id/oauth-relay/'));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Dapatkan URL Login Google yang mengarahkan ke OAuth Relay Proxy
     * 
     * @param string $returnUrl Callback URL lokal aplikasi asal (misal: http://cepad/e-praja/auth/google/callback)
     * @return string URL Google Auth siap pakai
     */
    public function getLoginUrl(string $returnUrl): string
    {
        if (empty($this->clientId)) {
            throw new Exception('Client ID Google belum dikonfigurasi.');
        }

        // Generate CSRF token & simpan di session
        $csrfToken = bin2hex(random_bytes(16));
        $_SESSION['oauth_csrf_token'] = $csrfToken;

        // Susun payload state (Base64-encoded JSON)
        $stateData = [
            'return_url' => $returnUrl,
            'app_name'   => $this->appName,
            'csrf_token' => $csrfToken,
        ];

        $state = base64_encode(json_encode($stateData));

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->relayUrl,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Menangani callback yang diteruskan dari OAuth Relay Proxy
     * 
     * @return array|null Array data pengguna Google (email, name, picture, sub) atau null jika tidak ada code
     * @throws Exception Jika validasi CSRF gagal atau terjadi error saat komunikasi dengan Google
     */
    public function handleCallback(): ?array
    {
        $code      = $_GET['code'] ?? null;
        $csrfToken = $_GET['csrf_token'] ?? null;

        if (!$code) {
            return null;
        }

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new Exception('Client ID atau Client Secret Google belum dikonfigurasi.');
        }

        // Validasi CSRF Token jika tersimpan di session
        $storedCsrf = $_SESSION['oauth_csrf_token'] ?? null;
        if ($csrfToken && $storedCsrf && !hash_equals($storedCsrf, $csrfToken)) {
            throw new Exception('CSRF token validation failed.');
        }

        // Hapus token CSRF setelah diverifikasi
        unset($_SESSION['oauth_csrf_token']);

        // 1. Tukar authorization code ke Access Token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'code'          => $code,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri'  => $this->relayUrl,
                'grant_type'    => 'authorization_code',
            ]),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception('Curl Error: ' . $curlErr);
        }

        if ($httpCode !== 200) {
            throw new Exception('Google Token Exchange Error (' . $httpCode . '): ' . $response);
        }

        $tokenData   = json_decode($response, true);
        $accessToken = $tokenData['access_token'] ?? null;

        if (!$accessToken) {
            throw new Exception('Access Token tidak ditemukan dalam respon Google.');
        }

        // 2. Ambil data Profil Pengguna
        $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $userResponse = curl_exec($ch);
        $userHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $userCurlErr  = curl_error($ch);
        curl_close($ch);

        if ($userCurlErr) {
            throw new Exception('Curl Error UserInfo: ' . $userCurlErr);
        }

        if (file_exists(__DIR__ . '/db.php')) {
            require_once __DIR__ . '/db.php';
        }

        $userData = json_decode($userResponse, true);

        if (class_exists('OAuthDB') && is_array($userData)) {
            $email = $userData['email'] ?? null;
            $name  = $userData['name']  ?? null;
            $sub   = $userData['sub']   ?? null;
            $host  = $_SERVER['HTTP_HOST'] ?? null;
            $uri   = $_SERVER['REQUEST_URI'] ?? null;

            if ($email && !OAuthDB::isUserAllowed($email)) {
                OAuthDB::logAccess('LOGIN', 'USER_BLOCKED', $this->appName, $email, $name, $sub, $host, $uri);
                throw new Exception('Akses Ditolak: Email (' . htmlspecialchars($email) . ') tidak terdaftar di whitelist allowed_users.');
            }

            OAuthDB::logAccess('LOGIN', 'OK', $this->appName, $email, $name, $sub, $host, $uri);
        }

        return $userData;
    }
}
