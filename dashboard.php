<?php
/**
 * ============================================================
 * OAUTH RELAY DASHBOARD & MONITORING
 * ============================================================
 * Halaman Native PHP Dashboard untuk memantau statistik login,
 * mengelola whitelist host, allowed users, dan audit log.
 * ============================================================
 */

session_start();
require_once __DIR__ . '/db.php';

// ── HANDLE GOOGLE OAUTH AUTHENTICATION (ADMIN ONLY) ───────────
$authError = '';

// 1. Process Callback from Google
if (isset($_GET['code']) && file_exists(__DIR__ . '/GoogleOAuthClient.php')) {
    try {
        require_once __DIR__ . '/GoogleOAuthClient.php';
        $oauth = new GoogleOAuthClient('OAuth Dashboard Admin');
        $userData = $oauth->handleCallback();

        if ($userData && !empty($userData['email'])) {
            $email = strtolower(trim($userData['email']));
            $pdo   = OAuthDB::getConnection();
            $stmt  = $pdo->prepare("SELECT name, role FROM allowed_users WHERE email = :email AND is_active = 1");
            $stmt->execute([':email' => $email]);
            $row   = $stmt->fetch();

            if ($row && $row['role'] === 'admin') {
                $_SESSION['oauth_admin_logged_in'] = true;
                $_SESSION['admin_email'] = $email;
                $_SESSION['admin_name']  = $row['name'] ?: ($userData['name'] ?? $email);
                header('Location: dashboard.php');
                exit;
            } else {
                $authError = "Akses Ditolak: Email <strong>" . htmlspecialchars($email) . "</strong> belum terdaftar sebagai Admin di whitelist.";
            }
        }
    } catch (Exception $e) {
        $authError = "Google Login Error: " . $e->getMessage();
    }
}

// 2. Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['oauth_admin_logged_in'], $_SESSION['admin_email'], $_SESSION['admin_name']);
    header('Location: dashboard.php');
    exit;
}

$isLoggedIn = !empty($_SESSION['oauth_admin_logged_in']);

// 3. Generate Google OAuth Login Link for Admin Login Screen
$googleLoginUrl = '#';
if (!$isLoggedIn && file_exists(__DIR__ . '/GoogleOAuthClient.php')) {
    try {
        require_once __DIR__ . '/GoogleOAuthClient.php';
        $client = new GoogleOAuthClient('OAuth Dashboard Admin');
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'cepad';
        $returnUrl = $protocol . '://' . $host . '/oauth-relay/dashboard.php';
        $googleLoginUrl = $client->getLoginUrl($returnUrl);
    } catch (Exception $e) {
        $authError = "Gagal membuat URL Google Auth: " . $e->getMessage();
    }
}

// ── HANDLE ACTIONS (HOST & USER MANAGEMENT) ──────────────────
$flashMessage = '';
$flashType = 'success';

