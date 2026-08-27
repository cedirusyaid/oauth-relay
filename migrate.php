<?php
/**
 * ============================================================
 * DATABASE MIGRATION SCRIPT
 * ============================================================
 * Jalankan script ini via CLI: php migrate.php
 * Atau via browser: http://cepad/oauth-relay/migrate.php
 * ============================================================
 */

require_once __DIR__ . '/db.php';

echo "=============================================\n";
echo "🔄 MEMULAI MIGRASI DATABASE OAUTH_DB...\n";
echo "=============================================\n";

$sqlFile = __DIR__ . '/schema.sql';
if (!file_exists($sqlFile)) {
    die("❌ Error: File schema.sql tidak ditemukan!\n");
}

$pdo = OAuthDB::getConnection();
if (!$pdo) {
    die("❌ Error: Gagal terhubung ke server database MariaDB/MySQL.\n");
}

try {
    $sql = file_get_contents($sqlFile);
    $pdo->exec($sql);
    echo "✅ Database & Tabel berhasil dimigrasi!\n";
    echo "  - Database: oauth_db\n";
    echo "  - Tabel: allowed_hosts, allowed_users, access_logs\n";
    echo "  - Seed Data: Initial Hosts & Admin (uttibatu@gmail.com)\n";
    echo "=============================================\n";
    echo "🎉 Migrasi Selesai Tanpa Error!\n";
} catch (PDOException $e) {
    echo "❌ Error Migrasi: " . $e->getMessage() . "\n";
}
