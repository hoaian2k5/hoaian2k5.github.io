<?php
/**
 * UniPortal – Kết quả học tập (GPA / Grades)
 * Figma frame: KQHT (1:479)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/models/Gpa.php';

require_student();

if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$student_id = $_SESSION['student_id'] ?? '';
$full_name  = $_SESSION['full_name']  ?? '';
$class_code = $_SESSION['class_code'] ?? '';

// Current semester
$currentSem = StudentModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);

// Get all semesters for the filter dropdown
$pdo        = get_db();
$allSems    = $pdo->prepare('SELECT semester_id,semester_name,academic_year FROM Semester ORDER BY start_date DESC');
$allSems->execute();
$semesters  = $allSems->fetchAll();

// Selected semester from filter (default = current)
$selSemId = isset($_GET['sem']) ? (int)$_GET['sem'] : $semId;

// Cumulative data
$cumData       = GpaModel::getCumulative($student_id);
$cumulativeGpa = $cumData['cumulative_gpa'];
$earnedCredits = $cumData['earned_credits'];
$classification = GpaModel::getClassification($cumulativeGpa);

// GPA history for chart
$gpaHistory = GpaModel::getGpaHistory($student_id);

// Grades for selected semester (detailed)
function getGradesForSemester(string $student_id, int $semester_id): array {
    if (!$semester_id) return [];
    $pdo  = get_db();
    // Fix: aggregate functions cannot appear in JOIN ON clauses (MySQL error 1111).
    // Two-step approach:
    //   Step 1 – compute the weighted score per enrollment (aggregated subquery).
    //   Step 2 – join GradeScale on the pre-computed score in the outer query.
    $stmt = $pdo->prepare(
        'SELECT
            base.course_code,
            base.course_name,
            base.credits,
            base.enroll_status,
            base.enrollment_id,
            base.section_code,
            base.lecturer_name,
            base.weighted_score,
            gs.letter_grade,
            gs.grade_point
         FROM (
             SELECT
                 c.course_code,
                 c.course_name,
                 c.credits,
                 e.status        AS enroll_status,
                 e.enrollment_id,
                 cs.section_code,
                 l.full_name     AS lecturer_name,
                 ROUND(
                     SUM(g.score * a.weight_percent / 100) / 10,
                     1
                 )               AS weighted_score
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id   = e.section_id
             JOIN Course c        ON c.course_id     = cs.course_id
             JOIN Lecturer l      ON l.lecturer_id   = cs.lecturer_id
             LEFT JOIN Grade g    ON g.enrollment_id = e.enrollment_id
             LEFT JOIN Assessment a ON a.assessment_id = g.assessment_id
             WHERE e.student_id = :sid AND cs.semester_id = :sem
             GROUP BY
                 e.enrollment_id,
                 c.course_code, c.course_name, c.credits,
                 e.status, cs.section_code, l.full_name
         ) AS base
         LEFT JOIN GradeScale gs
             ON base.weighted_score IS NOT NULL
            AND base.weighted_score >= gs.min_score
            AND (base.weighted_score <  gs.max_score
                 OR gs.max_score = 10.0)
         ORDER BY base.course_code ASC'
    );
    $stmt->execute([':sid' => $student_id, ':sem' => $semester_id]);
    return $stmt->fetchAll();
}

$grades    = getGradesForSemester($student_id, $selSemId ?: $semId);
$semGpa    = $selSemId ? GpaModel::getSemesterGpa($student_id, $selSemId) : null;

// Chart data
$chartLabels = $chartSemGpa = $chartCumGpa = [];
$rCred = $rPts = 0;
foreach ($gpaHistory as $r) {
    $yr = $r['academic_year'] ?? '';
    $chartLabels[] = preg_replace('/^Học kỳ\s*/i','HK',$r['semester_name']??'').' ('.$yr.')';
    $chartSemGpa[] = (float)($r['semester_gpa'] ?? 0);
    $rCred += (int)($r['credits_this_sem'] ?? 0);
    $rPts  += (float)($r['semester_gpa'] ?? 0) * (int)($r['credits_this_sem'] ?? 1);
    $chartCumGpa[] = $rCred > 0 ? round($rPts/$rCred,2) : 0;
}

