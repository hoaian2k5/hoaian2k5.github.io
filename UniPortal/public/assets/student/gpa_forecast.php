<?php
/**
 * UniPortal – Kế hoạch & Dự báo GPA (AI Advisor)
 * Figma frame: GPA forecasting (3:1779)
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

$currentSem   = StudentModel::getCurrentSemester();
$semId        = (int)($currentSem['semester_id'] ?? 0);
$cumData      = GpaModel::getCumulative($student_id);
$cumulativeGpa= $cumData['cumulative_gpa'];
$earnedCredits= $cumData['earned_credits'];
$semesterGpa  = $semId ? GpaModel::getSemesterGpa($student_id, $semId) : null;
$gpaHistory   = GpaModel::getGpaHistory($student_id);
$classification = GpaModel::getClassification($cumulativeGpa);

$remaining    = 130 - $earnedCredits;

// Scenarios: what GPA needed this semester to hit targets
function calcNeededGpa(float $currentCumGpa, int $earned, int $remaining, float $target): ?float {
    if ($remaining <= 0) return null;
    $semCredits = min(15, $remaining);
    $needed = ($target * ($earned + $semCredits) - $currentCumGpa * $earned) / $semCredits;
    return round(max(0.0, min(4.0, $needed)), 2);
}

$targets = [
    ['label' => 'Xuất sắc', 'gpa' => 3.60, 'color' => '#7c3aed'],
    ['label' => 'Giỏi',     'gpa' => 3.20, 'color' => '#2563eb'],
    ['label' => 'Khá',      'gpa' => 2.50, 'color' => '#059669'],
    ['label' => 'Trung bình','gpa'=> 2.00, 'color' => '#d97706'],
];

$projSemCredits = 15;
$projectedAfter = null;
if ($cumulativeGpa !== null && $semesterGpa !== null) {
    $projectedAfter = round(
        ($cumulativeGpa * $earnedCredits + $semesterGpa * $projSemCredits) / ($earnedCredits + $projSemCredits),
        2
    );
}

$gapToGioi = $cumulativeGpa !== null ? max(0, 3.20 - (float)$cumulativeGpa) : null;
$prob = $gapToGioi !== null ? ($gapToGioi <= 0 ? 92 : max(10, round(90 - $gapToGioi * 50))) : null;

// Chart data
$chartLabels = $chartSemGpa = $chartCumGpa = [];
$rC = $rP = 0;
foreach ($gpaHistory as $r) {
    $chartLabels[] = preg_replace('/^Học kỳ\s*/i','HK',$r['semester_name']??'').' ('.($r['academic_year']??')');
    $chartSemGpa[] = (float)($r['semester_gpa'] ?? 0);
    $rC += (int)($r['credits_this_sem'] ?? 0);
    $rP += (float)($r['semester_gpa'] ?? 0) * (int)($r['credits_this_sem'] ?? 1);
    $chartCumGpa[] = $rC > 0 ? round($rP/$rC,2) : 0;
}

