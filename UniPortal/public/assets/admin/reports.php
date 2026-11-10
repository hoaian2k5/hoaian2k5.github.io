<?php
/**
 * UniPortal – Báo cáo & Thống kê (Admin)
 * Grade management: view per section, add/edit grades, academic warnings.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: /UniPortal/public/assets/lecturer/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();

// ── POST: save grade ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_grade') {
        $enrollmentId  = (int)($_POST['enrollment_id']  ?? 0);
        $assessmentId  = (int)($_POST['assessment_id']  ?? 0);
        $score         = trim($_POST['score']           ?? '');
        $feedback      = trim($_POST['feedback']        ?? '');
        $adminId       = (int)($_SESSION['account_id']  ?? 0);

        if ($enrollmentId < 1 || $assessmentId < 1 || $score === '') {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Vui lòng nhập đầy đủ thông tin điểm.'];
        } else {
            $scoreF = (float)$score;
            if ($scoreF < 0 || $scoreF > 100) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Điểm phải nằm trong khoảng 0–100.'];
            } else {
                $pdo->prepare(
                    'INSERT INTO Grade (enrollment_id,assessment_id,score,feedback,graded_by,graded_at)
                     VALUES (:eid,:aid,:score,:fb,:by,NOW())
                     ON DUPLICATE KEY UPDATE score=:score2,feedback=:fb2,graded_at=NOW()'
                )->execute([
                    ':eid'    => $enrollmentId,
                    ':aid'    => $assessmentId,
                    ':score'  => $scoreF,
                    ':fb'     => $feedback,
                    ':by'     => $adminId,
                    ':score2' => $scoreF,
                    ':fb2'    => $feedback,
                ]);
                $_SESSION['_flash'] = ['type'=>'success','message'=>'Đã lưu điểm thành công.'];
            }
        }
        $redir = 'reports.php?tab=grades';
        if (isset($_POST['section_id'])) $redir .= '&section=' . (int)$_POST['section_id'];
        header('Location: ' . $redir); exit;
    }

    if ($action === 'add_warning') {
        $sid   = trim($_POST['student_id']    ?? '');
        $type  = $_POST['warning_type']       ?? '';
        $msg   = trim($_POST['message']       ?? '');
        $valid = ['Attendance','LowGrade','LowGPA','GraduationRisk'];
        if ($sid==='' || !in_array($type,$valid) || $msg==='') {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Điền đầy đủ: MSSV, loại cảnh báo, nội dung.'];
        } else {
            $ck = $pdo->prepare('SELECT 1 FROM Student WHERE student_id=:s LIMIT 1');
            $ck->execute([':s'=>$sid]);
            if (!$ck->fetch()) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>"Không tìm thấy sinh viên $sid."];
            } else {
                $pdo->prepare(
                    "INSERT INTO AcademicWarning (student_id,warning_type,message,status,is_read)
                     VALUES (:sid,:type,:msg,'Active',FALSE)"
                )->execute([':sid'=>$sid,':type'=>$type,':msg'=>$msg]);
                $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã thêm cảnh báo cho sinh viên $sid."];
            }
        }
        header('Location: reports.php?tab=warnings'); exit;
    }

    if ($action === 'resolve_warning') {
        $wid = (int)($_POST['warning_id'] ?? 0);
        if ($wid > 0) {
            $pdo->prepare("UPDATE AcademicWarning SET status='Resolved',is_read=TRUE WHERE warning_id=:wid")
                ->execute([':wid'=>$wid]);
            $_SESSION['_flash'] = ['type'=>'success','message'=>'Đã đánh dấu cảnh báo là đã giải quyết.'];
        }
        header('Location: reports.php?tab=warnings'); exit;
    }
}

// ── Flash ─────────────────────────────────────────────────────
$flash = '';
if (isset($_SESSION['_flash'])) { $flash=$_SESSION['_flash']['type'].':'.$_SESSION['_flash']['message']; unset($_SESSION['_flash']); }

$tab = $_GET['tab'] ?? 'overview';

// ── Helper ────────────────────────────────────────────────────
function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

// ── Overview stats ────────────────────────────────────────────
$totalStudents  = (int)$pdo->query("SELECT COUNT(*) FROM Student WHERE status='Active'")->fetchColumn();
$totalGrades    = (int)$pdo->query("SELECT COUNT(*) FROM Grade WHERE score IS NOT NULL")->fetchColumn();
$totalWarnings  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active'")->fetchColumn();
$unreadWarnings = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();

$avgGpaRow = $pdo->query(
    "SELECT ROUND(AVG(sub.gpa),2) AS avg FROM (
        SELECT ROUND(SUM(gs.grade_point*c.credits)/NULLIF(SUM(c.credits),0),2) AS gpa
        FROM Enrollment e
        JOIN ClassSection cs ON cs.section_id=e.section_id
        JOIN Course c ON c.course_id=cs.course_id
        JOIN Assessment a ON a.section_id=cs.section_id
        JOIN Grade g ON g.enrollment_id=e.enrollment_id AND g.assessment_id=a.assessment_id
        JOIN GradeScale gs ON (g.score/10) BETWEEN gs.min_score AND gs.max_score
        WHERE e.status IN ('Enrolled','Completed')
        GROUP BY e.student_id
    ) sub"
)->fetch();
$avgGpa = $avgGpaRow['avg'] ?? null;

// ── Grades tab data ───────────────────────────────────────────
$selSection = (int)($_GET['section'] ?? 0);
$semesters  = $pdo->query('SELECT semester_id,semester_name,academic_year FROM Semester ORDER BY start_date DESC')->fetchAll();
$selSem     = (int)($_GET['sem'] ?? 0);
if (!$selSem) {
    $actSem = $pdo->query("SELECT semester_id FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1")->fetch();
    $selSem = (int)($actSem['semester_id'] ?? 0);
}

$sections = [];
if ($selSem) {
    $secSt = $pdo->prepare(
        'SELECT cs.section_id,cs.section_code,c.course_name,c.course_code,l.full_name AS lec_name
         FROM ClassSection cs JOIN Course c ON c.course_id=cs.course_id
         JOIN Lecturer l ON l.lecturer_id=cs.lecturer_id
         WHERE cs.semester_id=:sem ORDER BY cs.section_code'
    );
    $secSt->execute([':sem'=>$selSem]);
    $sections = $secSt->fetchAll();
}
if (!$selSection && !empty($sections)) $selSection=(int)$sections[0]['section_id'];

$assessments = []; $gradeRows = [];
if ($selSection) {
    $asSt = $pdo->prepare('SELECT assessment_id,assessment_name,weight_percent,max_score,due_date FROM Assessment WHERE section_id=:sid ORDER BY due_date');
    $asSt->execute([':sid'=>$selSection]);
    $assessments = $asSt->fetchAll();

    // All students + their grades per assessment
    $grSt = $pdo->prepare(
        'SELECT s.student_id, s.full_name, e.enrollment_id,
                GROUP_CONCAT(CONCAT(g.assessment_id,\':\',IFNULL(g.score,\'\')) ORDER BY g.assessment_id SEPARATOR \'|\') AS grade_data
         FROM Enrollment e
         JOIN Student s ON s.student_id=e.student_id
         LEFT JOIN Grade g ON g.enrollment_id=e.enrollment_id
         WHERE e.section_id=:sid AND e.status IN (\'Enrolled\',\'Completed\')
         GROUP BY e.enrollment_id, s.student_id, s.full_name
         ORDER BY s.student_id'
    );
    $grSt->execute([':sid'=>$selSection]);
    $gradeRows = $grSt->fetchAll();
    // Parse grade_data into map [enrollment_id][assessment_id] = score
    foreach ($gradeRows as &$row) {
        $row['scores'] = [];
        if ($row['grade_data']) {
            foreach (explode('|', $row['grade_data']) as $pair) {
                [$aid, $sc] = explode(':', $pair, 2);
                $row['scores'][(int)$aid] = $sc !== '' ? (float)$sc : null;
            }
        }
    }
    unset($row);
}

// ── Warnings tab data ─────────────────────────────────────────
$wFilter = $_GET['wf'] ?? 'active';
$wSearch = trim($_GET['wq'] ?? '');
$wType   = $_GET['wtype'] ?? '';

$wWhere = "WHERE 1=1"; $wParams = [];
if ($wFilter==='active')   $wWhere.=" AND aw.status='Active'";
if ($wFilter==='resolved') $wWhere.=" AND aw.status='Resolved'";
if ($wFilter==='unread')   $wWhere.=" AND aw.is_read=FALSE";
if ($wType!=='')  { $wWhere.=" AND aw.warning_type=:wtype"; $wParams[':wtype']=$wType; }
if ($wSearch!=='') { $wWhere.=" AND (s.full_name LIKE :wq OR s.student_id LIKE :wq)"; $wParams[':wq']='%'.$wSearch.'%'; }

$wSt = $pdo->prepare("SELECT aw.*,s.full_name,s.class_code FROM AcademicWarning aw JOIN Student s ON s.student_id=aw.student_id $wWhere ORDER BY aw.is_read ASC, aw.created_at DESC LIMIT 200");
$wSt->execute($wParams);
$warnings = $wSt->fetchAll();

$students = $pdo->query('SELECT student_id,full_name FROM Student WHERE status=\'Active\' ORDER BY student_id')->fetchAll();

$typeLabels = ['Attendance'=>'Chuyên cần','LowGrade'=>'Điểm thấp','LowGPA'=>'GPA thấp','GraduationRisk'=>'Tốt nghiệp'];

$warnCount  = $unreadWarnings;
$pageTitle  = 'Báo cáo & Thống kê';
$activePage = 'reports';
$breadcrumb = 'Quản trị › Báo cáo & Thống kê';
require __DIR__ . '/_layout.php';
?>

<!-- Flash -->
<?php if ($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:4px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success'?'✅':'⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<h1 style="font-size:22px;font-weight:800;color:#0d1b2e;">Báo cáo &amp; Thống kê</h1>

<!-- Tabs -->
<div class="adm-tabs" style="background:#fff;border-radius:12px;padding:0 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
    <a href="reports.php?tab=overview"  class="adm-tab <?= $tab==='overview' ?'active':'' ?>">📊 Tổng quan</a>
    <a href="reports.php?tab=grades"    class="adm-tab <?= $tab==='grades'   ?'active':'' ?>">📝 Quản lý điểm</a>
    <a href="reports.php?tab=warnings"  class="adm-tab <?= $tab==='warnings' ?'active':'' ?>">
        ⚠️ Cảnh báo học vụ <?php if ($unreadWarnings>0): ?><span class="apill apill--red" style="font-size:10px;margin-left:4px;"><?= $unreadWarnings ?></span><?php endif; ?>
    </a>
</div>

<?php if ($tab === 'overview'): ?>
<!-- ── OVERVIEW ──────────────────────────────────────────────── -->
<div class="adm-kpi-row">
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Sinh viên đang học</span><span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-profile.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= number_format($totalStudents) ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Bản ghi điểm</span><span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-nav-grades.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= number_format($totalGrades) ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">GPA trung bình</span><span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-icon-gpa-card.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $avgGpa!==null?number_format((float)$avgGpa,2):'—' ?></span><span class="adm-kpi__unit">/ 4.0</span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Cảnh báo học vụ</span><span class="adm-kpi__icon adm-kpi__icon--red"><img src="../img/db-nav-alerts.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num adm-kpi__num--red"><?= $totalWarnings ?></span></div>
        <div class="adm-kpi__sub" style="color:#f59e0b;"><?= $unreadWarnings ?> chưa đọc</div></div>
</div>

<!-- GPA distribution -->
<?php
$gpaDist = $pdo->query(
    "SELECT
        SUM(CASE WHEN sub.gpa>=3.60 THEN 1 ELSE 0 END) AS excellent,
        SUM(CASE WHEN sub.gpa>=3.20 AND sub.gpa<3.60 THEN 1 ELSE 0 END) AS good,
        SUM(CASE WHEN sub.gpa>=2.50 AND sub.gpa<3.20 THEN 1 ELSE 0 END) AS average,
        SUM(CASE WHEN sub.gpa>=2.00 AND sub.gpa<2.50 THEN 1 ELSE 0 END) AS below,
        SUM(CASE WHEN sub.gpa<2.00 THEN 1 ELSE 0 END) AS warning
     FROM (
        SELECT ROUND(SUM(gs.grade_point*c.credits)/NULLIF(SUM(c.credits),0),2) AS gpa
        FROM Enrollment e
        JOIN ClassSection cs ON cs.section_id=e.section_id
        JOIN Course c ON c.course_id=cs.course_id
        JOIN Assessment a ON a.section_id=cs.section_id
        JOIN Grade g ON g.enrollment_id=e.enrollment_id AND g.assessment_id=a.assessment_id
        JOIN GradeScale gs ON (g.score/10) BETWEEN gs.min_score AND gs.max_score
        WHERE e.status IN ('Enrolled','Completed')
        GROUP BY e.student_id
     ) sub"
)->fetch();
$total = array_sum($gpaDist) ?: 1;
$distItems = [
    ['Xuất sắc ≥ 3.60','excellent','#22c55e'],
    ['Giỏi 3.20–3.59','good','#3b82f6'],
    ['Khá 2.50–3.19','average','#f59e0b'],
    ['Trung bình 2.00–2.49','below','#f97316'],
    ['Yếu < 2.00','warning','#ef4444'],
];
?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="acard" style="padding:20px 24px;">
        <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:16px;">Phân bổ GPA toàn trường</div>
        <?php foreach ($distItems as [$label,$key,$color]): $n=(int)($gpaDist[$key]??0); $pct=$total>0?round($n/$total*100):0; ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
            <span style="font-size:12px;color:#44474e;width:160px;flex-shrink:0;"><?= $label ?></span>
            <div style="flex:1;height:8px;background:#e5eeff;border-radius:9999px;overflow:hidden;">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:9999px;"></div>
            </div>
            <span style="font-size:12px;font-weight:700;color:<?= $color ?>;width:60px;text-align:right;"><?= $pct ?>% (<?= $n ?>)</span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="acard" style="padding:20px 24px;">
        <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:16px;">Cảnh báo học vụ theo loại</div>
        <?php
        $wByType = $pdo->query("SELECT warning_type, COUNT(*) AS cnt FROM AcademicWarning WHERE status='Active' GROUP BY warning_type")->fetchAll();
        $wMap = array_column($wByType, 'cnt', 'warning_type');
        $wTotal = max(1, array_sum(array_column($wByType, 'cnt')));
        foreach ($typeLabels as $k=>$v):
            $wn=(int)($wMap[$k]??0); $wp=$wTotal>0?round($wn/$wTotal*100):0;
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
            <span style="font-size:12px;color:#44474e;width:160px;flex-shrink:0;"><?= $v ?></span>
            <div style="flex:1;height:8px;background:#fee2e2;border-radius:9999px;overflow:hidden;">
                <div style="height:100%;width:<?= $wp ?>%;background:#ef4444;border-radius:9999px;"></div>
            </div>
            <span style="font-size:12px;font-weight:700;color:#ef4444;width:60px;text-align:right;"><?= $wn ?></span>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:16px;"><a href="reports.php?tab=warnings" class="abtn abtn--primary abtn--sm" style="background:#0d1b2e;">Quản lý cảnh báo →</a></div>
    </div>
</div>

<!-- Top students by GPA -->
<?php
$topStudents = $pdo->query(
    "SELECT s.student_id, s.full_name, s.class_code,
            ROUND(SUM(gs.grade_point*c.credits)/NULLIF(SUM(c.credits),0),2) AS cum_gpa
     FROM Student s
     JOIN Enrollment e ON e.student_id=s.student_id AND e.status IN ('Enrolled','Completed')
     JOIN ClassSection cs ON cs.section_id=e.section_id
     JOIN Course c ON c.course_id=cs.course_id
     JOIN Assessment a ON a.section_id=cs.section_id
     JOIN Grade g ON g.enrollment_id=e.enrollment_id AND g.assessment_id=a.assessment_id
     JOIN GradeScale gs ON (g.score/10) BETWEEN gs.min_score AND gs.max_score
     GROUP BY s.student_id HAVING cum_gpa IS NOT NULL ORDER BY cum_gpa DESC LIMIT 8"
)->fetchAll();
?>
<div class="acard" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;font-size:15px;font-weight:700;color:#001634;">🏆 Sinh viên có GPA cao nhất</div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>Hạng</th><th>Sinh viên</th><th>Lớp</th><th>GPA tích lũy</th><th>Xếp loại</th></tr></thead>
            <tbody>
                <?php if (empty($topStudents)): ?>
                <tr><td colspan="5" style="text-align:center;padding:24px;color:#94a3b8;">Chưa có dữ liệu GPA.</td></tr>
                <?php else: ?>
                <?php foreach ($topStudents as $i=>$sv): $g=(float)$sv['cum_gpa']; ?>
                <tr>
                    <td style="text-align:center;font-weight:700;color:<?= $i===0?'#f59e0b':($i===1?'#94a3b8':'#cd7f32') ?>;"><?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':($i+1))) ?></td>
                    <td style="font-weight:600;color:#0d1b2e;"><?= h($sv['full_name']) ?><div style="font-size:11px;color:#94a3b8;"><?= h($sv['student_id']) ?></div></td>
                    <td style="font-size:12px;"><?= h($sv['class_code']) ?></td>
                    <td><span style="font-size:18px;font-weight:800;color:<?= $g>=3.6?'#22c55e':($g>=3.2?'#3b82f6':'#f59e0b') ?>;"><?= number_format($g,2) ?></span><span style="font-size:11px;color:#94a3b8;"> / 4.0</span></td>
                    <td><span class="apill <?= $g>=3.6?'apill--green':($g>=3.2?'apill--blue':'') ?>"><?= $g>=3.6?'Xuất sắc':($g>=3.2?'Giỏi':($g>=2.5?'Khá':($g>=2.0?'Trung bình':'Yếu'))) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'grades'): ?>
<!-- ── GRADES TAB ─────────────────────────────────────────────── -->
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="tab" value="grades" />
        <select name="sem" onchange="this.form.submit()" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <?php foreach ($semesters as $s): ?>
            <option value="<?= (int)$s['semester_id'] ?>" <?= (int)$s['semester_id']===$selSem?'selected':'' ?>>
                <?= h($s['semester_name'].' '.$s['academic_year']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($sections)): ?>
        <select name="section" onchange="this.form.submit()" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <?php foreach ($sections as $s): ?>
            <option value="<?= (int)$s['section_id'] ?>" <?= (int)$s['section_id']===$selSection?'selected':'' ?>>
                <?= h($s['section_code'].' – '.$s['course_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($sections)): ?>
<div class="acard"><div style="text-align:center;padding:40px;color:#94a3b8;">Không có lớp học phần trong học kỳ này.</div></div>
<?php elseif (empty($assessments)): ?>
<div class="acard"><div style="text-align:center;padding:40px;color:#94a3b8;">Lớp này chưa có bài đánh giá nào.</div></div>
<?php else: ?>
<?php
// Find section info
$secInfo = null;
foreach ($sections as $s) { if ((int)$s['section_id']===$selSection) { $secInfo=$s; break; } }
?>
<?php if ($secInfo): ?>
<div style="font-size:13px;color:#64748b;padding:4px 0;">
    Lớp: <strong><?= h($secInfo['section_code']) ?></strong> – <?= h($secInfo['course_name']) ?> | GV: <?= h($secInfo['lec_name']) ?>
</div>
<?php endif; ?>
<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap" style="overflow-x:auto;">
        <form method="POST">
            <input type="hidden" name="_action"    value="save_grade" />
            <input type="hidden" name="section_id" value="<?= $selSection ?>" />
            <table class="adm-table" style="min-width:700px;">
                <thead>
                    <tr>
                        <th style="position:sticky;left:0;background:#f8fafc;z-index:2;">SINH VIÊN</th>
                        <?php foreach ($assessments as $a): ?>
                        <th style="text-align:center;white-space:nowrap;font-size:11px;">
                            <?= h($a['assessment_name']) ?><br/>
                            <span style="font-weight:400;color:#94a3b8;"><?= number_format((float)$a['weight_percent'],0) ?>% • max <?= number_format((float)$a['max_score'],0) ?></span>
                        </th>
                        <?php endforeach; ?>
                        <th style="text-align:center;">GPA ước tính</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($gradeRows)): ?>
                    <tr><td colspan="<?= count($assessments)+2 ?>" style="text-align:center;padding:32px;color:#94a3b8;">Không có sinh viên.</td></tr>
                    <?php else: ?>
                    <?php foreach ($gradeRows as $row):
                        // Estimate weighted score
                        $wScore = null; $wSum = 0; $wDen = 0;
                        foreach ($assessments as $a) {
                            $sc = $row['scores'][(int)$a['assessment_id']] ?? null;
                            if ($sc !== null) { $wSum += ($sc/10) * (float)$a['weight_percent']; $wDen += (float)$a['weight_percent']; }
                        }
                        if ($wDen > 0) $wScore = round($wSum / 100, 2);
                    ?>
                    <tr>
                        <td style="position:sticky;left:0;background:#fff;z-index:1;min-width:180px;">
                            <div style="font-weight:600;font-size:13px;color:#0d1b2e;"><?= h($row['full_name']) ?></div>
                            <div style="font-size:11px;color:#94a3b8;"><?= h($row['student_id']) ?></div>
                        </td>
                        <?php foreach ($assessments as $a): $sc=$row['scores'][(int)$a['assessment_id']]??null; ?>
                        <td style="text-align:center;padding:6px 4px;">
                            <input type="number" name="scores[<?= (int)$row['enrollment_id'] ?>][<?= (int)$a['assessment_id'] ?>]"
                                   value="<?= $sc!==null?number_format($sc,1):'' ?>"
                                   min="0" max="100" step="0.5" placeholder="—"
                                   style="width:64px;text-align:center;border:1px solid #e2e8f0;border-radius:6px;padding:4px;font-size:12px;outline:none;" />
                        </td>
                        <?php endforeach; ?>
                        <td style="text-align:center;font-weight:700;color:<?= $wScore!==null?($wScore>=3.2?'#22c55e':($wScore>=2.5?'#3b82f6':'#ef4444')):'#94a3b8' ?>;">
                            <?= $wScore!==null?number_format($wScore,2):'—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (!empty($gradeRows)): ?>
            <div style="padding:12px 16px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:8px;">
                <?php
                // Individual save buttons per assessment
                foreach ($assessments as $a):
                ?>
                <button type="submit" name="assessment_id" value="<?= (int)$a['assessment_id'] ?>"
                        formaction="reports.php?tab=grades&section=<?= $selSection ?>"
                        class="abtn abtn--outline abtn--sm"
                        onclick="prepareSave(<?= (int)$a['assessment_id'] ?>)">
                    💾 Lưu <?= h($a['assessment_name']) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<div style="font-size:12px;color:#94a3b8;padding:4px 0;">
    * Điểm nhập theo thang 0–100. Nhấn nút "Lưu" cho từng cột để lưu điểm bài đánh giá tương ứng.
</div>
<?php endif; ?>

<?php elseif ($tab === 'warnings'): ?>
<!-- ── WARNINGS TAB ───────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <h2 style="font-size:16px;font-weight:700;color:#0d1b2e;">Quản lý cảnh báo học vụ</h2>
    <button onclick="showModal('modalAddWarning')" class="abtn abtn--primary" style="background:#b91c1c;">⚠️ Thêm cảnh báo</button>
</div>

<!-- Filters -->
<div class="acard" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="tab" value="warnings" />
        <div style="position:relative;flex:0 0 220px;">
            <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="wq" value="<?= h($wSearch) ?>" placeholder="Tìm tên, MSSV..."
                   style="width:100%;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="wf" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="all"      <?= $wFilter==='all'      ?'selected':'' ?>>Tất cả</option>
            <option value="active"   <?= $wFilter==='active'   ?'selected':'' ?>>Đang hoạt động</option>
            <option value="unread"   <?= $wFilter==='unread'   ?'selected':'' ?>>Chưa đọc</option>
            <option value="resolved" <?= $wFilter==='resolved' ?'selected':'' ?>>Đã giải quyết</option>
        </select>
        <select name="wtype" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả loại</option>
            <?php foreach ($typeLabels as $k=>$v): ?><option value="<?= $k ?>" <?= $wType===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="abtn abtn--primary abtn--sm">🔍 Lọc</button>
        <?php if ($wSearch||$wType||$wFilter!=='all'): ?><a href="reports.php?tab=warnings" class="abtn abtn--outline abtn--sm">✕</a><?php endif; ?>
    </form>
</div>

<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>SINH VIÊN</th><th>LOẠI CẢNH BÁO</th><th>NỘI DUNG</th><th>NGÀY TẠO</th><th>TRẠNG THÁI</th><th>THAO TÁC</th></tr></thead>
            <tbody>
                <?php if (empty($warnings)): ?>
                <tr><td colspan="6" style="text-align:center;padding:32px;color:#94a3b8;">Không có cảnh báo nào.</td></tr>
                <?php else: ?>
                <?php foreach ($warnings as $w): $tl=$typeLabels[$w['warning_type']]??$w['warning_type']; ?>
                <tr style="<?= !$w['is_read']?'background:#fffbeb;':'' ?>">
                    <td>
                        <div style="font-weight:600;color:#0d1b2e;font-size:13px;"><?= h($w['full_name']) ?></div>
                        <div style="font-size:11px;color:#94a3b8;"><?= h($w['student_id']) ?> • <?= h($w['class_code']) ?></div>
                    </td>
                    <td><span class="apill apill--<?= in_array($w['warning_type'],['LowGPA','GraduationRisk'])?'red':'orange' ?>"><?= h($tl) ?></span></td>
                    <td style="font-size:12px;color:#44474e;max-width:240px;"><?= h(mb_strimwidth($w['message'],0,100,'…')) ?></td>
                    <td style="font-size:11px;color:#94a3b8;white-space:nowrap;"><?= date('d/m/Y H:i',strtotime($w['created_at'])) ?></td>
                    <td>
                        <?php if ($w['status']==='Resolved'): ?>
                        <span class="apill apill--green" style="font-size:10px;">✅ Đã giải quyết</span>
                        <?php elseif ($w['is_read']): ?>
                        <span class="apill apill--gray" style="font-size:10px;">Đang theo dõi</span>
                        <?php else: ?>
                        <span class="apill apill--red" style="font-size:10px;">● Chưa đọc</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($w['status']==='Active'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Đánh dấu đã giải quyết?')">
                            <input type="hidden" name="_action"    value="resolve_warning" />
                            <input type="hidden" name="warning_id" value="<?= (int)$w['warning_id'] ?>" />
                            <button type="submit" class="abtn abtn--green abtn--sm">✅ Giải quyết</button>
                        </form>
                        <?php else: ?>
                        <span style="font-size:12px;color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="padding:10px 16px;font-size:12px;color:#64748b;border-top:1px solid #f1f5f9;">
        Hiển thị <?= count($warnings) ?> cảnh báo
    </div>
</div>

<!-- MODAL: Add warning -->
<div id="modalAddWarning" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:480px;padding:28px;position:relative;">
    <button onclick="closeModal('modalAddWarning')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#b91c1c;margin-bottom:20px;">⚠️ Thêm cảnh báo học vụ</h2>
    <form method="POST">
        <input type="hidden" name="_action" value="add_warning" />
        <div style="display:grid;gap:12px;">
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Sinh viên *</label>
                <select name="student_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn sinh viên —</option>
                    <?php foreach ($students as $sv): ?><option value="<?= h($sv['student_id']) ?>"><?= h($sv['student_id'].' – '.$sv['full_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Loại cảnh báo *</label>
                <select name="warning_type" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($typeLabels as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Nội dung cảnh báo *</label>
                <textarea name="message" rows="3" required style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none;resize:vertical;"></textarea></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalAddWarning')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#b91c1c;">Thêm cảnh báo</button>
        </div>
    </form>
</div></div>

<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
function showModal(id){document.getElementById(id).style.display='flex';document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).style.display='none';document.body.style.overflow='';}
['modalAddWarning'].forEach(function(id){
    var el=document.getElementById(id);
    if(el)el.addEventListener('click',function(e){if(e.target===this)closeModal(id);});
});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal('modalAddWarning');});

// Grade save: set hidden fields based on which assessment column is being saved
function prepareSave(assessmentId) {
    // Collect only values for this assessment
    var form = document.querySelector('form[action*="reports"]') || document.forms[0];
    // handled server-side via button value
}
</script>
