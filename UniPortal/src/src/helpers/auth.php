<?php
/**
 * UniPortal – Authentication Helper
 * Queries the actual `Account` table (schema: Account, Student, Lecturer).
 * Role values in DB: 'Student' | 'Lecturer' | 'AcademicAffairs'
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// ── Session bootstrap ─────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── Login ─────────────────────────────────────────────────────
function auth_login(string $username, string $password, bool $remember = false): array {
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Vui lòng nhập đầy đủ thông tin đăng nhập.'];
    }

    $pdo = get_db();

    $stmt = $pdo->prepare(
        'SELECT account_id, username, password_hash, role, student_id, lecturer_id, is_active
         FROM Account
         WHERE username = :username
         LIMIT 1'
    );
    $stmt->execute([':username' => $username]);
    $account = $stmt->fetch();

    if (!$account) {
        return ['ok' => false, 'error' => 'Tài khoản không tồn tại.'];
    }
    if (!$account['is_active']) {
        return ['ok' => false, 'error' => 'Tài khoản đã bị khóa. Vui lòng liên hệ quản trị viên.'];
    }
    if (!password_verify($password, $account['password_hash'])) {
        return ['ok' => false, 'error' => 'Mật khẩu không chính xác.'];
    }

    session_regenerate_id(true);

    $_SESSION['account_id'] = $account['account_id'];
    $_SESSION['username']   = $account['username'];
    $_SESSION['role']       = $account['role'];

    // ── Student profile ──────────────────────────────────────
    if ($account['role'] === 'Student' && $account['student_id']) {
        $s = $pdo->prepare(
            'SELECT s.student_id, s.full_name, s.class_code, s.email,
                    s.admission_year, s.status AS student_status,
                    d.department_name, d.department_code
             FROM Student s
             LEFT JOIN Department d ON d.department_id = s.department_id
             WHERE s.student_id = :sid
             LIMIT 1'
        );
        $s->execute([':sid' => $account['student_id']]);
        $student = $s->fetch();
        if ($student) {
            $_SESSION['student_id']      = $student['student_id'];
            $_SESSION['full_name']       = $student['full_name'];
            $_SESSION['class_code']      = $student['class_code'];
            $_SESSION['email']           = $student['email'];
            $_SESSION['admission_year']  = $student['admission_year'];
            $_SESSION['department_name'] = $student['department_name'] ?? '';
            $_SESSION['department_code'] = $student['department_code'] ?? '';
        }
    }

    // ── Lecturer profile ─────────────────────────────────────
    if ($account['role'] === 'Lecturer' && $account['lecturer_id']) {
        $l = $pdo->prepare(
            'SELECT l.lecturer_id, l.full_name, l.email, l.phone,
                    l.status AS lecturer_status,
                    d.department_name, d.department_code
             FROM Lecturer l
             LEFT JOIN Department d ON d.department_id = l.department_id
             WHERE l.lecturer_id = :lid
             LIMIT 1'
        );
        $l->execute([':lid' => $account['lecturer_id']]);
        $lecturer = $l->fetch();
        if ($lecturer) {
            $_SESSION['lecturer_id']     = $lecturer['lecturer_id'];
            $_SESSION['full_name']       = $lecturer['full_name'];
            $_SESSION['email']           = $lecturer['email'];
            $_SESSION['phone']           = $lecturer['phone'] ?? '';
            $_SESSION['department_name'] = $lecturer['department_name'] ?? '';
            $_SESSION['department_code'] = $lecturer['department_code'] ?? '';
        }
    }

    if ($remember) {
        $token = bin2hex(random_bytes(32));
        setcookie('remember_token', $token, [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    return ['ok' => true, 'error' => ''];
}

// ── Logout ────────────────────────────────────────────────────
function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    setcookie('remember_token', '', time() - 1, '/');
}

// ── CSRF convenience (wraps functions.php helpers) ────────────
function require_csrf_on_post(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // functions.php csrf_verify() aborts with 403 on failure
        csrf_verify();
    }
}

// ── Guards ────────────────────────────────────────────────────
function require_login(string $redirect = '/UniPortal/public/index.php'): void {
    if (empty($_SESSION['account_id'])) {
        header('Location: ' . $redirect);
        exit;
    }
}

function require_student(): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'Student') {
        header('Location: /UniPortal/public/index.php');
        exit;
    }
}

function require_lecturer(): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'Lecturer') {
        header('Location: /UniPortal/public/index.php');
        exit;
    }
}

function require_staff(): void {
    require_login();
    if (!in_array($_SESSION['role'] ?? '', ['Lecturer', 'AcademicAffairs'])) {
        header('Location: /UniPortal/public/index.php');
        exit;
    }
}

function is_logged_in(): bool {
    return !empty($_SESSION['account_id']);
}
