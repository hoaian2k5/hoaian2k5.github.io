<?php
/**
 * UniPortal – Database Connection
 * Returns a PDO instance connected to the uniportal MySQL database.
 * Used by all models via require_once.
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'uniportal');
define('DB_USER', 'root');       // Default XAMPP username
define('DB_PASS', '');           // Default XAMPP password (empty)
define('DB_CHARSET', 'utf8mb4');

function get_db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Show a clean error; never expose $e->getMessage() in production
            http_response_code(500);
            exit('<p style="font-family:sans-serif;color:#c00;">Database connection failed. '
               . 'Please check XAMPP MySQL is running and credentials in src/config/database.php.</p>');
        }
    }

    return $pdo;
}
