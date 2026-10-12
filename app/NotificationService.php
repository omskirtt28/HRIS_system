<?php
declare(strict_types=1);
require_once __DIR__.'/Mailer.php';

/** In-app notifications (bell) with optional email delivery. One row per recipient; dedupe_key keeps each reminder to once. */
final class NotificationService
{
    public static function ready(): bool { return FoundationRepository::tableExists('notifications'); }

    /** $n: recipient_user_id, recipient_employee_id, subject_employee_id, kind, title, body, link, due_date, email_to, dedupe_key. Returns true when newly created. */
    public static function create(array $n): bool
    {
        $q=db()->prepare('INSERT IGNORE INTO notifications(recipient_employee_id,recipient_user_id,subject_employee_id,kind,title,body,link,due_date,email_to,dedupe_key) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$n['recipient_employee_id'] ?? null,$n['recipient_user_id'] ?? null,$n['subject_employee_id'] ?? null,$n['kind'],mb_substr((string)$n['title'],0,190),(string)$n['body'],
            isset($n['link'])?mb_substr((string)$n['link'],0,255):null,$n['due_date'] ?? null,$n['email_to'] ?? null,mb_substr((string)$n['dedupe_key'],0,190)]);
        return $q->rowCount()>0;
    }

    public static function recent(int $userId,int $limit=10): array
    {
        $q=db()->prepare('SELECT id,kind,title,body,link,due_date,read_at,created_at FROM notifications WHERE recipient_user_id=? ORDER BY id DESC LIMIT '.max(1,min(50,$limit)));
        $q->execute([$userId]); return $q->fetchAll();
    }

    public static function unreadCount(int $userId): int
    {
        $q=db()->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_user_id=? AND read_at IS NULL'); $q->execute([$userId]); return (int)$q->fetchColumn();
    }

    /** Marks one (or all, when $id is 0) of the user's own notifications as read. */
    public static function markRead(int $userId,int $id=0): void
    {
        db()->prepare('UPDATE notifications SET read_at=NOW() WHERE recipient_user_id=? AND read_at IS NULL'.($id>0?' AND id=?':''))->execute($id>0?[$userId,$id]:[$userId]);
    }

    /** Sends pending emails (3 tries each). Returns [sent, failed]. */
    public static function deliverPending(int $limit=25): array
    {
        if(!Mailer::configured()) return [0,0];
        $q=db()->query('SELECT id,email_to,title,body FROM notifications WHERE email_to IS NOT NULL AND emailed_at IS NULL AND email_attempts<3 ORDER BY id LIMIT '.max(1,$limit));
        $sent=0;$failed=0;
        foreach($q->fetchAll() as $n) {
            try {
                Mailer::send((string)$n['email_to'],'[PMBSI HRIS] '.$n['title'],$n['body']."\n\n— PMBSI HRIS (automatic message)");
                db()->prepare('UPDATE notifications SET emailed_at=NOW(),email_error=NULL,email_attempts=email_attempts+1 WHERE id=?')->execute([$n['id']]); $sent++;
            } catch(Throwable $e) {
                db()->prepare('UPDATE notifications SET email_error=?,email_attempts=email_attempts+1 WHERE id=?')->execute([mb_substr($e->getMessage(),0,250),$n['id']]); $failed++;
            }
        }
        return [$sent,$failed];
    }
}
