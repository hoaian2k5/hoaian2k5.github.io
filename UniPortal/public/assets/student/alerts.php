<?php
/**
 * UniPortal – Cảnh báo học tập (Academic Alerts)
 * Figma frame: warning (3:2296)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$student_id = $_SESSION['student_id'] ?? '';
$full_name  = $_SESSION['full_name']  ?? '';

// Handle mark-as-read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'], $_POST['warning_id'])) {
    csrf_verify();
    $wid = (int)$_POST['warning_id'];
    $pdo = get_db();
    $stmt = $pdo->prepare('UPDATE AcademicWarning SET is_read = TRUE WHERE warning_id = :wid AND student_id = :sid');
    $stmt->execute([':wid' => $wid, ':sid' => $student_id]);
    header('Location: alerts.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    csrf_verify();
    $pdo = get_db();
    $pdo->prepare('UPDATE AcademicWarning SET is_read = TRUE WHERE student_id = :sid')
        ->execute([':sid' => $student_id]);
    header('Location: alerts.php');
    exit;
}

$pdo = get_db();

// All warnings for this student
$stmt = $pdo->prepare(
    'SELECT warning_id, warning_type, message, created_at, is_read, status
     FROM AcademicWarning
     WHERE student_id = :sid
     ORDER BY is_read ASC, created_at DESC'
);
$stmt->execute([':sid' => $student_id]);
$warnings = $stmt->fetchAll();

$unread = array_filter($warnings, fn($w) => !$w['is_read'] && $w['status'] === 'Active');

function h($v):string{ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$typeLabels = [
    'Attendance'     => 'Chuyên cần',
    'LowGrade'       => 'Điểm thấp',
    'LowGPA'         => 'GPA thấp',
    'GraduationRisk' => 'Nguy cơ tốt nghiệp',
];

$typeIcon  = [
    'Attendance'     => 'db-icon-attendance.svg',
    'LowGrade'       => 'db-icon-alert-warn.svg',
    'LowGPA'         => 'db-icon-alert-warn.svg',
    'GraduationRisk' => 'db-icon-alert-warn.svg',
];

$typeCardClass = [
    'Attendance'     => 'warning-card--warn',
    'LowGrade'       => 'warning-card--critical',
    'LowGPA'         => 'warning-card--critical',
    'GraduationRisk' => 'warning-card--critical',
];

// Active filter
$filter = $_GET['filter'] ?? 'all';
$filteredWarnings = match($filter) {
    'unread'   => array_values(array_filter($warnings, fn($w) => !$w['is_read'])),
    'active'   => array_values(array_filter($warnings, fn($w) => $w['status'] === 'Active')),
    'resolved' => array_values(array_filter($warnings, fn($w) => $w['status'] === 'Resolved')),
    default    => $warnings,
};

$pageTitle  = 'Cảnh báo học tập';
$activePage = 'alerts';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = count(array_filter($warnings, fn($w) => !$w['is_read'] && $w['status'] === 'Active'));
require_once __DIR__ . '/../partials/layout.php';
?>

<!-- Page banner -->
<?php page_banner(
    'Cảnh báo học tập',
    'Theo dõi và xử lý các cảnh báo học vụ từ hệ thống',
    'db-icon-alert-warn.svg', $imgBase
); ?>

<!-- Summary stat row -->
<div class="stat-row">
    <?php
    $byType = array_count_values(array_column(
        array_filter($warnings, fn($w) => $w['status'] === 'Active'), 'warning_type'
    ));
    ?>
    <div class="stat-mini">
        <div class="stat-mini__label">Chưa đọc</div>
        <div class="stat-mini__value" style="color:<?= count($unread) > 0 ? '#ef4444' : '#059669' ?>;">
            <?= count($unread) ?>
        </div>
        <div class="stat-mini__sub">cảnh báo mới</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Đang hoạt động</div>
        <div class="stat-mini__value"><?= count(array_filter($warnings, fn($w)=>$w['status']==='Active')) ?></div>
        <div class="stat-mini__sub">cảnh báo</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Đã giải quyết</div>
        <div class="stat-mini__value" style="color:#059669;">
            <?= count(array_filter($warnings, fn($w)=>$w['status']==='Resolved')) ?>
        </div>
        <div class="stat-mini__sub">cảnh báo</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Tổng cộng</div>
        <div class="stat-mini__value"><?= count($warnings) ?></div>
        <div class="stat-mini__sub">cảnh báo</div>
    </div>
</div>

<!-- Filter + Mark all read -->
<div class="filter-ribbon">
    <div style="display:flex;gap:4px;">
        <?php foreach (['all'=>'Tất cả','unread'=>'Chưa đọc','active'=>'Đang hoạt động','resolved'=>'Đã giải quyết'] as $k=>$v): ?>
        <a href="?filter=<?= $k ?>"
           style="padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;
                  background:<?= $filter===$k ? '#102b50' : '#f1f5f9' ?>;
                  color:<?= $filter===$k ? '#fff' : '#44474e' ?>;">
            <?= $v ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php if (count($unread) > 0): ?>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="mark_all_read" value="1" />
        <button type="submit" class="btn-secondary">Đánh dấu tất cả đã đọc</button>
    </form>
    <?php endif; ?>
</div>

<!-- Warnings list -->
<?php if (empty($filteredWarnings)): ?>
<div class="card">
    <div class="empty-state">
        <div class="empty-state__icon">✅</div>
        <p>
            <?= $filter === 'unread' ? 'Không có cảnh báo chưa đọc.' :
                ($filter === 'resolved' ? 'Không có cảnh báo đã giải quyết.' : 'Không có cảnh báo học tập nào. Tiếp tục phát huy!') ?>
        </p>
    </div>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:12px;">
    <?php foreach ($filteredWarnings as $w): ?>
    <?php
        $cardCls = $w['status'] === 'Resolved'
            ? 'warning-card--ok'
            : ($typeCardClass[$w['warning_type']] ?? 'warning-card--info');
        $icon    = $typeIcon[$w['warning_type']] ?? 'db-icon-alert-info.svg';
        $label   = $typeLabels[$w['warning_type']] ?? $w['warning_type'];
    ?>
    <div class="card warning-card <?= $cardCls ?>"
         style="<?= !$w['is_read'] ? 'border-left-width:5px;' : 'opacity:.85;' ?>">
        <img class="warning-card__icon" src="<?= $imgBase ?><?= h($icon) ?>" alt="<?= h($label) ?>" />
        <div class="warning-card__body" style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                <h3 style="margin:0;"><?= h($label) ?></h3>
                <?php if (!$w['is_read']): ?>
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;" title="Chưa đọc"></span>
                <?php endif; ?>
                <span class="status-pill status-pill--<?= $w['status'] === 'Resolved' ? 'complete' : 'absent' ?>"
                      style="font-size:10px;">
                    <?= $w['status'] === 'Resolved' ? 'Đã giải quyết' : 'Đang hoạt động' ?>
                </span>
            </div>
            <p><?= h($w['message']) ?></p>
            <div style="margin-top:6px;font-size:11px;color:#94a3b8;">
                <?= date('d/m/Y H:i', strtotime($w['created_at'])) ?>
            </div>
        </div>
        <?php if (!$w['is_read'] && $w['status'] === 'Active'): ?>
        <div>
            <form method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="mark_read" value="1" />
                <input type="hidden" name="warning_id" value="<?= (int)$w['warning_id'] ?>" />
                <button type="submit" class="btn-secondary" style="font-size:11px;padding:5px 10px;">Đánh dấu đã đọc</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