if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pdo = OAuthDB::getConnection();

    if ($action === 'add_host' && $pdo) {
        $host    = strtolower(trim($_POST['host'] ?? ''));
        $appName = trim($_POST['app_name'] ?? '');
        if ($host) {
            try {
                $stmt = $pdo->prepare("INSERT INTO allowed_hosts (host, app_name, is_active) VALUES (:host, :app_name, 1) ON DUPLICATE KEY UPDATE app_name = VALUES(app_name), is_active = 1");
                $stmt->execute([':host' => $host, ':app_name' => $appName]);
                $flashMessage = "Host <strong>{$host}</strong> berhasil ditambahkan ke whitelist!";
            } catch (Exception $e) {
                $flashMessage = "Gagal menambah host: " . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }

    if ($action === 'toggle_host' && $pdo) {
        $hostId = (int)($_POST['host_id'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);
        if ($hostId > 0) {
            $stmt = $pdo->prepare("UPDATE allowed_hosts SET is_active = :status WHERE id = :id");
            $stmt->execute([':status' => $status, ':id' => $hostId]);
            $flashMessage = "Status host telah diperbarui!";
        }
    }

    if ($action === 'delete_host' && $pdo) {
        $hostId = (int)($_POST['host_id'] ?? 0);
        if ($hostId > 0) {
            $stmt = $pdo->prepare("DELETE FROM allowed_hosts WHERE id = :id");
            $stmt->execute([':id' => $hostId]);
            $flashMessage = "Host telah dihapus dari whitelist!";
        }
    }

    if ($action === 'add_user' && $pdo) {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $name  = trim($_POST['name'] ?? '');
        $role  = trim($_POST['role'] ?? 'user');
        if ($email) {
            if (OAuthDB::registerAllowedUser($email, $name, $role)) {
                $flashMessage = "User <strong>{$email}</strong> berhasil ditambahkan ke whitelist!";
            } else {
                $flashMessage = "Gagal menambahkan user.";
                $flashType = 'danger';
            }
        }
    }

    if ($action === 'toggle_user' && $pdo) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);
        if ($userId > 0) {
            $stmt = $pdo->prepare("UPDATE allowed_users SET is_active = :status WHERE id = :id");
            $stmt->execute([':status' => $status, ':id' => $userId]);
            $flashMessage = "Status user telah diperbarui!";
        }
    }

    if ($action === 'delete_user' && $pdo) {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $pdo->prepare("DELETE FROM allowed_users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            $flashMessage = "User telah dihapus dari whitelist!";
        }
    }

    if ($action === 'change_role' && $pdo) {
        $userId  = (int)($_POST['user_id'] ?? 0);
        $newRole = trim($_POST['role'] ?? 'user');
        if ($userId > 0 && in_array($newRole, ['user', 'admin'], true)) {
            $stmt = $pdo->prepare("UPDATE allowed_users SET role = :role WHERE id = :id");
            $stmt->execute([':role' => $newRole, ':id' => $userId]);
            $flashMessage = "Role user berhasil diubah menjadi <strong>{$newRole}</strong>!";
        }
    }
}

// ── AMBIL DATA STATISTIK DARI DB ────────────────────────────
$stats = [
    'total_today'   => 0,
    'total_active_hosts' => 0,
    'total_allowed_users' => 0,
    'total_blocked' => 0,
];
$recentLogs  = [];
$allHosts    = [];
$allUsers    = [];
$appStats    = [];
$dailyStats  = [];

