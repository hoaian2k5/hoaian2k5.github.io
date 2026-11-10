<?php
/**
 * UniPortal – Lecturer layout partial
 * Include AFTER setting: $pageTitle, $activePage, $warnCount (int)
 * $activePage values: 'dashboard','schedule','classes','materials','assignments','grading','warnings','profile'
 */
if (!isset($pageTitle))  $pageTitle  = 'UniPortal';
if (!isset($activePage)) $activePage = '';
if (!isset($warnCount))  $warnCount  = 0;

$IMG  = '../img/';           // relative to lecturer/ dir
$CSS  = '../css/';
$JS   = '../js/';

$lecName  = $_SESSION['full_name']       ?? 'Giảng viên';
$lecDept  = $_SESSION['department_name'] ?? '';
$lecCode  = $_SESSION['department_code'] ?? '';
$lecId    = $_SESSION['lecturer_id']     ?? 0;

// initials for avatar
$initials = '';
foreach (explode(' ', $lecName) as $w) $initials .= strtoupper(substr($w, 0, 1));
$initials = substr($initials, -2); // last 2 chars

$nav = [
    ['dashboard',   'Tổng quan',            'db-nav-dashboard.svg',  'dashboard.php'],
    ['schedule',    'Lịch giảng dạy',       'db-nav-schedule.svg',   'schedule.php'],
    ['classes',     'Lớp học & Sinh viên',  'db-nav-grades.svg',     'classes.php'],
    ['materials',   'Tài liệu học tập',     'db-icon-credits.svg',   'materials.php'],
    ['assignments', 'Bài tập & Bài nộp',    'db-nav-attendance.svg', 'assignments.php'],
    ['grading',     'Quản lý điểm danh',    'db-icon-attendance.svg','grading.php'],
    ['warnings',    'Cảnh báo & Can thiệp', 'db-nav-alerts.svg',     'warnings.php'],
    ['profile',     'Hồ sơ cá nhân',        'db-nav-profile.svg',    'profile.php'],
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
    <title><?= h($pageTitle) ?> – UniPortal Giảng viên</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="<?= $CSS ?>lecturer.css" />
    <?php if (!empty($extraCss)): ?>
    <link rel="stylesheet" href="<?= $CSS ?><?= h($extraCss) ?>" />
    <?php endif; ?>
</head>
<body>
<div class="lec-layout">

<!-- ═══════════════════ SIDEBAR ═══════════════════════════ -->
<aside class="lec-sidebar" id="lecSidebar">
    <div class="lec-sidebar__logo">
        <img src="<?= $IMG ?>db-logo-vnuis-sidebar.png" alt="IIS" />
    </div>

    <nav class="lec-sidebar__nav">
        <?php foreach ($nav as [$id, $label, $icon, $href]): ?>
        <a href="<?= h($href) ?>"
           class="<?= $activePage === $id ? 'active' : '' ?>"
           <?= $activePage === $id ? 'aria-current="page"' : '' ?>>
            <img src="<?= $IMG ?><?= h($icon) ?>" alt="" />
            <?= h($label) ?>
            <?php if ($id === 'warnings' && $warnCount > 0): ?>
            <span class="nav-badge"><?= min($warnCount, 9) ?><?= $warnCount > 9 ? '+' : '' ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="lec-sidebar__footer">
        <div class="lec-sidebar__system-info">
            <span class="lec-sidebar__system-dot"></span>
            <div class="lec-sidebar__system-text">
                <strong>Hệ thống UIS ĐHQGHN</strong><br/>
                Máy chủ: Đầy sáng
            </div>
        </div>
        <a href="?logout=1" class="lec-sidebar__logout">
            <img src="<?= $IMG ?>db-icon-logout.svg" alt="" />
            Đăng xuất tài khoản
        </a>
    </div>
</aside>

<!-- ═══════════════════ HEADER ════════════════════════════ -->
<header class="lec-header">
    <div class="lec-header__left">
        <button class="lec-hamburger" id="lecHamburger" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
        <img class="lec-header__logo"
             src="<?= $IMG ?>db-logo-vnuis-sidebar.png"
             alt="IIS" />
        <div class="lec-header__divider"></div>
        <div class="lec-header__breadcrumb">
            <span class="lec-header__breadcrumb-top">Cổng Giảng viên</span>
            <span class="lec-header__breadcrumb-bottom">Trường Quốc tế – ĐHQGHN</span>
        </div>
        <div class="lec-header__divider"></div>
        <div class="lec-header__search">
            <img class="s-icon" src="<?= $IMG ?>kqht-icon-search.svg" alt="" />
            <input type="search" placeholder="Tìm sinh viên, MSSV, mã lớp..." aria-label="Tìm kiếm" />
        </div>
    </div>

    <div class="lec-header__right">
        <?php
        require_once dirname(__DIR__, 3) . '/src/src/services/NotificationService.php';
        $lecNotifCount = NotificationService::unreadCount((int)($_SESSION['account_id'] ?? 0));
        $lecNotifItems = NotificationService::getRecent((int)($_SESSION['account_id'] ?? 0), 5);
        $lecBellBadge  = max($warnCount, $lecNotifCount);
        if (isset($_GET['mark_all_notif'])) {
            NotificationService::markAllRead((int)($_SESSION['account_id'] ?? 0));
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        ?>
        <div style="position:relative;">
        <button class="lec-header__bell" aria-label="Thông báo"
                onclick="var d=document.getElementById('lecNotifDrop');d.style.display=d.style.display==='none'?'block':'none';">
            <img src="<?= $IMG ?>db-icon-bell.svg" alt="" />
            <?php if ($lecBellBadge > 0): ?>
            <span class="hbadge"><?= min($lecBellBadge, 9) ?></span>
            <?php endif; ?>
        </button>
        <div id="lecNotifDrop" style="display:none;position:absolute;right:0;top:44px;width:290px;background:#fff;
             border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:999;overflow:hidden;">
            <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;">
                <span style="font-weight:700;font-size:13px;">Thông báo</span>
                <?php if ($lecBellBadge > 0): ?>
                <a href="?mark_all_notif=1" style="font-size:11px;color:#3b82f6;text-decoration:none;">Đánh dấu đã đọc</a>
                <?php endif; ?>
            </div>
            <?php if (empty($lecNotifItems)): ?>
            <div style="padding:18px;text-align:center;color:#94a3b8;font-size:12px;">Không có thông báo mới.</div>
            <?php else: ?>
            <div style="max-height:220px;overflow-y:auto;">
                <?php foreach ($lecNotifItems as $n): ?>
                <div style="padding:10px 14px;border-bottom:1px solid #f8fafc;background:<?= !$n['is_read']?'#f8fbff':'#fff' ?>;">
                    <div style="font-size:12px;font-weight:<?= !$n['is_read']?'700':'400' ?>;color:#001634;"><?= htmlspecialchars($n['title']) ?></div>
                    <div style="font-size:10px;color:#94a3b8;"><?= date('d/m H:i', strtotime($n['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <button class="lec-header__profile" aria-haspopup="true">
            <span class="hav"><?= h($initials) ?></span>
            <div>
                <div class="hname"><?= h($lecName) ?></div>
                <div class="hrole">Khoa <?= h($lecCode) ?> – Bộ môn</div>
            </div>
            <img class="hcaret" src="<?= $IMG ?>db-icon-caret.svg" alt="" />
        </button>
    </div>
</header>

<!-- ═══════════════════ MAIN ══════════════════════════════ -->
<main class="lec-main" id="lecMain">
<div class="lec-content">
