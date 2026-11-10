<?php
/**
 * UniPortal – Core Helper Functions
 * Loaded by every page via auth.php (which already calls require_once database.php).
 */

// ── Output escaping ───────────────────────────────────────────
if (!function_exists('h')) {
    function h(mixed $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// ── Number formatting ─────────────────────────────────────────
function fmt_gpa(?float $v): string {
    return $v !== null ? number_format($v, 2) : '—';
}

function fmt_score(?float $v): string {
    return $v !== null ? number_format($v, 1) : '—';
}

function locale_number(float $n, int $dec, string $locale = 'vi'): string {
    $formatted = number_format($n, $dec);
    if ($locale === 'vi') {
        $formatted = str_replace([',', '.'], ['TEMP', ','], $formatted);
        $formatted = str_replace('TEMP', '.', $formatted);
    }
    return $formatted;
}

// ── Abort helper ──────────────────────────────────────────────
function abort(int $code, string $msg = ''): never {
    http_response_code($code);
    $labels = [400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 429 => 'Too Many Requests', 500 => 'Server Error'];
    $label  = $labels[$code] ?? 'Error';
    $safeMsg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html lang='vi'><head><meta charset='UTF-8'><title>{$code} {$label}</title>"
       . "<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f6f9;}"
       . ".box{background:#fff;padding:40px 48px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);text-align:center;}"
       . "h1{font-size:48px;margin:0 0 8px;color:#001634;} p{color:#64748b;font-size:16px;margin:0 0 24px;}"
       . "a{color:#102b50;font-weight:600;}</style></head><body>"
       . "<div class='box'><h1>{$code}</h1><p>{$label}" . ($safeMsg ? ": {$safeMsg}" : '') . "</p>"
       . "<a href='javascript:history.back()'>← Quay lại</a></div></body></html>";
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): void {
    // Accept from POST field or from X-CSRF-Token header (for JSON requests)
    $submitted = $_POST['_csrf']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $stored    = $_SESSION['csrf_token'] ?? '';

    if (!$stored || !hash_equals($stored, $submitted)) {
        abort(403, 'CSRF validation failed');
    }
}

// ── Flash message helpers ─────────────────────────────────────
/**
 * Store a flash message in the session. Type: 'success' | 'error' | 'info' | 'warning'
 */
function flash_set(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieve and clear the flash message. Returns ['type'=>..., 'message'=>...] or null.
 */
function flash_get(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['_flash'])) {
        $f = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $f;
    }
    return null;
}

/**
 * Render the flash message as an HTML banner if one exists.
 * Safe to call on every page — renders nothing if no flash is set.
 */
function flash_render(): void {
    $f = flash_get();
    if (!$f) return;
    $colors = [
        'success' => ['#d1fae5', '#6ee7b7', '#065f46', '✅'],
        'error'   => ['#fee2e2', '#fca5a5', '#b91c1c', '⚠️'],
        'info'    => ['#dbeafe', '#93c5fd', '#1e40af', 'ℹ️'],
        'warning' => ['#fef9c3', '#fde047', '#854d0e', '⚠️'],
    ];
    [$bg, $border, $color, $icon] = $colors[$f['type']] ?? $colors['info'];
    echo '<div style="background:' . $bg . ';border:1px solid ' . $border . ';border-radius:10px;'
       . 'padding:12px 16px;color:' . $color . ';font-weight:600;font-family:sans-serif;'
       . 'font-size:13px;margin-bottom:16px;display:flex;align-items:flex-start;gap:8px;">'
       . '<span>' . $icon . '</span>'
       . '<span>' . htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8') . '</span>'
       . '</div>';
}

/**
 * Render field-level validation errors as a styled list.
 * $errors: ['field_name' => 'error message', ...]  or  ['error1', 'error2', ...]
 */
function validation_errors_render(array $errors): void {
    if (empty($errors)) return;
    echo '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;'
       . 'padding:12px 16px;color:#b91c1c;font-size:13px;margin-bottom:16px;">'
       . '<div style="font-weight:700;margin-bottom:6px;">⚠️ Vui lòng kiểm tra các lỗi sau:</div>'
       . '<ul style="margin:0;padding-left:18px;">';
    foreach ($errors as $field => $msg) {
        $label = is_string($field) && !is_int($field)
            ? '<strong>' . htmlspecialchars(ucfirst($field), ENT_QUOTES, 'UTF-8') . ':</strong> '
            : '';
        echo '<li>' . $label . htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul></div>';
}

// ── CSRF hidden input (convenience for forms) ─────────────────
function csrf_input(): string {
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />';
}
