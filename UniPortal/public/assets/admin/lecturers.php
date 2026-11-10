<?php
/**
 * UniPortal – Quản lý Giảng viên
 * Add / Edit / View / Lock-Unlock for Lecturer + Account.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: /UniPortal/public/assets/lecturer/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();

// ── POST handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add_lecturer') {
        $name   = trim($_POST['full_name']    ?? '');
        $email  = trim($_POST['email']        ?? '');
        $phone  = trim($_POST['phone']        ?? '') ?: null;
        $dept   = ($_POST['department_id']    ?? '') !== '' ? (int)$_POST['department_id'] : null;
        $status = in_array($_POST['status']   ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active';
        $uname  = trim($_POST['username']     ?? '');

        $err = '';
        if ($name === '')  $err = 'Họ và tên không được để trống.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Email không hợp lệ.';
        elseif ($uname === '') $err = 'Tên đăng nhập không được để trống.';

        if (!$err) {
            // Check email unique
            $ck = $pdo->prepare('SELECT 1 FROM Lecturer WHERE email=:e LIMIT 1');
            $ck->execute([':e' => $email]);
            if ($ck->fetch()) $err = 'Email đã được sử dụng bởi giảng viên khác.';
        }
        if (!$err) {
            $ck2 = $pdo->prepare('SELECT 1 FROM Account WHERE username=:u LIMIT 1');
            $ck2->execute([':u' => $uname]);
            if ($ck2->fetch()) $err = 'Tên đăng nhập đã tồn tại.';
        }

        if ($err) {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => $err];
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO Lecturer (full_name,email,phone,department_id,status)
                     VALUES (:name,:email,:phone,:dept,:status)'
                )->execute([':name'=>$name,':email'=>$email,':phone'=>$phone,':dept'=>$dept,':status'=>$status]);
                $lid  = (int)$pdo->lastInsertId();
                $hash = password_hash($uname, PASSWORD_BCRYPT);
                $pdo->prepare(
                    "INSERT INTO Account (username,password_hash,role,lecturer_id,is_active)
                     VALUES (:u,:h,'Lecturer',:lid,TRUE)"
                )->execute([':u'=>$uname,':h'=>$hash,':lid'=>$lid]);
                $pdo->commit();
                $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã thêm giảng viên $name. Mật khẩu mặc định: $uname"];
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Lỗi: '.$e->getMessage()];
            }
        }
        header('Location: lecturers.php'); exit;
    }

    if ($action === 'edit_lecturer') {
        $lid    = (int)($_POST['lecturer_id']   ?? 0);
        $name   = trim($_POST['full_name']      ?? '');
        $email  = trim($_POST['email']          ?? '');
        $phone  = trim($_POST['phone']          ?? '') ?: null;
        $dept   = ($_POST['department_id']      ?? '') !== '' ? (int)$_POST['department_id'] : null;
        $status = in_array($_POST['status']     ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $lid < 1) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Thông tin không hợp lệ.'];
        } else {
            $ck = $pdo->prepare('SELECT 1 FROM Lecturer WHERE email=:e AND lecturer_id!=:lid LIMIT 1');
            $ck->execute([':e'=>$email,':lid'=>$lid]);
            if ($ck->fetch()) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Email đã được dùng bởi giảng viên khác.'];
            } else {
                $pdo->prepare(
                    'UPDATE Lecturer SET full_name=:name,email=:email,phone=:phone,
                            department_id=:dept,status=:status WHERE lecturer_id=:lid'
                )->execute([':name'=>$name,':email'=>$email,':phone'=>$phone,':dept'=>$dept,':status'=>$status,':lid'=>$lid]);
                $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã cập nhật giảng viên #$lid."];
            }
        }
        header('Location: lecturers.php'); exit;
    }

    if ($action === 'toggle_lock') {
        $lid  = (int)($_POST['lecturer_id'] ?? 0);
        $lock = (int)($_POST['lock']        ?? 1);
        if ($lid > 0) {
            $pdo->prepare('UPDATE Account SET is_active=:v WHERE lecturer_id=:lid')
                ->execute([':v'=>$lock?0:1,':lid'=>$lid]);
            $msg = $lock ? "Đã khóa tài khoản giảng viên #$lid" : "Đã mở khóa tài khoản giảng viên #$lid";
            $_SESSION['_flash'] = ['type'=>'success','message'=>$msg];
        }
        header('Location: lecturers.php'); exit;
    }

    if ($action === 'reset_password') {
        $lid   = (int)($_POST['lecturer_id'] ?? 0);
        $uname = trim($_POST['username']     ?? '');
        if ($lid > 0 && $uname !== '') {
            $hash = password_hash($uname, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE Account SET password_hash=:h WHERE lecturer_id=:lid')
                ->execute([':h'=>$hash,':lid'=>$lid]);
            $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã đặt lại mật khẩu về: $uname"];
        }
        header('Location: lecturers.php'); exit;
    }
}

// ── Flash ─────────────────────────────────────────────────────
$flash = '';
if (isset($_SESSION['_flash'])) {
    $flash = $_SESSION['_flash']['type'].':'.$_SESSION['_flash']['message'];
    unset($_SESSION['_flash']);
}

// ── View detail JSON ──────────────────────────────────────────
if (isset($_GET['view'])) {
    $lid = (int)$_GET['view'];
    $row = $pdo->prepare(
        'SELECT l.*, d.department_name, d.department_code,
                a.is_active, a.username, a.account_id,
                (SELECT COUNT(*) FROM ClassSection cs WHERE cs.lecturer_id=l.lecturer_id) AS total_sections,
                (SELECT COUNT(DISTINCT e.student_id) FROM Enrollment e
                 JOIN ClassSection cs2 ON cs2.section_id=e.section_id
                 WHERE cs2.lecturer_id=l.lecturer_id AND e.status IN (\'Enrolled\',\'Completed\')) AS total_students
         FROM Lecturer l
         LEFT JOIN Department d ON d.department_id=l.department_id
         LEFT JOIN Account a ON a.lecturer_id=l.lecturer_id
         WHERE l.lecturer_id=:lid LIMIT 1'
    );
    $row->execute([':lid'=>$lid]);
    header('Content-Type: application/json');
    echo json_encode($row->fetch() ?: []);
    exit;
}

// ── Stats ─────────────────────────────────────────────────────
$totalLec    = (int)$pdo->query("SELECT COUNT(*) FROM Lecturer WHERE status='Active'")->fetchColumn();
$totalAcc    = (int)$pdo->query("SELECT COUNT(*) FROM Account WHERE role='Lecturer' AND is_active=TRUE")->fetchColumn();
$lockedAcc   = (int)$pdo->query("SELECT COUNT(*) FROM Account WHERE role='Lecturer' AND is_active=FALSE")->fetchColumn();
$totalSec    = (int)$pdo->query("SELECT COUNT(*) FROM ClassSection")->fetchColumn();

// ── Filters ───────────────────────────────────────────────────
$search  = trim($_GET['q']    ?? '');
$deptF   = trim($_GET['dept'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page-1)*$perPage;

$where  = "WHERE 1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (l.full_name LIKE :q OR l.email LIKE :q OR a.username LIKE :q)";
    $params[':q'] = '%'.$search.'%';
}
if ($deptF !== '') {
    $where .= " AND d.department_code=:dept";
    $params[':dept'] = $deptF;
}

$cntSt = $pdo->prepare("SELECT COUNT(*) FROM Lecturer l LEFT JOIN Department d ON d.department_id=l.department_id LEFT JOIN Account a ON a.lecturer_id=l.lecturer_id $where");
$cntSt->execute($params);
$totalFiltered = (int)$cntSt->fetchColumn();
$totalPages    = max(1,(int)ceil($totalFiltered/$perPage));

$listSt = $pdo->prepare(
    "SELECT l.lecturer_id, l.full_name, l.email, l.phone, l.status,
            d.department_name, d.department_code, d.department_id,
            a.is_active AS acc_active, a.username,
            (SELECT COUNT(*) FROM ClassSection cs WHERE cs.lecturer_id=l.lecturer_id) AS section_count,
            (SELECT COUNT(DISTINCT e.student_id) FROM Enrollment e
             JOIN ClassSection cs2 ON cs2.section_id=e.section_id
             WHERE cs2.lecturer_id=l.lecturer_id AND e.status IN ('Enrolled','Completed')) AS student_count
     FROM Lecturer l
     LEFT JOIN Department d ON d.department_id=l.department_id
     LEFT JOIN Account a ON a.lecturer_id=l.lecturer_id
     $where
     ORDER BY l.lecturer_id ASC
     LIMIT :lim OFFSET :off"
);
$params[':lim'] = $perPage;
$params[':off'] = $offset;
$listSt->execute($params);
$lecturers = $listSt->fetchAll();

$depts = $pdo->query('SELECT department_id, department_code, department_name FROM Department ORDER BY department_code')->fetchAll();

// ── Helpers ───────────────────────────────────────────────────
$avColors = ['#1a3a6b','#0f5132','#6f5600','#7c3aed','#b91c1c','#0369a1','#166534','#92400e'];
function lec_initials(string $n): string {
    $r=''; foreach(explode(' ',trim($n)) as $w) $r.=strtoupper(substr($w,0,1)); return substr($r,-2);
}
function lec_avColor(string $id): string {
    global $avColors; return $avColors[abs(crc32($id)) % count($avColors)];
}
function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Quản lý giảng viên';
$activePage = 'lecturers';
$breadcrumb = 'Quản trị › Giảng viên';
require __DIR__ . '/_layout.php';
?>

<!-- Flash -->
<?php if ($flash): [$ft,$fm] = explode(':',$flash,2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:4px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success'?'✅':'⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- Title + Add button -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <h1 style="font-size:22px;font-weight:800;color:#0d1b2e;">Quản lý Giảng viên</h1>
    <button onclick="openAddModal()" class="abtn abtn--primary" style="background:#0d1b2e;">➕ Thêm giảng viên</button>
</div>

<!-- KPI cards -->
<div class="adm-kpi-row">
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Tổng giảng viên</span>
            <span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-profile.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $totalLec ?></span></div>
        <div class="adm-kpi__sub">Đang công tác</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Tài khoản hoạt động</span>
            <span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-icon-degree-check.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $totalAcc ?></span></div>
        <div class="adm-kpi__sub">Tài khoản giảng viên</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Lớp học phần</span>
            <span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-nav-grades.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $totalSec ?></span></div>
        <div class="adm-kpi__sub">Tổng lớp được phụ trách</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Tài khoản bị khóa</span>
            <span class="adm-kpi__icon adm-kpi__icon--red"><img src="../img/db-nav-alerts.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num" style="color:<?= $lockedAcc>0?'#ef4444':'#001634' ?>"><?= $lockedAcc ?></span></div>
        <div class="adm-kpi__sub"><?= $lockedAcc>0?'Cần xử lý':'Không có' ?></div>
    </div>
</div>

<!-- Filters -->
<div class="acard" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <div style="position:relative;flex:0 0 280px;">
            <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="q" value="<?= h($search) ?>" placeholder="Tìm tên, email, tài khoản..."
                   style="width:100%;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="dept" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả bộ môn</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= h($d['department_code']) ?>" <?= $deptF===$d['department_code']?'selected':'' ?>>
                <?= h($d['department_code']) ?> – <?= h($d['department_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="abtn abtn--primary abtn--sm">🔍 Lọc</button>
        <?php if ($search||$deptF): ?><a href="lecturers.php" class="abtn abtn--outline abtn--sm">✕ Xóa lọc</a><?php endif; ?>
    </form>
</div>

<!-- Lecturer table -->
<div class="acard" style="padding:0;overflow:hidden;">
    <div style="padding:12px 20px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#64748b;">
        Hiển thị <?= $offset+1 ?>–<?= min($offset+$perPage,$totalFiltered) ?> trong tổng số <strong><?= $totalFiltered ?></strong> giảng viên
    </div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead>
                <tr>
                    <th>GIẢNG VIÊN</th>
                    <th>BỘ MÔN</th>
                    <th style="text-align:center;">LỚP PHỤ TRÁCH</th>
                    <th style="text-align:center;">SINH VIÊN</th>
                    <th>TRẠNG THÁI</th>
                    <th>TÀI KHOẢN</th>
                    <th style="width:160px;">THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lecturers)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">Không tìm thấy giảng viên nào.</td></tr>
                <?php else: ?>
                <?php foreach ($lecturers as $lec):
                    $ini    = lec_initials($lec['full_name']);
                    $color  = lec_avColor((string)$lec['lecturer_id']);
                    $locked = !$lec['acc_active'];
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="adm-av" style="background:<?= $color ?>;"><?= h($ini) ?></span>
                            <div>
                                <div style="font-weight:700;color:#0d1b2e;font-size:13px;"><?= h($lec['full_name']) ?></div>
                                <div style="font-size:11px;color:#64748b;"><?= h($lec['email']) ?></div>
                                <?php if ($lec['phone']): ?>
                                <div style="font-size:11px;color:#94a3b8;"><?= h($lec['phone']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:12px;font-weight:600;color:#334155;"><?= h($lec['department_name'] ?? '—') ?></div>
                        <div style="font-size:11px;color:#94a3b8;"><?= h($lec['department_code'] ?? '') ?></div>
                    </td>
                    <td style="text-align:center;font-weight:700;color:#001634;"><?= (int)$lec['section_count'] ?></td>
                    <td style="text-align:center;font-weight:700;color:#001634;"><?= (int)$lec['student_count'] ?></td>
                    <td>
                        <span class="apill <?= $lec['status']==='Active'?'apill--green':'apill--gray' ?>">
                            <?= $lec['status']==='Active'?'Đang công tác':'Không hoạt động' ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($lec['username']): ?>
                            <?php if ($locked): ?>
                            <span class="apill apill--red" style="font-size:10px;">🔒 Bị khóa</span>
                            <?php else: ?>
                            <span class="apill apill--green" style="font-size:10px;">● <?= h($lec['username']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="apill apill--gray" style="font-size:10px;">Chưa có tài khoản</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <button onclick="openViewModal(<?= (int)$lec['lecturer_id'] ?>)"
                                    class="abtn abtn--outline abtn--sm" title="Xem">👁</button>
                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($lec),ENT_QUOTES) ?>)"
                                    class="abtn abtn--outline abtn--sm" title="Sửa">✏</button>
                            <?php if ($lec['username']): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $locked?'Mở khóa':'Khóa' ?> tài khoản <?= h($lec['username']) ?>?')">
                                <input type="hidden" name="_action"     value="toggle_lock" />
                                <input type="hidden" name="lecturer_id" value="<?= (int)$lec['lecturer_id'] ?>" />
                                <input type="hidden" name="lock"        value="<?= $locked?0:1 ?>" />
                                <button type="submit" class="abtn abtn--outline abtn--sm"
                                        style="color:<?= $locked?'#059669':'#ef4444' ?>;" title="<?= $locked?'Mở khóa':'Khóa' ?>">
                                    <?= $locked?'🔓':'🔒' ?>
                                </button>
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
            <?php if ($page>1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>">‹</a><?php endif; ?>
            <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
                <?php if ($p==$page): ?><span class="active"><?= $p ?></span>
                <?php else: ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"><?= $p ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page<$totalPages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>">›</a><?php endif; ?>
        </div>
    </div>
</div>

<!-- ══ MODAL: ADD ══════════════════════════════════════════════ -->
<div id="modalAdd" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
    <button onclick="closeModal('modalAdd')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">➕ Thêm giảng viên mới</h2>
    <form method="POST">
        <input type="hidden" name="_action" value="add_lecturer" />
        <?= lecturerFormFields($depts) ?>
        <div style="margin-top:14px;">
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên đăng nhập *</label>
            <input type="text" name="username" placeholder="VD: lecturer_mis" required
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            <div style="font-size:11px;color:#94a3b8;margin-top:3px;">Mật khẩu mặc định = tên đăng nhập</div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="closeModal('modalAdd')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Thêm giảng viên</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: EDIT ═════════════════════════════════════════════ -->
<div id="modalEdit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
    <button onclick="closeModal('modalEdit')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">✏ Chỉnh sửa giảng viên</h2>
    <form method="POST">
        <input type="hidden" name="_action"     value="edit_lecturer" />
        <input type="hidden" name="lecturer_id" id="edit_lid" />
        <?= lecturerFormFields($depts, 'edit_') ?>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="closeModal('modalEdit')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lưu thay đổi</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: VIEW ═════════════════════════════════════════════ -->
<div id="modalView" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
    <button onclick="closeModal('modalView')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">👁 Chi tiết giảng viên</h2>
    <div id="viewContent"><div style="text-align:center;padding:32px;color:#94a3b8;">Đang tải...</div></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <form method="POST" id="resetPwForm">
            <input type="hidden" name="_action"     value="reset_password" />
            <input type="hidden" name="lecturer_id" id="view_lid_reset" />
            <input type="hidden" name="username"    id="view_uname_reset" />
            <button type="submit" class="abtn abtn--outline"
                    onclick="return confirm('Đặt lại mật khẩu về tên đăng nhập?')">🔑 Reset mật khẩu</button>
        </form>
        <button onclick="closeModal('modalView')" class="abtn abtn--primary" style="background:#0d1b2e;">Đóng</button>
    </div>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<?php
function lecturerFormFields(array $depts, string $prefix = ''): string {
    ob_start(); ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div style="grid-column:1/-1;">
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Họ và tên *</label>
            <input type="text" name="full_name" id="<?= $prefix ?>full_name" maxlength="100" required
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Email *</label>
            <input type="email" name="email" id="<?= $prefix ?>email" required
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Số điện thoại</label>
            <input type="text" name="phone" id="<?= $prefix ?>phone" maxlength="15"
                   style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Bộ môn</label>
            <select name="department_id" id="<?= $prefix ?>dept_id"
                    style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                <option value="">— Chọn bộ môn —</option>
                <?php foreach ($depts as $d): ?>
                <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_code'].' – '.$d['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trạng thái</label>
            <select name="status" id="<?= $prefix ?>lec_status"
                    style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                <option value="Active">Đang công tác</option>
                <option value="Inactive">Không hoạt động</option>
            </select>
        </div>
    </div>
    <?php return ob_get_clean();
}
?>

<script>
function openAddModal() {
    document.getElementById('modalAdd').querySelector('form').reset();
    showModal('modalAdd');
}
function openEditModal(lec) {
    document.getElementById('edit_lid').value              = lec.lecturer_id;
    setVal('edit_full_name',  lec.full_name);
    setVal('edit_email',      lec.email);
    setVal('edit_phone',      lec.phone || '');
    setVal('edit_dept_id',    lec.department_id || '');
    setVal('edit_lec_status', lec.status || 'Active');
    showModal('modalEdit');
}
function openViewModal(lid) {
    document.getElementById('view_lid_reset').value   = lid;
    document.getElementById('viewContent').innerHTML  = '<div style="text-align:center;padding:32px;color:#94a3b8;">Đang tải...</div>';
    showModal('modalView');
    fetch('lecturers.php?view=' + lid)
        .then(r => r.json())
        .then(function(lec) {
            if (!lec || !lec.lecturer_id) { document.getElementById('viewContent').innerHTML='<p style="color:#94a3b8;">Không tìm thấy.</p>'; return; }
            document.getElementById('view_uname_reset').value = lec.username || '';
            var accStatus = lec.is_active
                ? '<span style="color:#059669;font-weight:700;">● Hoạt động ('+( lec.username||'—')+')</span>'
                : '<span style="color:#ef4444;font-weight:700;">🔒 Bị khóa</span>';
            var rows = [
                ['Mã GV', '#'+lec.lecturer_id],
                ['Họ và tên', lec.full_name],
                ['Email', lec.email],
                ['Điện thoại', lec.phone || '—'],
                ['Bộ môn', (lec.department_code||'')+(lec.department_name?' – '+lec.department_name:'')],
                ['Trạng thái', lec.status==='Active'?'Đang công tác':'Không hoạt động'],
                ['Tài khoản', accStatus],
                ['Số lớp phụ trách', lec.total_sections + ' lớp'],
                ['Tổng sinh viên', lec.total_students + ' sinh viên'],
            ];
            var html = '<table style="width:100%;border-collapse:collapse;">';
            rows.forEach(function(r) {
                html += '<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 4px;font-weight:600;color:#334155;width:44%;font-size:12px;">'+r[0]+'</td><td style="padding:8px 4px;color:#0d1b2e;font-size:13px;">'+r[1]+'</td></tr>';
            });
            document.getElementById('viewContent').innerHTML = html+'</table>';
        })
        .catch(function() { document.getElementById('viewContent').innerHTML='<p style="color:#ef4444;">Lỗi tải dữ liệu.</p>'; });
}
function showModal(id) { document.getElementById(id).style.display='flex'; document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).style.display='none'; document.body.style.overflow=''; }
function setVal(id,val) { var el=document.getElementById(id); if(el) el.value=val!==null&&val!==undefined?val:''; }
['modalAdd','modalEdit','modalView'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) { if(e.target===this) closeModal(id); });
});
document.addEventListener('keydown', function(e) { if(e.key==='Escape') ['modalAdd','modalEdit','modalView'].forEach(closeModal); });
</script>
