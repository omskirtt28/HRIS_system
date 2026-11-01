<?php
declare(strict_types=1);

/** HR-managed Downloads and FAQs behind the My Profile quick links. Everyone signed in reads; employees.manage writes. */
final class PortalContentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const EXT_MIME = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    public static function ready(): bool
    {
        return FoundationRepository::tableExists('portal_downloads') && FoundationRepository::tableExists('portal_faqs');
    }

    public static function canManage(): bool { return Auth::can('employees.manage'); }

    public static function storageDir(): string
    {
        $dir = dirname(__DIR__).'/storage/portal_downloads';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not prepare the upload folder.');
        return $dir;
    }

    /** @return list<array<string,mixed>> */
    public static function downloads(bool $includeHidden = false): array
    {
        if (!self::ready()) return [];
        return db()->query('SELECT id,title,description,original_name,mime_type,file_size,active,created_at FROM portal_downloads'.($includeHidden ? '' : ' WHERE active=1').' ORDER BY created_at DESC,id DESC')->fetchAll();
    }

    public static function download(int $id): ?array
    {
        if (!self::ready()) return null;
        $st = db()->prepare('SELECT * FROM portal_downloads WHERE id=? AND active=1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @param array{name?:string,tmp_name?:string,size?:int,error?:int} $file */
    public static function addDownload(array $file, string $title, string $description): int
    {
        if (!self::ready()) throw new RuntimeException('Run the portal content migration first.');
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a file to upload.');
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('File upload failed.');
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) throw new RuntimeException('File must be 10 MB or smaller.');
        $original = mb_substr(basename((string)($file['name'] ?? 'file')), 0, 255);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::EXT_MIME[$ext])) throw new RuntimeException('Allowed files: PDF, JPG, PNG, WEBP, DOC/DOCX, XLS/XLSX.');
        $mime = (string)((new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: 'application/octet-stream');
        if (!in_array($mime, self::EXT_MIME[$ext], true)) throw new RuntimeException('The file content does not match its type.');
        $title = mb_substr(trim($title) !== '' ? trim($title) : pathinfo($original, PATHINFO_FILENAME), 0, 180);
        $description = mb_substr(trim($description), 0, 500);
        $stored = 'dl_'.bin2hex(random_bytes(16)).'.'.$ext;
        $dir = self::storageDir();
        if (!move_uploaded_file((string)$file['tmp_name'], $dir.'/'.$stored)) throw new RuntimeException('Could not save the uploaded file.');
        try {
            $st = db()->prepare('INSERT INTO portal_downloads(title,description,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?,?)');
            $st->execute([$title, $description !== '' ? $description : null, $original, $stored, $mime, $size, (int)(Auth::user()['id'] ?? 0) ?: null]);
        } catch (Throwable $e) { @unlink($dir.'/'.$stored); throw $e; }
        $id = (int)db()->lastInsertId();
        audit('Portal','ADD_DOWNLOAD','portal_download',$id,['title'=>$title,'name'=>$original]);
        return $id;
    }

    /** Hide only; the file stays on disk so nothing is lost. */
    public static function removeDownload(int $id): bool { return self::hide('portal_downloads', 'REMOVE_DOWNLOAD', 'portal_download', $id); }

    /** $table is always one of this class's own two table names, never user input. */
    private static function hide(string $table, string $action, string $recordType, int $id): bool
    {
        if (!self::ready()) return false;
        $st = db()->prepare('UPDATE '.$table.' SET active=0 WHERE id=? AND active=1');
        $st->execute([$id]);
        if ($st->rowCount() !== 1) return false;
        audit('Portal', $action, $recordType, $id);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public static function faqs(): array
    {
        if (!self::ready()) return [];
        return db()->query('SELECT id,question,answer,sort_order FROM portal_faqs WHERE active=1 ORDER BY sort_order,id')->fetchAll();
    }

    /** $id 0 adds. Returns the FAQ id. */
    public static function saveFaq(int $id, string $question, string $answer, int $sortOrder): int
    {
        if (!self::ready()) throw new RuntimeException('Run the portal content migration first.');
        $question = trim($question); $answer = trim($answer);
        if ($question === '' || $answer === '') throw new RuntimeException('Enter both the question and the answer.');
        if (mb_strlen($question) > 300) throw new RuntimeException('The question can be at most 300 characters.');
        if (mb_strlen($answer) > 4000) throw new RuntimeException('The answer can be at most 4000 characters.');
        $sortOrder = max(0, min(9999, $sortOrder));
        $uid = (int)(Auth::user()['id'] ?? 0) ?: null;
        if ($id > 0) {
            $st = db()->prepare('UPDATE portal_faqs SET question=?,answer=?,sort_order=? WHERE id=? AND active=1');
            $st->execute([$question, $answer, $sortOrder, $id]);
            $exists = db()->prepare('SELECT COUNT(*) FROM portal_faqs WHERE id=? AND active=1'); $exists->execute([$id]);
            if ((int)$exists->fetchColumn() !== 1) throw new RuntimeException('FAQ not found.');
            audit('Portal','UPDATE_FAQ','portal_faq',$id);
            return $id;
        }
        $st = db()->prepare('INSERT INTO portal_faqs(question,answer,sort_order,created_by) VALUES(?,?,?,?)');
        $st->execute([$question, $answer, $sortOrder, $uid]);
        $id = (int)db()->lastInsertId();
        audit('Portal','ADD_FAQ','portal_faq',$id);
        return $id;
    }

    public static function removeFaq(int $id): bool { return self::hide('portal_faqs', 'REMOVE_FAQ', 'portal_faq', $id); }

    public static function sizeLabel(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int)round($bytes / 1024)).' KB';
    }
}
