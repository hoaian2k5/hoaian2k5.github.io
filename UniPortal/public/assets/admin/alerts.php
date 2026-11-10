<?php
/**
 * UniPortal – Admin: Cảnh báo học vụ (Academic Warnings)
 * Full CRUD: view, filter, resolve, delete warnings + generate new ones.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/AcademicAlertService.php';
require_once $root . '/src/src/services/NotificationService.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: /UniPortal/public/assets/lecturer/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo      = get_db();
$adminId  = (int)($_SESSION['account_id'] ?? 25);

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add_warning') {
        $sid   = trim($_POST['student_id']    ?? '');
        $type  = $_POST['warning_type']       ?? '';
        $msg   = trim($_POST['message']       ?? '');
        $valid = ['Attendance','LowGrade','LowGPA','GraduationRisk'];
        if ($sid === '' || !in_array($type, $valid) || $msg === '') {
            flash_set('error', 'Điền đầy đủ: MSSV, loại cảnh báo, nội dung cảnh báo.');
        } else {
            $ck = $pdo->prepare('SELECT 1 FROM Student WHERE student_id=:s LIMIT 1');
            $ck->execute([':s' => $sid]);
            if (!$ck->fetch()) {
                flash_set('error', "Không tìm thấy sinh viên $sid.");
            } else {
                $pdo->prepare(
                    "INSERT INTO AcademicWarning (student_id,warning_type,message,status,is_read)
                     VALUES (:sid,:type,:msg,'Active',FALSE)"
                )->execute([':sid' => $sid, ':type' => $type, ':msg' => $msg]);
                // Notify the student
                $accRow = $pdo->prepare('SELECT account_id FROM Account WHERE student_id=:s LIMIT 1');
                $accRow->execute([':s' => $sid]);
                $accId = (int)($accRow->fetchColumn() ?? 0);
                if ($accId) {
                    NotificationService::send($accId, 'Warning', "⚠️ Cảnh báo học vụ: " . $msg,
                        $msg, '/UniPortal/public/assets/student/alerts.php');
                }
                AcademicAlertService::writeAuditLog($pdo, $adminId, 'CREATE_WARNING', 'AcademicWarning', $sid,
                    null, json_encode(['type' => $type, 'msg' => $msg]));
                flash_set('success', "Đã thêm cảnh báo cho sinh viên $sid.");
            }
        }
        header('Location: alerts.php'); exit;
    }

    if ($action === 'resolve_warning') {
        $wid = (int)($_POST['warning_id'] ?? 0);
        if ($wid > 0) {
            $pdo->prepare("UPDATE AcademicWarning SET status='Resolved', is_read=TRUE WHERE warning_id=:wid")
                ->execute([':wid' => $wid]);
            flash_set('success', 'Đã đánh dấu cảnh báo là đã giải quyết.');
        }
        header('Location: alerts.php?' . http_build_query(array_intersect_key($_GET, ['filter'=>1,'type'=>1,'q'=>1]))); exit;
    }

    if ($action === 'delete_warning') {
        $wid = (int)($_POST['warning_id'] ?? 0);
        if ($wid > 0) {
            $pdo->prepare('DELETE FROM AcademicWarning WHERE warning_id=:wid')->execute([':wid' => $wid]);
            flash_set('success', 'Đã xóa cảnh báo.');
        }
        header('Location: alerts.php'); exit;
    }

    if ($action === 'recalc_all') {
        $students = $pdo->query("SELECT student_id FROM Student WHERE status='Active'")->fetchAll();
        $count = 0;
        foreach ($students as $s) {
            $result = AcademicAlertService::recalcGpa($s['student_id']);
            $count += $result['warnings_created'];
        }
        flash_set('success', "Đã tái tính GPA và tạo $count cảnh báo mới.");
        header('Location: alerts.php'); exit;
    }
}

// ── Filter & fetch ─────────────────────────────────────────────
$filter  = $_GET['filter'] ?? 'active';
$typeF   = $_GET['type']   ?? '';
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$where  = "WHERE aw.warning_id > 0";
$params = [];
if ($filter === 'active')   $where .= " AND aw.status = 'Active'";
if ($filter === 'resolved') $where .= " AND aw.status = 'Resolved'";
if ($filter === 'unread')   $where .= " AND aw.is_read = FALSE";
if ($typeF !== '')          { $where .= " AND aw.warning_type = :type"; $params[':type'] = $typeF; }
if ($search !== '')         { $where .= " AND (s.full_name LIKE :q OR s.student_id LIKE :q)"; $params[':q'] = '%'.$search.'%'; }

$countSt = $pdo->prepare("SELECT COUNT(*) FROM AcademicWarning aw JOIN Student s ON s.student_id=aw.student_id $where");
$countSt->execute($params);
$totalRows = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT aw.warning_id, aw.warning_type, aw.message, aw.created_at, aw.is_read, aw.status,
            s.student_id, s.full_name, s.class_code
     FROM AcademicWarning aw
     JOIN Student s ON s.student_id = aw.student_id
     $where
     ORDER BY aw.is_read ASC, aw.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$warnings = $stmt->fetchAll();

// Summary counts
$totalActive   = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active'")->fetchColumn();
$totalUnread   = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE is_read=FALSE AND status='Active'")->fetchColumn();
$totalResolved = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Resolved'")->fetchColumn();
$totalAll      = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning")->fetchColumn();

// Students list for add-warning modal
$studentList = $pdo->query("SELECT student_id, full_name, class_code FROM Student WHERE status='Active' ORDER BY student_id LIMIT 200")->fetchAll();

$typeLabels = ['Attendance'=>'Chuyên cần','LowGrade'=>'Điểm thấp','LowGPA'=>'GPA thấp','GraduationRisk'=>'Nguy cơ tốt nghiệp'];
$warnCount  = $totalUnread;
$pageTitle  = 'Cảnh báo học vụ';
$activePage = 'alerts';
$breadcrumb = 'Quản trị › Cảnh báo học vụ';
require __DIR__ . '/_layout.php';
?>

<!-- ═══ PAGE HEADER ════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="font-size:20px;font-weight:800;color:#0d1b2e;">Cảnh báo học vụ</h1>
        <p style="font-size:12px;color:#64748b;margin-top:3px;">Quản lý toàn bộ cảnh báo học vụ: vắng học, điểm thấp, GPA thấp, nguy cơ tốt nghiệp.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <form method="POST" style="display:inline;" onsubmit="return confirm('Tái tính GPA và cảnh báo cho TẤT CẢ sinh viên?\nQuá trình này có thể mất vài giây.')">
            <input type="hidden" name="_action" value="recalc_all" />
            <button type="submit" class="abtn abtn--outline">🔄 Tái tính toàn hệ thống</button>
        </form>
        <button onclick="document.getElementById('modalAddWarn').style.display='flex'" class="abtn abtn--primary">+ Thêm cảnh báo thủ công</button>
    </div>
</div>

<?php flash_render(); ?>

<!-- ═══ 4 KPI CARDS ════════════════════════════════════════ -->
<div class="adm-kpi-row">
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Tổng cảnh báo</span>
            <span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-alerts.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= number_format($totalAll) ?></span></div>
        <div class="adm-kpi__sub">Tất cả thời gian</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Đang hoạt động</span>
            <span class="adm-kpi__icon adm-kpi__icon--red"><img src="../img/db-icon-alert-warn.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num" style="color:#ef4444;"><?= number_format($totalActive) ?></span></div>
        <div class="adm-kpi__sub adm-kpi__sub--down"><?= $totalUnread ?> chưa đọc</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Chưa đọc</span>
            <span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-icon-alert-warn.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num" style="color:#f59e0b;"><?= number_format($totalUnread) ?></span></div>
        <div class="adm-kpi__sub">Sinh viên chưa xem</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Đã giải quyết</span>
            <span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-icon-degree-check.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num" style="color:#059669;"><?= number_format($totalResolved) ?></span></div>
        <div class="adm-kpi__sub"><?= $totalAll > 0 ? round($totalResolved/$totalAll*100) : 0 ?>% tổng số</div>
    </div>
</div>

<!-- ═══ FILTER TABS + SEARCH ═══════════════════════════════ -->
<div class="acard" style="padding:14px 16px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <?php foreach ([
            'all'      => "Tất cả ($totalAll)",
            'active'   => "Đang active ($totalActive)",
            'unread'   => "Chưa đọc ($totalUnread)",
            'resolved' => "Đã giải quyết ($totalResolved)",
        ] as $k => $v): ?>
        <a href="?filter=<?= $k ?>"
           class="abtn <?= $filter === $k ? 'abtn--primary' : 'abtn--outline' ?>"
           style="padding:6px 14px;font-size:12px;">
            <?= htmlspecialchars($v) ?>
        </a>
        <?php endforeach; ?>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select name="type" onchange="this.form.submit()" style="height:34px;padding:0 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;background:#fff;">
                <option value="">Tất cả loại</option>
                <?php foreach ($typeLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $typeF === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>" />
            <div style="position:relative;">
                <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);width:12px;opacity:.4;" />
                <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Tìm MSSV, tên sinh viên…"
                       style="height:34px;padding:0 10px 0 26px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;min-width:200px;outline:none;" />
            </div>
            <button type="submit" class="abtn abtn--primary" style="padding:6px 14px;font-size:12px;">Lọc</button>
            <?php if ($search || $typeF): ?>
            <a href="?filter=<?= htmlspecialchars($filter) ?>" class="abtn abtn--outline" style="padding:6px 14px;font-size:12px;">✕ Xóa lọc</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ═══ TABLE ═════════════════════════════════════════════ -->
<div class="acard" style="padding:0;overflow:hidden;">
    <div style="padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:14px;font-weight:700;color:#0d1b2e;">
            <?= number_format($totalRows) ?> kết quả
        </span>
        <span style="font-size:12px;color:#94a3b8;">Trang <?= $page ?>/<?= $totalPages ?></span>
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;white-space:nowrap;">Sinh viên</th>
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;">Loại</th>
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;">Nội dung cảnh báo</th>
                    <th style="padding:10px 14px;text-align:left;font-weight:600;color:#334155;white-space:nowrap;">Ngày tạo</th>
                    <th style="padding:10px 14px;text-align:center;font-weight:600;color:#334155;">Trạng thái</th>
                    <th style="padding:10px 14px;text-align:center;font-weight:600;color:#334155;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($warnings)): ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không có cảnh báo nào phù hợp với bộ lọc.
                </td></tr>
                <?php else: ?>
                <?php foreach ($warnings as $w):
                    $typeCls = match($w['warning_type']) {
                        'LowGPA','GraduationRisk' => 'background:#fee2e2;color:#b91c1c;',
                        'Attendance'              => 'background:#fff7ed;color:#c2410c;',
                        default                   => 'background:#fef9c3;color:#854d0e;',
                    };
                    $isUnread = !$w['is_read'] && $w['status'] === 'Active';
                ?>
                <tr style="border-bottom:1px solid #f1f5f9;<?= $isUnread ? 'background:#fffbeb;' : '' ?>">
                    <td style="padding:10px 14px;">
                        <div style="font-weight:700;color:#001634;">
                            <?= htmlspecialchars($w['full_name']) ?>
                            <?php if ($isUnread): ?>
                            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#ef4444;margin-left:4px;vertical-align:middle;" title="Chưa đọc"></span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($w['student_id']) ?> • <?= htmlspecialchars($w['class_code']) ?></div>
                    </td>
                    <td style="padding:10px 14px;">
                        <span style="padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;<?= $typeCls ?>">
                            <?= htmlspecialchars($typeLabels[$w['warning_type']] ?? $w['warning_type']) ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px;max-width:360px;">
                        <div style="font-size:12px;color:#334155;line-height:1.5;"><?= htmlspecialchars(mb_strimwidth($w['message'], 0, 120, '…')) ?></div>
                    </td>
                    <td style="padding:10px 14px;white-space:nowrap;font-size:12px;color:#64748b;"><?= date('d/m/Y H:i', strtotime($w['created_at'])) ?></td>
                    <td style="padding:10px 14px;text-align:center;">
                        <?php if ($w['status'] === 'Resolved'): ?>
                        <span style="background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">✅ Đã giải quyết</span>
                        <?php elseif ($isUnread): ?>
                        <span style="background:#fee2e2;color:#b91c1c;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">🔴 Cần xử lý</span>
                        <?php else: ?>
                        <span style="background:#fef9c3;color:#854d0e;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">⏳ Đang theo dõi</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 14px;text-align:center;">
                        <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                            <?php if ($w['status'] === 'Active'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="_action"    value="resolve_warning" />
                                <input type="hidden" name="warning_id" value="<?= (int)$w['warning_id'] ?>" />
                                <button class="abtn abtn--outline" style="padding:4px 10px;font-size:11px;" title="Giải quyết">✅ Giải quyết</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa cảnh báo này?')">
                                <input type="hidden" name="_action"    value="delete_warning" />
                                <input type="hidden" name="warning_id" value="<?= (int)$w['warning_id'] ?>" />
                                <button class="abtn abtn--outline" style="padding:4px 10px;font-size:11px;color:#ef4444;border-color:#fca5a5;" title="Xóa">🗑</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:12px 16px;border-top:1px solid #f1f5f9;display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="?filter=<?= htmlspecialchars($filter) ?>&type=<?= htmlspecialchars($typeF) ?>&q=<?= urlencode($search) ?>&page=<?= $p ?>"
           style="display:inline-block;width:32px;height:32px;line-height:32px;text-align:center;border-radius:6px;font-size:13px;text-decoration:none;
                  background:<?= $p===$page ? '#0d1b2e' : '#f8fafc' ?>;color:<?= $p===$page ? '#fff' : '#334155' ?>;">
            <?= $p ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ MODAL: Add Warning ════════════════════════════════ -->
<div id="modalAddWarn" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:500px;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
    <button onclick="document.getElementById('modalAddWarn').style.display='none'"
            style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">+ Thêm cảnh báo học vụ</h2>
    <form method="POST">
        <input type="hidden" name="_action" value="add_warning" />
        <div style="display:flex;flex-direction:column;gap:14px;">
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Sinh viên *</label>
                <select name="student_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;">
                    <option value="">— Chọn sinh viên —</option>
                    <?php foreach ($studentList as $st): ?>
                    <option value="<?= htmlspecialchars($st['student_id']) ?>">
                        <?= htmlspecialchars($st['student_id'] . ' – ' . $st['full_name'] . ' (' . $st['class_code'] . ')') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Loại cảnh báo *</label>
                <select name="warning_type" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;">
                    <?php foreach ($typeLabels as $k => $v): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Nội dung cảnh báo *</label>
                <textarea name="message" required rows="3" placeholder="Nhập nội dung cảnh báo chi tiết…"
                          style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit;resize:vertical;outline:none;"></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('modalAddWarn').style.display='none'"
                        class="abtn abtn--outline">Hủy</button>
                <button type="submit" class="abtn abtn--primary">Thêm cảnh báo</button>
            </div>
        </div>
    </form>
</div></div>

<script>
document.getElementById('modalAddWarn').addEventListener('click', function(e){
    if (e.target === this) this.style.display = 'none';
});
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') document.getElementById('modalAddWarn').style.display = 'none';
});
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
