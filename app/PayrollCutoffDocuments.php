<?php
declare(strict_types=1);

/** Standalone downloads: native XLSX (ZipArchive) and PDF, without office software. */
final class PayrollCutoffDocuments
{
    public static function sections(array $saved): array
    {
        $sections=[];
        foreach($saved['rows'] as $day) {
            $key=$day['_employee']['export_section'];
            $sections[$key][(int)$day['employee_id']][]=$day;
        }
        ksort($sections,SORT_NATURAL|SORT_FLAG_CASE);
        foreach($sections as &$employees) uasort($employees,static fn($a,$b)=>strcasecmp($a[0]['_employee']['employee_name'],$b[0]['_employee']['employee_name']));
        unset($employees);return $sections;
    }

    public static function download(int $cutoffId,string $format,bool $forReview=false): never
    {
        Auth::requirePermission('payroll.export');
        if(!in_array($format,['xlsx','pdf'],true))throw new RuntimeException('Choose Excel or PDF.');
        if($forReview) {
            $saved=PayrollCutoffExportService::currentReport($cutoffId);$run=$saved['run'];$batch=$saved['report_meta'];
        } else {
            $saved=PayrollCutoffExportService::saved($cutoffId);$run=PayrollAttendanceService::run($cutoffId);$batch=PayrollCutoffExportService::batch($cutoffId);
        }
        $file=tempnam(sys_get_temp_dir(),'hris-cutoff-');
        if($file===false)throw new RuntimeException('The server could not create the download. Check its temporary folder.');
        try {
            if($format==='xlsx') self::excel($file,$saved,$run,$batch); else self::pdf($file,$saved,$run,$batch);
            audit('Payroll',$forReview?'DOWNLOAD_ATTENDANCE_FOR_REVIEW':'DOWNLOAD_COMPLETE_ATTENDANCE','payroll_cutoff',$cutoffId,['format'=>$format,'content_hash'=>$batch['content_hash']??null,'employees'=>$batch['employee_count'],'for_review'=>$forReview]);
            header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'application/pdf'));
            header('Content-Disposition: attachment; filename="PMBSI-Attendance-'.($forReview?'For-Review-':'').$run['period_start'].'-'.$run['period_end'].'.'.$format.'"');
            header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($file));
            readfile($file);
        } finally {if(is_file($file))unlink($file);}
        exit;
    }

    public static function stateLabel(string $state): string
    {
        return match($state){'COMPLETE','CORRECTED'=>'Complete','APPROVED_OB'=>'OB','APPROVED_LEAVE'=>'Leave','REST_DAY'=>'Rest day','NO_WORK'=>'No work','ABSENT'=>'Absent','NOT_EMPLOYED'=>'Before start date','ZERO_CREDIT'=>'Reviewed: no work credit',default=>ucwords(strtolower(str_replace('_',' ',$state)))};
    }

    private static function notes(array $day): string
    {
        $notes=[$day['export_day_label']??self::stateLabel($day['state'])];
        if(!empty($day['export_missing']))$notes[]='Missing: '.implode(', ',$day['export_missing']);
        foreach($day['export_review_notes']??[] as $note)$notes[]=$note;
        foreach(array_unique(array_values($day['export_marks']??[])) as $type)$notes[]=$type.' approved';
        if(!empty($day['export_note']))$notes[]=$day['export_note'];
        foreach(json_decode($day['sources_json'],true)?:[] as $source) {
            if(!empty($source['no']))$notes[]=$source['no'];
            if(($source['type']??'')==='ZERO_CREDIT')$notes[]=(string)($source['note']??'');
        }
        return implode(' / ',array_filter(array_unique($notes)));
    }

    private static function xml(string $value): string
    {
        $value=preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value)??'';
        return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
    }

    private static function column(int $number): string
    {
        $result='';for(;$number>0;$number=intdiv($number-1,26))$result=chr(65+($number-1)%26).$result;return $result;
    }

    private static function cell(int $column,int $row,mixed $value,int $style=0,bool $number=false): string
    {
        if($value===null||$value==='')return '';
        $at=self::column($column).$row;
        return '<c r="'.$at.'" s="'.$style.'"'.($number?'':' t="inlineStr"').'>'.($number?'<v>'.(string)$value.'</v>':'<is><t xml:space="preserve">'.self::xml((string)$value).'</t></is>').'</c>';
    }

    private static function dateSerial(string $date): int
    {
        return (int)(new DateTimeImmutable('1899-12-30'))->diff(new DateTimeImmutable(substr($date,0,10)))->format('%r%a');
    }

    private static function timeSerial(string $stamp): float
    {
        $time=new DateTimeImmutable($stamp);return ((int)$time->format('H')*3600+(int)$time->format('i')*60+(int)$time->format('s'))/86400;
    }

    private static function sheetName(array $employee,array &$used): string
    {
        $name=($employee['export_workplace']==='HO'?'HO ':'').($employee['export_company']?:'NA').' '.($employee['export_workplace']==='RETAIL'?($employee['area_code']?:$employee['area_name']).' - '.($employee['branch_code']?:$employee['branch_name']):$employee['export_department']);
        $name=trim(str_replace(['\\','/',':','*','?','[',']'], '-', $name)," '\t\r\n");
        $base=mb_substr($name?:'To assign',0,31);$name=$base;$n=1;
        while(isset($used[mb_strtolower($name)]))$name=mb_substr($base,0,26).' '.(++$n);
        $used[mb_strtolower($name)]=true;return $name;
    }

    private static function excel(string $file,array $saved,array $run,array $batch): void
    {
        if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP zip extension to download Excel files.');
        $zip=new ZipArchive();if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not prepare the Excel file.');
        try {
            $types='<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $relations='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';$used=[];$i=0;
            if(!empty($saved['source_gaps'])) {
                $i++;$used['source summary']=true;
                $types.='<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $workbook.='<sheet name="Source Summary" sheetId="1" r:id="rId1"/>';
                $relations.='<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
                if(!$zip->addFromString('xl/worksheets/sheet1.xml',self::summarySheet($saved['source_gaps'],$saved['employees'],$run,$batch)))throw new RuntimeException('Could not write the Excel summary worksheet.');
            }
            foreach(self::sections($saved) as $section=>$employees) {
                $i++;$employee=reset($employees)[0]['_employee'];$name=self::sheetName($employee,$used);
                $types.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $workbook.='<sheet name="'.self::xml($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
                $relations.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
                if(!$zip->addFromString('xl/worksheets/sheet'.$i.'.xml',self::worksheet($section,$employees,$run,$batch)))throw new RuntimeException('Could not write an Excel worksheet.');
            }
            $relations.='<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
            $parts=['[Content_Types].xml'=>$types.'</Types>','_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="workbook" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>', 'xl/workbook.xml'=>$workbook.'</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>','xl/_rels/workbook.xml.rels'=>$relations,'xl/styles.xml'=>self::styles()];
            foreach($parts as $path=>$content)if(!$zip->addFromString($path,$content))throw new RuntimeException('Could not finish the Excel file.');
        } catch(Throwable $e){$zip->close();throw $e;}
        if(!$zip->close())throw new RuntimeException('Could not save the Excel download.');
    }

    /** Which departments / branches have employees with no biometric logs, so the missing files are obvious. */
    private static function summarySheet(array $gaps,array $employees,array $run,array $batch): string
    {
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetFormatPr defaultRowHeight="20"/><cols><col min="1" max="1" width="48" customWidth="1"/><col min="2" max="4" width="16" customWidth="1"/><col min="5" max="5" width="34" customWidth="1"/></cols><sheetData>';
        $r=1;$xml.='<row r="1" ht="26" customHeight="1">'.self::cell(1,1,'PMBSI - Biometric source summary / FOR REVIEW',8).'</row>';
        $r++;$xml.='<row r="'.$r.'">'.self::cell(1,$r,$run['period_start'].' to '.$run['period_end'].' / Generated: '.$batch['generated_at']).'</row>';
        $r++;$xml.='<row r="'.$r.'">'.self::cell(1,$r,'Yellow = no biometric log received for any employee in that group. Request or import that file; nothing is assumed absent.').'</row>';
        $r+=2;$xml.='<row r="'.$r.'" ht="30" customHeight="1">';
        foreach(['Group / Department / Branch','Employees','With logs','Without logs','Status'] as $c=>$h)$xml.=self::cell($c+1,$r,$h,1);
        $xml.='</row>';
        foreach($gaps['sections'] as $section=>$count) {
            $r++;$missing=$count['total']-$count['with_logs'];$none=$count['with_logs']===0;
            $status=$none?'NO LOGS RECEIVED':($missing?'PARTIAL - '.$missing.' employees without logs':'Complete');
            $style=$none?9:($missing?2:0);
            $xml.='<row r="'.$r.'">'.self::cell(1,$r,$section,$style).self::cell(2,$r,$count['total'],$style,true).self::cell(3,$r,$count['with_logs'],$style,true).self::cell(4,$r,$missing,$style,true).self::cell(5,$r,$status,$style).'</row>';
        }
        $r+=2;$xml.='<row r="'.$r.'" ht="30" customHeight="1">';
        foreach(['Employees without biometric logs','Code','','','Group'] as $c=>$h)if($h!=='')$xml.=self::cell($c+1,$r,$h,1);
        $xml.='</row>';
        foreach($gaps['no_log_employees'] as $id) {
            $e=$employees[$id]??null;if(!$e)continue;$r++;
            $xml.='<row r="'.$r.'">'.self::cell(1,$r,$e['employee_name']).self::cell(2,$r,$e['employee_no']?:'Code to follow').self::cell(5,$r,$e['export_section']).'</row>';
        }
        return $xml.'</sheetData><pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="portrait"/></worksheet>';
    }

    private static function worksheet(string $section,array $employees,array $run,array $batch): string
    {
        $headers=['Code','Name','Date Hired','Company','Branch','Department','Position','COLA','Date','Day','C-In','B-Out','B-In','C-Out','Remarks','Schedule',"Late\n(min)","Late\nin Between", "Training\nDays / Amount",'',"Days\nPresent",'',"Late\nHours","UT\nHours",'TOTAL','Excess','Offset','ROT','EROT','RND','NDOT','RD','RDOT','RDND','SOT','ESOT','SPHD','SPHOT','SPHND','SPHRD','LEGHD','LEGHWRK','LEGHOT','LEGHRD','Approved OT (h)'];
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane xSplit="2" ySplit="10" topLeftCell="C11" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="22"/><cols>';
        foreach([1=>16,2=>30,3=>15,4=>22,5=>20,6=>25,7=>25,8=>10,9=>16,10=>12,11=>13,12=>13,13=>13,14=>13,15=>55,16=>24] as $c=>$width)$xml.='<col min="'.$c.'" max="'.$c.'" width="'.$width.'" customWidth="1"/>';
        $xml.='<col min="17" max="45" width="13" customWidth="1"/></cols><sheetData>';
        $review=!empty($batch['for_review']);
        $headings=[1=>'PMBSI - Payroll Timekeep / '.($review?'FOR REVIEW':'Complete Attendance'),2=>$run['period_start'].' to '.$run['period_end'],3=>$section,4=>'Generated: '.$batch['generated_at'].' / '.count($employees).' employees',5=>'Yellow: approved TA / OB clock changes and matched approved OT',6=>$review?'FOR REVIEW: missing times stay blank. Pending requests are not applied. Cutoff status stays the same.':'Blank pay columns stay available for Payroll. Attendance hours do not calculate salary.'];
        if($review)$headings[7]=$batch['notice'];
        foreach($headings as $r=>$label)$xml.='<row r="'.$r.'" ht="26" customHeight="1">'.self::cell(1,$r,$label,$r===1?8:0).'</row>';
        $xml.='<row r="10" ht="42" customHeight="1">';foreach($headers as $c=>$value)$xml.=self::cell($c+1,10,$value,1);$xml.='</row>';$r=10;
        foreach($employees as $days) {
            foreach($days as $n=>$day) {
                $r++;$e=$day['_employee'];$cells='';
                if($n===0) {
                    foreach([1=>$e['employee_no']?:'Code to follow',2=>$e['employee_name']] as $c=>$v)$cells.=self::cell($c,$r,$v,2);
                    $cells.=!empty($e['hire_date'])?self::cell(3,$r,self::dateSerial($e['hire_date']),3,true):self::cell(3,$r,'To follow',2);
                    foreach([4=>$e['company_name']?:'Company to assign',5=>trim($e['branch_code'].' / '.$e['branch_name'],' /'),6=>$e['export_department'],7=>$e['position_name']] as $c=>$v)$cells.=self::cell($c,$r,$v,2);
                }
                $cells.=self::cell(9,$r,self::dateSerial($day['work_date']),3,true).self::cell(10,$r,date('D',strtotime($day['work_date'])));
                foreach(PayrollCalculator::FIELDS as $c=>$field)if(!empty($day[$field]))$cells.=self::cell(11+$c,$r,self::timeSerial($day[$field]),isset($day['export_marks'][$field])?5:4,true);
                $note=self::notes($day);$cells.=self::cell(15,$r,$note,!empty($day['export_marks'])?9:0);
                $site=(json_decode($day['rate_snapshot_json'],true)?:[])['site']??[];
                if(!empty($site['shift_start'])&&!empty($site['shift_end']))$cells.=self::cell(16,$r,date('g:i A',strtotime($site['shift_start'])).' - '.date('g:i A',strtotime($site['shift_end'])));
                $hasClocks=(bool)array_filter(array_intersect_key($day,array_flip(PayrollCalculator::FIELDS)));
                if($hasClocks) {
                    if(!empty($site['shift_start']))$cells.=self::cell(17,$r,(int)$day['late_minutes'],6,true).self::cell(23,$r,round((int)$day['late_minutes']/60,4),6,true).self::cell(24,$r,round((int)$day['undertime_minutes']/60,4),6,true);
                    // The source template's TOTAL/pay categories stay blank: no assumed payroll formulas.
                }
                $ot=PayrollCutoffExportService::otMinutes($day);if($ot>0)$cells.=self::cell(45,$r,round($ot/60,4),7,true);
                $height=$review?max(34,min(409,10+15*(int)ceil(mb_strlen($note)/48))):34;
                $xml.='<row r="'.$r.'" ht="'.$height.'" customHeight="1">'.$cells.'</row>';
            }
            $r++;$xml.='<row r="'.$r.'" ht="12" customHeight="1"/>';
        }
        $xml.='</sheetData><mergeCells count="'.count($headings).'">';for($n=1;$n<=count($headings);$n++)$xml.='<mergeCell ref="A'.$n.':AS'.$n.'"/>';
        return $xml.'</mergeCells><printOptions gridLines="true"/><pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="landscape"/></worksheet>';
    }

    private static function styles(): string
    {
        $styles='<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="3"><numFmt numFmtId="164" formatCode="mmm d, yyyy"/><numFmt numFmtId="165" formatCode="h:mm AM/PM"/><numFmt numFmtId="166" formatCode="0.00"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="16"/><name val="Arial"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF18212F"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFEF99"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="10">';
        foreach([[0,0,0],[0,1,2],[0,0,3],[164,0,0],[165,0,0],[165,0,4],[166,0,0],[166,0,4],[0,2,0],[0,0,4]] as [$num,$font,$fill])$styles.='<xf numFmtId="'.$num.'" fontId="'.$font.'" fillId="'.$fill.'" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>';
        return $styles.'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private static function pdf(string $file,array $saved,array $run,array $batch): void
    {
        $pdf=new PayrollCutoffPdf();$page=0;
        if(!empty($saved['source_gaps'])) {
            $y=0;
            $summaryHeader=static function()use($pdf,$run,$batch,&$page,&$y):void {
                $pdf->page();$page++;$pdf->text(36,40,'PMBSI - Biometric source summary / FOR REVIEW',17,true,523);
                $pdf->text(36,58,$run['period_start'].' to '.$run['period_end'].' / Generated '.$batch['generated_at'],9,false,523);
                $pdf->text(36,76,'Groups marked NO LOGS have no biometric punches in any imported file. Nothing is assumed absent.',8,false,523);
                $pdf->rect(36,90,523,22,[.94,.96,.98]);$pdf->text(42,105,'Group / Department / Branch',9,true,280);$pdf->text(330,105,'With logs / Total',9,true,90);$pdf->text(430,105,'Status',9,true,125);
                $pdf->text(490,822,'Page '.$page,8,false,65);$y=130;
            };
            $summaryHeader();
            foreach($saved['source_gaps']['sections'] as $name=>$count) {
                if($y>785)$summaryHeader();
                $missing=$count['total']-$count['with_logs'];
                if($count['with_logs']===0)$pdf->rect(36,$y-11,523,16,[1,.94,.60]);
                $pdf->text(42,$y,$name,8,false,280);$pdf->text(330,$y,$count['with_logs'].' / '.$count['total'],8,false,90);
                $pdf->text(430,$y,$count['with_logs']===0?'NO LOGS RECEIVED':($missing?$missing.' without logs':'Complete'),8,$count['with_logs']===0,125);$y+=16;
            }
        }
        foreach(self::sections($saved) as $section=>$employees)foreach($employees as $days) {
            $e=$days[0]['_employee'];$y=0;$continuation=false;
            $header=static function()use($pdf,$run,$batch,$section,$e,&$page,&$y,&$continuation):void {
                $review=!empty($batch['for_review']);
                $pdf->page();$page++;$pdf->text(36,40,$review?'PMBSI - Attendance / FOR REVIEW':'PMBSI - Cutoff Attendance Logs',17,true,523);
                $pdf->text(36,58,$run['period_start'].' to '.$run['period_end'].' / Generated '.$batch['generated_at'],9,false,523);
                $y=81;
                if($review)foreach($pdf->wrap($batch['notice'],100) as $line){$pdf->text(36,$y,$line,8,false,523);$y+=13;}
                foreach($pdf->wrap($section,80) as $line){$pdf->text(36,$y,$line,10,true,523);$y+=14;}
                $pdf->text(36,$y+4,$e['employee_name'].($continuation?' (continued)':''),11,true,523);$y+=22;
                $pdf->text(36,$y,($e['employee_no']?:'Code to follow / HRIS ID '.$e['id']).' / '.$e['company_name'].' / '.$e['position_name'],9,false,523);$y+=18;
                $pdf->rect(36,$y-10,523,24,[.94,.96,.98]);foreach([[42,'No. / Employee Code'],[170,'Date / Time'],[347,'Status'],[443,'Source']] as [$x,$text])$pdf->text($x,$y+5,$text,9,true,$x===170?172:120);
                $y+=27;
                if($review)$pdf->text(36,792,'FOR REVIEW: missing times are blank; pending requests are not applied; cutoff status stays the same.',8,true,523);
                $pdf->text(36,807,'Yellow: approved TA / OB / OT changes. Salary is calculated separately.',8,false,523);$pdf->text(490,822,'Page '.$page,8,false,65);$continuation=true;
            };
            $header();
            foreach($days as $day) {
                $items=[];
                foreach(PayrollCalculator::FIELDS as $field)if(!empty($day[$field]))$items[]=[$day[$field],match($field){'time_in'=>'IN','lunch_out'=>'BREAK OUT','lunch_in'=>'BREAK IN',default=>'OUT'},$day['export_marks'][$field]??'Biometric',isset($day['export_marks'][$field])];
                if(!$items)$items[]=[$day['work_date'],$day['export_day_label']??self::stateLabel($day['state']),'Day record',false];
                foreach($day['export_missing']??[] as $missing)$items[]=[$day['work_date'],'Missing '.$missing,'For review',false];
                if(($ot=PayrollCutoffExportService::otMinutes($day))>0)$items[]=[$day['work_date'],'OT '.number_format($ot/60,2).' h','Approved OT',true];
                foreach($items as [$stamp,$status,$source,$yellow]) {
                    if($y>767)$header();
                    if($yellow)$pdf->rect(36,$y-11,523,20,[1,.94,.60]);
                    $pdf->text(42,$y,(string)($e['employee_no']?:'HRIS '.$e['id']),9,false,123);
                    $pdf->text(170,$y,date(strlen($stamp)>10?'M d, Y g:i A':'M d, Y',strtotime($stamp)),9,false,172);
                    $pdf->text(347,$y,$status,9,false,91);$pdf->text(443,$y,$source,9,$yellow,110);
                    $pdf->line(36,$y+7,559,$y+7);$y+=20;
                }
                $note=self::notes($day);
                if($note!=='Complete')foreach($pdf->wrap($note,100) as $line){if($y>767)$header();$pdf->text(42,$y,$line,8,false,511);$y+=13;}
            }
        }
        $pdf->save($file);
    }
}

/** A small PDF writer: standard embedded references, WinAnsi fonts, no executable content. */
final class PayrollCutoffPdf
{
    private array $pages=[];
    public function page():void {$this->pages[]='';}
    private function append(string $command):void {$this->pages[count($this->pages)-1].=$command."\n";}
    private function encoded(string $text):string {return iconv('UTF-8','Windows-1252//TRANSLIT',str_replace(["\r","\n"],' ',$text))?:'';}
    public function wrap(string $text,int $length):array {return explode("\n",wordwrap($this->encoded($text),$length,"\n",true));}
    public function text(float $x,float $top,string $text,int $size,bool $bold,float $width):void
    {
        // wrap() returns WinAnsi lines; recover UTF-8 once so encoding is never applied twice.
        if(!mb_check_encoding($text,'UTF-8'))$text=iconv('Windows-1252','UTF-8',$text)?:$text;
        $text=$this->encoded($text);$limit=max(1,(int)floor($width/($size*.62)));
        if(strlen($text)>$limit)$text=substr($text,0,max(0,$limit-3)).'...';
        $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$text);
        $this->append('0.09 0.13 0.18 rg BT /'.($bold?'F2':'F1').' '.$size.' Tf 1 0 0 1 '.$x.' '.(842-$top).' Tm ('.$escaped.') Tj ET');
    }
    public function rect(float $x,float $top,float $width,float $height,array $rgb):void {$this->append(implode(' ',$rgb).' rg '.$x.' '.(842-$top-$height).' '.$width.' '.$height.' re f');}
    public function line(float $x,float $top,float $endX,float $endTop):void {$this->append('0.88 0.90 0.93 RG 0.4 w '.$x.' '.(842-$top).' m '.$endX.' '.(842-$endTop).' l S');}
    public function save(string $file):void
    {
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];$kids=[];$next=5;
        foreach($this->pages as $stream){$page=$next++;$content=$next++;$kids[]=$page.' 0 R';$objects[$page]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$content.' 0 R >>';$objects[$content]='<< /Length '.strlen($stream).' >>' ."\nstream\n".$stream."endstream";}
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';ksort($objects);
        $output="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $id=>$object){$offsets[$id]=strlen($output);$output.=$id." 0 obj\n".$object."\nendobj\n";}
        $xref=strlen($output);$output.='xref' ."\n0 ".$next."\n0000000000 65535 f \n";for($id=1;$id<$next;$id++)$output.=sprintf('%010d 00000 n ',$offsets[$id])."\n";
        $output.='trailer' ."\n<< /Size ".$next.' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
        if(file_put_contents($file,$output)===false)throw new RuntimeException('Could not save the PDF download.');
    }
}
