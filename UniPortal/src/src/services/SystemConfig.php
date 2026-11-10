<?php
/**
 * UniPortal – SystemConfig Service
 * Cached key-value access to the SystemConfig table.
 * All academic policy thresholds must come from here – never hardcoded.
 */
require_once __DIR__ . '/../config/database.php';

class SystemConfig {

    private static array $cache = [];

    // Get a single value (returns string or $default)
    public static function get(string $key, string $default = ''): string {
        if (!isset(self::$cache[$key])) {
            self::load();
        }
        return self::$cache[$key] ?? $default;
    }

    public static function getFloat(string $key, float $default = 0.0): float {
        return (float) self::get($key, (string)$default);
    }

    public static function getInt(string $key, int $default = 0): int {
        return (int) self::get($key, (string)$default);
    }

    // Load all config into static cache
    public static function load(): void {
        try {
            $pdo  = get_db();
            $rows = $pdo->query('SELECT config_key, config_value FROM SystemConfig')->fetchAll();
            foreach ($rows as $r) {
                self::$cache[$r['config_key']] = $r['config_value'];
            }
        } catch (Throwable) {
            // DB not available – use hardcoded fallbacks silently
        }
    }

    // Save a value (admin only – caller must enforce auth)
    public static function set(string $key, string $value): bool {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'INSERT INTO SystemConfig (config_key, config_value)
                 VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE config_value = :v2, updated_at = NOW()'
            );
            $ok = $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
            self::$cache[$key] = $value;
            return $ok;
        } catch (Throwable) {
            return false;
        }
    }

    // Bulk save array of ['key'=>'value']
    public static function setMany(array $pairs): void {
        foreach ($pairs as $k => $v) {
            self::set($k, $v);
        }
    }

    // Graduation classification from GPA
    public static function getClassification(float|null $gpa): string {
        if ($gpa === null) return 'Chưa có dữ liệu';
        if ($gpa >= self::getFloat('grad_excellent_gpa', 3.60)) return 'Xuất sắc';
        if ($gpa >= self::getFloat('grad_good_gpa',      3.20)) return 'Giỏi';
        if ($gpa >= self::getFloat('grad_average_gpa',   2.50)) return 'Khá';
        if ($gpa >= self::getFloat('grad_pass_gpa',      2.00)) return 'Trung bình';
        return 'Yếu';
    }

    // Check if GPA triggers LowGPA warning
    public static function isLowGpa(float|null $gpa): bool {
        if ($gpa === null) return false;
        return $gpa < self::getFloat('gpa_warning_threshold', 2.00);
    }

    // Check if absence % triggers attendance warning
    public static function isAttendanceWarn(float $absentPct): bool {
        return $absentPct >= self::getFloat('attendance_warn_pct', 15);
    }

    public static function isAttendanceBan(float $absentPct): bool {
        return $absentPct >= self::getFloat('attendance_ban_pct', 20);
    }
}
