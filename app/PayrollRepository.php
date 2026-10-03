<?php
declare(strict_types=1);

final class PayrollRepository
{
    public static function ready(): bool
    {
        foreach (['payroll_request_types','payroll_requests','payroll_request_approvals','payroll_cutoffs','leave_types','employee_leave_balances','leave_requests'] as $table) {
            if (!FoundationRepository::tableExists($table)) return false;
        }
        return true;
    }

    public static function currentEmployee(): ?array
    {
        $uid=(int)(Auth::user()['id']??0);
        if ($uid<=0) return null;
        $st=db()->prepare('SELECT e.*,d.name department_name,p.name position_name,b.name branch_name,b.code branch_code,ar.name area_name,ar.code area_code,
            m.first_name manager_first_name,m.middle_name manager_middle_name,m.last_name manager_last_name,m.user_id manager_user_id
            FROM employees e
            LEFT JOIN departments d ON d.id=e.department_id
            LEFT JOIN positions p ON p.id=e.position_id
            LEFT JOIN branches b ON b.id=e.branch_id
            LEFT JOIN areas ar ON ar.id=b.area_id
            LEFT JOIN employees m ON m.id=e.manager_employee_id
            WHERE e.user_id=? LIMIT 1');
        $st->execute([$uid]); $row=$st->fetch(); return $row ?: null;
    }

    public static function requestTypes(): array
    {
        if(!FoundationRepository::tableExists('payroll_request_types')) return [];
        return db()->query("SELECT * FROM payroll_request_types WHERE active=1 ORDER BY sort_order,name")->fetchAll();
    }

    public static function openCutoffs(): array
    {
        return self::requestCutoffs();
    }

    /**
     * Cutoffs available while filing a request.
     * Includes previous periods so employees can file a missed adjustment,
     * while the UI still auto-matches the cutoff from the affected date.
     */
    public static function requestCutoffs(): array
    {
        if(!FoundationRepository::tableExists('payroll_cutoffs')) return [];
        self::ensureCutoffCalendar(18,3);
        $today=(new DateTimeImmutable('today'))->format('Y-m-d');
        $from=(new DateTimeImmutable('first day of this month'))->modify('-18 months')->format('Y-m-d');
        $to=(new DateTimeImmutable('last day of this month'))->modify('+3 months')->format('Y-m-d');
        $st=db()->prepare('SELECT *, CASE WHEN period_end < ? THEN "PREVIOUS" WHEN period_start <= ? AND period_end >= ? THEN "CURRENT" ELSE "UPCOMING" END filing_state FROM payroll_cutoffs WHERE period_end >= ? AND period_start <= ?');
        $st->execute([$today,$today,$today,$from,$to]);
        $rows=$st->fetchAll();
        $rank=['CURRENT'=>0,'PREVIOUS'=>1,'UPCOMING'=>2];
        usort($rows,static function(array $a,array $b)use($rank):int{
            $sa=(string)($a['filing_state']??'PREVIOUS');$sb=(string)($b['filing_state']??'PREVIOUS');
            $cmp=($rank[$sa]??9)<=>($rank[$sb]??9);if($cmp!==0)return $cmp;
            if($sa==='UPCOMING')return strcmp((string)$a['period_start'],(string)$b['period_start']);
            return strcmp((string)$b['period_start'],(string)$a['period_start']);
        });
        return $rows;
    }

    public static function ensureCurrentCutoff(): void
    {
        if(!FoundationRepository::tableExists('payroll_cutoffs')) return;
        self::ensureCutoffForDate((new DateTimeImmutable('today'))->format('Y-m-d'));
    }

    /** Ensure historical/current/upcoming cutoff rows exist for request filing. */
    public static function ensureCutoffCalendar(int $monthsBack=18,int $monthsForward=3): void
    {
        if(!FoundationRepository::tableExists('payroll_cutoffs')) return;
        $anchor=new DateTimeImmutable('first day of this month');
        $startMonth=$anchor->modify('-'.max(0,$monthsBack).' months');
        $endMonth=$anchor->modify('+'.max(0,$monthsForward).' months');
        $st=db()->prepare('INSERT IGNORE INTO payroll_cutoffs(code,period_start,period_end,status,created_by) VALUES(?,?,?,?,?)');
        $uid=(int)(Auth::user()['id']??0)?:null;
        for($m=$startMonth;$m<=$endMonth;$m=$m->modify('+1 month')){
            foreach([4,19] as $day){
                $periodStart=$m->setDate((int)$m->format('Y'),(int)$m->format('n'),$day);
                $periodEnd=$day===4?$periodStart->setDate((int)$periodStart->format('Y'),(int)$periodStart->format('n'),18):$periodStart->modify('first day of next month')->modify('+2 days');
                self::insertCutoffPeriod($st,$periodStart,$periodEnd,$uid);
            }
        }
    }

    /** Return/create the cutoff that contains the supplied affected date. */
    public static function cutoffForDate(string $date): ?array
    {
        $date=self::validDate($date); if(!$date)return null;
        self::ensureCutoffForDate($date);
        $st=db()->prepare('SELECT * FROM payroll_cutoffs WHERE period_start<=? AND period_end>=? ORDER BY period_start DESC LIMIT 1');
        $st->execute([$date,$date]);$row=$st->fetch();return $row?:null;
    }

    private static function ensureCutoffForDate(string $date): void
    {
        $d=new DateTimeImmutable($date);$day=(int)$d->format('j');
        if($day>=19){$start=$d->setDate((int)$d->format('Y'),(int)$d->format('n'),19);$end=$start->modify('first day of next month')->modify('+2 days');}
        elseif($day>=4){$start=$d->setDate((int)$d->format('Y'),(int)$d->format('n'),4);$end=$d->setDate((int)$d->format('Y'),(int)$d->format('n'),18);}
        else{$end=$d->setDate((int)$d->format('Y'),(int)$d->format('n'),3);$start=$end->modify('first day of previous month')->modify('+18 days');}
        $st=db()->prepare('INSERT IGNORE INTO payroll_cutoffs(code,period_start,period_end,status,created_by) VALUES(?,?,?,?,?)');
        self::insertCutoffPeriod($st,$start,$end,(int)(Auth::user()['id']??0)?:null);
    }

    private static function insertCutoffPeriod(PDOStatement $st,DateTimeImmutable $start,DateTimeImmutable $end,?int $uid): void
    {
        $today=new DateTimeImmutable('today');
        $status=$end<$today?'CLOSED':($start<=$today&&$end>=$today?'OPEN':'UPCOMING');
        $code=$start->format('Ymd').'-'.$end->format('Ymd');
        $st->execute([$code,$start->format('Y-m-d'),$end->format('Y-m-d'),$status,$uid]);
    }

    public static function myPayrollRequests(int $employeeId): array
    {
        if(!self::ready()) return [];
        $st=db()->prepare('SELECT pr.*,prt.code type_code,prt.name type_name,pc.period_start cutoff_start,pc.period_end cutoff_end
            FROM payroll_requests pr JOIN payroll_request_types prt ON prt.id=pr.request_type_id
            LEFT JOIN payroll_cutoffs pc ON pc.id=pr.cutoff_id
            WHERE pr.employee_id=? ORDER BY pr.created_at DESC LIMIT 100');
        $st->execute([$employeeId]); return $st->fetchAll();
    }

    public static function payrollRequest(int $id): ?array
    {
        $st=db()->prepare('SELECT pr.*,prt.code type_code,prt.name type_name,prt.category,prt.requires_attachment,
            e.employee_no,e.first_name,e.middle_name,e.last_name,d.name department_name,b.name branch_name,p.name position_name,
            ar.name area_name, CONCAT_WS(" ",m.first_name,m.last_name) manager_name,
            pc.period_start cutoff_start,pc.period_end cutoff_end
            FROM payroll_requests pr
            JOIN payroll_request_types prt ON prt.id=pr.request_type_id
            JOIN employees e ON e.id=pr.employee_id
            LEFT JOIN departments d ON d.id=e.department_id
            LEFT JOIN branches b ON b.id=e.branch_id
            LEFT JOIN areas ar ON ar.id=b.area_id
            LEFT JOIN positions p ON p.id=e.position_id
            LEFT JOIN employees m ON m.id=e.manager_employee_id
            LEFT JOIN payroll_cutoffs pc ON pc.id=pr.cutoff_id WHERE pr.id=? LIMIT 1');
        $st->execute([$id]); $r=$st->fetch(); if(!$r)return null;
        $st=db()->prepare('SELECT * FROM payroll_request_time_entries WHERE request_id=?');$st->execute([$id]);$r['time']=$st->fetch()?:[];
        if(PayrollAttendanceService::ready()) { $st=db()->prepare('SELECT started_at,ended_at FROM payroll_request_overtime_windows WHERE request_id=?'); $st->execute([$id]); if($window=$st->fetch()) { $r['time']['ot_start_date']=substr($window['started_at'],0,10); $r['time']['ot_end_date']=substr($window['ended_at'],0,10); } }
        $st=db()->prepare('SELECT a.*,u.full_name acted_by_name,au.full_name assigned_name FROM payroll_request_approvals a LEFT JOIN users u ON u.id=a.acted_by LEFT JOIN users au ON au.id=a.approver_user_id WHERE a.request_id=? ORDER BY a.step_no');$st->execute([$id]);$r['approvals']=$st->fetchAll();
        $r['backpay']=PayrollAttendanceService::ready()?PayrollAttendanceService::backpayRequest($id):null;
        $st=db()->prepare('SELECT h.*,u.full_name created_by_name FROM payroll_request_history h LEFT JOIN users u ON u.id=h.created_by WHERE h.request_id=? ORDER BY h.created_at,h.id');$st->execute([$id]);$r['history']=$st->fetchAll();
        $st=db()->prepare('SELECT * FROM payroll_request_attachments WHERE request_id=? ORDER BY uploaded_at');$st->execute([$id]);$r['attachments']=$st->fetchAll();
        return $r;
    }

    public static function createPayrollRequest(int $employeeId,array $data,array $files=[],?int $revisionId=null): int
    {
        if(!self::ready()) throw new RuntimeException('Import the Phase 3A database migration first.');
        $employee=EmployeeRepository::find($employeeId);
        if(!$employee || (int)($employee['user_id']??0)!==(int)(Auth::user()['id']??0)) throw new RuntimeException('You may only file for your own employee record.');
        $st=db()->prepare('SELECT * FROM payroll_request_types WHERE id=? AND active=1'); $st->execute([(int)($data['request_type_id']??0)]); $type=$st->fetch();
        if(!$type) throw new RuntimeException('Choose a valid request type.');
        $affected=self::validDate((string)($data['affected_date']??'')); if(!$affected) throw new RuntimeException('Affected date is required.');
        $code=strtoupper((string)$type['code']);
        $automatic=PayrollAttendanceService::ready() && PayrollAttendanceService::automaticType($code);
        $route=$automatic?PayrollAttendanceService::route($employee):['type'=>'MANAGER','user_id'=>self::managerApproverUserId($employee)];
        if(!$route['user_id']) throw new RuntimeException('Your Manager/ADL must be configured with an active approval account.');
        $purpose=trim((string)($data['purpose']??'')); $reason=trim((string)($data['reason']??''));
        if(in_array($code,['OB','POB'],true)) { if($purpose==='') throw new RuntimeException('Purpose is required for Official Business.'); $reason=$purpose; }
        elseif($reason==='') throw new RuntimeException('Reason is required.');
        $times=array_fill_keys(['time_in','lunch_out','lunch_in','time_out','ot_start','ot_end'],null);
        $allowed=in_array($code,['TA','PTA'],true)?PayrollCalculator::FIELDS:(in_array($code,['OT','POT'],true)?['ot_start','ot_end']:[]);
        foreach($allowed as $f) {
            $value=trim((string)($data[$f]??''));
            if($value!=='') { $times[$f]=self::validTime($value); if(!$times[$f]) throw new RuntimeException('Invalid '.str_replace('_',' ',$f).'.'); }
        }
        if(in_array($code,['TA','PTA'],true) && !array_filter($times)) throw new RuntimeException('Enter at least one punch to correct.');
        if(in_array($code,['OT','POT'],true) && (!$times['ot_start']||!$times['ot_end']||$times['ot_start']===$times['ot_end'])) throw new RuntimeException('Enter different OT start/end times. An earlier end means next day.');
        $cutoffId=null;
        if(strtoupper((string)$type['category'])!=='LEAVE') {
            $cutoff=self::cutoffForDate($affected); if(!$cutoff) throw new RuntimeException('Cutoff could not be determined.');
            $selected=(int)($data['cutoff_id']??0);
            if($selected>0) { $st=db()->prepare('SELECT * FROM payroll_cutoffs WHERE id=?'); $st->execute([$selected]); $cutoff=$st->fetch(); if(!$cutoff||$affected<$cutoff['period_start']||$affected>$cutoff['period_end']) throw new RuntimeException('The selected cutoff must cover the affected date.'); }
            $cutoffId=(int)$cutoff['id'];
        }
        $claimCutoffId=null;
        if($automatic && in_array($code,['POB','POT'],true) && $cutoffId && PayrollAttendanceService::run($cutoffId)['run_state']==='FINALIZED') {
            $claimCutoffId=(int)($data['backpay_cutoff_id']??0);
            if(!$claimCutoffId) throw new RuntimeException('Choose an open payout cutoff for this claim from a finalized period.');
            $target=PayrollAttendanceService::run($claimCutoffId);
            if($target['period_start']<=$affected) throw new RuntimeException('The payout cutoff must start after the historical affected date.');
        }
        $uid=(int)Auth::user()['id'];
        db()->beginTransaction();
        try {
            if(PayrollAttendanceService::ready() && $cutoffId) { $actionCutoff=$claimCutoffId??$cutoffId; PayrollAttendanceService::lockOpen($actionCutoff); PayrollAttendanceService::assertTicketAllowed(['id'=>$actionCutoff]); }
            // Lock the employee to serialize duplicate filing and revisions.
            db()->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE')->execute([$employeeId]);
            $old=null;
            if($revisionId) {
                $st=db()->prepare('SELECT * FROM payroll_requests WHERE id=? FOR UPDATE'); $st->execute([$revisionId]); $old=$st->fetch();
                if(!$old || (int)$old['employee_id']!==$employeeId || $old['status']!=='RETURNED_FOR_REVISION') throw new RuntimeException('Only your returned request can be revised.');
                if((int)$old['request_type_id']!==(int)$type['id'] || $old['affected_date']!==$affected) throw new RuntimeException('Keep the request type and affected date when revising.');
            }
            $st=db()->prepare('SELECT COUNT(*) FROM payroll_requests WHERE employee_id=? AND request_type_id=? AND affected_date=? AND id<>? AND status NOT IN ("REJECTED","CANCELLED")'); $st->execute([$employeeId,$type['id'],$affected,$revisionId??0]);
            if((int)$st->fetchColumn()) throw new RuntimeException('A request of this type already exists for this date. Open that request.');
            $st=db()->prepare('SELECT COUNT(*) FROM payroll_request_attachments WHERE request_id=?'); $st->execute([$revisionId??0]);
            if((int)$type['requires_attachment']===1 && !self::hasUpload($files) && !(int)$st->fetchColumn()) throw new RuntimeException('Attach supporting evidence.');
            $status=$route['type']==='ADL'?'FOR_ADL_APPROVAL':'FOR_MANAGER_APPROVAL'; $step=1;
            if($old) {
                $id=$revisionId;
                $st=db()->prepare('SELECT COALESCE(MAX(step_no),0)+1 FROM payroll_request_approvals WHERE request_id=?'); $st->execute([$id]); $step=(int)$st->fetchColumn();
                db()->prepare('UPDATE payroll_request_approvals SET status="SKIPPED" WHERE request_id=? AND status="PENDING"')->execute([$id]);
                db()->prepare('UPDATE payroll_requests SET cutoff_id=?,status=?,current_step=?,reason=?,remarks=?,submitted_at=NOW(),completed_at=NULL WHERE id=?')->execute([$cutoffId,$status,$step,$reason,trim((string)($data['remarks']??''))?:null,$id]);
                self::addPayrollHistory($id,$status,'Returned request revised and resubmitted; previous approval decisions retained.',$uid);
            } else {
                $no=self::nextRequestNo('PTR','payroll_requests');
                db()->prepare('INSERT INTO payroll_requests(request_no,employee_id,request_type_id,cutoff_id,affected_date,status,current_step,reason,remarks,submitted_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?)')->execute([$no,$employeeId,$type['id'],$cutoffId,$affected,$status,$step,$reason,trim((string)($data['remarks']??''))?:null,$uid]); $id=(int)db()->lastInsertId();
                self::addPayrollHistory($id,$status,'Request submitted.',$uid);
            }
            db()->prepare('INSERT INTO payroll_request_time_entries(request_id,time_in,lunch_out,lunch_in,time_out,ot_start,ot_end,destination,purpose,original_rest_day,new_rest_day) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE time_in=VALUES(time_in),lunch_out=VALUES(lunch_out),lunch_in=VALUES(lunch_in),time_out=VALUES(time_out),ot_start=VALUES(ot_start),ot_end=VALUES(ot_end),destination=VALUES(destination),purpose=VALUES(purpose),original_rest_day=VALUES(original_rest_day),new_rest_day=VALUES(new_rest_day)')->execute([$id,$times['time_in'],$times['lunch_out'],$times['lunch_in'],$times['time_out'],$times['ot_start'],$times['ot_end'],in_array($code,['OB','POB'],true)?(trim((string)($data['destination']??''))?:null):null,$purpose?:null,self::validDate((string)($data['original_rest_day']??'')),self::validDate((string)($data['new_rest_day']??''))]);
            if($claimCutoffId) db()->prepare('INSERT INTO payroll_backpay_claims(request_id,processing_cutoff_id) VALUES(?,?) ON DUPLICATE KEY UPDATE processing_cutoff_id=VALUES(processing_cutoff_id),state="AWAITING_APPROVAL",amount=NULL,snapshot_json=NULL')->execute([$id,$claimCutoffId]);
            if($automatic && in_array($code,['OT','POT'],true)) {
                $site=PayrollAttendanceService::site((int)$employee['branch_id'])??[];
                $startDate=trim((string)($data['ot_start_date']??''));
                $endDate=trim((string)($data['ot_end_date']??''));
                $startAt=$startDate!==''?new DateTimeImmutable(PayrollAttendanceService::date($startDate).' '.$times['ot_start']):new DateTimeImmutable(PayrollAttendanceService::reviewOnly()?PayrollAttendanceReview::correctionTime($affected,$times['ot_start'],$site):PayrollCalculator::correctionTime($affected,$times['ot_start'],$site));
                $endAt=$endDate!==''?new DateTimeImmutable(PayrollAttendanceService::date($endDate).' '.$times['ot_end']):new DateTimeImmutable($startAt->format('Y-m-d').' '.$times['ot_end']);
                if($endDate==='' && $endAt<=$startAt) $endAt=$endAt->modify('+1 day');
                if($startAt->format('Y-m-d')<$affected || $startAt->format('Y-m-d')>(new DateTimeImmutable($affected))->modify('+1 day')->format('Y-m-d') || $endAt<=$startAt || $endAt->getTimestamp()-$startAt->getTimestamp()>86400) throw new RuntimeException('OT dates must cover at most 24 hours, starting on the affected date or its overnight next day.');
                db()->prepare('INSERT INTO payroll_request_overtime_windows(request_id,started_at,ended_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE started_at=VALUES(started_at),ended_at=VALUES(ended_at)')->execute([$id,$startAt->format('Y-m-d H:i:s'),$endAt->format('Y-m-d H:i:s')]);
            }
            db()->prepare('INSERT INTO payroll_request_approvals(request_id,step_no,approver_type,approver_user_id,status) VALUES(?,?,?,?,"PENDING")')->execute([$id,$step,$route['type'],$route['user_id']]);
            if(!$automatic) {
                $second=trim((string)$type['second_approver_group']);
                if($second!=='') db()->prepare('INSERT INTO payroll_request_approvals(request_id,step_no,approver_type,status) VALUES(?,?,?,"PENDING")')->execute([$id,++$step,$second]);
                if((int)$type['requires_payroll_processing']===1) db()->prepare('INSERT INTO payroll_request_approvals(request_id,step_no,approver_type,status) VALUES(?,?,"PAYROLL","PENDING")')->execute([$id,++$step]);
            }
            if(self::hasUpload($files)) self::storePayrollAttachments($id,$files,$uid);
            audit('Payroll & Timekeeping',$old?'RESUBMIT_REQUEST':'SUBMIT_REQUEST','payroll_request',$id,['type'=>$code,'approver_type'=>$route['type']]); db()->commit(); return $id;
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function managerQueue(): array
    {
        if(!self::ready())return [];$uid=(int)(Auth::user()['id']??0);
        $st=db()->prepare('SELECT pr.id,pr.request_no,pr.affected_date,pr.status,pr.created_at,prt.name type_name,e.employee_no,e.first_name,e.last_name,b.name branch_name
            FROM payroll_request_approvals a JOIN payroll_requests pr ON pr.id=a.request_id JOIN payroll_request_types prt ON prt.id=pr.request_type_id JOIN employees e ON e.id=pr.employee_id LEFT JOIN branches b ON b.id=e.branch_id
            WHERE a.approver_type IN ("MANAGER","ADL") AND a.status="PENDING" AND a.approver_user_id=? AND pr.current_step=a.step_no ORDER BY pr.created_at');
        $st->execute([$uid]);return $st->fetchAll();
    }

    public static function managerPayrollHistory(int $limit=100): array
    {
        if(!self::ready())return [];
        $uid=(int)(Auth::user()['id']??0);
        $limit=max(1,min(250,$limit));
        $sql='SELECT pr.id,pr.request_no,pr.affected_date,pr.status request_status,pr.created_at,
            prt.name type_name,e.employee_no,e.first_name,e.last_name,b.name branch_name,
            a.status decision_status,a.remarks decision_remarks,a.acted_at decision_at
            FROM payroll_request_approvals a
            JOIN payroll_requests pr ON pr.id=a.request_id
            JOIN payroll_request_types prt ON prt.id=pr.request_type_id
            JOIN employees e ON e.id=pr.employee_id
            LEFT JOIN branches b ON b.id=e.branch_id
            WHERE a.approver_type IN ("MANAGER","ADL") AND a.acted_by=?
              AND a.status IN ("APPROVED","REJECTED","RETURNED")
            ORDER BY a.acted_at DESC,a.id DESC LIMIT '.$limit;
        $st=db()->prepare($sql);$st->execute([$uid]);return $st->fetchAll();
    }

    public static function managerLeaveHistory(int $limit=100): array
    {
        if(!FoundationRepository::tableExists('leave_request_approvals'))return [];
        $uid=(int)(Auth::user()['id']??0);
        $limit=max(1,min(250,$limit));
        $sql='SELECT lr.id,lr.request_no,lr.date_from,lr.date_to,lr.status request_status,lr.created_at,
            lt.name leave_type_name,e.employee_no,e.first_name,e.last_name,b.name branch_name,
            a.status decision_status,a.remarks decision_remarks,a.acted_at decision_at
            FROM leave_request_approvals a
            JOIN leave_requests lr ON lr.id=a.request_id
            JOIN leave_types lt ON lt.id=lr.leave_type_id
            JOIN employees e ON e.id=lr.employee_id
            LEFT JOIN branches b ON b.id=e.branch_id
            WHERE a.approver_type="MANAGER" AND a.acted_by=?
              AND a.status IN ("APPROVED","REJECTED","RETURNED")
            ORDER BY a.acted_at DESC,a.id DESC LIMIT '.$limit;
        $st=db()->prepare($sql);$st->execute([$uid]);return $st->fetchAll();
    }

    public static function hrPayrollQueue(string $approverType): array
    {
        if(!self::ready())return [];
        $st=db()->prepare('SELECT pr.id,pr.request_no,pr.affected_date,pr.status,pr.created_at,prt.name type_name,
            e.employee_no,e.first_name,e.last_name,b.name branch_name,a.step_no,
            CONCAT_WS(" ",m.first_name,m.last_name) reporting_manager_name,
            ma.status manager_approval_status,ma.acted_at manager_approved_at,
            COALESCE(mu.full_name,mau.full_name,CONCAT_WS(" ",m.first_name,m.last_name)) manager_approver_name
            FROM payroll_request_approvals a
            JOIN payroll_requests pr ON pr.id=a.request_id
            JOIN payroll_request_types prt ON prt.id=pr.request_type_id
            JOIN employees e ON e.id=pr.employee_id
            LEFT JOIN branches b ON b.id=e.branch_id
            LEFT JOIN employees m ON m.id=e.manager_employee_id
            LEFT JOIN payroll_request_approvals ma ON ma.request_id=pr.id AND ma.approver_type="MANAGER"
            LEFT JOIN users mu ON mu.id=ma.acted_by
            LEFT JOIN users mau ON mau.id=ma.approver_user_id
            WHERE a.approver_type=? AND a.status="PENDING" AND pr.current_step=a.step_no ORDER BY pr.created_at');
        $st->execute([$approverType]);return $st->fetchAll();
    }

    public static function decidePayroll(int $requestId,string $decision,string $remarks=''): void
    {
        if(!Auth::check()) throw new RuntimeException('Sign in first.');
        $decision=strtoupper(trim($decision)); $remarks=trim($remarks);
        if(!in_array($decision,['APPROVE','REJECT','RETURN'],true)) throw new RuntimeException('Invalid decision.');
        if($decision!=='APPROVE' && $remarks==='') throw new RuntimeException('Explain a return or rejection.');
        $req=self::payrollRequest($requestId); if(!$req) throw new RuntimeException('Request not found.');
        db()->beginTransaction();
        try {
            if(PayrollAttendanceService::ready() && $req['cutoff_id']) { $claim=PayrollAttendanceService::backpayRequest($requestId); $actionCutoff=(int)($claim['processing_cutoff_id']??$req['cutoff_id']); PayrollAttendanceService::lockOpen($actionCutoff); }
            $st=db()->prepare('SELECT * FROM payroll_requests WHERE id=? FOR UPDATE'); $st->execute([$requestId]); $fresh=$st->fetch();
            $step=(int)$fresh['current_step'];
            $st=db()->prepare('SELECT * FROM payroll_request_approvals WHERE request_id=? AND step_no=? AND status="PENDING" FOR UPDATE'); $st->execute([$requestId,$step]); $approval=$st->fetch();
            if(!$approval) throw new RuntimeException('This request is no longer awaiting your decision.');
            $uid=(int)Auth::user()['id']; $type=$approval['approver_type'];
            $permission=match($type) {'MANAGER'=>'payroll.approve_manager','ADL'=>'payroll.approve_adl','HR_TIMEKEEPING'=>'payroll.approve_hr','PAYROLL'=>'payroll.process','HR_LEAVE'=>'leave.approve_hr',default=>throw new RuntimeException('Unknown approval stage.')};
            if(!Auth::can($permission)) throw new RuntimeException('You do not have approval permission.');
            if(in_array($type,['MANAGER','ADL'],true) && ((int)$approval['approver_user_id']!==$uid || (int)$req['created_by']===$uid)) throw new RuntimeException('This request is assigned to another approver.');
            if($type==='HR_LEAVE' && strtoupper((string)$req['category'])!=='LEAVE') throw new RuntimeException('This request is not a leave ticket.');
            if($type==='HR_LEAVE' && ((int)$fresh['created_by']===$uid || (int)(self::currentEmployee()['id']??0)===(int)$fresh['employee_id'])) throw new RuntimeException('Another approver must review your own leave correction.');
            $auto=PayrollAttendanceService::ready() && PayrollAttendanceService::automaticType($req['type_code']);
            if($auto && $req['cutoff_id'] && $decision==='APPROVE') PayrollAttendanceService::assertTicketAllowed(['id'=>$actionCutoff]);
            $newApproval=match($decision) {'APPROVE'=>'APPROVED','RETURN'=>'RETURNED',default=>'REJECTED'};
            db()->prepare('UPDATE payroll_request_approvals SET status=?,remarks=?,acted_by=?,acted_at=NOW() WHERE id=?')->execute([$newApproval,$remarks?:null,$uid,$approval['id']]);
            $nextStep=$step;
            if($decision==='RETURN') $newStatus='RETURNED_FOR_REVISION';
            elseif($decision==='REJECT') $newStatus='REJECTED';
            elseif($auto && in_array($type,['MANAGER','ADL'],true)) {
                db()->prepare('UPDATE payroll_request_approvals SET status="SKIPPED",remarks="Manager/ADL is the final approver under the biometric payroll workflow." WHERE request_id=? AND step_no>? AND status="PENDING"')->execute([$requestId,$step]);
                $newStatus='APPROVED';
            } else {
                $st=db()->prepare('SELECT step_no,approver_type FROM payroll_request_approvals WHERE request_id=? AND step_no>? AND status="PENDING" ORDER BY step_no LIMIT 1'); $st->execute([$requestId,$step]); $next=$st->fetch();
                if($next) { $nextStep=(int)$next['step_no']; $newStatus=self::statusForApprover($next['approver_type']); } else $newStatus='COMPLETED';
            }
            db()->prepare('UPDATE payroll_requests SET status=?,current_step=?,completed_at=? WHERE id=?')->execute([$newStatus,$nextStep,in_array($newStatus,['APPROVED','COMPLETED'],true)?date('Y-m-d H:i:s'):null,$requestId]);
            self::addPayrollHistory($requestId,$newStatus,$remarks?:stage_label($newApproval).'.',$uid);
            if($auto) PayrollAttendanceService::afterApproval($requestId);
            audit('Payroll & Timekeeping','APPROVAL_'.$decision,'payroll_request',$requestId,['step'=>$step,'approver_type'=>$type]); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function leaveTypes(): array
    {
        if(!FoundationRepository::tableExists('leave_types'))return [];
        return db()->query('SELECT * FROM leave_types WHERE active=1 ORDER BY sort_order,name')->fetchAll();
    }

    public static function leaveBalances(int $employeeId): array
    {
        if(!FoundationRepository::tableExists('leave_types'))return [];
        $st=db()->prepare('SELECT lt.id,lt.code,lt.name,lt.paid,lt.requires_credit,COALESCE(b.balance,0) balance,
            COALESCE((SELECT SUM(lr.days) FROM leave_requests lr WHERE lr.employee_id=? AND lr.leave_type_id=lt.id AND lr.status IN ("FOR_MANAGER_APPROVAL","FOR_HR_LEAVE_APPROVAL")),0) pending
            FROM leave_types lt LEFT JOIN employee_leave_balances b ON b.leave_type_id=lt.id AND b.employee_id=? WHERE lt.active=1 ORDER BY lt.sort_order,lt.name');
        $st->execute([$employeeId,$employeeId]);return $st->fetchAll();
    }

    public static function leaveTransactions(int $employeeId): array
    {
        if(!FoundationRepository::tableExists('leave_credit_transactions'))return [];
        $st=db()->prepare('SELECT t.*,lt.name leave_type_name,u.full_name created_by_name FROM leave_credit_transactions t JOIN leave_types lt ON lt.id=t.leave_type_id LEFT JOIN users u ON u.id=t.created_by WHERE t.employee_id=? ORDER BY t.created_at DESC,t.id DESC LIMIT 100');
        $st->execute([$employeeId]);return $st->fetchAll();
    }

    public static function myLeaveRequests(int $employeeId): array
    {
        if(!FoundationRepository::tableExists('leave_requests'))return [];
        $st=db()->prepare('SELECT lr.*,lt.name leave_type_name,lt.code leave_type_code FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.employee_id=? ORDER BY lr.created_at DESC LIMIT 100');$st->execute([$employeeId]);return $st->fetchAll();
    }

    public static function createLeaveRequest(int $employeeId,array $data): int
    {
        $employee=EmployeeRepository::find($employeeId);if(!$employee|| (int)($employee['user_id']??0)!==(int)(Auth::user()['id']??0))throw new RuntimeException('Employee record is not linked to this account.');
        $typeId=(int)($data['leave_type_id']??0);$st=db()->prepare('SELECT * FROM leave_types WHERE id=? AND active=1');$st->execute([$typeId]);$type=$st->fetch();if(!$type)throw new RuntimeException('Choose a valid leave type.');
        $from=self::validDate((string)($data['date_from']??''));$to=self::validDate((string)($data['date_to']??''));if(!$from||!$to||$to<$from)throw new RuntimeException('Enter a valid leave date range.');
        $days=(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days+1;$days=(float)$days;
        $reason=trim((string)($data['reason']??''));if($reason==='')throw new RuntimeException('Reason is required.');
        $st=db()->prepare('SELECT COUNT(*) FROM leave_requests WHERE employee_id=? AND status NOT IN ("REJECTED","CANCELLED") AND date_from<=? AND date_to>=?');$st->execute([$employeeId,$to,$from]);if((int)$st->fetchColumn()>0)throw new RuntimeException('You already have a leave request overlapping this date range.');
        if((int)$type['requires_credit']===1){$st=db()->prepare('SELECT balance FROM employee_leave_balances WHERE employee_id=? AND leave_type_id=?');$st->execute([$employeeId,$typeId]);$bal=(float)($st->fetchColumn()?:0);if($days>$bal)throw new RuntimeException('Insufficient leave credits for this request.');}
        $managerUserId=self::managerApproverUserId($employee);
        if(!$managerUserId)throw new RuntimeException('Your assigned Immediate Manager must have an active HRIS Manager account with approval access.');
        $uid=(int)(Auth::user()['id']??0);$no=self::nextRequestNo('LVR','leave_requests');
        db()->beginTransaction();try{
            $st=db()->prepare('INSERT INTO leave_requests(request_no,employee_id,leave_type_id,date_from,date_to,days,reason,status,current_step,created_by) VALUES(?,?,?,?,?,?,?,"FOR_MANAGER_APPROVAL",1,?)');$st->execute([$no,$employeeId,$typeId,$from,$to,$days,$reason,$uid]);$id=(int)db()->lastInsertId();
            $st=db()->prepare('INSERT INTO leave_request_approvals(request_id,step_no,approver_type,approver_user_id,status) VALUES(?,1,"MANAGER",?,"PENDING"),(?,2,"HR_LEAVE",NULL,"PENDING")');$st->execute([$id,$managerUserId,$id]);db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        audit('Leave','SUBMIT_LEAVE','leave_request',$id,['request_no'=>$no,'days'=>$days]);return $id;
    }

    public static function leaveQueue(string $type): array
    {
        if(!FoundationRepository::tableExists('leave_request_approvals'))return [];$uid=(int)(Auth::user()['id']??0);
        $sql='SELECT lr.*,lt.name leave_type_name,e.employee_no,e.first_name,e.last_name,b.name branch_name,a.step_no FROM leave_request_approvals a JOIN leave_requests lr ON lr.id=a.request_id JOIN leave_types lt ON lt.id=lr.leave_type_id JOIN employees e ON e.id=lr.employee_id LEFT JOIN branches b ON b.id=e.branch_id WHERE a.approver_type=? AND a.status="PENDING" AND lr.current_step=a.step_no';$params=[$type];if($type==='MANAGER'){$sql.=' AND a.approver_user_id=?';$params[]=$uid;}$sql.=' ORDER BY lr.created_at';$st=db()->prepare($sql);$st->execute($params);return $st->fetchAll();
    }

    /** The final leave queue includes both leave applications and leave correction tickets. */
    public static function leaveApprovalQueue(): array
    {
        Auth::requirePermission('leave.approve_hr');
        if(!self::ready()) return [];
        $queue=[];
        foreach(self::leaveQueue('HR_LEAVE') as $row) {
            if($row['status']!=='FOR_HR_LEAVE_APPROVAL') continue;
            $queue[]=$row+['approval_kind'=>'LEAVE','type_name'=>$row['leave_type_name']];
        }
        $q=db()->prepare('SELECT pr.*,prt.name type_name,e.employee_no,e.first_name,e.last_name,b.name branch_name
            FROM payroll_requests pr
            JOIN payroll_request_types prt ON prt.id=pr.request_type_id
            JOIN payroll_request_approvals a ON a.request_id=pr.id AND a.step_no=pr.current_step
            JOIN employees e ON e.id=pr.employee_id
            LEFT JOIN branches b ON b.id=e.branch_id
            WHERE prt.category="LEAVE" AND pr.status="FOR_HR_LEAVE_APPROVAL"
              AND a.approver_type="HR_LEAVE" AND a.status="PENDING"
            ORDER BY pr.created_at,pr.id');
        $q->execute();
        foreach($q->fetchAll() as $row) $queue[]=$row+['approval_kind'=>'PAYROLL'];
        usort($queue,static fn($a,$b)=>strcmp((string)$a['created_at'],(string)$b['created_at']) ?: strcmp($a['request_no'],$b['request_no']));
        return $queue;
    }

    public static function decideLeave(int $requestId,string $decision,string $remarks=''): void
    {
        $decision=strtoupper(trim($decision));if(!in_array($decision,['APPROVE','REJECT','RETURN'],true))throw new RuntimeException('Invalid decision.');
        $st=db()->prepare('SELECT lr.*,lt.requires_credit FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.id=?');$st->execute([$requestId]);$req=$st->fetch();if(!$req)throw new RuntimeException('Leave request not found.');
        $step=(int)$req['current_step'];$st=db()->prepare('SELECT * FROM leave_request_approvals WHERE request_id=? AND step_no=? AND status="PENDING"');$st->execute([$requestId,$step]);$a=$st->fetch();if(!$a)throw new RuntimeException('Request is not awaiting approval.');$uid=(int)(Auth::user()['id']??0);$type=(string)$a['approver_type'];
        if($type==='MANAGER' && (int)$a['approver_user_id']!==$uid)throw new RuntimeException('This leave request is assigned to another manager.');
        if($type==='HR_LEAVE' && !Auth::can('leave.approve_hr'))throw new RuntimeException('You do not have HR Leave approval permission.');
        db()->beginTransaction();try{
            $ast=$decision==='APPROVE'?'APPROVED':($decision==='RETURN'?'RETURNED':'REJECTED');$st=db()->prepare('UPDATE leave_request_approvals SET status=?,remarks=?,acted_by=?,acted_at=NOW() WHERE id=?');$st->execute([$ast,$remarks?:null,$uid,$a['id']]);
            if($decision==='APPROVE' && $type==='MANAGER'){$status='FOR_HR_LEAVE_APPROVAL';$next=2;}
            elseif($decision==='APPROVE' && $type==='HR_LEAVE'){$status='APPROVED';$next=2;if((int)$req['requires_credit']===1)self::applyLeaveCredit((int)$req['employee_id'],(int)$req['leave_type_id'],-(float)$req['days'],'LEAVE_DEDUCTION','leave_request',$requestId,'Approved leave '.$req['request_no'],$uid);}
            elseif($decision==='RETURN'){$status='RETURNED_FOR_REVISION';$next=$step;}else{$status='REJECTED';$next=$step;}
            $st=db()->prepare('UPDATE leave_requests SET status=?,current_step=? WHERE id=?');$st->execute([$status,$next,$requestId]);db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        audit('Leave','APPROVAL_'.$decision,'leave_request',$requestId,['step'=>$step,'approver_type'=>$type]);
    }

    public static function adjustLeaveCredit(int $employeeId,int $leaveTypeId,float $amount,string $reason): void
    {
        if(!Auth::can('leave.credits.manage'))throw new RuntimeException('You do not have permission to manage leave credits.');
        if(abs($amount)<0.001)throw new RuntimeException('Adjustment amount cannot be zero.');if(trim($reason)==='')throw new RuntimeException('Adjustment reason is required.');
        db()->beginTransaction();
        try{self::applyLeaveCredit($employeeId,$leaveTypeId,$amount,'MANUAL_ADJUSTMENT','manual',null,$reason,(int)(Auth::user()['id']??0));db()->commit();}
        catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        audit('Leave','ADJUST_CREDIT','employee',$employeeId,['leave_type_id'=>$leaveTypeId,'amount'=>$amount,'reason'=>$reason]);
    }

    public static function allActiveEmployees(): array
    {
        if(!FoundationRepository::tableExists('employees'))return [];
        return db()->query("SELECT id,employee_no,first_name,middle_name,last_name FROM employees WHERE status IN ('ACTIVE','PROBATIONARY','ON_LEAVE') ORDER BY last_name,first_name")->fetchAll();
    }

    private static function applyLeaveCredit(int $employeeId,int $leaveTypeId,float $amount,string $transactionType,?string $referenceType,?int $referenceId,string $reason,?int $uid): void
    {
        $st=db()->prepare('INSERT INTO employee_leave_balances(employee_id,leave_type_id,balance) VALUES(?,?,?) ON DUPLICATE KEY UPDATE balance=balance+VALUES(balance)');$st->execute([$employeeId,$leaveTypeId,$amount]);
        $st=db()->prepare('SELECT balance FROM employee_leave_balances WHERE employee_id=? AND leave_type_id=?');$st->execute([$employeeId,$leaveTypeId]);if((float)$st->fetchColumn() < -0.0001)throw new RuntimeException('Leave credit adjustment would create a negative balance.');
        $st=db()->prepare('INSERT INTO leave_credit_transactions(employee_id,leave_type_id,amount,transaction_type,reference_type,reference_id,reason,created_by) VALUES(?,?,?,?,?,?,?,?)');$st->execute([$employeeId,$leaveTypeId,$amount,$transactionType,$referenceType,$referenceId,$reason,$uid]);
    }

    private static function addPayrollHistory(int $id,string $status,string $note,?int $uid): void
    {
        $st=db()->prepare('INSERT INTO payroll_request_history(request_id,status,note,created_by) VALUES(?,?,?,?)');$st->execute([$id,$status,$note,$uid]);
    }

    private static function nextRequestNo(string $prefix,string $table): string
    {
        $base=$prefix.'-'.date('Ymd').'-';$safe=in_array($table,['payroll_requests','leave_requests'],true)?$table:'payroll_requests';$st=db()->prepare("SELECT request_no FROM {$safe} WHERE request_no LIKE ? ORDER BY id DESC LIMIT 50");$st->execute([$base.'%']);$max=0;foreach($st->fetchAll(PDO::FETCH_COLUMN) as $v){if(preg_match('/(\d+)$/',(string)$v,$m))$max=max($max,(int)$m[1]);}return $base.str_pad((string)($max+1),4,'0',STR_PAD_LEFT);
    }

    private static function statusForApprover(string $type): string
    {
        return match($type){'HR_TIMEKEEPING'=>'FOR_HR_TIMEKEEPING_APPROVAL','PAYROLL'=>'FOR_PAYROLL_PROCESSING','HR_LEAVE'=>'FOR_HR_LEAVE_APPROVAL',default=>'SUBMITTED'};
    }

    private static function validDate(string $v): ?string
    {
        $v=trim($v);if($v==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v?$v:null;
    }

    private static function validTime(mixed $v): ?string
    {
        $v=trim((string)$v);if($v==='')return null;if(preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$v))return strlen($v)===5?$v.':00':$v;return null;
    }

    private static function managerApproverUserId(array $employee): ?int
    {
        $managerId=(int)($employee['manager_employee_id']??0);if($managerId<=0)return null;
        $st=db()->prepare('SELECT u.id,r.code,MAX(CASE WHEN p.code="payroll.approve_manager" THEN 1 ELSE 0 END) can_approve FROM employees m JOIN users u ON u.id=m.user_id AND u.status="ACTIVE" JOIN roles r ON r.id=u.role_id LEFT JOIN role_permissions rp ON rp.role_id=r.id LEFT JOIN permissions p ON p.id=rp.permission_id WHERE m.id=? GROUP BY u.id,r.code LIMIT 1');
        $st->execute([$managerId]);$row=$st->fetch();if(!$row)return null;
        if($row['code']==='SUPER_ADMIN'||(int)$row['can_approve']===1)return (int)$row['id'];
        return null;
    }

    private static function hasUpload(array $files): bool
    {
        $errors=$files['error']??UPLOAD_ERR_NO_FILE;if(is_array($errors)){foreach($errors as $e)if((int)$e===UPLOAD_ERR_OK)return true;return false;}return (int)$errors===UPLOAD_ERR_OK;
    }

    private static function storePayrollAttachments(int $requestId,array $files,int $uid): void
    {
        $names=is_array($files['name']??null)?$files['name']:[$files['name']??''];$tmp=is_array($files['tmp_name']??null)?$files['tmp_name']:[$files['tmp_name']??''];$errs=is_array($files['error']??null)?$files['error']:[$files['error']??UPLOAD_ERR_NO_FILE];$sizes=is_array($files['size']??null)?$files['size']:[$files['size']??0];
        $dir=dirname(__DIR__).'/storage/payroll_requests';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Unable to create payroll request storage.');
        $finfo=new finfo(FILEINFO_MIME_TYPE);$allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];$saved=[];
        try{
            foreach($names as $i=>$name){
                if(($errs[$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
                if(($errs[$i]??0)!==UPLOAD_ERR_OK)throw new RuntimeException('One attachment could not be uploaded.');
                if((int)($sizes[$i]??0)>10*1024*1024)throw new RuntimeException('Each attachment must be 10 MB or smaller.');
                $mime=$finfo->file((string)$tmp[$i]);$ext=$allowed[$mime]??null;if(!$ext)throw new RuntimeException('Attachments must be PDF, JPG, PNG, or WEBP.');
                $stored='ptr_'.$requestId.'_'.bin2hex(random_bytes(10)).'.'.$ext;
                if(!move_uploaded_file((string)$tmp[$i],$dir.'/'.$stored))throw new RuntimeException('Could not save an attachment.');
                $saved[]=$dir.'/'.$stored;
                $st=db()->prepare('INSERT INTO payroll_request_attachments(request_id,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?)');$st->execute([$requestId,mb_substr((string)$name,0,255),$stored,$mime,(int)$sizes[$i],$uid]);
                if(count($saved)>=5)break;
            }
        }catch(Throwable $e){foreach($saved as $path)if(is_file($path))@unlink($path);throw $e;}
    }
}
