<?php
/**
 * UniPortal – Shared layout partial
 * Usage: require_once __DIR__ . '/../partials/layout.php';
 * Before including, define: $pageTitle, $activePage, $imgBase, $assetBase
 * After including, close </div></main></div></body></html> with layout_end()
 *
 * $activePage values: 'dashboard','gpa','attendance','gpa-forecast','alerts','schedule','profile'
 */

// Ensure required vars have defaults
$pageTitle  = $pageTitle  ?? 'UniPortal';
$activePage = $activePage ?? '';
$imgBase    = $imgBase    ?? '../img/';     // relative to the page file
$assetBase  = $assetBase  ?? '../';        // relative to the page file

$studentName = $_SESSION['full_name']  ?? 'Sinh viên';
$studentId   = $_SESSION['student_id'] ?? '';
$warningCnt  = 0; // pages that need this can override before including

// Nav items: [id, label, icon-file, href]
$navItems = [
    ['dashboard',     'Tổng quan',                    'db-nav-dashboard.svg',   'dashboard.php'],
    ['gpa',           'Kết quả học tập',              'db-nav-grades.svg',      'gpa.php'],
    ['attendance',    'Điểm danh - Chuyên cần',       'db-nav-attendance.svg',  'attendance.php'],
    ['registration',  'Đăng ký môn học',              'db-nav-grades.svg',      'registration.php'],
    ['assignments',   'Bài tập & Nộp bài',            'db-nav-attendance.svg',  'assignments.php'],
    ['tuition',       'Học phí & Thanh toán',         'db-icon-credits.svg',    'tuition.php'],
    ['gpa-forecast',  'Kế hoạch & Dự báo GPA',        'db-nav-gpa-plan.svg',    'gpa_forecast.php'],
    ['alerts',        'Cảnh báo học tập',             'db-nav-alerts.svg',      'alerts.php'],
    ['schedule',      'Thời khóa biểu',               'db-nav-schedule.svg',    'transcript.php'],
    ['profile',       'Hồ sơ cá nhân',                'db-nav-profile.svg',     'profile.php'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> – UniPortal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="<?= $assetBase ?>css/dashboard.css" />
    <?php if (!empty($extraCss)): ?>
    <link rel="stylesheet" href="<?= $assetBase ?>css/<?= htmlspecialchars($extraCss) ?>" />
    <?php endif; ?>
</head>
<body>
<div class="db-layout">

<!-- SIDEBAR -->
<aside class="db-sidebar" id="sidebar" aria-label="Navigation">
    <div>
        <div class="db-sidebar__logo">
            <img src="<?= $imgBase ?>db-logo-vnuis-sidebar.png" alt="VNU-IS" />
        </div>
        <nav class="db-sidebar__nav" aria-label="Student menu">
            <?php foreach ($navItems as [$id, $label, $icon, $href]): ?>
            <a href="<?= $href ?>"
               class="<?= $activePage === $id ? 'active' : '' ?>"
               <?= $activePage === $id ? 'aria-current="page"' : '' ?>>
                <img src="<?= $imgBase ?><?= $icon ?>" alt="" />
                <?= htmlspecialchars($label) ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="db-sidebar__footer">
        <div class="db-sidebar__profile-card">
            <div class="profile-info">
                <img class="avatar" src="<?= $imgBase ?>db-profile.png"
                     alt="<?= htmlspecialchars($studentName) ?>" />
                <div>
                    <div class="name"><?= htmlspecialchars($studentName) ?></div>
                    <div class="student-id">MSSV: <?= htmlspecialchars($studentId) ?></div>
                </div>
            </div>
            <a href="dashboard.php?logout=1" class="logout-btn" title="Đăng xuất" aria-label="Đăng xuất">
                <img src="<?= $imgBase ?>db-icon-logout.svg" alt="Đăng xuất" />
            </a>
        </div>
    </div>
</aside>

<!-- HEADER -->
<header class="db-header" role="banner">
    <div style="display:flex;align-items:center;gap:12px;">
        <button class="db-hamburger" id="hamburger" aria-label="Mở menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <div class="db-header__search">
            <img class="search-icon" src="<?= $imgBase ?>db-icon-wave.svg" alt="" aria-hidden="true" />
            <input type="search" placeholder="Tìm kiếm môn học, thông báo..." aria-label="Tìm kiếm" />
        </div>
    </div>
    <div class="db-header__right">
        <?php
        // Notification bell with real unread count
        $notifCount = 0;
        $notifItems = [];
        if (!empty($_SESSION['account_id'])) {
            require_once $GLOBALS['_root'] ?? ($root ?? dirname(__DIR__, 3)) . '/src/src/services/NotificationService.php';
            $notifCount = NotificationService::unreadCount((int)$_SESSION['account_id']);
            $notifItems = NotificationService::getRecent((int)$_SESSION['account_id'], 5);
        }
        $bellCount = max($warningCnt, $notifCount);
        ?>
        <div style="position:relative;" id="notifDropContainer">
        <button class="db-header__bell" id="notifBell" aria-label="Thông báo"
                onclick="document.getElementById('notifDrop').classList.toggle('show')">
            <img src="<?= $imgBase ?>db-icon-bell.svg" alt="" />
            <?php if ($bellCount > 0): ?>
            <span class="badge" aria-hidden="true"><?= min($bellCount, 9) ?><?= $bellCount > 9 ? '+' : '' ?></span>
            <?php endif; ?>
        </button>
        <!-- Notification dropdown -->
        <div id="notifDrop" style="display:none;position:absolute;right:0;top:44px;width:320px;background:#fff;
             border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:500;overflow:hidden;">
            <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-weight:700;font-size:14px;color:#001634;">Thông báo</span>
                <?php if ($bellCount > 0): ?>
                <a href="?mark_all_notif=1" style="font-size:12px;color:#3b82f6;text-decoration:none;">Đánh dấu tất cả đã đọc</a>
                <?php endif; ?>
            </div>
            <?php if (empty($notifItems) && $warningCnt === 0): ?>
            <div style="padding:24px;text-align:center;color:#94a3b8;font-size:13px;">Không có thông báo mới.</div>
            <?php else: ?>
            <div style="max-height:280px;overflow-y:auto;">
                <?php foreach ($notifItems as $n):
                    $typeIco = ['Warning'=>'⚠️','Assignment'=>'📋','Grade'=>'📊','Attendance'=>'📅','Registration'=>'📝','Payment'=>'💰','System'=>'🔔'][$n['type']] ?? '🔔';
                ?>
                <div style="padding:12px 16px;border-bottom:1px solid #f8fafc;background:<?= !$n['is_read'] ? '#f8fbff' : '#fff' ?>;">
                    <div style="font-size:13px;font-weight:<?= !$n['is_read'] ? '700' : '400' ?>;color:#001634;">
                        <?= $typeIco ?> <?= htmlspecialchars($n['title']) ?>
                    </div>
                    <?php if ($n['body']): ?>
                    <div style="font-size:11px;color:#64748b;margin-top:3px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
                        <?= htmlspecialchars(mb_strimwidth($n['body'], 0, 60, '…')) ?>
                    </div>
                    <?php endif; ?>
                    <div style="font-size:10px;color:#94a3b8;margin-top:3px;"><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="padding:10px 16px;text-align:center;border-top:1px solid #f1f5f9;">
                <a href="alerts.php" style="font-size:12px;color:#3b82f6;text-decoration:none;">Xem tất cả cảnh báo →</a>
            </div>
        </div>
        </div>
        <button class="db-header__profile" id="profileToggle">
            <img class="avatar" src="<?= $imgBase ?>db-profile.png" alt="<?= htmlspecialchars($studentName) ?>" />
            <div>
                <div class="name"><?= htmlspecialchars($studentName) ?></div>
                <div class="student-id">MSSV: <?= htmlspecialchars($studentId) ?></div>
            </div>
            <img class="caret" src="<?= $imgBase ?>db-icon-caret.svg" alt="" aria-hidden="true" />
        </button>
    </div>
</header>
<?php
// Handle mark-all-notifications-read
if (isset($_GET['mark_all_notif']) && !empty($_SESSION['account_id'])) {
    require_once ($root ?? dirname(__DIR__, 3)) . '/src/src/services/NotificationService.php';
    NotificationService::markAllRead((int)$_SESSION['account_id']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
?>

<!-- MAIN CONTENT WRAPPER -->
<main class="db-main" id="main-content">
<div class="db-content">
<?php
// Helper to write shared page-title banner used on inner pages
function page_banner(string $title, string $subtitle, string $iconFile, string $imgBase, array $actions = []): void {
    ?>
    <div class="card page-banner" style="padding:24px;display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                <div style="width:28px;height:28px;background:#e5eeff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <img src="<?= $imgBase ?><?= htmlspecialchars($iconFile) ?>" style="width:17px;height:17px;" alt="" />
                </div>
                <h1 style="font-size:24px;font-weight:700;color:#001634;letter-spacing:-.5px;"><?= htmlspecialchars($title) ?></h1>
            </div>
            <p style="font-size:14px;color:#44474e;"><?= htmlspecialchars($subtitle) ?></p>
        </div>
        <?php if ($actions): ?>
        <div style="display:flex;gap:8px;align-items:center;">
            <?php foreach ($actions as $a): ?>
            <a href="<?= htmlspecialchars($a['href'] ?? '#') ?>"
               class="<?= htmlspecialchars($a['class'] ?? 'btn-secondary') ?>"
               style="display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;<?= $a['style'] ?? '' ?>">
                <?php if (!empty($a['icon'])): ?>
                <img src="<?= $imgBase ?><?= htmlspecialchars($a['icon']) ?>" style="width:14px;height:14px;" alt="" />
                <?php endif; ?>
                <?= htmlspecialchars($a['label']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
