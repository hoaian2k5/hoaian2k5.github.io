<?php
/**
 * UniPortal – Nhật ký Hệ thống & Giám sát Tác vụ (Audit Log)
 * Reads real AuditLog rows with filtering, search, and pagination.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: /UniPortal/public/assets/lecturer/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();

// ── Stats ─────────────────────────────────────────────────────
$totalSV    = (int)$pdo->query("SELECT COUNT(*) FROM Student WHERE status='Active'")->fetchColumn();
$activeWarn = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active'")->fetchColumn();

// Check if AuditLog table exists (created by migration v2)
$auditExists = false;
try {
    $pdo->query("SELECT 1 FROM AuditLog LIMIT 1");
    $auditExists = true;
} catch (Throwable) {}

// ── Filter ────────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$actionF = trim($_GET['action_f'] ?? '');
$dateF   = trim($_GET['date']   ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$totalLogs = 0;
$logs      = [];

if ($auditExists) {
    $where  = "WHERE l.log_id > 0";
    $params = [];
    if ($search !== '') {
        $where .= " AND (l.action LIKE :q OR l.target_entity LIKE :q OR l.target_id LIKE :q OR a.username LIKE :q)";
        $params[':q'] = '%'.$search.'%';
    }
    if ($actionF !== '') {
        $where .= " AND l.action = :af";
        $params[':af'] = $actionF;
    }
    if ($dateF !== '') {
        $where .= " AND DATE(l.created_at) = :dt";
        $params[':dt'] = $dateF;
    }

    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM AuditLog l LEFT JOIN Account a ON a.account_id=l.actor_id $where");
    $cntSt->execute($params);
    $totalLogs = (int)$cntSt->fetchColumn();

    $logSt = $pdo->prepare(
        "SELECT l.log_id, l.actor_id, l.action, l.target_entity, l.target_id,
                l.old_value_json, l.new_value_json, l.ip_address, l.created_at,
                a.username, a.role
         FROM AuditLog l
         LEFT JOIN Account a ON a.account_id = l.actor_id
         $where
         ORDER BY l.created_at DESC
         LIMIT $perPage OFFSET " . (($page-1)*$perPage)
    );
    $logSt->execute($params);
    $logs = $logSt->fetchAll();

    // Distinct actions for filter dropdown
    $actionsAvail = $pdo->query("SELECT DISTINCT action FROM AuditLog ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $actionsAvail = [];
}

$totalPages = max(1, (int)ceil($totalLogs / $perPage));

// Recent logins (from Account.last_login_at)
$recentLoginsCount = (int)$pdo->query("SELECT COUNT(*) FROM Account WHERE last_login_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND is_active=TRUE")->fetchColumn();

// Action color map
function actionColor(string $action): string {
    if (str_contains($action, 'CREATE')) return 'background:#d1fae5;color:#065f46;';
    if (str_contains($action, 'UPDATE') || str_contains($action, 'EDIT')) return 'background:#dbeafe;color:#1e40af;';
    if (str_contains($action, 'DELETE') || str_contains($action, 'LOCK')) return 'background:#fee2e2;color:#b91c1c;';
    if (str_contains($action, 'LOGIN') || str_contains($action, 'LOGOUT')) return 'background:#f3e8ff;color:#7e22ce;';
    return 'background:#f1f5f9;color:#334155;';
}

$warnCount  = $activeWarn;
$pageTitle  = 'Nhật ký hệ thống';
$activePage = 'auditlog';
$breadcrumb = 'Quản trị › Nhật ký Hệ thống';
require __DIR__ . '/_layout.php';
?>

<!-- ═══ PAGE HEADER ══════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="font-size:20px;font-weight:800;color:#0d1b2e;">Nhật ký Hệ thống & Giám sát Tác vụ</h1>
        <p style="font-size:12px;color:#64748b;margin-top:3px;">
            Toàn bộ hành động quản trị: tạo tài khoản, đổi điểm, cảnh báo học vụ, cập nhật cấu hình.
        </p>
    </div>
    <a href="?export=csv" class="abtn abtn--outline">⬇ Xuất CSV</a>
</div>

<!-- ═══ 4 KPI CARDS ══════════════════════════════════════════ -->
<div class="adm-kpi-row">
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Tổng bản ghi log</span>
            <span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-grades.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= number_format($totalLogs) ?></span></div>
        <div class="adm-kpi__sub">Nhật ký hệ thống</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Đăng nhập hôm nay</span>
            <span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-nav-profile.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $recentLoginsCount ?></span></div>
        <div class="adm-kpi__sub">Tài khoản hoạt động 24h qua</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Sinh viên đang học</span>
            <span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-nav-profile.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= number_format($totalSV) ?></span></div>
        <div class="adm-kpi__sub">Trạng thái Active</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Cảnh báo đang hoạt động</span>
            <span class="adm-kpi__icon adm-kpi__icon--red"><img src="../img/db-icon-alert-warn.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num" style="color:#ef4444;"><?= number_format($activeWarn) ?></span></div>
        <div class="adm-kpi__sub">AcademicWarning Active</div>
    </div>
</div>

<?php if (!$auditExists): ?>
<div class="acard" style="padding:28px;text-align:center;color:#f59e0b;">
    <div style="font-size:32px;margin-bottom:8px;">⚠️</div>
    <h3 style="font-weight:700;margin-bottom:8px;">Bảng AuditLog chưa tồn tại</h3>
    <p style="font-size:13px;color:#64748b;">Vui lòng chạy migration v2: <code>mysql -u root uniportal &lt; database/migrate_v2_complete.sql</code></p>
</div>
<?php else: ?>

<!-- ═══ FILTER ROW ═══════════════════════════════════════════ -->
<div class="acard" style="padding:14px 16px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <div style="position:relative;">
            <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);width:12px;opacity:.4;" />
            <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Tìm theo action, entity, username…"
                   style="height:34px;padding:0 10px 0 26px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;min-width:220px;outline:none;" />
        </div>
        <?php if (!empty($actionsAvail)): ?>
        <select name="action_f" style="height:34px;padding:0 8px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;background:#fff;">
            <option value="">Tất cả hành động</option>
            <?php foreach ($actionsAvail as $a): ?>
            <option value="<?= htmlspecialchars($a) ?>" <?= $actionF===$a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="date" name="date" value="<?= htmlspecialchars($dateF) ?>"
               style="height:34px;padding:0 8px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;" />
        <button type="submit" class="abtn abtn--primary" style="padding:6px 14px;font-size:12px;">Lọc</button>
        <?php if ($search || $actionF || $dateF): ?>
        <a href="auditlog.php" class="abtn abtn--outline" style="padding:6px 14px;font-size:12px;">✕ Xóa lọc</a>
        <?php endif; ?>
        <span style="margin-left:auto;font-size:12px;color:#94a3b8;">
            <?= number_format($totalLogs) ?> bản ghi • Trang <?= $page ?>/<?= $totalPages ?>
        </span>
    </form>
</div>

<!-- ═══ LOG TABLE ════════════════════════════════════════════ -->
<div class="acard" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;white-space:nowrap;">#</th>
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;white-space:nowrap;">Thời gian</th>
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;">Người thực hiện</th>
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;">Hành động</th>
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;">Đối tượng</th>
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;">Chi tiết</th>
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;white-space:nowrap;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    <?= $auditExists ? 'Không có bản ghi log nào.' : 'AuditLog chưa được khởi tạo.' ?>
                </td></tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:8px 14px;color:#94a3b8;"><?= (int)$log['log_id'] ?></td>
                    <td style="padding:8px 14px;white-space:nowrap;color:#64748b;">
                        <?= date('d/m/Y', strtotime($log['created_at'])) ?><br/>
                        <span style="font-size:10px;"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                    </td>
                    <td style="padding:8px 14px;">
                        <div style="font-weight:600;color:#001634;"><?= htmlspecialchars($log['username'] ?? 'system') ?></div>
                        <div style="font-size:10px;color:#94a3b8;"><?= htmlspecialchars($log['role'] ?? '') ?> #<?= (int)$log['actor_id'] ?></div>
                    </td>
                    <td style="padding:8px 14px;">
                        <span style="padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;<?= actionColor($log['action']) ?>">
                            <?= htmlspecialchars($log['action']) ?>
                        </span>
                    </td>
                    <td style="padding:8px 14px;font-size:11px;">
                        <div style="color:#334155;"><?= htmlspecialchars($log['target_entity']) ?></div>
                        <div style="color:#94a3b8;"># <?= htmlspecialchars($log['target_id']) ?></div>
                    </td>
                    <td style="padding:8px 14px;max-width:280px;">
                        <?php
                        $newVal = $log['new_value_json'] ? @json_decode($log['new_value_json'], true) : null;
                        $oldVal = $log['old_value_json'] ? @json_decode($log['old_value_json'], true) : null;
                        $display = '';
                        if ($newVal && is_array($newVal)) {
                            $pairs = [];
                            foreach (array_slice($newVal, 0, 3, true) as $k => $v) {
                                $pairs[] = "$k: " . (is_string($v) ? mb_strimwidth($v, 0, 30, '…') : json_encode($v));
                            }
                            $display = implode(', ', $pairs);
                        }
                        ?>
                        <div style="font-size:11px;color:#44474e;line-height:1.4;"><?= htmlspecialchars($display ?: '—') ?></div>
                    </td>
                    <td style="padding:8px 14px;font-size:11px;color:#94a3b8;white-space:nowrap;"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:12px 16px;border-top:1px solid #f1f5f9;display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
        <?php
        $startP = max(1, $page - 3);
        $endP   = min($totalPages, $page + 3);
        if ($startP > 1) echo '<a href="?page=1&q='.urlencode($search).'&action_f='.urlencode($actionF).'&date='.urlencode($dateF).'" style="display:inline-block;width:32px;height:32px;line-height:32px;text-align:center;border-radius:6px;font-size:13px;text-decoration:none;background:#f8fafc;color:#334155;">1</a>';
        for ($p = $startP; $p <= $endP; $p++):
        ?>
        <a href="?page=<?= $p ?>&q=<?= urlencode($search) ?>&action_f=<?= urlencode($actionF) ?>&date=<?= urlencode($dateF) ?>"
           style="display:inline-block;width:32px;height:32px;line-height:32px;text-align:center;border-radius:6px;font-size:13px;text-decoration:none;
                  background:<?= $p===$page ? '#0d1b2e' : '#f8fafc' ?>;color:<?= $p===$page ? '#fff' : '#334155' ?>;">
            <?= $p ?>
        </a>
        <?php endfor;
        if ($endP < $totalPages) echo '<a href="?page='.$totalPages.'&q='.urlencode($search).'&action_f='.urlencode($actionF).'&date='.urlencode($dateF).'" style="display:inline-block;width:32px;height:32px;line-height:32px;text-align:center;border-radius:6px;font-size:13px;text-decoration:none;background:#f8fafc;color:#334155;">'.$totalPages.'</a>';
        ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>
