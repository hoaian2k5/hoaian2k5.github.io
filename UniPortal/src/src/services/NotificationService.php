<?php
/**
 * UniPortal – Notification Service
 * Creates, retrieves and marks notifications for all roles.
 */
require_once __DIR__ . '/../config/database.php';

class NotificationService {

    // Send a notification to one account
    public static function send(
        int    $account_id,
        string $type,
        string $title,
        string $body  = '',
        string $link  = ''
    ): bool {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'INSERT INTO Notification (account_id, type, title, body, link)
                 VALUES (:aid, :type, :title, :body, :link)'
            );
            return $stmt->execute([
                ':aid'   => $account_id,
                ':type'  => $type,
                ':title' => $title,
                ':body'  => $body,
                ':link'  => $link,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    // Send to all accounts with a given role
    public static function broadcast(
        string $role,
        string $type,
        string $title,
        string $body = '',
        string $link = ''
    ): int {
        try {
            $pdo   = get_db();
            $stmt  = $pdo->prepare('SELECT account_id FROM Account WHERE role=:r AND is_active=TRUE');
            $stmt->execute([':r' => $role]);
            $count = 0;
            foreach ($stmt->fetchAll() as $row) {
                if (self::send((int)$row['account_id'], $type, $title, $body, $link)) $count++;
            }
            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    // Unread count for an account
    public static function unreadCount(int $account_id): int {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM Notification WHERE account_id=:aid AND is_read=FALSE');
            $stmt->execute([':aid' => $account_id]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    // Latest N notifications for an account
    public static function getRecent(int $account_id, int $limit = 8): array {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'SELECT notification_id, type, title, body, link, is_read,
                        created_at
                 FROM Notification
                 WHERE account_id = :aid
                 ORDER BY created_at DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit,      PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    // Mark a single notification as read (must belong to account)
    public static function markRead(int $notification_id, int $account_id): bool {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'UPDATE Notification SET is_read=TRUE
                 WHERE notification_id=:nid AND account_id=:aid'
            );
            return $stmt->execute([':nid' => $notification_id, ':aid' => $account_id]);
        } catch (Throwable) {
            return false;
        }
    }

    // Mark all as read for an account
    public static function markAllRead(int $account_id): bool {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare('UPDATE Notification SET is_read=TRUE WHERE account_id=:aid');
            return $stmt->execute([':aid' => $account_id]);
        } catch (Throwable) {
            return false;
        }
    }
}
