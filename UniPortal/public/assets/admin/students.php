<?php
/**
 * UniPortal – Quản lý Sinh viên & Tài khoản
 * Fully wired: Add, Edit, View detail, Lock/Unlock account.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: /UniPortal/public/assets/lecturer/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();

// ── POST handlers ──────────────────────────────────────────────
$flash = '';

// ── Helper: validate & upsert student + account ───────────────
function doSaveStudent(PDO $pdo, array $p, bool $isNew): string {
    $sid   = strtoupper(trim($p['student_id']   ?? ''));
    $name  = trim($p['full_name']               ?? '');
    $class = trim($p['class_code']              ?? '');
    $email = trim($p['email']                   ?? '');
    $phone = trim($p['phone']                   ?? '') ?: null;
    $dob   = trim($p['date_of_birth']           ?? '') ?: null;
    $gender= in_array($p['gender'] ?? '', ['Male','Female','Other']) ? $p['gender'] : null;
    $year  = (int)($p['admission_year']         ?? date('Y'));
    $dept  = ($p['department_id'] ?? '') !== '' ? (int)$p['department_id'] : null;
    $status= in_array($p['status'] ?? '', ['Active','Suspended','Graduated','Withdrawn'])
                ? $p['status'] : 'Active';

    if ($sid === '')    return 'error:Mã sinh viên không được để trống.';
    if (!preg_match('/^\d{8}$/', $sid)) return 'error:Mã sinh viên phải gồm đúng 8 chữ số.';
    if ($name === '')   return 'error:Họ và tên không được để trống.';
    if ($class === '')  return 'error:Mã lớp không được để trống.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
        return 'error:Email không hợp lệ.';
    if ($year < 2000 || $year > (int)date('Y') + 1)
        return 'error:Năm nhập học không hợp lệ.';

    if ($isNew) {
        // Check duplicate student_id
        $ck = $pdo->prepare('SELECT 1 FROM Student WHERE student_id = :sid LIMIT 1');
        $ck->execute([':sid' => $sid]);
        if ($ck->fetch()) return 'error:Mã sinh viên đã tồn tại.';

        // Check duplicate email
        $ckE = $pdo->prepare('SELECT 1 FROM Student WHERE email = :email LIMIT 1');
        $ckE->execute([':email' => $email]);
        if ($ckE->fetch()) return 'error:Email đã được sử dụng bởi sinh viên khác.';

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO Student (student_id,full_name,class_code,phone,email,gender,
                            date_of_birth,department_id,admission_year,status)
                 VALUES (:sid,:name,:class,:phone,:email,:gender,:dob,:dept,:year,:status)'
            )->execute([
                ':sid'    => $sid,
                ':name'   => $name,
                ':class'  => $class,
                ':phone'  => $phone,
                ':email'  => $email,
                ':gender' => $gender,
                ':dob'    => $dob,
                ':dept'   => $dept,
                ':year'   => $year,
                ':status' => $status,
            ]);

            // Create account with default password = student_id
            $hash = password_hash($sid, PASSWORD_BCRYPT);
            $pdo->prepare(
                'INSERT INTO Account (username,password_hash,role,student_id,is_active)
                 VALUES (:user,:hash,\'Student\',:sid,TRUE)'
            )->execute([':user' => $sid, ':hash' => $hash, ':sid' => $sid]);

            $pdo->commit();
            return 'success:Đã thêm sinh viên ' . $sid . '. Mật khẩu mặc định: ' . $sid;
        } catch (Exception $e) {
            $pdo->rollBack();
            return 'error:Lỗi khi thêm sinh viên: ' . $e->getMessage();
        }
    } else {
        // Edit existing
        $origSid = trim($p['orig_student_id'] ?? $sid);

        // Check email unique (excluding self)
        $ckE = $pdo->prepare('SELECT 1 FROM Student WHERE email = :email AND student_id != :sid LIMIT 1');
        $ckE->execute([':email' => $email, ':sid' => $origSid]);
        if ($ckE->fetch()) return 'error:Email đã được sử dụng bởi sinh viên khác.';

        $pdo->prepare(
            'UPDATE Student SET full_name=:name,class_code=:class,phone=:phone,email=:email,
                    gender=:gender,date_of_birth=:dob,department_id=:dept,
                    admission_year=:year,status=:status
             WHERE student_id=:sid'
        )->execute([
            ':name'   => $name,
            ':class'  => $class,
            ':phone'  => $phone,
            ':email'  => $email,
            ':gender' => $gender,
            ':dob'    => $dob,
            ':dept'   => $dept,
            ':year'   => $year,
            ':status' => $status,
            ':sid'    => $origSid,
        ]);
        return 'success:Đã cập nhật thông tin sinh viên ' . $origSid . '.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add_student') {
        $flash = doSaveStudent($pdo, $_POST, true);
        $_SESSION['_flash'] = ['type' => explode(':', $flash)[0], 'message' => substr($flash, strpos($flash,':')+1)];
        header('Location: students.php');
        exit;
    }

    if ($action === 'edit_student') {
        $flash = doSaveStudent($pdo, $_POST, false);
        $_SESSION['_flash'] = ['type' => explode(':', $flash)[0], 'message' => substr($flash, strpos($flash,':')+1)];
        header('Location: students.php');
        exit;
    }

    if ($action === 'toggle_lock') {
        $sid    = trim($_POST['student_id'] ?? '');
        $lock   = (int)($_POST['lock'] ?? 1); // 1=lock, 0=unlock
        if ($sid !== '') {
            $pdo->prepare('UPDATE Account SET is_active = :v WHERE student_id = :sid')
                ->execute([':v' => $lock ? 0 : 1, ':sid' => $sid]);
            $msg = $lock ? 'Đã khóa tài khoản sinh viên ' . $sid : 'Đã mở khóa tài khoản sinh viên ' . $sid;
            $_SESSION['_flash'] = ['type' => 'success', 'message' => $msg];
        }
        header('Location: students.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
        exit;
    }

    if ($action === 'reset_password') {
        $sid = trim($_POST['student_id'] ?? '');
        if ($sid !== '') {
            $hash = password_hash($sid, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE Account SET password_hash = :h WHERE student_id = :sid')
                ->execute([':h' => $hash, ':sid' => $sid]);
            $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Đã đặt lại mật khẩu về mặc định: ' . $sid];
        }
        header('Location: students.php');
        exit;
    }
}

// ── Flash from session ────────────────────────────────────────
if (isset($_SESSION['_flash'])) {
    $flash = $_SESSION['_flash']['type'] . ':' . $_SESSION['_flash']['message'];
    unset($_SESSION['_flash']);
}

// ── Stats ────────────────────────────────────────────────────
$totalSV    = (int)$pdo->query("SELECT COUNT(*) FROM Student WHERE status='Active'")->fetchColumn();
$totalAcc   = (int)$pdo->query("SELECT COUNT(*) FROM Account WHERE is_active=TRUE")->fetchColumn();
$activeWarn = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM AcademicWarning WHERE status='Active'")->fetchColumn();
$pendingAcc = (int)$pdo->query("SELECT COUNT(*) FROM Account WHERE is_active=FALSE")->fetchColumn();

// ── Filters ──────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$deptF   = trim($_GET['dept']   ?? '');
$statusF = trim($_GET['status'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$where  = "WHERE 1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (s.student_id LIKE :q OR s.full_name LIKE :q OR s.email LIKE :q OR s.class_code LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}
if ($deptF !== '') {
    $where .= " AND d.department_code = :dept";
    $params[':dept'] = $deptF;
}
if ($statusF !== '') {
    $where .= " AND s.status = :st";
    $params[':st'] = $statusF;
}

$countSt = $pdo->prepare("SELECT COUNT(*) FROM Student s LEFT JOIN Department d ON d.department_id=s.department_id $where");
$countSt->execute($params);
$totalFiltered = (int)$countSt->fetchColumn();
$totalPages    = max(1, (int)ceil($totalFiltered / $perPage));

$listSt = $pdo->prepare(
    "SELECT s.student_id, s.full_name, s.class_code, s.email, s.phone,
            s.gender, s.date_of_birth, s.admission_year, s.status,
            d.department_name, d.department_code, d.department_id,
            a.is_active AS acc_active, a.account_id,
            (SELECT COUNT(*) FROM AcademicWarning aw WHERE aw.student_id=s.student_id AND aw.status='Active') AS warn_cnt,
            (SELECT ROUND(SUM(gs2.grade_point*c2.credits)/NULLIF(SUM(c2.credits),0),2)
             FROM Enrollment e2 JOIN ClassSection cs2 ON cs2.section_id=e2.section_id
             JOIN Course c2 ON c2.course_id=cs2.course_id
             JOIN Assessment a2 ON a2.section_id=cs2.section_id
             JOIN Grade g2 ON g2.enrollment_id=e2.enrollment_id AND g2.assessment_id=a2.assessment_id
             JOIN GradeScale gs2 ON (g2.score/10) BETWEEN gs2.min_score AND gs2.max_score
             WHERE e2.student_id=s.student_id AND e2.status IN ('Enrolled','Completed')) AS cum_gpa,
            (SELECT COUNT(*) FROM Enrollment e3 WHERE e3.student_id=s.student_id AND e3.status IN ('Enrolled','Completed')) AS completed_courses
     FROM Student s
     LEFT JOIN Department d ON d.department_id=s.department_id
     LEFT JOIN Account a ON a.student_id=s.student_id
     $where
     ORDER BY s.student_id ASC
     LIMIT :lim OFFSET :off"
);
$params[':lim'] = $perPage;
$params[':off'] = $offset;
$listSt->execute($params);
$students = $listSt->fetchAll();

$depts = $pdo->query('SELECT department_id, department_code, department_name FROM Department ORDER BY department_code')->fetchAll();

// ── Helpers ───────────────────────────────────────────────────
$avColors = ['#1a3a6b','#0f5132','#6f5600','#7c3aed','#b91c1c','#0369a1','#166534','#92400e'];
function initials(string $n): string {
    $r=''; foreach(explode(' ',trim($n)) as $w) $r.=strtoupper(substr($w,0,1)); return substr($r,-2);
}
function avColor(string $id): string {
    global $avColors; return $avColors[abs(crc32($id)) % count($avColors)];
}
function statusLabel(string $s): array {
    return match($s) {
        'Active'    => ['Đang học',        'apill--green'],
        'Graduated' => ['Tốt nghiệp',      'apill--blue'],
        'Suspended' => ['Cảnh báo học vụ', 'apill--red'],
        'Withdrawn' => ['Thôi học',        'apill--gray'],
        default     => [$s,                'apill--gray'],
    };
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── View detail: AJAX-like GET ────────────────────────────────
if (isset($_GET['view'])) {
    $vsid = trim($_GET['view']);
    $vs   = $pdo->prepare(
        'SELECT s.*, d.department_name, d.department_code, a.is_active, a.created_at AS acc_created,
                (SELECT COUNT(*) FROM AcademicWarning aw WHERE aw.student_id=s.student_id AND aw.status=\'Active\') AS warn_cnt,
                (SELECT ROUND(SUM(gs2.grade_point*c2.credits)/NULLIF(SUM(c2.credits),0),2)
                 FROM Enrollment e2 JOIN ClassSection cs2 ON cs2.section_id=e2.section_id
                 JOIN Course c2 ON c2.course_id=cs2.course_id
                 JOIN Assessment a2 ON a2.section_id=cs2.section_id
                 JOIN Grade g2 ON g2.enrollment_id=e2.enrollment_id AND g2.assessment_id=a2.assessment_id
                 JOIN GradeScale gs2 ON (g2.score/10) BETWEEN gs2.min_score AND gs2.max_score
                 WHERE e2.student_id=s.student_id AND e2.status IN (\'Enrolled\',\'Completed\')) AS cum_gpa
         FROM Student s
         LEFT JOIN Department d ON d.department_id=s.department_id
         LEFT JOIN Account a ON a.student_id=s.student_id
         WHERE s.student_id=:sid LIMIT 1'
    );
    $vs->execute([':sid' => $vsid]);
    $vStudent = $vs->fetch();
    // Return JSON for modal
    header('Content-Type: application/json');
    echo json_encode($vStudent ?: []);
    exit;
}

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Quản lý sinh viên & tài khoản';
$activePage = 'students';
$breadcrumb = 'Quản trị › Sinh viên & Tài khoản';
require __DIR__ . '/_layout.php';
?>

<!-- ── Flash banner ───────────────────────────────────────── -->
<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:4px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success'?'✅':'⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- ── Page title + action buttons ────────────────────────── -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="font-size:22px;font-weight:800;color:#0d1b2e;line-height:1.2;">
            Quản lý sinh viên &amp; Tài khoản
        </h1>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button onclick="openAddModal()" class="abtn abtn--primary" style="background:#0d1b2e;">
            ➕ Thêm sinh viên mới
        </button>
    </div>
</div>

<!-- ── 4 KPI cards ────────────────────────────────────────── -->
<div class="adm-kpi-row">
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Tổng sinh viên chính quy</span>
            <span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-profile.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= number_format($totalSV) ?></span></div>
        <div class="adm-kpi__sub adm-kpi__sub--up">Tổng <?= $totalSV ?> sinh viên đang theo học</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Tài khoản kích hoạt</span>
            <span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-icon-degree-check.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= number_format($totalAcc) ?></span></div>
        <div class="adm-kpi__sub"><?= $totalAcc ?> tài khoản đang hoạt động</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Cảnh báo học vụ</span>
            <span class="adm-kpi__icon adm-kpi__icon--red"><img src="../img/db-nav-alerts.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num adm-kpi__num--red"><?= $activeWarn ?></span></div>
        <div class="adm-kpi__sub adm-kpi__sub--down">Sinh viên có cảnh báo đang hoạt động</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Tài khoản bị khóa</span>
            <span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-icon-credits.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $pendingAcc ?></span></div>
        <div class="adm-kpi__sub" style="color:<?= $pendingAcc>0?'#f59e0b':'#94a3b8' ?>;">
            <?= $pendingAcc > 0 ? 'Cần xử lý' : 'Không có tài khoản bị khóa' ?>
        </div>
    </div>
</div>

<!-- ── Filters ────────────────────────────────────────────── -->
<div class="acard" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <div style="position:relative;flex:0 0 300px;">
            <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="q" value="<?= h($search) ?>"
                   placeholder="Tìm MSSV, họ tên, email, lớp..."
                   style="width:100%;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="dept" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả ngành</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= h($d['department_code']) ?>" <?= $deptF===$d['department_code']?'selected':'' ?>>
                <?= h($d['department_code']) ?> – <?= h($d['department_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="status" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả trạng thái</option>
            <option value="Active"    <?= $statusF==='Active'?'selected':'' ?>>Đang học</option>
            <option value="Graduated" <?= $statusF==='Graduated'?'selected':'' ?>>Tốt nghiệp</option>
            <option value="Suspended" <?= $statusF==='Suspended'?'selected':'' ?>>Cảnh báo</option>
            <option value="Withdrawn" <?= $statusF==='Withdrawn'?'selected':'' ?>>Thôi học</option>
        </select>
        <button type="submit" class="abtn abtn--primary abtn--sm">🔍 Lọc</button>
        <?php if ($search || $deptF || $statusF): ?>
        <a href="students.php" class="abtn abtn--outline abtn--sm">✕ Xóa lọc</a>
        <?php endif; ?>
    </form>
</div>

<!-- ── Student table ──────────────────────────────────────── -->
<div class="acard" style="padding:0;overflow:hidden;border-radius:12px;">
    <div style="padding:12px 20px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#64748b;">
        Hiển thị <?= $offset+1 ?>–<?= min($offset+$perPage,$totalFiltered) ?> trong tổng số <strong><?= number_format($totalFiltered) ?></strong> sinh viên
    </div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead>
                <tr>
                    <th>MSSV &amp; HỌ TÊN</th>
                    <th>NGÀNH &amp; LỚP</th>
                    <th>GPA TÍCH LŨY</th>
                    <th>TÍN CHỈ</th>
                    <th>TRẠNG THÁI</th>
                    <th>TÀI KHOẢN</th>
                    <th style="width:160px;">THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không tìm thấy sinh viên nào.
                </td></tr>
                <?php else: ?>
                <?php foreach ($students as $sv):
                    $ini      = initials($sv['full_name']);
                    $color    = avColor($sv['student_id']);
                    $gpa      = $sv['cum_gpa'] !== null ? (float)$sv['cum_gpa'] : null;
                    [$stLabel,$stCls] = statusLabel($sv['status']);
                    $tc       = (int)$sv['completed_courses'];
                    $tcPct    = round($tc / 130 * 100, 1);
                    $wc       = (int)$sv['warn_cnt'];
                    $locked   = !$sv['acc_active'];
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="adm-av" style="background:<?= $color ?>;"><?= h($ini) ?></span>
                            <div>
                                <div style="font-weight:700;color:#0d1b2e;font-size:13px;">
                                    <?= h($sv['full_name']) ?>
                                    <?php if ($wc > 0): ?>
                                    <span class="apill apill--red" style="font-size:9px;margin-left:4px;">△ <?= $wc ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:11px;color:#64748b;"><?= h($sv['student_id']) ?> • <?= h($sv['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:12px;font-weight:600;color:#334155;"><?= h($sv['department_name'] ?? $sv['department_code'] ?? '—') ?></div>
                        <div style="font-size:11px;color:#94a3b8;"><?= h($sv['class_code']) ?></div>
                    </td>
                    <td>
                        <?php if ($gpa !== null): ?>
                        <div class="adm-gpa-bar">
                            <div class="adm-gpa-bar__track">
                                <div class="adm-gpa-bar__fill adm-gpa-bar__fill--<?= $gpa<2?'crit':($gpa<2.5?'warn':'ok') ?>"
                                     style="width:<?= round($gpa/4*100) ?>%"></div>
                            </div>
                            <span class="adm-gpa-bar__val" style="color:<?= $gpa<2?'#ef4444':($gpa<2.5?'#f59e0b':'#001634') ?>;">
                                <?= number_format($gpa,2) ?> / 4.0
                            </span>
                        </div>
                        <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;font-style:italic;">Chưa có dữ liệu</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:12px;font-weight:700;color:#0d1b2e;"><?= $tc ?>/130 TC</div>
                        <div style="height:4px;background:#e2e8f0;border-radius:9999px;overflow:hidden;margin-top:3px;min-width:60px;">
                            <div style="height:100%;width:<?= $tcPct ?>%;background:<?= $tcPct>=80?'#0d1b2e':'#fdcc35' ?>;border-radius:9999px;"></div>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;margin-top:1px;"><?= $tcPct ?>%</div>
                    </td>
                    <td>
                        <span class="apill <?= $stCls ?>"><?= h($stLabel) ?></span>
                    </td>
                    <td>
                        <?php if ($locked): ?>
                        <span class="apill apill--red" style="font-size:10px;">🔒 Bị khóa</span>
                        <?php else: ?>
                        <span class="apill apill--green" style="font-size:10px;">● Hoạt động</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <button onclick="openViewModal('<?= h($sv['student_id']) ?>')"
                                    class="abtn abtn--outline abtn--sm" title="Xem chi tiết">👁</button>
                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($sv), ENT_QUOTES) ?>)"
                                    class="abtn abtn--outline abtn--sm" title="Chỉnh sửa">✏</button>
                            <?php if ($locked): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Mở khóa tài khoản <?= h($sv['student_id']) ?>?')">
                                <input type="hidden" name="_action"    value="toggle_lock" />
                                <input type="hidden" name="student_id" value="<?= h($sv['student_id']) ?>" />
                                <input type="hidden" name="lock"       value="0" />
                                <button type="submit" class="abtn abtn--outline abtn--sm" title="Mở khóa" style="color:#059669;">🔓</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Khóa tài khoản <?= h($sv['student_id']) ?>?')">
                                <input type="hidden" name="_action"    value="toggle_lock" />
                                <input type="hidden" name="student_id" value="<?= h($sv['student_id']) ?>" />
                                <input type="hidden" name="lock"       value="1" />
                                <button type="submit" class="abtn abtn--outline abtn--sm" title="Khóa tài khoản" style="color:#ef4444;">🔒</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-top:1px solid #f1f5f9;">
        <span style="font-size:12px;color:#64748b;">Trang <?= $page ?> / <?= $totalPages ?></span>
        <div class="adm-pagination">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>">‹</a>
            <?php endif; ?>
            <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
            <?php if ($p==$page): ?>
            <span class="active"><?= $p ?></span>
            <?php else: ?>
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"><?= $p ?></a>
            <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>">›</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: ADD STUDENT
     ══════════════════════════════════════════════════════════ -->
<div id="modalAdd" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
        <button onclick="closeModal('modalAdd')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
        <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">➕ Thêm sinh viên mới</h2>
        <form method="POST">
            <input type="hidden" name="_action" value="add_student" />
            <?= modalStudentFields($depts) ?>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button type="button" onclick="closeModal('modalAdd')" class="abtn abtn--outline">Hủy</button>
                <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Thêm sinh viên</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: EDIT STUDENT
     ══════════════════════════════════════════════════════════ -->
<div id="modalEdit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
        <button onclick="closeModal('modalEdit')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
        <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">✏ Chỉnh sửa sinh viên</h2>
        <form method="POST" id="editForm">
            <input type="hidden" name="_action"        value="edit_student" />
            <input type="hidden" name="orig_student_id" id="edit_orig_sid" />
            <?= modalStudentFields($depts, 'edit_') ?>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button type="button" onclick="closeModal('modalEdit')" class="abtn abtn--outline">Hủy</button>
                <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lưu thay đổi</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: VIEW DETAIL
     ══════════════════════════════════════════════════════════ -->
<div id="modalView" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
        <button onclick="closeModal('modalView')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
        <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">👁 Chi tiết sinh viên</h2>
        <div id="viewContent" style="font-size:13px;line-height:2;">
            <div style="text-align:center;padding:32px;color:#94a3b8;">Đang tải...</div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <form method="POST" id="resetPwForm">
                <input type="hidden" name="_action"    value="reset_password" />
                <input type="hidden" name="student_id" id="view_sid_reset" />
                <button type="submit" class="abtn abtn--outline" onclick="return confirm('Đặt lại mật khẩu về MSSV?')">🔑 Reset mật khẩu</button>
            </form>
            <button onclick="closeModal('modalView')" class="abtn abtn--primary" style="background:#0d1b2e;">Đóng</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<?php
// ── Shared form fields helper ─────────────────────────────────
function modalStudentFields(array $depts, string $prefix = ''): string {
    $years = range((int)date('Y'), 2000);
    ob_start(); ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã sinh viên *</label>
            <input type="text" name="student_id" id="<?= $prefix ?>student_id"
                   maxlength="8" pattern="\d{8}" placeholder="VD: 23070099"
                   <?= $prefix ? '' : '' ?>
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" required />
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Họ và tên *</label>
            <input type="text" name="full_name" id="<?= $prefix ?>full_name"
                   maxlength="100" placeholder="Nguyễn Văn A"
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" required />
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Email *</label>
            <input type="email" name="email" id="<?= $prefix ?>email"
                   placeholder="sv@vnu.edu.vn"
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" required />
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Số điện thoại</label>
            <input type="text" name="phone" id="<?= $prefix ?>phone"
                   maxlength="15" placeholder="09xxxxxxxx"
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã lớp *</label>
            <input type="text" name="class_code" id="<?= $prefix ?>class_code"
                   maxlength="20" placeholder="QH-2023-I-MIS-CQ"
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" required />
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày sinh</label>
            <input type="date" name="date_of_birth" id="<?= $prefix ?>date_of_birth"
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giới tính</label>
            <select name="gender" id="<?= $prefix ?>gender"
                    style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;background:#fff;">
                <option value="">— Chọn —</option>
                <option value="Male">Nam</option>
                <option value="Female">Nữ</option>
                <option value="Other">Khác</option>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Năm nhập học *</label>
            <select name="admission_year" id="<?= $prefix ?>admission_year"
                    style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;background:#fff;" required>
                <?php foreach ($years as $y): ?>
                <option value="<?= $y ?>" <?= $y == (int)date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Khoa / Bộ môn</label>
            <select name="department_id" id="<?= $prefix ?>department_id"
                    style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;background:#fff;">
                <option value="">— Chọn ngành —</option>
                <?php foreach ($depts as $d): ?>
                <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_code'] . ' – ' . $d['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trạng thái</label>
            <select name="status" id="<?= $prefix ?>status"
                    style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;background:#fff;">
                <option value="Active">Đang học</option>
                <option value="Suspended">Cảnh báo học vụ</option>
                <option value="Graduated">Tốt nghiệp</option>
                <option value="Withdrawn">Thôi học</option>
            </select>
        </div>
    </div>
    <?php return ob_get_clean();
}
?>

<script>
function openAddModal() {
    // Reset all add-form fields
    document.getElementById('modalAdd').querySelector('form').reset();
    showModal('modalAdd');
}

function openEditModal(sv) {
    var p = 'edit_';
    document.getElementById('edit_orig_sid').value      = sv.student_id || '';
    setVal(p+'student_id',      sv.student_id);
    setVal(p+'full_name',       sv.full_name);
    setVal(p+'email',           sv.email);
    setVal(p+'phone',           sv.phone || '');
    setVal(p+'class_code',      sv.class_code);
    setVal(p+'date_of_birth',   sv.date_of_birth ? sv.date_of_birth.split('T')[0] : '');
    setVal(p+'gender',          sv.gender || '');
    setVal(p+'admission_year',  sv.admission_year);
    setVal(p+'department_id',   sv.department_id || '');
    setVal(p+'status',          sv.status || 'Active');
    // Disable student_id field in edit mode
    var sidField = document.getElementById(p+'student_id');
    if (sidField) sidField.readOnly = true;
    showModal('modalEdit');
}

function openViewModal(sid) {
    document.getElementById('view_sid_reset').value = sid;
    document.getElementById('viewContent').innerHTML = '<div style="text-align:center;padding:32px;color:#94a3b8;">Đang tải...</div>';
    showModal('modalView');
    // Fetch details via GET ?view=sid
    fetch('students.php?view=' + encodeURIComponent(sid))
        .then(r => r.json())
        .then(function(sv) {
            if (!sv || !sv.student_id) {
                document.getElementById('viewContent').innerHTML = '<p style="color:#94a3b8;">Không tìm thấy dữ liệu.</p>';
                return;
            }
            var gpa = sv.cum_gpa ? parseFloat(sv.cum_gpa).toFixed(2) : '—';
            var accStatus = sv.is_active ? '<span style="color:#059669;font-weight:700;">● Đang hoạt động</span>' : '<span style="color:#ef4444;font-weight:700;">🔒 Bị khóa</span>';
            var rows = [
                ['MSSV', sv.student_id],
                ['Họ và tên', sv.full_name],
                ['Email', sv.email],
                ['Số điện thoại', sv.phone || '—'],
                ['Mã lớp', sv.class_code],
                ['Giới tính', {'Male':'Nam','Female':'Nữ','Other':'Khác'}[sv.gender] || '—'],
                ['Ngày sinh', sv.date_of_birth ? sv.date_of_birth.split('T')[0] : '—'],
                ['Ngành', (sv.department_code || '') + (sv.department_name ? ' – ' + sv.department_name : '')],
                ['Năm nhập học', sv.admission_year],
                ['Trạng thái học vụ', sv.status],
                ['GPA tích lũy', gpa + ' / 4.0'],
                ['Cảnh báo học vụ', sv.warn_cnt > 0 ? sv.warn_cnt + ' cảnh báo đang hoạt động' : 'Không có'],
                ['Tài khoản SSO', accStatus],
            ];
            var html = '<table style="width:100%;border-collapse:collapse;">';
            rows.forEach(function(r) {
                html += '<tr style="border-bottom:1px solid #f1f5f9;">'
                    + '<td style="padding:8px 4px;font-weight:600;color:#334155;width:44%;font-size:12px;">' + r[0] + '</td>'
                    + '<td style="padding:8px 4px;color:#0d1b2e;font-size:13px;">' + (r[1] || '—') + '</td>'
                    + '</tr>';
            });
            html += '</table>';
            document.getElementById('viewContent').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('viewContent').innerHTML = '<p style="color:#ef4444;">Lỗi khi tải dữ liệu.</p>';
        });
}

function showModal(id) {
    var m = document.getElementById(id);
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
}
function setVal(id, val) {
    var el = document.getElementById(id);
    if (el) el.value = val !== null && val !== undefined ? val : '';
}
// Close on backdrop click
['modalAdd','modalEdit','modalView'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
// ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') ['modalAdd','modalEdit','modalView'].forEach(closeModal);
});
</script>
