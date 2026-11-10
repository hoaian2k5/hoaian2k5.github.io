<?php
/**
 * UniPortal – Lớp học & Sinh viên
 * Figma: class screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId   = (int)($_SESSION['lecturer_id'] ?? 0);
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);

$sections  = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount = LecturerModel::getUnreadWarningCount($lecId);

// Selected section
$selSection = (int)($_GET['section'] ?? ($sections[0]['section_id'] ?? 0));
$search     = trim($_GET['q'] ?? '');

// Find selected section info
$selSectionInfo = null;
foreach ($sections as $s) {
    if ((int)$s['section_id'] === $selSection) { $selSectionInfo = $s; break; }
}

// Students in selected section
$students = $selSection ? LecturerModel::getStudentsInSection($selSection, $search) : [];

// Warning students for right panel
$warnings = $selSection ? array_filter(
    LecturerModel::getWarningsForLecturer($lecId, 10),
    fn($w) => ($w['section_code'] ?? '') === ($selSectionInfo['section_code'] ?? '')
) : [];

$pageTitle  = 'Lớp học & Sinh viên';
$activePage = 'classes';
require __DIR__ . '/_layout.php';

function initials(string $n): string {
    $r=''; foreach(explode(' ',trim($n)) as $w) $r.=strtoupper(substr($w,0,1));
    return substr($r,-2);
}
?>

<!-- ═══════════ COURSE CARDS TOP ROW ════════════════════════ -->
<div class="lec-course-cards">
    <?php foreach ($sections as $sec):
        $isActive = (int)$sec['section_id'] === $selSection;
        $wc = (int)$sec['warning_count'];
        $submitPct = $sec['enrolled_count'] > 0
            ? round($sec['enrolled_count'] / ($sec['capacity'] ?: $sec['enrolled_count']) * 100) : 0;
        // attendance rate placeholder
        $pdo2 = get_db();
        $attStmt = $pdo2->prepare(
            'SELECT ROUND(100*SUM(a.status IN (\'Present\',\'Late\'))/NULLIF(COUNT(*),0),1) as rate
             FROM Attendance a
             JOIN Enrollment e ON e.enrollment_id=a.enrollment_id
             WHERE e.section_id=:sid'
        );
        $attStmt->execute([':sid' => $sec['section_id']]);
        $attRate = $attStmt->fetchColumn();
    ?>
    <a href="?section=<?= (int)$sec['section_id'] ?>" class="lec-course-card <?= $isActive ? 'active' : '' ?>"
       style="text-decoration:none;display:block;">
        <div class="lec-course-card__badge <?= $sec['sem_status']==='Active' ? 'lec-course-card__badge--active' : 'lec-course-card__badge--teaching' ?>">
            <?= $sec['sem_status']==='Active' ? '● Đang giảng dạy' : '● Đang giảng dạy' ?>
        </div>
        <div class="lec-course-card__code"><?= htmlspecialchars($sec['section_code']) ?></div>
        <div class="lec-course-card__name"><?= htmlspecialchars($sec['course_name']) ?></div>
        <div class="lec-course-card__stats">
            <div class="lec-course-card__stat">
                <span class="lec-course-card__stat-val"><?= (int)$sec['enrolled_count'] ?></span>
                <span class="lec-course-card__stat-lbl">Sĩ số</span>
            </div>
            <div class="lec-course-card__stat">
                <span class="lec-course-card__stat-val"><?= $attRate !== null ? $attRate.'%' : '—' ?></span>
                <span class="lec-course-card__stat-lbl">Chuyên cần</span>
            </div>
        </div>
        <div class="lec-course-card__progress">
            <div class="lec-course-card__progress-bar">
                <div class="lec-course-card__progress-fill" style="width:<?= $submitPct ?>%"></div>
            </div>
            <div class="lec-course-card__progress-label">Nộp bài: <?= $submitPct ?>%</div>
        </div>
        <div class="lec-course-card__footer">
            <?php if ($wc > 0): ?>
            <span class="lec-course-card__warn">⚠️ <?= $wc ?> SV cảnh báo học vụ</span>
            <?php endif; ?>
            <span class="lec-course-card__action">Chuyển sang lớp này →</span>
        </div>
    </a>
    <?php endforeach; ?>
    <?php if (empty($sections)): ?>
    <div class="lcard" style="padding:24px;grid-column:1/-1;">
        <div class="lec-empty"><div class="lec-empty__icon">📚</div><p>Chưa có lớp học phần nào trong học kỳ này.</p></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($selSectionInfo): ?>

<!-- ═══════════ SECTION BANNER ════════════════════════════ -->
<div class="lec-section-banner">
    <div class="lec-section-banner__info">
        <span class="lec-section-banner__code"><?= htmlspecialchars($selSectionInfo['section_code']) ?> • <?= (int)$selSectionInfo['credits'] ?> TC</span>
        <span class="lec-section-banner__name"><?= htmlspecialchars($selSectionInfo['course_name']) ?></span>
        <div class="lec-section-banner__meta">
            <span>🕐 <?= htmlspecialchars($selSectionInfo['schedule'] ?? 'TBA') ?></span>
            <span>📍 <?= htmlspecialchars($selSectionInfo['room'] ?? 'TBA') ?></span>
            <span>📊 Tiến độ nộp bài: <?= (int)$selSectionInfo['enrolled_count'] ?> SV</span>
        </div>
    </div>
    <div class="lec-section-banner__actions">
        <a href="assignments.php?section=<?= (int)$selSection ?>" class="lbtn lbtn--yellow lbtn--sm">📋 Tạo bài tập</a>
        <a href="grading.php?section=<?= (int)$selSection ?>" class="lbtn lbtn--outline lbtn--sm" style="color:#fff;border-color:rgba(255,255,255,.3);">✅ Nhật ký can thiệp</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;">

<!-- STUDENT TABLE -->
<div class="lcard" style="padding:0;overflow:hidden;">
    <!-- Toolbar -->
    <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="section" value="<?= (int)$selSection ?>" />
            <div style="position:relative;">
                <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;height:13px;opacity:.4;" />
                <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Tìm MSSV, tên sinh viên, email..."
                       style="width:280px;height:36px;background:#f1f5f9;border:none;border-radius:8px;padding:0 12px 0 32px;font-size:13px;outline:none;" />
            </div>
            <select name="status" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                <option value="">Trạng thái: Tất cả</option>
                <option value="ok">An toàn</option>
                <option value="warn">Cần theo dõi</option>
            </select>
            <button type="submit" class="lbtn lbtn--primary lbtn--sm">Lọc</button>
        </form>
        <div style="font-size:13px;color:#64748b;">
            Hiển thị <?= count($students) ?> / <?= (int)($selSectionInfo['enrolled_count'] ?? 0) ?> sinh viên
        </div>
    </div>

    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th style="width:40px;text-align:center;">STT</th>
                    <th>Học viên & MSSV</th>
                    <th>Chuyên cần</th>
                    <th>Bài tập thiếu</th>
                    <th>Trạng thái học vụ</th>
                    <th>Tác vụ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không tìm thấy sinh viên nào.
                </td></tr>
                <?php else: ?>
                <?php foreach ($students as $i => $st):
                    $ini = initials($st['full_name']);
                    $tot = max(1, (int)$st['total_sessions']);
                    $att = (int)$st['attended'];
                    $pct = $tot > 0 ? round($att/$tot*100,1) : 0;
                    $barCls = $pct >= 90 ? 'ok' : ($pct >= 80 ? 'warn' : 'crit');
                    $pctCls = $pct >= 90 ? 'ok' : ($pct >= 80 ? 'warn' : 'crit');
                    $risk = $pct < 80 ? 'Cần theo dõi' : ($pct < 90 ? 'Theo dõi' : 'An toàn');
                    $riskLpill = $pct < 80 ? 'lpill--red' : ($pct < 90 ? 'lpill--orange' : 'lpill--green');
                ?>
                <tr>
                    <td style="text-align:center;font-size:12px;color:#94a3b8;"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="lec-av"><?= htmlspecialchars($ini) ?></span>
                            <div>
                                <div style="font-weight:700;color:#001634;font-size:13px;"><?= htmlspecialchars($st['full_name']) ?></div>
                                <div style="font-size:11px;color:#64748b;">
                                    <?= htmlspecialchars($st['student_id']) ?> • <?= htmlspecialchars($st['class_code']) ?>
                                </div>
                                <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($st['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="att-bar-wrap">
                            <div class="att-bar">
                                <div class="att-bar__fill att-bar__fill--<?= $barCls ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                            <span class="att-pct att-pct--<?= $pctCls ?>"><?= $pct ?>%</span>
                        </div>
                        <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?= $att ?>/<?= $tot ?> buổi</div>
                    </td>
                    <td style="text-align:center;">
                        <span class="lpill lpill--gray">—</span>
                    </td>
                    <td>
                        <span class="lpill <?= $riskLpill ?>"><?= $risk ?></span>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <a href="warnings.php?student=<?= htmlspecialchars($st['student_id']) ?>"
                               class="lbtn lbtn--outline lbtn--sm" title="Chi tiết">👁</a>
                            <a href="grading.php?section=<?= (int)$selSection ?>"
                               class="lbtn lbtn--outline lbtn--sm" title="Điểm danh">📋</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f1f5f9;">
        <span style="font-size:12px;color:#64748b;">
            Hiển thị 1 – <?= count($students) ?> trên tổng số <?= (int)$selSectionInfo['enrolled_count'] ?> sinh viên của lớp
        </span>
        <div class="lec-pagination">
            <span class="active">1</span>
            <?php if (count($students) < (int)$selSectionInfo['enrolled_count']): ?>
            <a href="?section=<?= (int)$selSection ?>&page=2">2</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- RIGHT PANEL: Warning profile -->
<div class="lcard" style="padding:20px;">
    <div style="font-size:15px;font-weight:700;color:#001634;margin-bottom:14px;">
        🔴 Hồ sơ cảnh báo học vụ
        <?php if (!empty($warnings)): ?>
        <span class="lpill lpill--red" style="margin-left:6px;font-size:11px;">Cần thi</span>
        <?php endif; ?>
    </div>
    <?php if (empty($warnings)): ?>
    <div class="lec-empty"><div class="lec-empty__icon">✅</div><p>Không có sinh viên nào cần can thiệp.</p></div>
    <?php else: ?>
    <?php
    // Show first warning student with full details
    $fw = array_values($warnings)[0];
    $ini2 = initials($fw['full_name']);
    $wType = ['Attendance'=>'🔴 Vắng học','LowGPA'=>'📉 GPA thấp','LowGrade'=>'📝 Điểm thấp','GraduationRisk'=>'🎓 Nguy cơ tốt nghiệp'];
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#fff5f5;border-radius:8px;margin-bottom:12px;">
        <div class="lec-av" style="width:44px;height:44px;font-size:15px;"><?= htmlspecialchars($ini2) ?></div>
        <div>
            <div style="font-weight:700;color:#001634;"><?= htmlspecialchars($fw['full_name']) ?></div>
            <div style="font-size:12px;color:#64748b;">MSSV: <?= htmlspecialchars($fw['student_id']) ?> • Lớp <?= htmlspecialchars($fw['class_code']) ?></div>
        </div>
    </div>

    <?php foreach (array_values($warnings) as $w):
        $wl = $wType[$w['warning_type']] ?? '⚠️ Cảnh báo';
    ?>
    <div style="border:1px solid #fecaca;background:#fff5f5;border-radius:8px;padding:12px;margin-bottom:8px;">
        <div style="font-size:12px;font-weight:700;color:#b91c1c;margin-bottom:4px;"><?= $wl ?></div>
        <div style="font-size:12px;color:#44474e;line-height:16px;">
            <?= htmlspecialchars(mb_strimwidth($w['message'],0,120,'…')) ?>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
            <?= date('d/m/Y H:i', strtotime($w['created_at'])) ?>
        </div>
        <div style="display:flex;gap:6px;margin-top:8px;">
            <a href="warnings.php" class="lbtn lbtn--red lbtn--sm">Ghi nhận can thiệp</a>
        </div>
    </div>
    <?php endforeach; ?>

    <div style="margin-top:8px;">
        <a href="warnings.php" class="lbtn lbtn--outline" style="width:100%;justify-content:center;font-size:12px;">
            Xem toàn bộ cảnh báo →
        </a>
    </div>
    <?php endif; ?>
</div>

</div><!-- end 2-col grid -->
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>
