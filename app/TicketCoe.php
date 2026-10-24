<?php
declare(strict_types=1);

/**
 * Certificate of Employment request: filing fields, the HR-managed .docx template, the filled document and its access rules.
 * The filled file is private like an Incident Report: only the requester and HR can see or download it, and only through
 * hr-coe-download, which re-runs the ticket access check. No PDF is produced; HR prints the .docx.
 */
final class TicketCoe
{
    public const TYPE='COE_REQUEST';
    public const PURPOSES=['Loan','Visa/Embassy','Bank','New employer','Other'];
    public const REQUIRED=['employee_name','position','hire_date','company','purpose','date_issued'];
    public const OPTIONAL=['salary','signatory_name','signatory_title'];
    public const SETTINGS=['signatory_name'=>'Signatory name','signatory_title'=>'Signatory title','company_display_name'=>'Company name on the certificate (leave blank to use the employee\'s legal entity)'];
    public const MAX_TEMPLATE_BYTES=2097152;
    public const MAX_UNPACKED_BYTES=20971520;
    public const MAX_ENTRIES=300;
    private const W='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const MAIN_TYPE='application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

    public static function ready(): bool { return FoundationRepository::tableExists('coe_documents'); }
    private static function dir(string $name): string
    {
        $dir=dirname(__DIR__).'/storage/'.$name;
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Certificate storage is unavailable.');
        return $dir;
    }
    public static function canManageTemplate(): bool
    {
        return TicketRepository::isHr() && Auth::can('ticket.coe.template.manage');
    }
    private static function requireManager(): void
    {
        if(!self::canManageTemplate())throw new RuntimeException('Only HR with COE template access can manage the template.');
    }

    // ------------------------------------------------------------------ filing

