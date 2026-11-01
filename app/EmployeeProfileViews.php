<?php
declare(strict_types=1);

/** Markup for My Profile. Every dynamic value goes through e(). */
final class EmployeeProfileViews
{
    public static function empty(): string
    {
        return '<section class="panel myp-empty"><h2>Profile not available</h2><p>Your account is not linked to an employee record yet. Contact HR to link your Employee 201 file.</p></section>';
    }

    /** Label/value pairs as a definition grid; blank values read "Not provided". */
    public static function rows(array $pairs): string
    {
        $html = '';
        foreach ($pairs as [$label, $value]) {
            $html .= '<div><dt>'.e($label).'</dt><dd'.($value === '' ? ' class="myp-none"' : '').'>'.e($value === '' ? 'Not provided' : $value).'</dd></div>';
        }
        return '<dl class="myp-grid">'.$html.'</dl>';
    }

    /** Government IDs: masked by default; the full number is fetched on demand by employee-profile.js. */
    public static function ids(array $rows, string $csrf): string
    {
        if (!$rows) return '<p class="myp-none">No government IDs have been recorded yet.</p>';
        $html = '';
        foreach ($rows as $r) {
            $chip = $r['state'] === 'expired' ? '<span class="badge red">Expired</span>' : ($r['state'] === 'soon' ? '<span class="badge amber">Expires soon</span>' : '');
            $meta = array_filter([$r['issued'] !== '' ? 'Issued '.$r['issued'] : '', $r['expiry'] !== '' ? 'Expires '.$r['expiry'] : '']);
            $html .= '<li class="myp-id"><div class="myp-id-main"><span class="myp-id-type">'.e($r['label']).'</span>'
                .'<code class="myp-idnum" data-masked="'.e($r['masked']).'">'.e($r['masked'] !== '' ? $r['masked'] : 'Not provided').'</code></div>'
                .'<div class="myp-id-meta">'.e(implode(' · ', $meta)).$chip.'</div>'
                .($r['masked'] !== '' ? '<button type="button" class="btn sm myp-reveal" data-id="'.(int)$r['id'].'" aria-label="Show full '.e($r['label']).' number">Show</button>' : '')
                .'</li>';
        }
        return '<ul class="myp-idlist" data-url="'.e(url('employee-profile-reveal')).'" data-csrf="'.e($csrf).'">'.$html.'</ul><p class="myp-live" role="status" aria-live="polite"></p>'
            .'<p class="myp-hint">Numbers are hidden by default and hide again after 30 seconds. Each reveal is recorded.</p>';
    }

    /** Scanned documents: thumbnail grid with type filter and an image preview dialog. */
    public static function documents(array $docs): string
    {
        if (!$docs) return '<p class="myp-none">No documents have been uploaded to your file yet.</p>';
        $types = [];
        foreach ($docs as $d) { $types[$d['type_code']] = ['label' => $d['type'], 'n' => ($types[$d['type_code']]['n'] ?? 0) + 1]; }
        $chips = '<button type="button" class="myp-chip is-on" data-filter="all" aria-pressed="true">All <b>'.count($docs).'</b></button>';
        foreach ($types as $code => $t) $chips .= '<button type="button" class="myp-chip" data-filter="'.e($code).'" aria-pressed="false">'.e($t['label']).' <b>'.$t['n'].'</b></button>';
        $items = '';
        foreach ($docs as $d) {
            $view = e(url('employee-profile-document', ['id' => $d['id']]));
            $dl = e(url('employee-profile-document', ['id' => $d['id'], 'download' => 1]));
            $thumbUrl = e(url('employee-profile-document', ['id' => $d['id'], 'thumb' => 1]));
            $thumb = $d['kind'] === 'image'
                ? '<a class="myp-thumb" href="'.$view.'" target="_blank" rel="noopener" data-lightbox data-title="'.e($d['title']).'"><img loading="lazy" decoding="async" width="480" height="360" src="'.$thumbUrl.'" alt="'.e($d['title']).'"></a>'
                : '<a class="myp-thumb myp-thumb--file" href="'.$view.'" target="_blank" rel="noopener" aria-label="Open '.e($d['title']).'"><span class="myp-ext">'.e($d['ext'] !== '' ? $d['ext'] : 'FILE').'</span></a>';
            $items .= '<li class="myp-doc" data-type="'.e($d['type_code']).'">'.$thumb.'<div class="myp-docbody"><strong>'.e($d['title']).'</strong>'
                .'<span>'.e(implode(' · ', array_filter([$d['type'], $d['date'], $d['size']]))).'</span>'
                .'<div class="myp-docactions"><a href="'.$view.'" target="_blank" rel="noopener">View</a><a href="'.$dl.'" download>Download</a></div></div></li>';
        }
        return '<div class="myp-docs"><div class="myp-chips" role="group" aria-label="Filter documents by type">'.$chips.'</div><ul class="myp-docgrid">'.$items.'</ul>'
            .'<dialog class="myp-lightbox" aria-label="Document preview"><button type="button" class="myp-lb-close" aria-label="Close preview">&times;</button><img alt=""><p class="myp-lb-cap"></p></dialog></div>';
    }

    public static function card(string $title, string $body, bool $wide = false): string
    {
        return '<section class="panel myp-card'.($wide ? ' myp-card--wide' : '').'"><div class="panel-head"><div><h2>'.e($title).'</h2></div></div><div class="panel-body">'.$body.'</div></section>';
    }

