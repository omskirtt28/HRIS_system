<?php
declare(strict_types=1);
require_once __DIR__.'/ProbationService.php';
require_once __DIR__.'/NotificationViews.php';

function notification_handle(string $page): void
{
    $action=(string)($_POST['action'] ?? '');
    if(!in_array($page,['notifications','hr-probation'],true) && $action!=='notification_read') return;
    need_db();
    if($action==='notification_read' && $_SERVER['REQUEST_METHOD']==='POST') {
        header('Content-Type: application/json; charset=utf-8');
        if(!Auth::check()) { http_response_code(401); echo '{"ok":false}'; exit; }
        verify_csrf();
        NotificationService::markRead((int)Auth::user()['id'],(int)($_POST['id'] ?? 0));
        echo json_encode(['ok'=>true,'unread'=>NotificationService::unreadCount((int)Auth::user()['id'])]); exit;
    }
    if($page==='notifications') {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        if(!Auth::check() || !NotificationService::ready()) { echo json_encode(['unread'=>0,'items'=>[]]); exit; }
        $uid=(int)Auth::user()['id'];
        echo json_encode(['unread'=>NotificationService::unreadCount($uid),'items'=>array_map(static fn($n)=>[
            'id'=>(int)$n['id'],'title'=>$n['title'],'body'=>$n['body'],'link'=>$n['link'],'read'=>$n['read_at']!==null,'when'=>date('M j, g:i A',strtotime((string)$n['created_at'])),
        ],NotificationService::recent($uid,10))],JSON_UNESCAPED_UNICODE); exit;
    }
    Auth::requirePermission('employees.view_all');
    $filter=(string)($_GET['filter'] ?? ''); if(!in_array($filter,['soon','overdue'],true)) $filter='';
    probation_watch_page(ProbationService::watch($filter),$filter,ProbationService::ready(),Mailer::configured());
    exit;
}
