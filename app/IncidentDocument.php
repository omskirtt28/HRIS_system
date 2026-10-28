<?php
declare(strict_types=1);

/** Official Incident Report copies. The supplied master is immutable; only document.xml field paragraphs are filled. */
final class IncidentDocument
{
    public const TEMPLATE_VERSION='PMBSI-IR-20261027';
    public const TEMPLATE_SHA256='ed809eb1ea579c94efb9a8ea05dda070163f5ca3da393aaf57ee074f95a313ae';
    public const NATURES=['ATTENDANCE'=>'Attendance Violation','CONDUCT'=>'Conduct & Behavior','DISHONESTY'=>'Dishonesty','DAMAGE'=>'Damage','NEGLIGENCE'=>'Negligence','LOSS'=>'Loss','INSUBORDINATION'=>'Insubordination','OTHERS'=>'Others'];
    public const LOCATIONS=['BRANCH_SITE'=>'Branch/Site','OTHERS'=>'Others'];
    public const SUPPORTING=['SYSTEM_RECORDS'=>'System Records','PHOTOS'=>'Photos','TRANSACTION_RECORDS'=>'Transaction Records','CCTV'=>'CCTV Footage','ATTENDANCE_RECORDS'=>'Attendance Records','WRITTEN_STATEMENTS'=>'Written Statements','OTHERS'=>'Others'];
    private const W='http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public static function category(mixed $nature):string
    {
        if(!is_string($nature)||!isset(self::NATURES[$nature]))throw new RuntimeException('Choose one nature of incident.');
        return match($nature){'ATTENDANCE'=>'Attendance-related','DAMAGE','LOSS'=>'Property or equipment','OTHERS'=>'Other',default=>'Workplace conduct'};
    }
    /** Verified filer details, not the people named in the report. Never writes Employee 201. */
    public static function filerContext(array $employee):array
    {
        if((int)($employee['user_id']??0)!==TicketRepository::uid())throw new RuntimeException('Use your own linked Employee 201 record to file an incident.');
        $q=db()->prepare('SELECT e.first_name,e.middle_name,e.last_name,e.suffix,e.employee_no,e.mobile_no,p.name position_name,
            TRIM(CONCAT_WS(" ",m.first_name,m.middle_name,m.last_name,m.suffix)) immediate_head
            FROM employees e LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN employees m ON m.id=e.manager_employee_id WHERE e.id=? AND e.user_id=?');
        $q->execute([(int)$employee['id'],TicketRepository::uid()]);$e=$q->fetch();
        if(!$e)throw new RuntimeException('Please ask HR to check your linked Employee 201 record.');
        return ['employee_name'=>trim(implode(' ',array_filter([$e['first_name'],$e['middle_name'],$e['last_name'],$e['suffix']],static fn($v)=>$v!==null&&$v!==''))),
            'employee_code'=>(string)$e['employee_no'],'company_branch'=>trim((string)$employee['company_name'].' - '.(string)$employee['branch_name']),
            'position'=>trim((string)$e['position_name']),'immediate_head'=>trim((string)$e['immediate_head']),'contact_no'=>trim((string)$e['mobile_no'])];
    }
    private static function text(array $data,string $key,int $max,bool $required=false):string
    {
        $value=$data[$key]??'';
        if(!is_string($value))throw new RuntimeException('Enter a valid '.str_replace('_',' ',$key).'.');
        $value=trim(str_replace(["\r\n","\r"],"\n",$value));
        if(($required&&$value==='')||mb_strlen($value)>$max||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u',$value))throw new RuntimeException(ucfirst(str_replace('_',' ',$key)).' is missing or invalid (maximum '.$max.' characters).');
        return $value;
    }
    /** Frozen answers plus verified employee details. No signatures or account access are inferred from names. */
    public static function answers(array $data,array $employee):array
    {
        $a=self::filerContext($employee);
        if($a['employee_name']===''||$a['position']==='')throw new RuntimeException('Please ask HR to complete your employee name and actual position before filing.');
        foreach(['immediate_head'=>200,'contact_no'=>100] as $key=>$max)if($a[$key]==='')$a[$key]=self::text($data,$key,$max,true);
        $a['nature']=self::text($data,'nature',30,true);self::category($a['nature']);
        $a['nature_other']=self::text($data,'nature_other',5000);
        if($a['nature']!=='OTHERS')$a['nature_other']='';
        $a['location_type']=self::text($data,'location_type',20,true);
        if(!isset(self::LOCATIONS[$a['location_type']]))throw new RuntimeException('Choose Branch/Site or Others for the location.');
        $a['location']=self::text($data,'location',200,true);
        $a['incident_at']=self::text($data,'incident_at',30,true);
        $a['incident_end_time']=self::text($data,'incident_end_time',5);
        if($a['incident_end_time']!==''&&!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$a['incident_end_time']))throw new RuntimeException('Enter a valid optional end time.');
        if($a['incident_end_time']!==''&&$a['incident_end_time']<(new DateTimeImmutable($a['incident_at']))->format('H:i'))throw new RuntimeException('The optional end time must be on the same day and at or after the incident start time.');
        foreach(['involved_names'=>2000,'witness_names'=>2000,'initial_action'=>5000,'customer_name'=>200,'customer_contact'=>200] as $key=>$max)$a[$key]=self::text($data,$key,$max);
        $a['explanation']=self::text($data,'explanation',5000,true);
        $support=$data['supporting']??[];
        if(!is_array($support)||count($support)>count(self::SUPPORTING))throw new RuntimeException('Choose valid supporting document types.');
        foreach($support as $s)if(!is_string($s)||!isset(self::SUPPORTING[$s]))throw new RuntimeException('Choose valid supporting document types.');
        $a['supporting']=array_values(array_unique($support));
        $a['supporting_other']=self::text($data,'supporting_other',5000,in_array('OTHERS',$support,true));
        if(!in_array('OTHERS',$support,true))$a['supporting_other']='';
        $a['submitted_at']=date('Y-m-d H:i:s');$a['report_date']=date('Y-m-d');
        $a['template_version']=self::TEMPLATE_VERSION;$a['template_sha256']=self::TEMPLATE_SHA256;
        return $a;
    }
    public static function canRead(array $t):bool
    {
        return TicketIncident::isIncident($t)&&((int)$t['requester_user_id']===TicketRepository::uid()||TicketIncident::isHandler($t));
    }
    /** UI metadata only. Paths and frozen contact/name answers never go to other participants. */
    public static function info(array $t):?array
    {
        if(!self::canRead($t))return null;
        $a=$t['payload']['official_incident']??null;
        if(!is_array($a)||!is_array($a['document']??null))return null;
        return ['template_version'=>(string)$a['template_version'],'submitted_at'=>(string)$a['submitted_at'],'pdf_status'=>isset($a['document']['pdf'])?'ready':'unavailable'];
    }
    /** Absolute local paths only; reject traversal and Windows stream/device path syntax. */
    private static function absoluteDirectory(string $path):string
    {
        $path=str_replace('\\','/',$path);
        $absolute=PHP_OS_FAMILY==='Windows'?preg_match('#^[A-Za-z]:/#',$path):str_starts_with($path,'/');
        if(!$absolute||str_starts_with($path,'//')||preg_match('/[\x00-\x1F]/',$path))throw new RuntimeException('Configure an absolute private Incident Report storage directory outside the web roots.');
        $path=preg_replace('#/+#','/',$path);
        foreach(explode('/',substr($path,PHP_OS_FAMILY==='Windows'?3:1)) as $part)if($part==='.'||$part==='..'||str_contains($part,':')||(PHP_OS_FAMILY==='Windows'&&rtrim($part," .")!==$part))throw new RuntimeException('The private Incident Report storage directory is invalid.');
        $path=rtrim($path,'/');return $path===''?'/':(preg_match('/^[A-Za-z]:$/D',$path)?$path.'/':$path);
    }
    /** Resolve existing ancestors too, so a not-yet-created directory cannot bypass symlink checks. */
    private static function canonicalDirectory(string $path):string
    {
        $path=self::absoluteDirectory($path);$tail=[];$parent=$path;
        while(!is_dir($parent)){
            if(file_exists($parent)||is_link($parent))throw new RuntimeException('The private Incident Report storage directory is invalid.');
            $next=dirname($parent);if($next===$parent||$next==='.')throw new RuntimeException('The private Incident Report storage directory is unavailable.');
            array_unshift($tail,basename($parent));$parent=$next;
        }
        $resolved=realpath($parent);if($resolved===false)throw new RuntimeException('The private Incident Report storage directory is unavailable.');
        return self::absoluteDirectory(str_replace('\\','/',$resolved).($tail?'/'.implode('/',$tail):''));
    }
    private static function inside(string $path,string $root):bool
    {
        $path=rtrim($path,'/');$root=rtrim($root,'/');
        if(PHP_OS_FAMILY==='Windows'){$path=strtolower($path);$root=strtolower($root);}
        return $path===$root||str_starts_with($path,$root.'/');
    }
    private static function dir():string
    {
        // PHP's built-in server ignores .htaccess. Confidential files must live outside every served root.
        $cfg=$GLOBALS['config']['incident_documents']??[];$configured=is_array($cfg)?($cfg['storage_root']??''):'';
        if(!is_string($configured))throw new RuntimeException('Configure an absolute private Incident Report storage directory outside the web roots.');
        $requested=self::absoluteDirectory($configured!==''?$configured:dirname(__DIR__,3).'/hris-private/incident_documents');$dir=self::canonicalDirectory($requested);
        $served=[dirname(__DIR__)];
        if(is_string($_SERVER['DOCUMENT_ROOT']??null)&&$_SERVER['DOCUMENT_ROOT']!=='')$served[]=$_SERVER['DOCUMENT_ROOT'];
        // XAMPP can also serve sibling projects: protect its whole htdocs ancestor when present.
        foreach($served as $base){for($ancestor=self::absoluteDirectory($base);;){if(strtolower(basename($ancestor))==='htdocs')$served[]=$ancestor;$next=dirname($ancestor);if($next===$ancestor||$next==='.')break;$ancestor=$next;}}
        $blocked=[];foreach(array_unique($served) as $base){$blocked[]=self::absoluteDirectory($base);$blocked[]=self::canonicalDirectory($base);}
        $assertPrivate=static function(string $candidate)use($blocked):void{foreach($blocked as $root)if(self::inside($candidate,$root))throw new RuntimeException('Incident Report storage must be outside the application and web roots. Ask your administrator to configure private storage.');};
        // Check both lexical aliases and their resolved targets, before mkdir and before any document write.
        $assertPrivate($requested);$assertPrivate($dir);
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Private Incident Report storage is unavailable. Ask your administrator to configure a writable directory outside the web roots.');
        $dir=self::canonicalDirectory($dir);$assertPrivate($dir);
        if(!is_writable($dir))throw new RuntimeException('Private Incident Report storage is not writable. Ask your administrator to check its access settings.');
        return $dir;
    }
    private static function path(array $file,string $extension):string
    {
        $name=$file['stored_name']??'';
        if(!is_string($name)||!preg_match('/^[a-f0-9]{48}\.'.$extension.'$/D',$name))throw new RuntimeException('The incident document is unavailable.');
        $path=self::dir().'/'.$name;
        if(!is_file($path)||!is_string($file['sha256']??null)||!hash_equals($file['sha256'],hash_file('sha256',$path)))throw new RuntimeException('The incident document is unavailable.');
        return $path;
    }
    private static function metadata(string $path):array
    {
        return ['stored_name'=>basename($path),'sha256'=>hash_file('sha256',$path),'file_size'=>filesize($path)];
    }
    /** Scoped paragraph replacement, keeping the paragraph and run styles of that exact field. */
    private static function fillParagraph(DOMXPath $xp,DOMElement $p,string $value):void
    {
        $doc=$p->ownerDocument;$run=$xp->query('./w:r',$p)->item(0);$properties=$run?$xp->query('./w:rPr',$run)->item(0):$xp->query('./w:pPr/w:rPr',$p)->item(0);
        $new=$doc->createElementNS(self::W,'w:r');if($properties)$new->appendChild($properties->cloneNode(true));
        foreach($xp->query('./w:r/w:t|./w:r/w:br|./w:r/w:tab',$p) as $node)$node->parentNode->removeChild($node);
        foreach(explode("\n",$value) as $i=>$line){if($i)$new->appendChild($doc->createElementNS(self::W,'w:br'));$text=$doc->createElementNS(self::W,'w:t');$text->setAttributeNS('http://www.w3.org/XML/1998/namespace','xml:space','preserve');$text->appendChild($doc->createTextNode($line));$new->appendChild($text);}
        $p->appendChild($new);
    }
    private static function fill(string $path,array $a):void
    {
        $zip=new ZipArchive();if(@$zip->open($path)!==true)throw new RuntimeException('The official report could not be created. Please retry.');
        try{
            $xml=$zip->getFromName('word/document.xml');if(!is_string($xml)||preg_match('/<!(DOCTYPE|ENTITY)/i',$xml))throw new RuntimeException('The official report template is invalid.');
            $doc=new DOMDocument();$doc->preserveWhiteSpace=true;$before=libxml_use_internal_errors(true);
            try{$loaded=$doc->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($before);}
            if(!$loaded)throw new RuntimeException('The official report template is invalid.');
            $xp=new DOMXPath($doc);$xp->registerNamespace('w',self::W);$p=iterator_to_array($xp->query('//w:p'));
            if(count($p)!==160)throw new RuntimeException('The official report template version is not supported.');
            // Verify unique field anchors in addition to the immutable file hash; never replace repeated sample names globally.
            foreach([3=>'LAST NAME, FIRST NAME ',5=>'MONTH XX, 20XX',65=>'(Insert incident description here)',128=>'JUAN DELA CRUZ',135=>'JUAN DELA CRUZ',140=>'Branch Immediate Head',143=>'Client HR/Mgmt. Representative'] as $i=>$expected){
                if($p[$i]->textContent!==$expected)throw new RuntimeException('The official report template fields do not match.');
            }
            $at=new DateTimeImmutable($a['incident_at']);$time=$at->format('h:i A');if($a['incident_end_time']!=='')$time.=' – '.DateTimeImmutable::createFromFormat('!H:i',$a['incident_end_time'])->format('h:i A');
            $values=[3=>$a['employee_name'],5=>strtoupper((new DateTimeImmutable($a['report_date']))->format('F j, Y')),7=>$a['company_branch'],9=>$a['position'],11=>$a['immediate_head'],13=>$a['contact_no'],
                43=>$a['nature_other'],48=>$a['location_type']==='BRANCH_SITE'?$a['location']:'',51=>$a['location_type']==='OTHERS'?$a['location']:'',54=>strtoupper($at->format('F j, Y')),56=>$time,
                58=>$a['involved_names']?:'N/A',60=>$a['witness_names']?:'N/A',65=>$a['explanation'],70=>$a['initial_action']?:'N/A',76=>$a['customer_name']?:'N/A',78=>$a['customer_contact']?:'N/A',107=>$a['supporting_other'],
                128=>$a['employee_name'],135=>'',146=>$a['position'],149=>''];
            foreach(array_combine(array_keys(self::NATURES),[20,23,26,29,32,35,38,41]) as $key=>$i)$values[$i]=$a['nature']===$key?'☒':'☐';
            $values[46]=$a['location_type']==='BRANCH_SITE'?'☒':'☐';$values[49]=$a['location_type']==='OTHERS'?'☒':'☐';
            foreach(array_combine(array_keys(self::SUPPORTING),[87,90,93,96,99,102,105]) as $key=>$i)$values[$i]=in_array($key,$a['supporting'],true)?'☒':'☐';
            foreach($values as $i=>$value)self::fillParagraph($xp,$p[$i],$value);
            // Narrative rows are minimum-height, splittable rows in the master. Keep fonts, widths and pagination flow intact.
            if(!$zip->addFromString('word/document.xml',$doc->saveXML()))throw new RuntimeException('The official report could not be created.');
        }finally{if(!$zip->close())throw new RuntimeException('The official report could not be saved. Please retry.');}
    }
    /** Required DOCX is generated inside the ticket transaction. PDF is optional and never rolls the report back. */
    public static function generate(array $answers):array
    {
        if(!class_exists(ZipArchive::class)||!class_exists(DOMDocument::class))throw new RuntimeException('Official Incident Reports need the PHP ZIP and XML extensions. Ask your administrator.');
        $master=dirname(__DIR__).'/resources/templates/incident-report-master.docx';
        if(!is_file($master)||!hash_equals(self::TEMPLATE_SHA256,hash_file('sha256',$master))||($answers['template_sha256']??'')!==self::TEMPLATE_SHA256)throw new RuntimeException('The official Incident Report master is missing or changed. Ask your administrator.');
        $path=self::dir().'/'.bin2hex(random_bytes(24)).'.docx';
        TicketService::trackMoved($path);
        if(!@copy($master,$path))throw new RuntimeException('The official report could not be saved. Please retry.');
        self::fill($path,$answers);
        $document=['docx'=>self::metadata($path)];$pdf=self::convert($path);if($pdf!==null)$document['pdf']=$pdf;
        return $document;
    }
    /** Remove only our randomly named conversion workspace; do not follow symlinks out of it. */
    private static function cleanup(string $directory):void
    {
        if(!is_dir($directory))return;
        foreach(new DirectoryIterator($directory) as $entry){if($entry->isDot())continue;$p=$entry->getPathname();if($entry->isDir()&&!$entry->isLink())self::cleanup($p);else @unlink($p);}
        @rmdir($directory);
    }
    private static function windowsKiller():string
    {
        return rtrim((string)getenv('SystemRoot'),'\\/').'/System32/taskkill.exe';
    }
    /** Windows console LibreOffice can spawn soffice.bin; end the whole job tree before removing its profile. */
    private static function stopConverter(mixed $process):void
    {
        $status=proc_get_status($process);if(!$status['running'])return;
        if(PHP_OS_FAMILY==='Windows'&&(int)$status['pid']>0){
            $discard=[];$kill=@proc_open([self::windowsKiller(),'/PID',(string)(int)$status['pid'],'/T','/F'],[0=>['file','NUL','r'],1=>['file','NUL','w'],2=>['file','NUL','w']],$discard,null,null,['bypass_shell'=>true,'create_no_window'=>true]);
            if(is_resource($kill)){
                $until=microtime(true)+2;$ks=proc_get_status($kill);
                while($ks['running']&&microtime(true)<$until){usleep(25000);$ks=proc_get_status($kill);}
                if($ks['running'])proc_terminate($kill,9);proc_close($kill);
            }
        }
        if(proc_get_status($process)['running'])proc_terminate($process,9);
    }
    private static function convert(string $docx):?array
    {
        $cfg=$GLOBALS['config']['incident_documents']??[];$exe=is_array($cfg)&&is_string($cfg['libreoffice_path']??null)?$cfg['libreoffice_path']:'';
        // No PATH lookup, desktop automation, shell command interpolation or external document service.
        if($exe===''||!is_file($exe)||!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#',$exe))return null;
        foreach(['proc_open','proc_get_status','proc_terminate','proc_close'] as $function)if(!is_callable($function))return null;
        if(PHP_OS_FAMILY==='Windows'&&!is_file(self::windowsKiller()))return null;
        $timeout=max(5,min(60,(int)($cfg['conversion_timeout_seconds']??25)));$job='';$process=null;$discard=[];
        try{
            $job=self::dir().'/convert-'.bin2hex(random_bytes(16));
            if(!@mkdir($job,0700)||!@mkdir($job.'/profile',0700))return null;
            $profile=str_replace('\\','/',realpath($job.'/profile'));$encoded=implode('/',array_map('rawurlencode',explode('/',$profile)));$encoded=preg_replace('/^([A-Za-z])%3A/','$1:',$encoded);
            $uri='file://'.(str_starts_with($profile,'/')?'':'/').$encoded;
            $command=[$exe,'-env:UserInstallation='.$uri,'--headless','--nologo','--nodefault','--nolockcheck','--nofirststartwizard','--convert-to','pdf:writer_pdf_Export','--outdir',$job,$docx];
            $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
            $process=@proc_open($command,[0=>['file',$null,'r'],1=>['file',$null,'w'],2=>['file',$null,'w']],$discard,$job,null,['bypass_shell'=>true,'create_no_window'=>true]);
            if(!is_resource($process))return null;
            $deadline=microtime(true)+$timeout;$status=proc_get_status($process);
            while($status['running']&&microtime(true)<$deadline){usleep(50000);$status=proc_get_status($process);}
            if($status['running'])return null;// finally terminates the process tree before the private profile is removed.
            $pdf=$job.'/'.pathinfo($docx,PATHINFO_FILENAME).'.pdf';
            if(!is_file($pdf)||filesize($pdf)<5||filesize($pdf)>30*1024*1024||file_get_contents($pdf,false,null,0,5)!=='%PDF-')return null;
            $destination=self::dir().'/'.bin2hex(random_bytes(24)).'.pdf';
            if(!@rename($pdf,$destination))return null;
            TicketService::trackMoved($destination);return self::metadata($destination);
        }catch(Throwable){error_log('Incident PDF preview unavailable.');return null;
        }finally{
            if(is_resource($process)){self::stopConverter($process);proc_close($process);}
            try{self::cleanup($job);}catch(Throwable){error_log('Incident converter temporary cleanup deferred.');}
        }
    }
    /** Called under the same ticket row lock as the version/handler checks. */
    public static function retry(array $t):?array
    {
        $a=$t['payload']['official_incident']??null;
        if(!is_array($a)||!is_array($a['document']['docx']??null))throw new RuntimeException('This case has no generated official document.');
        if(isset($a['document']['pdf']))return $a['document']['pdf'];
        return self::convert(self::path($a['document']['docx'],'docx'));
    }
    public static function download(int $id,string $format):array
    {
        if(!in_array($format,['pdf','docx'],true))throw new RuntimeException('Choose PDF or DOCX.');
        $t=TicketRepository::find($id);
        if(!self::canRead($t))throw new RuntimeException('Only the reporter and eligible handling HR may open this document.');
        $file=$t['payload']['official_incident']['document'][$format]??null;
        if(!is_array($file))throw new RuntimeException($format==='pdf'?'The PDF preview is unavailable. Download the DOCX or ask handling HR to retry.':'This case has no generated official document.');
        $path=self::path($file,$format);audit('Tickets','INCIDENT_DOCUMENT_OPENED','ticket',$id,['format'=>$format]);
        return ['path'=>$path,'name'=>'Incident-Report-'.preg_replace('/[^A-Za-z0-9-]/','',(string)$t['ticket_no']).'.'.$format];
    }
}
