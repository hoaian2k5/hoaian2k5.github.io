<?php
/**
 * UniPortal – Admin layout partial
 * Include AFTER setting: $pageTitle, $activePage, $breadcrumb, $warnCount (int)
 * $activePage: 'dashboard','students','attendance','reports','auditlog','settings'
 */
if (!isset($pageTitle))   $pageTitle   = 'UniPortal Admin';
if (!isset($activePage))  $activePage  = '';
if (!isset($breadcrumb))  $breadcrumb  = 'Quản trị › Học vụ & Sinh viên';
if (!isset($warnCount))   $warnCount   = 0;
if (!isset($headerTitle)) $headerTitle = 'HỆ THỐNG QUẢN TRỊ ĐÀO TẠO & HỌC VỤ VNUIS';

$IMG = '../img/';
$CSS = '../css/';
$JS  = '../js/';

$adminName = $_SESSION['username'] ?? 'Quản trị viên';
$adminIni  = strtoupper(substr($adminName, 0, 2));

$nav = [
    ['dashboard',      'Tổng quan',                     'db-nav-dashboard.svg',      'dashboard.php'],
    ['students',       'Quản lý sinh viên',              'db-nav-profile.svg',        'students.php'],
    ['lecturers',      'Quản lý giảng viên',             'db-icon-schedule-user.svg', 'lecturers.php'],
    ['courses',        'Môn học & Lớp học phần',         'db-nav-grades.svg',         'courses.php'],
    ['curriculum',     'Chương trình đào tạo',           'db-icon-gpa-card.svg',      'curriculum.php'],
    ['enrollment',     'Đăng ký học phần',               'db-nav-attendance.svg',     'enrollment.php'],
    ['attendance',     'Quản lý điểm danh',              'db-nav-attendance.svg',     'attendance.php'],
    ['reports',        'Báo cáo & Thống kê',             'db-nav-grades.svg',         'reports.php'],
    ['import_export',  'Import & Export',                'db-icon-credits.svg',       'import_export.php'],
    ['auditlog',       'Nhật ký hệ thống',               'db-nav-alerts.svg',         'auditlog.php'],
    ['settings',       'Cài đặt hệ thống',               'db-icon-ai.svg',            'config.php'],
];

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= h($pageTitle) ?> – UniPortal Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="<?= $CSS ?>admin.css" />
    <?php if (!empty($extraCss)): ?>
    <link rel="stylesheet" href="<?= $CSS ?><?= h($extraCss) ?>" />
    <?php endif; ?>
</head>
<body>
<div class="adm-layout">

<!-- ═══════════ SIDEBAR ═══════════════════════════════════ -->
<aside class="adm-sidebar" id="admSidebar">
    <div class="adm-sidebar__logo">
        <img src="<?= $IMG ?>db-logo-vnuis-sidebar.png" alt="IIS" />
    </div>
    <nav class="adm-sidebar__nav">
        <?php foreach ($nav as [$id, $label, $icon, $href]): ?>
        <a href="<?= h($href) ?>"
           class="<?= $activePage === $id ? 'active' : '' ?>"
           <?= $activePage === $id ? 'aria-current="page"' : '' ?>>
            <img src="<?= $IMG ?><?= h($icon) ?>" alt="" />
            <?= h($label) ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="adm-sidebar__footer">
        <div class="adm-sidebar__online">
            <span class="adm-sidebar__dot"></span>
            <div class="adm-sidebar__status">
                <strong>Trực tuyến</strong><br/>
                Đồng bộ ĐHQGHN: <?= date('H:i') ?> Hôm nay
            </div>
        </div>
        <a href="?logout=1" class="adm-sidebar__logout">
            <img src="<?= $IMG ?>db-icon-logout.svg" alt="" />
            Đăng xuất
        </a>
    </div>
</aside>

<!-- ═══════════ HEADER ════════════════════════════════════ -->
<header class="adm-header">
    <div style="display:flex;align-items:center;gap:16px;">
        <button class="adm-hamburger" id="admHam"><span></span><span></span><span></span></button>
        <div class="adm-header__left">
            <div class="adm-header__title"><?= h($headerTitle) ?></div>
            <div class="adm-header__breadcrumb"><?= h($breadcrumb) ?></div>
        </div>
    </div>
    <div class="adm-header__right">
        <div class="adm-header__search">
            <img class="s-ico" src="<?= $IMG ?>kqht-icon-search.svg" alt="" />
            <input type="search" placeholder="Tìm kiếm sinh viên, tài khoản, học vụ…" />
        </div>
        <?php
        require_once dirname(__DIR__, 3) . '/src/src/services/NotificationService.php';
        $notifCount = NotificationService::unreadCount((int)($_SESSION['account_id'] ?? 0));
        $notifItems = NotificationService::getRecent((int)($_SESSION['account_id'] ?? 0), 5);
        $bellBadge  = max((int)($warnCount ?? 0), $notifCount);
        if (isset($_GET['mark_all_notif'])) {
            NotificationService::markAllRead((int)($_SESSION['account_id'] ?? 0));
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        ?>
        <div style="position:relative;">
        <button class="adm-header__bell" id="admBell"
                onclick="var d=document.getElementById('admNotifDrop');d.style.display=d.style.display==='none'?'block':'none';">
            <img src="<?= $IMG ?>db-icon-bell.svg" alt="" />
            <?php if ($bellBadge > 0): ?>
            <span class="hbadge"><?= min($bellBadge, 9) ?></span>
            <?php endif; ?>
        </button>
        <div id="admNotifDrop" style="display:none;position:absolute;right:0;top:44px;width:300px;background:#fff;
             border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:999;overflow:hidden;">
            <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;">
                <span style="font-weight:700;font-size:13px;">Thông báo</span>
                <?php if ($bellBadge > 0): ?>
                <a href="?mark_all_notif=1" style="font-size:11px;color:#3b82f6;text-decoration:none;">Đánh dấu đã đọc</a>
                <?php endif; ?>
            </div>
            <?php if (empty($notifItems) && !$warnCount): ?>
            <div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Không có thông báo.</div>
            <?php else: ?>
            <div style="max-height:240px;overflow-y:auto;">
                <?php foreach ($notifItems as $n): ?>
                <div style="padding:10px 14px;border-bottom:1px solid #f8fafc;background:<?= !$n['is_read']?'#f8fbff':'#fff' ?>;">
                    <div style="font-size:12px;font-weight:<?= !$n['is_read']?'700':'400' ?>;color:#001634;"><?= htmlspecialchars($n['title']) ?></div>
                    <div style="font-size:10px;color:#94a3b8;"><?= date('d/m H:i', strtotime($n['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="padding:8px;text-align:center;border-top:1px solid #f1f5f9;">
                <a href="alerts.php" style="font-size:11px;color:#3b82f6;text-decoration:none;">Xem cảnh báo học vụ →</a>
            </div>
        </div>
        </div>
        <button class="adm-header__profile">
            <div class="hav"><?= h($adminIni) ?></div>
            <div>
                <div class="hname">Quản trị viên Hệ thống</div>
                <div class="hrole">Phòng Đào tạo</div>
            </div>
            <img src="<?= $IMG ?>db-icon-caret.svg" alt="" style="width:7px;height:4px;opacity:.5;margin-left:2px;" />
        </button>
    </div>
</header>

<!-- ═══════════ MAIN CONTENT ══════════════════════════════ -->
<main class="adm-main">
<div class="adm-content">
