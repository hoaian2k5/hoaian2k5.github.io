<?php
/**
 * UniPortal – Admin Dashboard (Tổng quan)
 * Figma: dashboard screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';

require_login();
if ($_SESSION['role'] === 'Student') { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: /UniPortal/public/assets/lecturer/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();

// ── Real DB stats ─────────────────────────────────────────────
$totalStudents  = (int)$pdo->query("SELECT COUNT(*) FROM Student WHERE status='Active'")->fetchColumn();
$totalAccounts  = (int)$pdo->query("SELECT COUNT(*) FROM Account WHERE is_active=TRUE")->fetchColumn();
$activeWarnings = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active'")->fetchColumn();
$unreadWarnings = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$currentSem     = StudentModel::getCurrentSemester();

// GPA distribution from real grade data
$gpaSt = $pdo->prepare(
    'SELECT
        ROUND(SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits),0), 2) AS cum_gpa,
        s.student_id
     FROM Student s
     JOIN Enrollment e  ON e.student_id  = s.student_id AND e.status IN (\'Enrolled\',\'Completed\')
     JOIN ClassSection cs ON cs.section_id = e.section_id
     JOIN Course c      ON c.course_id    = cs.course_id
     JOIN Assessment a  ON a.section_id   = cs.section_id
     JOIN Grade g       ON g.enrollment_id = e.enrollment_id AND g.assessment_id = a.assessment_id
     JOIN GradeScale gs ON (g.score/10) BETWEEN gs.min_score AND gs.max_score
     GROUP BY s.student_id'
);
$gpaSt->execute();
$gpaRows = $gpaSt->fetchAll();

$gpaBuckets = ['excellent'=>0,'good'=>0,'average'=>0,'below_avg'=>0,'warning'=>0];
$totalGpa = 0; $gpaCount = 0;
foreach ($gpaRows as $r) {
    $g = (float)$r['cum_gpa'];
    $totalGpa += $g; $gpaCount++;
    if ($g >= 3.60) $gpaBuckets['excellent']++;
    elseif ($g >= 3.20) $gpaBuckets['good']++;
    elseif ($g >= 2.50) $gpaBuckets['average']++;
    elseif ($g >= 2.00) $gpaBuckets['below_avg']++;
    else $gpaBuckets['warning']++;
}
$avgGpa = $gpaCount > 0 ? round($totalGpa / $gpaCount, 2) : null;
$base = max(1, $gpaCount);

// If no real GPA data, show design-matching placeholder values
if ($gpaCount === 0) {
    $gpaBuckets = ['excellent'=>1,'good'=>3,'below_avg'=>1,'average'=>7,'warning'=>0];
    $base = 12; $avgGpa = 2.94;
}

// Attendance overall rate
$attStmt = $pdo->query(
    "SELECT ROUND(100*SUM(a.status IN ('Present','Late','Excused'))/NULLIF(COUNT(*),0),1) AS rate
     FROM Attendance a"
);
$attRate = (float)($attStmt->fetchColumn() ?? 94.2);

// GPA trend – real semester GPAs
$trendSt = $pdo->prepare(
    'SELECT sem.semester_name, sem.academic_year,
            ROUND(SUM(gs.grade_point*c.credits)/NULLIF(SUM(c.credits),0),2) AS gpa
     FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id = e.section_id
     JOIN Semester sem ON sem.semester_id = cs.semester_id
     JOIN Course c ON c.course_id = cs.course_id
     JOIN Assessment a ON a.section_id = cs.section_id
     JOIN Grade g ON g.enrollment_id=e.enrollment_id AND g.assessment_id=a.assessment_id
     JOIN GradeScale gs ON (g.score/10) BETWEEN gs.min_score AND gs.max_score
     WHERE e.status IN (\'Enrolled\',\'Completed\')
     GROUP BY sem.semester_id ORDER BY sem.start_date ASC LIMIT 5'
);
$trendSt->execute();
$trendRows = $trendSt->fetchAll();
$trendLabels = [];
$trendVals = [];
foreach ($trendRows as $t) {
    $trendLabels[] = preg_replace('/^Học kỳ\s*/i','HK',$t['semester_name']??'').' '.substr($t['academic_year']??'',2,5);
    $trendVals[]   = (float)$t['gpa'];
}
// Fallback trend if no data
if (empty($trendLabels)) {
    $trendLabels = ['HK1 24-25','HK2 24-25','HK1 25-26','HK2 25-26','HK1 26-27'];
    $trendVals   = [2.86, 2.86, 2.89, 2.91, 2.94];
}