    public static function validate(array $out): array
    {
        if($out['purpose']==='Other'){
            if(($out['purpose_other']??null)===null||trim((string)$out['purpose_other'])==='')throw new RuntimeException('Describe the purpose when you choose Other.');
        }else $out['purpose_other']=null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$out['needed_by']);
        if(!$d||$d->format('Y-m-d')!==$out['needed_by'])throw new RuntimeException('Enter a valid needed-by date.');
        $today=new DateTimeImmutable('today');
        if($d<$today)throw new RuntimeException('The needed-by date cannot be in the past.');
        if($d>$today->modify('+1 year'))throw new RuntimeException('The needed-by date must be within the next year.');
        return $out;
    }
    public static function save(int $ticketId,array $p): void
    {
        db()->prepare('INSERT INTO coe_requests(ticket_id,purpose,purpose_other,needed_by,delivery) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE purpose=VALUES(purpose),purpose_other=VALUES(purpose_other),needed_by=VALUES(needed_by),delivery=VALUES(delivery)')
            ->execute([$ticketId,$p['purpose'],$p['purpose_other']??null,$p['needed_by'],$p['delivery']]);
    }

    // ------------------------------------------------------------------ settings

    public static function settings(): array
    {
        $out=array_fill_keys(array_keys(self::SETTINGS),'');
        if(!self::ready())return $out;
        foreach(db()->query('SELECT setting_key,setting_value FROM coe_settings') as $r)if(isset($out[$r['setting_key']]))$out[$r['setting_key']]=$r['setting_value'];
        return $out;
    }
    public static function saveSettings(array $in): void
    {
        self::requireManager();$old=self::settings();$changed=[];
        foreach(self::SETTINGS as $key=>$label){
            $v=$in[$key]??'';if(!is_string($v))throw new RuntimeException($label.' is invalid.');
            $v=self::clean($v);if(mb_strlen($v)>150)throw new RuntimeException($label.' must be 150 characters or fewer.');
            if($v===$old[$key])continue;
            db()->prepare('INSERT INTO coe_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)')->execute([$key,$v,TicketRepository::uid()]);
            $changed[]=$key;
        }
        if($changed)audit('Tickets','COE_SETTINGS_CHANGED','coe_settings',null,['keys'=>$changed]);
    }

    // ------------------------------------------------------------------ values

    /** Strip characters XML cannot hold, flatten line breaks, cap the length. Escaping itself is done by the DOM on write. */
    public static function clean(string $v): string
    {
        $v=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$v)??'';
        $v=trim(preg_replace('/\s+/u',' ',$v)??'');
        return mb_substr($v,0,300);
    }
    private static function ticketRow(int $ticketId): array
    {
        $q=db()->prepare('SELECT c.* FROM coe_requests c WHERE c.ticket_id=?');$q->execute([$ticketId]);
        return $q->fetch()?:throw new RuntimeException('COE details are missing for this request.');
    }
    private static function salaryText(int $employeeId,string $date): ?string
    {
        $q=db()->prepare('SELECT salary_basis,salary_amount FROM payroll_employee_rates WHERE employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC,id DESC LIMIT 1');
        $q->execute([$employeeId,$date,$date]);$r=$q->fetch();if(!$r)return null;
        $per=['MONTHLY'=>'month','DAILY'=>'day','HOURLY'=>'hour'][$r['salary_basis']]??null;if($per===null)return null;
        return 'PHP '.number_format((float)$r['salary_amount'],2).' per '.$per;
    }
    /**
     * Placeholder => text, straight from the employee record (and the HR config for the signatory). Missing data stays blank
     * and is reported by problems(); nothing is invented.
     */
    public static function values(array $t,bool $includeSalary,?string $served=null): array
    {
        $served??=date('Y-m-d');$request=self::ticketRow((int)$t['id']);
        $q=db()->prepare('SELECT e.*,p.name position_name,le.name legal_name FROM employees e LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN legal_entities le ON le.id=e.legal_entity_id WHERE e.id=?');
        $q->execute([(int)$t['subject_employee_id']]);$e=$q->fetch()?:throw new RuntimeException('Employee record not found.');
        $s=self::settings();$fmt=static fn(?string $d)=>$d?(new DateTimeImmutable($d))->format('F j, Y'):'';
        $purpose=$request['purpose']==='Other'?(string)$request['purpose_other']:$request['purpose'];
        $v=[
            'employee_name'=>EmployeeRepository::fullName($e),'position'=>(string)$e['position_name'],'hire_date'=>$fmt($e['hire_date']),
            'company'=>$s['company_display_name']!==''?$s['company_display_name']:(string)$e['legal_name'],
            'purpose'=>$purpose,'date_issued'=>$fmt($served),
            'salary'=>$includeSalary?(string)self::salaryText((int)$e['id'],$served):'',
            'signatory_name'=>$s['signatory_name'],'signatory_title'=>$s['signatory_title'],
        ];
        return array_map(fn($x)=>self::clean((string)$x),$v);
    }
    /** Reasons the certificate cannot be issued yet. */
    public static function problems(array $values,bool $includeSalary): array
    {
        $labels=['employee_name'=>'Employee name','position'=>'Position','hire_date'=>'Hire date','company'=>'Company','purpose'=>'Purpose','date_issued'=>'Date issued'];$out=[];
        foreach(self::REQUIRED as $k)if($values[$k]==='')$out[]=$labels[$k].' is missing. '.($k==='hire_date'||$k==='position'?'Complete it in Employee 201 first.':'');
        if($includeSalary&&$values['salary']==='')$out[]='Salary is included but no salary rate is on file for the issue date. Untick "Include salary" or add the rate.';
        return array_map('trim',$out);
    }

    // ------------------------------------------------------------------ template store

    public static function activeTemplate(): ?array
    {
        if(!self::ready())return null;
        return db()->query('SELECT * FROM coe_templates WHERE is_active=1 ORDER BY version_no DESC LIMIT 1')->fetch()?:null;
    }
    public static function templates(): array
    {
        self::requireManager();
        return db()->query('SELECT t.*,u.full_name uploader FROM coe_templates t JOIN users u ON u.id=t.uploaded_by ORDER BY t.version_no DESC')->fetchAll();
    }
    private static function templatePath(array $row): string
    {
        if(!preg_match('/^[a-f0-9]{48}\.docx$/',$row['stored_name']))throw new RuntimeException('Template file is invalid.');
        $path=self::dir('coe_templates').'/'.$row['stored_name'];
        if(!is_file($path)||!hash_equals($row['sha256'],hash_file('sha256',$path)))throw new RuntimeException('The stored template is missing or changed. Upload it again.');
        return $path;
    }
    private static function xmlParts(ZipArchive $zip): array
    {
        $parts=[];
        for($i=0;$i<$zip->numFiles;$i++){$n=(string)$zip->getNameIndex($i);if(preg_match('#^word/(document|header\d*|footer\d*)\.xml$#',$n))$parts[]=$n;}
        return $parts;
    }
    /**
     * Real-content check, not just the extension: size, ZIP signature, a sane archive, the Word main part, no macros, well-formed
     * XML without DOCTYPE/entities, only known placeholders, and every required placeholder present.
     * @return string[] placeholders found
     */
    public static function inspectDocx(string $path,string $originalName): array
    {
        if(strtolower(pathinfo($originalName,PATHINFO_EXTENSION))!=='docx')throw new RuntimeException('Upload a Word .docx file.');
        $size=is_file($path)?(int)filesize($path):0;
        if($size<1||$size>self::MAX_TEMPLATE_BYTES)throw new RuntimeException('The template must be between 1 byte and 2 MB.');
        $fh=fopen($path,'rb');$sig=$fh?fread($fh,4):'';if($fh)fclose($fh);
        if($sig!=="PK\x03\x04")throw new RuntimeException('This is not a real .docx file (its contents are not a Word document).');
        $zip=new ZipArchive();if($zip->open($path,ZipArchive::RDONLY)!==true)throw new RuntimeException('This is not a real .docx file (it cannot be opened as a Word document).');
        try{
            if($zip->numFiles<1||$zip->numFiles>self::MAX_ENTRIES)throw new RuntimeException('The .docx has an unexpected number of parts.');
            $total=0;$names=[];
            for($i=0;$i<$zip->numFiles;$i++){
                $st=$zip->statIndex($i);$n=(string)$st['name'];
                if($n===''||str_contains($n,'\\')||str_starts_with($n,'/')||preg_match('#(^|/)\.\.(/|$)#',$n))throw new RuntimeException('The .docx contains an unsafe file path.');
                if(preg_match('#vbaProject|activex#i',$n))throw new RuntimeException('Macros and embedded objects are not allowed in the template.');
                $total+=(int)$st['size'];if($total>self::MAX_UNPACKED_BYTES)throw new RuntimeException('The .docx is too large when unpacked.');
                $names[$n]=true;
            }
            if(!isset($names['[Content_Types].xml'])||!isset($names['word/document.xml']))throw new RuntimeException('This is not a Word document (word/document.xml is missing).');
            $types=(string)$zip->getFromName('[Content_Types].xml');
            if(!str_contains($types,self::MAIN_TYPE)||stripos($types,'macroEnabled')!==false)throw new RuntimeException('This is not a plain Word .docx (macro-enabled or another format).');
            $found=[];
            foreach(self::xmlParts($zip) as $name){
                $xml=(string)$zip->getFromName($name);
                $dom=self::load($xml);
                foreach(self::paragraphs($dom) as $p)foreach(self::matches($p['text']) as $m)$found[$m['name']]=true;
            }
            $found=array_keys($found);sort($found);
            $unknown=array_diff($found,array_merge(self::REQUIRED,self::OPTIONAL));
            if($unknown)throw new RuntimeException('Unknown placeholder(s): {{'.implode('}}, {{',$unknown).'}}. Use only the listed placeholders.');
            $missing=array_diff(self::REQUIRED,$found);
            if($missing)throw new RuntimeException('Missing required placeholder(s): {{'.implode('}}, {{',$missing).'}}.');
            return $found;
        }finally{$zip->close();}
    }
    public static function addTemplate(string $tmpPath,string $originalName,string $note=''): int
    {
        self::requireManager();
        $note=self::clean($note);if(mb_strlen($note)>200)throw new RuntimeException('The note must be 200 characters or fewer.');
        $found=self::inspectDocx($tmpPath,$originalName);
        $stored=bin2hex(random_bytes(24)).'.docx';$dest=self::dir('coe_templates').'/'.$stored;
        if(!copy($tmpPath,$dest))throw new RuntimeException('The template could not be saved.');
        try{
            db()->beginTransaction();
            $v=(int)db()->query('SELECT COALESCE(MAX(version_no),0)+1 FROM coe_templates FOR UPDATE')->fetchColumn();
            db()->exec('UPDATE coe_templates SET is_active=0');
            db()->prepare('INSERT INTO coe_templates(version_no,original_name,stored_name,sha256,file_size,placeholders_json,note,is_active,uploaded_by) VALUES(?,?,?,?,?,?,?,1,?)')
                ->execute([$v,mb_substr(basename($originalName),0,255),$stored,hash_file('sha256',$dest),filesize($dest),json_encode($found),$note!==''?$note:null,TicketRepository::uid()]);
            $id=(int)db()->lastInsertId();
            audit('Tickets','COE_TEMPLATE_UPLOADED','coe_template',$id,['version'=>$v,'placeholders'=>$found,'size'=>filesize($dest)]);
            db()->commit();return $id;
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if(is_file($dest))unlink($dest);throw $e;}
    }
    public static function activate(int $id): void
    {
        self::requireManager();
        db()->beginTransaction();
        try{
            $q=db()->prepare('SELECT * FROM coe_templates WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();
            if(!$row)throw new RuntimeException('Template not found.');
            self::templatePath($row);
            db()->exec('UPDATE coe_templates SET is_active=0');db()->prepare('UPDATE coe_templates SET is_active=1 WHERE id=?')->execute([$id]);
            audit('Tickets','COE_TEMPLATE_ACTIVATED','coe_template',$id,['version'=>(int)$row['version_no']]);
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    }
    public static function templateFile(int $id): array
    {
        self::requireManager();
        $q=db()->prepare('SELECT * FROM coe_templates WHERE id=?');$q->execute([$id]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('Template not found.');
        $path=self::templatePath($row);audit('Tickets','COE_TEMPLATE_DOWNLOADED','coe_template',$id,['version'=>(int)$row['version_no']]);
        return ['path'=>$path,'name'=>'COE-template-v'.(int)$row['version_no'].'.docx'];
    }

    // ------------------------------------------------------------------ docx xml

    private static function load(string $xml): DOMDocument
    {
        if(preg_match('/<!(DOCTYPE|ENTITY)/i',$xml))throw new RuntimeException('The .docx contains a disallowed XML declaration.');
        $dom=new DOMDocument();$dom->preserveWhiteSpace=true;
        $prev=libxml_use_internal_errors(true);
        $ok=$dom->loadXML($xml,LIBXML_NONET);
        libxml_clear_errors();libxml_use_internal_errors($prev);
        if(!$ok)throw new RuntimeException('The .docx contains damaged XML.');
        return $dom;
    }
    /** @return array<int,array{nodes:DOMElement[],text:string}> one entry per paragraph; text is the paragraph's w:t text joined. */
    private static function paragraphs(DOMDocument $dom): array
    {
        $x=new DOMXPath($dom);$x->registerNamespace('w',self::W);$out=[];
        foreach($x->query('//w:p') as $p){
            $nodes=[];$text='';
            foreach($x->query('.//w:t',$p) as $t){$nodes[]=$t;$text.=$t->textContent;}
            $out[]=['nodes'=>$nodes,'text'=>$text];
        }
        return $out;
    }
    /** @return array<int,array{start:int,end:int,name:string}> byte offsets in the joined paragraph text. */
    private static function matches(string $text): array
    {
        $out=[];
        if(preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/',$text,$m,PREG_OFFSET_CAPTURE|PREG_SET_ORDER))
            foreach($m as $hit)$out[]=['start'=>$hit[0][1],'end'=>$hit[0][1]+strlen($hit[0][0]),'name'=>$hit[1][0]];
        return $out;
    }
    /** Replace placeholders, including ones Word split over several runs. Values are written as DOM text, so they are escaped. */
    private static function fillXml(string $xml,array $map): string
    {
        $dom=self::load($xml);
        foreach(self::paragraphs($dom) as $para){
            $hits=self::matches($para['text']);if(!$hits)continue;
            $pos=0;
            foreach($para['nodes'] as $node){
                $text=$node->textContent;$s=$pos;$e=$pos+strlen($text);$pos=$e;
                $out='';$cursor=$s;$touched=false;
                foreach($hits as $h){
                    if($h['end']<=$s||$h['start']>=$e)continue;
                    $touched=true;
                    if($h['start']>=$s){$out.=substr($text,$cursor-$s,$h['start']-$cursor);$out.=$map[$h['name']]??'';}
                    $cursor=min($h['end'],$e);
                }
                if(!$touched)continue;
                $out.=substr($text,$cursor-$s);
                while($node->firstChild)$node->removeChild($node->firstChild);
                $node->appendChild($dom->createTextNode($out));
                $node->setAttributeNS('http://www.w3.org/XML/1998/namespace','xml:space','preserve');
            }
        }
        return $dom->saveXML()?:throw new RuntimeException('The certificate could not be written.');
    }
    public static function fillDocx(string $src,string $dest,array $map): void
    {
        if(!copy($src,$dest))throw new RuntimeException('The certificate could not be created.');
        $zip=new ZipArchive();if($zip->open($dest)!==true)throw new RuntimeException('The certificate could not be created.');
        try{
            foreach(self::xmlParts($zip) as $name){
                $zip->addFromString($name,self::fillXml((string)$zip->getFromName($name),$map));
            }
        }finally{if(!$zip->close())throw new RuntimeException('The certificate could not be written.');}
    }
    /** Built-in layout used until HR uploads a template. Uses the same placeholders, so it goes through the same fill path. */
    public static function defaultDocx(bool $includeSalary): string
    {
        $para=static fn(string $t,bool $bold=false,string $align='both',int $after=240)=>'<w:p><w:pPr><w:spacing w:after="'.$after.'"/><w:jc w:val="'.$align.'"/></w:pPr><w:r><w:rPr>'.($bold?'<w:b/>':'').'<w:sz w:val="24"/></w:rPr><w:t xml:space="preserve">'.$t.'</w:t></w:r></w:p>';
        $body=$para('CERTIFICATE OF EMPLOYMENT',true,'center',480).$para('{{date_issued}}',false,'left',360).$para('TO WHOM IT MAY CONCERN:',true,'left')
            .$para('This is to certify that {{employee_name}} has been employed with {{company}} as {{position}} since {{hire_date}}.')
            .($includeSalary?$para('The employee\'s current compensation is {{salary}}.'):'')
            .$para('This certification is issued upon the request of the employee for {{purpose}} purposes.',false,'both',720)
            .$para('{{signatory_name}}',true,'left',0).$para('{{signatory_title}}',false,'left');
        $doc='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="'.self::W.'"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>';
        $path=tempnam(sys_get_temp_dir(),'coe');$zip=new ZipArchive();$zip->open($path,ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="'.self::MAIN_TYPE.'"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml',$doc);$zip->close();return $path;
    }

    // ------------------------------------------------------------------ serve + download

    private static function previewToken(array $t,array $values,bool $includeSalary,?array $template): string
    {
        return hash('sha256',json_encode([(int)$t['id'],$values,$includeSalary,$template['id']??0],JSON_THROW_ON_ERROR));
    }
    /** HR preview of the exact values that go on the certificate. Required before Served; the token ties the Served click to these values. */
    public static function preview(int $id,bool $includeSalary): array
    {
        if(!TicketRepository::isHr())throw new RuntimeException('HR ticket review access is required.');
        $t=TicketRepository::find($id);
        if(!empty($t['viewer_only'])||$t['type_code']!==self::TYPE)throw new RuntimeException('Ticket not found or access denied.');
        if(!in_array($t['status'],['PENDING_HR','IN_PROGRESS'],true))throw new RuntimeException('Only a Pending or In Progress COE can be previewed.');
        if((int)$t['requester_user_id']===TicketRepository::uid()||(int)$t['subject_user_id']===TicketRepository::uid())throw new RuntimeException('Another HR reviewer must handle your request.');
        $values=self::values($t,$includeSalary);$template=self::activeTemplate();
        $token=self::previewToken($t,$values,$includeSalary,$template);
        $_SESSION['coe_preview'][$id]=['token'=>$token,'expires'=>time()+1800];
        return ['ticket'=>$t,'values'=>$values,'problems'=>self::problems($values,$includeSalary),'include_salary'=>$includeSalary,'template'=>$template,'request'=>self::ticketRow($id)];
    }
    /** Called by TicketTypes::complete() inside the Served transaction. */
    public static function complete(array $t,array $data): bool
    {
        if(!self::ready())throw new RuntimeException('COE requires migration 014.');
        $include=!empty($data['include_salary']);$template=self::activeTemplate();
        $values=self::values($t,$include);
        if($problems=self::problems($values,$include))throw new RuntimeException($problems[0]);
        $saved=$_SESSION['coe_preview'][(int)$t['id']]??null;
        if(!$saved||$saved['expires']<time()||!hash_equals($saved['token'],self::previewToken($t,$values,$include,$template)))
            throw new RuntimeException('Open the COE preview and confirm the values before marking this Served.');
        unset($_SESSION['coe_preview'][(int)$t['id']]);
        $q=db()->prepare('SELECT 1 FROM coe_documents WHERE ticket_id=? FOR UPDATE');$q->execute([$t['id']]);if($q->fetchColumn())throw new RuntimeException('A certificate was already generated for this request.');
        $src=$template?self::templatePath($template):self::defaultDocx($include);
        $stored=bin2hex(random_bytes(24)).'.docx';$dest=self::dir('coe_documents').'/'.$stored;
        try{
            self::fillDocx($src,$dest,$values);
            TicketService::trackMoved($dest);
            db()->prepare('INSERT INTO coe_documents(ticket_id,template_id,template_version,stored_name,sha256,file_size,date_issued,include_salary,generated_by) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$t['id'],$template['id']??null,$template['version_no']??null,$stored,hash_file('sha256',$dest),filesize($dest),date('Y-m-d'),$include?1:0,TicketRepository::uid()]);
            $docId=(int)db()->lastInsertId();
            db()->prepare('UPDATE coe_requests SET include_salary=? WHERE ticket_id=?')->execute([$include?1:0,$t['id']]);
            audit('Tickets','COE_GENERATED','coe_document',$docId,['ticket_id'=>(int)$t['id'],'template_version'=>$template['version_no']??'built-in','include_salary'=>$include]);
        }finally{if(!$template&&isset($src)&&is_file($src))unlink($src);}
        return true;
    }
    public static function document(int $ticketId): ?array
    {
        if(!self::ready())return null;
        $q=db()->prepare('SELECT * FROM coe_documents WHERE ticket_id=?');$q->execute([$ticketId]);return $q->fetch()?:null;
    }
    /** Requester or HR only. Goes through the normal ticket access check first, so viewers, ADL, SC, OIC, Manager and Accounting are refused. */
    public static function download(int $ticketId): array
    {
        $t=TicketRepository::find($ticketId);
        $uid=TicketRepository::uid();
        if(!empty($t['viewer_only'])||$t['type_code']!==self::TYPE||!((int)$t['requester_user_id']===$uid||TicketRepository::isHr()))throw new RuntimeException('Ticket not found or access denied.');
        $doc=$t['status']==='COMPLETED'?self::document($ticketId):null;
        if(!$doc)throw new RuntimeException('The certificate is not available yet.');
        if(!preg_match('/^[a-f0-9]{48}\.docx$/',$doc['stored_name']))throw new RuntimeException('Certificate file is invalid.');
        $path=self::dir('coe_documents').'/'.$doc['stored_name'];
        if(!is_file($path)||!hash_equals($doc['sha256'],hash_file('sha256',$path)))throw new RuntimeException('The certificate file is missing or changed. Please ask HR.');
        audit('Tickets','COE_DOWNLOADED','coe_document',(int)$doc['id'],['ticket_id'=>$ticketId,'by_requester'=>(int)$t['requester_user_id']===$uid]);
        return ['path'=>$path,'name'=>'Certificate-of-Employment-'.preg_replace('/[^A-Za-z0-9-]/','',$t['ticket_no']).'.docx'];
    }
}