function h($v):string{ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function gf($v):string{ return $v!==null ? number_format((float)$v,2) : '—'; }

$pageTitle  = 'Kế hoạch & Dự báo GPA';
$activePage = 'gpa-forecast';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<!-- Page banner -->
<?php page_banner(
    'Kế hoạch & Dự báo GPA (AI)',
    'Mô hình dự báo GPA tích lũy và lộ trình học tập tối ưu từ AI',
    'db-icon-ai.svg', $imgBase
); ?>

<!-- AI Summary card -->
<div class="ai-card" style="border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,.05);">
    <div class="ai-card__top">
        <div class="ai-card__title-row">
            <div class="ai-card__icon"><img src="<?= $imgBase ?>db-icon-ai.svg" alt="" /></div>
            <span class="ai-card__title">Phân tích học tập thông minh (AI Advisor Module)</span>
        </div>
        <span class="ai-card__accuracy">Độ chuẩn xác 90%</span>
    </div>
    <div class="ai-card__heading">Gợi ý tối ưu hóa kết quả học tập từ AI</div>
    <div class="ai-card__body">
        <?php if ($cumulativeGpa !== null): ?>
        Dựa trên mô hình dự báo GPA, với GPA tích lũy hiện tại là
        <strong><?= gf($cumulativeGpa) ?></strong>
        <?php if ($semesterGpa !== null): ?>(kỳ này đạt ~<strong><?= gf($semesterGpa) ?></strong>),<?php endif; ?>
        bạn có <strong><?= $prob ?>% khả năng đạt tốt nghiệp loại Giỏi</strong> (GPA ≥ 3,20).
        <?php if ($gapToGioi > 0): ?>
        Cần duy trì GPA ≥ <strong><?= gf(calcNeededGpa((float)$cumulativeGpa, $earnedCredits, $remaining, 3.20)) ?></strong>
        trong các học kỳ còn lại để đạt mục tiêu này.
        <?php else: ?>
        Bạn đã đạt mục tiêu Giỏi. Hãy duy trì phong độ để hướng đến loại <strong>Xuất sắc (≥ 3,60)</strong>!
        <?php endif; ?>
        <?php else: ?>
        Chưa đủ dữ liệu để phân tích. Hãy hoàn thành ít nhất một học kỳ để nhận gợi ý từ AI.
        <?php endif; ?>
    </div>
    <div class="ai-card__bottom">
        <div class="ai-card__forecast">
            <img src="<?= $imgBase ?>db-icon-forecast.svg" alt="" />
            GPA dự báo sau HK này:
            <strong><?= $projectedAfter !== null ? number_format($projectedAfter,2) : '—' ?></strong>
        </div>
    </div>
</div>

<!-- Two-column: chart left, scenarios right -->
<div class="forecast-grid">

    <!-- Left: GPA trend + projection -->
    <div class="card" style="padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
            <div>
                <div style="font-size:18px;font-weight:700;color:#001634;">Diễn biến & Dự báo GPA</div>
                <div style="font-size:13px;color:#44474e;">Lịch sử GPA học kỳ và xu hướng tích lũy</div>
            </div>
            <div class="chart-tabs">
                <button class="chart-tab active" data-chart="semester">GPA học kỳ</button>
                <button class="chart-tab" data-chart="cumulative">GPA tích lũy</button>
            </div>
        </div>
        <?php if (empty($gpaHistory)): ?>
        <div class="empty-state"><div class="empty-state__icon">📊</div><p>Chưa có dữ liệu GPA.</p></div>
        <?php else: ?>
        <div style="position:relative;height:220px;">
            <canvas id="gpaChartCanvas" role="img" aria-label="Biểu đồ GPA"></canvas>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Scenarios needed GPA per target -->
    <div class="card" style="padding:24px;display:flex;flex-direction:column;gap:16px;">
        <div style="font-size:18px;font-weight:700;color:#001634;">Lộ trình đến mục tiêu</div>
        <div style="font-size:13px;color:#44474e;margin-top:-8px;">GPA tối thiểu cần đạt mỗi học kỳ</div>

        <?php foreach ($targets as $t): ?>
        <?php
            $needed = $cumulativeGpa !== null
                ? calcNeededGpa((float)$cumulativeGpa, $earnedCredits, $remaining, $t['gpa'])
                : null;
            $achieved = $cumulativeGpa !== null && (float)$cumulativeGpa >= $t['gpa'];
        ?>
        <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <span style="font-weight:700;color:#001634;"><?= h($t['label']) ?></span>
                <span style="font-size:12px;font-weight:600;color:<?= $t['color'] ?>;">
                    Mục tiêu: <?= number_format($t['gpa'],2) ?>
                </span>
            </div>
            <?php if ($achieved): ?>
            <div style="color:#059669;font-size:13px;font-weight:600;">✅ Đã đạt mục tiêu này</div>
            <?php elseif ($needed !== null): ?>
            <div style="font-size:13px;color:#44474e;margin-bottom:6px;">
                Cần GPA mỗi kỳ: <strong style="color:<?= $t['color'] ?>;"><?= number_format($needed,2) ?></strong>
            </div>
            <div style="height:6px;background:#e5eeff;border-radius:9999px;overflow:hidden;">
                <?php $prog = $cumulativeGpa !== null ? min(100, round((float)$cumulativeGpa/$t['gpa']*100)) : 0; ?>
                <div style="height:100%;width:<?= $prog ?>%;background:<?= $t['color'] ?>;border-radius:9999px;transition:width .4s;"></div>
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px;"><?= $prog ?>% tiến độ</div>
            <?php else: ?>
            <div style="color:#94a3b8;font-size:13px;">Chưa đủ dữ liệu</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:8px;padding:12px;background:#eff4ff;border-radius:8px;font-size:12px;color:#44474e;line-height:1.6;">
            <strong>Lưu ý:</strong> Dự báo dựa trên GPA hiện tại và giả định còn
            <?= $remaining ?> tín chỉ (<?= max(1,round($remaining/15)) ?> học kỳ).
            Kết quả thực tế phụ thuộc vào điểm từng môn học.
        </div>
    </div>

</div>

<!-- KPI summary row -->
<div class="kpi-row" style="grid-template-columns:repeat(3,1fr);">
    <div class="card kpi-card">
        <div class="kpi-card__header"><span class="kpi-card__label">GPA Tích lũy</span>
            <span class="kpi-card__badge kpi-card__badge--green"><?= h($classification) ?></span></div>
        <div class="kpi-card__value"><span class="kpi-card__num"><?= gf($cumulativeGpa) ?></span><span class="kpi-card__unit">/ 4.00</span></div>
    </div>
    <div class="card kpi-card">
        <div class="kpi-card__header"><span class="kpi-card__label">GPA kỳ hiện tại</span></div>
        <div class="kpi-card__value"><span class="kpi-card__num"><?= gf($semesterGpa) ?></span><span class="kpi-card__unit">/ 4.00</span></div>
    </div>
    <div class="card kpi-card">
        <div class="kpi-card__header"><span class="kpi-card__label">GPA dự báo</span>
            <span class="kpi-card__badge kpi-card__badge--blue">Sau kỳ này</span></div>
        <div class="kpi-card__value"><span class="kpi-card__num"><?= gf($projectedAfter) ?></span><span class="kpi-card__unit">/ 4.00</span></div>
    </div>
</div>

</div></main>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" crossorigin="anonymous"></script>
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
</body></html>
