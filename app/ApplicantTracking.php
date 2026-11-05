<?php
declare(strict_types=1);

/**
 * Applicant-safe tracking (SPEC-applicant-tracking-link.md, John approved 2026-11-05).
 * Public projection of HR stages + private 90-day tracking links. HR stages, rules and audit stay unchanged.
 */
final class ApplicantTracking
{
    public const STEPS=['Screening','Interview','Offer','Deployment','Deployed'];
    /** HR stage code -> applicant label. Endorsed and Hiring Review are internal and read as Interview; legacy Applied as Screening. */
    public const LABELS=['APPLIED'=>'Screening','SCREENING'=>'Screening','INTERVIEW'=>'Interview','ENDORSED'=>'Interview','CLIENT_REVIEW'=>'Interview','OFFER'=>'Offer','DEPLOYMENT'=>'Deployment','DEPLOYED'=>'Deployed'];
    /** Outcomes are a status note, never a progress step. */
    public const OUTCOMES=['REJECTED'=>'Not selected','WITHDRAWN'=>'Withdrawn','ON_HOLD'=>'On hold'];
    public const LINK_DAYS=90;

    public static function label(string $code): string { return self::LABELS[$code] ?? self::OUTCOMES[$code] ?? 'Under review'; }

    /** Only label + date per milestone: comments, actors and internal stage names never leave this function. */
    public static function publicView(array $row): array
    {
        $milestones=[]; $reached=-1;
        foreach($row['history'] as $h){
            $label=self::label((string)$h['to_code']);
            $step=array_search($label,self::STEPS,true);
            if($step!==false) $reached=$step;
            if($milestones && $milestones[count($milestones)-1]['label']===$label) continue;
            $milestones[]=['label'=>$label,'date'=>(string)$h['changed_at']];
        }
        $code=(string)$row['stage_code'];
        $current=array_search(self::LABELS[$code] ?? '',self::STEPS,true);
        if($current!==false) $reached=$current; // a real move back shows the earlier step as current
        return ['title'=>(string)$row['title'],'client_name'=>(string)$row['client_name'],'application_no'=>(string)$row['application_no'],
            'applied_at'=>(string)$row['applied_at'],'status'=>self::label($code),'outcome'=>self::OUTCOMES[$code] ?? null,'reached'=>$reached,'milestones'=>$milestones];
    }

    /** 'done', 'cur' or '' for progress step $i. An outcome or Deployed has no current step: the step reached counts as done. */
    public static function stepState(array $view,int $i): string
    {
        $finished=$view['outcome']!==null || $view['status']==='Deployed';
        if($i<$view['reached'] || ($finished && $i===$view['reached'])) return 'done';
        return $i===$view['reached'] ? 'cur' : '';
    }

    public static function validToken(string $token): bool { return (bool)preg_match('/^[a-f0-9]{64}$/',$token); }

    public static function link(string $base,string $token): string
    {
        return rtrim($base,'/').'/index.php?'.http_build_query(['page'=>'track','t'=>$token]);
    }

    /** New private link for one application. Only the SHA-256 of the token is stored; the token is returned once. */
    public static function issue(string $applicationNo): string
    {
        $st=db()->prepare('SELECT id FROM applications WHERE application_no=? LIMIT 1'); $st->execute([$applicationNo]);
        $id=(int)$st->fetchColumn(); if($id<=0) throw new RuntimeException('Application not found.');
        $token=bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO applicant_tracking_links(application_id,token_hash,expires_at,created_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL '.self::LINK_DAYS.' DAY),NOW())')
            ->execute([$id,hash('sha256',$token)]);
        audit('Recruitment','TRACKING_LINK_ISSUED','application',$id,['days'=>self::LINK_DAYS]); // never the token
        return $token;
    }

    /** ['application_id','expires_at'] for a live link, else null (bad format, unknown, expired or revoked look the same). */
    public static function find(string $token): ?array
    {
        if(!self::validToken($token)) return null;
        $st=db()->prepare('SELECT application_id,expires_at FROM applicant_tracking_links WHERE token_hash=? AND revoked_at IS NULL AND expires_at>NOW() LIMIT 1');
        $st->execute([hash('sha256',$token)]);
        return $st->fetch() ?: null;
    }

    /** Gmail delivery: only when mail and a trusted base_url are configured. Never blocks the application. */
    public static function email(string $to,string $applicationNo,string $token): bool
    {
        $base=(string)cfg('app.base_url',''); if($base===''||!Mailer::configured()) return false;
        $body="Thank you for applying to PMBSI.\n\nReference number: $applicationNo\n\nOpen your private tracking link to see your application status:\n"
            .self::link($base,$token)."\n\nKeep this link private. Anyone with it can see this application's status. It works for ".self::LINK_DAYS." days.\n"
            ."You can also use Track Application on our website with your reference number and email.\n";
        try { Mailer::send($to,'Your PMBSI application tracking link',$body); return true; }
        catch(Throwable $e){ error_log('Tracking link email failed: '.$e->getMessage()); return false; }
    }
}