$pdo = OAuthDB::getConnection();
if ($pdo) {
    // Stats overview
    $stats['total_today'] = (int) $pdo->query("SELECT COUNT(*) FROM access_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $stats['total_active_hosts'] = (int) $pdo->query("SELECT COUNT(*) FROM allowed_hosts WHERE is_active = 1")->fetchColumn();
    $stats['total_allowed_users'] = (int) $pdo->query("SELECT COUNT(*) FROM allowed_users WHERE is_active = 1")->fetchColumn();
    $stats['total_blocked'] = (int) $pdo->query("SELECT COUNT(*) FROM access_logs WHERE status LIKE '%BLOCK%'")->fetchColumn();
    $stats['total_unique_users'] = (int) $pdo->query("SELECT COUNT(DISTINCT user_email) FROM access_logs WHERE user_email IS NOT NULL AND user_email != ''")->fetchColumn();

    // Hosts list
    $allHosts = $pdo->query("SELECT * FROM allowed_hosts ORDER BY id DESC")->fetchAll();

    // Users list
    $allUsers = $pdo->query("SELECT * FROM allowed_users ORDER BY id DESC")->fetchAll();

    // Statistik Email/User Identik (Grouping by User Email)
    $identikUserStats = $pdo->query("
        SELECT 
            user_email, 
            MAX(user_name) as user_name, 
            COUNT(*) as total_logins,
            GROUP_CONCAT(DISTINCT COALESCE(NULLIF(app_name, ''), 'Relay Proxy') SEPARATOR ', ') as apps,
            MAX(created_at) as last_access
        FROM access_logs 
        WHERE user_email IS NOT NULL AND user_email != ''
        GROUP BY user_email 
        ORDER BY total_logins DESC 
        LIMIT 20
    ")->fetchAll();

    // Filter Logs Search & Presence
    $searchLog    = trim($_GET['search'] ?? '');
    $statusFilter = trim($_GET['status'] ?? '');
    $userFilter   = trim($_GET['user_filter'] ?? 'with_user'); // Default: 'with_user'!

    $whereClause = "WHERE 1=1";
    $params = [];

    // Filter keberadaan email/user (Default: hanya tampilkan yang ada email/user)
    if ($userFilter === 'with_user') {
        $whereClause .= " AND (user_email IS NOT NULL AND user_email != '')";
    } elseif ($userFilter === 'without_user') {
        $whereClause .= " AND (user_email IS NULL OR user_email = '')";
    }

    if ($searchLog) {
        $whereClause .= " AND (user_email LIKE :s OR user_name LIKE :s OR app_name LIKE :s OR return_host LIKE :s)";
        $params[':s'] = "%{$searchLog}%";
    }
    if ($statusFilter) {
        $whereClause .= " AND status = :st";
        $params[':st'] = $statusFilter;
    }

    $logStmt = $pdo->prepare("SELECT * FROM access_logs {$whereClause} ORDER BY id DESC LIMIT 100");
    $logStmt->execute($params);
    $recentLogs = $logStmt->fetchAll();

    // App Usage Stat
    $appStats = $pdo->query("SELECT COALESCE(NULLIF(app_name, ''), 'Unknown') as app, COUNT(*) as total FROM access_logs GROUP BY app ORDER BY total DESC LIMIT 5")->fetchAll();

    // Daily Stats (Last 7 Days)
    $dailyStats = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%d %b') as date_label, COUNT(*) as total 
        FROM access_logs 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at) 
        ORDER BY created_at ASC
    ")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OAuth Relay Proxy Dashboard</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        .navbar-brand { font-weight: 700; letter-spacing: -0.5px; }
        .card-stat { border: none; border-radius: 12px; transition: transform 0.2s; }
        .card-stat:hover { transform: translateY(-3px); }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
        .nav-tabs .nav-link { font-weight: 600; color: #495057; border: none; border-bottom: 3px solid transparent; }
        .nav-tabs .nav-link.active { color: #0d6efd; border-bottom-color: #0d6efd; background: none; }
        .table-custom { border-radius: 10px; overflow: hidden; }
        .badge-status { font-weight: 600; font-size: 0.75rem; padding: 5px 10px; border-radius: 20px; }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fa-solid fa-shield-halved text-primary me-2"></i>OAuth Relay Dashboard
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="help.php" class="btn btn-outline-info btn-sm me-2">
                <i class="fa-solid fa-book-open me-1"></i> Panduan Integrasi
            </a>
            <?php if ($isLoggedIn): ?>
                <span class="text-light me-2 small"><i class="fa-solid fa-user-circle me-1"></i> Admin</span>
                <a href="dashboard.php?action=logout" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-right-from-bracket me-1"></i> Logout</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

<?php if (!$isLoggedIn): ?>
<!-- LOGIN SCREEN -->
<div class="row justify-content-center py-5">
    <div class="col-md-5">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body p-4 text-center">
                <div class="stat-icon bg-danger text-white mx-auto mb-3">
                    <i class="fa-brands fa-google"></i>
                </div>
                <h4 class="fw-bold mb-1">OAuth Admin Login</h4>
                <p class="text-muted small mb-4">Hanya akun Google terdaftar (Admin) yang dapat mengakses halaman ini.</p>

                <?php if ($authError): ?>
                    <div class="alert alert-danger py-2 small mb-3"><?= $authError ?></div>
                <?php endif; ?>

                <a href="<?= htmlspecialchars($googleLoginUrl) ?>" class="btn btn-danger btn-lg w-100 fw-semibold d-flex align-items-center justify-content-center gap-2 py-3 rounded-3 shadow-sm">
                    <i class="fa-brands fa-google fs-4"></i> Login dengan Google
                </a>
            </div>
        </div>
    </div>
</div>
<?php else: ?>

<!-- FLASH MESSAGE -->
<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
        <?= $flashMessage ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- STATS CARDS -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card card-stat shadow-sm bg-white p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Akses Hari Ini</div>
                    <h3 class="fw-bold mb-0 text-dark"><?= number_format($stats['total_today']) ?></h3>
                </div>
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-stat shadow-sm bg-white p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">User Identik (Unik)</div>
                    <h3 class="fw-bold mb-0" style="color: #6f42c1;"><?= number_format($stats['total_unique_users']) ?></h3>
                </div>
                <div class="stat-icon bg-opacity-10" style="background-color: rgba(111, 66, 193, 0.1); color: #6f42c1;">
                    <i class="fa-solid fa-user-check"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-stat shadow-sm bg-white p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Host Aktif</div>
                    <h3 class="fw-bold mb-0 text-success"><?= number_format($stats['total_active_hosts']) ?></h3>
                </div>
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="fa-solid fa-server"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-stat shadow-sm bg-white p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">User Whitelist</div>
                    <h3 class="fw-bold mb-0 text-info"><?= number_format($stats['total_allowed_users']) ?></h3>
                </div>
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-stat shadow-sm bg-white p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Ditolak</div>
                    <h3 class="fw-bold mb-0 text-danger"><?= number_format($stats['total_blocked']) ?></h3>
                </div>
                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                    <i class="fa-solid fa-ban"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS SECTION -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card shadow-sm border-0 rounded-3 p-3">
            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-chart-area me-2 text-primary"></i>Tren Akses Relay (7 Hari Terakhir)</h6>
            <div style="height: 220px;">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 rounded-3 p-3">
            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-pie-chart me-2 text-primary"></i>Top Aplikasi Pengakses</h6>
            <div style="height: 220px;">
                <canvas id="appChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- MAIN TABS SECTION -->
<div class="card shadow-sm border-0 rounded-3">
    <div class="card-header bg-white border-bottom-0 pt-3 pb-0 px-3">
        <ul class="nav nav-tabs" id="dashboardTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="logs-tab" data-bs-toggle="tab" data-bs-target="#logs-panel">
                    <i class="fa-solid fa-list-check me-1"></i> Audit Logs
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="identik-tab" data-bs-toggle="tab" data-bs-target="#identik-panel">
                    <i class="fa-solid fa-user-check me-1"></i> Statistik User Identik (<?= count($identikUserStats) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="hosts-tab" data-bs-toggle="tab" data-bs-target="#hosts-panel">
                    <i class="fa-solid fa-globe me-1"></i> Whitelist Host (<?= count($allHosts) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="users-tab" data-bs-toggle="tab" data-bs-target="#users-panel">
                    <i class="fa-solid fa-user-shield me-1"></i> Allowed Users (<?= count($allUsers) ?>)
                </button>
            </li>
        </ul>
    </div>

    <div class="card-body p-3">
        <div class="tab-content" id="dashboardTabContent">

            <!-- TAB 1: AUDIT LOGS -->
            <div class="tab-pane fade show active" id="logs-panel">
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari Email, Nama, Aplikasi, Host..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="user_filter" class="form-select form-select-sm">
                            <option value="with_user" <?= ($userFilter ?? 'with_user') === 'with_user' ? 'selected' : '' ?>>👤 Hanya Ada Email/User (Default)</option>
                            <option value="without_user" <?= ($userFilter ?? '') === 'without_user' ? 'selected' : '' ?>>🚫 Hanya Tanpa Email/User</option>
                            <option value="all" <?= ($userFilter ?? '') === 'all' ? 'selected' : '' ?>>🌐 Semua Audit Log</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Semua Status</option>
                            <option value="OK" <?= ($_GET['status'] ?? '') === 'OK' ? 'selected' : '' ?>>OK (Diizinkan)</option>
                            <option value="BLOCKED" <?= ($_GET['status'] ?? '') === 'BLOCKED' ? 'selected' : '' ?>>BLOCKED (Host)</option>
                            <option value="USER_BLOCKED" <?= ($_GET['status'] ?? '') === 'USER_BLOCKED' ? 'selected' : '' ?>>USER_BLOCKED (Email)</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                    </div>
                    <div class="col-md-1">
                        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle small mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>#ID</th>
                                <th>Tipe</th>
                                <th>Status</th>
                                <th>Aplikasi</th>
                                <th>User / Email</th>
                                <th>Host / Target URL</th>
                                <th>IP Address</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentLogs)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">Belum ada data audit log.</td>
                            </tr>
                            <?php else: foreach ($recentLogs as $log): ?>
                            <tr>
                                <td class="fw-bold">#<?= $log['id'] ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($log['event_type']) ?></span></td>
                                <td>
                                    <?php if ($log['status'] === 'OK'): ?>
                                        <span class="badge badge-status bg-success">OK</span>
                                    <?php elseif ($log['status'] === 'USER_BLOCKED'): ?>
                                        <span class="badge badge-status bg-warning text-dark">USER BLOCKED</span>
                                    <?php else: ?>
                                        <span class="badge badge-status bg-danger"><?= htmlspecialchars($log['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold text-primary"><?= htmlspecialchars($log['app_name'] ?: '-') ?></td>
                                <td>
                                    <?php if ($log['user_email']): ?>
                                        <div><strong><?= htmlspecialchars($log['user_name'] ?: '-') ?></strong></div>
                                        <div class="text-muted small"><?= htmlspecialchars($log['user_email']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><code><?= htmlspecialchars($log['return_host'] ?: '-') ?></code></div>
                                    <?php if ($log['return_url']): ?>
                                        <div class="text-muted small text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($log['return_url']) ?>"><?= htmlspecialchars($log['return_url']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($log['ip_address'] ?: '-') ?></code></td>
                                <td class="text-nowrap"><?= date('d M Y H:i', strtotime($log['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: STATISTIK USER IDENTIK -->
            <div class="tab-pane fade" id="identik-panel">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="fa-solid fa-chart-column me-1"></i> <strong>Statistik User Identik:</strong> Menampilkan peringkat dan rangkuman aktivitas berdasarkan email/pengguna unik yang pernah terotentikasi di sistem OAuth Relay Proxy.
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle small mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" style="width: 100px;">Ranking</th>
                                <th>Email Pengguna (Identik)</th>
                                <th>Nama Pengguna</th>
                                <th>Total Login / Akses</th>
                                <th>Aplikasi Diakses</th>
                                <th>Akses Terakhir</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($identikUserStats)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">Belum ada data statistik user identik.</td>
                            </tr>
                            <?php else: $rank = 1; foreach ($identikUserStats as $u): ?>
                            <tr>
                                <td class="fw-bold text-center">
                                    <?php if ($rank === 1): ?>
                                        <span class="badge bg-warning text-dark fs-6 px-2"><i class="fa-solid fa-trophy me-1"></i> #1</span>
                                    <?php elseif ($rank === 2): ?>
                                        <span class="badge bg-secondary text-white fs-6 px-2"><i class="fa-solid fa-medal me-1"></i> #2</span>
                                    <?php elseif ($rank === 3): ?>
                                        <span class="badge bg-danger text-white fs-6 px-2"><i class="fa-solid fa-award me-1"></i> #3</span>
                                    <?php else: ?>
                                        <span class="text-muted fw-semibold">#<?= $rank ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><strong class="text-primary fs-6"><?= htmlspecialchars($u['user_email']) ?></strong></td>
                                <td class="fw-semibold"><?= htmlspecialchars($u['user_name'] ?: '-') ?></td>
                                <td><span class="badge bg-primary fs-6 px-3 py-1"><?= number_format($u['total_logins']) ?> x</span></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($u['apps']) ?></span></td>
                                <td class="text-nowrap text-muted"><?= date('d M Y H:i', strtotime($u['last_access'])) ?></td>
                            </tr>
                            <?php $rank++; endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 3: WHITELIST HOST -->
            <div class="tab-pane fade" id="hosts-panel">
                <div class="row g-3 mb-3">
                    <div class="col-md-5">
                        <div class="card border p-3 rounded-3 bg-light">
                            <h6 class="fw-bold mb-2"><i class="fa-solid fa-plus-circle me-1 text-success"></i> Tambah Whitelist Host</h6>
                            <form method="POST">
                                <input type="hidden" name="action" value="add_host">
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Hostname / Domain / IP</label>
                                    <input type="text" name="host" class="form-control form-control-sm" placeholder="Contoh: cepad2 / sub.domain.com" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small mb-1">Nama Aplikasi (Opsional)</label>
                                    <input type="text" name="app_name" class="form-control form-control-sm" placeholder="Contoh: SIMPEG / E-PRAJA">
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100"><i class="fa-solid fa-save me-1"></i> Simpan Host</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle small mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Host</th>
                                        <th>Aplikasi</th>
                                        <th>Status</th>
                                        <th>Tanggal Dibuat</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allHosts as $h): ?>
                                    <tr>
                                        <td><code class="fw-bold fs-6"><?= htmlspecialchars($h['host']) ?></code></td>
                                        <td><?= htmlspecialchars($h['app_name'] ?: '-') ?></td>
                                        <td>
                                            <?php if ($h['is_active']): ?>
                                                <span class="badge bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Non-Aktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('d M Y', strtotime($h['created_at'])) ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="toggle_host">
                                                    <input type="hidden" name="host_id" value="<?= $h['id'] ?>">
                                                    <input type="hidden" name="status" value="<?= $h['is_active'] ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-outline-<?= $h['is_active'] ? 'warning' : 'success' ?> btn-sm py-0 px-2" title="Ubah Status">
                                                        <i class="fa-solid fa-power-off"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('Hapus host ini dari whitelist?')">
                                                    <input type="hidden" name="action" value="delete_host">
                                                    <input type="hidden" name="host_id" value="<?= $h['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Hapus Host">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: ALLOWED USERS -->
            <div class="tab-pane fade" id="users-panel">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="fa-solid fa-info-circle me-1"></i> <strong>Catatan Whitelist Pengguna:</strong> Jika tabel ini kosong, semua akun Google yang berhasil terautentikasi dapat melakukan login. Jika diisi, hanya email yang terdaftar & aktif yang diizinkan.
                </div>

                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="card border p-3 rounded-3 bg-light">
                            <h6 class="fw-bold mb-2"><i class="fa-solid fa-user-plus me-1 text-primary"></i> Tambah Whitelist User</h6>
                            <form method="POST">
                                <input type="hidden" name="action" value="add_user">
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Email Google</label>
                                    <input type="email" name="email" class="form-control form-control-sm" placeholder="user@sinjaikab.go.id" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Nama Lengkap (Opsional)</label>
                                    <input type="text" name="name" class="form-control form-control-sm" placeholder="Contoh: Ahmad Hidayat">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small mb-1">Role</label>
                                    <select name="role" class="form-select form-select-sm">
                                        <option value="user">User biasa</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-save me-1"></i> Simpan User</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle small mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Email User</th>
                                        <th>Nama</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($allUsers)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Belum ada user yang didaftarkan. (Semua email diizinkan).</td>
                                    </tr>
                                    <?php else: foreach ($allUsers as $u): ?>
                                    <tr>
                                        <td><strong class="text-primary"><?= htmlspecialchars($u['email']) ?></strong></td>
                                        <td><?= htmlspecialchars($u['name'] ?: '-') ?></td>
                                        <td>
                                            <form method="POST" class="d-inline-block">
                                                <input type="hidden" name="action" value="change_role">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <select name="role" class="form-select form-select-sm py-0 px-2 fw-semibold <?= $u['role'] === 'admin' ? 'bg-danger text-white border-danger' : 'bg-light text-dark' ?>" onchange="this.form.submit()">
                                                    <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>user</option>
                                                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                                </select>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if ($u['is_active']): ?>
                                                <span class="badge bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Non-Aktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="toggle_user">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <input type="hidden" name="status" value="<?= $u['is_active'] ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-outline-<?= $u['is_active'] ? 'warning' : 'success' ?> btn-sm py-0 px-2" title="Ubah Status">
                                                        <i class="fa-solid fa-power-off"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('Hapus user ini?')">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Hapus User">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php endif; ?>

</div>

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($isLoggedIn): ?>
<script>
// Chart 1: Daily Access Trend
const dailyLabels = <?= json_encode(array_column($dailyStats, 'date_label')) ?>;
const dailyData   = <?= json_encode(array_column($dailyStats, 'total')) ?>;

new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: dailyLabels.length ? dailyLabels : ['Hari Ini'],
        datasets: [{
            label: 'Total Akses',
            data: dailyData.length ? dailyData : [<?= $stats['total_today'] ?>],
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
            fill: true,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});

// Chart 2: App Usage Pie Chart
const appLabels = <?= json_encode(array_column($appStats, 'app')) ?>;
const appData   = <?= json_encode(array_column($appStats, 'total')) ?>;

new Chart(document.getElementById('appChart'), {
    type: 'doughnut',
    data: {
        labels: appLabels.length ? appLabels : ['Tidak ada data'],
        datasets: [{
            data: appData.length ? appData : [1],
            backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#0dcaf0']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>
<?php endif; ?>

</body>
</html>
