<?php
/**
 * UniPortal – Lecturer Dashboard (Tổng quan)
 * Figma: dashboard screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId    = (int)($_SESSION['lecturer_id'] ?? 0);
$lecName  = $_SESSION['full_name'] ?? 'Giảng viên';

// ── Data ──────────────────────────────────────────────────────
$pdo        = get_db();
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);

$sections     = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$todaySess    = LecturerModel::getTodaySessions($lecId);
$warnCount    = LecturerModel::getUnreadWarningCount($lecId);
$pendingStats = LecturerModel::getPendingGradingStats($lecId);
$warnings     = LecturerModel::getWarningsForLecturer($lecId, 4);

// Assignments overview per section (top 3 newest due)
$assignStmt = $pdo->prepare(
    'SELECT a.assessment_id, a.assessment_name, a.due_date, a.weight_percent, a.max_score,
            cs.section_code, cs.section_id,
            (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id = cs.section_id
              AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled_count,
            (SELECT COUNT(*) FROM Grade g JOIN Enrollment e2 ON e2.enrollment_id = g.enrollment_id
              WHERE g.assessment_id = a.assessment_id AND e2.section_id = cs.section_id
                AND g.score IS NOT NULL) AS graded_count
     FROM Assessment a
     JOIN ClassSection cs ON cs.section_id = a.section_id
     WHERE cs.lecturer_id = :lid
     ORDER BY a.due_date DESC
     LIMIT 4'
);
$assignStmt->execute([':lid' => $lecId]);
$recentAssigns = $assignStmt->fetchAll();

// KPI counts
$totalSections  = count($sections);
$pendingGrades  = (int)($pendingStats['pending_grades'] ?? 0);
$pendingSubmits = $pdo->prepare(
    'SELECT COUNT(*) FROM Assessment a JOIN ClassSection cs ON cs.section_id=a.section_id
     WHERE cs.lecturer_id=:lid AND a.due_date > NOW()'
);
$pendingSubmits->execute([':lid' => $lecId]);
$submitsOpen = (int)$pendingSubmits->fetchColumn();

// Today's date display
$todayStr = date('l, d/m/Y'); // e.g. Monday, 18/11/2024 → translate
$daysVi   = ['Monday'=>'Thứ Hai','Tuesday'=>'Thứ Ba','Wednesday'=>'Thứ Tư',
              'Thursday'=>'Thứ Năm','Friday'=>'Thứ Sáu','Saturday'=>'Thứ Bảy','Sunday'=>'Chủ Nhật'];
$todayDisplay = ($daysVi[date('l')] ?? date('l')) . ', ' . date('d/m/Y');

$pageTitle  = 'Tổng quan';
$activePage = 'dashboard';
require __DIR__ . '/_layout.php';

function fmt_time(string $t): string { return substr($t, 0, 5); }
function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $r = '';
    foreach ($parts as $p) $r .= strtoupper(substr($p, 0, 1));
    return substr($r, -2);
}
?>

<!-- ═══════════ QUICK ACTION BUTTONS (top) ═══════════════════ -->
<div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="assignments.php" class="lbtn lbtn--primary">
        ✏️ Tạo bài tập mới
    </a>
    <a href="materials.php" class="lbtn lbtn--outline">
        📁 Tải tài liệu lên
    </a>
    <a href="grading.php" class="lbtn lbtn--yellow">
        ✅ Điểm danh ca hôm nay
    </a>
    <?php if ($warnCount > 0): ?>
    <a href="warnings.php" class="lbtn lbtn--outline" style="color:#ef4444;border-color:#fca5a5;">
        ⚠️ Xem cảnh báo học tập
    </a>
    <?php endif; ?>
</div>

<!-- ═══════════ 4 KPI CARDS ═══════════════════════════════════ -->
<div class="lec-kpi-row">

    <!-- Buổi dạy hôm nay -->
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Buổi dạy hôm nay</span>
            <span class="lec-kpi__icon lec-kpi__icon--yellow">
                <img src="../img/db-icon-schedule-time.svg" alt="" />
            </span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num"><?= count($todaySess) ?></span>
            <span class="lec-kpi__unit">ca giảng</span>
        </div>
        <div class="lec-kpi__sub">
            <?php if (!empty($todaySess)):
                $first = $todaySess[0];
                $last  = end($todaySess);
                echo 'Sáng ' . fmt_time($first['start_time']) . ' • Chiều ' . fmt_time($last['start_time']);
            else: ?>
            Không có buổi dạy hôm nay
            <?php endif; ?>
        </div>
        <?php if (!empty($todaySess)): ?>
        <div class="lec-kpi__sub" style="margin-top:2px;">
            <a href="schedule.php">Ca 1 sắp điểm ra (1tp)</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bài tập đang hỗ -->
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Bài tập đang hỗ</span>
            <span class="lec-kpi__icon lec-kpi__icon--blue">
                <img src="../img/db-nav-grades.svg" alt="" />
            </span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num"><?= $submitsOpen ?></span>
            <span class="lec-kpi__unit">chủ đề</span>
        </div>
        <div class="lec-kpi__sub">
            <?= $totalSections ?> lớp học phần
        </div>
        <div class="lec-kpi__sub">
            <a href="assignments.php">2 bài hạn trong 48h</a>
        </div>
    </div>

    <!-- Bài nộp cho chấm -->
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Bài nộp cho chấm</span>
            <span class="lec-kpi__icon lec-kpi__icon--green">
                <img src="../img/db-icon-gpa-card.svg" alt="" />
            </span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num"><?= $pendingGrades ?></span>
            <span class="lec-kpi__unit">bài nộp</span>
        </div>
        <div class="lec-kpi__sub">
            Đồ án giữa kỳ • <strong>INS3065</strong>
        </div>
        <div class="lec-kpi__sub" style="color:#f59e0b;">
            Hạn chấm: 25/11/2024
        </div>
    </div>

    <!-- Cần can thiệp -->
    <div class="lcard lec-kpi" style="<?= $warnCount > 0 ? 'border-left:4px solid #ef4444;' : '' ?>">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Cần can thiệp</span>
            <span class="lec-kpi__icon lec-kpi__icon--red">
                <img src="../img/db-nav-alerts.svg" alt="" />
            </span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num" style="color:#ef4444;"><?= count($warnings) ?></span>
            <span class="lec-kpi__unit">sinh viên</span>
        </div>
        <div class="lec-kpi__sub">
            <?= $warnCount > 0 ? $warnCount . ' vắng >20%, 3 yếu GPA' : 'Không có cảnh báo mới' ?>
        </div>
        <?php if ($warnCount > 0): ?>
        <div class="lec-kpi__sub" style="color:#ef4444;font-weight:700;">
            <a href="warnings.php" style="color:#ef4444;">Cần can thiệp sớm →</a>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- ═══════════ LỊCH GIẢNG DẠY HÔM NAY ══════════════════════ -->
<div class="lcard" style="padding:20px 24px;">
    <div class="lec-today-header">
        <div>
            <span class="lec-today-title">Lịch giảng dạy hôm nay</span>
            <span class="lec-today-date" style="margin-left:10px;"><?= $todayDisplay ?></span>
        </div>
        <a href="schedule.php" class="lec-today-link">Xem toàn bộ thời khóa biểu →</a>
    </div>

    <?php if (empty($todaySess)): ?>
    <div class="lec-empty">
        <div class="lec-empty__icon">📅</div>
        <p>Hôm nay không có buổi dạy nào. Enjoy your day!</p>
    </div>
    <?php else: ?>
    <div class="lec-sessions-grid">
        <?php foreach ($todaySess as $i => $sess):
            $isNow   = strtotime($sess['session_date'].' '.$sess['start_time']) <= time()
                       && time() <= strtotime($sess['session_date'].' '.$sess['end_time']);
            $cardCls = $isNow ? 'lec-session-card--now' : 'lec-session-card--upcoming';
            $badgeCls= $isNow ? 'lec-session-card__badge--now' : 'lec-session-card__badge--upcoming';
            $badgeTxt= $isNow ? '🔴 Sắp bắt đầu (15 phút)' : 'Chưa bắt đầu';
            $pct     = $sess['enrolled_count'] > 0
                ? round($sess['recorded_count']/$sess['enrolled_count']*100) : 0;
        ?>
        <div class="lec-session-card <?= $cardCls ?>">
            <div class="lec-session-card__badge <?= $badgeCls ?>"><?= $badgeTxt ?></div>
            <div class="lec-session-card__time">
                <?= fmt_time($sess['start_time']) ?> – <?= fmt_time($sess['end_time']) ?>
            </div>
            <div class="lec-session-card__course">
                <?= htmlspecialchars($sess['course_code']) ?> – <?= htmlspecialchars($sess['course_name']) ?>
            </div>
            <div class="lec-session-card__meta">
                <span>
                    <img src="../img/db-icon-schedule-loc.svg" alt="" />
                    Lớp: <?= htmlspecialchars($sess['section_code']) ?> •
                    <?= htmlspecialchars($sess['room'] ?? 'TBA') ?>
                </span>
                <span>
                    <img src="../img/db-icon-credits.svg" alt="" />
                    <?= (int)$sess['enrolled_count'] ?> Sinh viên
                </span>
                <?php if ($sess['topic']): ?>
                <span>📝 <?= htmlspecialchars($sess['topic']) ?></span>
                <?php endif; ?>
            </div>
            <div class="lec-session-card__actions">
                <a href="grading.php?section=<?= (int)$sess['section_id'] ?>&session=<?= (int)$sess['session_id'] ?>"
                   class="lbtn lbtn--yellow lbtn--sm">✅ Điểm danh ca nay</a>
                <a href="classes.php?section=<?= (int)$sess['section_id'] ?>"
                   class="lbtn lbtn--outline lbtn--sm">Chi tiết ca học</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════ ROW 2: BÀI TẬP + CẢNH BÁO ═══════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

    <!-- Bài tập & Tiến độ chấm bài -->
    <div class="lcard" style="padding:20px 24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <div style="font-size:16px;font-weight:700;color:#001634;">
                📋 Bài tập & Tiến độ chấm bài
            </div>
            <a href="assignments.php" class="lbtn lbtn--outline lbtn--sm">Tất cả bài tập</a>
        </div>

        <?php if (empty($recentAssigns)): ?>
        <div class="lec-empty"><p>Chưa có bài tập nào.</p></div>
        <?php else: ?>
        <?php foreach ($recentAssigns as $a):
            $enrolled = (int)$a['enrolled_count'];
            $graded   = (int)$a['graded_count'];
            $pct      = $enrolled > 0 ? round($graded/$enrolled*100) : 0;
            $isOverdue= strtotime($a['due_date']) < time();
            $isDone   = $graded >= $enrolled && $enrolled > 0;
            $barCls   = $isDone ? 'green' : ($pct > 60 ? 'yellow' : 'navy');
        ?>
        <div class="lec-assign-card">
            <div class="lec-assign-card__top">
                <div style="display:flex;flex-direction:column;gap:4px;flex:1;">
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span class="lec-assign-card__section"><?= htmlspecialchars($a['section_code']) ?></span>
                    </div>
                    <div class="lec-assign-card__title"><?= htmlspecialchars($a['assessment_name']) ?></div>
                </div>
                <div class="lec-assign-card__status">
                    <?php if ($isDone): ?>
                    <span class="lpill lpill--green">Đã hoàn thành chấm</span>
                    <?php elseif ($isOverdue): ?>
                    <span class="lpill lpill--orange">Đang tiếp nhận nộp</span>
                    <?php else: ?>
                    <span class="lpill lpill--yellow">Còn <?= $enrolled-$graded ?> bài chờ</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="lec-assign-card__meta">
                <span>📅 Hạn nộp: <?= date('H:i, d/m/Y', strtotime($a['due_date'])) ?></span>
                <span>📊 Đã chấm: <?= $graded ?>/<?= $enrolled ?> SV</span>
            </div>
            <div class="lec-assign-progress">
                <div class="lec-assign-progress__bar">
                    <div class="lec-assign-progress__fill lec-assign-progress__fill--<?= $barCls ?>"
                         style="width:<?= $pct ?>%"></div>
                </div>
                <span class="lec-assign-progress__label"><?= $pct ?>%</span>
            </div>
            <div style="display:flex;gap:8px;margin-top:6px;">
                <a href="assignments.php?assessment=<?= (int)$a['assessment_id'] ?>"
                   class="lbtn lbtn--primary lbtn--sm">→ Vào chấm ngay</a>
                <a href="assignments.php" class="lbtn lbtn--outline lbtn--sm">Xem bảng điểm</a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Cảnh báo & Can thiệp khẩn -->
    <div class="lcard" style="padding:20px 24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <div style="font-size:16px;font-weight:700;color:#001634;">
                ⚠️ Cảnh báo & Can thiệp khẩn
            </div>
            <?php if (!empty($warnings)): ?>
            <span style="font-size:12px;color:#64748b;">
                <?= count($warnings) ?> sinh viên có dấu hiệu rủi ro cao
            </span>
            <?php endif; ?>
        </div>

        <?php if (empty($warnings)): ?>
        <div class="lec-empty">
            <div class="lec-empty__icon">✅</div>
            <p>Tất cả sinh viên đang học tốt. Không có cảnh báo mới.</p>
        </div>
        <?php else: ?>
        <?php foreach ($warnings as $w):
            $ini = initials($w['full_name']);
            $wt  = $w['warning_type'];
            $lvlClass = in_array($wt, ['LowGPA','Attendance']) ? 'cao' : ($wt === 'LowGrade' ? 'tb' : 'thap');
            $lvlLabel = ['cao' => 'MỨC ĐỘ: CAO', 'tb' => 'MỨC ĐỘ: TB', 'thap' => 'MỨC ĐỘ: THẤP'][$lvlClass];
            $tagClass = match($wt) {
                'Attendance'     => 'att',
                'LowGPA'         => 'gpa',
                'LowGrade'       => 'grade',
                default          => 'att'
            };
            $tagLabel = match($wt) {
                'Attendance'     => '🔴 Vắng',
                'LowGPA'         => '📉 GPA thấp',
                'LowGrade'       => '📝 Điểm thấp',
                default          => '⚠️ Cảnh báo'
            };
        ?>
        <div class="lec-warn-item <?= $lvlClass === 'tb' ? 'lec-warn-item--medium' : ($lvlClass === 'thap' ? 'lec-warn-item--low' : '') ?>">
            <div class="lec-warn-item__avatar"><?= htmlspecialchars($ini) ?></div>
            <div class="lec-warn-item__body">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                    <span class="lec-warn-item__name"><?= htmlspecialchars($w['full_name']) ?></span>
                    <span class="lec-warn-item__level lec-warn-item__level--<?= $lvlClass ?>"><?= $lvlLabel ?></span>
                </div>
                <div class="lec-warn-item__info">
                    MSSV: <?= htmlspecialchars($w['student_id']) ?> • <?= htmlspecialchars($w['section_code'] ?? '') ?>
                </div>
                <div class="lec-warn-item__tags">
                    <span class="lec-warn-item__tag lec-warn-item__tag--<?= $tagClass ?>"><?= $tagLabel ?></span>
                </div>
                <div class="lec-warn-item__desc"><?= htmlspecialchars(mb_strimwidth($w['message'], 0, 100, '…')) ?></div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="warnings.php" class="lbtn lbtn--red lbtn--sm">Ghi nhận can thiệp</a>
                    <a href="classes.php?student=<?= htmlspecialchars($w['student_id']) ?>"
                       class="lbtn lbtn--outline lbtn--sm">Liên hệ SV & Cố vấn</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:12px;text-align:center;">
            <a href="warnings.php" class="lbtn lbtn--outline" style="width:100%;justify-content:center;">
                Xem tất cả <?= count($warnings) ?> trường hợp cần hỗ trợ →
            </a>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
