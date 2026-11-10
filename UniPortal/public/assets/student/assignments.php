<?php
/**
 * UniPortal – Bài tập & Nộp bài (Student Assignments)
 * View assignments from enrolled sections, upload submissions,
 * view feedback and grades.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/services/NotificationService.php';
require_once $root . '/src/src/services/AcademicAlertService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo        = get_db();
$student_id = $_SESSION['student_id'] ?? '';
$account_id = (int)($_SESSION['account_id'] ?? 0);
$full_name  = $_SESSION['full_name']   ?? '';

// Storage path (outside web root)
$storageBase = $root . '/storage/uploads/submissions';
if (!is_dir($storageBase)) {
    mkdir($storageBase, 0755, true);
}

$flash = '';

// ── Handle file submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assignment'])) {
    csrf_verify();
    $assignment_id  = (int)($_POST['assignment_id']  ?? 0);
    $enrollment_id  = (int)($_POST['enrollment_id']  ?? 0);

    // Validate ownership: enrollment_id must belong to this student
    $ownChk = $pdo->prepare(
        'SELECT e.enrollment_id FROM Enrollment e
         WHERE e.enrollment_id=:eid AND e.student_id=:sid LIMIT 1'
    );
    $ownChk->execute([':eid' => $enrollment_id, ':sid' => $student_id]);
    if (!$ownChk->fetch()) {
        $flash = 'error:Không có quyền nộp bài cho lớp này.';
    } else {
        // Check assignment still open (allow late flag)
        $asgRow = $pdo->prepare(
            'SELECT a.*, sc.config_value AS allow_late
             FROM Assignment a
             LEFT JOIN SystemConfig sc ON sc.config_key=\'late_submission_allowed\'
             WHERE a.assignment_id=:aid AND a.is_deleted=0 LIMIT 1'
        );
        $asgRow->execute([':aid' => $assignment_id]);
        $asg = $asgRow->fetch();

        if (!$asg) {
            $flash = 'error:Bài tập không tồn tại.';
        } elseif (strtotime($asg['due_date']) < time() && !$asg['allow_late']) {
            $flash = 'error:Đã quá hạn nộp bài.';
        } elseif (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
            $flash = 'error:Vui lòng chọn file để nộp.';
        } else {
            $file = $_FILES['submission_file'];
            // Validate file
            $maxSize = 20 * 1024 * 1024; // 20 MB
            $allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx','zip','rar','jpg','jpeg','png','txt','py','java','cpp','c'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($file['size'] > $maxSize) {
                $flash = 'error:File quá lớn (tối đa 20 MB).';
            } elseif (!in_array($ext, $allowed)) {
                $flash = 'error:Định dạng file không được phép.';
            } else {
                // Save file
                $dir = $storageBase . '/' . $assignment_id;
                if (!is_dir($dir)) mkdir($dir, 0755, true);

                $safeName  = bin2hex(random_bytes(8)) . '.' . $ext;
                $destPath  = $dir . '/' . $safeName;
                $relPath   = 'submissions/' . $assignment_id . '/' . $safeName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    // Upsert submission
                    $pdo->prepare(
                        'INSERT INTO Submission
                            (assignment_id, enrollment_id, file_path, file_name, file_size, file_mime, submitted_at, grade_status)
                         VALUES (:aid, :eid, :fp, :fn, :fs, :fm, NOW(), \'Draft\')
                         ON DUPLICATE KEY UPDATE
                            file_path=:fp2, file_name=:fn2, file_size=:fs2, file_mime=:fm2, submitted_at=NOW()'
                    )->execute([
                        ':aid'  => $assignment_id,
                        ':eid'  => $enrollment_id,
                        ':fp'   => $relPath,   ':fp2'  => $relPath,
                        ':fn'   => $file['name'], ':fn2' => $file['name'],
                        ':fs'   => $file['size'], ':fs2' => $file['size'],
                        ':fm'   => $file['type'], ':fm2' => $file['type'],
                    ]);

                    NotificationService::send(
                        $account_id, 'Assignment',
                        '📤 Nộp bài thành công',
                        'Bài tập: ' . $asg['title'],
                        '/UniPortal/public/assets/student/assignments.php'
                    );

                    $flash = 'success:Nộp bài thành công! File: ' . htmlspecialchars($file['name']);
                } else {
                    $flash = 'error:Lỗi lưu file. Vui lòng thử lại.';
                }
            }
        }
    }
}

// ── Load assignments ──────────────────────────────────────────
$semRow = $pdo->query("SELECT * FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1")->fetch();
$semId  = $semRow ? (int)$semRow['semester_id'] : 0;

// All assignments for the student's enrolled sections
$asgStmt = $pdo->prepare(
    'SELECT
        a.assignment_id, a.title, a.description, a.due_date,
        a.max_score, a.attachment_name, a.attachment_path,
        cs.section_code, cs.section_id,
        c.course_code, c.course_name,
        e.enrollment_id,
        sub.submission_id, sub.file_name AS sub_file, sub.submitted_at,
        sub.score AS sub_score, sub.feedback AS sub_feedback, sub.grade_status
     FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id = e.section_id
     JOIN Course c        ON c.course_id   = cs.course_id
     JOIN Assignment a    ON a.section_id  = cs.section_id AND a.is_deleted = 0
     LEFT JOIN Submission sub ON sub.assignment_id = a.assignment_id
                              AND sub.enrollment_id = e.enrollment_id
     WHERE e.student_id    = :sid
       AND cs.semester_id  = :sem
       AND e.status        IN (\'Enrolled\',\'Completed\')
     ORDER BY a.due_date ASC'
);
$asgStmt->execute([':sid' => $student_id, ':sem' => $semId]);
$assignments = $asgStmt->fetchAll();

// Stats
$totalAsg  = count($assignments);
$submitted = count(array_filter($assignments, fn($a) => $a['submission_id'] !== null));
$pending   = $totalAsg - $submitted;
$graded    = count(array_filter($assignments, fn($a) => $a['sub_score'] !== null));
$overdue   = count(array_filter($assignments, fn($a) => !$a['submission_id'] && strtotime($a['due_date']) < time()));

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_dt(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }

$pageTitle  = 'Bài tập & Nộp bài';
$activePage = 'assignments';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<?php page_banner('Bài tập & Nộp bài', 'Xem và nộp bài tập cho các lớp học phần', 'db-nav-attendance.svg', $imgBase); ?>

<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="stat-row">
    <div class="stat-mini"><div class="stat-mini__label">Tổng bài tập</div><div class="stat-mini__value"><?= $totalAsg ?></div></div>
    <div class="stat-mini"><div class="stat-mini__label">Đã nộp</div><div class="stat-mini__value" style="color:#059669;"><?= $submitted ?></div></div>
    <div class="stat-mini"><div class="stat-mini__label">Chưa nộp</div><div class="stat-mini__value" style="color:#f59e0b;"><?= $pending ?></div></div>
    <div class="stat-mini"><div class="stat-mini__label">Quá hạn</div><div class="stat-mini__value" style="color:#ef4444;"><?= $overdue ?></div></div>
    <div class="stat-mini"><div class="stat-mini__label">Đã có điểm</div><div class="stat-mini__value" style="color:#3b82f6;"><?= $graded ?></div></div>
</div>

<?php if (empty($assignments)): ?>
<div class="card" style="padding:40px;text-align:center;color:#94a3b8;">
    <div style="font-size:32px;margin-bottom:8px;">📋</div>
    <p>Chưa có bài tập nào trong học kỳ này.</p>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:14px;">
    <?php foreach ($assignments as $asg):
        $isOverdue   = strtotime($asg['due_date']) < time();
        $hasSubmit   = $asg['submission_id'] !== null;
        $isGraded    = $asg['sub_score'] !== null;
        $timeLeft    = strtotime($asg['due_date']) - time();
        $urgentClass = !$hasSubmit && $timeLeft < 86400 && $timeLeft > 0 ? 'border-left:4px solid #ef4444;' : '';
    ?>
    <div class="card" style="padding:20px 24px;<?= $urgentClass ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                    <span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($asg['course_code']) ?></span>
                    <span style="font-size:11px;color:#64748b;"><?= h($asg['section_code']) ?></span>
                    <?php if ($isGraded): ?>
                    <span class="status-pill status-pill--complete" style="font-size:10px;">✅ Đã có điểm</span>
                    <?php elseif ($hasSubmit): ?>
                    <span class="status-pill status-pill--enrolled" style="font-size:10px;">📤 Đã nộp</span>
                    <?php elseif ($isOverdue): ?>
                    <span class="status-pill status-pill--absent" style="font-size:10px;">⏰ Quá hạn</span>
                    <?php else: ?>
                    <span style="background:#fef9c3;color:#854d0e;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;">Chờ nộp</span>
                    <?php endif; ?>
                </div>
                <h3 style="font-size:16px;font-weight:700;color:#001634;margin-bottom:6px;"><?= h($asg['title']) ?></h3>
                <?php if ($asg['description']): ?>
                <p style="font-size:13px;color:#44474e;margin-bottom:8px;line-height:1.5;"><?= nl2br(h($asg['description'])) ?></p>
                <?php endif; ?>
                <div style="display:flex;gap:16px;font-size:12px;color:#64748b;flex-wrap:wrap;">
                    <span>📅 Hạn nộp: <strong style="color:<?= $isOverdue&&!$hasSubmit?'#ef4444':'#334155' ?>"><?= fmt_dt($asg['due_date']) ?></strong></span>
                    <span>📊 Điểm tối đa: <?= number_format((float)$asg['max_score'], 0) ?></span>
                    <?php if ($asg['attachment_name']): ?>
                    <span>📎 <a href="../../assets/files/<?= h($asg['attachment_path']) ?>" download style="color:#3b82f6;"><?= h($asg['attachment_name']) ?></a></span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Score badge if graded -->
            <?php if ($isGraded): ?>
            <div style="text-align:center;padding:12px 16px;background:#f0fdf4;border-radius:10px;min-width:80px;">
                <div style="font-size:24px;font-weight:800;color:#059669;"><?= number_format((float)$asg['sub_score'], 1) ?></div>
                <div style="font-size:11px;color:#64748b;">/ <?= number_format((float)$asg['max_score'], 0) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Submission feedback if graded -->
        <?php if ($isGraded && $asg['sub_feedback']): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#f8fafc;border-radius:8px;border-left:3px solid #3b82f6;">
            <div style="font-size:12px;font-weight:600;color:#1e40af;margin-bottom:4px;">💬 Nhận xét của giảng viên:</div>
            <div style="font-size:13px;color:#334155;"><?= nl2br(h($asg['sub_feedback'])) ?></div>
        </div>
        <?php endif; ?>

        <!-- Submission info if already submitted -->
        <?php if ($hasSubmit && !$isGraded): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#f0fdf4;border-radius:8px;font-size:12px;color:#166534;">
            📤 Đã nộp: <strong><?= h($asg['sub_file']) ?></strong>
            lúc <?= fmt_dt($asg['submitted_at']) ?>
        </div>
        <?php endif; ?>

        <!-- Upload form (not overdue or late allowed) -->
        <?php if (!$isGraded && (!$isOverdue || true)): // allow resubmit until graded ?>
        <div style="margin-top:14px;padding:14px;background:#f8fafc;border-radius:8px;border:1px dashed #cbd5e1;">
            <div style="font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;">
                <?= $hasSubmit ? '🔄 Nộp lại bài' : '📤 Nộp bài' ?>
                <?php if ($isOverdue): ?><span style="color:#ef4444;font-size:11px;margin-left:6px;">(Quá hạn – có thể bị trừ điểm)</span><?php endif; ?>
            </div>
            <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <?= csrf_input() ?>
                <input type="hidden" name="submit_assignment" value="1" />
                <input type="hidden" name="assignment_id"    value="<?= (int)$asg['assignment_id'] ?>" />
                <input type="hidden" name="enrollment_id"    value="<?= (int)$asg['enrollment_id'] ?>" />
                <input type="file" name="submission_file" required
                       accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.rar,.jpg,.jpeg,.png,.txt,.py,.java,.cpp,.c"
                       style="font-size:13px;" />
                <button type="submit"
                        style="padding:8px 18px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                    Nộp bài
                </button>
                <span style="font-size:11px;color:#94a3b8;">Tối đa 20 MB • PDF, Word, ZIP, ảnh, code…</span>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
