<?php
/**
 * UniPortal – Đăng ký môn học (Course Registration)
 * Students can enroll in / drop sections for the Active semester
 * within the configured registration window.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/services/NotificationService.php';
require_once $root . '/src/src/services/AcademicAlertService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo        = get_db();
$student_id = $_SESSION['student_id']   ?? '';
$account_id = (int)($_SESSION['account_id'] ?? 0);
$full_name  = $_SESSION['full_name']     ?? '';

// ── Load active semester ──────────────────────────────────────
$semRow = $pdo->query(
    "SELECT * FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1"
)->fetch();

$regOpen   = false;
$regMsg    = '';
if ($semRow) {
    $now = time();
    $ro  = $semRow['reg_open']  ? strtotime($semRow['reg_open'])  : 0;
    $rc  = $semRow['reg_close'] ? strtotime($semRow['reg_close']) : PHP_INT_MAX;
    if ($ro && $rc) {
        $regOpen = ($now >= $ro && $now <= $rc);
        if (!$regOpen && $now < $ro)  $regMsg = 'Chưa đến thời gian đăng ký. Mở từ ' . date('d/m/Y H:i', $ro);
        if (!$regOpen && $now > $rc)  $regMsg = 'Đã hết thời gian đăng ký (đóng ' . date('d/m/Y H:i', $rc) . ')';
    } else {
        $regOpen = true; // no window configured – allow always
    }
}

$flash = '';

// ── Handle POST: enroll or drop ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $semRow) {
    csrf_verify();
    $action     = $_POST['action']     ?? '';
    $section_id = (int)($_POST['section_id'] ?? 0);

    if ($action === 'enroll' && $regOpen) {
        // Verify section exists and belongs to Active semester
        $secStmt = $pdo->prepare(
            'SELECT cs.*, c.course_code, c.course_name, c.credits
             FROM ClassSection cs JOIN Course c ON c.course_id=cs.course_id
             WHERE cs.section_id=:sid AND cs.semester_id=:sem LIMIT 1'
        );
        $secStmt->execute([':sid' => $section_id, ':sem' => $semRow['semester_id']]);
        $sec = $secStmt->fetch();

        if (!$sec) {
            $flash = 'error:Không tìm thấy lớp học phần.';
        } else {
            // Check capacity
            $enrolled = (int)$pdo->prepare(
                'SELECT COUNT(*) FROM Enrollment WHERE section_id=:sid AND status IN (\'Enrolled\',\'Completed\')'
            )->execute([':sid' => $section_id]) ? (function() use ($pdo, $section_id) {
                $s = $pdo->prepare('SELECT COUNT(*) FROM Enrollment WHERE section_id=:sid AND status IN (\'Enrolled\',\'Completed\')');
                $s->execute([':sid' => $section_id]);
                return (int)$s->fetchColumn();
            })() : 0;

            if ($sec['capacity'] && $enrolled >= $sec['capacity']) {
                $flash = 'error:Lớp đã đầy (' . $enrolled . '/' . $sec['capacity'] . ' sinh viên).';
            } else {
                // Check duplicate
                $dupChk = $pdo->prepare(
                    'SELECT enrollment_id FROM Enrollment WHERE student_id=:sid AND section_id=:sec LIMIT 1'
                );
                $dupChk->execute([':sid' => $student_id, ':sec' => $section_id]);
                if ($dupChk->fetch()) {
                    $flash = 'error:Bạn đã đăng ký lớp này rồi.';
                } else {
                    // Check same course already enrolled this semester
                    $sameCoursChk = $pdo->prepare(
                        'SELECT e.enrollment_id FROM Enrollment e
                         JOIN ClassSection cs ON cs.section_id=e.section_id
                         WHERE e.student_id=:sid AND cs.course_id=:cid AND cs.semester_id=:sem
                           AND e.status IN (\'Enrolled\',\'Completed\') LIMIT 1'
                    );
                    $sameCoursChk->execute([
                        ':sid' => $student_id,
                        ':cid' => $sec['course_id'],
                        ':sem' => $semRow['semester_id'],
                    ]);
                    if ($sameCoursChk->fetch()) {
                        $flash = 'error:Bạn đã đăng ký môn ' . $sec['course_code'] . ' ở lớp khác trong học kỳ này rồi.';
                    } else {
                        // Enroll
                        $pdo->prepare(
                            'INSERT INTO Enrollment (student_id, section_id, status, enrollment_date)
                             VALUES (:sid, :sec, \'Enrolled\', NOW())'
                        )->execute([':sid' => $student_id, ':sec' => $section_id]);

                        // Update / create tuition row
                        self_update_tuition($pdo, $student_id, (int)$semRow['semester_id']);

                        NotificationService::send(
                            $account_id, 'Registration',
                            '✅ Đăng ký thành công: ' . $sec['course_code'],
                            'Bạn đã đăng ký lớp ' . $sec['section_code'] . ' – ' . $sec['course_name'],
                            '/UniPortal/public/assets/student/registration.php'
                        );

                        AcademicAlertService::writeAuditLog(
                            $pdo, $account_id, 'ENROLL', 'Enrollment', $student_id,
                            null, json_encode(['section_id' => $section_id, 'course' => $sec['course_code']])
                        );

                        $flash = 'success:Đã đăng ký thành công lớp ' . $sec['section_code'] . ' – ' . $sec['course_name'];
                    }
                }
            }
        }
    }

    if ($action === 'drop' && $regOpen) {
        $dropStmt = $pdo->prepare(
            'SELECT e.enrollment_id, cs.section_code, c.course_name
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id=e.section_id
             JOIN Course c ON c.course_id=cs.course_id
             WHERE e.student_id=:sid AND e.section_id=:sec
               AND cs.semester_id=:sem AND e.status=\'Enrolled\' LIMIT 1'
        );
        $dropStmt->execute([':sid' => $student_id, ':sec' => $section_id, ':sem' => $semRow['semester_id']]);
        $drop = $dropStmt->fetch();
        if ($drop) {
            $pdo->prepare('UPDATE Enrollment SET status=\'Withdrawn\' WHERE enrollment_id=:eid')
                ->execute([':eid' => $drop['enrollment_id']]);

            self_update_tuition($pdo, $student_id, (int)$semRow['semester_id']);

            NotificationService::send(
                $account_id, 'Registration',
                '🗑 Đã hủy đăng ký: ' . $drop['section_code'],
                'Bạn đã hủy đăng ký lớp ' . $drop['section_code'],
                '/UniPortal/public/assets/student/registration.php'
            );
            $flash = 'success:Đã hủy đăng ký lớp ' . $drop['section_code'];
        } else {
            $flash = 'error:Không thể hủy đăng ký (lớp không tồn tại hoặc đã hoàn thành).';
        }
    }

    if (!$regOpen && $action) {
        $flash = 'error:' . ($regMsg ?: 'Ngoài thời gian đăng ký môn học.');
    }
}

// Re-usable: recalculate tuition from active enrollments
function self_update_tuition(PDO $pdo, string $student_id, int $semester_id): void {
    $row = $pdo->prepare(
        'SELECT SUM(c.credits) AS tc
         FROM Enrollment e
         JOIN ClassSection cs ON cs.section_id=e.section_id
         JOIN Course c ON c.course_id=cs.course_id
         WHERE e.student_id=:sid AND cs.semester_id=:sem AND e.status IN (\'Enrolled\',\'Completed\')'
    );
    $row->execute([':sid' => $student_id, ':sem' => $semester_id]);
    $tc  = (int)($row->fetchColumn() ?? 0);
    $vnd = $tc * 500000; // default rate

    // try to get from SystemConfig
    try {
        $r = $pdo->query("SELECT config_value FROM SystemConfig WHERE config_key='tuition_per_credit'")->fetchColumn();
        if ($r) $vnd = $tc * (int)$r;
    } catch (Throwable) {}

    $pdo->prepare(
        'INSERT INTO Tuition (student_id, semester_id, credits, amount_vnd, due_date, status)
         VALUES (:sid, :sem, :tc, :vnd,
                 DATE_ADD((SELECT start_date FROM Semester WHERE semester_id=:sem2), INTERVAL 30 DAY),
                 \'Unpaid\')
         ON DUPLICATE KEY UPDATE credits=:tc2, amount_vnd=:vnd2'
    )->execute([
        ':sid'  => $student_id, ':sem'  => $semester_id,
        ':sem2' => $semester_id, ':tc'  => $tc, ':vnd'  => $vnd,
        ':tc2'  => $tc, ':vnd2' => $vnd,
    ]);
}

// ── Sections available for active semester ────────────────────
$semId = $semRow ? (int)$semRow['semester_id'] : 0;

// My current enrollments this semester
$myEnrStmt = $pdo->prepare(
    'SELECT e.section_id, e.status, cs.section_code, c.course_code, c.course_name
     FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id=e.section_id
     JOIN Course c ON c.course_id=cs.course_id
     WHERE e.student_id=:sid AND cs.semester_id=:sem AND e.status IN (\'Enrolled\',\'Completed\')'
);
$myEnrStmt->execute([':sid' => $student_id, ':sem' => $semId]);
$myEnrollments = $myEnrStmt->fetchAll();
$mySectionIds  = array_column($myEnrollments, 'section_id');
$myCourseIds   = [];
if ($semId) {
    $mc = $pdo->prepare(
        'SELECT cs.course_id FROM Enrollment e JOIN ClassSection cs ON cs.section_id=e.section_id
         WHERE e.student_id=:sid AND cs.semester_id=:sem AND e.status IN (\'Enrolled\',\'Completed\')'
    );
    $mc->execute([':sid' => $student_id, ':sem' => $semId]);
    $myCourseIds = array_column($mc->fetchAll(), 'course_id');
}

// Search/filter
$searchQ  = trim($_GET['q']    ?? '');
$deptF    = trim($_GET['dept'] ?? '');

$whereSQL = "WHERE cs.semester_id = :sem";
$params   = [':sem' => $semId];
if ($searchQ) {
    $whereSQL .= " AND (c.course_code LIKE :q OR c.course_name LIKE :q OR cs.section_code LIKE :q)";
    $params[':q'] = '%' . $searchQ . '%';
}
if ($deptF) {
    $whereSQL .= " AND c.department_id = :dept";
    $params[':dept'] = $deptF;
}

$secStmt = $pdo->prepare(
    "SELECT cs.section_id, cs.section_code, cs.schedule, cs.room, cs.capacity,
            cs.course_id,
            c.course_code, c.course_name, c.credits,
            d.department_name,
            l.full_name AS lecturer_name,
            (SELECT COUNT(*) FROM Enrollment e2 WHERE e2.section_id=cs.section_id
              AND e2.status IN ('Enrolled','Completed')) AS enrolled_count
     FROM ClassSection cs
     JOIN Course c ON c.course_id=cs.course_id
     LEFT JOIN Department d ON d.department_id=c.department_id
     JOIN Lecturer l ON l.lecturer_id=cs.lecturer_id
     $whereSQL
     ORDER BY c.course_code, cs.section_code"
);
$secStmt->execute($params);
$sections = $secStmt->fetchAll();

$depts = $pdo->query('SELECT department_id, department_name FROM Department ORDER BY department_name')->fetchAll();

// ── Helpers ───────────────────────────────────────────────────
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pageTitle  = 'Đăng ký môn học';
$activePage = 'registration';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<?php page_banner('Đăng ký môn học', 'Đăng ký / hủy đăng ký môn học cho học kỳ ' . h($semRow['semester_name'] ?? '—') . ' ' . h($semRow['academic_year'] ?? ''), 'db-nav-grades.svg', $imgBase); ?>

<!-- Status banner -->
<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- Registration window notice -->
<?php if ($semRow): ?>
<div style="padding:12px 16px;border-radius:10px;font-size:13px;
            background:<?= $regOpen?'#dbeafe':'#fef9c3' ?>;
            border:1px solid <?= $regOpen?'#93c5fd':'#fde047' ?>;
            color:<?= $regOpen?'#1e40af':'#854d0e' ?>;">
    <?= $regOpen ? '📝 Đang trong thời gian đăng ký môn học.' : '⏰ ' . h($regMsg) ?>
    <?php if ($semRow['reg_open'] && $semRow['reg_close']): ?>
    <span style="margin-left:8px;opacity:.7;font-size:12px;">
        (<?= date('d/m/Y H:i', strtotime($semRow['reg_open'])) ?> – <?= date('d/m/Y H:i', strtotime($semRow['reg_close'])) ?>)
    </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- My current registrations -->
<?php if (!empty($myEnrollments)): ?>
<div class="card" style="padding:20px 24px;">
    <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:12px;">
        📚 Môn đã đăng ký học kỳ này (<?= count($myEnrollments) ?> môn)
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($myEnrollments as $my): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;background:#f1f5f9;border-radius:8px;font-size:13px;">
            <span style="font-weight:700;color:#001634;"><?= h($my['course_code']) ?></span>
            <span style="color:#64748b;"><?= h($my['section_code']) ?></span>
            <?php if ($regOpen && $my['status'] === 'Enrolled'): ?>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Hủy đăng ký <?= h($my['course_code']) ?>?')">
                <input type="hidden" name="action"     value="drop" />
                <input type="hidden" name="section_id" value="<?= (int)$my['section_id'] ?>" />
                <button type="submit" style="background:#fee2e2;border:none;color:#b91c1c;border-radius:6px;padding:3px 8px;font-size:11px;cursor:pointer;font-weight:600;">✕ Hủy</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Search / filter -->
<div class="filter-ribbon">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <div style="position:relative;">
            <img src="<?= $imgBase ?>kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="q" value="<?= h($searchQ) ?>"
                   placeholder="Tìm mã môn, tên môn, mã lớp..."
                   style="width:280px;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="dept" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả bộ môn</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= (int)$d['department_id'] ?>" <?= $deptF == $d['department_id'] ? 'selected' : '' ?>>
                <?= h($d['department_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" style="height:36px;padding:0 16px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Tìm</button>
        <?php if ($searchQ || $deptF): ?>
        <a href="registration.php" style="height:36px;padding:0 12px;display:flex;align-items:center;background:#f1f5f9;border-radius:8px;font-size:13px;text-decoration:none;color:#44474e;">✕ Xóa lọc</a>
        <?php endif; ?>
    </form>
</div>

<!-- Available sections table -->
<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:12px 20px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#64748b;">
        Tìm thấy <strong><?= count($sections) ?></strong> lớp học phần
    </div>
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Mã môn / Tên môn học</th>
                    <th style="text-align:center;">TC</th>
                    <th>Mã lớp</th>
                    <th>Giảng viên</th>
                    <th>Lịch học</th>
                    <th style="text-align:center;">Sĩ số</th>
                    <th style="text-align:center;">Đăng ký</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sections)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không có lớp học phần nào.
                </td></tr>
                <?php else: ?>
                <?php foreach ($sections as $sec):
                    $isFull      = $sec['capacity'] && $sec['enrolled_count'] >= $sec['capacity'];
                    $isEnrolled  = in_array((int)$sec['section_id'], array_map('intval', $mySectionIds));
                    $sameCourse  = in_array((int)$sec['course_id'],  array_map('intval', $myCourseIds)) && !$isEnrolled;
                    $capColor    = $isFull ? '#ef4444' : ($sec['enrolled_count'] / max(1, $sec['capacity']) > 0.8 ? '#f59e0b' : '#059669');
                ?>
                <tr style="<?= $isEnrolled ? 'background:#f0fdf4;' : '' ?>">
                    <td>
                        <div style="font-weight:700;color:#001634;font-size:13px;"><?= h($sec['course_name']) ?></div>
                        <span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:1px 5px;border-radius:3px;"><?= h($sec['course_code']) ?></span>
                        <?php if ($sec['department_name']): ?>
                        <span style="font-size:11px;color:#94a3b8;margin-left:4px;"><?= h($sec['department_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$sec['credits'] ?></td>
                    <td style="font-family:monospace;font-size:12px;"><?= h($sec['section_code']) ?></td>
                    <td style="font-size:12px;color:#44474e;"><?= h($sec['lecturer_name']) ?></td>
                    <td style="font-size:12px;color:#44474e;"><?= h($sec['schedule'] ?? '—') ?><?= $sec['room'] ? '<br/><span style="font-size:11px;color:#94a3b8;">📍 ' . h($sec['room']) . '</span>' : '' ?></td>
                    <td style="text-align:center;">
                        <span style="font-size:13px;font-weight:700;color:<?= $capColor ?>;">
                            <?= (int)$sec['enrolled_count'] ?>
                        </span>
                        <?php if ($sec['capacity']): ?>
                        <span style="font-size:11px;color:#94a3b8;">/ <?= (int)$sec['capacity'] ?></span>
                        <?php endif; ?>
                        <?php if ($isFull): ?>
                        <span class="status-pill status-pill--absent" style="margin-left:4px;font-size:10px;">Đầy</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($isEnrolled): ?>
                        <span style="color:#059669;font-weight:700;font-size:12px;">✅ Đã đăng ký</span>
                        <?php elseif ($sameCourse): ?>
                        <span style="color:#94a3b8;font-size:12px;" title="Đã đăng ký môn này ở lớp khác">— Trùng môn</span>
                        <?php elseif ($isFull): ?>
                        <span style="color:#ef4444;font-size:12px;">Hết chỗ</span>
                        <?php elseif ($regOpen): ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action"     value="enroll" />
                            <input type="hidden" name="section_id" value="<?= (int)$sec['section_id'] ?>" />
                            <button type="submit"
                                    style="padding:5px 14px;background:#102b50;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;">
                                + Đăng ký
                            </button>
                        </form>
                        <?php else: ?>
                        <span style="color:#94a3b8;font-size:11px;">Ngoài HĐK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></main>
<script>
// Close notification dropdown on outside click
document.addEventListener('click', function(e) {
    var drop = document.getElementById('notifDrop');
    var bell = document.getElementById('notifBell');
    if (drop && bell && !bell.contains(e.target) && !drop.contains(e.target)) {
        drop.style.display = 'none';
    }
});
document.getElementById('notifBell')?.addEventListener('click', function(e) {
    var d = document.getElementById('notifDrop');
    if (d) d.style.display = d.style.display === 'none' ? 'block' : 'none';
    e.stopPropagation();
});
</script>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
