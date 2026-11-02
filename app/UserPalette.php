<?php
declare(strict_types=1);
/** HR appearance palettes (SPEC-hr-appearance-palettes.md). Only the HR portal role may choose one; Admin and others keep the Light/Dark toggle. */
final class UserPalette {
    const KEYS=['light','dark','beige','earth','pastel','pink'];

    const LABELS=['light'=>'Light','dark'=>'Dark','beige'=>'Soft Beige','earth'=>'Earth Tones','pastel'=>'Soft Pastel','pink'=>'Soft Pink'];

    public static function isValid(string $key): bool { return in_array($key,self::KEYS,true); }

    public static function normalize(string $key): string { return self::isValid($key)?$key:'light'; }

    /** Attributes for the <html> tag so the saved palette is there before first paint. Light is the base for every palette. */
    public static function htmlAttrs(string $key): string {
        $k=self::normalize($key);
        return ' data-theme="'.($k==='dark'?'dark':'light').'"'.(!in_array($k,['light','dark'],true)?' data-palette="'.$k.'"':'').' data-palette-user';
    }

    public static function canChoose(array $user): bool { return ($user['role_portal']??null)==='hr'; }

    /** The saved palette for an HR user, or '' (use the browser's Light/Dark toggle). */
    public static function read(array $user): string {
        if(!self::canChoose($user)) return '';
        $q=db()->prepare('SELECT ui_palette FROM users WHERE id=?'); $q->execute([(int)($user['id']??0)]);
        $key=(string)($q->fetchColumn()?:'');
        return self::isValid($key)?$key:'';
    }

    public static function save(array $user,string $key): bool {
        if(!self::canChoose($user)||!self::isValid($key)) return false;
        $q=db()->prepare('UPDATE users SET ui_palette=? WHERE id=?'); $q->execute([$key,(int)($user['id']??0)]);
        return true;
    }
}
