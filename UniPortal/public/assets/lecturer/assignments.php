<?php
/**
 * UniPortal – Lecturer Assignment Management
 * Tabs: (1) Manage Assignments (create/edit/delete)
 *       (2) Grade Submissions (view uploads, enter score/feedback, publish)
 *       (3) Assessment Grading (existing Assessment-based grading)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';
require_once $root . '/src/src/services/NotificationService.php';
require_once $root . '/src/src/services/AcademicAlertService.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo       = get_db();
$lecId     = (int)($_SESSION['lecturer_id'] ?? 0);
$accountId = (int)($_SESSION['account_id']  ?? 0);

$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);
$sections   = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount  = LecturerModel::getUnreadWarningCount($lecId);

// Storage path for assignment attachments
$storageBase = $root . '/storage/uploads/assignments';
if (!is_dir($storageBase)) mkdir($storageBase, 0755, true);

$flash = '';

// ── Helper: verify lecturer owns the section ──────────────────
function lecOwnsSection(int $lecId, int $sectionId, array $sections): bool {
    foreach ($sections as $s) {
        if ((int)$s['section_id'] === $sectionId) return true;
    }
    return false;
}

// ── POST: create assignment ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create_assignment') {
        $sectionId  = (int)($_POST['section_id'] ?? 0);
        $title      = trim($_POST['title']       ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $due        = trim($_POST['due_date']    ?? '');
        $maxScore   = (float)($_POST['max_score'] ?? 10);

        if (!lecOwnsSection($lecId, $sectionId, $sections)) {
            $flash = 'error:Không có quyền tạo bài tập cho lớp này.';
        } elseif ($title === '') {
            $flash = 'error:Tiêu đề bài tập không được để trống.';
        } elseif (!$due || strtotime($due) === false) {
            $flash = 'error:Hạn nộp không hợp lệ.';
        } elseif ($maxScore <= 0) {
            $flash = 'error:Điểm tối đa phải lớn hơn 0.';
        } else {
            $attPath = $attName = $attSize = $attMime = null;

            // Handle optional attachment
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $f       = $_FILES['attachment'];
                $allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx','zip','jpg','jpeg','png','txt'];
                $ext     = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) {
                    $flash = 'error:Định dạng file đính kèm không hợp lệ.';
                } elseif ($f['size'] > 20 * 1024 * 1024) {
                    $flash = 'error:File đính kèm tối đa 20 MB.';
                } else {
                    $dir = $storageBase . '/' . $sectionId;
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
                    if (move_uploaded_file($f['tmp_name'], $dir . '/' . $safeName)) {
                        $attPath = 'assignments/' . $sectionId . '/' . $safeName;
                        $attName = $f['name'];
                        $attSize = $f['size'];
                        $attMime = $f['type'];
                    }
                }
            }

            if (!$flash) {
                $pdo->prepare(
                    'INSERT INTO Assignment
                        (section_id, title, description, attachment_path, attachment_name,
                         attachment_size, attachment_mime, due_date, max_score, is_deleted, created_by)
                     VALUES
                        (:sec, :title, :desc, :ap, :an, :as_, :am, :due, :ms, 0, :by)'
                )->execute([
                    ':sec'   => $sectionId, ':title' => $title,
                    ':desc'  => $desc ?: null, ':ap' => $attPath, ':an' => $attName,
                    ':as_'   => $attSize, ':am' => $attMime,
                    ':due'   => date('Y-m-d H:i:s', strtotime($due)),
                    ':ms'    => $maxScore, ':by' => $accountId,
                ]);
                $newId = (int)$pdo->lastInsertId();

                // Notify enrolled students
                $enrolled = $pdo->prepare(
                    'SELECT a.account_id FROM Enrollment e
                     JOIN Account a ON a.student_id=e.student_id
                     WHERE e.section_id=:sid AND e.status IN (\'Enrolled\',\'Completed\')'
                );
                $enrolled->execute([':sid' => $sectionId]);
                foreach ($enrolled->fetchAll() as $row) {
                    NotificationService::send(
                        (int)$row['account_id'], 'Assignment',
                        '📋 Bài tập mới: ' . $title,
                        'Hạn nộp: ' . date('d/m/Y H:i', strtotime($due)),
                        '/UniPortal/public/assets/student/assignments.php'
                    );
                }

                AcademicAlertService::writeAuditLog(
                    $pdo, $accountId, 'CREATE_ASSIGNMENT', 'Assignment', (string)$newId,
                    null, json_encode(['title' => $title, 'section_id' => $sectionId])
                );
                $flash = 'success:Đã tạo bài tập "' . $title . '" thành công.';
            }
        }
    }

    if ($action === 'edit_assignment') {
        $asgId    = (int)($_POST['assignment_id'] ?? 0);
        $title    = trim($_POST['title']          ?? '');
        $desc     = trim($_POST['description']    ?? '');
        $due      = trim($_POST['due_date']       ?? '');
        $maxScore = (float)($_POST['max_score']   ?? 10);

        // Verify ownership via section
        $ownerCheck = $pdo->prepare(
            'SELECT a.section_id FROM Assignment a
             JOIN ClassSection cs ON cs.section_id=a.section_id
             WHERE a.assignment_id=:aid AND cs.lecturer_id=:lid AND a.is_deleted=0 LIMIT 1'
        );
        $ownerCheck->execute([':aid' => $asgId, ':lid' => $lecId]);
        if (!$ownerCheck->fetch()) {
            $flash = 'error:Không có quyền chỉnh sửa bài tập này.';
        } elseif ($title === '' || !$due || $maxScore <= 0) {
            $flash = 'error:Vui lòng điền đầy đủ thông tin hợp lệ.';
        } else {
            $pdo->prepare(
                'UPDATE Assignment SET title=:title, description=:desc, due_date=:due,
                        max_score=:ms, updated_at=NOW()
                 WHERE assignment_id=:aid'
            )->execute([
                ':title' => $title, ':desc' => $desc ?: null,
                ':due'   => date('Y-m-d H:i:s', strtotime($due)),
                ':ms'    => $maxScore, ':aid' => $asgId,
            ]);
            $flash = 'success:Đã cập nhật bài tập.';
        }
    }

    if ($action === 'delete_assignment') {
        $asgId = (int)($_POST['assignment_id'] ?? 0);
        $ownerCheck = $pdo->prepare(
            'SELECT a.assignment_id FROM Assignment a
             JOIN ClassSection cs ON cs.section_id=a.section_id
             WHERE a.assignment_id=:aid AND cs.lecturer_id=:lid AND a.is_deleted=0 LIMIT 1'
        );
        $ownerCheck->execute([':aid' => $asgId, ':lid' => $lecId]);
        if (!$ownerCheck->fetch()) {
            $flash = 'error:Không có quyền xóa bài tập này.';
        } else {
            // Check for graded submissions
            $graded = (int)$pdo->prepare('SELECT COUNT(*) FROM Submission WHERE assignment_id=:aid AND score IS NOT NULL')
                ->execute([':aid' => $asgId]) ? (function() use ($pdo,$asgId) {
                    $s=$pdo->prepare('SELECT COUNT(*) FROM Submission WHERE assignment_id=:aid AND score IS NOT NULL');
                    $s->execute([':aid'=>$asgId]); return (int)$s->fetchColumn();
                })() : 0;
            if ($graded > 0) {
                $flash = 'error:Không thể xóa – đã có ' . $graded . ' bài nộp đã chấm điểm.';
            } else {
                $pdo->prepare('UPDATE Assignment SET is_deleted=1, updated_at=NOW() WHERE assignment_id=:aid')
                    ->execute([':aid' => $asgId]);
                $flash = 'success:Đã xóa bài tập.';
            }
        }
    }

    if ($action === 'grade_submission') {
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $score        = trim($_POST['score']          ?? '');
        $feedback     = trim($_POST['feedback']       ?? '');
        $publish      = isset($_POST['publish']);

        // Verify ownership
        $sub = $pdo->prepare(
            'SELECT sub.submission_id, sub.assignment_id, sub.enrollment_id,
                    a.max_score, cs.lecturer_id
             FROM Submission sub
             JOIN Assignment a ON a.assignment_id=sub.assignment_id
             JOIN ClassSection cs ON cs.section_id=a.section_id
             WHERE sub.submission_id=:sid AND cs.lecturer_id=:lid LIMIT 1'
        );
        $sub->execute([':sid' => $submissionId, ':lid' => $lecId]);
        $subRow = $sub->fetch();

        if (!$subRow) {
            $flash = 'error:Không tìm thấy bài nộp hoặc không có quyền chấm.';
        } elseif ($score !== '' && ((float)$score < 0 || (float)$score > (float)$subRow['max_score'])) {
            $flash = 'error:Điểm phải từ 0 đến ' . number_format((float)$subRow['max_score'], 0) . '.';
        } else {
            $gradeStatus = $publish ? 'Published' : 'Draft';
            $pdo->prepare(
                'UPDATE Submission SET score=:score, feedback=:fb, grade_status=:gs
                 WHERE submission_id=:sid'
            )->execute([
                ':score' => $score !== '' ? (float)$score : null,
                ':fb'    => $feedback ?: null,
                ':gs'    => $gradeStatus,
                ':sid'   => $submissionId,
            ]);

            if ($publish && $score !== '') {
                // Notify student
                $stu = $pdo->prepare(
                    'SELECT a.account_id FROM Enrollment e
                     JOIN Account a ON a.student_id=e.student_id
                     WHERE e.enrollment_id=:eid LIMIT 1'
                );
                $stu->execute([':eid' => $subRow['enrollment_id']]);
                $stuAcc = (int)($stu->fetchColumn() ?? 0);
                if ($stuAcc) {
                    NotificationService::send(
                        $stuAcc, 'Grade',
                        '📊 Bài tập đã được chấm điểm',
                        'Điểm: ' . number_format((float)$score, 1) . '/' . number_format((float)$subRow['max_score'], 1),
                        '/UniPortal/public/assets/student/assignments.php'
                    );
                }
                // Trigger GPA recalc check
                AcademicAlertService::afterGradeSave((int)$subRow['enrollment_id']);
            }
            $flash = 'success:Đã lưu điểm' . ($publish ? ' và công bố cho sinh viên.' : ' (bản nháp).');
        }
    }

    // Redirect to avoid re-POST on refresh
    if ($flash) {
        $_SESSION['_flash'] = $flash;
        $redirect = 'assignments.php?tab=' . ($_GET['tab'] ?? 'manage');
        if (isset($_GET['section'])) $redirect .= '&section=' . (int)$_GET['section'];
        if (isset($_GET['asg']))     $redirect .= '&asg='     . (int)$_GET['asg'];
        header('Location: ' . $redirect);
        exit;
    }
}

// Pick up flash from session redirect
if (isset($_SESSION['_flash'])) {
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
}

// ── Tab & selection ───────────────────────────────────────────
$tab        = $_GET['tab']     ?? 'manage';
$selSection = (int)($_GET['section'] ?? ($sections[0]['section_id'] ?? 0));
$selAsg     = (int)($_GET['asg']     ?? 0);

$selSectionInfo = null;
foreach ($sections as $s) {
    if ((int)$s['section_id'] === $selSection) { $selSectionInfo = $s; break; }
}

// ── Load assignments for selected section ─────────────────────
$assignments = [];
if ($selSection) {
    $asgSt = $pdo->prepare(
        'SELECT a.*,
                (SELECT COUNT(*) FROM Submission sub WHERE sub.assignment_id=a.assignment_id) AS sub_count,
                (SELECT COUNT(*) FROM Submission sub WHERE sub.assignment_id=a.assignment_id AND sub.score IS NOT NULL) AS graded_count,
                (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=a.section_id AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled_count
         FROM Assignment a
         WHERE a.section_id=:sid AND a.is_deleted=0
         ORDER BY a.due_date ASC'
    );
    $asgSt->execute([':sid' => $selSection]);
    $assignments = $asgSt->fetchAll();
}

// ── Load submissions for selected assignment ──────────────────
$submissions = [];
$selAsgInfo  = null;
if ($selAsg && $tab === 'submissions') {
    // Verify ownership
    $asgOwn = $pdo->prepare(
        'SELECT a.* FROM Assignment a
         JOIN ClassSection cs ON cs.section_id=a.section_id
         WHERE a.assignment_id=:aid AND cs.lecturer_id=:lid AND a.is_deleted=0 LIMIT 1'
    );
    $asgOwn->execute([':aid' => $selAsg, ':lid' => $lecId]);
    $selAsgInfo = $asgOwn->fetch();

    if ($selAsgInfo) {
        $subSt = $pdo->prepare(
            'SELECT sub.*, s.student_id, s.full_name, s.class_code,
                    e.enrollment_id AS enr_id
             FROM Enrollment e
             JOIN Student s ON s.student_id=e.student_id
             LEFT JOIN Submission sub ON sub.assignment_id=:aid AND sub.enrollment_id=e.enrollment_id
             WHERE e.section_id=:sid AND e.status IN (\'Enrolled\',\'Completed\')
             ORDER BY s.student_id ASC'
        );
        $subSt->execute([':aid' => $selAsg, ':sid' => $selAsgInfo['section_id']]);
        $submissions = $subSt->fetchAll();
    }
}

// ── Assessment grading (existing feature, wired to AcademicAlertService) ────
// Handled in separate grade-save POST below

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    $assessmentId = (int)($_POST['assessment_id'] ?? 0);
    $scores       = $_POST['scores'] ?? [];
    $maxScore     = (float)($_POST['max_score'] ?? 100);
    $saved = 0;
    foreach ($scores as $enrollmentId => $score) {
        $score = trim((string)$score);
        if ($score === '' || $score === '—') continue;
        $sf = (float)$score;
        if ($sf < 0 || $sf > $maxScore) continue;
        $ok = LecturerModel::saveGrade((int)$enrollmentId, $assessmentId, $sf, $lecId, (string)($_POST['feedback'][$enrollmentId] ?? ''));
        if ($ok) {
            $saved++;
            AcademicAlertService::afterGradeSave((int)$enrollmentId);
        }
    }
    $flash = $saved > 0
        ? 'success:Đã lưu ' . $saved . ' điểm và cập nhật cảnh báo học vụ.'
        : 'error:Không có điểm nào được lưu. Kiểm tra phạm vi điểm.';
    // Store in session for redirect
    $_SESSION['_flash_assess'] = $flash;
}

// ── Assessment grading data (tab=assess) ─────────────────────
$selAssessment    = (int)($_GET['assessment'] ?? 0);
$assessments      = $selSection ? LecturerModel::getAssessments($selSection) : [];
if (!$selAssessment && !empty($assessments)) $selAssessment = (int)$assessments[0]['assessment_id'];
$selAssessmentInfo = null;
$gradeRows        = [];
if ($selAssessment && $selSection) {
    foreach ($assessments as $a) {
        if ((int)$a['assessment_id'] === $selAssessment) { $selAssessmentInfo = $a; break; }
    }
    $gradeRows = LecturerModel::getGradesForAssessment($selAssessment, $selSection);
}
$pendingStats = LecturerModel::getPendingGradingStats($lecId);

if (isset($_SESSION['_flash_assess'])) {
    $flash = $_SESSION['_flash_assess'];
    unset($_SESSION['_flash_assess']);
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_dt(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }
function fmt_size(?int $b): string {
    if (!$b) return '—';
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b/1024, 1) . ' KB';
    return round($b/1048576, 1) . ' MB';
}
function initials(string $n): string {
    $r=''; foreach(explode(' ',trim($n)) as $w) $r.=strtoupper(substr($w,0,1)); return substr($r,-2);
}

$pageTitle  = 'Bài tập & Chấm bài';
$activePage = 'assignments';
require __DIR__ . '/_layout.php';
?>

<!-- ══ FLASH BANNER ════════════════════════════════════════════ -->
<?php if ($flash): [$ft,$fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- ══ PAGE HEADER ══════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <h1 style="font-size:20px;font-weight:800;color:#001634;">Bài tập &amp; Chấm bài</h1>
    <?php if ($tab === 'manage'): ?>
    <button onclick="document.getElementById('modalCreate').style.display='flex'"
            class="lbtn lbtn--primary">+ Tạo Bài Tập Mới</button>
    <?php endif; ?>
</div>

<!-- ══ TABS ════════════════════════════════════════════════════ -->
<div style="display:flex;gap:4px;background:#fff;border-radius:12px;padding:6px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
    <?php
    $tabs = [
        ['manage',      '📋 Quản lý bài tập'],
        ['submissions', '📤 Bài nộp & Chấm điểm'],
        ['assess',      '📊 Chấm điểm đánh giá (Assessment)'],
    ];
    foreach ($tabs as [$tid, $tlabel]):
        $params = 'tab=' . $tid . ($selSection ? '&section=' . $selSection : '');
        if ($tid === 'submissions' && $selAsg) $params .= '&asg=' . $selAsg;
    ?>
    <a href="assignments.php?<?= $params ?>"
       style="padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;
              background:<?= $tab===$tid?'#0d1b2e':'transparent' ?>;
              color:<?= $tab===$tid?'#fff':'#64748b' ?>;">
        <?= $tlabel ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ══ SECTION SELECTOR ════════════════════════════════════════ -->
<?php if (!empty($sections)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Lớp:</span>
    <?php foreach ($sections as $sec): ?>
    <a href="assignments.php?tab=<?= h($tab) ?>&section=<?= (int)$sec['section_id'] ?>"
       class="lpill <?= (int)$sec['section_id']===$selSection?'lpill--blue':'lpill--gray' ?>">
        <?= h($sec['section_code']) ?> (<?= (int)$sec['enrolled_count'] ?> SV)
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
// TAB 1: MANAGE ASSIGNMENTS
// ════════════════════════════════════════════════════════════
if ($tab === 'manage'):
?>

<?php if (empty($assignments)): ?>
<div class="lcard" style="padding:40px;text-align:center;color:#94a3b8;">
    <div style="font-size:32px;margin-bottom:8px;">📋</div>
    <p>Chưa có bài tập nào cho lớp này. Nhấn "Tạo Bài Tập Mới" để bắt đầu.</p>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:12px;">
    <?php foreach ($assignments as $asg):
        $isOverdue = strtotime($asg['due_date']) < time();
        $pct = $asg['enrolled_count'] > 0 ? round($asg['sub_count'] / $asg['enrolled_count'] * 100) : 0;
        $isDone = $asg['graded_count'] >= $asg['enrolled_count'] && $asg['enrolled_count'] > 0;
    ?>
    <div class="lcard" style="padding:18px 22px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;">
                <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:4px;"><?= h($asg['title']) ?></div>
                <?php if ($asg['description']): ?>
                <div style="font-size:13px;color:#44474e;margin-bottom:8px;line-height:1.5;"><?= nl2br(h(mb_strimwidth($asg['description'], 0, 200, '…'))) ?></div>
                <?php endif; ?>
                <div style="display:flex;gap:16px;font-size:12px;color:#64748b;flex-wrap:wrap;">
                    <span>📅 Hạn: <strong style="color:<?= $isOverdue?'#ef4444':'#334155' ?>"><?= fmt_dt($asg['due_date']) ?></strong></span>
                    <span>📊 Max: <?= number_format((float)$asg['max_score'], 0) ?> điểm</span>
                    <?php if ($asg['attachment_name']): ?>
                    <span>📎 <?= h($asg['attachment_name']) ?> (<?= fmt_size((int)$asg['attachment_size']) ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                <div style="text-align:center;">
                    <div style="font-size:20px;font-weight:800;color:<?= $isDone?'#059669':'#3b82f6' ?>;"><?= (int)$asg['sub_count'] ?>/<?= (int)$asg['enrolled_count'] ?></div>
                    <div style="font-size:10px;color:#94a3b8;">Đã nộp</div>
                </div>
                <div style="height:4px;width:80px;background:#e5eeff;border-radius:9999px;overflow:hidden;">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $isDone?'#059669':'#3b82f6' ?>;border-radius:9999px;"></div>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
            <a href="assignments.php?tab=submissions&section=<?= (int)$asg['section_id'] ?>&asg=<?= (int)$asg['assignment_id'] ?>"
               class="lbtn lbtn--primary lbtn--sm">📤 Xem bài nộp & chấm điểm</a>
            <button onclick="openEdit(<?= htmlspecialchars(json_encode($asg), ENT_QUOTES) ?>)"
                    class="lbtn lbtn--outline lbtn--sm">✏ Sửa</button>
            <?php if ((int)$asg['graded_count'] === 0): ?>
            <form method="POST" style="display:inline;"
                  onsubmit="return confirm('Xóa bài tập \'<?= h(addslashes($asg['title'])) ?>\'?')">
                <input type="hidden" name="_action"      value="delete_assignment" />
                <input type="hidden" name="assignment_id" value="<?= (int)$asg['assignment_id'] ?>" />
                <button type="submit" class="lbtn lbtn--outline lbtn--sm" style="color:#ef4444;">🗑 Xóa</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
// TAB 2: SUBMISSIONS & GRADING
// ════════════════════════════════════════════════════════════
elseif ($tab === 'submissions'):
?>

<!-- Assignment selector for this section -->
<?php if (!empty($assignments)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Bài tập:</span>
    <?php foreach ($assignments as $asg): ?>
    <a href="assignments.php?tab=submissions&section=<?= $selSection ?>&asg=<?= (int)$asg['assignment_id'] ?>"
       class="lpill <?= (int)$asg['assignment_id']===$selAsg?'lpill--blue':'lpill--gray' ?>"
       style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
        <?= h(mb_strimwidth($asg['title'], 0, 30, '…')) ?>
        <span style="margin-left:4px;opacity:.6;">(<?= (int)$asg['sub_count'] ?>/<?= (int)$asg['enrolled_count'] ?>)</span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$selAsgInfo): ?>
<div class="lcard" style="padding:32px;text-align:center;color:#94a3b8;">
    <p>Chọn bài tập ở trên để xem danh sách bài nộp.</p>
</div>
<?php else: ?>

<!-- Assignment info bar -->
<div class="lcard" style="padding:14px 20px;background:#eff4ff;border-left:4px solid #3b82f6;">
    <div style="font-size:15px;font-weight:700;color:#1e40af;"><?= h($selAsgInfo['title']) ?></div>
    <div style="font-size:12px;color:#3b82f6;margin-top:3px;">
        Hạn nộp: <?= fmt_dt($selAsgInfo['due_date']) ?> •
        Điểm tối đa: <?= number_format((float)$selAsgInfo['max_score'], 0) ?>
    </div>
</div>

<!-- Submissions table -->
<div class="lcard" style="padding:0;overflow:hidden;">
    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>Sinh viên</th>
                    <th>Trạng thái nộp</th>
                    <th>Thời gian nộp</th>
                    <th>File đính kèm</th>
                    <th>Điểm (/ <?= number_format((float)$selAsgInfo['max_score'], 0) ?>)</th>
                    <th>Nhận xét</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">
                    Chưa có sinh viên nào trong lớp.
                </td></tr>
                <?php else: ?>
                <?php foreach ($submissions as $sub):
                    $hasFile = !empty($sub['file_path']);
                    $isLate  = $hasFile && strtotime($sub['submitted_at']) > strtotime($selAsgInfo['due_date']);
                    $ini     = initials($sub['full_name']);
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span class="lec-av" style="width:32px;height:32px;"><?= h($ini) ?></span>
                            <div>
                                <div style="font-weight:700;font-size:13px;color:#001634;"><?= h($sub['full_name']) ?></div>
                                <div style="font-size:11px;color:#64748b;"><?= h($sub['student_id']) ?> – <?= h($sub['class_code']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($hasFile): ?>
                        <span class="lpill lpill--green" style="font-size:10px;">✅ Đã nộp<?= $isLate?' (trễ)':'' ?></span>
                        <?php else: ?>
                        <span class="lpill lpill--gray" style="font-size:10px;">Chưa nộp</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#64748b;"><?= $sub['submitted_at'] ? fmt_dt($sub['submitted_at']) : '—' ?></td>
                    <td style="font-size:12px;">
                        <?php if ($hasFile): ?>
                        <a href="<?= h('/UniPortal/storage/' . $sub['file_path']) ?>" download
                           style="color:#3b82f6;display:flex;align-items:center;gap:4px;">
                            📎 <?= h(mb_strimwidth($sub['file_name'] ?? '', 0, 25, '…')) ?>
                            <span style="font-size:10px;color:#94a3b8;">(<?= fmt_size((int)($sub['file_size'] ?? 0)) ?>)</span>
                        </a>
                        <?php else: ?>
                        <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($sub['score'] !== null): ?>
                        <span style="font-size:18px;font-weight:800;color:<?= (float)$sub['score']/$selAsgInfo['max_score']>=0.5?'#059669':'#ef4444' ?>;">
                            <?= number_format((float)$sub['score'], 1) ?>
                        </span>
                        <?php if ($sub['grade_status'] === 'Published'): ?>
                        <span style="font-size:10px;color:#059669;display:block;">✅ Đã công bố</span>
                        <?php else: ?>
                        <span style="font-size:10px;color:#f59e0b;display:block;">📝 Nháp</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;">Chưa chấm</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#44474e;max-width:150px;">
                        <?= $sub['feedback'] ? h(mb_strimwidth($sub['feedback'], 0, 50, '…')) : '—' ?>
                    </td>
                    <td>
                        <?php if ($hasFile): ?>
                        <button onclick="openGrade(
                                    <?= (int)($sub['submission_id'] ?? 0) ?>,
                                    '<?= h(addslashes($sub['full_name'])) ?>',
                                    <?= $sub['score'] !== null ? (float)$sub['score'] : 'null' ?>,
                                    '<?= h(addslashes($sub['feedback'] ?? '')) ?>',
                                    <?= (float)$selAsgInfo['max_score'] ?>
                                )"
                                class="lbtn lbtn--yellow lbtn--sm">
                            <?= $sub['score'] !== null ? '✏ Sửa điểm' : '✅ Chấm bài' ?>
                        </button>
                        <?php else: ?>
                        <span style="font-size:11px;color:#94a3b8;">Chờ nộp</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
// TAB 3: ASSESSMENT-BASED GRADING
// ════════════════════════════════════════════════════════════
elseif ($tab === 'assess'):
?>

<!-- Assessment selector -->
<?php if (!empty($assessments)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Bài đánh giá:</span>
    <?php foreach ($assessments as $a): ?>
    <a href="assignments.php?tab=assess&section=<?= $selSection ?>&assessment=<?= (int)$a['assessment_id'] ?>"
       class="lpill <?= (int)$a['assessment_id']===$selAssessment?'lpill--blue':'lpill--gray' ?>">
        <?= h(mb_strimwidth($a['assessment_name'], 0, 30, '…')) ?>
        <span style="opacity:.6;margin-left:4px;"><?= number_format((float)$a['weight_percent'],0) ?>%</span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($selAssessmentInfo && !empty($gradeRows)): ?>
<div class="lcard" style="padding:14px 20px;background:#eff4ff;border-left:4px solid #3b82f6;">
    <div style="font-size:14px;font-weight:700;color:#1e40af;"><?= h($selAssessmentInfo['assessment_name']) ?></div>
    <div style="font-size:12px;color:#3b82f6;margin-top:2px;">
        Trọng số: <?= number_format((float)$selAssessmentInfo['weight_percent'],0) ?>% •
        Điểm tối đa: <?= number_format((float)$selAssessmentInfo['max_score'],0) ?> •
        Hạn: <?= fmt_dt($selAssessmentInfo['due_date']) ?>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="save_grades"   value="1" />
    <input type="hidden" name="assessment_id" value="<?= (int)$selAssessment ?>" />
    <input type="hidden" name="max_score"     value="<?= h($selAssessmentInfo['max_score']) ?>" />
    <div class="lcard" style="padding:0;overflow:hidden;">
        <div class="lec-table-wrap">
            <table class="lec-table" id="gradeTable">
                <thead>
                    <tr>
                        <th>Sinh viên</th>
                        <th style="width:120px;text-align:center;">Điểm (0–100)</th>
                        <th>Nhận xét</th>
                        <th>Ngày chấm</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gradeRows as $i => $gr):
                        $hasGrade  = $gr['score'] !== null;
                        $scoreDisp = $hasGrade ? number_format((float)$gr['score'], 1) : '';
                        $ini       = initials($gr['full_name']);
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="lec-av" style="width:32px;height:32px;"><?= h($ini) ?></span>
                                <div>
                                    <div style="font-weight:700;font-size:13px;color:#001634;"><?= h($gr['full_name']) ?></div>
                                    <div style="font-size:11px;color:#64748b;"><?= h($gr['student_id']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <input type="number"
                                   class="lec-score-input"
                                   name="scores[<?= (int)$gr['enrollment_id'] ?>]"
                                   value="<?= h($scoreDisp) ?>"
                                   min="0" max="100"
                                   step="0.5"
                                   placeholder="—"
                                   style="width:80px;text-align:center;border:1px solid #e2e8f0;border-radius:8px;padding:6px;font-size:13px;font-weight:700;outline:none;" />
                        </td>
                        <td>
                            <input type="text"
                                   name="feedback[<?= (int)$gr['enrollment_id'] ?>]"
                                   value="<?= h($gr['feedback'] ?? '') ?>"
                                   placeholder="Nhận xét (tùy chọn)"
                                   style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;font-size:12px;outline:none;" />
                        </td>
                        <td style="font-size:11px;color:#94a3b8;">
                            <?= $gr['graded_at'] ? date('d/m H:i', strtotime($gr['graded_at'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid #f1f5f9;background:#f8fafc;flex-wrap:wrap;gap:10px;">
            <span style="font-size:12px;color:#64748b;">
                Điểm nhập theo thang 0–100. GPA và cảnh báo học vụ sẽ tự động cập nhật sau khi lưu.
            </span>
            <div style="display:flex;gap:8px;">
                <button type="reset"  class="lbtn lbtn--outline">Hủy thay đổi</button>
                <button type="submit" class="lbtn lbtn--primary">💾 Lưu điểm &amp; Cập nhật GPA</button>
            </div>
        </div>
    </div>
</form>
<?php elseif ($selSection): ?>
<div class="lcard" style="padding:32px;text-align:center;color:#94a3b8;">
    <p>Chọn bài đánh giá ở trên để nhập điểm.</p>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- ══ MODAL: CREATE ASSIGNMENT ════════════════════════════════ -->
<div id="modalCreate" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
    <button onclick="this.closest('#modalCreate').style.display='none'"
            style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:20px;">+ Tạo Bài Tập Mới</h2>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="_action" value="create_assignment" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Lớp học phần *</label>
                <select name="section_id" required style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= (int)$sec['section_id'] ?>" <?= (int)$sec['section_id']===$selSection?'selected':'' ?>>
                        <?= h($sec['section_code']) ?> – <?= h($sec['course_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tiêu đề bài tập *</label>
                <input type="text" name="title" required maxlength="255"
                       placeholder="VD: Đồ án cuối kỳ – Thiết kế hệ thống"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px;font-size:13px;outline:none;" />
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mô tả / Đề bài</label>
                <textarea name="description" rows="4" placeholder="Mô tả yêu cầu bài tập..."
                          style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:13px;outline:none;resize:vertical;"></textarea>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Hạn nộp *</label>
                <input type="datetime-local" name="due_date" required
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Điểm tối đa *</label>
                <input type="number" name="max_score" required min="1" max="100" value="10"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">File đề bài đính kèm (PDF, Word, ZIP… tối đa 20 MB)</label>
                <input type="file" name="attachment"
                       accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.jpg,.jpeg,.png,.txt"
                       style="font-size:13px;width:100%;" />
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="document.getElementById('modalCreate').style.display='none'"
                    class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--primary">Tạo bài tập</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: EDIT ASSIGNMENT ══════════════════════════════════ -->
<div id="modalEdit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
    <button onclick="this.closest('#modalEdit').style.display='none'"
            style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:20px;">✏ Chỉnh sửa Bài tập</h2>
    <form method="POST">
        <input type="hidden" name="_action"      value="edit_assignment" />
        <input type="hidden" name="assignment_id" id="editAsgId" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tiêu đề *</label>
                <input type="text" name="title" id="editTitle" required maxlength="255"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px;font-size:13px;outline:none;" />
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mô tả</label>
                <textarea name="description" id="editDesc" rows="3"
                          style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:13px;outline:none;resize:vertical;"></textarea>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Hạn nộp *</label>
                <input type="datetime-local" name="due_date" id="editDue" required
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Điểm tối đa *</label>
                <input type="number" name="max_score" id="editMax" required min="1" max="100"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="document.getElementById('modalEdit').style.display='none'"
                    class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--primary">Lưu thay đổi</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: GRADE SUBMISSION ═════════════════════════════════ -->
<div id="modalGrade" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:440px;padding:28px;position:relative;">
    <button onclick="document.getElementById('modalGrade').style.display='none'"
            style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:4px;">✅ Chấm bài</h2>
    <p id="gradeStudentName" style="font-size:13px;color:#64748b;margin-bottom:20px;"></p>
    <form method="POST">
        <input type="hidden" name="_action"      value="grade_submission" />
        <input type="hidden" name="submission_id" id="gradeSubId" />
        <div style="display:flex;flex-direction:column;gap:14px;">
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">
                    Điểm (0 – <span id="gradeMaxLabel">10</span>) *
                </label>
                <input type="number" name="score" id="gradeScore"
                       min="0" step="0.5" required
                       style="width:100%;height:40px;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px;font-size:18px;font-weight:700;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Nhận xét</label>
                <textarea name="feedback" id="gradeFeedback" rows="3"
                          placeholder="Nhận xét về bài nộp (tùy chọn)..."
                          style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:13px;outline:none;resize:vertical;"></textarea>
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="publish" id="gradePublish" />
                <span>Công bố điểm cho sinh viên ngay</span>
            </label>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="document.getElementById('modalGrade').style.display='none'"
                    class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--primary">💾 Lưu điểm</button>
        </div>
    </form>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
// Create modal
document.querySelectorAll('[id^="modal"]').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('[id^="modal"]').forEach(m => m.style.display = 'none');
});

function openEdit(asg) {
    document.getElementById('editAsgId').value  = asg.assignment_id;
    document.getElementById('editTitle').value  = asg.title;
    document.getElementById('editDesc').value   = asg.description || '';
    document.getElementById('editMax').value    = asg.max_score;
    // Format datetime-local
    if (asg.due_date) {
        var d = asg.due_date.replace(' ', 'T').substring(0, 16);
        document.getElementById('editDue').value = d;
    }
    document.getElementById('modalEdit').style.display = 'flex';
}

function openGrade(subId, studentName, score, feedback, maxScore) {
    document.getElementById('gradeSubId').value       = subId;
    document.getElementById('gradeStudentName').textContent = studentName;
    document.getElementById('gradeScore').value       = score !== null ? score : '';
    document.getElementById('gradeScore').max         = maxScore;
    document.getElementById('gradeMaxLabel').textContent = maxScore;
    document.getElementById('gradeFeedback').value    = feedback;
    document.getElementById('gradePublish').checked   = false;
    document.getElementById('modalGrade').style.display = 'flex';
}

// Score input validation
document.querySelectorAll('.lec-score-input').forEach(function(inp) {
    inp.addEventListener('input', function() {
        var max = parseFloat(this.max) || 100;
        this.style.color = parseFloat(this.value) > max ? '#ef4444' : '#001634';
    });
});
</script>