function h($v):string{ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function gf($v):string{ return $v !== null ? number_format((float)$v,2) : '—'; }

function gradeBadgeClass(?string $letter):string {
    if (!$letter) return '';
    $l = strtoupper($letter[0]);
    return match($l) { 'A' => 'grade-badge--A', 'B' => 'grade-badge--B',
                        'C' => 'grade-badge--C', 'D' => 'grade-badge--D',
                        'F' => 'grade-badge--F', default => '' };
}

$pageTitle  = 'Kết quả học tập';
$activePage = 'gpa';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<!-- Page banner -->
<?php page_banner(
    'Kết quả học tập',
    'Tra cứu điểm số, GPA theo từng học kỳ và toàn khoá học',
    'kqht-icon-diploma.svg', $imgBase,
    [
        ['label'=>'Xuất PDF','icon'=>'kqht-icon-dl.svg','href'=>'#','style'=>'background:#e5eeff;color:#102b50;'],
        ['label'=>'In bảng điểm','icon'=>'kqht-icon-print.svg','href'=>'#','class'=>'btn-primary','style'=>'background:#001634;color:#fff;'],
    ]
); ?>

<!-- Summary KPI cards -->
<div class="kpi-row" style="grid-template-columns:repeat(3,1fr);">
    <div class="card kpi-card">
        <div class="kpi-card__header">
            <span class="kpi-card__label">GPA Tích lũy</span>
            <span class="kpi-card__badge kpi-card__badge--green"><?= h($classification) ?></span>
        </div>
        <div class="kpi-card__value">
            <span class="kpi-card__num"><?= gf($cumulativeGpa) ?></span>
            <span class="kpi-card__unit">/ 4.00</span>
        </div>
        <div class="kpi-card__footer">
            <div class="progress-bar">
                <div class="progress-bar__fill progress-bar__fill--green"
                     style="width:<?= $cumulativeGpa !== null ? round($cumulativeGpa/4*100,1) : 0 ?>%"></div>
            </div>
            <div class="kpi-card__meta">Mục tiêu tốt nghiệp Giỏi: ≥ 3.20</div>
        </div>
    </div>
    <div class="card kpi-card">
        <div class="kpi-card__header">
            <span class="kpi-card__label">GPA học kỳ <?= $selSemId && $selSemId !== $semId ? 'đã chọn' : 'hiện tại' ?></span>
            <span class="kpi-card__badge kpi-card__badge--blue"><?= $semGpa !== null ? GpaModel::getClassification($semGpa) : '—' ?></span>
        </div>
        <div class="kpi-card__value">
            <span class="kpi-card__num"><?= gf($semGpa) ?></span>
            <span class="kpi-card__unit">/ 4.00</span>
        </div>
        <div class="kpi-card__footer">
            <div class="progress-bar">
                <div class="progress-bar__fill progress-bar__fill--green"
                     style="width:<?= $semGpa !== null ? round($semGpa/4*100,1) : 0 ?>%"></div>
            </div>
            <div class="kpi-card__meta"><?= h($currentSem['semester_name'] ?? '—') ?></div>
        </div>
    </div>
    <div class="card kpi-card">
        <div class="kpi-card__header">
            <span class="kpi-card__label">Tín chỉ tích lũy</span>
            <span class="kpi-card__badge kpi-card__badge--blue"><?= $earnedCredits ?> / 130 TC</span>
        </div>
        <div class="kpi-card__value">
            <span class="kpi-card__num"><?= $earnedCredits ?></span>
            <span class="kpi-card__unit">tín chỉ</span>
        </div>
        <div class="kpi-card__footer">
            <div class="progress-bar">
                <div class="progress-bar__fill progress-bar__fill--navy"
                     style="width:<?= round($earnedCredits/130*100,1) ?>%"></div>
            </div>
            <div class="kpi-card__meta">Còn <?= 130 - $earnedCredits ?> TC để tốt nghiệp</div>
        </div>
    </div>
</div>

<!-- GPA Trend Chart -->
<div class="card" style="padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
        <div>
            <div style="font-size:18px;font-weight:700;color:#001634;">Diễn biến GPA qua các học kỳ</div>
            <div style="font-size:13px;color:#44474e;margin-top:2px;">Biểu đồ theo dõi GPA học kỳ và GPA tích lũy toàn khoá</div>
        </div>
        <div class="chart-tabs">
            <button class="chart-tab active" data-chart="semester">GPA học kỳ</button>
            <button class="chart-tab" data-chart="cumulative">GPA tích lũy</button>
        </div>
    </div>
    <?php if (empty($gpaHistory)): ?>
    <div class="empty-state"><div class="empty-state__icon">📊</div><p>Chưa có dữ liệu GPA từ các học kỳ trước.</p></div>
    <?php else: ?>
    <div style="position:relative;height:220px;">
        <canvas id="gpaChartCanvas" role="img" aria-label="Biểu đồ GPA"></canvas>
    </div>
    <?php endif; ?>
</div>

<!-- Filter ribbon + grades table -->
<div class="filter-ribbon">
    <div class="filter-ribbon__search">
        <img class="si" src="<?= $imgBase ?>kqht-icon-search.svg" alt="" />
        <input type="search" id="gradeSearch" placeholder="Tìm môn học..." />
    </div>
    <div class="filter-ribbon__right">
        <span style="font-size:13px;font-weight:600;color:#44474e;">Học kỳ:</span>
        <form method="GET" style="display:flex;align-items:center;gap:8px;">
            <select name="sem" onchange="this.form.submit()">
                <?php foreach ($semesters as $s): ?>
                <option value="<?= h($s['semester_id']) ?>"
                    <?= (int)$s['semester_id'] === ($selSemId ?: $semId) ? 'selected' : '' ?>>
                    <?= h($s['semester_name']) ?> <?= h($s['academic_year']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="card">
    <div class="data-table-wrap">
        <table class="data-table" id="gradesTable">
            <thead>
                <tr>
                    <th>Mã môn</th>
                    <th>Tên môn học</th>
                    <th>TC</th>
                    <th>Điểm tổng kết (10)</th>
                    <th>Điểm chữ</th>
                    <th>Điểm hệ 4</th>
                    <th>Giảng viên</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grades)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không có dữ liệu điểm cho học kỳ này.
                </td></tr>
                <?php else: ?>
                <?php foreach ($grades as $g): ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= h($g['course_code']) ?></span></td>
                    <td style="font-weight:600;color:#001634;max-width:220px;"><?= h($g['course_name']) ?></td>
                    <td style="text-align:center;"><?= h($g['credits']) ?></td>
                    <td style="text-align:center;font-weight:600;">
                        <?= $g['weighted_score'] !== null ? number_format((float)$g['weighted_score'],1) : '<span style="color:#94a3b8;">—</span>' ?>
                    </td>
                    <td style="text-align:center;">
                        <?php $ltr = $g['letter_grade'] ?? null; ?>
                        <?php if ($ltr): ?>
                        <span class="grade-badge <?= gradeBadgeClass($ltr) ?>"><?= h($ltr) ?></span>
                        <?php else: ?>
                        <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:600;"><?= $g['grade_point'] !== null ? number_format((float)$g['grade_point'],1) : '<span style="color:#94a3b8;">—</span>' ?></td>
                    <td style="font-size:12px;color:#44474e;"><?= h($g['lecturer_name']) ?></td>
                    <td>
                        <?php $st = strtolower($g['enroll_status'] ?? ''); ?>
                        <span class="status-pill status-pill--<?= $st === 'completed' ? 'complete' : ($st === 'enrolled' ? 'enrolled' : 'absent') ?>">
                            <?= $g['enroll_status'] === 'Completed' ? 'Hoàn thành' : ($g['enroll_status'] === 'Enrolled' ? 'Đang học' : h($g['enroll_status'])) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"
        crossorigin="anonymous"></script>
<script>
const UNIPORTAL = {
    chartLabels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
    chartSemGpa: <?= json_encode($chartSemGpa) ?>,
    chartCumGpa: <?= json_encode($chartCumGpa) ?>,
    earnedCredits: <?= (int)$earnedCredits ?>,
    totalCredits: 130
};
</script>
<script src="../../assets/js/dashboard.js"></script>
<script>
// Client-side table search
document.getElementById('gradeSearch')?.addEventListener('input', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('#gradesTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
</body></html>
