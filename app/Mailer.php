<?php
declare(strict_types=1);

/**
 * Small SMTP sender (implicit TLS, AUTH LOGIN) for Gmail / any SMTP host. No dependencies.
 * Settings live in config/config.local.php under 'mail' (never in git). Gmail needs an App Password.
 */
final class Mailer
{
    /** Tests replace the transport: fn(string $to,string $subject,string $body): void. */
    public static $transport=null;

    private static function settings(): array
    {
        $m=$GLOBALS['config']['mail'] ?? [];
        return is_array($m)?$m:[];
    }

    public static function configured(): bool
    {
        if(self::$transport!==null) return true;
        $m=self::settings();
        return !empty($m['host']) && !empty($m['username']) && !empty($m['password']);
    }

    private static function clean(string $value): string { return trim(str_replace(["\r","\n"],' ',$value)); }

    public static function send(string $to,string $subject,string $body): void
    {
        $to=self::clean($to);
        if(!filter_var($to,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid recipient email address.');
        if(self::$transport!==null) { (self::$transport)($to,$subject,$body); return; }
        if(!self::configured()) throw new RuntimeException('Email is not set up. Add the mail settings to config/config.local.php.');
        $m=self::settings();
        $host=(string)$m['host']; $port=(int)($m['port'] ?? 465); $user=(string)$m['username']; $pass=(string)$m['password'];
        $from=self::clean((string)($m['from_email'] ?? $user)); $fromName=self::clean((string)($m['from_name'] ?? 'PMBSI HRIS'));
        $context=stream_context_create(['ssl'=>array_filter(['verify_peer'=>true,'verify_peer_name'=>true,'cafile'=>$m['cafile'] ?? null])]);
        $socket=@stream_socket_client('ssl://'.$host.':'.$port,$errno,$error,15,STREAM_CLIENT_CONNECT,$context);
        if(!$socket) throw new RuntimeException('Could not connect to the mail server ('.($error?:'check host / port / internet').').');
        stream_set_timeout($socket,15);
        try {
            self::expect($socket,[220]);
            self::command($socket,'EHLO hris.local',[250]);
            self::command($socket,'AUTH LOGIN',[334]);
            self::command($socket,base64_encode($user),[334]);
            self::command($socket,base64_encode($pass),[235]);
            self::command($socket,'MAIL FROM:<'.$from.'>',[250]);
            self::command($socket,'RCPT TO:<'.$to.'>',[250,251]);
            self::command($socket,'DATA',[354]);
            $headers=[
                'From: =?UTF-8?B?'.base64_encode($fromName).'?= <'.$from.'>','To: <'.$to.'>',
                'Subject: =?UTF-8?B?'.base64_encode(self::clean($subject)).'?=','Date: '.date('r'),'Message-ID: <'.bin2hex(random_bytes(12)).'@hris.local>',
                'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64',
            ];
            self::command($socket,implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n").".",[250]);
            self::command($socket,'QUIT',[221]);
        } finally { fclose($socket); }
    }

    private static function command($socket,string $line,array $ok): void { fwrite($socket,$line."\r\n"); self::expect($socket,$ok); }

    private static function expect($socket,array $ok): void
    {
        $reply='';
        while(($line=fgets($socket,515))!==false) { $reply=$line; if(strlen($line)<4||$line[3]!=='-') break; }
        $code=(int)substr($reply,0,3);
        if(!in_array($code,$ok,true)) throw new RuntimeException('Mail server replied: '.mb_substr(trim($reply),0,160));
    }
}
