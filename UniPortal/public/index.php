<?php
/**
 * UniPortal – Login Page (index.php)
 * Entry point for the application.
 * Handles GET (display form) and POST (authenticate).
 */

// ── Bootstrap: load auth helper (starts session inside) ──────
require_once __DIR__ . '/../src/src/helpers/auth.php';

// ── If already logged in, redirect to the correct dashboard ──
if (is_logged_in()) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'AcademicAffairs') {
        header('Location: assets/admin/dashboard.php');
    } elseif ($role === 'Lecturer') {
        header('Location: assets/lecturer/dashboard.php');
    } else {
        header('Location: assets/student/dashboard.php');
    }
    exit;
}

// ── Handle POST login attempt ─────────────────────────────────
$error        = '';
$old_username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    csrf_verify();
    // Sanitise inputs – never trust raw POST data
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    $old_username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

    $result = auth_login($username, $password, $remember);

    if ($result['ok']) {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'AcademicAffairs') {
            header('Location: assets/admin/dashboard.php');
        } elseif ($role === 'Lecturer') {
            header('Location: assets/lecturer/dashboard.php');
        } else {
            header('Location: assets/student/dashboard.php');
        }
        exit;
    }

    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description" content="Cổng thông tin Đào tạo và Quản lý học vụ – VNU-IS" />
    <title>Đăng nhập – VNU-IS UniPortal</title>

    <!-- Preconnect for Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />

    <!-- Login stylesheet -->
    <link rel="stylesheet" href="assets/css/login.css" />
</head>
<body>

