<?php
declare(strict_types=1);

/** Pages behind the My Profile quick links. Plain panels and tables already used elsewhere; no new CSS or JS. */
final class PortalContentViews
{
    /** page => [title, empty-state text] for the three pay pages that wait for payroll computation. */
    public const PAY_PAGES = [
        'profile-payslip' => ['Payslip', 'Your payslips will appear here once payroll computation is released.'],
        'profile-deductions' => ['Deductions', 'Your payroll deductions will appear here once payroll computation is released.'],
        'profile-additional' => ['Additional', 'Bonuses, allowances, 13th month pay and other extra pay will appear here once payroll computation is released.'],
    ];

    public static function back(): string { return '<a class="btn" href="'.e(url('employee-profile')).'">Back to My Profile</a>'; }

    private static function removeForm(int $id, string $what): string
    {
        return '<form method="post" style="display:inline-block;margin-left:8px" onsubmit="return confirm(\'Remove this '.$what.' from the portal?\')">'.csrf_field()
            .'<input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="'.$id.'"><button class="btn danger-outline" type="submit">Remove</button></form>';
    }

    public static function payPage(string $page, bool $isHr): string
    {
        [$title, $text] = self::PAY_PAGES[$page];
        $note = $isHr ? '<p class="tiny muted">Nothing is entered by hand for now. HR will manage this page when the payroll computation patch is released.</p>' : '';
        return '<section class="panel"><div class="panel-head"><div><h2>'.e($title).'</h2><p>Nothing to show yet</p></div></div><div class="panel-body"><p>'.e($text).'</p>'.$note.'</div></section>';
    }

    public static function notReady(bool $manage): string
    {
        return $manage ? '<div class="alert error">Run the portal content database migration (20261031_018_portal_content.sql) to activate this page.</div>'
            : '<section class="panel"><div class="panel-body"><p>Nothing has been published here yet.</p></div></section>';
    }

    /** @param list<array<string,mixed>> $items */
    public static function downloads(array $items, bool $manage): string
    {
        $rows = '';
        foreach ($items as $d) {
            $id = (int)$d['id'];
            $rows .= '<tr><td><strong>'.e($d['title']).'</strong>'.(!empty($d['description']) ? '<div class="tiny muted">'.e($d['description']).'</div>' : '').'<div class="tiny muted">'.e($d['original_name']).'</div></td>'
                .'<td>'.e(PortalContentService::sizeLabel((int)$d['file_size'])).'</td><td>'.e(date('M j, Y', strtotime((string)$d['created_at']))).'</td>'
                .'<td><a class="btn" href="'.e(url('profile-download-file', ['id' => $id])).'">Download</a>'
                .($manage ? self::removeForm($id, 'file') : '')
                .'</td></tr>';
        }
        $list = $rows !== ''
            ? '<div class="phase3a-table-wrap"><table class="phase3a-table"><thead><tr><th>File</th><th>Size</th><th>Added</th><th></th></tr></thead><tbody>'.$rows.'</tbody></table></div>'
            : '<div class="panel-body"><p>No files have been published yet.</p></div>';
        $form = !$manage ? '' : '<section class="panel"><div class="panel-head"><div><h2>Add a file</h2><p>PDF, image, Word or Excel, up to 10 MB. Everyone signed in can download it.</p></div></div>'
            .'<form method="post" enctype="multipart/form-data" class="panel-body">'.csrf_field().'<input type="hidden" name="action" value="add">'
            .'<div class="field"><label for="dl_title">Title</label><input id="dl_title" name="title" maxlength="180"></div>'
            .'<div class="field"><label for="dl_desc">Description (optional)</label><input id="dl_desc" name="description" maxlength="500"></div>'
            .'<div class="field"><label for="dl_file">File</label><input id="dl_file" type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"></div>'
            .'<button class="btn primary" type="submit">Upload</button></form></section>';
        return $form.'<section class="panel"><div class="panel-head"><div><h2>Downloads</h2><p>Forms and documents from HR</p></div></div>'.$list.'</section>';
    }

    /** @param list<array<string,mixed>> $items */
    public static function faqs(array $items, bool $manage): string
    {
        $out = '';
        foreach ($items as $f) {
            $id = (int)$f['id'];
            $out .= '<details class="panel"><summary class="panel-head"><div><h2>'.e($f['question']).'</h2></div></summary><div class="panel-body"><p>'.nl2br(e($f['answer'])).'</p>';
            if ($manage) {
                $out .= '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="'.$id.'">'
                    .'<div class="field"><label for="fq'.$id.'">Question</label><input id="fq'.$id.'" name="question" maxlength="300" required value="'.e($f['question']).'"></div>'
                    .'<div class="field"><label for="fa'.$id.'">Answer</label><textarea id="fa'.$id.'" name="answer" rows="4" maxlength="4000" required>'.e($f['answer']).'</textarea></div>'
                    .'<div class="field"><label for="fs'.$id.'">Order</label><input id="fs'.$id.'" type="number" name="sort_order" min="0" max="9999" value="'.(int)$f['sort_order'].'"></div>'
                    .'<button class="btn primary" type="submit">Save</button></form>'
                    .self::removeForm($id, 'FAQ');
            }
            $out .= '</div></details>';
        }
        if ($out === '') $out = '<section class="panel"><div class="panel-body"><p>No FAQs have been published yet.</p></div></section>';
        $form = !$manage ? '' : '<section class="panel"><div class="panel-head"><div><h2>Add an FAQ</h2><p>Lower numbers appear first.</p></div></div>'
            .'<form method="post" class="panel-body">'.csrf_field().'<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">'
            .'<div class="field"><label for="faq_q">Question</label><input id="faq_q" name="question" maxlength="300" required></div>'
            .'<div class="field"><label for="faq_a">Answer</label><textarea id="faq_a" name="answer" rows="4" maxlength="4000" required></textarea></div>'
            .'<div class="field"><label for="faq_s">Order</label><input id="faq_s" type="number" name="sort_order" min="0" max="9999" value="100"></div>'
            .'<button class="btn primary" type="submit">Add FAQ</button></form></section>';
        return $form.$out;
    }
}
