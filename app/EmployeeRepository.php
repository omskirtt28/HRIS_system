<?php
declare(strict_types=1);

final class EmployeeRepository
{
    private const STATUSES=['ACTIVE','PROBATIONARY','ON_LEAVE','INACTIVE','RESIGNED','TERMINATED'];
    private const GOV_ID_TYPES=['SSS','PHILHEALTH','PAGIBIG','TIN','UMID','PASSPORT','DRIVERS_LICENSE','PRC','OTHER'];
    private const DOC_TYPES=['CONTRACT','RESUME','NBI_CLEARANCE','POLICE_CLEARANCE','MEDICAL','GOVERNMENT_ID','CERTIFICATE','MEMO','OTHER'];

    public static function ready(): bool
    {
        return FoundationRepository::tableExists('employees');
    }

    public static function phase2Ready(): bool
    {
        foreach(['employee_government_ids','employee_emergency_contacts','employee_documents','employee_employment_history'] as $table) {
            if(!FoundationRepository::tableExists($table)) return false;
        }
        return self::ready();
    }

    public static function nextEmployeeNo(): string
    {
        $prefix='PMBSI-'.date('Y').'-';
        if (!self::ready()) return $prefix.'0001';
        $st=db()->prepare("SELECT employee_no FROM employees WHERE employee_no LIKE ? ORDER BY id DESC LIMIT 200");
        $st->execute([$prefix.'%']);
        $max=0;
        foreach($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
            if(preg_match('/(\\d+)$/',(string)$no,$m)) $max=max($max,(int)$m[1]);
        }
        return $prefix.str_pad((string)($max+1),4,'0',STR_PAD_LEFT);
    }

