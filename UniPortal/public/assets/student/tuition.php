<?php
/**
 * UniPortal – Học phí & Thanh toán (Tuition & Payment)
 * View tuition fees by semester, submit payment evidence.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/services/NotificationService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo        = get_db();
$student_id = $_SESSION['student_id'] ?? '';
$account_id = (int)($_SESSION['account_id'] ?? 0);

// Storage path
$storageBase = $root . '/storage/uploads/payments';
if (!is_dir($storageBase)) mkdir($storageBase, 0755, true);

$flash = '';

// ── Handle payment submission ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    csrf_verify();
    $tuition_id  = (int)($_POST['tuition_id']   ?? 0);
    $amount_vnd  = (int)($_POST['amount_vnd']   ?? 0);
    $method      = $_POST['method']             ?? 'BankTransfer';
    $ref_no      = trim($_POST['reference_no']  ?? '');
    $note        = trim($_POST['note']          ?? '');

    $validMethods = ['BankTransfer', 'Cash', 'Online'];
    if (!in_array($method, $validMethods)) $method = 'BankTransfer';

    // Verify tuition belongs to this student
    $tChk = $pdo->prepare('SELECT tuition_id, status, amount_vnd FROM Tuition WHERE tuition_id=:tid AND student_id=:sid LIMIT 1');
    $tChk->execute([':tid' => $tuition_id, ':sid' => $student_id]);
    $tuition = $tChk->fetch();

    if (!$tuition) {
        $flash = 'error:Không tìm thấy thông tin học phí.';
    } elseif ($tuition['status'] === 'Paid') {
        $flash = 'error:Học phí này đã được xác nhận thanh toán.';
    } elseif ($amount_vnd <= 0) {
        $flash = 'error:Số tiền không hợp lệ.';
    } else {
        $evidencePath = null;
        $evidenceName = null;

        // Handle optional evidence file
        if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['evidence_file'];
            $allowed = ['jpg','jpeg','png','pdf'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $flash = 'error:File minh chứng chỉ chấp nhận JPG, PNG, PDF.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $flash = 'error:File minh chứng tối đa 5 MB.';
            } else {
                $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
                $destPath = $storageBase . '/' . $safeName;
                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $evidencePath = 'payments/' . $safeName;
                    $evidenceName = $file['name'];
                }
            }
        }

        if (!$flash) {
            $pdo->prepare(
                'INSERT INTO Payment (tuition_id, student_id, amount_vnd, method, reference_no,
                            evidence_path, evidence_name, note, status)
                 VALUES (:tid, :sid, :amt, :meth, :ref, :ep, :en, :note, \'Pending\')'
            )->execute([
                ':tid'  => $tuition_id,
                ':sid'  => $student_id,
                ':amt'  => $amount_vnd,
                ':meth' => $method,
                ':ref'  => $ref_no   ?: null,
                ':ep'   => $evidencePath,
                ':en'   => $evidenceName,
                ':note' => $note     ?: null,
            ]);

            // Mark tuition as PartiallyPaid if not full amount
            $newStatus = $amount_vnd >= (int)$tuition['amount_vnd'] ? 'PartiallyPaid' : 'PartiallyPaid';
            $pdo->prepare("UPDATE Tuition SET status='PartiallyPaid' WHERE tuition_id=:tid AND status='Unpaid'")
                ->execute([':tid' => $tuition_id]);

            NotificationService::send(
                $account_id, 'Payment',
                '💰 Đã gửi xác nhận thanh toán học phí',
                'Số tiền: ' . number_format($amount_vnd) . ' VNĐ – Đang chờ Admin duyệt.',
                '/UniPortal/public/assets/student/tuition.php'
            );

            $flash = 'success:Đã gửi xác nhận thanh toán. Vui lòng chờ Admin xác nhận (1-3 ngày làm việc).';
        }
    }
}

// ── Load tuition by semester ──────────────────────────────────
$tuitions = $pdo->prepare(
    'SELECT t.*, sem.semester_name, sem.academic_year, sem.status AS sem_status
     FROM Tuition t
     JOIN Semester sem ON sem.semester_id = t.semester_id
     WHERE t.student_id = :sid
     ORDER BY sem.start_date DESC'
);
$tuitions->execute([':sid' => $student_id]);
$tuitionRows = $tuitions->fetchAll();

// For each tuition, load its payments
$tuitionIds = array_column($tuitionRows, 'tuition_id');
$payments   = [];
if ($tuitionIds) {
    $inList = implode(',', array_map('intval', $tuitionIds));
    $payRows = $pdo->query(
        "SELECT * FROM Payment WHERE tuition_id IN ($inList) ORDER BY submitted_at DESC"
    )->fetchAll();
    foreach ($payRows as $p) {
        $payments[(int)$p['tuition_id']][] = $p;
    }
}

// Totals
$totalDue  = array_sum(array_column(array_filter($tuitionRows, fn($t) => $t['status'] !== 'Paid' && $t['status'] !== 'Waived'), 'amount_vnd'));
$totalPaid = array_sum(array_column(array_filter($tuitionRows, fn($t) => $t['status'] === 'Paid'), 'amount_vnd'));

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function vnd(int $n): string { return number_format($n, 0, ',', '.') . ' ₫'; }

$statusLabels = ['Unpaid'=>['Chưa thanh toán','#fee2e2','#b91c1c'],'PartiallyPaid'=>['Đã gửi xác nhận','#fef9c3','#854d0e'],'Paid'=>['Đã thanh toán','#d1fae5','#065f46'],'Waived'=>['Miễn học phí','#dbeafe','#1e40af']];
$payStatusLabels = ['Pending'=>['⏳ Chờ duyệt','#fef9c3','#854d0e'],'Approved'=>['✅ Đã duyệt','#d1fae5','#065f46'],'Rejected'=>['❌ Từ chối','#fee2e2','#b91c1c']];

$pageTitle  = 'Học phí & Thanh toán';
$activePage = 'tuition';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<?php page_banner('Học phí & Thanh toán', 'Tra cứu học phí và gửi xác nhận thanh toán', 'db-icon-credits.svg', $imgBase); ?>

<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- Summary cards -->
<div class="stat-row">
    <div class="stat-mini">
        <div class="stat-mini__label">Học phí chưa thanh toán</div>
        <div class="stat-mini__value" style="color:#ef4444;"><?= vnd($totalDue) ?></div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Đã xác nhận thanh toán</div>
        <div class="stat-mini__value" style="color:#059669;"><?= vnd($totalPaid) ?></div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Tổng học kỳ</div>
        <div class="stat-mini__value"><?= count($tuitionRows) ?></div>
    </div>
</div>

<?php if (empty($tuitionRows)): ?>
<div class="card" style="padding:40px;text-align:center;color:#94a3b8;">
    <div style="font-size:32px;margin-bottom:8px;">💰</div>
    <p>Chưa có thông tin học phí. Vui lòng liên hệ Phòng Kế hoạch Tài chính.</p>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:16px;">
    <?php foreach ($tuitionRows as $t):
        [$stLabel, $stBg, $stColor] = $statusLabels[$t['status']] ?? ['Không rõ','#f1f5f9','#64748b'];
        $tPayments = $payments[(int)$t['tuition_id']] ?? [];
        $canSubmit = in_array($t['status'], ['Unpaid','PartiallyPaid']);
    ?>
    <div class="card" style="padding:20px 24px;">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
            <div>
                <div style="font-size:16px;font-weight:700;color:#001634;">
                    Học kỳ <?= h($t['semester_name']) ?> <?= h($t['academic_year']) ?>
                </div>
                <div style="font-size:13px;color:#64748b;margin-top:2px;">
                    <?= (int)$t['credits'] ?> tín chỉ ×
                    <?php
                    try { $rate = (int)$pdo->query("SELECT config_value FROM SystemConfig WHERE config_key='tuition_per_credit'")->fetchColumn(); }
                    catch (Throwable) { $rate = 500000; }
                    ?>
                    <?= vnd($rate) ?>/TC
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:22px;font-weight:800;color:#001634;"><?= vnd((int)$t['amount_vnd']) ?></div>
                <span style="display:inline-block;padding:4px 10px;background:<?= $stBg ?>;color:<?= $stColor ?>;border-radius:6px;font-size:12px;font-weight:700;margin-top:4px;">
                    <?= $stLabel ?>
                </span>
            </div>
        </div>

        <?php if ($t['due_date']): ?>
        <div style="font-size:12px;color:#64748b;margin-bottom:12px;">
            📅 Hạn thanh toán: <?= date('d/m/Y', strtotime($t['due_date'])) ?>
            <?php if (strtotime($t['due_date']) < time() && $canSubmit): ?>
            <span style="color:#ef4444;font-weight:700;margin-left:6px;">⚠️ Đã quá hạn</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Payment history -->
        <?php if (!empty($tPayments)): ?>
        <div style="margin-bottom:14px;">
            <div style="font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;">Lịch sử thanh toán</div>
            <?php foreach ($tPayments as $p):
                [$pLabel, $pBg, $pColor] = $payStatusLabels[$p['status']] ?? ['Không rõ','#f1f5f9','#64748b'];
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#f8fafc;border-radius:8px;margin-bottom:6px;flex-wrap:wrap;gap:8px;">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#001634;"><?= vnd((int)$p['amount_vnd']) ?></div>
                    <div style="font-size:11px;color:#64748b;">
                        <?= ['BankTransfer'=>'Chuyển khoản','Cash'=>'Tiền mặt','Online'=>'Thanh toán online'][$p['method']] ?? $p['method'] ?>
                        <?= $p['reference_no'] ? '• Mã GD: ' . h($p['reference_no']) : '' ?>
                        • <?= date('d/m/Y H:i', strtotime($p['submitted_at'])) ?>
                    </div>
                </div>
                <span style="background:<?= $pBg ?>;color:<?= $pColor ?>;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;">
                    <?= $pLabel ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Payment submission form -->
        <?php if ($canSubmit): ?>
        <div style="padding:16px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
            <div style="font-size:14px;font-weight:700;color:#334155;margin-bottom:12px;">📤 Gửi xác nhận thanh toán</div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="submit_payment" value="1" />
                <input type="hidden" name="tuition_id"     value="<?= (int)$t['tuition_id'] ?>" />
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Số tiền (VNĐ) *</label>
                        <input type="number" name="amount_vnd" required min="1"
                               value="<?= (int)$t['amount_vnd'] ?>"
                               style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Hình thức</label>
                        <select name="method" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                            <option value="BankTransfer">Chuyển khoản ngân hàng</option>
                            <option value="Cash">Nộp tiền mặt</option>
                            <option value="Online">Thanh toán online (VNPay, MoMo…)</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã giao dịch / Biên lai</label>
                        <input type="text" name="reference_no" placeholder="VD: VCB2024111800001"
                               style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ảnh/File minh chứng (JPG, PNG, PDF)</label>
                        <input type="file" name="evidence_file" accept=".jpg,.jpeg,.png,.pdf"
                               style="font-size:12px;width:100%;" />
                    </div>
                    <div style="grid-column:1/-1;">
                        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ghi chú</label>
                        <input type="text" name="note" placeholder="Ghi chú thêm (nếu có)"
                               style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                    </div>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px;align-items:center;justify-content:flex-end;">
                    <div style="font-size:11px;color:#94a3b8;flex:1;">
                        Thông tin chuyển khoản: <strong>VPBank – 123456789 – VNUIS Academic</strong>
                        (Nội dung: MSSV <?= h($student_id) ?> học phí HK<?= h($t['semester_name']) ?>)
                    </div>
                    <button type="submit"
                            style="padding:9px 20px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;">
                        📤 Gửi xác nhận
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="margin-top:16px;padding:14px 16px;background:#f8fafc;border-radius:10px;font-size:12px;color:#64748b;">
    <strong>Lưu ý:</strong> Xác nhận thanh toán sẽ được Admin Phòng Kế hoạch Tài chính duyệt trong vòng 1-3 ngày làm việc.
    Liên hệ: <a href="mailto:taichinhketoan@vnu.edu.vn" style="color:#3b82f6;">taichinhketoan@vnu.edu.vn</a> • ĐT: 024 3557 5993
</div>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
