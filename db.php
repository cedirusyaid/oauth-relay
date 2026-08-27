<?php
/**
 * ============================================================
 * OAUTH DATABASE HELPER
 * ============================================================
 * Mengelola koneksi database oauth_db untuk whitelist host,
 * whitelist user, dan pencatatan log akses user/relay.
 * ============================================================
 */

class OAuthDB
{
    private static ?PDO $pdo = null;

    private static string $host   = '127.0.0.1';
    private static string $dbname = 'oauth_db';
    private static string $user   = 'enikda1';
    private static string $pass   = 'enikda123';

    private static array $defaultHosts = [
        'localhost',
        '127.0.0.1',
        'cepad',
        'cepad.tailb17b07.ts.net',
        'cedev',
        '100.122.111.21',
        'apps.sinjaikab.go.id',
        'e-praja.sinjaikab.go.id',
        'enikda.sinjaikab.go.id',
        'e-pad.sinjaikab.go.id',
    ];

    /**
     * Dapatkan koneksi PDO ke database oauth_db
     */
    public static function getConnection(): ?PDO
    {
        if (self::$pdo === null) {
            try {
                self::$pdo = new PDO(
                    "mysql:host=" . self::$host . ";dbname=" . self::$dbname . ";charset=utf8mb4",
                    self::$user,
                    self::$pass,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_TIMEOUT            => 3,
                    ]
                );
            } catch (PDOException $e) {
                error_log("OAuthDB Connection Error: " . $e->getMessage());
                self::$pdo = null;
            }
        }
        return self::$pdo;
    }

    /**
     * Dapatkan daftar host yang diizinkan (Database + Fallback Default)
     */
    public static function getAllowedHosts(): array
    {
        $pdo = self::getConnection();
        if (!$pdo) {
            return self::$defaultHosts;
        }

        try {
            $stmt  = $pdo->query("SELECT host FROM allowed_hosts WHERE is_active = 1");
            $hosts = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($hosts)) {
                return array_values(array_unique(array_merge(self::$defaultHosts, $hosts)));
            }
        } catch (PDOException $e) {
            error_log("OAuthDB getAllowedHosts Error: " . $e->getMessage());
        }

        return self::$defaultHosts;
    }

    /**
     * Memeriksa apakah email pengguna terdaftar di whitelist (allowed_users)
     * Jika tabel allowed_users kosong (belum ada entri aktif), semua email Google diizinkan.
     */
    public static function isUserAllowed(string $email): bool
    {
        $pdo = self::getConnection();
        if (!$pdo) {
            return true; // Fallback jika DB bermasalah
        }

        try {
            $countStmt    = $pdo->query("SELECT COUNT(*) FROM allowed_users WHERE is_active = 1");
            $totalAllowed = (int) $countStmt->fetchColumn();

            // Jika belum ada aturan pembatasan user, izinkan semua pengguna terotentikasi Google
            if ($totalAllowed === 0) {
                return true;
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_users WHERE email = :email AND is_active = 1");
            $stmt->execute([':email' => strtolower(trim($email))]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (PDOException $e) {
            error_log("OAuthDB isUserAllowed Error: " . $e->getMessage());
            return true;
        }
    }

    /**
     * Tambahkan user baru ke allowed_users whitelist
     */
    public static function registerAllowedUser(string $email, ?string $name = null, string $role = 'user'): bool
    {
        $pdo = self::getConnection();
        if (!$pdo) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO allowed_users (email, name, role, is_active)
                VALUES (:email, :name, :role, 1)
                ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role), is_active = 1
            ");
            return $stmt->execute([
                ':email' => strtolower(trim($email)),
                ':name'  => $name,
                ':role'  => $role,
            ]);
        } catch (PDOException $e) {
            error_log("OAuthDB registerAllowedUser Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Catat log aktivitas ke database & file log fallback
     */
    public static function logAccess(
        string $eventType,
        string $status,
        ?string $appName = null,
        ?string $userEmail = null,
        ?string $userName = null,
        ?string $googleSub = null,
        ?string $returnHost = null,
        ?string $returnUrl = null
    ): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '-';

        // 1. Simpan ke database oauth_db.access_logs
        $pdo = self::getConnection();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO access_logs 
                    (event_type, status, app_name, user_email, user_name, google_sub, return_host, return_url, ip_address, user_agent)
                    VALUES (:event_type, :status, :app_name, :user_email, :user_name, :google_sub, :return_host, :return_url, :ip, :ua)
                ");
                $stmt->execute([
                    ':event_type'  => $eventType,
                    ':status'      => $status,
                    ':app_name'    => $appName,
                    ':user_email'  => $userEmail,
                    ':user_name'   => $userName,
                    ':google_sub'  => $googleSub,
                    ':return_host' => $returnHost,
                    ':return_url'  => $returnUrl,
                    ':ip'          => $ip,
                    ':ua'          => substr($ua, 0, 500),
                ]);
            } catch (PDOException $e) {
                error_log("OAuthDB logAccess Error: " . $e->getMessage());
            }
        }

        // 2. Simpan ke file log teks (fallback)
        $logDir = __DIR__ . '/writable/logs/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . 'oauth-relay-' . date('Y-m') . '.log';
        $line    = sprintf(
            "[%s] [%s] %s | app=%s | host=%s | user=%s (%s) | ip=%s\n",
            date('Y-m-d H:i:s'),
            $eventType,
            $status,
            $appName ?? '-',
            $returnHost ?? '-',
            $userEmail ?? '-',
            $userName ?? '-',
            $ip
        );
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
