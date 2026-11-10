<?php
/**
 * UniPortal – AuditLogModel
 * Writes one row to the AuditLog table.
 * Never throws — log failures must not break the main flow.
 */

require_once __DIR__ . '/../config/database.php';

class AuditLogModel {

    /**
     * @param int         $actor_id       Account.account_id of the person acting
     * @param string      $action         e.g. 'account.create', 'account.lock', 'grade.update'
     * @param string      $target_entity  Table name, e.g. 'Student', 'Account', 'Grade'
     * @param string      $target_id      Stringified PK of the affected row
     * @param array|null  $old_val        Old value snapshot (will be JSON-encoded)
     * @param array|null  $new_val        New value snapshot (will be JSON-encoded)
     * @param string|null $ip             IP address of the actor
     */
    public static function write(
        int    $actor_id,
        string $action,
        string $target_entity,
        string $target_id,
        ?array $old_val,
        ?array $new_val,
        ?string $ip = null
    ): void {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'INSERT INTO AuditLog
                   (actor_id, action, target_entity, target_id,
                    old_value_json, new_value_json, ip_address, created_at)
                 VALUES
                   (:actor, :action, :entity, :target,
                    :old_json, :new_json, :ip, NOW())'
            );
            $stmt->execute([
                ':actor'    => $actor_id,
                ':action'   => substr($action, 0, 60),
                ':entity'   => substr($target_entity, 0, 40),
                ':target'   => substr($target_id, 0, 40),
                ':old_json' => $old_val !== null ? json_encode($old_val, JSON_UNESCAPED_UNICODE) : null,
                ':new_json' => $new_val !== null ? json_encode($new_val, JSON_UNESCAPED_UNICODE) : null,
                ':ip'       => $ip ? substr($ip, 0, 45) : null,
            ]);
        } catch (Throwable $e) {
            // Log failure is non-fatal; write to PHP error log instead
            error_log('AuditLog write failed: ' . $e->getMessage());
        }
    }
}