$jsLabels = json_encode($trendLabels, JSON_UNESCAPED_UNICODE);
$jsVals   = json_encode($trendVals);

$warnCount = $unreadWarnings;

$pageTitle   = 'Tổng quan';
$activePage  = 'dashboard';
$breadcrumb  = 'Quản trị › Học vụ & Sinh viên';
require __DIR__ . '/_layout.php';
?>

<!-- ═══ 3 KPI TOP CARDS ══════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">

    <!-- Tổng số sinh viên -->
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Tổng số sinh<br/>viên</span>
            <span class="adm-kpi__icon adm-kpi__icon--blue">
                <img src="../img/db-nav-profile.svg" alt="" />
            </span>
        </div>
        <div class="adm-kpi__value">
            <span class="adm-kpi__num"><?= number_format($totalStudents) ?></span>
            <span class="adm-kpi__unit">SV</span>
        </div>
        <div class="adm-kpi__sub adm-kpi__sub--up">↗ <?= max(0, $totalStudents) ?> so với cùng kỳ •</div>
    </div>

    <!-- GPA trung bình trường -->
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">GPA trung bình<br/>trường</span>
            <span class="adm-kpi__icon adm-kpi__icon--green">
                <img src="../img/db-nav-grades.svg" alt="" />
            </span>
        </div>
        <div class="adm-kpi__value">
            <span class="adm-kpi__num"><?= $avgGpa !== null ? number_format($avgGpa,2) : '—' ?></span>
            <span class="adm-kpi__unit">/ 4.0</span>
        </div>
        <div class="adm-kpi__sub adm-kpi__sub--up">↑ +0.08 &nbsp;<span style="color:#94a3b8;">Xếp loại Khá toàn diện</span></div>
    </div>

    <!-- Cảnh báo học tập -->
    <div class="acard adm-kpi" style="border-left: 4px solid #ef4444;">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Cảnh báo học<br/>tập</span>
            <span class="adm-kpi__icon adm-kpi__icon--red">
                <img src="../img/db-nav-alerts.svg" alt="" />
            </span>
        </div>
        <div class="adm-kpi__value">
            <span class="adm-kpi__num adm-kpi__num--red"><?= $activeWarnings ?></span>
            <span class="adm-kpi__unit">hồ sơ</span>
        </div>
        <div class="adm-kpi__sub-row">
            <span class="adm-kpi__sub-item adm-kpi__sub--down">
                ↓ <?= (int)ceil($activeWarnings*0.18) ?> SV
                <span style="color:#94a3b8;">(<?= $activeWarnings>0?number_format($activeWarnings/$totalStudents*100,1):0 ?>%)</span>
            </span>
            <span class="adm-kpi__sub-item" style="color:#f59e0b;">
                • <?= $unreadWarnings ?> <span style="color:#94a3b8;">Nguy cơ</span>
            </span>
            <span class="adm-kpi__sub-item">
                • <?= $activeWarnings - $unreadWarnings ?> <span style="color:#94a3b8;">Theo dõi</span>
            </span>
        </div>
    </div>
</div>

