<?php
/**
 * UniPortal – AccountService
 * Handles creation, locking, and password reset of student and lecturer accounts.
 * All write operations are wrapped in transactions and logged to AuditLog.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AuditLogModel.php';

class AccountService {

    // ── Password generation ───────────────────────────────────
    /**
     * Generate a 12-character random alphanumeric password.
     * Uses bin2hex(random_bytes(6)) = 12 hex chars.
     * Shown to admin ONCE; never stored in plain text.
     */
    private static function generatePassword(): string {
        return bin2hex(random_bytes(6));
    }

    // ── Create student account ────────────────────────────────
    /**
     * Create a Student row + Account row in one transaction.
     *
     * Required $data keys:
     *   student_id    CHAR(8) matching ^[0-9]{8}$
     *   full_name     string
     *   class_code    string
     *   email         string (unique)
     *   department_id int (FK → Department)
     *   admission_year int
     * Optional:
     *   phone         string|null
     *   gender        'Male'|'Female'|'Other'|null
     *   date_of_birth 'YYYY-MM-DD'|null
     *
     * Returns:
     *   ['ok'=>true,  'plain_password'=>'abc123def456']
     *   ['ok'=>false, 'errors'=>['field'=>'message', ...]]
     */
    public static function createStudentAccount(array $data, int $actor_id, string $ip = ''): array {
        $errors = self::validateStudentData($data);
        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            // Insert Student row
            $stmt = $pdo->prepare(
                'INSERT INTO Student
                   (student_id, full_name, class_code, phone, email,
                    gender, date_of_birth, department_id, admission_year, status)
                 VALUES
                   (:sid, :name, :class, :phone, :email,
                    :gender, :dob, :dept, :year, \'Active\')'
            );
            $stmt->execute([
                ':sid'    => $data['student_id'],
                ':name'   => trim($data['full_name']),
                ':class'  => trim($data['class_code']),
                ':phone'  => !empty($data['phone']) ? trim($data['phone']) : null,
                ':email'  => strtolower(trim($data['email'])),
                ':gender' => $data['gender'] ?? null,
                ':dob'    => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
                ':dept'   => (int)($data['department_id']),
                ':year'   => (int)$data['admission_year'],
            ]);

            // Generate and hash password
            $plain = self::generatePassword();
            $hash  = password_hash($plain, PASSWORD_BCRYPT);

            // Insert Account row
            $stmt2 = $pdo->prepare(
                'INSERT INTO Account
                   (username, password_hash, role, student_id, is_active, created_at)
                 VALUES
                   (:user, :hash, \'Student\', :sid, TRUE, NOW())'
            );
            $stmt2->execute([
                ':user' => $data['student_id'],  // username = student_id
                ':hash' => $hash,
                ':sid'  => $data['student_id'],
            ]);
            $account_id = (int)$pdo->lastInsertId();

            $pdo->commit();

            // Audit log (after commit — non-fatal if it fails)
            AuditLogModel::write(
                $actor_id,
                'account.create',
                'Student',
                $data['student_id'],
                null,
                [
                    'student_id'    => $data['student_id'],
                    'full_name'     => $data['full_name'],
                    'email'         => $data['email'],
                    'account_id'    => $account_id,
                ],
                $ip
            );

            return ['ok' => true, 'plain_password' => $plain];

        } catch (PDOException $e) {
            $pdo->rollBack();
            // Catch duplicate key violations cleanly
            if ($e->getCode() === '23000') {
                $msg = $e->getMessage();
                if (strpos($msg, 'uq_student_email') !== false) {
                    return ['ok' => false, 'errors' => ['email' => 'Email này đã được sử dụng.']];
                }
                if (strpos($msg, 'uq_student_phone') !== false) {
                    return ['ok' => false, 'errors' => ['phone' => 'Số điện thoại này đã được sử dụng.']];
                }
                if (strpos($msg, 'PRIMARY') !== false || strpos($msg, 'uq_account_student') !== false) {
                    return ['ok' => false, 'errors' => ['student_id' => 'Mã sinh viên này đã tồn tại.']];
                }
            }
            error_log('AccountService::createStudentAccount PDO error: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['_general' => 'Lỗi hệ thống khi tạo tài khoản. Vui lòng thử lại.']];
        }
    }

    // ── Validation ────────────────────────────────────────────
    private static function validateStudentData(array $data): array {
        $errors = [];

        // student_id: exactly 8 digits
        $sid = trim($data['student_id'] ?? '');
        if ($sid === '') {
            $errors['student_id'] = 'Mã sinh viên không được để trống.';
        } elseif (!preg_match('/^[0-9]{8}$/', $sid)) {
            $errors['student_id'] = 'Mã sinh viên phải gồm đúng 8 chữ số.';
        } else {
            // Check uniqueness
            $pdo = get_db();
            $exists = $pdo->prepare('SELECT 1 FROM Student WHERE student_id = :sid LIMIT 1');
            $exists->execute([':sid' => $sid]);
            if ($exists->fetch()) {
                $errors['student_id'] = 'Mã sinh viên này đã tồn tại.';
            }
        }

        // full_name
        if (empty(trim($data['full_name'] ?? ''))) {
            $errors['full_name'] = 'Họ và tên không được để trống.';
        }

        // class_code
        if (empty(trim($data['class_code'] ?? ''))) {
            $errors['class_code'] = 'Mã lớp không được để trống.';
        }

        // email
        $email = strtolower(trim($data['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'Email không được để trống.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email không hợp lệ.';
        } else {
            $pdo = get_db();
            $echeck = $pdo->prepare('SELECT 1 FROM Student WHERE email = :e LIMIT 1');
            $echeck->execute([':e' => $email]);
            if ($echeck->fetch()) {
                $errors['email'] = 'Email này đã được sử dụng.';
            }
        }

        // phone (optional but must be valid if provided)
        $phone = trim($data['phone'] ?? '');
        if ($phone !== '' && !preg_match('/^[0-9]{10,11}$/', $phone)) {
            $errors['phone'] = 'Số điện thoại phải gồm 10–11 chữ số.';
        } elseif ($phone !== '') {
            $pdo = get_db();
            $pcheck = $pdo->prepare('SELECT 1 FROM Student WHERE phone = :p LIMIT 1');
            $pcheck->execute([':p' => $phone]);
            if ($pcheck->fetch()) {
                $errors['phone'] = 'Số điện thoại này đã được sử dụng.';
            }
        }

        // department_id
        if (empty($data['department_id']) || (int)$data['department_id'] <= 0) {
            $errors['department_id'] = 'Vui lòng chọn ngành học.';
        }

        // admission_year
        $year = (int)($data['admission_year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            $errors['admission_year'] = 'Năm nhập học không hợp lệ.';
        }

        // date_of_birth (optional)
        if (!empty($data['date_of_birth'])) {
            $d = DateTime::createFromFormat('Y-m-d', $data['date_of_birth']);
            if (!$d) {
                $errors['date_of_birth'] = 'Ngày sinh không hợp lệ (định dạng YYYY-MM-DD).';
            }
        }

        return $errors;
    }

    // ── Lock / unlock ─────────────────────────────────────────
    public static function setActive(int $account_id, bool $active, int $actor_id, string $ip = ''): void {
        $pdo = get_db();
        // Read old state
        $old = $pdo->prepare('SELECT is_active, username FROM Account WHERE account_id = :id LIMIT 1');
        $old->execute([':id' => $account_id]);
        $oldRow = $old->fetch();

        $pdo->prepare('UPDATE Account SET is_active = :a WHERE account_id = :id')
            ->execute([':a' => $active ? 1 : 0, ':id' => $account_id]);

        AuditLogModel::write(
            $actor_id,
            $active ? 'account.unlock' : 'account.lock',
            'Account',
            (string)$account_id,
            ['is_active' => $oldRow['is_active'] ?? null],
            ['is_active' => $active ? 1 : 0],
            $ip
        );
    }

    // ── Reset password ────────────────────────────────────────
    public static function resetPassword(int $account_id, int $actor_id, string $ip = ''): string {
        $plain = self::generatePassword();
        $hash  = password_hash($plain, PASSWORD_BCRYPT);

        $pdo = get_db();
        $pdo->prepare('UPDATE Account SET password_hash = :h WHERE account_id = :id')
            ->execute([':h' => $hash, ':id' => $account_id]);

        AuditLogModel::write(
            $actor_id,
            'account.reset_password',
            'Account',
            (string)$account_id,
            null,
            ['action' => 'password_reset'],
            $ip
        );

        return $plain;
    }
}
