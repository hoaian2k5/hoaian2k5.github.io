<?php
/**
 * UniPortal – Admin: Enrollment & Semester Registration Management
 * Tabs: (1) Semesters + Registration Windows, (2) Enrollment Management
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/NotificationService.php';
require_once $root . '/src/src/services/AcademicAlertService.php';

require_login();
if ($_SESSION['role'] !== 'AcademicAffairs') { header('Location: /UniPortal/public/index.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo       = get_db();
$adminAccId= (int)($_SESSION['account_id'] ?? 25);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'update_semester') {
        $semId    = (int)($_POST['semester_id'] ?? 0);
        $regOpen  = trim($_POST['reg_open']  ?? '');
        $regClose = trim($_POST['reg_close'] ?? '');
        $status   = in_array($_POST['status']??'',['Upcoming','Active','Completed']) ? $_POST['status'] : 'Upcoming';

        if ($semId < 1) { $_SESSION['_flash']='error:Học kỳ không hợp lệ.'; }
        else {
            if ($status === 'Active') {
                $pdo->query("UPDATE Semester SET status='Completed' WHERE status='Active' AND semester_id != $semId");
            }
            $pdo->prepare(
                'UPDATE Semester SET status=:st, reg_open=:ro, reg_close=:rc WHERE semester_id=:id'
            )->execute([
                ':st' => $status,
                ':ro' => $regOpen  ? date('Y-m-d H:i:s', strtotime($regOpen))  : null,
                ':rc' => $regClose ? date('Y-m-d H:i:s', strtotime($regClose)) : null,
                ':id' => $semId,
            ]);

            // Notify all students when registration opens
            if ($status === 'Active' && $regOpen) {
                NotificationService::broadcast('Student','Registration',
                    '📝 Mở đăng ký môn học: ' . (function() use ($pdo,$semId) {
                        $r=$pdo->prepare('SELECT semester_name, academic_year FROM Semester WHERE semester_id=:id LIMIT 1');
                        $r->execute([':id'=>$semId]); $row=$r->fetch();
                        return ($row['semester_name']??'').' '.($row['academic_year']??'');
                    })(),
                    'Đăng ký môn học đã mở. Vào Cổng sinh viên để đăng ký.',
                    '/UniPortal/public/assets/student/registration.php'
                );
            }
            AcademicAlertService::writeAuditLog($pdo,$adminAccId,'UPDATE_SEMESTER','Semester',(string)$semId,null,json_encode(['status'=>$status]));
            $_SESSION['_flash'] = 'success:Đã cập nhật học kỳ.';
        }
        header('Location: enrollment.php?tab=semesters'); exit;
    }

    if ($action === 'add_enrollment') {
        $studentId = trim($_POST['student_id'] ?? '');
        $sectionId = (int)($_POST['section_id'] ?? 0);
        if ($studentId && $sectionId) {
            // Check capacity
            $secRow = $pdo->prepare('SELECT capacity FROM ClassSection WHERE section_id=:sid LIMIT 1');
            $secRow->execute([':sid'=>$sectionId]);
            $sec = $secRow->fetch();
            $enrolled = (int)$pdo->prepare('SELECT COUNT(*) FROM Enrollment WHERE section_id=:sid AND status IN (\'Enrolled\',\'Completed\')')->execute([':sid'=>$sectionId]) ? (function() use ($pdo,$sectionId) {
                $s=$pdo->prepare('SELECT COUNT(*) FROM Enrollment WHERE section_id=:sid AND status IN (\'Enrolled\',\'Completed\')');
                $s->execute([':sid'=>$sectionId]); return (int)$s->fetchColumn();
            })() : 0;

            $dup = $pdo->prepare('SELECT 1 FROM Enrollment WHERE student_id=:s AND section_id=:sec LIMIT 1');
            $dup->execute([':s'=>$studentId,':sec'=>$sectionId]);
            if ($dup->fetch()) {
                $_SESSION['_flash'] = 'error:Sinh viên đã đăng ký lớp này rồi.';
            } elseif ($sec['capacity'] && $enrolled >= $sec['capacity']) {
                $_SESSION['_flash'] = 'error:Lớp đã đầy.';
            } else {
                $pdo->prepare('INSERT INTO Enrollment (student_id,section_id,status,enrollment_date) VALUES (:s,:sec,\'Enrolled\',NOW())')
                    ->execute([':s'=>$studentId,':sec'=>$sectionId]);
                AcademicAlertService::writeAuditLog($pdo,$adminAccId,'ADMIN_ENROLL','Enrollment',$studentId,null,json_encode(['section_id'=>$sectionId]));
                $_SESSION['_flash'] = 'success:Đã thêm đăng ký cho sinh viên ' . $studentId . '.';
            }
        } else {
            $_SESSION['_flash'] = 'error:Thiếu thông tin sinh viên hoặc lớp học phần.';
        }
        header('Location: enrollment.php?tab=enrollments&sem=' . (int)($_POST['semester_id']??4)); exit;
    }

    if ($action === 'update_enrollment_status') {
        $eid    = (int)($_POST['enrollment_id'] ?? 0);
        $status = in_array($_POST['status']??'',['Enrolled','Completed','Withdrawn']) ? $_POST['status'] : '';
        if ($eid && $status) {
            $pdo->prepare('UPDATE Enrollment SET status=:s WHERE enrollment_id=:id')
                ->execute([':s'=>$status,':id'=>$eid]);
            $_SESSION['_flash'] = 'success:Đã cập nhật trạng thái đăng ký.';
        }
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'enrollment.php?tab=enrollments')); exit;
    }
}

$flash = $_SESSION['_flash'] ?? ''; unset($_SESSION['_flash']);
$tab   = $_GET['tab'] ?? 'semesters';
$selSem= (int)($_GET['sem'] ?? 0);

// ── Data ──────────────────────────────────────────────────────
$semesters = $pdo->query('SELECT * FROM Semester ORDER BY start_date DESC')->fetchAll();
if (!$selSem && !empty($semesters)) $selSem = (int)$semesters[0]['semester_id'];

// Enrollment data
$searchStu = trim($_GET['stu'] ?? '');
$whereE = "WHERE cs.semester_id=:sem"; $paramsE = [':sem'=>$selSem];
if ($searchStu) { $whereE .= " AND (s.student_id LIKE :q OR s.full_name LIKE :q)"; $paramsE[':q']='%'.$searchStu.'%'; }

$enrollments = [];
if ($selSem && $tab === 'enrollments') {
    $enrSt = $pdo->prepare(
        "SELECT e.enrollment_id, e.student_id, e.status, e.enrollment_date,
                s.full_name, s.class_code,
                cs.section_code, c.course_code, c.course_name, c.credits
         FROM Enrollment e
         JOIN Student s      ON s.student_id  = e.student_id
         JOIN ClassSection cs ON cs.section_id = e.section_id
         JOIN Course c        ON c.course_id   = cs.course_id
         $whereE
         ORDER BY s.student_id, c.course_code
         LIMIT 200"
    );
    $enrSt->execute($paramsE);
    $enrollments = $enrSt->fetchAll();
}

// Sections for the enrollment add form
$sections = [];
if ($selSem) {
    $secSt = $pdo->prepare(
        'SELECT cs.section_id, cs.section_code, c.course_code, c.course_name,
                l.full_name AS lecturer_name,
                cs.capacity,
                (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=cs.section_id AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled
         FROM ClassSection cs
         JOIN Course c ON c.course_id=cs.course_id
         JOIN Lecturer l ON l.lecturer_id=cs.lecturer_id
         WHERE cs.semester_id=:sem ORDER BY cs.section_code'
    );
    $secSt->execute([':sem'=>$selSem]);
    $sections = $secSt->fetchAll();
}

$students = $pdo->query("SELECT student_id, full_name FROM Student WHERE status='Active' ORDER BY student_id")->fetchAll();

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Đăng ký học phần';
$activePage = 'enrollment';
$breadcrumb = 'Quản trị › Đăng ký học phần';
require __DIR__ . '/_layout.php';
?>

<?php if ($flash): [$ft,$fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:2px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<h1 style="font-size:20px;font-weight:800;color:#0d1b2e;">Quản lý Đăng ký Học phần</h1>

<!-- Tabs -->
<div style="display:flex;gap:4px;background:#fff;border-radius:10px;padding:5px;box-shadow:0 1px 3px rgba(0,0,0,.06);width:fit-content;">
    <a href="enrollment.php?tab=semesters&sem=<?= $selSem ?>"
       style="padding:7px 16px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;
              background:<?= $tab==='semesters'?'#0d1b2e':'transparent' ?>;
              color:<?= $tab==='semesters'?'#fff':'#64748b' ?>;">📅 Học kỳ & Đăng ký</a>
    <a href="enrollment.php?tab=enrollments&sem=<?= $selSem ?>"
       style="padding:7px 16px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;
              background:<?= $tab==='enrollments'?'#0d1b2e':'transparent' ?>;
              color:<?= $tab==='enrollments'?'#fff':'#64748b' ?>;">👥 Danh sách Đăng ký</a>
</div>

<!-- Semester selector -->
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Học kỳ:</span>
    <?php foreach ($semesters as $sem): ?>
    <a href="enrollment.php?tab=<?= h($tab) ?>&sem=<?= (int)$sem['semester_id'] ?>"
       class="apill <?= (int)$sem['semester_id']===$selSem?'apill--blue':'apill--gray' ?>"
       style="text-decoration:none;">
        <?= h($sem['semester_name']) ?> <?= h($sem['academic_year']) ?>
        <span style="font-size:10px;opacity:.7;margin-left:3px;">[<?= h($sem['status']) ?>]</span>
    </a>
    <?php endforeach; ?>
</div>

<?php
// ════ TAB 1: SEMESTERS ════════════════════════════════════════
if ($tab === 'semesters'):
    $selSemData = null;
    foreach ($semesters as $s) { if ((int)$s['semester_id']===$selSem) { $selSemData=$s; break; } }
?>
<?php if ($selSemData): ?>
<div class="acard" style="padding:22px 24px;max-width:600px;">
    <div style="font-size:15px;font-weight:700;color:#0d1b2e;margin-bottom:16px;">
        ⚙️ Cấu hình học kỳ <?= h($selSemData['semester_name']) ?> <?= h($selSemData['academic_year']) ?>
    </div>
    <form method="POST">
        <input type="hidden" name="_action"    value="update_semester" />
        <input type="hidden" name="semester_id" value="<?= (int)$selSem ?>" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trạng thái</label>
                <select name="status" style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="Upcoming"  <?= $selSemData['status']==='Upcoming' ?'selected':'' ?>>Sắp tới</option>
                    <option value="Active"    <?= $selSemData['status']==='Active'   ?'selected':'' ?>>Đang diễn ra</option>
                    <option value="Completed" <?= $selSemData['status']==='Completed'?'selected':'' ?>>Đã kết thúc</option>
                </select>
            </div>
            <div></div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">
                    📝 Mở đăng ký lúc
                </label>
                <input type="datetime-local" name="reg_open"
                       value="<?= $selSemData['reg_open'] ? date('Y-m-d\TH:i', strtotime($selSemData['reg_open'])) : '' ?>"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">
                    🔒 Đóng đăng ký lúc
                </label>
                <input type="datetime-local" name="reg_close"
                       value="<?= $selSemData['reg_close'] ? date('Y-m-d\TH:i', strtotime($selSemData['reg_close'])) : '' ?>"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">💾 Lưu cấu hình</button>
        </div>
    </form>
    <div style="margin-top:14px;padding:10px 12px;background:#f8fafc;border-radius:8px;font-size:12px;color:#64748b;">
        <strong>Hiện tại:</strong>
        Học kỳ <?= h($selSemData['status']) ?> •
        Đăng ký: <?= $selSemData['reg_open'] ? date('d/m/Y H:i', strtotime($selSemData['reg_open'])) : '—' ?>
        → <?= $selSemData['reg_close'] ? date('d/m/Y H:i', strtotime($selSemData['reg_close'])) : '—' ?>
    </div>
</div>

<!-- Sections summary for this semester -->
<div class="acard" style="padding:0;overflow:hidden;">
    <div style="padding:12px 20px;border-bottom:1px solid #f1f5f9;font-size:13px;font-weight:600;color:#334155;">
        Lớp học phần trong học kỳ (<?= count($sections) ?> lớp)
    </div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>Mã lớp</th><th>Môn học</th><th>Giảng viên</th><th style="text-align:center;">Đăng ký / Sĩ số</th></tr></thead>
            <tbody>
                <?php if (empty($sections)): ?>
                <tr><td colspan="4" style="text-align:center;padding:24px;color:#94a3b8;">Không có lớp học phần.</td></tr>
                <?php else: ?>
                <?php foreach ($sections as $sec):
                    $pct = $sec['capacity'] ? round($sec['enrolled']/$sec['capacity']*100) : 0;
                    $color = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#059669');
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:12px;"><?= h($sec['section_code']) ?></td>
                    <td><?= h($sec['course_code']) ?> – <?= h($sec['course_name']) ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= h($sec['lecturer_name']) ?></td>
                    <td style="text-align:center;">
                        <span style="font-weight:700;color:<?= $color ?>;"><?= (int)$sec['enrolled'] ?></span>
                        <?php if ($sec['capacity']): ?>
                        <span style="color:#94a3b8;font-size:11px;">/ <?= (int)$sec['capacity'] ?></span>
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
// ════ TAB 2: ENROLLMENTS ══════════════════════════════════════
elseif ($tab === 'enrollments'):
?>

<!-- Add enrollment form -->
<div class="acard" style="padding:16px 20px;">
    <div style="font-size:13px;font-weight:600;color:#334155;margin-bottom:10px;">➕ Thêm đăng ký thủ công</div>
    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="_action"    value="add_enrollment" />
        <input type="hidden" name="semester_id" value="<?= $selSem ?>" />
        <div>
            <label style="font-size:11px;font-weight:600;color:#334155;display:block;margin-bottom:3px;">Sinh viên *</label>
            <select name="student_id" required style="height:36px;min-width:220px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                <option value="">— Chọn sinh viên —</option>
                <?php foreach ($students as $sv): ?>
                <option value="<?= h($sv['student_id']) ?>"><?= h($sv['student_id']) ?> – <?= h($sv['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#334155;display:block;margin-bottom:3px;">Lớp học phần *</label>
            <select name="section_id" required style="height:36px;min-width:220px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                <option value="">— Chọn lớp —</option>
                <?php foreach ($sections as $sec): ?>
                <option value="<?= (int)$sec['section_id'] ?>">
                    <?= h($sec['section_code']) ?> (<?= (int)$sec['enrolled'] ?>/<?= $sec['capacity']?:(int)$sec['enrolled'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="padding-top:14px;">
            <button type="submit" class="abtn abtn--primary abtn--sm">➕ Thêm</button>
        </div>
    </form>
</div>

<!-- Search -->
<div class="acard" style="padding:12px 16px;">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="tab" value="enrollments" />
        <input type="hidden" name="sem" value="<?= $selSem ?>" />
        <div style="position:relative;">
            <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:12px;opacity:.4;" />
            <input type="search" name="stu" value="<?= h($searchStu) ?>"
                   placeholder="Tìm MSSV, tên sinh viên..."
                   style="width:260px;height:34px;background:#f1f5f9;border:none;border-radius:8px;padding:0 10px 0 28px;font-size:13px;outline:none;" />
        </div>
        <button type="submit" class="abtn abtn--primary abtn--sm">Tìm</button>
        <?php if ($searchStu): ?><a href="enrollment.php?tab=enrollments&sem=<?= $selSem ?>" class="abtn abtn--outline abtn--sm">✕</a><?php endif; ?>
        <span style="font-size:12px;color:#64748b;">Hiển thị <?= count($enrollments) ?> bản ghi</span>
    </form>
</div>

<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>Sinh viên</th><th>Lớp học phần</th><th>Môn học</th><th style="text-align:center;">TC</th><th>Ngày ĐK</th><th>Trạng thái</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($enrollments)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Không có dữ liệu đăng ký.</td></tr>
                <?php else: ?>
                <?php foreach ($enrollments as $e):
                    $stColor = ['Enrolled'=>'apill--green','Completed'=>'apill--blue','Withdrawn'=>'apill--gray'][$e['status']] ?? 'apill--gray';
                    $stLabel = ['Enrolled'=>'Đang học','Completed'=>'Hoàn thành','Withdrawn'=>'Đã rút'][$e['status']] ?? $e['status'];
                ?>
                <tr>
                    <td>
                        <div style="font-weight:600;font-size:13px;color:#0d1b2e;"><?= h($e['full_name']) ?></div>
                        <div style="font-size:11px;color:#64748b;"><?= h($e['student_id']) ?> • <?= h($e['class_code']) ?></div>
                    </td>
                    <td style="font-family:monospace;font-size:12px;"><?= h($e['section_code']) ?></td>
                    <td style="font-size:12px;color:#44474e;"><?= h($e['course_code']) ?> – <?= h($e['course_name']) ?></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$e['credits'] ?></td>
                    <td style="font-size:11px;color:#94a3b8;"><?= date('d/m/Y', strtotime($e['enrollment_date'])) ?></td>
                    <td><span class="apill <?= $stColor ?>" style="font-size:11px;"><?= $stLabel ?></span></td>
                    <td>
                        <form method="POST" style="display:inline-flex;gap:4px;">
                            <input type="hidden" name="_action"       value="update_enrollment_status" />
                            <input type="hidden" name="enrollment_id"  value="<?= (int)$e['enrollment_id'] ?>" />
                            <select name="status" style="height:28px;padding:0 6px;font-size:11px;border:1px solid #e2e8f0;border-radius:6px;">
                                <?php foreach (['Enrolled'=>'Đang học','Completed'=>'HT','Withdrawn'=>'Rút'] as $sv=>$sl): ?>
                                <option value="<?= $sv ?>" <?= $e['status']===$sv?'selected':'' ?>><?= $sl ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" style="height:28px;padding:0 8px;background:#102b50;color:#fff;border:none;border-radius:6px;font-size:11px;cursor:pointer;">💾</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>
