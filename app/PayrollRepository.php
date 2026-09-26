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
                $periodEnd=$day===4?$periodStart->setDate((int)$periodStart->format('Y'),(int)$periodStart->format('n'),18):$periodStart->modify('+14 days');
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
        if($day>=19){$start=$d->setDate((int)$d->format('Y'),(int)$d->format('n'),19);$end=$start->modify('+14 days');}
        elseif($day>=4){$start=$d->setDate((int)$d->format('Y'),(int)$d->format('n'),4);$end=$d->setDate((int)$d->format('Y'),(int)$d->format('n'),18);}
        else{$end=$d->setDate((int)$d->format('Y'),(int)$d->format('n'),3);$start=$end->modify('-14 days');}
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
            pc.period_start cutoff_start,pc.period_end cutoff_end
            FROM payroll_requests pr
            JOIN payroll_request_types prt ON prt.id=pr.request_type_id
            JOIN employees e ON e.id=pr.employee_id
            LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN branches b ON b.id=e.branch_id LEFT JOIN positions p ON p.id=e.position_id
            LEFT JOIN payroll_cutoffs pc ON pc.id=pr.cutoff_id WHERE pr.id=? LIMIT 1');
        $st->execute([$id]); $r=$st->fetch(); if(!$r)return null;
        $st=db()->prepare('SELECT * FROM payroll_request_time_entries WHERE request_id=?');$st->execute([$id]);$r['time']=$st->fetch()?:[];
        $st=db()->prepare('SELECT a.*,u.full_name acted_by_name,au.full_name assigned_name FROM payroll_request_approvals a LEFT JOIN users u ON u.id=a.acted_by LEFT JOIN users au ON au.id=a.approver_user_id WHERE a.request_id=? ORDER BY a.step_no');$st->execute([$id]);$r['approvals']=$st->fetchAll();
        $st=db()->prepare('SELECT h.*,u.full_name created_by_name FROM payroll_request_history h LEFT JOIN users u ON u.id=h.created_by WHERE h.request_id=? ORDER BY h.created_at,h.id');$st->execute([$id]);$r['history']=$st->fetchAll();
        $st=db()->prepare('SELECT * FROM payroll_request_attachments WHERE request_id=? ORDER BY uploaded_at');$st->execute([$id]);$r['attachments']=$st->fetchAll();
        return $r;
    }

    public static function createPayrollRequest(int $employeeId,array $data,array $files=[]): int
    {
        if(!self::ready()) throw new RuntimeException('Import the Phase 3A database migration first.');
        $employee=EmployeeRepository::find($employeeId); if(!$employee)throw new RuntimeException('Employee record not found.');
        if((int)($employee['user_id']??0)!==(int)(Auth::user()['id']??0)) throw new RuntimeException('You can only file a request for your own employee record.');
        $typeId=(int)($data['request_type_id']??0);$st=db()->prepare('SELECT * FROM payroll_request_types WHERE id=? AND active=1');$st->execute([$typeId]);$type=$st->fetch();if(!$type)throw new RuntimeException('Choose a valid request type.');
        $affected=self::validDate((string)($data['affected_date']??'')); if(!$affected)throw new RuntimeException('Affected date is required.');
        $reason=trim((string)($data['reason']??''));if($reason==='')throw new RuntimeException('Reason is required.');
        if((int)$type['requires_manager_approval']===1 && empty($employee['manager_employee_id']))throw new RuntimeException('Your Immediate Manager is not configured in your employee record. Ask HR to update your Reporting To assignment.');
        $managerUserId=self::managerApproverUserId($employee);
        if((int)$type['requires_manager_approval']===1 && !$managerUserId)throw new RuntimeException('Your assigned Immediate Manager must have an active HRIS Manager account with approval access.');
        $st=db()->prepare('SELECT COUNT(*) FROM payroll_requests WHERE employee_id=? AND request_type_id=? AND affected_date=? AND status NOT IN ("REJECTED","CANCELLED","COMPLETED")');$st->execute([$employeeId,$typeId,$affected]);if((int)$st->fetchColumn()>0)throw new RuntimeException('You already have an active request of this type for the selected date.');
        $cutoffId=null;
        if(strtoupper((string)$type['category'])!=='LEAVE'){
            $selectedCutoffId=(int)($data['cutoff_id']??0);
            $autoCutoff=self::cutoffForDate($affected);
            if(!$autoCutoff)throw new RuntimeException('No payroll cutoff could be determined for the selected affected date.');
            if($selectedCutoffId>0){
                $st=db()->prepare('SELECT * FROM payroll_cutoffs WHERE id=? LIMIT 1');$st->execute([$selectedCutoffId]);$selected=$st->fetch();
                if(!$selected)throw new RuntimeException('Choose a valid payroll cutoff.');
                if($affected<(string)$selected['period_start']||$affected>(string)$selected['period_end'])throw new RuntimeException('The selected payroll cutoff does not cover the affected date. Choose a date within that cutoff or use the automatically matched cutoff.');
                $cutoffId=(int)$selected['id'];
            }else{$cutoffId=(int)$autoCutoff['id'];}
        }
        if((int)$type['requires_attachment']===1 && !self::hasUpload($files)) throw new RuntimeException('This request type requires at least one supporting attachment.');
        $uid=(int)(Auth::user()['id']??0);
        db()->beginTransaction();
        try{
            $no=self::nextRequestNo('PTR','payroll_requests');
            $status=(int)$type['requires_manager_approval']===1?'FOR_MANAGER_APPROVAL':self::statusForApprover((string)$type['second_approver_group']);
            $step=(int)$type['requires_manager_approval']===1?1:1;
            $st=db()->prepare('INSERT INTO payroll_requests(request_no,employee_id,request_type_id,cutoff_id,affected_date,status,current_step,reason,remarks,submitted_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?)');
            $st->execute([$no,$employeeId,$typeId,$cutoffId,$affected,$status,$step,$reason,trim((string)($data['remarks']??''))?:null,$uid]);
            $id=(int)db()->lastInsertId();
            $st=db()->prepare('INSERT INTO payroll_request_time_entries(request_id,time_in,lunch_out,lunch_in,time_out,ot_start,ot_end,destination,purpose,original_rest_day,new_rest_day) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$id,self::validTime($data['time_in']??''),self::validTime($data['lunch_out']??''),self::validTime($data['lunch_in']??''),self::validTime($data['time_out']??''),self::validTime($data['ot_start']??''),self::validTime($data['ot_end']??''),trim((string)($data['destination']??''))?:null,trim((string)($data['purpose']??''))?:null,self::validDate((string)($data['original_rest_day']??'')),self::validDate((string)($data['new_rest_day']??''))]);
            $stepNo=1;
            if((int)$type['requires_manager_approval']===1){$st=db()->prepare('INSERT INTO payroll_request_approvals(request_id,step_no,approver_type,approver_user_id,status) VALUES(?,?,?,?,"PENDING")');$st->execute([$id,$stepNo++,'MANAGER',$managerUserId]);}
            $second=trim((string)$type['second_approver_group']);if($second!==''){$st=db()->prepare('INSERT INTO payroll_request_approvals(request_id,step_no,approver_type,status) VALUES(?,?,?,"PENDING")');$st->execute([$id,$stepNo++,$second]);}
            if((int)$type['requires_payroll_processing']===1){$st=db()->prepare('INSERT INTO payroll_request_approvals(request_id,step_no,approver_type,status) VALUES(?,?,"PAYROLL","PENDING")');$st->execute([$id,$stepNo++]);}
            self::addPayrollHistory($id,$status,'Request submitted.',$uid);
            if(self::hasUpload($files)) self::storePayrollAttachments($id,$files,$uid);
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        audit('Payroll & Timekeeping','SUBMIT_REQUEST','payroll_request',$id,['request_no'=>$no,'type'=>$type['code']]);
        return $id;
    }

    public static function managerQueue(): array
    {
        if(!self::ready())return [];$uid=(int)(Auth::user()['id']??0);
        $st=db()->prepare('SELECT pr.id,pr.request_no,pr.affected_date,pr.status,pr.created_at,prt.name type_name,e.employee_no,e.first_name,e.last_name,b.name branch_name
            FROM payroll_request_approvals a JOIN payroll_requests pr ON pr.id=a.request_id JOIN payroll_request_types prt ON prt.id=pr.request_type_id JOIN employees e ON e.id=pr.employee_id LEFT JOIN branches b ON b.id=e.branch_id
            WHERE a.approver_type="MANAGER" AND a.status="PENDING" AND a.approver_user_id=? AND pr.current_step=a.step_no ORDER BY pr.created_at');
        $st->execute([$uid]);return $st->fetchAll();
    }

    public static function hrPayrollQueue(string $approverType): array
    {
        if(!self::ready())return [];
        $st=db()->prepare('SELECT pr.id,pr.request_no,pr.affected_date,pr.status,pr.created_at,prt.name type_name,e.employee_no,e.first_name,e.last_name,b.name branch_name,a.step_no
            FROM payroll_request_approvals a JOIN payroll_requests pr ON pr.id=a.request_id JOIN payroll_request_types prt ON prt.id=pr.request_type_id JOIN employees e ON e.id=pr.employee_id LEFT JOIN branches b ON b.id=e.branch_id
            WHERE a.approver_type=? AND a.status="PENDING" AND pr.current_step=a.step_no ORDER BY pr.created_at');
        $st->execute([$approverType]);return $st->fetchAll();
    }

    public static function decidePayroll(int $requestId,string $decision,string $remarks=''): void
    {
        $decision=strtoupper(trim($decision));if(!in_array($decision,['APPROVE','REJECT','RETURN'],true))throw new RuntimeException('Invalid decision.');
        $req=self::payrollRequest($requestId);if(!$req)throw new RuntimeException('Request not found.');
        $step=(int)$req['current_step'];$st=db()->prepare('SELECT * FROM payroll_request_approvals WHERE request_id=? AND step_no=? AND status="PENDING" LIMIT 1');$st->execute([$requestId,$step]);$approval=$st->fetch();if(!$approval)throw new RuntimeException('This request is not awaiting approval at the current step.');
        $uid=(int)(Auth::user()['id']??0);$type=(string)$approval['approver_type'];
        if($type==='MANAGER' && (int)($approval['approver_user_id']??0)!==$uid)throw new RuntimeException('This request is assigned to another manager.');
        if($type==='HR_TIMEKEEPING' && !Auth::can('payroll.approve_hr'))throw new RuntimeException('You do not have HR Timekeeping approval permission.');
        if($type==='PAYROLL' && !Auth::can('payroll.process'))throw new RuntimeException('You do not have Payroll processing permission.');
        db()->beginTransaction();try{
            $newApproval=$decision==='APPROVE'?'APPROVED':($decision==='RETURN'?'RETURNED':'REJECTED');
            $st=db()->prepare('UPDATE payroll_request_approvals SET status=?,remarks=?,acted_by=?,acted_at=NOW() WHERE id=?');$st->execute([$newApproval,$remarks?:null,$uid,$approval['id']]);
            if($decision==='RETURN'){$newStatus='RETURNED_FOR_REVISION';$nextStep=$step;}
            elseif($decision==='REJECT'){$newStatus='REJECTED';$nextStep=$step;}
            else{
                $st=db()->prepare('SELECT step_no,approver_type FROM payroll_request_approvals WHERE request_id=? AND step_no>? AND status="PENDING" ORDER BY step_no LIMIT 1');$st->execute([$requestId,$step]);$next=$st->fetch();
                if($next){$nextStep=(int)$next['step_no'];$newStatus=self::statusForApprover((string)$next['approver_type']);}
                else{$nextStep=$step;$newStatus='COMPLETED';}
            }
            $st=db()->prepare('UPDATE payroll_requests SET status=?,current_step=?,completed_at=? WHERE id=?');$st->execute([$newStatus,$nextStep,$newStatus==='COMPLETED'?date('Y-m-d H:i:s'):null,$requestId]);
            self::addPayrollHistory($requestId,$newStatus,$remarks?:stage_label($newApproval).'.',$uid);
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        audit('Payroll & Timekeeping','APPROVAL_'.$decision,'payroll_request',$requestId,['step'=>$step,'approver_type'=>$type]);
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
        $v=trim((string)$v);if($v==='')return null;if(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$v))return $v.':00';return null;
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
