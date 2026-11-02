<?php
declare(strict_types=1);

/** interview-invite (SPEC-interview-invite.md): email the applicant the details of a scheduled interview. Never throws. */
final class InterviewInvite
{
    /** Sending stays off until mail.send_interview_invites is true in config/config.local.php. */
    public static function enabled(): bool { return Mailer::configured() && !empty($GLOBALS['config']['mail']['send_interview_invites']); }

    /** j***@example.com: enough for HR to recognise the address, never the whole thing in the timeline. */
    public static function mask(string $email): string
    {
        [$user,$domain]=array_pad(explode('@',$email,2),2,'');
        return mb_substr($user,0,1).'***@'.$domain;
    }

    /** @return array{0:string,1:string} [status 'sent'|'skipped'|'failed', timeline/audit text] */
    public static function send(int $interviewId): array
    {
        $st=db()->prepare('SELECT i.interview_type,i.scheduled_at,i.location_or_link,ap.first_name,ap.email,j.title job_title FROM interviews i JOIN applications a ON a.id=i.application_id JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id WHERE i.id=?');
        $st->execute([$interviewId]); $i=$st->fetch();
        if(!$i) return ['failed','Invite email failed: interview not found'];
        if(!self::enabled()) return ['skipped','Invite email not sent: email sending is off'];
        $to=trim((string)$i['email']);
        if(!filter_var($to,FILTER_VALIDATE_EMAIL)) return ['skipped','Invite email not sent: the applicant has no valid email address'];
        $where=trim((string)$i['location_or_link']);
        $body="Hello ".$i['first_name'].",\n\nYour ".$i['interview_type']." for ".$i['job_title']." is scheduled on "
            .date('F j, Y \a\t g:i A',strtotime((string)$i['scheduled_at']))." (Philippine Time).\n\n"
            .'Location / link: '.($where!==''?$where:'To be confirmed')."\n\nReply to this email if you cannot attend.\n\nPMBSI HR\n";
        try { Mailer::send($to,'Interview schedule: '.$i['job_title'],$body); }
        catch(Throwable $e){ return ['failed','Invite email failed: '.mb_substr($e->getMessage(),0,120)]; }
        return ['sent','Invite emailed to '.self::mask($to)];
    }
}