    public static function summary(): array
    {
        if(!self::ready()) return ['total'=>0,'active'=>0,'probationary'=>0,'inactive'=>0,'new_this_month'=>0];
        $row=db()->query("SELECT
            COUNT(*) total,
            SUM(status='ACTIVE') active,
            SUM(status='PROBATIONARY') probationary,
            SUM(status IN ('INACTIVE','RESIGNED','TERMINATED')) inactive,
            SUM(YEAR(hire_date)=YEAR(CURDATE()) AND MONTH(hire_date)=MONTH(CURDATE())) new_this_month
            FROM employees")->fetch() ?: [];
        return array_map('intval',array_merge(['total'=>0,'active'=>0,'probationary'=>0,'inactive'=>0,'new_this_month'=>0],$row));
    }

    public static function directory(array $filters=[]): array
    {
        if(!self::ready()) return [];
        $where=['1=1']; $params=[];
        $q=trim((string)($filters['q']??''));
        if($q!=='') {
            $where[]='(e.employee_no LIKE ? OR e.first_name LIKE ? OR e.middle_name LIKE ? OR e.last_name LIKE ? OR e.company_email LIKE ? OR e.personal_email LIKE ?)';
            $like='%'.$q.'%'; $params=array_merge($params,[$like,$like,$like,$like,$like,$like]);
        }
        foreach(['department_id'=>'e.department_id','branch_id'=>'e.branch_id','employment_type_id'=>'e.employment_type_id'] as $key=>$col) {
            $v=(int)($filters[$key]??0); if($v>0){$where[]="$col=?";$params[]=$v;}
        }
        $status=strtoupper(trim((string)($filters['status']??'')));
        if($status!==''){ $where[]='e.status=?'; $params[]=$status; }
        $sql='SELECT e.*,d.name department_name,p.name position_name,b.name branch_name,b.code branch_code,et.name employment_type_name,u.email user_email
              FROM employees e
              LEFT JOIN departments d ON d.id=e.department_id
              LEFT JOIN positions p ON p.id=e.position_id
              LEFT JOIN branches b ON b.id=e.branch_id
              LEFT JOIN employment_types et ON et.id=e.employment_type_id
              LEFT JOIN users u ON u.id=e.user_id
              WHERE '.implode(' AND ',$where).' ORDER BY e.status IN ("ACTIVE","PROBATIONARY") DESC,e.last_name,e.first_name LIMIT 500';
        $st=db()->prepare($sql); $st->execute($params); return $st->fetchAll();
    }

    public static function find(int $id): ?array
    {
        if(!self::ready()) return null;
        $manager=self::columnExists('employees','manager_employee_id');
        $managerSelect=$manager ? ',TRIM(CONCAT_WS(" ",m.first_name,m.middle_name,m.last_name,m.suffix)) manager_name,mu.email manager_email' : ',NULL manager_name,NULL manager_email';
        $managerJoin=$manager ? ' LEFT JOIN employees m ON m.id=e.manager_employee_id LEFT JOIN users mu ON mu.id=m.user_id ' : ' ';
        $sql='SELECT e.*,d.name department_name,p.name position_name,b.name branch_name,b.code branch_code,et.name employment_type_name,u.email user_email,u.status user_status'.$managerSelect.'
            FROM employees e
            LEFT JOIN departments d ON d.id=e.department_id
            LEFT JOIN positions p ON p.id=e.position_id
            LEFT JOIN branches b ON b.id=e.branch_id
            LEFT JOIN employment_types et ON et.id=e.employment_type_id
            LEFT JOIN users u ON u.id=e.user_id'.$managerJoin.' WHERE e.id=?';
        $st=db()->prepare($sql);$st->execute([$id]); $row=$st->fetch(); return $row ?: null;
    }

    public static function findByUserId(int $userId): ?array
    {
        if(!self::ready()) return null;
        $st=db()->prepare('SELECT * FROM employees WHERE user_id=? LIMIT 1');
        $st->execute([$userId]); $row=$st->fetch(); return $row ?: null;
    }

    public static function masters(?int $employeeId=null): array
    {
        return [
            'departments'=>FoundationRepository::departments(),
            'positions'=>FoundationRepository::positions(),
            'branches'=>FoundationRepository::branches(),
            'employment_types'=>FoundationRepository::employmentTypes(),
            'employee_users'=>self::availableEmployeeUsers($employeeId),
            'manager_candidates'=>self::managerCandidates($employeeId),
        ];
    }


    public static function managerCandidates(?int $employeeId=null): array
    {
        if(!self::ready()) return [];
        $sql="SELECT e.id,e.employee_no,e.first_name,e.middle_name,e.last_name,p.name position_name,
                     u.email user_email,u.status user_status,r.code role_code,r.name role_name,
                     MAX(CASE WHEN r.code='SUPER_ADMIN' OR pm.code='payroll.approve_manager' THEN 1 ELSE 0 END) manager_ready
              FROM employees e
              LEFT JOIN positions p ON p.id=e.position_id
              LEFT JOIN users u ON u.id=e.user_id
              LEFT JOIN roles r ON r.id=u.role_id
              LEFT JOIN role_permissions rp ON rp.role_id=r.id
              LEFT JOIN permissions pm ON pm.id=rp.permission_id
              WHERE e.status IN ('ACTIVE','PROBATIONARY','ON_LEAVE')";
        $params=[];
        if($employeeId){$sql.=' AND e.id<>?';$params[]=$employeeId;}
        $sql.=' GROUP BY e.id,e.employee_no,e.first_name,e.middle_name,e.last_name,p.name,u.email,u.status,r.code,r.name ORDER BY manager_ready DESC,e.last_name,e.first_name';
        $st=db()->prepare($sql);$st->execute($params);return $st->fetchAll();
    }

    private static function validateImmediateManager(?int $managerEmployeeId,int $employeeId): void
    {
        if(!$managerEmployeeId)return;
        if($managerEmployeeId===$employeeId)throw new RuntimeException('An employee cannot be their own Immediate Manager.');
        $sql="SELECT e.id,e.status,u.id user_id,u.status user_status,r.code role_code,
                    MAX(CASE WHEN r.code='SUPER_ADMIN' OR p.code='payroll.approve_manager' THEN 1 ELSE 0 END) manager_ready
              FROM employees e
              LEFT JOIN users u ON u.id=e.user_id
              LEFT JOIN roles r ON r.id=u.role_id
              LEFT JOIN role_permissions rp ON rp.role_id=r.id
              LEFT JOIN permissions p ON p.id=rp.permission_id
              WHERE e.id=?
              GROUP BY e.id,e.status,u.id,u.status,r.code
              LIMIT 1";
        $st=db()->prepare($sql);$st->execute([$managerEmployeeId]);$row=$st->fetch();
        if(!$row)throw new RuntimeException('Selected Immediate Manager was not found.');
        if(!in_array((string)$row['status'],['ACTIVE','PROBATIONARY','ON_LEAVE'],true))throw new RuntimeException('Selected Immediate Manager is not an active employee.');
        if(empty($row['user_id'])||(string)$row['user_status']!=='ACTIVE')throw new RuntimeException('Selected Immediate Manager must have an active linked HRIS account.');
        if((int)$row['manager_ready']!==1)throw new RuntimeException('Selected Immediate Manager does not have Manager / Immediate Head approval access. Ask an administrator to update the account role or permissions first.');
    }

    public static function availableEmployeeUsers(?int $employeeId=null): array
    {
        if(!self::ready()) return [];
        $sql="SELECT u.id,u.full_name,u.email,e.id linked_employee_id FROM users u JOIN roles r ON r.id=u.role_id
            LEFT JOIN employees e ON e.user_id=u.id
            WHERE r.portal='employee' AND u.status='ACTIVE' AND (e.id IS NULL";
        $params=[];
        if($employeeId){$sql.=' OR e.id=?';$params[]=$employeeId;}
        $sql.=') ORDER BY u.full_name';
        $st=db()->prepare($sql);$st->execute($params);return $st->fetchAll();
    }

    public static function create(array $data): int
    {
        if(!self::ready()) throw new RuntimeException('Run the Phase 2A employee migration first.');
        [$employeeNo,$first,$middle,$last,$suffix,$companyEmail,$personalEmail,$status,$userId,$departmentId,$positionId,$branchId,$employmentTypeId,$hireDate,$regularizationDate]=self::validatedEmployeeData($data,null);
        $createdBy=(int)(Auth::user()['id']??0) ?: null;
        $sql='INSERT INTO employees(employee_no,user_id,first_name,middle_name,last_name,suffix,preferred_name,company_email,personal_email,mobile_no,birth_date,gender,civil_status,address_text,department_id,position_id,branch_id,employment_type_id,hire_date,regularization_date,status,created_by,updated_by)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $params=[
            $employeeNo,$userId,$first,$middle?:null,$last,$suffix?:null,trim((string)($data['preferred_name']??''))?:null,
            $companyEmail?:null,$personalEmail?:null,trim((string)($data['mobile_no']??''))?:null,self::dateOrNull((string)($data['birth_date']??'')),
            trim((string)($data['gender']??''))?:null,trim((string)($data['civil_status']??''))?:null,trim((string)($data['address_text']??''))?:null,
            $departmentId,$positionId,$branchId,$employmentTypeId,$hireDate,$regularizationDate,$status,$createdBy,$createdBy
        ];
        try { $st=db()->prepare($sql); $st->execute($params); }
        catch(PDOException $e){ self::throwDuplicateEmployee($e); throw $e; }
        $id=(int)db()->lastInsertId();
        audit('Employees','CREATE_EMPLOYEE','employee',$id,['employee_no'=>$employeeNo,'name'=>trim($first.' '.$last),'status'=>$status]);
        if(self::phase2Ready()) self::addHistory($id,'HIRED',$hireDate,null,[
            'department_id'=>$departmentId,'position_id'=>$positionId,'branch_id'=>$branchId,'employment_type_id'=>$employmentTypeId,'status'=>$status
        ],'Employee master record created.');
        return $id;
    }

    public static function updatePersonal(int $id,array $data): void
    {
        self::requirePhase2Employee($id);
        $old=self::find($id); if(!$old) throw new RuntimeException('Employee record not found.');
        $first=trim((string)($data['first_name']??''));$last=trim((string)($data['last_name']??''));
        if($first===''||$last==='') throw new RuntimeException('First name and last name are required.');
        $companyEmail=strtolower(trim((string)($data['company_email']??'')));
        $personalEmail=strtolower(trim((string)($data['personal_email']??'')));
        foreach([$companyEmail,$personalEmail] as $email) if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
        if($companyEmail!==''){
            $st=db()->prepare('SELECT COUNT(*) FROM employees WHERE LOWER(company_email)=LOWER(?) AND id<>?');$st->execute([$companyEmail,$id]);
            if((int)$st->fetchColumn()>0) throw new RuntimeException('Company email is already used by another employee.');
        }
        $sql='UPDATE employees SET first_name=?,middle_name=?,last_name=?,suffix=?,preferred_name=?,birth_date=?,place_of_birth=?,gender=?,civil_status=?,nationality=?,mobile_no=?,personal_email=?,company_email=?,address_text=?,permanent_address_text=?,updated_by=? WHERE id=?';
        $params=[
            $first,trim((string)($data['middle_name']??''))?:null,$last,trim((string)($data['suffix']??''))?:null,trim((string)($data['preferred_name']??''))?:null,
            self::dateOrNull((string)($data['birth_date']??'')),trim((string)($data['place_of_birth']??''))?:null,trim((string)($data['gender']??''))?:null,
            trim((string)($data['civil_status']??''))?:null,trim((string)($data['nationality']??''))?:null,trim((string)($data['mobile_no']??''))?:null,
            $personalEmail?:null,$companyEmail?:null,trim((string)($data['address_text']??''))?:null,trim((string)($data['permanent_address_text']??''))?:null,
            (int)(Auth::user()['id']??0) ?: null,$id
        ];
        try{$st=db()->prepare($sql);$st->execute($params);}catch(PDOException $e){self::throwDuplicateEmployee($e);throw $e;}
        audit('Employees','UPDATE_PERSONAL','employee',$id,['name_before'=>self::fullName($old),'name_after'=>trim($first.' '.$last)]);
    }

    public static function updateEmployment(int $id,array $data): void
    {
        self::requirePhase2Employee($id);
        $old=self::find($id); if(!$old) throw new RuntimeException('Employee record not found.');
        [$employeeNo,$first,$middle,$last,$suffix,$companyEmail,$personalEmail,$status,$userId,$departmentId,$positionId,$branchId,$employmentTypeId,$hireDate,$regularizationDate]=self::validatedEmployeeData(array_merge($old,$data),$id);
        $effectiveDate=self::dateOrNull((string)($data['effective_date']??'')) ?: date('Y-m-d');
        $remarks=trim((string)($data['remarks']??''));
        $before=[
            'department_id'=>$old['department_id']!==null?(int)$old['department_id']:null,'position_id'=>$old['position_id']!==null?(int)$old['position_id']:null,
            'branch_id'=>$old['branch_id']!==null?(int)$old['branch_id']:null,'employment_type_id'=>$old['employment_type_id']!==null?(int)$old['employment_type_id']:null,'manager_employee_id'=>isset($old['manager_employee_id'])&&$old['manager_employee_id']!==null?(int)$old['manager_employee_id']:null,'status'=>(string)$old['status']
        ];
        $managerEmployeeId=self::columnExists('employees','manager_employee_id') ? ((int)($data['manager_employee_id']??($old['manager_employee_id']??0))?:null) : null;
        self::validateImmediateManager($managerEmployeeId,$id);
        $after=['department_id'=>$departmentId,'position_id'=>$positionId,'branch_id'=>$branchId,'employment_type_id'=>$employmentTypeId,'manager_employee_id'=>$managerEmployeeId,'status'=>$status];
        $changed=[];foreach($before as $k=>$v) if($v!==$after[$k])$changed[]=$k;
        db()->beginTransaction();
        try{
            $hasManager=self::columnExists('employees','manager_employee_id');
            $sql=$hasManager?'UPDATE employees SET employee_no=?,user_id=?,department_id=?,position_id=?,branch_id=?,employment_type_id=?,manager_employee_id=?,hire_date=?,regularization_date=?,status=?,updated_by=? WHERE id=?':'UPDATE employees SET employee_no=?,user_id=?,department_id=?,position_id=?,branch_id=?,employment_type_id=?,hire_date=?,regularization_date=?,status=?,updated_by=? WHERE id=?';
            $params=$hasManager?[$employeeNo,$userId,$departmentId,$positionId,$branchId,$employmentTypeId,$managerEmployeeId,$hireDate,$regularizationDate,$status,(int)(Auth::user()['id']??0)?:null,$id]:[$employeeNo,$userId,$departmentId,$positionId,$branchId,$employmentTypeId,$hireDate,$regularizationDate,$status,(int)(Auth::user()['id']??0)?:null,$id];
            $st=db()->prepare($sql);$st->execute($params);
            if($changed && self::phase2Ready()){
                $event=self::historyEventType($changed,(string)$old['status'],$status);
                self::addHistory($id,$event,$effectiveDate,$before,$after,$remarks?:'Employment information updated.');
            }
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if($e instanceof PDOException)self::throwDuplicateEmployee($e);throw $e;}
        audit('Employees','UPDATE_EMPLOYMENT','employee',$id,['changed'=>$changed,'effective_date'=>$effectiveDate,'remarks'=>$remarks]);
    }

    public static function uploadProfilePhoto(int $id,array $file): void
    {
        $e=self::find($id);if(!$e)throw new RuntimeException('Employee record not found.');
        if(!self::phase2Ready())throw new RuntimeException('Run the Phase 2B migration first.');
        if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)throw new RuntimeException('Choose a profile photo first.');
        if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Profile photo upload failed.');
        if((int)($file['size']??0)>3*1024*1024)throw new RuntimeException('Profile photo must be 3 MB or smaller.');
        $mime=self::detectedMime((string)$file['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
        if(!$ext)throw new RuntimeException('Profile photo must be JPG, PNG, or WEBP.');
        $dir=self::storageDir('employee_photos');$stored='employee_'.$id.'_'.bin2hex(random_bytes(12)).'.'.$ext;
        if(!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$stored))throw new RuntimeException('Could not save the profile photo.');
        $oldStored=(string)($e['profile_photo_stored_name']??'');
        $st=db()->prepare('UPDATE employees SET profile_photo_original_name=?,profile_photo_stored_name=?,profile_photo_mime_type=?,profile_photo_updated_at=NOW(),updated_by=? WHERE id=?');
        $st->execute([mb_substr((string)($file['name']??'profile.'.$ext),0,255),$stored,$mime,(int)(Auth::user()['id']??0)?:null,$id]);
        if($oldStored!==''&&is_file($dir.'/'.$oldStored))@unlink($dir.'/'.$oldStored);
        audit('Employees','UPDATE_PROFILE_PHOTO','employee',$id,['file'=>$stored]);
    }

    public static function profilePhoto(int $id): ?array
    {
        $st=db()->prepare('SELECT profile_photo_original_name original_name,profile_photo_stored_name stored_name,profile_photo_mime_type mime_type FROM employees WHERE id=?');
        $st->execute([$id]);$r=$st->fetch();return ($r&&!empty($r['stored_name']))?$r:null;
    }

    public static function governmentIds(int $employeeId): array
    {
        if(!FoundationRepository::tableExists('employee_government_ids'))return [];
        $st=db()->prepare('SELECT * FROM employee_government_ids WHERE employee_id=? ORDER BY FIELD(id_type,"SSS","PHILHEALTH","PAGIBIG","TIN","UMID","PASSPORT","DRIVERS_LICENSE","PRC","OTHER"),id');$st->execute([$employeeId]);return $st->fetchAll();
    }

    public static function saveGovernmentId(int $employeeId,array $data): void
    {
        self::requirePhase2Employee($employeeId);
        $type=strtoupper(trim((string)($data['id_type']??'')));if(!in_array($type,self::GOV_ID_TYPES,true))throw new RuntimeException('Invalid government ID type.');
        $number=trim((string)($data['id_number']??''));if($number==='')throw new RuntimeException('ID number is required.');
        $st=db()->prepare('INSERT INTO employee_government_ids(employee_id,id_type,id_number,issued_date,expiry_date,notes,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id_number=VALUES(id_number),issued_date=VALUES(issued_date),expiry_date=VALUES(expiry_date),notes=VALUES(notes),updated_by=VALUES(updated_by),updated_at=NOW()');
        $uid=(int)(Auth::user()['id']??0)?:null;$st->execute([$employeeId,$type,$number,self::dateOrNull((string)($data['issued_date']??'')),self::dateOrNull((string)($data['expiry_date']??'')),trim((string)($data['notes']??''))?:null,$uid,$uid]);
        audit('Employees','SAVE_GOVERNMENT_ID','employee',$employeeId,['id_type'=>$type]);
    }

    public static function deleteGovernmentId(int $employeeId,int $id): void
    {
        self::requirePhase2Employee($employeeId);$st=db()->prepare('DELETE FROM employee_government_ids WHERE id=? AND employee_id=?');$st->execute([$id,$employeeId]);audit('Employees','DELETE_GOVERNMENT_ID','employee',$employeeId,['government_id_record'=>$id]);
    }

    public static function emergencyContacts(int $employeeId): array
    {
        if(!FoundationRepository::tableExists('employee_emergency_contacts'))return [];
        $st=db()->prepare('SELECT * FROM employee_emergency_contacts WHERE employee_id=? ORDER BY is_primary DESC,name');$st->execute([$employeeId]);return $st->fetchAll();
    }

    public static function saveEmergencyContact(int $employeeId,array $data): void
    {
        self::requirePhase2Employee($employeeId);$id=(int)($data['contact_id']??0);$name=trim((string)($data['name']??''));$relationship=trim((string)($data['relationship']??''));$mobile=trim((string)($data['mobile_no']??''));
        if($name===''||$relationship===''||$mobile==='')throw new RuntimeException('Name, relationship, and mobile number are required.');
        $email=strtolower(trim((string)($data['email']??'')));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid emergency contact email.');
        $primary=!empty($data['is_primary'])?1:0;$uid=(int)(Auth::user()['id']??0)?:null;
        db()->beginTransaction();try{
            if($primary){$st=db()->prepare('UPDATE employee_emergency_contacts SET is_primary=0,updated_by=? WHERE employee_id=?');$st->execute([$uid,$employeeId]);}
            if($id>0){$st=db()->prepare('UPDATE employee_emergency_contacts SET name=?,relationship=?,mobile_no=?,email=?,address_text=?,is_primary=?,updated_by=? WHERE id=? AND employee_id=?');$st->execute([$name,$relationship,$mobile,$email?:null,trim((string)($data['address_text']??''))?:null,$primary,$uid,$id,$employeeId]);}
            else{$st=db()->prepare('INSERT INTO employee_emergency_contacts(employee_id,name,relationship,mobile_no,email,address_text,is_primary,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?)');$st->execute([$employeeId,$name,$relationship,$mobile,$email?:null,trim((string)($data['address_text']??''))?:null,$primary,$uid,$uid]);$id=(int)db()->lastInsertId();}
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        audit('Employees','SAVE_EMERGENCY_CONTACT','employee',$employeeId,['contact_id'=>$id,'primary'=>$primary]);
    }

    public static function deleteEmergencyContact(int $employeeId,int $id): void
    {
        self::requirePhase2Employee($employeeId);$st=db()->prepare('DELETE FROM employee_emergency_contacts WHERE id=? AND employee_id=?');$st->execute([$id,$employeeId]);audit('Employees','DELETE_EMERGENCY_CONTACT','employee',$employeeId,['contact_id'=>$id]);
    }

    public static function documents(int $employeeId): array
    {
        if(!FoundationRepository::tableExists('employee_documents'))return [];
        $st=db()->prepare('SELECT d.*,u.full_name uploaded_by_name FROM employee_documents d LEFT JOIN users u ON u.id=d.uploaded_by WHERE d.employee_id=? ORDER BY d.uploaded_at DESC,d.id DESC');$st->execute([$employeeId]);return $st->fetchAll();
    }

    public static function uploadDocument(int $employeeId,array $file,array $data): int
    {
        self::requirePhase2Employee($employeeId);
        if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)throw new RuntimeException('Choose a document to upload.');
        if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Document upload failed.');
        if((int)($file['size']??0)>10*1024*1024)throw new RuntimeException('Document must be 10 MB or smaller.');
        $type=strtoupper(trim((string)($data['document_type']??'OTHER')));if(!in_array($type,self::DOC_TYPES,true))$type='OTHER';
        $original=mb_substr(basename((string)($file['name']??'document')),0,255);$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        $allowedExt=['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];if(!in_array($ext,$allowedExt,true))throw new RuntimeException('Allowed files: PDF, JPG, PNG, WEBP, DOC/DOCX, XLS/XLSX.');
        $mime=self::detectedMime((string)$file['tmp_name']);
        $allowedMime=['application/pdf','image/jpeg','image/png','image/webp','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip','application/octet-stream'];
        if(!in_array($mime,$allowedMime,true))throw new RuntimeException('Unsupported document file type.');
        $stored='emp_'.$employeeId.'_'.bin2hex(random_bytes(16)).'.'.$ext;$dir=self::storageDir('employee_documents');
        if(!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$stored))throw new RuntimeException('Could not save the uploaded document.');
        $title=trim((string)($data['title']??''))?:pathinfo($original,PATHINFO_FILENAME);$uid=(int)(Auth::user()['id']??0)?:null;
        try{$st=db()->prepare('INSERT INTO employee_documents(employee_id,document_type,title,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?,?,?)');$st->execute([$employeeId,$type,mb_substr($title,0,180),$original,$stored,$mime,(int)$file['size'],$uid]);}
        catch(Throwable $e){@unlink($dir.'/'.$stored);throw $e;}
        $id=(int)db()->lastInsertId();audit('Employees','UPLOAD_DOCUMENT','employee',$employeeId,['document_id'=>$id,'type'=>$type,'name'=>$original]);return $id;
    }

    public static function document(int $employeeId,int $documentId): ?array
    {
        if(!FoundationRepository::tableExists('employee_documents'))return null;$st=db()->prepare('SELECT * FROM employee_documents WHERE id=? AND employee_id=?');$st->execute([$documentId,$employeeId]);$r=$st->fetch();return $r?:null;
    }

    public static function deleteDocument(int $employeeId,int $documentId): void
    {
        $d=self::document($employeeId,$documentId);if(!$d)throw new RuntimeException('Document not found.');$st=db()->prepare('DELETE FROM employee_documents WHERE id=? AND employee_id=?');$st->execute([$documentId,$employeeId]);$path=self::storageDir('employee_documents').'/'.$d['stored_name'];if(is_file($path))@unlink($path);audit('Employees','DELETE_DOCUMENT','employee',$employeeId,['document_id'=>$documentId,'name'=>$d['original_name']]);
    }

    public static function history(int $employeeId): array
    {
        if(!FoundationRepository::tableExists('employee_employment_history'))return [];
        $sql='SELECT h.*,u.full_name created_by_name,fd.name from_department_name,td.name to_department_name,fp.name from_position_name,tp.name to_position_name,fb.name from_branch_name,tb.name to_branch_name,fet.name from_employment_type_name,tet.name to_employment_type_name
              FROM employee_employment_history h
              LEFT JOIN users u ON u.id=h.created_by
              LEFT JOIN departments fd ON fd.id=h.from_department_id LEFT JOIN departments td ON td.id=h.to_department_id
              LEFT JOIN positions fp ON fp.id=h.from_position_id LEFT JOIN positions tp ON tp.id=h.to_position_id
              LEFT JOIN branches fb ON fb.id=h.from_branch_id LEFT JOIN branches tb ON tb.id=h.to_branch_id
              LEFT JOIN employment_types fet ON fet.id=h.from_employment_type_id LEFT JOIN employment_types tet ON tet.id=h.to_employment_type_id
              WHERE h.employee_id=? ORDER BY h.effective_date DESC,h.id DESC';
        $st=db()->prepare($sql);$st->execute([$employeeId]);return $st->fetchAll();
    }

    public static function auditTrail(int $employeeId,int $limit=100): array
    {
        $limit=max(1,min(200,$limit));$st=db()->prepare('SELECT a.*,u.full_name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.record_type="employee" AND a.record_id=? ORDER BY a.created_at DESC LIMIT '.$limit);$st->execute([$employeeId]);return $st->fetchAll();
    }

    public static function fullName(array $e): string
    {
        return trim(implode(' ',array_filter([(string)($e['first_name']??''),(string)($e['middle_name']??''),(string)($e['last_name']??''),(string)($e['suffix']??'')])));
    }

    public static function govIdTypes(): array { return self::GOV_ID_TYPES; }
    public static function documentTypes(): array { return self::DOC_TYPES; }

    private static function columnExists(string $table,string $column): bool
    {
        $st=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute([$table,$column]);return (int)$st->fetchColumn()>0;
    }

    private static function validatedEmployeeData(array $data,?int $employeeId): array
    {
        $first=trim((string)($data['first_name']??''));$middle=trim((string)($data['middle_name']??''));$last=trim((string)($data['last_name']??''));$suffix=trim((string)($data['suffix']??''));
        if($first===''||$last==='')throw new RuntimeException('First name and last name are required.');
        $employeeNo=strtoupper(trim((string)($data['employee_no']??''))) ?: self::nextEmployeeNo();
        $hireDate=self::dateOrNull((string)($data['hire_date']??''));if(!$hireDate)throw new RuntimeException('Enter a valid hire date.');
        $regularizationDate=self::dateOrNull((string)($data['regularization_date']??''));
        $companyEmail=strtolower(trim((string)($data['company_email']??'')));$personalEmail=strtolower(trim((string)($data['personal_email']??'')));
        foreach([$companyEmail,$personalEmail] as $email)if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
        $status=strtoupper(trim((string)($data['status']??'ACTIVE')));if(!in_array($status,self::STATUSES,true))throw new RuntimeException('Invalid employee status.');
        $userId=(int)($data['user_id']??0)?:null;$departmentId=(int)($data['department_id']??0)?:null;$positionId=(int)($data['position_id']??0)?:null;$branchId=(int)($data['branch_id']??0)?:null;$employmentTypeId=(int)($data['employment_type_id']??0)?:null;
        if($employeeId){$st=db()->prepare('SELECT COUNT(*) FROM employees WHERE employee_no=? AND id<>?');$st->execute([$employeeNo,$employeeId]);if((int)$st->fetchColumn()>0)throw new RuntimeException('Employee number already exists.');}
        if($userId){$sql='SELECT COUNT(*) FROM employees WHERE user_id=?'.($employeeId?' AND id<>?':'');$st=db()->prepare($sql);$st->execute($employeeId?[$userId,$employeeId]:[$userId]);if((int)$st->fetchColumn()>0)throw new RuntimeException('That user account is already linked to an employee.');}
        if($positionId&&$departmentId){$st=db()->prepare('SELECT department_id FROM positions WHERE id=?');$st->execute([$positionId]);$pd=$st->fetchColumn();if($pd!==false&&$pd!==null&&(int)$pd!==$departmentId)throw new RuntimeException('Selected position does not belong to the selected department.');}
        return [$employeeNo,$first,$middle,$last,$suffix,$companyEmail,$personalEmail,$status,$userId,$departmentId,$positionId,$branchId,$employmentTypeId,$hireDate,$regularizationDate];
    }

    private static function addHistory(int $employeeId,string $eventType,string $effectiveDate,?array $from,array $to,string $remarks): void
    {
        if(!FoundationRepository::tableExists('employee_employment_history'))return;$uid=(int)(Auth::user()['id']??0)?:null;
        $st=db()->prepare('INSERT INTO employee_employment_history(employee_id,event_type,effective_date,from_department_id,to_department_id,from_position_id,to_position_id,from_branch_id,to_branch_id,from_employment_type_id,to_employment_type_id,from_status,to_status,remarks,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$employeeId,$eventType,$effectiveDate,$from['department_id']??null,$to['department_id']??null,$from['position_id']??null,$to['position_id']??null,$from['branch_id']??null,$to['branch_id']??null,$from['employment_type_id']??null,$to['employment_type_id']??null,$from['status']??null,$to['status']??null,$remarks?:null,$uid]);
    }

    private static function historyEventType(array $changed,string $oldStatus,string $newStatus): string
    {
        if(count($changed)>1)return 'EMPLOYMENT_UPDATE';$k=$changed[0]??'';
        if($k==='branch_id')return 'BRANCH_TRANSFER';if($k==='department_id')return 'DEPARTMENT_CHANGE';if($k==='position_id')return 'POSITION_CHANGE';if($k==='employment_type_id')return 'EMPLOYMENT_TYPE_CHANGE';
        if($k==='status'&&$oldStatus==='PROBATIONARY'&&$newStatus==='ACTIVE')return 'REGULARIZATION';if($k==='status')return 'STATUS_CHANGE';return 'EMPLOYMENT_UPDATE';
    }

    private static function requirePhase2Employee(int $employeeId): array
    {
        if(!self::phase2Ready())throw new RuntimeException('Run the Phase 2B employee 201 File migration first.');$e=self::find($employeeId);if(!$e)throw new RuntimeException('Employee record not found.');return $e;
    }

    private static function dateOrNull(string $date): ?string
    {
        $date=trim($date);if($date==='')return null;if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date))throw new RuntimeException('Enter a valid date.');$d=DateTime::createFromFormat('Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new RuntimeException('Enter a valid date.');return $date;
    }

    private static function storageDir(string $name): string
    {
        $dir=dirname(__DIR__).'/storage/'.$name;if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create secure employee storage folder.');return $dir;
    }

    private static function detectedMime(string $path): string
    {
        $f=new finfo(FILEINFO_MIME_TYPE);return (string)($f->file($path)?:'application/octet-stream');
    }

    private static function throwDuplicateEmployee(PDOException $e): void
    {
        if($e->getCode()==='23000')throw new RuntimeException('Employee number, company email, or linked user already exists.');
    }
}
