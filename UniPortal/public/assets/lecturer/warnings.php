<?php
/**
 * UniPortal – Cảnh báo & Can thiệp
 * Figma: warning screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId      = (int)($_SESSION['lecturer_id'] ?? 0);
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);
$warnCount  = LecturerModel::getUnreadWarningCount($lecId);
$sections   = $semId ? LecturerModel::getSections($lecId, $semId) : [];

// ── Handle mark read POST ─────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $wid = (int)$_POST['warning_id'];
    $pdo = get_db();
    $pdo->prepare('UPDATE AcademicWarning SET is_read = TRUE WHERE warning_id = :wid')
        ->execute([':wid' => $wid]);
    $flash = 'success:Đã ghi nhận can thiệp.';
}

// ── Filter ────────────────────────────────────────────────────
$filter  = $_GET['filter'] ?? 'all';
$typeF   = $_GET['type']   ?? '';
$search  = trim($_GET['q'] ?? '');
$pdo     = get_db();

$where   = "WHERE cs.lecturer_id = :lid";
$params  = [':lid' => $lecId];
if ($typeF)   { $where .= " AND aw.warning_type = :type"; $params[':type'] = $typeF; }
if ($search)  { $where .= " AND (s.full_name LIKE :q OR s.student_id LIKE :q)"; $params[':q'] = '%'.$search.'%'; }
if ($filter === 'unread')   $where .= " AND aw.is_read = FALSE";
if ($filter === 'active')   $where .= " AND aw.status = 'Active'";
if ($filter === 'resolved') $where .= " AND aw.status = 'Resolved'";

$stmt = $pdo->prepare(
    "SELECT DISTINCT aw.warning_id, aw.warning_type, aw.message,
            aw.created_at, aw.is_read, aw.status AS warn_status,
            s.student_id, s.full_name, s.class_code, s.email,
            cs.section_code
     FROM AcademicWarning aw
     JOIN Student s ON s.student_id = aw.student_id
     JOIN Enrollment e ON e.student_id = s.student_id
     JOIN ClassSection cs ON cs.section_id = e.section_id
     $where
     ORDER BY aw.is_read ASC, aw.created_at DESC
     LIMIT 100"
);
$stmt->execute($params);
$warnings = $stmt->fetchAll();

// ── Summary stats ─────────────────────────────────────────────
$allWarnings = LecturerModel::getWarningsForLecturer($lecId, 200);
$totalAll    = count($allWarnings);
$totalAtt    = count(array_filter($allWarnings, fn($w) => $w['warning_type'] === 'Attendance'));
$totalGPA    = count(array_filter($allWarnings, fn($w) => $w['warning_type'] === 'LowGPA'));
$totalGrade  = count(array_filter($allWarnings, fn($w) => $w['warning_type'] === 'LowGrade'));
$resolvedCount = count(array_filter($allWarnings, fn($w) => $w['warn_status'] === 'Resolved'));

$typeLabels = [
    'Attendance'    => 'Chuyên cần',
    'LowGrade'      => 'Điểm thấp',
    'LowGPA'        => 'GPA thấp',
    'GraduationRisk'=> 'Tốt nghiệp',
];

$pageTitle  = 'Cảnh báo & Can thiệp';
$activePage = 'warnings';
require __DIR__ . '/_layout.php';

function initials(string $n): string {
    $r=''; foreach(explode(' ',trim($n)) as $w) $r.=strtoupper(substr($w,0,1));
    return substr($r,-2);
}
?>

<!-- ═══════════ 4 STAT CARDS ════════════════════════════════ -->
<div class="lec-kpi-row">
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Tổng ca cảnh báo</span>
            <span class="lec-kpi__icon lec-kpi__icon--red"><img src="../img/db-nav-alerts.svg" alt="" /></span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num" style="color:#ef4444;"><?= str_pad($totalAll,'2','0',STR_PAD_LEFT) ?></span>
            <span class="lec-kpi__unit">sinh viên</span>
        </div>
        <div class="lec-kpi__sub">Thuộc <?= count($sections) ?> lớp học phần phụ trách</div>
    </div>
    <div class="lcard lec-kpi" style="border-left:4px solid #ef4444;">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Cấm thi (Vắng >20%)</span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num" style="color:#ef4444;"><?= str_pad($totalAtt,'2','0',STR_PAD_LEFT) ?></span>
            <span class="lec-kpi__unit">sinh viên</span>
        </div>
        <div class="lec-kpi__sub" style="color:#ef4444;">⚡ Xử lý gấp trước Tuần 13</div>
    </div>
    <div class="lcard lec-kpi" style="border-left:4px solid #f59e0b;">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Điểm thành phần thấp</span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num" style="color:#f59e0b;"><?= str_pad($totalGrade,'2','0',STR_PAD_LEFT) ?></span>
            <span class="lec-kpi__unit">sinh viên</span>
        </div>
        <div class="lec-kpi__sub">GPA < 2.0 hoặc tụt > 0.5 điểm</div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Đã can thiệp &amp; đang theo dõi</span>
            <span class="lec-kpi__icon lec-kpi__icon--green"><img src="../img/db-icon-degree-check.svg" alt="" /></span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num" style="color:#166534;"><?= $resolvedCount ?></span>
            <span class="lec-kpi__unit">/ <?= $totalAll ?> hồ sơ</span>
        </div>
        <div class="lec-kpi__sub">
            <div style="height:6px;background:#dcfce7;border-radius:9999px;overflow:hidden;margin-bottom:3px;">
                <div style="height:100%;width:<?= $totalAll>0?round($resolvedCount/$totalAll*100):0 ?>%;background:#22c55e;border-radius:9999px;"></div>
            </div>
            <?= $totalAll>0?round($resolvedCount/$totalAll*100):0 ?>%
        </div>
    </div>
</div>

<!-- ═══════════ FILTER TABS ══════════════════════════════════ -->
<?php if ($flash): [$ftype,$fmsg] = explode(':',  $flash, 2); ?>
<div class="lec-flash lec-flash--<?= $ftype==='success'?'success':'error' ?>">
    <?= $ftype==='success'?'✅':'⚠️' ?> <?= htmlspecialchars($fmsg) ?>
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
    <?php
    $filters = ['all'=>"Tất cả cảnh báo $totalAll",'active'=>"Nguy cơ cấm thi chuyên cần $totalAtt",'unread'=>"GPA dưới ngưỡng an toàn $totalGPA",'grade'=>"Thiếu nộp bài tập $totalGrade"];
    foreach ($filters as $k => $v):
    ?>
    <a href="?filter=<?= $k ?>"
       class="lpill <?= $filter===$k?'lpill--blue':'lpill--gray' ?>">
        <?= htmlspecialchars($v) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ═══════════ SEARCH + TABLE ═══════════════════════════════ -->
<div class="lcard" style="padding:0;overflow:hidden;">
    <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>" />
            <div style="position:relative;flex:1;min-width:220px;">
                <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
                <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Tìm tên sinh viên, MSSV, email..."
                       style="width:100%;height:36px;background:#f1f5f9;border:none;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
            </div>
            <select name="type" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                <option value="">Tất cả loại</option>
                <?php foreach ($typeLabels as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $typeF===$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="lbtn lbtn--primary lbtn--sm">Lọc</button>
            <?php if ($search || $typeF): ?>
            <a href="warnings.php" class="lbtn lbtn--outline lbtn--sm">Xoá lọc</a>
            <?php endif; ?>
        </form>
    </div>

    <div style="padding:12px 16px;border-bottom:1px solid #e2e8f0;">
        <div style="font-size:14px;font-weight:700;color:#001634;">☑ Danh Sách Cảnh Báo Học Vụ</div>
    </div>

    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>Sinh viên &amp; Lớp</th>
                    <th>Loại cảnh báo</th>
                    <th>Mức độ</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($warnings)): ?>
                <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không có cảnh báo nào phù hợp với bộ lọc.
                </td></tr>
                <?php else: ?>
                <?php foreach ($warnings as $w):
                    $ini = initials($w['full_name']);
                    $wt  = $w['warning_type'];
                    $lvl = in_array($wt, ['LowGPA','Attendance']) ? 'Mức 3' : ($wt==='LowGrade' ? 'Mức 2' : 'Mức 1');
                    $lvlCls = $lvl==='Mức 3'?'lpill--red':($lvl==='Mức 2'?'lpill--orange':'lpill--yellow');
                    $tagLabel = ['Attendance'=>'Vắng %','LowGPA'=>'GPA thấp','LowGrade'=>'Điểm thấp','GraduationRisk'=>'Tốt nghiệp'][$wt]??$wt;
                    $tagCls   = ['Attendance'=>'att','LowGPA'=>'gpa','LowGrade'=>'grade'][$wt]??'att';
                ?>
                <tr style="<?= !$w['is_read']?'background:#fffbeb;':'' ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="lec-av" style="width:36px;height:36px;background:<?= $lvl==='Mức 3'?'#fee2e2':($lvl==='Mức 2'?'#fff7ed':'#eff4ff') ?>;">
                                <?= htmlspecialchars($ini) ?>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:13px;color:#001634;">
                                    <?= htmlspecialchars($w['full_name']) ?>
                                    <?php if (!$w['is_read']): ?>
                                    <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#ef4444;margin-left:4px;vertical-align:middle;"></span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:11px;color:#64748b;">
                                    <?= htmlspecialchars($w['student_id']) ?> • <?= htmlspecialchars($w['section_code'] ?? '') ?>
                                </div>
                                <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($w['email'] ?? '') ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <span class="lec-warn-item__tag lec-warn-item__tag--<?= $tagCls ?>">
                                <?= htmlspecialchars($tagLabel) ?>
                            </span>
                            <div style="font-size:12px;color:#44474e;max-width:280px;line-height:16px;">
                                <?= htmlspecialchars(mb_strimwidth($w['message'],0,80,'…')) ?>
                            </div>
                            <div style="font-size:11px;color:#94a3b8;"><?= date('d/m/Y H:i', strtotime($w['created_at'])) ?></div>
                        </div>
                    </td>
                    <td>
                        <span class="lpill <?= $lvlCls ?>"><?= $lvl ?></span>
                    </td>
                    <td>
                        <?php if ($w['warn_status'] === 'Resolved'): ?>
                        <span class="lpill lpill--green" style="font-size:11px;">✅ Đã hoàn tất</span>
                        <?php elseif ($w['is_read']): ?>
                        <span class="lpill lpill--gray" style="font-size:11px;">Đang theo dõi</span>
                        <?php else: ?>
                        <span class="lpill lpill--red" style="font-size:11px;">Cần xử lý</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="classes.php?section=<?= urlencode($w['section_code']??'') ?>" class="lbtn lbtn--outline lbtn--sm" title="Xem chi tiết">👁</a>
                            <?php if (!$w['is_read']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="mark_read" value="1" />
                                <input type="hidden" name="warning_id" value="<?= (int)$w['warning_id'] ?>" />
                                <button type="submit" class="lbtn lbtn--red lbtn--sm">Ghi nhận can thiệp</button>
                            </form>
                            <?php endif; ?>
                            <a href="#" class="lbtn lbtn--outline lbtn--sm">Liên hệ SV &amp; Cố vấn</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="padding:12px 16px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <span style="font-size:12px;color:#64748b;">Hiển thị 1 – <?= count($warnings) ?> trên tổng số <?= $totalAll ?> cảnh báo</span>
        <div class="lec-pagination">
            <span class="active">1</span>
            <?php if ($totalAll > count($warnings)): ?>
            <a href="?page=2">2</a>
            <a href="?page=3">→</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