    /** Anchor-based tabs: without JavaScript every panel simply stays visible and the links jump to it. */
    public static function tabs(array $panels): string
    {
        $nav = ''; $body = ''; $first = true;
        foreach ($panels as $id => [$label, $html]) {
            $nav .= '<a role="tab" id="myp-tab-'.e($id).'" href="#myp-'.e($id).'" aria-controls="myp-'.e($id).'" aria-selected="'.($first ? 'true' : 'false').'">'.e($label).'</a>';
            $body .= '<div role="tabpanel" id="myp-'.e($id).'" aria-labelledby="myp-tab-'.e($id).'" class="myp-panel"'.($first ? '' : ' hidden').'>'.$html.'</div>';
            $first = false;
        }
        return '<div class="myp-tabs"><nav class="myp-tablist" role="tablist" aria-label="Profile sections">'.$nav.'</nav>'.$body.'</div>';
    }

    public static function history(array $rows): string
    {
        if (!$rows) return '<p class="myp-none">No employment history has been recorded yet.</p>';
        $html = '';
        foreach ($rows as $r) {
            $html .= '<li><time>'.e($r['date']).'</time><strong>'.e($r['title']).'</strong>'.($r['detail'] !== '' ? '<span>'.e($r['detail']).'</span>' : '').'</li>';
        }
        return '<ol class="myp-timeline">'.$html.'</ol>';
    }

    public static function leave(array $rows): string
    {
        if (!$rows) return '<p class="myp-none">No leave credits have been assigned yet.</p>';
        $html = '';
        foreach ($rows as [$name, $balance]) $html .= '<div class="myp-credit"><strong>'.e($balance).'</strong><span>'.e($name).'</span></div>';
        return '<div class="myp-credits">'.$html.'</div>';
    }

    /** Full page body: hero + tabs. */
    public static function page(array $hero, array $personal, array $contact, array $employment, array $history, array $files = [], string $kind = 'employee', array $shortcuts = []): string
    {
        $contactHtml = self::card('Contact', self::rows($contact['contact'])).self::card('Address', self::rows($contact['address']));
        $emergency = '';
        foreach ($contact['emergency'] as $person) $emergency .= self::rows($person);
        $contactHtml .= self::card('Emergency contact', $emergency !== '' ? $emergency : '<p class="myp-none">No emergency contact has been recorded yet.</p>');
        $employmentHtml = self::card('Job details', self::rows($employment['job']))
            .self::card('Dates', self::rows($employment['dates']))
            .self::card('Pay rate', $employment['pay'] ? self::rows($employment['pay']) : '<p class="myp-none">No pay rate has been set up for you yet.</p>')
            .self::card('Leave credits', self::leave($employment['leave']))
            .self::card('Employment history', self::history($history), true);
        $note = $kind === 'employee' ? 'This page is read-only. To update your details, contact HR.' : 'This page is read-only. Profile data is maintained in Employee 201.';
        return self::hero($hero).self::shortcuts($shortcuts).self::tabs([
            'personal' => ['Personal', self::card('Personal details', self::rows($personal))],
            'contact' => ['Contact and Address', $contactHtml],
            'employment' => ['Employment', $employmentHtml],
            'files' => ['IDs and Documents', self::card('Government IDs', self::ids($files['ids'] ?? [], csrf_token()), true).self::card('Documents', self::documents($files['documents'] ?? []), true)],
        ]).'<noscript><style>.myp-panel[hidden]{display:grid!important}</style></noscript><p class="myp-note">'.e($note).'</p>';
    }

    /** Quick links row. Items that are not available yet are plain text with a Soon pill, never dead links. */
    public static function shortcuts(array $items): string
    {
        if (!$items) return '';
        $html = '';
        foreach ($items as $i) {
            if ($i['print']) $html .= '<li><button type="button" class="myp-shortcut" data-print>'.e($i['label']).'</button></li>';
            elseif ($i['state'] === 'soon') $html .= '<li><span class="myp-shortcut is-soon" aria-disabled="true">'.e($i['label']).'<em class="myp-soon">Soon</em></span></li>';
            else $html .= '<li><a class="myp-shortcut" href="'.e(url($i['page'])).'">'.e($i['label']).'</a></li>';
        }
        return '<nav class="myp-shortcuts" aria-label="Quick links"><ul>'.$html.'</ul></nav>';
    }

    public static function hero(array $h): string
    {
        $avatar = $h['has_photo']
            ? '<img class="myp-photo" width="88" height="88" src="'.e(url('employee-profile-photo')).'" alt="'.e($h['name']).'">'
            : '<span class="myp-avatar" aria-hidden="true">'.e(initials($h['name'])).'</span>';
        $facts = [];
        if ($h['employee_no'] !== '') $facts[] = ['Employee no.', $h['employee_no']];
        if ($h['company'] !== '') $facts[] = ['Company', $h['company']];
        if ($h['start'] !== '') $facts[] = ['Start date', $h['start']];
        if ($h['tenure'] !== '') $facts[] = ['Tenure', $h['tenure']];
        $factHtml = '';
        foreach ($facts as [$label, $value]) $factHtml .= '<div><dt>'.e($label).'</dt><dd>'.e($value).'</dd></div>';
        return '<section class="panel myp-hero">'.$avatar.'<div class="myp-hero-copy">'
            .'<div class="myp-title"><h2>'.e($h['name']).'</h2>'.($h['status'] !== '' ? '<span class="badge '.e($h['tone']).'">'.e($h['status']).'</span>' : '').'</div>'
            .'<p class="myp-role">'.e($h['position'] !== '' ? $h['position'] : 'Position not provided').'</p>'
            .($h['place'] !== '' ? '<p class="myp-place">'.e($h['place']).'</p>' : '')
            .'</div>'.($factHtml !== '' ? '<dl class="myp-facts">'.$factHtml.'</dl>' : '').'</section>';
    }
}