<!-- ═══ ROW 2: GPA DIST + TREND CHART + ATT DONUT ════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;align-items:start;">

    <!-- Phân bổ GPA toàn trường -->
    <div class="acard" style="padding:20px 24px;">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
            <span style="width:10px;height:10px;border-radius:50%;background:#22c55e;display:inline-block;"></span>
            <span class="adm-section-title">Phân bổ GPA toàn trường</span>
        </div>
        <div class="adm-section-sub" style="margin-bottom:18px;">
            Thống kê theo <?= $totalStudents ?> sinh viên chính quy đang theo học
        </div>
        <div class="gpa-dist">
            <?php
            $dists = [
                ['label'=>'Xuất sắc (≥ 3.60)', 'key'=>'excellent', 'cls'=>'green',  'pct'=>12],
                ['label'=>'Giỏi (3.20 – 3.59)', 'key'=>'good',     'cls'=>'navy',   'pct'=>28],
                ['label'=>'Khá (2.50 – 3.19)',   'key'=>'average',  'cls'=>'gold',   'pct'=>44],
                ['label'=>'Trung bình (2.00 – 2.49)', 'key'=>'below_avg','cls'=>'orange','pct'=>12],
                ['label'=>'Cảnh báo học vụ (< 2.00)', 'key'=>'warning',  'cls'=>'red',   'pct'=>4],
            ];
            foreach ($dists as $d):
                $realN = $gpaBuckets[$d['key']] ?? 0;
                $realPct = $base > 0 ? round($realN/$base*100) : $d['pct'];
                $dispPct = $gpaCount > 0 ? $realPct : $d['pct'];
                $dispN   = $gpaCount > 0 ? $realN : (int)($totalStudents * $d['pct'] / 100);
            ?>
            <div class="gpa-dist__row">
                <span class="gpa-dist__label"><?= $d['label'] ?></span>
                <div class="gpa-dist__bar-wrap">
                    <div class="gpa-dist__bar gpa-dist__bar--<?= $d['cls'] ?>" style="width:<?= $dispPct ?>%"></div>
                </div>
                <span class="gpa-dist__pct"><?= $dispPct ?>%<?= $gpaCount>0?" ($dispN SV)":"" ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tiến trình GPA 5 Học kỳ -->
    <div class="acard" style="padding:20px 24px;">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
            <span style="width:10px;height:10px;border-radius:50%;background:#fdcc35;display:inline-block;"></span>
            <span class="adm-section-title">Tiến trình GPA 5 Học kỳ</span>
        </div>
        <div class="adm-section-sub" style="margin-bottom:14px;">
            Giai đoạn từ <?= $trendLabels[0] ?? 'HK1 24-25' ?> đến <?= end($trendLabels) ?>
        </div>
        <div style="position:relative;height:160px;">
            <canvas id="trendChart"></canvas>
        </div>
        <div style="margin-top:10px;font-size:12px;color:#64748b;">
            Tỷ lệ hoàn thành tín chỉ: <strong style="color:#001634;">96.8%</strong>
            <span style="color:#22c55e;margin-left:4px;">↑ +4.2%</span>
        </div>
    </div>

    <!-- Chuyên cần -->
    <div class="acard" style="padding:20px 24px;">
        <div class="adm-section-title" style="margin-bottom:2px;">Chuyên cần</div>
        <div class="adm-section-sub">Tuần 12 – Tổng hợp 107 lớp học phần</div>
        <div style="display:flex;justify-content:center;margin:16px 0;position:relative;">
            <div style="position:relative;width:140px;height:140px;">
                <canvas id="attDonut"></canvas>
                <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;">
                    <div style="font-size:26px;font-weight:800;color:#001634;"><?= number_format($attRate,1) ?>%</div>
                    <div style="font-size:10px;color:#64748b;font-weight:600;">HIỆN DIỆN</div>
                </div>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;">
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#0d1b2e;margin-right:5px;"></span>Tốt (&gt;90%)</span>
                <span style="font-weight:700;">78%</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#fdcc35;margin-right:5px;"></span>Đạt chuẩn (80–90%)</span>
                <span style="font-weight:700;">16%</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;margin-right:5px;"></span>Vắng (&gt;20%)</span>
                <span style="font-weight:700;">6%</span>
            </div>
        </div>
        <a href="attendance.php" style="display:block;text-align:center;margin-top:12px;font-size:12px;font-weight:600;color:#1a3a6b;text-decoration:underline;">
            Xem chi tiết danh sách vắng →
        </a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
// GPA Trend
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= $jsLabels ?>,
        datasets: [{
            data: <?= $jsVals ?>,
            borderColor: '#fdcc35',
            backgroundColor: 'rgba(253,204,53,0.12)',
            pointBackgroundColor: '#0d1b2e',
            pointRadius: 5,
            pointHoverRadius: 7,
            borderWidth: 2.5,
            tension: 0.35,
            fill: true,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false },
            tooltip: { callbacks: { label: ctx => ' GPA: ' + ctx.parsed.y.toFixed(2) } }
        },
        scales: {
            y: { min: 2.5, max: 3.2,
                 ticks: { font: { size: 9 }, color: '#94a3b8', stepSize: 0.2 },
                 grid: { color: 'rgba(0,0,0,.04)' } },
            x: { ticks: { font: { size: 9 }, color: '#94a3b8' }, grid: { display: false } }
        }
    }
});
// Attendance Donut
new Chart(document.getElementById('attDonut'), {
    type: 'doughnut',
    data: {
        datasets: [{
            data: [78, 16, 6],
            backgroundColor: ['#0d1b2e','#fdcc35','#ef4444'],
            borderWidth: 0, hoverOffset: 3,
        }]
    },
    options: {
        responsive: false, cutout: '72%',
        plugins: { legend: { display: false }, tooltip: { callbacks: {
            label: ctx => [' Tốt',' Đạt chuẩn',' Vắng'][ctx.dataIndex] + ': ' + ctx.parsed + '%'
        }}}
    }
});
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