<div class="login-page">

    <!-- ════════════════════════════════════════════
         LEFT PANEL — photo + branding + hero text
         ════════════════════════════════════════════ -->
    <aside class="panel-left" aria-label="Giới thiệu hệ thống">

        <!-- Background photo -->
        <div class="panel-left__bg">
            <img src="assets/img/login-bg.png"
                 alt=""
                 role="presentation"
                 loading="eager" />
        </div>

        <!-- Overlay layers -->
        <div class="panel-left__overlay-grad"   aria-hidden="true"></div>
        <div class="panel-left__overlay-vignette" aria-hidden="true"></div>

        <!-- All visible content -->
        <div class="panel-left__content">

            <!-- Top: logo + name + badge -->
            <div class="branding">
                <div class="branding__logo-row">
                    <!-- Frosted emblem box -->
                    <div class="branding__emblem-box">
                        <img src="assets/img/vnuis-emblem.svg"
                             alt="VNU-IS logo"
                             width="30" height="32" />
                    </div>
                    <!-- Name + subtitle -->
                    <div>
                        <div class="branding__name">VNU-IS</div>
                        <div class="branding__subtitle">
                            TRƯỜNG QUỐC TẾ&nbsp;•&nbsp;ĐẠI HỌC QUỐC GIA HÀ NỘI
                        </div>
                    </div>
                </div>

                <!-- Pill badge -->
                <div class="branding__badge">
                    <img src="assets/img/icon-mortarboard.svg"
                         alt=""
                         aria-hidden="true"
                         width="15" height="10.5" />
                    <span class="branding__badge-text">
                        Cổng thông tin Đào tạo &amp; Quản lý học vụ
                    </span>
                </div>
            </div>

            <!-- Center: hero heading (flex: 1 pushes this to the middle) -->
            <div class="hero">
                <div class="hero__inner">
                    <div class="hero__accent-line" aria-hidden="true"></div>
                    <div class="hero__text-group">
                        <h1 class="hero__heading">
                            Hành trình học tập
                            <span>thông minh &amp; chủ động</span>
                        </h1>
                        <p class="hero__body">
                            Theo dõi GPA, quản lý tiến độ học tập và nhận cảnh báo<br />
                            học vụ kịp thời với mô hình phân tích dự báo ĐHQGHN.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Bottom: copyright -->
            <footer class="panel-left__footer" aria-label="Thông tin bản quyền">
                © 2026 VNU-IS Academic System
            </footer>

        </div><!-- /.panel-left__content -->
    </aside>

    <!-- ════════════════════════════════════════════
         RIGHT PANEL — login form
         ════════════════════════════════════════════ -->
    <main class="panel-right" aria-label="Đăng nhập">

        <!-- Vertically centered form card -->
        <div class="panel-right__middle">
            <div class="login-form-card">

                <!-- Header -->
                <div class="form-header">
                    <h2 class="form-header__title">Đăng nhập hệ thống</h2>
                    <p class="form-header__sub">
                        Chào mừng bạn quay trở lại! Vui lòng nhập thông tin tài khoản để tiếp tục.
                    </p>
                </div>

                <!-- Error banner (only shown when PHP sets $error) -->
                <?php if ($error !== ''): ?>
                <div class="alert-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>

                <!-- Login form -->
                <form id="loginForm"
                      method="POST"
                      action="index.php"
                      autocomplete="on"
                      novalidate>
                    <?= csrf_input() ?>

                    <div class="form-fields">

                        <!-- Field 1: Username -->
                        <div class="form-field">
                            <label class="form-field__label" for="username">
                                MÃ SINH VIÊN / TÊN ĐĂNG NHẬP
                                <span class="required" aria-hidden="true"> *</span>
                            </label>
                            <div class="input-wrapper">
                                <!-- Left icon: person/ID card -->
                                <span class="input-wrapper__icon-left" aria-hidden="true">
                                    <img src="assets/img/icon-user.svg"
                                         alt="" width="18" height="14" />
                                </span>
                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    class="input-wrapper__field<?= $error ? ' input--error' : '' ?>"
                                    value="<?= $old_username ?>"
                                    placeholder="VD: 23070591"
                                    autocomplete="username"
                                    required
                                    aria-required="true"
                                    aria-describedby="username-hint"
                                    spellcheck="false"
                                    autocapitalize="off"
                                />
                            </div>
                            <span id="username-hint" class="form-field__hint">
                                Dùng mã sinh viên chính quy hoặc tài khoản cán bộ quản trị
                            </span>
                        </div>

                        <!-- Field 2: Password -->
                        <div class="form-field">
                            <label class="form-field__label" for="password">
                                MẬT KHẨU
                                <span class="required" aria-hidden="true"> *</span>
                            </label>
                            <div class="input-wrapper">
                                <!-- Left icon: lock -->
                                <span class="input-wrapper__icon-left" aria-hidden="true">
                                    <img src="assets/img/icon-lock.svg"
                                         alt="" width="14" height="16" />
                                </span>
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="input-wrapper__field input-wrapper__field--password<?= $error ? ' input--error' : '' ?>"
                                    placeholder="Nhập mật khẩu"
                                    autocomplete="current-password"
                                    required
                                    aria-required="true"
                                />
                                <!-- Right: eye toggle -->
                                <button type="button"
                                        id="togglePassword"
                                        class="input-wrapper__icon-right"
                                        aria-label="Hiện mật khẩu">
                                    <img id="eyeIcon"
                                         src="assets/img/icon-eye.svg"
                                         alt=""
                                         width="15.75" height="12.25" />
                                </button>
                            </div>
                        </div>

                        <!-- Row: remember me + forgot password -->
                        <div class="form-row">
                            <label class="checkbox-label">
                                <input type="checkbox"
                                       name="remember"
                                       id="remember"
                                       <?= isset($_POST['remember']) ? 'checked' : '' ?> />
                                <span class="checkbox-label__box" aria-hidden="true">
                                    <img src="assets/img/icon-check.svg"
                                         alt="" width="10" height="8" />
                                </span>
                                <span class="checkbox-label__text">Ghi nhớ đăng nhập</span>
                            </label>
                            <a href="#" class="forgot-link">Quên mật khẩu?</a>
                        </div>

                        <!-- Submit button -->
                        <button type="submit"
                                id="submitBtn"
                                class="btn-login">
                            <span>Đăng nhập</span>
                            <img class="btn-login__arrow"
                                 src="assets/img/icon-arrow.svg"
                                 alt=""
                                 aria-hidden="true"
                                 width="10.5" height="9" />
                        </button>

                    </div><!-- /.form-fields -->
                </form>

                <!-- Help note -->
                <div class="form-help">
                    <p class="form-help__line">
                        Gặp sự cố khi đăng nhập?
                        <a href="mailto:daotao@vnu.edu.vn" class="form-help__link">
                            Liên hệ quản trị viên
                        </a>
                    </p>
                    <p class="form-help__contact">
                        Phòng Đào tạo (P.103)&nbsp;•&nbsp;Hotline: 024 3557 5992
                    </p>
                </div>

            </div><!-- /.login-form-card -->
        </div><!-- /.panel-right__middle -->

        <!-- Right panel footer -->
        <footer class="panel-right__footer" aria-label="Liên kết chân trang">
            <span class="panel-right__footer-copy">
                VNUIS Academic Management System
            </span>
            <nav class="panel-right__footer-links" aria-label="Chính sách">
                <a href="#">Quy chế đào tạo</a>
                <span class="panel-right__footer-sep" aria-hidden="true">•</span>
                <a href="#">Bảo mật thông tin</a>
            </nav>
        </footer>

    </main><!-- /.panel-right -->

</div><!-- /.login-page -->

<script src="assets/js/main.js"></script>
</body>
</html>
