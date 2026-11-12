<?php
defined('FF_UP') || define('FF_UP', 'https://bdris.gov.bd');
defined('FF_TTL') || define('FF_TTL', 1500);
defined('FF_CACHE') || define('FF_CACHE', __DIR__ . '/cache/');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$ffHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('bdrisform');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $ffHttps,
    ]);
    @session_start();
}

function ff_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ff_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ff_wants_json(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
}

function ff_input(): array
{
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ct, 'application/json')) {
        $raw = file_get_contents('php://input');
        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : [];
    }
    return $_POST;
}

function ff_val(array $a, string $k, string $d = ''): string
{
    $v = $a[$k] ?? $d;
    return is_scalar($v) ? trim((string)$v) : $d;
}

function ff_post(string $k, string $d = ''): string
{
    return ff_val($_POST, $k, $d);
}

function ff_sid(): string
{
    $sid = (string)($_SESSION['ff_sid'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) {
        $sid = bin2hex(random_bytes(16));
        $_SESSION['ff_sid'] = $sid;
    }
    return $sid;
}

function ff_state_path(string $sid): string
{
    return FF_CACHE . 'ff/' . $sid . '.json';
}

function ff_jar_path(string $sid): string
{
    return FF_CACHE . 'ff/' . $sid . '.jar';
}

function ff_lock_path(string $sid): string
{
    return FF_CACHE . 'ff/' . $sid . '.lock';
}

function ff_prepare(): void
{
    $dir = FF_CACHE . 'ff/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function ff_lock(string $sid)
{
    ff_prepare();
    $h = @fopen(ff_lock_path($sid), 'c+');
    if ($h === false) {
        throw new RuntimeException('Cache directory is not writable');
    }
    @flock($h, LOCK_EX);
    return $h;
}

function ff_unlock($h): void
{
    if ($h) {
        @flock($h, LOCK_UN);
        @fclose($h);
    }
}

function ff_state_default(): array
{
    return [
        'token' => '',
        'header' => 'X-CSRF-TOKEN',
        'fetched_at' => 0,
        'attachments' => [],
        'verified' => false,
        'otp_phone' => '',
        'verified_at' => 0,
        'otp_code' => '',
        'types_dob' => '',
        'required_types' => [],
    ];
}

function ff_state(string $sid): array
{
    $p = ff_state_path($sid);
    $base = ff_state_default();
    if (!is_file($p)) {
        return $base;
    }
    $j = json_decode((string)@file_get_contents($p), true);
    if (!is_array($j)) {
        return $base;
    }
    $j = array_merge($base, $j);
    if (!is_array($j['attachments'])) {
        $j['attachments'] = [];
    }
    if (!is_array($j['required_types'])) {
        $j['required_types'] = [];
    }
    return $j;
}

function ff_state_save(string $sid, array $state): void
{
    ff_prepare();
    $p = ff_state_path($sid);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $tmp = $p . '.tmp';
    if (@file_put_contents($tmp, $json) !== false) {
        @rename($tmp, $p);
    } else {
        @file_put_contents($p, $json);
    }
    @chmod($p, 0600);
}

function ff_http(string $sid, string $url, $body, array $headers, bool $follow = false): array
{
    ff_prepare();
    $base = [
        'Accept-Language: en-US,en;q=0.9,bn;q=0.8',
        'User-Agent: Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 75,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_COOKIEJAR => ff_jar_path($sid),
        CURLOPT_COOKIEFILE => ff_jar_path($sid),
        CURLOPT_HTTPHEADER => array_merge($base, $headers),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ];
    if ($body !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $msg = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('BDRIS connection failed: ' . $msg);
    }
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $head = substr($raw, 0, $hsize);
    $resBody = substr($raw, $hsize);
    $redirect = '';
    if (preg_match('/^Location:\s*(.+?)\s*$/mi', $head, $m)) {
        $redirect = trim($m[1]);
    }
    return [
        'status' => $status,
        'head' => $head,
        'body' => $resBody,
        'redirect' => $redirect,
        'url' => $effective,
    ];
}

function ff_abs(string $loc): string
{
    if ($loc === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $loc)) {
        return $loc;
    }
    if (str_starts_with($loc, '//')) {
        return 'https:' . $loc;
    }
    if (str_starts_with($loc, '/')) {
        return FF_UP . $loc;
    }
    return FF_UP . '/' . ltrim($loc, '/');
}

function ff_is_login(string $url): bool
{
    $u = strtolower($url);
    return str_contains($u, '/login') || str_contains($u, 'signin');
}

function ff_ensure(string $sid, array $state, bool $force = false): array
{
    $jar = ff_jar_path($sid);
    if (!$force && $state['token'] !== '' && (time() - (int)$state['fetched_at']) < FF_TTL && is_file($jar)) {
        return $state;
    }
    $docHeaders = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1',
    ];
    ff_http($sid, FF_UP . '/', null, $docHeaders);
    $pageHeaders = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1',
        'Referer: ' . FF_UP . '/',
    ];
    $page = ff_http($sid, FF_UP . '/br/application', null, $pageHeaders);
    $html = (string)$page['body'];
    if (str_contains($html, 'Please type on browser')) {
        ff_http($sid, FF_UP . '/', null, $docHeaders);
        $page = ff_http($sid, FF_UP . '/br/application', null, $pageHeaders);
        $html = (string)$page['body'];
    }
    if (ff_is_login((string)$page['redirect'])) {
        throw new RuntimeException('BDRIS session could not be established');
    }
    $token = '';
    $header = 'X-CSRF-TOKEN';
    $patterns = [
        '~<meta[^>]+name=["\']_csrf["\'][^>]+content=["\']([^"\']+)["\']~i',
        '~<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']_csrf["\']~i',
        '~<input[^>]+name=["\']_csrf["\'][^>]+value=["\']([^"\']+)["\']~i',
        '~<input[^>]+value=["\']([^"\']+)["\'][^>]+name=["\']_csrf["\']~i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $html, $m)) {
            $token = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            break;
        }
    }
    if (preg_match('~<meta[^>]+name=["\']_csrf_headerName["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
        $h = trim($m[1]);
        if ($h !== '') {
            $header = $h;
        }
    }
    if ($token === '') {
        throw new RuntimeException('BDRIS session token not found');
    }
    $state['token'] = $token;
    $state['header'] = $header;
    $state['fetched_at'] = time();
    ff_state_save($sid, $state);
    return $state;
}

function ff_plain(string $html): string
{
    $html = preg_replace('~<(script|style|noscript)[^>]*>.*?</\1>~is', ' ', $html) ?? '';
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return trim($text);
}

function ff_digits(string $v): string
{
    $map = [
        '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
        '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
    ];
    return strtr($v, $map);
}

function ff_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) === 13 && str_starts_with($d, '880')) {
        $d = '0' . substr($d, 3);
    }
    if (strlen($d) !== 11 || $d[0] !== '0') {
        return '';
    }
    return substr($d, 0, 5) . '-' . substr($d, 5);
}

function ff_phone_display(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) === 13 && str_starts_with($d, '880')) {
        $d = '0' . substr($d, 3);
    }
    if (strlen($d) === 11) {
        return substr($d, 0, 5) . ' ' . substr($d, 5);
    }
    return $d;
}

function ff_date(string $raw): string
{
    $v = ff_digits(trim($raw));
    if (!preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $v, $m)) {
        return '';
    }
    $d = (int)$m[1];
    $mo = (int)$m[2];
    $y = (int)$m[3];
    if (!checkdate($mo, $d, $y)) {
        return '';
    }
    return sprintf('%02d/%02d/%04d', $d, $mo, $y);
}

function ff_extract_errors(string $body): string
{
    $messages = [];
    if (class_exists('DOMDocument')) {
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        @$d->loadHTML('<?xml encoding="utf-8" ?>' . $body);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $x = new DOMXPath($d);
        $q = "//*[contains(concat(' ', normalize-space(@class), ' '), ' alert-danger ') or contains(concat(' ', normalize-space(@class), ' '), ' invalid-feedback ') or contains(concat(' ', normalize-space(@class), ' '), ' toast-message ') or contains(concat(' ', normalize-space(@class), ' '), ' swal2-content ')]";
        foreach ($x->query($q) as $node) {
            $t = ff_plain((string)$d->saveHTML($node));
            if ($t !== '' && !in_array($t, $messages, true)) {
                $messages[] = mb_substr($t, 0, 220);
            }
        }
    }
    if (!$messages) {
        $t = ff_plain($body);
        if ($t !== '') {
            $messages[] = mb_substr($t, 0, 280);
        }
    }
    return implode(' | ', array_slice($messages, 0, 4));
}

function ff_extract_success(string $body, string $url): array
{
    $plain = ff_plain($body);
    $app = '';
    foreach ([
        '~আবেদনপত্র\s*নম্বর\s*[:：]?\s*([0-9০-৯]{3,})~u',
        '~আবেদন\s*নম্বর\s*[:：]?\s*([0-9০-৯]{3,})~u',
        '~Application\s*(?:No|Number)\.?\s*[:：]?\s*([0-9]{3,})~i',
    ] as $p) {
        if (preg_match($p, $plain, $m)) {
            $app = ff_digits($m[1]);
            break;
        }
    }
    if ($app === '') {
        if (preg_match('~/(?:success|saved|view|application)/([0-9]{5,})~i', $url, $m)) {
            $app = $m[1];
        }
    }
    $office = '';
    foreach ([
        '~কার্যাল[য়য়]ের?\s*নাম[^:：]{0,30}[:：]\s*(.{3,200}?)(?=\s*(?:নির্ধারিত|ভবিষ্যত|আবেদনপত্র|জমা\s*দেওয়|নিচের|আপনাকে|$))~u',
        '~কার্যাল[য়য়]\s*[:：]\s*(.{3,200}?)(?=\s*(?:জমা\s*দেওয়|আবেদনপত্র|নির্ধারিত|ভবিষ্যত|$))~u',
    ] as $p) {
        if (preg_match($p, $plain, $m)) {
            $office = trim(preg_replace('/^[\s\-–—]+/u', '', $m[1]));
            break;
        }
    }
    $deadline = '';
    foreach ([
        '~আগামী\s*([0-9০-৯]{1,4}[\/.\-][0-9০-৯]{1,2}[\/.\-][0-9০-৯]{2,4})~u',
        '~([0-9০-৯]{1,4}[\/.\-][0-9০-৯]{1,2}[\/.\-][0-9০-৯]{2,4})\s*তারিখের\s*মধ্যে~u',
        '~শেষ\s*তারিখ\s*[:：]?\s*([0-9০-৯]{1,4}[\/.\-][0-9০-৯]{1,2}[\/.\-][0-9০-৯]{2,4})~u',
    ] as $p) {
        if (preg_match($p, $plain, $m)) {
            $deadline = ff_digits($m[1]);
            break;
        }
    }
    return [$app, $office, $deadline, $plain];
}

function ff_normalize_names($in): array
{
    $keys = [
        'personInfoForBirth.personNameBn',
        'personInfoForBirth.personNameEn',
        'personInfoForBirth.father.personNameBn',
        'personInfoForBirth.father.personNameEn',
        'personInfoForBirth.mother.personNameBn',
        'personInfoForBirth.mother.personNameEn',
    ];
    $src = is_array($in) ? $in : [];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = ff_val($src, $k);
    }
    return $out;
}

function ff_relation(string $v): string
{
    $ok = ['SELF', 'FATHER', 'MOTHER', 'GRANDFATHER', 'GRANDMOTHER', 'MATERNALGRANDFATHER', 'MATERNALGRANDMOTHER', 'GUARDIAN', 'OTHERS'];
    return in_array($v, $ok, true) ? $v : '';
}

function ff_action_geo(string $sid, array $state): array
{
    $in = ff_input();
    $parent = (int)ff_val($in, 'parent');
    $order = (int)ff_val($in, 'order');
    $type = (int)ff_val($in, 'type');
    $ward = ff_val($in, 'ward') === '1' || ff_val($in, 'ward') === 'true';
    if ($parent <= 0 || $order < 1 || $type < 1) {
        return ['ok' => false, 'message' => 'অবস্থান তথ্য সঠিক নয়'];
    }
    $state = ff_ensure($sid, $state);
    $q = http_build_query([
        'geoGroup' => 'birthPlace',
        'geoOrder' => $order,
        'geoType' => $type,
        'ward' => $ward ? 'true' : 'false',
    ]);
    $url = FF_UP . '/v1/api/geo/parentGeoIdWithGeoGroupAndGeoOrder/' . $parent . '?' . $q;
    $headers = [
        'client: bris',
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
        $state['header'] . ': ' . $state['token'],
        'Referer: ' . FF_UP . '/br/application',
    ];
    $r = ff_http($sid, $url, null, $headers);
    if (ff_is_login((string)$r['redirect'])) {
        $state['token'] = '';
        $state['fetched_at'] = 0;
        $state = ff_ensure($sid, $state, true);
        $headers[2] = $state['header'] . ': ' . $state['token'];
        $r = ff_http($sid, $url, null, $headers);
    }
    $j = json_decode((string)$r['body'], true);
    if (!is_array($j) || !isset($j['geoObject']) || !is_array($j['geoObject'])) {
        return ['ok' => false, 'message' => 'অবস্থান তালিকা আনা যায়নি'];
    }
    $items = [];
    foreach ($j['geoObject'] as $g) {
        if (!is_array($g) || empty($g['id'])) {
            continue;
        }
        $items[] = [
            'id' => (int)$g['id'],
            'nameBn' => (string)($g['nameBn'] ?? ''),
            'nameEn' => (string)($g['nameEn'] ?? ''),
            'level' => (int)($g['geoLevelId'] ?? 0),
            'parent' => (int)($g['parentGeoId'] ?? 0),
        ];
    }
    return ['ok' => true, 'items' => $items];
}

function ff_action_types(string $sid, array $state): array
{
    $in = ff_input();
    $dob = ff_date(ff_val($in, 'dob'));
    if ($dob === '') {
        return ['ok' => false, 'message' => 'জন্ম তারিখ সঠিকভাবে দিন'];
    }
    $state = ff_ensure($sid, $state);
    $url = FF_UP . '/api/br/attachment-types-by-dob?dob=' . urlencode($dob);
    $r = ff_http($sid, $url, null, [
        'client: bris',
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
        $state['header'] . ': ' . $state['token'],
        'Referer: ' . FF_UP . '/br/application',
    ]);
    $j = json_decode((string)$r['body'], true);
    if (!is_array($j)) {
        return ['ok' => false, 'message' => 'সংযুক্তির ধরন আনা যায়নি'];
    }
    $items = [];
    $ids = [];
    foreach ($j as $t) {
        if (!is_array($t) || empty($t['id'])) {
            continue;
        }
        $id = (int)$t['id'];
        $ids[] = $id;
        $items[] = [
            'id' => $id,
            'nameBn' => preg_replace('/\s+/u', ' ', (string)($t['attachmentNameBn'] ?? '')) ?: ('সংযুক্তি #' . $id),
            'nameEn' => preg_replace('/\s+/u', ' ', (string)($t['attachmentNameEn'] ?? '')) ?: ('Attachment #' . $id),
        ];
    }
    $state['types_dob'] = $dob;
    $state['required_types'] = $ids;
    ff_state_save($sid, $state);
    return ['ok' => true, 'items' => $items, 'required' => $ids];
}

function ff_action_parent_info(string $sid, array $state): array
{
    $in = ff_input();
    $type = ff_val($in, 'type') === 'mother' ? 'mother' : 'father';
    $label = $type === 'father' ? 'পিতার' : 'মাতার';
    $ubrn = preg_replace('/\D+/', '', ff_digits(ff_val($in, 'ubrn'))) ?? '';
    $dob = ff_date(ff_val($in, 'dob'));
    $nameEn = ff_val($in, 'nameEn');
    $childDob = ff_date(ff_val($in, 'childDob'));
    if (strlen($ubrn) !== 17) {
        return ['ok' => false, 'message' => '১৭ ডিজিটের জন্ম নিবন্ধন নম্বর দিন'];
    }
    if ($dob === '') {
        return ['ok' => false, 'message' => $label . ' জন্ম তারিখ সঠিকভাবে দিন'];
    }
    if ($nameEn === '') {
        return ['ok' => false, 'message' => $label . ' নাম ইংরেজিতে দিন'];
    }
    if ($childDob === '') {
        return ['ok' => false, 'message' => 'আগে শিশুর জন্ম তারিখ দিন'];
    }
    $state = ff_ensure($sid, $state);
    $post = http_build_query([
        'ubrn' => $ubrn,
        'dob' => $dob,
        'nameEn' => $nameEn,
        'childBirthDate' => $childDob,
        'gender' => $type === 'father' ? 'MALE' : 'FEMALE',
    ]);
    $headers = [
        'client: bris',
        'Accept: application/json, text/plain, */*',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        $state['header'] . ': ' . $state['token'],
        'Origin: ' . FF_UP,
        'Referer: ' . FF_UP . '/br/application',
    ];
    $r = ff_http($sid, FF_UP . '/api/br/parent-info', $post, $headers);
    if (ff_is_login((string)$r['redirect'])) {
        $state['token'] = '';
        $state['fetched_at'] = 0;
        $state = ff_ensure($sid, $state, true);
        $headers[4] = $state['header'] . ': ' . $state['token'];
        $r = ff_http($sid, FF_UP . '/api/br/parent-info', $post, $headers);
    }
    $j = json_decode((string)$r['body'], true);
    if (!is_array($j)) {
        $msg = ff_extract_errors((string)$r['body']);
        return ['ok' => false, 'message' => $msg !== '' ? $msg : 'পিতামাতার তথ্য যাচাই করা যায়নি'];
    }
    if ($j === []) {
        return ['ok' => false, 'message' => 'এই জন্ম নিবন্ধন নম্বর ও জন্ম তারিখে কোনো রেকর্ড পাওয়া যায়নি'];
    }
    if (count($j) > 1) {
        return ['ok' => false, 'message' => 'একাধিক রেকর্ড পাওয়া গেছে, নম্বর ও জন্ম তারিখ যাচাই করুন'];
    }
    $p = is_array($j[0]) ? $j[0] : [];
    $expected = $type === 'father' ? 0 : 1;
    if (isset($p['gender']) && (int)$p['gender'] !== $expected) {
        return ['ok' => false, 'message' => $label . ' রেকর্ডের লিঙ্গ মেলেনি'];
    }
    $nameBn = trim((string)($p['personNameBn'] ?? ''));
    $nameEnOut = trim((string)($p['personNameEn'] ?? ''));
    if ($nameBn === '' && $nameEnOut === '') {
        return ['ok' => false, 'message' => 'রেকর্ডে নাম পাওয়া যায়নি'];
    }
    return [
        'ok' => true,
        'message' => $label . ' তথ্য যাচাই হয়েছে',
        'nameBn' => $nameBn,
        'nameEn' => $nameEnOut,
    ];
}

function ff_action_upload(string $sid, array $state): array
{
    $type = (int)($_POST['attachmentType'] ?? 0);
    $typeName = trim((string)($_POST['attachmentName'] ?? ''));
    if ($type <= 0) {
        return ['ok' => false, 'message' => 'সংযুক্তির ধরন নির্বাচন করুন'];
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        return ['ok' => false, 'message' => 'ফাইল নির্বাচন করুন'];
    }
    $f = $_FILES['file'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'ফাইল আপলোডে সমস্যা হয়েছে'];
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'ফাইলের আকার ৫ মেগাবাইটের মধ্যে হতে হবে'];
    }
    $mime = '';
    if (function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($tmp);
    }
    if ($mime === '') {
        $mime = (string)($f['type'] ?? '');
    }
    $allowed = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!in_array($mime, $allowed, true)) {
        return ['ok' => false, 'message' => 'শুধু JPG, PNG বা PDF ফাইল দিন'];
    }
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string)($f['name'] ?? 'file'))) ?: 'file';
    $state = ff_ensure($sid, $state);
    $post = [
        '_csrf' => $state['token'],
        'attachmentType' => (string)$type,
        'attachmentSubType' => '-1',
        'files' => new CURLFile($tmp, $mime, $name),
    ];
    $r = ff_http($sid, FF_UP . '/admin/doc/upload', $post, [
        'client: bris',
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
        $state['header'] . ': ' . $state['token'],
        'Referer: ' . FF_UP . '/br/application',
    ]);
    $j = json_decode((string)$r['body'], true);
    $item = is_array($j) && isset($j['files'][0]) && is_array($j['files'][0]) ? $j['files'][0] : null;
    if (!$item || empty($item['id'])) {
        $msg = ff_extract_errors((string)$r['body']);
        return ['ok' => false, 'message' => $msg !== '' ? $msg : 'সংযুক্তি আপলোড হয়নি'];
    }
    $id = (string)$item['id'];
    foreach ($state['attachments'] as $aid => $meta) {
        if ((int)($meta['type'] ?? 0) === $type) {
            unset($state['attachments'][$aid]);
        }
    }
    $state['attachments'][$id] = [
        'id' => $id,
        'type' => $type,
        'typeName' => mb_substr($typeName !== '' ? $typeName : ('সংযুক্তি #' . $type), 0, 220),
        'name' => $name,
        'size' => $size,
        'url' => (string)($item['url'] ?? ''),
    ];
    ff_state_save($sid, $state);
    return ['ok' => true, 'item' => $state['attachments'][$id], 'items' => array_values($state['attachments'])];
}

function ff_action_remove(string $sid, array $state): array
{
    $in = ff_input();
    $id = ff_val($in, 'id');
    if ($id === '') {
        return ['ok' => false, 'message' => 'সংযুক্তি পাওয়া যায়নি'];
    }
    if (isset($state['attachments'][$id])) {
        unset($state['attachments'][$id]);
        ff_state_save($sid, $state);
    }
    return ['ok' => true, 'items' => array_values($state['attachments'])];
}

function ff_otp_context(array $in): array
{
    $phone = ff_phone(ff_val($in, 'phone'));
    $relation = ff_relation(ff_val($in, 'relation'));
    $names = ff_normalize_names($in['names'] ?? []);
    $missing = [];
    foreach ($names as $k => $v) {
        if ($v === '') {
            $missing[] = $k;
        }
    }
    if ($phone === '') {
        return ['error' => 'সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন'];
    }
    if ($relation === '') {
        return ['error' => 'আবেদনকারীর সম্পর্ক নির্বাচন করুন'];
    }
    if ($missing) {
        return ['error' => 'শিশু ও পিতা-মাতার নামের সব ঘর পূরণ করুন'];
    }
    $applicantName = ff_val($in, 'applicantName');
    if ($applicantName === '') {
        return ['error' => 'আবেদনকারীর নাম দিন'];
    }
    return [
        'phone' => $phone,
        'relation' => $relation,
        'names' => $names,
        'email' => ff_val($in, 'email'),
        'applicantName' => $applicantName,
        'applicantBrn' => ff_val($in, 'applicantBrn'),
        'applicantDob' => ff_val($in, 'applicantDob'),
        'geoLocationId' => (int)ff_val($in, 'geoLocationId'),
    ];
}

function ff_otp_query(array $c, string $withOtp = '', string $otp = ''): string
{
    $q = [
        'appType' => 'BIRTH_REGISTRATION_APPLICATION',
        'phone' => $c['phone'],
        'email' => $c['email'],
        'officeId' => '0',
        'personUbrn' => '',
        'relation' => $c['relation'],
        'applicantName' => $c['applicantName'],
        'applicantBrn' => $c['applicantBrn'],
        'applicantDob' => $withOtp !== '' ? ff_date($c['applicantDob']) : $c['applicantDob'],
        'officeAddressType' => 'BIRTHPLACE',
    ];
    if ($withOtp !== '') {
        $q['otp'] = $otp;
        $q['geoLocationId'] = (string)$c['geoLocationId'];
    }
    return http_build_query($q);
}

function ff_action_send_otp(string $sid, array $state): array
{
    $in = ff_input();
    $c = ff_otp_context($in);
    if (isset($c['error'])) {
        return ['ok' => false, 'message' => $c['error']];
    }
    $state = ff_ensure($sid, $state);
    $url = FF_UP . '/api/otp/sent?' . ff_otp_query($c);
    $r = ff_http($sid, $url, http_build_query($c['names']), [
        'client: bris',
        'Accept: application/json, text/plain, */*',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        $state['header'] . ': ' . $state['token'],
        'Origin: ' . FF_UP,
        'Referer: ' . FF_UP . '/br/application',
    ]);
    $j = json_decode((string)$r['body'], true);
    $okHttp = $r['status'] >= 200 && $r['status'] < 300;
    $flag = null;
    $message = '';
    if (is_array($j)) {
        $flag = $j['isSuccess'] ?? $j['success'] ?? null;
        $message = trim((string)($j['message'] ?? $j['msg'] ?? ''));
    } else {
        $message = trim(ff_plain((string)$r['body']));
    }
    if (!$okHttp || $flag === false || ff_is_login((string)$r['redirect'])) {
        return ['ok' => false, 'message' => $message !== '' ? $message : 'ওটিপি পাঠানো যায়নি'];
    }
    $state['otp_phone'] = $c['phone'];
    $state['verified'] = false;
    $state['verified_at'] = 0;
    $state['otp_code'] = '';
    ff_state_save($sid, $state);
    return ['ok' => true, 'message' => $message !== '' ? $message : 'ওটিপি পাঠানো হয়েছে', 'phone' => ff_phone_display($c['phone'])];
}

function ff_action_verify_otp(string $sid, array $state): array
{
    $in = ff_input();
    $otp = preg_replace('/\D+/', '', ff_digits(ff_val($in, 'otp'))) ?? '';
    if (strlen($otp) < 4 || strlen($otp) > 8) {
        return ['ok' => false, 'message' => 'ওটিপি কোড সঠিকভাবে দিন'];
    }
    $c = ff_otp_context($in);
    if (isset($c['error'])) {
        return ['ok' => false, 'message' => $c['error']];
    }
    if ($c['geoLocationId'] <= 0) {
        return ['ok' => false, 'message' => 'জন্মস্থানের ঠিকানা নির্বাচন করুন'];
    }
    $state = ff_ensure($sid, $state);
    if ($state['otp_phone'] !== '' && $state['otp_phone'] !== $c['phone']) {
        return ['ok' => false, 'message' => 'এই নম্বরে ওটিপি পাঠানো হয়নি'];
    }
    $url = FF_UP . '/api/otp/verify?' . ff_otp_query($c, '1', $otp);
    $r = ff_http($sid, $url, http_build_query($c['names']), [
        'client: bris',
        'Accept: application/json, text/plain, */*',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        $state['header'] . ': ' . $state['token'],
        'Origin: ' . FF_UP,
        'Referer: ' . FF_UP . '/br/application',
    ]);
    $j = json_decode((string)$r['body'], true);
    $verified = is_array($j) && (($j['isVerified'] ?? false) === true || ($j['verified'] ?? false) === true);
    if (!$verified) {
        $message = is_array($j) ? trim((string)($j['message'] ?? $j['msg'] ?? '')) : '';
        if ($message === '') {
            $message = 'ওটিপি যাচাই হয়নি';
        }
        return ['ok' => false, 'message' => $message];
    }
    $state['verified'] = true;
    $state['otp_phone'] = $c['phone'];
    $state['verified_at'] = time();
    $state['otp_code'] = $otp;
    ff_state_save($sid, $state);
    return ['ok' => true, 'message' => 'মোবাইল নম্বর যাচাই হয়েছে'];
}

function ff_group_values(string $phrase, array $c): array
{
    return [
        $phrase . 'Country' => '1',
        $phrase . 'Div' => $c['div'],
        $phrase . 'Dist' => $c['dist'],
        $phrase . 'CityCorpCantOrUpazila' => $c['city'],
        $phrase . 'PaurasavaOrUnion' => $c['union'],
        $phrase . 'WardInCityCorp' => $c['kind'] === 'city' ? $c['ward'] : '',
        $phrase . 'Area' => $c['area'],
        $phrase . 'WardInPaurasavaOrUnion' => $c['kind'] === 'union' ? $c['ward'] : '',
        $phrase . 'PostOfc' => $c['postOfcBn'],
        $phrase . 'PostOfcEn' => $c['postOfcEn'],
        $phrase . 'VilAreaTownBn' => $c['villageBn'],
        $phrase . 'VilAreaTownEn' => $c['villageEn'],
        $phrase . 'HouseRoadBn' => $c['houseBn'],
        $phrase . 'HouseRoadEn' => $c['houseEn'],
        $phrase . 'PostCode' => $c['postCode'],
        $phrase . 'LocationId' => $c['location'],
        $phrase . 'Bn' => $c['addrBn'],
        $phrase . 'En' => $c['addrEn'],
    ];
}

function ff_action_submit(string $sid, array $state): array
{
    $state = ff_ensure($sid, $state);
    $firstBn = ff_post('personFirstBn');
    $lastBn = ff_post('personLastBn');
    $firstEn = ff_post('personFirstEn');
    $lastEn = ff_post('personLastEn');
    $fullBn = trim($firstBn . ' ' . $lastBn);
    $fullEn = trim($firstEn . ' ' . $lastEn);
    $dob = ff_date(ff_post('dob'));
    $gender = ff_post('gender');
    $thChild = (int)ff_post('thChild');
    $nid = preg_replace('/\D+/', '', ff_digits(ff_post('nid'))) ?? '';
    $fatherFullBn = ff_post('fatherNameBn');
    $fatherFullEn = ff_post('fatherNameEn');
    $motherFullBn = ff_post('motherNameBn');
    $motherFullEn = ff_post('motherNameEn');
    $fatherUbrn = preg_replace('/\D+/', '', ff_digits(ff_post('fatherUbrn'))) ?? '';
    $motherUbrn = preg_replace('/\D+/', '', ff_digits(ff_post('motherUbrn'))) ?? '';
    $fatherDob = ff_date(ff_post('fatherDob'));
    $motherDob = ff_date(ff_post('motherDob'));
    $phone = ff_phone(ff_post('phone'));
    $otp = preg_replace('/\D+/', '', ff_digits(ff_post('otp'))) ?? '';
    if ($otp === '' && !empty($state['otp_code'])) {
        $otp = (string)$state['otp_code'];
    }
    $relationInput = ff_post('relationWithApplicant');
    if ($relationInput === '') {
        $relationInput = ff_post('relation');
    }
    $relation = ff_relation($relationInput);
    $geoDiv = (int)ff_post('geoDiv');
    $geoDist = (int)ff_post('geoDist');
    $geoCity = (int)ff_post('geoCity');
    $geoUnion = (int)ff_post('geoUnion');
    $geoWard = (int)ff_post('geoWard');
    $geoArea = (int)ff_post('geoArea');
    $cityLevel = (int)ff_post('geoCityLevel');
    $wardKind = ff_post('geoWardKind') === 'city' ? 'city' : 'union';
    $locationId = (int)ff_post('geoLocationId');
    $geoDivName = ff_post('geoDivName');
    $geoDistName = ff_post('geoDistName');
    $geoCityName = ff_post('geoCityName');
    $geoUnionName = ff_post('geoUnionName');
    $geoWardName = ff_post('geoWardName');
    $geoDivNameEn = ff_post('geoDivNameEn');
    $geoDistNameEn = ff_post('geoDistNameEn');
    $geoCityNameEn = ff_post('geoCityNameEn');
    $geoUnionNameEn = ff_post('geoUnionNameEn');
    $geoWardNameEn = ff_post('geoWardNameEn');
    $postOfcBn = ff_post('birthPlacePostOfc');
    $postOfcEn = ff_post('birthPlacePostOfcEn');
    $villageBn = ff_post('birthPlaceVilAreaTownBn');
    $villageEn = ff_post('birthPlaceVilAreaTownEn');
    $houseBn = ff_post('birthPlaceHouseRoadBn');
    $houseEn = ff_post('birthPlaceHouseRoadEn');
    $postCode = preg_replace('/\D+/', '', ff_digits(ff_post('birthPlacePostCode'))) ?? '';
    $email = ff_post('email');
    $applicantNameInput = ff_post('applicantName');
    $applicantBrnInput = preg_replace('/\D+/', '', ff_digits(ff_post('applicantBrn'))) ?? '';
    $applicantDobInput = ff_date(ff_post('applicantDob'));
    $applicantNid = preg_replace('/\D+/', '', ff_digits(ff_post('applicantNotParentsNid'))) ?? '';

    $errors = [];
    if ($lastBn === '' || $lastEn === '') {
        $errors[] = 'শিশুর নাম (বাংলা ও ইংরেজি) দিন — এক শব্দের নাম হলে শুধু শেষ অংশে লিখুন';
    }
    if ($dob === '') {
        $errors[] = 'জন্ম তারিখ DD/MM/YYYY আকারে দিন';
    }
    if (!in_array($gender, ['MALE', 'FEMALE', 'THIRD_GENDER'], true)) {
        $errors[] = 'লিঙ্গ নির্বাচন করুন';
    }
    if ($thChild < 1 || $thChild > 11) {
        $errors[] = 'সন্তান সংখ্যা ১ থেকে ১১ এর মধ্যে দিন';
    }
    if ($fatherFullBn === '' || $fatherFullEn === '' || $motherFullBn === '' || $motherFullEn === '') {
        $errors[] = 'পিতা ও মাতার নাম (বাংলা ও ইংরেজি) দিন';
    }
    if ($geoDiv <= 0 || $geoDist <= 0 || $geoCity <= 0) {
        $errors[] = 'বিভাগ, জেলা ও উপজেলা নির্বাচন করুন';
    }
    if (in_array($cityLevel, [3, 4, 5], true) && $geoUnion <= 0) {
        $errors[] = 'ইউনিয়ন বা পৌরসভা নির্বাচন করুন';
    }
    if (in_array($cityLevel, [3, 4, 5], true) && $geoWard <= 0) {
        $errors[] = 'ওয়ার্ড নির্বাচন করুন';
    }
    if ($locationId <= 0) {
        $errors[] = 'জন্মস্থানের ঠিকানা নির্বাচন করুন';
    }
    if ($villageBn === '' || $villageEn === '' || $postOfcBn === '' || $postOfcEn === '') {
        $errors[] = 'গ্রাম ও ডাকঘরের নাম বাংলা ও ইংরেজিতে দিন';
    }
    if ($phone === '') {
        $errors[] = 'সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন';
    }
    if ($relation === '') {
        $errors[] = 'আবেদনকারীর সম্পর্ক নির্বাচন করুন';
    }
    if (strlen($otp) < 4) {
        $errors[] = 'ওটিপি কোড দিন';
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'ইমেইল ঠিকানা সঠিক নয়';
    }
    if ($nid !== '' && (strlen($nid) < 10 || strlen($nid) > 17)) {
        $errors[] = 'জাতীয় পরিচয়পত্র নম্বর সঠিক নয়';
    }
    if (!$state['verified'] || $state['otp_phone'] !== $phone) {
        $errors[] = 'মোবাইল নম্বর ওটিপি দিয়ে যাচাই করুন';
    }
    $attachments = array_values($state['attachments']);
    if (!$attachments) {
        $errors[] = 'প্রয়োজনীয় সংযুক্তি আপলোড করুন';
    }
    if ($state['required_types'] && $attachments) {
        $have = [];
        foreach ($attachments as $a) {
            $have[(int)($a['type'] ?? 0)] = true;
        }
        foreach ($state['required_types'] as $rt) {
            if (!isset($have[(int)$rt])) {
                $errors[] = 'প্রয়োজনীয় সংযুক্তি সম্পূর্ণ হয়নি (টাইপ #' . (int)$rt . ')';
                break;
            }
        }
    }
    if ($relation === 'SELF') {
        $applicantName = $fullEn;
        $applicantBrn = '';
        $applicantDob = '';
    } elseif ($relation === 'FATHER') {
        $applicantName = $fatherFullEn;
        $applicantBrn = $fatherUbrn;
        $applicantDob = $fatherDob;
    } elseif ($relation === 'MOTHER') {
        $applicantName = $motherFullEn;
        $applicantBrn = $motherUbrn;
        $applicantDob = $motherDob;
    } else {
        $applicantName = $applicantNameInput;
        $applicantBrn = $applicantBrnInput;
        $applicantDob = $applicantDobInput;
    }
    if ($applicantName === '') {
        $errors[] = 'আবেদনকারীর নাম দিন';
    }
    if ($errors) {
        return ['ok' => false, 'message' => implode(' • ', array_slice($errors, 0, 5)), 'errors' => $errors];
    }

    $addrPartsBn = trim($houseBn . ' ' . $villageBn . ' ' . $postOfcBn);
    $addrPartsEn = trim($houseEn . ' ' . $villageEn . ' ' . $postOfcEn);
    $addr = [
        'div' => (string)$geoDiv,
        'dist' => (string)$geoDist,
        'city' => (string)$geoCity,
        'union' => $geoUnion > 0 ? (string)$geoUnion : '',
        'ward' => $geoWard > 0 ? (string)$geoWard : '',
        'area' => $geoArea > 0 ? (string)$geoArea : '',
        'kind' => $wardKind,
        'postOfcBn' => $postOfcBn,
        'postOfcEn' => $postOfcEn,
        'villageBn' => $villageBn,
        'villageEn' => $villageEn,
        'houseBn' => $houseBn,
        'houseEn' => $houseEn,
        'postCode' => $postCode,
        'location' => (string)$locationId,
        'addrBn' => $addrPartsBn,
        'addrEn' => $addrPartsEn,
    ];
    $addrFullBn = implode(', ', array_filter([
        $houseBn,
        $villageBn,
        $postOfcBn,
        $geoUnionName,
        $geoCityName,
        $geoDistName,
        $geoDivName,
        'বাংলাদেশ',
    ], function ($v) {
        return trim((string)$v) !== '';
    }));
    $addrFullEn = implode(', ', array_filter([
        $houseEn,
        $villageEn,
        $postOfcEn,
        $geoUnionNameEn,
        $geoCityNameEn,
        $geoDistNameEn,
        $geoDivNameEn,
        'BANGLADESH',
    ], function ($v) {
        return trim((string)$v) !== '';
    }));
    $genderLabel = ['MALE' => 'পুরুষ', 'FEMALE' => 'মহিলা', 'THIRD_GENDER' => 'তৃতীয় লিঙ্গ'][$gender] ?? $gender;
    $relationLabel = [
        'SELF' => 'নিজে',
        'FATHER' => 'পিতা',
        'MOTHER' => 'মাতা',
        'GRANDFATHER' => 'দাদা',
        'GRANDMOTHER' => 'দাদি',
        'MATERNALGRANDFATHER' => 'নানা',
        'MATERNALGRANDMOTHER' => 'নানি',
        'GUARDIAN' => 'অভিভাবক',
        'OTHERS' => 'অন্যান্য',
    ][$relation] ?? $relation;
    $attachmentSummary = [];
    foreach ($attachments as $a) {
        $attachmentSummary[] = [
            'type' => (string)($a['type'] ?? ''),
            'type_name' => (string)($a['typeName'] ?? ''),
            'name' => (string)($a['name'] ?? ''),
        ];
    }
    $body = [
        '_csrf' => $state['token'],
        'officeAddressType' => 'BIRTHPLACE',
        'officeId' => '0',
        'geoLocationId' => (string)$locationId,
        'officeAddrCountry' => '1',
        'officeAddrCity' => '-1',
        'officeAddrOffice' => '',
        'personNameBn' => $fullBn,
        'personNameEn' => $fullEn,
        'personInfoForBirth.personNameBn' => $fullBn,
        'personInfoForBirth.personNameEn' => $fullEn,
        'personInfoForBirth.personFirstNameBn' => $firstBn,
        'personInfoForBirth.personLastNameBn' => $lastBn,
        'personInfoForBirth.personFirstNameEn' => $firstEn,
        'personInfoForBirth.personLastNameEn' => $lastEn,
        'personInfoForBirth.personBirthDate' => $dob,
        'personInfoForBirth.thChild' => (string)$thChild,
        'personInfoForBirth.gender' => $gender,
        'personInfoForBirth.personNid' => $nid,
        'religion' => 'NOT_APPLICABLE',
        'personInfoForBirth.religionOther' => '',
        'father.id' => '',
        'personInfoForBirth.father.ubrn' => $fatherUbrn,
        'personInfoForBirth.father.personBirthDate' => $fatherDob,
        'personInfoForBirth.father.personNameBn' => $fatherFullBn,
        'personInfoForBirth.father.personNameEn' => $fatherFullEn,
        'personInfoForBirth.father.passportNumber' => ff_post('fatherPassport'),
        'personInfoForBirth.father.personNationality' => '1',
        'mother.id' => '',
        'personInfoForBirth.mother.ubrn' => $motherUbrn,
        'personInfoForBirth.mother.personBirthDate' => $motherDob,
        'personInfoForBirth.mother.personNameBn' => $motherFullBn,
        'personInfoForBirth.mother.personNameEn' => $motherFullEn,
        'personInfoForBirth.mother.passportNumber' => ff_post('motherPassport'),
        'personInfoForBirth.mother.personNationality' => '1',
        'copyBirthPlaceToPermAddr' => 'yes',
        'copyPermAddrToPrsntAddr' => 'yes',
        'relationWithApplicant' => $relation,
        'applicantName' => $applicantName,
        'applicantBrn' => $applicantBrn,
        'applicantDob' => $applicantDob,
        'applicantNotParentsNid' => $applicantNid,
        'applicantAddress' => $addrPartsEn,
        'phone' => $phone,
        'email' => $email,
        'otp' => $otp,
        'declaration' => 'on',
        'personImage' => '',
        'redirect' => '',
    ];
    $body += ff_group_values('birthPlace', $addr);
    $body += ff_group_values('permAddr', $addr);
    $body += ff_group_values('prsntAddr', $addr);
    $body['attachments'] = [];
    foreach ($attachments as $a) {
        $body['attachments'][] = (string)$a['id'];
    }
    $pairs = [];
    foreach ($body as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $vv) {
                $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$vv);
            }
        } else {
            $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
    }
    $encoded = implode('&', $pairs);
    $r = ff_http($sid, FF_UP . '/br/application', $encoded, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'Origin: ' . FF_UP,
        'Referer: ' . FF_UP . '/br/application',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1',
    ]);
    $page = $r;
    $loc = ff_abs((string)$r['redirect']);
    $hops = 0;
    while ($loc !== '' && $hops < 4) {
        if (ff_is_login($loc)) {
            return ['ok' => false, 'message' => 'BDRIS সেশন শেষ হয়েছে, আবার চেষ্টা করুন'];
        }
        $page = ff_http($sid, $loc, null, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Referer: ' . FF_UP . '/br/application',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
        ]);
        $loc = ff_abs((string)$page['redirect']);
        $hops++;
    }
    $status = (int)$page['status'];
    $html = (string)$page['body'];
    if ($status < 200 || $status >= 400) {
        $msg = ff_extract_errors($html);
        return ['ok' => false, 'message' => $msg !== '' ? $msg : ('BDRIS HTTP ' . $status), 'debug' => mb_substr(ff_plain($html), 0, 500)];
    }
    [$app, $office, $deadline, $plain] = ff_extract_success($html, (string)$page['url']);
    if ($app !== '' || str_contains($plain, 'সফলভাবে') || (str_contains($plain, 'সফল') && (str_contains((string)$page['url'], 'success') || str_contains((string)$page['url'], 'application')))) {
        $details = [
            'application' => $app,
            'office' => $office,
            'deadline' => $deadline,
            'submitted_at' => date('d/m/Y, h:i A'),
            'name_bn' => $fullBn,
            'name_en' => $fullEn,
            'dob' => $dob,
            'gender' => $genderLabel,
            'th_child' => (string)$thChild,
            'nid' => $nid,
            'father_bn' => $fatherFullBn,
            'father_en' => $fatherFullEn,
            'father_dob' => $fatherDob,
            'father_ubrn' => $fatherUbrn,
            'mother_bn' => $motherFullBn,
            'mother_en' => $motherFullEn,
            'mother_dob' => $motherDob,
            'mother_ubrn' => $motherUbrn,
            'relation' => $relationLabel,
            'applicant_name' => $applicantName,
            'phone' => ff_phone_display($phone),
            'email' => $email,
            'address_bn' => $addrFullBn,
            'address_en' => $addrFullEn,
            'postcode' => $postCode,
            'attachments' => $attachmentSummary,
        ];
        $state['attachments'] = [];
        $state['verified'] = false;
        $state['otp_phone'] = '';
        $state['verified_at'] = 0;
        $state['otp_code'] = '';
        $state['required_types'] = [];
        ff_state_save($sid, $state);
        return [
            'ok' => true,
            'application' => $app,
            'office' => $office,
            'deadline' => $deadline,
            'message' => $app !== '' ? 'আবেদনপত্র সফলভাবে জমা হয়েছে' : 'আবেদন জমা হয়েছে',
            'details' => $details,
        ];
    }
    $msg = ff_extract_errors($html);
    if ($msg === '' || strlen($msg) > 400) {
        $msg = 'আবেদন জমা হয়নি, তথ্য যাচাই করুন';
    }
    if (preg_match('/ওটিপি|otp/i', $msg)) {
        $state['verified'] = false;
        $state['verified_at'] = 0;
        $state['otp_phone'] = '';
        $state['otp_code'] = '';
        ff_state_save($sid, $state);
    }
    return ['ok' => false, 'message' => $msg, 'debug' => mb_substr($plain, 0, 500)];
}

function ff_page_open(string $title): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0c4a35">
<title>' . ff_h($title) . '</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
';
    echo <<<'HTML'
<style>
:root{--ink:#132a20;--ink-soft:#344940;--muted:#63766c;--line:#e2eae5;--line-strong:#cfded5;--paper:#f3f7f4;--card:#fff;--brand:#0f5e43;--brand-deep:#0a4230;--brand-soft:#ecf4ef;--gold:#c19a4b;--gold-deep:#9c7729;--gold-soft:#fbf6e9;--danger:#b42318;--ok:#0d7a4c;--ok-soft:#edf9f2;--radius:15px;--radius-sm:10px;--shadow-1:0 1px 2px rgba(16,42,34,.04),0 2px 8px -2px rgba(16,42,34,.05);--shadow-2:0 1px 2px rgba(16,42,34,.04),0 18px 40px -24px rgba(16,42,34,.18);--shadow-3:0 2px 4px rgba(16,42,34,.04),0 30px 70px -30px rgba(16,42,34,.28);--font-bn:'Hind Siliguri','Noto Sans Bengali',system-ui,-apple-system,'Segoe UI',sans-serif;--font-en:'Inter',system-ui,-apple-system,'Segoe UI',sans-serif}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
body{margin:0;padding:0;font-family:var(--font-bn);color:var(--ink);line-height:1.55;background-color:var(--paper);background-image:radial-gradient(900px 340px at 50% -160px,rgba(15,94,67,.10),transparent 65%),radial-gradient(620px 320px at 100% 0,rgba(193,154,75,.07),transparent 62%),linear-gradient(180deg,#f8faf8 0,#f3f7f4 480px,#f3f7f4 100%);-webkit-font-smoothing:antialiased}
::selection{background:rgba(15,94,67,.16)}
a{color:var(--brand);text-decoration:none}
.wrap{width:min(1180px,calc(100% - 32px));margin:0 auto}
.top{position:relative;overflow:hidden;background:linear-gradient(115deg,#082d21 0%,#0c4a35 45%,#116b4c 100%);color:#fff;border-bottom:3px solid var(--gold)}
.top::before{content:"";position:absolute;inset:0;background:repeating-linear-gradient(65deg,rgba(255,255,255,.045) 0 1px,transparent 1px 15px),repeating-linear-gradient(-65deg,rgba(255,255,255,.035) 0 1px,transparent 1px 15px);pointer-events:none}
.top::after{content:"";position:absolute;right:-130px;top:-180px;width:440px;height:440px;border-radius:50%;background:radial-gradient(circle,rgba(193,154,75,.22),transparent 62%);pointer-events:none}
.top .wrap{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 0}
.brand{display:flex;align-items:center;gap:14px;min-width:0}
.seal{width:48px;height:48px;flex:0 0 auto;border-radius:50%;display:grid;place-items:center;color:#0b3a2a;background:radial-gradient(circle at 30% 25%,#e8ce8b,#c19a4b 58%,#a37a2c);box-shadow:inset 0 0 0 1px rgba(255,255,255,.45),inset 0 0 0 4px rgba(255,255,255,.18),0 10px 22px -10px rgba(0,0,0,.55)}
.seal svg{width:26px;height:26px;display:block}
.brand-text strong{display:block;font-size:19px;font-weight:700;letter-spacing:.2px;line-height:1.25}
.brand-text small{display:block;margin-top:2px;font-family:var(--font-en);font-size:10.5px;font-weight:500;letter-spacing:1.6px;text-transform:uppercase;color:rgba(255,255,255,.62)}
.pill{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);padding:7px 13px;border-radius:999px;font-size:12px;font-weight:500;white-space:nowrap;backdrop-filter:blur(6px)}
.pill svg{width:14px;height:14px;opacity:.85}
main{padding:22px 0 44px}
.parent-pair{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;align-items:stretch}
.parent-pair .card{margin:0}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow-2);margin-bottom:18px;overflow:hidden}
.card-head{display:flex;gap:12px;align-items:center;padding:16px 20px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#fdfefd,#f7faf8)}
.num{width:34px;height:34px;border-radius:10px;background:linear-gradient(150deg,#12724f,#0b4a35);color:#fff;display:grid;place-items:center;font-family:var(--font-bn);font-weight:700;font-size:13.5px;flex:0 0 auto;box-shadow:0 8px 16px -10px rgba(10,66,48,.7),inset 0 1px 0 rgba(255,255,255,.18)}
.card-head h2{font-size:15px;font-weight:700;margin:0;line-height:1.35;color:var(--ink)}
.card-head small{display:block;color:var(--muted);font-size:11.5px;font-weight:400;margin-top:2px}
.card-body{padding:20px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.span2{grid-column:1/-1}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.field{display:block;min-width:0}
.field>span{display:flex;align-items:center;gap:5px;font-size:12.5px;font-weight:600;color:#31453b;margin-bottom:7px;line-height:1.4}
.field>span b{color:var(--danger);font-weight:700;font-size:13px;line-height:1}
input,select,textarea{width:100%;border:1px solid #d8e3dc;background:#fcfdfc;border-radius:var(--radius-sm);padding:11px 13px;font:inherit;font-size:14px;color:var(--ink);outline:none;transition:border-color .16s,box-shadow .16s,background .16s;box-shadow:inset 0 1px 1px rgba(16,42,34,.02)}
input::placeholder,textarea::placeholder{color:#a2b0a8}
input:hover,select:hover,textarea:hover{border-color:#c2d2c8}
input:focus,select:focus,textarea:focus{border-color:var(--brand);background:#fff;box-shadow:0 0 0 4px rgba(15,94,67,.10)}
input[readonly]{background:#f3f7f4;color:#5b6e64;border-color:var(--line);box-shadow:none}
input:disabled,select:disabled,textarea:disabled{background:#f3f7f4;color:#5b6e64;border-color:var(--line);cursor:not-allowed;opacity:1}
select:disabled{background-image:none;padding-right:13px}
textarea:disabled{resize:none}
input[type=checkbox]{width:18px;height:18px;padding:0;border:0;background:transparent;box-shadow:none;accent-color:var(--brand);cursor:pointer}
input[type=file]{padding:10px;background:#fff}
select{appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2363766c' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:15px;padding-right:38px;cursor:pointer}
textarea{min-height:84px;resize:vertical}
.input-group{display:flex;gap:8px;align-items:stretch}
.input-group input{flex:1;min-width:0}
.input-group .btn{flex:0 0 auto}
.verify-btn{padding:0 16px;font-size:12.5px}
.verify-btn.is-done{background:var(--ok-soft);border-color:#bfe6d2;color:var(--ok)}
.hint{display:block;font-size:11.5px;line-height:1.45;color:var(--muted);margin-top:5px}
.row{margin-bottom:16px}
.row:last-child{margin-bottom:0}
.office{position:relative;display:flex;gap:14px;align-items:center;border:1px solid #e8dec4;background:linear-gradient(120deg,#fdfaf2,#faf5e9);border-radius:12px;padding:14px 16px;margin-bottom:0;overflow:hidden}
.office .ic{width:44px;height:44px;border-radius:12px;background:#fff;border:1px solid #ecdfc0;color:var(--gold-deep);display:grid;place-items:center;flex:0 0 auto;box-shadow:0 6px 14px -10px rgba(156,119,41,.55)}
.office .ic svg{width:22px;height:22px}
.office strong{display:block;font-size:14px;color:#40350f}
.office small{display:block;color:#7b6a3e;font-size:11.5px;margin-top:2px}
.status{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 14px;border-radius:10px;background:#f6f9f7;border:1px solid var(--line);font-size:12.5px;color:var(--muted);margin-top:12px}
.status b{font-weight:700;color:var(--ink)}
.status.ok{background:var(--ok-soft);border-color:#bfe6d2;color:var(--ok)}
.status.ok b{color:var(--ok)}
.btn{appearance:none;border:1px solid transparent;border-radius:var(--radius-sm);padding:11px 18px;font:inherit;font-weight:600;font-size:13.5px;line-height:1.2;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;white-space:nowrap;transition:background .18s,border-color .18s,box-shadow .18s,transform .06s}
.btn:focus-visible{outline:3px solid rgba(15,94,67,.25);outline-offset:2px}
.btn:active{transform:translateY(1px)}
.btn[disabled]{opacity:.55;cursor:not-allowed;box-shadow:none;transform:none}
.btn-main{background:linear-gradient(180deg,#127a55,#0c563e);color:#fff;border-color:#0a4a35;box-shadow:0 1px 2px rgba(10,66,48,.28),0 10px 20px -12px rgba(10,66,48,.6),inset 0 1px 0 rgba(255,255,255,.14)}
.btn-main:hover{background:linear-gradient(180deg,#168761,#0e5f45)}
.btn-ghost{background:#fff;border-color:#cbd9d1;color:var(--brand-deep);box-shadow:var(--shadow-1)}
.btn-ghost:hover{background:#f5faf7;border-color:#b7cbc0}
.btn-danger{background:#fff;border-color:#f0cfcb;color:var(--danger)}
.btn-danger:hover{background:#fdf4f3}
.btn-sm{padding:7px 11px;font-size:12px;border-radius:8px}
.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}
.uploader{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end}
.type-select{min-width:0}
.files{margin-top:14px;border-top:1px solid var(--line)}
.file{display:flex;gap:12px;align-items:center;padding:11px 2px;border-bottom:1px solid var(--line);font-size:12.5px}
.file:last-child{border-bottom:0}
.file .t{flex:1;min-width:0}
.file .t strong{display:block;font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.file .t small{color:var(--muted);font-size:11.5px}
.file .id{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:10.5px;color:var(--brand-deep);background:var(--brand-soft);border:1px solid #d9e9e0;padding:3px 7px;border-radius:6px}
.notice{position:relative;font-size:12.5px;line-height:1.6;padding:11px 14px 11px 17px;border-radius:10px;border:1px solid #eee2c6;background:linear-gradient(120deg,#fdfbf4,#faf6ea);color:#6a5624;margin-top:12px}
.notice::before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:3px;border-radius:0 3px 3px 0;background:var(--gold)}
.notice strong{color:#4d3d14}
.name-preview{margin-top:14px;padding-top:12px;border-top:1px dashed var(--line);font-size:12px;line-height:1.6;color:var(--muted)}
.name-preview strong{color:var(--ink-soft);font-weight:600}
.name-preview span{display:block;font-size:11.5px;margin-top:2px}
.declaration{display:flex;gap:11px;align-items:flex-start;font-size:13px;line-height:1.55;background:#f8faf9;border:1px solid var(--line);border-radius:11px;padding:13px 15px;cursor:pointer}
.declaration input{width:18px;height:18px;margin-top:2px;flex:0 0 auto;accent-color:var(--brand)}
.alert{display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.55;padding:13px 16px;border-radius:11px;margin-bottom:16px}
.alert.err{background:#fdf1ef;border:1px solid #f1c7c2;color:#8a1f15}
.alert.ok{background:var(--ok-soft);border:1px solid #bfe6d2;color:#0a5c3f}
.hidden{display:none!important}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(14px);background:#0c3326;color:#fff;padding:12px 18px;border:1px solid rgba(255,255,255,.12);border-radius:12px;font-size:13px;line-height:1.45;box-shadow:0 18px 40px -16px rgba(0,0,0,.5);opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:50;max-width:min(92vw,560px);text-align:center}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.bad{background:#7a1f17;border-color:#8f2c22}
.result{max-width:760px;margin:36px auto;background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:var(--shadow-3)}
.result.wide{max-width:960px}
.result-top{position:relative;padding:34px 30px 24px;text-align:center;background:radial-gradient(420px 200px at 50% -80px,rgba(15,94,67,.10),transparent 70%),linear-gradient(180deg,#fbfdfc,#fff)}
.result-top::after{content:"";position:absolute;left:30px;right:30px;bottom:0;height:1px;background:linear-gradient(90deg,transparent,var(--line),transparent)}
.result-mark{width:64px;height:64px;border-radius:50%;display:grid;place-items:center;margin:0 auto 14px;font-size:28px;font-weight:800}
.result.ok .result-mark{background:linear-gradient(160deg,#e6f7ee,#d2f0e1);color:var(--ok);box-shadow:0 0 0 6px rgba(13,122,76,.06),0 12px 24px -14px rgba(13,122,76,.45)}
.result.bad .result-mark{background:#fdeceb;color:var(--danger);box-shadow:0 0 0 6px rgba(180,35,24,.05)}
.result h1{font-size:22px;font-weight:700;letter-spacing:.1px;margin:0 0 6px}
.result p{margin:0;color:var(--muted);font-size:13px}
.result-body{padding:0 30px 28px}
.app-hero{position:relative;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;overflow:hidden;background:linear-gradient(120deg,#0a4230,#0f5e43 60%,#13724f);color:#fff;border:1px solid rgba(255,255,255,.08);border-radius:13px;padding:18px 20px;margin:18px 0;box-shadow:0 18px 32px -22px rgba(10,66,48,.85)}
.app-hero::after{content:"";position:absolute;right:-60px;top:-90px;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle,rgba(193,154,75,.30),transparent 62%)}
.app-hero span{position:relative;z-index:1;font-family:var(--font-en);font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;opacity:.75}
.app-hero strong{position:relative;z-index:1;font-family:var(--font-en);font-size:26px;font-weight:700;letter-spacing:1.4px}
.info-sec{border:1px solid var(--line);border-radius:12px;margin:14px 0;overflow:hidden;background:#fff;box-shadow:var(--shadow-1)}
.info-sec h3{margin:0;padding:11px 16px;background:linear-gradient(180deg,#fbfdfc,#f6f9f7);border-bottom:1px solid var(--line);font-size:13px;font-weight:700;color:var(--ink-soft);letter-spacing:.1px}
.info-sec .kv{margin:0;border:0;border-radius:0;background:#fff;grid-template-columns:190px 1fr;padding:14px 16px}
.kv{display:grid;grid-template-columns:160px 1fr;gap:9px 16px;font-size:13px;background:#f8faf9;border:1px solid var(--line);border-radius:11px;padding:15px;margin:16px 0}
.kv span{color:var(--muted)}
.kv strong{font-weight:600;color:var(--ink);overflow-wrap:anywhere}
.appno{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:18px;letter-spacing:.5px;color:var(--brand-deep)}
.debug{margin-top:14px;font-size:11px;color:var(--muted)}
.debug summary{cursor:pointer}
.debug pre{white-space:pre-wrap;background:#f7faf8;border:1px solid var(--line);border-radius:8px;padding:10px;max-height:220px;overflow:auto;font-size:11px}
footer{position:relative;margin-top:10px;padding:22px 0 30px;color:var(--muted);font-size:11.5px;text-align:center}
footer::before{content:"";position:absolute;top:0;left:50%;transform:translateX(-50%);width:min(1180px,calc(100% - 32px));height:1px;background:linear-gradient(90deg,transparent,var(--line),transparent)}
@media(max-width:960px){.parent-pair{grid-template-columns:1fr}.grid3{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.grid2,.grid3{grid-template-columns:1fr}.uploader{grid-template-columns:1fr}.brand-text strong{font-size:17px}.pill{display:none}.card-head{padding:14px 15px}.card-body{padding:15px}.kv{grid-template-columns:1fr}.top .wrap{padding:13px 0}.result{margin:18px auto}.result-body{padding:0 18px 22px}.result-top{padding:26px 18px 20px}.app-hero strong{font-size:22px}.info-sec .kv{grid-template-columns:1fr}input,select,textarea{font-size:16px}}
@media print{.top,footer,.actions,.notice,.result-top .result-mark{display:none!important}body{background:#fff;background-image:none}.wrap{width:100%}.result{box-shadow:none;border:0;margin:0;max-width:none;border-radius:0}.info-sec{break-inside:avoid;box-shadow:none}.result-top{padding:0 0 8px;background:#fff}.app-hero{background:#fff;color:#000;border:1px solid #bbb;box-shadow:none}.app-hero strong{color:#000}.app-hero span{opacity:1;color:#444}}
@media(prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
</style>
</head>
<body>
<header class="top"><div class="wrap"><div class="brand"><span class="seal" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4c1.8 2.2 2.6 4.4 2.6 6.4 0 2.6-1.2 4.6-2.6 5.6-1.4-1-2.6-3-2.6-5.6C9.4 8.4 10.2 6.2 12 4Z"/><path d="M12 16c-2.8 0-5.2-1.3-6.8-3.4 2.1-.7 4.2-.4 5.8.6M12 16c2.8 0 5.2-1.3 6.8-3.4-2.1-.7-4.2-.4-5.8.6M4 19c2.5 1.1 5.2 1.6 8 1.6s5.5-.5 8-1.6"/></svg></span><div class="brand-text"><strong>জন্ম নিবন্ধন আবেদন</strong><small>BDRIS · সরাসরি ফর্ম</small></div></div><span class="pill"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>নিরাপদ অনলাইন আবেদন</span></div></header>
HTML;
}

function ff_page_close(): void
{
    echo <<<'HTML'
<footer><div class="wrap">জন্ম নিবন্ধন তথ্য সিস্টেম (BDRIS) · এই ফর্মটি সরাসরি নিবন্ধক কার্যালয়ে আবেদন জমা দেয়</div></footer>
<div class="toast" id="toast"></div>
</body>
</html>
HTML;
}

function ff_render_result(array $result): void
{
    $ok = !empty($result['ok']);
    if (!$ok) {
        ff_page_open('আবেদন সম্পন্ন হয়নি');
        ?>
        <main><div class="wrap"><div class="result bad">
        <div class="result-top"><div class="result-mark">!</div><h1>আবেদন সম্পন্ন হয়নি</h1><p><?php echo ff_h((string)($result['message'] ?? 'আবার চেষ্টা করুন')); ?></p></div>
        <div class="result-body">
        <div class="notice" style="border-color:#f3c5bf;background:#fef5f4;color:#7a1f17"><?php echo ff_h((string)($result['message'] ?? 'অনুগ্রহ করে তথ্য যাচাই করে আবার চেষ্টা করুন')); ?></div>
        <?php if (!empty($result['debug'])) { ?><details class="debug"><summary>প্রযুক্তিগত তথ্য</summary><pre><?php echo ff_h((string)$result['debug']); ?></pre></details><?php } ?>
        <div class="actions"><a class="btn btn-main" href="<?php echo ff_h(basename(__FILE__)); ?>">ফিরে যান</a></div>
        </div></div></div></main>
        <?php
        ff_page_close();
        return;
    }
    $d = is_array($result['details'] ?? null) ? $result['details'] : [];
    $val = function (string $k, string $fallback = '—') use ($d): string {
        $v = $d[$k] ?? '';
        return trim((string)$v) !== '' ? (string)$v : $fallback;
    };
    $row = function (string $label, string $value): void {
        if (trim($value) === '' || $value === '—') {
            return;
        }
        echo '<span>' . ff_h($label) . '</span><strong>' . ff_h($value) . '</strong>';
    };
    $app = $val('application', (string)($result['application'] ?? '—'));
    $office = $val('office', (string)($result['office'] ?? ''));
    $deadline = $val('deadline', (string)($result['deadline'] ?? ''));
    $attachments = is_array($d['attachments'] ?? null) ? $d['attachments'] : [];
    ff_page_open('আবেদন জমা হয়েছে');
    ?>
    <main><div class="wrap"><div class="result ok wide">
    <div class="result-top"><div class="result-mark">✓</div><h1>আবেদন জমা হয়েছে</h1><p>আপনার জন্ম নিবন্ধন আবেদন সফলভাবে জমা হয়েছে। নিচের তথ্য সংরক্ষণ করুন।</p></div>
    <div class="result-body">
    <div class="app-hero"><span>আবেদনপত্র নম্বর</span><strong><?php echo ff_h($app); ?></strong></div>
    <div class="info-sec"><h3>আবেদনের তথ্য</h3><div class="kv">
    <?php $row('জমার সময়', $val('submitted_at')); $row('কার্যালয়', $office); $row('জমা দেওয়ার শেষ তারিখ', $deadline); $row('সম্পর্ক', $val('relation')); $row('মোবাইল', $val('phone')); $row('ইমেইল', $val('email')); ?>
    </div></div>
    <div class="info-sec"><h3>জন্মগ্রহণকারীর তথ্য</h3><div class="kv">
    <?php $row('নাম (বাংলা)', $val('name_bn')); $row('নাম (English)', $val('name_en')); $row('জন্ম তারিখ', $val('dob')); $row('লিঙ্গ', $val('gender')); $row('সন্তান সংখ্যা', $val('th_child')); $row('জাতীয় পরিচয়পত্র', $val('nid')); ?>
    </div></div>
    <div class="info-sec"><h3>পিতা-মাতার তথ্য</h3><div class="kv">
    <?php $row('পিতার নাম (বাংলা)', $val('father_bn')); $row('পিতার নাম (English)', $val('father_en')); $row('পিতার জন্ম তারিখ', $val('father_dob')); $row('পিতার জন্ম নিবন্ধন নম্বর', $val('father_ubrn')); $row('মাতার নাম (বাংলা)', $val('mother_bn')); $row('মাতার নাম (English)', $val('mother_en')); $row('মাতার জন্ম তারিখ', $val('mother_dob')); $row('মাতার জন্ম নিবন্ধন নম্বর', $val('mother_ubrn')); ?>
    </div></div>
    <div class="info-sec"><h3>ঠিকানা</h3><div class="kv">
    <?php $row('ঠিকানা (বাংলা)', $val('address_bn')); $row('Address (English)', $val('address_en')); $row('পোস্ট কোড', $val('postcode')); ?>
    </div></div>
    <div class="info-sec"><h3>সংযুক্তি</h3><div class="kv">
    <?php if ($attachments) { foreach ($attachments as $a) { $label = 'সংযুক্তি #' . (string)($a['type'] ?? ''); $text = trim((string)($a['type_name'] ?? '')); $file = trim((string)($a['name'] ?? '')); $value = $text !== '' ? mb_substr($text, 0, 120) . ($file !== '' ? ' — ' . $file : '') : $file; $row($label, $value); } } else { echo '<span>সংযুক্তি</span><strong>—</strong>'; } ?>
    </div></div>
    <div class="notice">আবেদনপত্রের প্রিন্ট কপি সংরক্ষণ করুন এবং নির্ধারিত সময়ের মধ্যে প্রয়োজনীয় কাগজপত্রসহ নিবন্ধক কার্যালয়ে যোগাযোগ করুন।</div>
    <div class="actions"><button type="button" class="btn btn-main" onclick="window.print()">প্রিন্ট / PDF</button><a class="btn btn-ghost" href="<?php echo ff_h(basename(__FILE__)); ?>">নতুন আবেদন</a></div>
    </div></div></div></main>
    <?php
    ff_page_close();
}

$ffAction = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
if ($ffAction !== '') {
    $ffSid = ff_sid();
    $ffLock = null;
    $ffCode = 200;
    try {
        $ffLock = ff_lock($ffSid);
        $ffState = ff_state($ffSid);
        switch ($ffAction) {
            case 'geo':
                $ffResult = ff_action_geo($ffSid, $ffState);
                break;
            case 'attachment_types':
                $ffResult = ff_action_types($ffSid, $ffState);
                break;
            case 'parent_info':
                $ffResult = ff_action_parent_info($ffSid, $ffState);
                break;
            case 'upload':
                $ffResult = ff_action_upload($ffSid, $ffState);
                break;
            case 'remove':
                $ffResult = ff_action_remove($ffSid, $ffState);
                break;
            case 'send_otp':
                $ffResult = ff_action_send_otp($ffSid, $ffState);
                break;
            case 'verify_otp':
                $ffResult = ff_action_verify_otp($ffSid, $ffState);
                break;
            case 'submit':
                $ffResult = ff_action_submit($ffSid, $ffState);
                break;
            default:
                $ffResult = ['ok' => false, 'message' => 'অজানা অনুরোধ'];
                $ffCode = 400;
        }
    } catch (Throwable $ffEx) {
        $ffResult = ['ok' => false, 'message' => $ffEx->getMessage()];
        $ffCode = 500;
    }
    if ($ffLock) {
        ff_unlock($ffLock);
        $ffLock = null;
    }
    if ($ffAction === 'submit' && !ff_wants_json()) {
        ff_render_result($ffResult);
        exit;
    }
    if (!isset($ffResult['ok']) || $ffResult['ok'] !== true) {
        $ffCode = $ffCode === 200 ? 422 : $ffCode;
    }
    ff_json($ffResult, $ffCode);
}

$ffSid = ff_sid();
$ffState = ff_state($ffSid);
$ffBootError = '';
try {
    $ffLock = ff_lock($ffSid);
    try {
        $ffState = ff_ensure($ffSid, $ffState);
    } finally {
        ff_unlock($ffLock);
    }
} catch (Throwable $ffEx) {
    $ffBootError = $ffEx->getMessage();
}
$ffAttachments = array_values($ffState['attachments']);
$ffVerified = !empty($ffState['verified']);
$ffTokenReady = $ffState['token'] !== '';
$ffAttachJson = json_encode($ffAttachments, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
ff_page_open('জন্ম নিবন্ধন আবেদন ফর্ম');
?>
<main><div class="wrap">
<?php if ($ffBootError !== '') { ?>
<div class="alert err">BDRIS সেশনে সংযোগ করা যায়নি: <?php echo ff_h($ffBootError); ?> — পেজ রিফ্রেশ করে আবার চেষ্টা করুন।</div>
<?php } ?>
<form id="appForm" action="?action=submit" method="post" novalidate>

<section class="card"><div class="card-head"><span class="num">১</span><h2>নিবন্ধন কার্যালয়<small>জন্মস্থান অনুযায়ী কার্যালয় নির্ধারিত হবে</small></h2></div><div class="card-body">
<div class="office"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V9l7-5 7 5v12"/><path d="M9 21v-6h6v6"/><path d="M9 11h.01M15 11h.01"/></svg></span><div><strong>বালুয়া ইউনিয়ন পরিষদ</strong><small>বালুয়া, সোনাতলা, বগুড়া, রাজশাহী · ডিফল্ট কার্যালয়</small></div></div>
<input type="hidden" name="geoDiv" id="geoDiv" value="6">
<input type="hidden" name="geoDist" id="geoDist" value="52">
<input type="hidden" name="geoCity" id="geoCity" value="121">
<input type="hidden" name="geoUnion" id="geoUnion" value="1356">
<input type="hidden" name="geoWard" id="geoWard" value="">
<input type="hidden" name="geoArea" id="geoArea" value="">
<input type="hidden" name="geoLocationId" id="geoLocationId" value="1356">
<input type="hidden" name="geoCityLevel" id="geoCityLevel" value="3">
<input type="hidden" name="geoWardKind" id="geoWardKind" value="union">
<input type="hidden" name="geoDivName" id="geoDivName" value="রাজশাহী বিভাগ">
<input type="hidden" name="geoDistName" id="geoDistName" value="বগুড়া">
<input type="hidden" name="geoCityName" id="geoCityName" value="সোনাতলা">
<input type="hidden" name="geoUnionName" id="geoUnionName" value="বালুয়া">
<input type="hidden" name="geoWardName" id="geoWardName" value="">
<input type="hidden" name="geoDivNameEn" id="geoDivNameEn" value="RAJSHAHI DIVISION">
<input type="hidden" name="geoDistNameEn" id="geoDistNameEn" value="BOGURA">
<input type="hidden" name="geoCityNameEn" id="geoCityNameEn" value="SONATOLA">
<input type="hidden" name="geoUnionNameEn" id="geoUnionNameEn" value="BALUA">
<input type="hidden" name="geoWardNameEn" id="geoWardNameEn" value="">
</div></section>

<section class="card"><div class="card-head"><span class="num">২</span><h2>শিশুর তথ্য<small>জন্ম সনদের নাম অনুযায়ী</small></h2></div><div class="card-body">
<div class="grid2">
<label class="field"><span>নামের প্রথম অংশ (বাংলা)</span><input name="personFirstBn" id="personFirstBn" autocomplete="off"><small class="hint">এক শব্দের নাম হলে খালি রাখুন</small></label>
<label class="field"><span>নামের শেষ অংশ (বাংলা) <b>*</b></span><input name="personLastBn" id="personLastBn" autocomplete="off" required><small class="hint">এক শব্দের নাম হলে পুরো নাম এখানে দিন</small></label>
<label class="field"><span>First name (English)</span><input name="personFirstEn" id="personFirstEn" autocomplete="off"><small class="hint">Leave blank for a single-word name</small></label>
<label class="field"><span>Last name (English) <b>*</b></span><input name="personLastEn" id="personLastEn" autocomplete="off" required><small class="hint">Put the full single-word name here</small></label>
<label class="field"><span>জন্ম তারিখ <b>*</b></span><input name="dob" id="dob" placeholder="DD/MM/YYYY" inputmode="numeric" maxlength="10" required><small class="hint">যেমন: 05/08/2007</small></label>
<label class="field"><span>লিঙ্গ <b>*</b></span><select name="gender" id="gender" required><option value="">নির্বাচন করুন</option><option value="FEMALE">মহিলা</option><option value="MALE">পুরুষ</option><option value="THIRD_GENDER">তৃতীয় লিঙ্গ</option></select></label>
<label class="field"><span>সন্তান সংখ্যা <b>*</b></span><select name="thChild" id="thChild" required>
<?php for ($i = 1; $i <= 11; $i++) { echo '<option value="' . $i . '">' . $i . '</option>'; } ?>
</select></label>
<label class="field"><span>জাতীয় পরিচয়পত্র নম্বর (ঐচ্ছিক)</span><input name="nid" id="nid" inputmode="numeric" maxlength="17"><small class="hint">১৮ বছরের বেশি হলে প্রযোজ্য</small></label>
</div>
<div class="name-preview">পুরো নাম: <strong id="namePreviewBn">—</strong> / <strong id="namePreviewEn">—</strong><span>এক শব্দের নাম হলে প্রথম অংশ খালি রেখে পুরো নাম শেষ অংশে লিখুন।</span></div>
</div></section>

<section class="card"><div class="card-head"><span class="num">৩</span><h2>জন্মস্থানের ঠিকানা</h2></div><div class="card-body">
<div class="grid2">
<label class="field"><span>দেশ <b>*</b></span><select id="birthPlaceCountry" disabled><option value="1" data-level="0" data-name-en="BANGLADESH" selected>বাংলাদেশ</option></select></label>
<label class="field"><span>বিভাগ <b>*</b></span><select id="birthPlaceDiv" disabled><option value="6" data-level="1" data-name-en="RAJSHAHI DIVISION" selected>রাজশাহী বিভাগ</option></select></label>
<label class="field"><span>জেলা <b>*</b></span><select id="birthPlaceDist" disabled><option value="52" data-level="2" data-name-en="BOGURA" selected>বগুড়া</option></select></label>
<label class="field"><span>সিটি কর্পোরেশন,ক্যান্টনমেন্ট/উপজেলা <b>*</b></span><select id="birthPlaceCityCorpCantOrUpazila" disabled><option value="121" data-level="3" data-name-en="SONATOLA" selected>সোনাতলা</option></select></label>
<label class="field geo-union-row"><span>পৌরসভা/ইউনিয়ন <b>*</b></span><select id="birthPlacePaurasavaOrUnion" disabled><option value="1356" data-level="5" data-name-en="BALUA" selected>বালুয়া</option></select></label>
<label class="field geo-ward-row"><span>ওয়ার্ড <b>*</b></span><select id="birthPlaceWardInPaurasavaOrUnion" required><option value="">ওয়ার্ড নির্বাচন করুন</option><option value="30471" data-ward="1" data-name-en="Ward - 1">ওয়ার্ড - 1</option><option value="30472" data-ward="2" data-name-en="Ward - 2">ওয়ার্ড - 2</option><option value="30473" data-ward="3" data-name-en="Ward - 3">ওয়ার্ড - 3</option><option value="30474" data-ward="4" data-name-en="Ward - 4">ওয়ার্ড - 4</option><option value="30475" data-ward="5" data-name-en="Ward - 5">ওয়ার্ড - 5</option><option value="30476" data-ward="6" data-name-en="Ward - 6">ওয়ার্ড - 6</option><option value="30477" data-ward="7" data-name-en="Ward - 7">ওয়ার্ড - 7</option><option value="30478" data-ward="8" data-name-en="Ward - 8">ওয়ার্ড - 8</option><option value="30479" data-ward="9" data-name-en="Ward - 9">ওয়ার্ড - 9</option></select></label>
<label class="field"><span>ডাকঘর (বাংলায়) <b>*</b></span><input id="birthPlacePostOfc" value="বালুয়াহাট ৫৮২৬" disabled><input type="hidden" name="birthPlacePostOfc" value="বালুয়াহাট ৫৮২৬"></label>
<label class="field"><span>ডাকঘর (ইংরেজিতে) <b>*</b></span><input id="birthPlacePostOfcEn" value="Baluahat -5826" disabled><input type="hidden" name="birthPlacePostOfcEn" value="Baluahat -5826"></label>
<label class="field"><span>গ্রাম/পাড়া/মহল্লা <b>*</b></span><input id="birthPlaceVilAreaTownBn" value="ডাকঘরঃ" disabled><input type="hidden" name="birthPlaceVilAreaTownBn" value="ডাকঘরঃ"></label>
<label class="field"><span>গ্রাম/পাড়া/মহল্লা (ইংরেজি) <b>*</b></span><input id="birthPlaceVilAreaTownEn" value="Post:" disabled><input type="hidden" name="birthPlaceVilAreaTownEn" value="Post:"></label>
<label class="field span2"><span>গ্রাম/এলাকা নির্বাচন</span><select id="voterArea" disabled><option value="">আগে ওয়ার্ড নির্বাচন করুন</option></select><small class="hint">ওয়ার্ড নির্বাচন করলে এলাকার তালিকা আসবে; এলাকা নির্বাচন করলে বাসা ও সড়ক ঘরে নাম বসবে।</small></label>
<label class="field"><span>বাসা ও সড়ক (নাম, নম্বর)</span><input name="birthPlaceHouseRoadBn" id="birthPlaceHouseRoadBn" value="গ্রামঃ "></label>
<label class="field"><span>বাসা ও সড়ক (নাম, নম্বর) (ইংরেজি)</span><input name="birthPlaceHouseRoadEn" id="birthPlaceHouseRoadEn" value="Village: "></label>
</div>
</div></section>

<div class="parent-pair">
<section class="card"><div class="card-head"><span class="num">৪</span><h2>পিতার তথ্য<small>নাম বাংলা ও ইংরেজিতে দিন</small></h2></div><div class="card-body">
<div class="grid2 parent-verify-fields" style="margin-bottom:16px">
<label class="field"><span>জন্ম নিবন্ধন নম্বর (ঐচ্ছিক)</span><input name="fatherUbrn" id="fatherUbrn" inputmode="numeric" maxlength="17"></label>
<label class="field"><span>জন্ম তারিখ (ঐচ্ছিক)</span><input name="fatherDob" id="fatherDob" placeholder="DD/MM/YYYY" inputmode="numeric" maxlength="10"></label>
</div>
<label class="field"><span>পিতার নাম (বাংলা) <b>*</b></span><input name="fatherNameBn" id="fatherNameBn" required></label>
<div class="field" style="margin-top:16px"><span>পিতার নাম (English) <b>*</b></span><div class="input-group"><input name="fatherNameEn" id="fatherNameEn" aria-label="পিতার নাম (English)" required><button type="button" class="btn btn-ghost verify-btn hidden" id="fatherVerifyBtn" data-type="father">যাচাই</button></div></div>
</div></section>

<section class="card"><div class="card-head"><span class="num">৫</span><h2>মাতার তথ্য<small>নাম বাংলা ও ইংরেজিতে দিন</small></h2></div><div class="card-body">
<div class="grid2 parent-verify-fields" style="margin-bottom:16px">
<label class="field"><span>জন্ম নিবন্ধন নম্বর (ঐচ্ছিক)</span><input name="motherUbrn" id="motherUbrn" inputmode="numeric" maxlength="17"></label>
<label class="field"><span>জন্ম তারিখ (ঐচ্ছিক)</span><input name="motherDob" id="motherDob" placeholder="DD/MM/YYYY" inputmode="numeric" maxlength="10"></label>
</div>
<label class="field"><span>মাতার নাম (বাংলা) <b>*</b></span><input name="motherNameBn" id="motherNameBn" required></label>
<div class="field" style="margin-top:16px"><span>মাতার নাম (English) <b>*</b></span><div class="input-group"><input name="motherNameEn" id="motherNameEn" aria-label="মাতার নাম (English)" required><button type="button" class="btn btn-ghost verify-btn hidden" id="motherVerifyBtn" data-type="mother">যাচাই</button></div></div>
</div></section>
</div>

<section class="card"><div class="card-head"><span class="num">৬</span><h2>আবেদনকারী<small>আবেদন কে করছেন</small></h2></div><div class="card-body">
<div class="grid2">
<label class="field"><span>সম্পর্ক <b>*</b></span><select id="relationWithApplicant" disabled><option value="OTHERS" selected>অন্যান্য</option></select><input type="hidden" name="relationWithApplicant" value="OTHERS"></label>
<label class="field"><span>আবেদনকারীর নাম (English) <b>*</b></span><input name="applicantName" id="applicantName" value="Md Abdullah Al Emon" readonly required></label>
</div>
<div class="grid2" id="applicantExtra">
<label class="field"><span>আবেদনকারীর জন্ম নিবন্ধন নম্বর</span><input name="applicantBrn" id="applicantBrn" value="20041019510113908" readonly></label>
<label class="field"><span>আবেদনকারীর জন্ম তারিখ</span><input name="applicantDob" id="applicantDob" value="18/01/2004" readonly></label>
<label class="field"><span>আবেদনকারীর NID (ঐচ্ছিক)</span><input name="applicantNotParentsNid" id="applicantNotParentsNid" inputmode="numeric" maxlength="17"></label>
</div>
</div></section>

<section class="card"><div class="card-head"><span class="num">৭</span><h2>সংযুক্তি<small>জন্ম তারিখ অনুযায়ী প্রয়োজনীয় কাগজ</small></h2></div><div class="card-body">
<div class="uploader"><label class="field type-select"><span>সংযুক্তির ধরন <b>*</b></span><select id="attachmentType"><option value="">আগে জন্ম তারিখ দিন</option></select></label><div><button type="button" class="btn btn-main" id="uploadBtn">আপলোড</button></div></div>
<input type="file" id="fileInput" accept="image/jpeg,image/png,application/pdf" class="hidden">
<div class="hint">JPG, PNG বা PDF · সর্বোচ্চ ৫ মেগাবাইট · প্রতিটি ধরনের জন্য আলাদা ফাইল</div>
<div id="typeHint" class="notice hidden"></div>
<div class="files" id="fileList"></div>
</div></section>

<section class="card"><div class="card-head"><span class="num">৮</span><h2>মোবাইল যাচাই<small>ওটিপি কোড দিয়ে আবেদন নিশ্চিত করুন</small></h2></div><div class="card-body">
<div class="grid2">
<label class="field"><span>মোবাইল নম্বর <b>*</b></span><input name="phone" id="phone" placeholder="01XXXXXXXXX" inputmode="tel" maxlength="14" required><small class="hint">একই নম্বরে বারবার আবেদন করা যাবে</small></label>
<label class="field"><span>ইমেইল (ঐচ্ছিক)</span><input name="email" id="email" type="email" maxlength="120"></label>
</div>
<div class="actions"><button type="button" class="btn btn-ghost" id="sendOtpBtn">ওটিপি পাঠান</button></div>
<div class="grid2 hidden" id="otpBox" style="margin-top:14px">
<label class="field"><span>ওটিপি কোড <b>*</b></span><input id="otp" name="otp" inputmode="numeric" maxlength="8" autocomplete="one-time-code"></label>
<div style="display:flex;align-items:flex-end"><button type="button" class="btn btn-main" id="verifyOtpBtn">যাচাই করুন</button></div>
</div>
<div class="status" id="verifyStatus"><span>মোবাইল যাচাই</span><b>হয়নি</b></div>
</div></section>

<section class="card"><div class="card-head"><span class="num">৯</span><h2>ঘোষণা ও জমা<small>তথ্য সঠিক থাকলে জমা দিন</small></h2></div><div class="card-body">
<label class="declaration"><input type="checkbox" name="declaration" id="declaration" required><span>আমি ঘোষণা করছি যে প্রদত্ত তথ্য সত্য এবং সঠিক। ভুল তথ্য দিলে আবেদন বাতিল হতে পারে।</span></label>
<div class="actions"><button type="submit" class="btn btn-main" id="submitBtn" disabled>আবেদন জমা দিন</button><span class="hint" id="submitHint">আগে মোবাইল নম্বর যাচাই করুন</span></div>
</div></section>
</form>
</main>

<script>
const initialAttachments=<?php echo $ffAttachJson !== false ? $ffAttachJson : '[]'; ?>;
const bootReady=<?php echo $ffTokenReady ? 'true' : 'false'; ?>;
const $=function(s){return document.querySelector(s)};
const $$=function(s){return Array.prototype.slice.call(document.querySelectorAll(s))};
const api=function(n){return location.pathname+'?action='+n};
function toast(msg,bad){const t=document.getElementById('toast');t.textContent=msg;t.className='toast show'+(bad?' bad':'');clearTimeout(window.__tt);window.__tt=setTimeout(function(){t.className='toast'},4200)}
async function post(name,data){const r=await fetch(api(name),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(data||{})});let j=null;try{j=await r.json()}catch(e){throw new Error('সার্ভার থেকে সঠিক উত্তর আসেনি')}if(!r.ok||!j||!j.ok){throw new Error((j&&(j.message||j.error))||'অনুরোধ ব্যর্থ হয়েছে')}return j}
function val(id){const e=document.getElementById(id);return e?String(e.value||'').trim():''}
function enDigits(v){return String(v||'').replace(/[০-৯]/g,function(d){return String('০১২৩৪৫৬৭৮৯'.indexOf(d))})}
function fullName(a,b){return (val(a)+' '+val(b)).trim()}
function updateNames(){const bn=fullName('personFirstBn','personLastBn');const en=fullName('personFirstEn','personLastEn');$('#namePreviewBn').textContent=bn||'—';$('#namePreviewEn').textContent=en||'—';syncApplicant()}
function syncApplicant(){const rel=val('relationWithApplicant');const el=$('#applicantName');const extra=$('#applicantExtra');if(rel==='SELF'){el.value=fullName('personFirstEn','personLastEn');el.readOnly=true;extra.classList.add('hidden')}else if(rel==='FATHER'){el.value=val('fatherNameEn');el.readOnly=true;extra.classList.remove('hidden')}else if(rel==='MOTHER'){el.value=val('motherNameEn');el.readOnly=true;extra.classList.remove('hidden')}else if(rel==='OTHERS'){el.readOnly=true;extra.classList.remove('hidden')}else{el.readOnly=false;extra.classList.remove('hidden')}}
['personFirstBn','personLastBn','personFirstEn','personLastEn','fatherNameBn','fatherNameEn','motherNameBn','motherNameEn'].forEach(function(id){const e=document.getElementById(id);if(e)e.addEventListener('input',updateNames)});
if($('#relationWithApplicant'))$('#relationWithApplicant').addEventListener('change',syncApplicant);
['dob','fatherDob','motherDob','applicantDob'].forEach(function(id){const e=document.getElementById(id);if(e)e.addEventListener('blur',function(){const d=enDigits(e.value).replace(/\D/g,'');if(d.length===8){e.value=d.slice(0,2)+'/'+d.slice(2,4)+'/'+d.slice(4)}else{e.value=String(e.value).replace(/[^0-9\/০-৯]/g,'')}})});
function dobValue(id){const d=enDigits(val(id));return /^\d{2}\/\d{2}\/\d{4}$/.test(d)?d:''}
function parentVerifyAllowed(){const d=dobValue('dob');if(!d)return false;return Number(d.split('/')[2])>=2013}
function toggleVerifyButtons(){const on=parentVerifyAllowed();$$('.verify-btn').forEach(function(b){b.classList.toggle('hidden',!on);b.disabled=!on});$$('.parent-verify-fields').forEach(function(box){box.classList.toggle('hidden',!on);Array.prototype.forEach.call(box.querySelectorAll('input'),function(inp){inp.disabled=!on})})}
function resetVerifyButton(type){const b=document.getElementById(type+'VerifyBtn');if(!b)return;b.classList.remove('is-done');b.textContent='যাচাই'}
async function verifyParent(btn){const type=btn.dataset.type==='mother'?'mother':'father';const label=type==='father'?'পিতার':'মাতার';const ubrn=enDigits(val(type+'Ubrn')).replace(/\D/g,'');const dob=dobValue(type+'Dob');const nameEn=val(type+'NameEn');const childDob=dobValue('dob');if(!parentVerifyAllowed()){toast('শিশুর জন্ম তারিখ ২০১৩ বা পরে হলে যাচাই করা যাবে',true);return}if(ubrn.length!==17){toast('১৭ ডিজিটের জন্ম নিবন্ধন নম্বর দিন',true);return}if(!dob){toast(label+' জন্ম তারিখ DD/MM/YYYY আকারে দিন',true);return}if(!nameEn){toast(label+' নাম ইংরেজিতে দিন',true);return}resetVerifyButton(type);setBusy(btn,true);let done=false;try{const j=await post('parent_info',{type:type,ubrn:ubrn,dob:dob,nameEn:nameEn,childDob:childDob});if(j.nameBn)document.getElementById(type+'NameBn').value=j.nameBn;if(j.nameEn)document.getElementById(type+'NameEn').value=j.nameEn;updateNames();toast(j.message||label+' তথ্য যাচাই হয়েছে');done=true}catch(e){toast(e.message,true)}finally{setBusy(btn,false);if(done){btn.textContent='যাচাই হয়েছে';btn.classList.add('is-done')}}}
$$('.verify-btn').forEach(function(btn){btn.addEventListener('click',function(){verifyParent(btn)})});
['fatherUbrn','fatherDob','fatherNameEn','motherUbrn','motherDob','motherNameEn'].forEach(function(id){const e=document.getElementById(id);if(e)e.addEventListener('input',function(){resetVerifyButton(id.indexOf('father')===0?'father':'mother')})});
$('#dob').addEventListener('input',toggleVerifyButtons);
toggleVerifyButtons();
</script>

<script>
const uploaded=Array.isArray(initialAttachments)?initialAttachments.slice():[];
function renderFiles(){const box=$('#fileList');box.innerHTML='';if(!uploaded.length){box.innerHTML='<div class="hint" style="padding:10px 0">এখনো কোনো সংযুক্তি নেই</div>';return}uploaded.forEach(function(a){const row=document.createElement('div');row.className='file';const t=document.createElement('div');t.className='t';const s=document.createElement('strong');s.textContent=a.typeName||('সংযুক্তি #'+a.type);const m=document.createElement('small');m.textContent=(a.name||'file')+' · '+Math.max(1,Math.round((Number(a.size)||0)/1024))+' KB';t.appendChild(s);t.appendChild(m);const id=document.createElement('span');id.className='id';id.textContent='#'+a.type;const b=document.createElement('button');b.type='button';b.className='btn btn-danger btn-sm';b.textContent='মুছুন';b.addEventListener('click',async function(){b.disabled=true;try{const j=await post('remove',{id:String(a.id)});uploaded.length=0;j.items.forEach(function(x){uploaded.push(x)});renderFiles();updateTypeHint();toast('সংযুক্তি মুছে ফেলা হয়েছে')}catch(e){toast(e.message,true);b.disabled=false}});row.appendChild(t);row.appendChild(id);row.appendChild(b);box.appendChild(row)});}
function updateTypeHint(){const hint=$('#typeHint');const sel=$('#attachmentType');const ids=Array.from(sel.options).filter(function(o){return o.value}).map(function(o){return Number(o.value)});if(!ids.length){hint.classList.add('hidden');return}const have=uploaded.map(function(a){return Number(a.type)});const missing=ids.filter(function(id){return have.indexOf(id)<0});hint.classList.remove('hidden');if(missing.length){hint.textContent='এখনো বাকি: '+missing.map(function(id){const o=Array.from(sel.options).find(function(x){return Number(x.value)===id});return o?o.textContent:('#'+id)}).join(', ')}else{hint.textContent='প্রয়োজনীয় সংযুক্তি সম্পূর্ণ হয়েছে'}}
async function loadTypes(){const dob=val('dob');const sel=$('#attachmentType');if(!/^\d{2}\/\d{2}\/\d{4}$/.test(enDigits(dob))){sel.innerHTML='<option value="">আগে জন্ম তারিখ দিন</option>';updateTypeHint();return}sel.innerHTML='<option value="">আন হচ্ছে…</option>';try{const j=await post('attachment_types',{dob:enDigits(dob)});sel.innerHTML='';if(!j.items.length){sel.innerHTML='<option value="">কোনো ধরন পাওয়া যায়নি</option>';updateTypeHint();return}j.items.forEach(function(t){const o=document.createElement('option');o.value=String(t.id);o.textContent=t.nameBn;sel.appendChild(o)});updateTypeHint()}catch(e){const ids=[40,41];sel.innerHTML='';ids.forEach(function(id){const o=document.createElement('option');o.value=String(id);o.textContent='সংযুক্তি #'+id;sel.appendChild(o)});toast(e.message,true)}}
let typeTimer=null;$('#dob').addEventListener('input',function(){clearTimeout(typeTimer);typeTimer=setTimeout(loadTypes,500)});

function setHidden(k,v){const map={div:'geoDiv',dist:'geoDist',city:'geoCity',union:'geoUnion',ward:'geoWard',area:'geoArea',locationId:'geoLocationId',cityLevel:'geoCityLevel',wardKind:'geoWardKind'};const e=document.getElementById(map[k]);if(e)e.value=String(v)}
function syncGeoName(selId,hidBn,hidEn){const el=document.getElementById(selId);const o=el?el.options[el.selectedIndex]:null;const bn=document.getElementById(hidBn);const en=document.getElementById(hidEn);if(!bn||!en)return;if(!o||!o.value){bn.value='';en.value='';return}bn.value=o.textContent;en.value=o.dataset.nameEn||''}
function syncGeoNames(){syncGeoName('birthPlaceDiv','geoDivName','geoDivNameEn');syncGeoName('birthPlaceDist','geoDistName','geoDistNameEn');syncGeoName('birthPlaceCityCorpCantOrUpazila','geoCityName','geoCityNameEn');syncGeoName('birthPlacePaurasavaOrUnion','geoUnionName','geoUnionNameEn');syncGeoName('birthPlaceWardInPaurasavaOrUnion','geoWardName','geoWardNameEn')}
function clearSelect(el,ph){if(!el)return;el.innerHTML='';const o=document.createElement('option');o.value='';o.textContent=ph;el.appendChild(o)}
function fillSelect(el,items,ph){clearSelect(el,ph);items.forEach(function(it){const o=document.createElement('option');o.value=String(it.id);o.textContent=it.nameBn||it.nameEn||('#'+it.id);o.dataset.level=String(it.level||'');o.dataset.parent=String(it.parent||'');o.dataset.nameEn=String(it.nameEn||'');el.appendChild(o)})}
function selectedLevel(el){const o=el&&el.options[el.selectedIndex];return o?Number(o.dataset.level||0):0}
async function loadGeo(parent,order,type,ward,el,ph){fillSelect(el,[],'আন হচ্ছে…');try{const q=await post('geo',{parent:parent,order:order,type:type,ward:ward?'1':'0'});fillSelect(el,q.items,ph);return q.items}catch(e){fillSelect(el,[],ph);toast(e.message,true);return []}}
async function onDivision(){const v=Number(val('birthPlaceDiv'));setHidden('div',v);setHidden('dist',0);setHidden('city',0);setHidden('union',0);setHidden('ward',0);setHidden('area',0);setHidden('locationId',0);clearSelect($('#birthPlaceDist'),'জেলা নির্বাচন করুন');clearSelect($('#birthPlaceCityCorpCantOrUpazila'),'উপজেলা / সিটি নির্বাচন করুন');clearSelect($('#birthPlacePaurasavaOrUnion'),'ইউনিয়ন / পৌরসভা নির্বাচন করুন');clearSelect($('#birthPlaceWardInPaurasavaOrUnion'),'ওয়ার্ড নির্বাচন করুন');if(v>0)await loadGeo(v,2,2,false,$('#birthPlaceDist'),'জেলা নির্বাচন করুন')}
async function onDistrict(){const v=Number(val('birthPlaceDist'));setHidden('dist',v);setHidden('city',0);setHidden('union',0);setHidden('ward',0);setHidden('area',0);setHidden('locationId',0);clearSelect($('#birthPlaceCityCorpCantOrUpazila'),'উপজেলা / সিটি নির্বাচন করুন');clearSelect($('#birthPlacePaurasavaOrUnion'),'ইউনিয়ন / পৌরসভা নির্বাচন করুন');clearSelect($('#birthPlaceWardInPaurasavaOrUnion'),'ওয়ার্ড নির্বাচন করুন');if(v>0)await loadGeo(v,3,3,false,$('#birthPlaceCityCorpCantOrUpazila'),'উপজেলা / সিটি নির্বাচন করুন')}
async function onCity(){const el=$('#birthPlaceCityCorpCantOrUpazila');const v=Number(val('birthPlaceCityCorpCantOrUpazila'));const lvl=selectedLevel(el);setHidden('city',v);setHidden('cityLevel',lvl);setHidden('union',0);setHidden('ward',0);setHidden('area',0);setHidden('locationId',0);const unionRow=document.querySelector('.geo-union-row');const wardRow=document.querySelector('.geo-ward-row');clearSelect($('#birthPlacePaurasavaOrUnion'),'ইউনিয়ন / পৌরসভা নির্বাচন করুন');clearSelect($('#birthPlaceWardInPaurasavaOrUnion'),'ওয়ার্ড নির্বাচন করুন');if(v<=0)return;if(lvl===3){unionRow.classList.remove('hidden');wardRow.classList.remove('hidden');setHidden('wardKind','union');$('#birthPlacePaurasavaOrUnion').disabled=false;await loadGeo(v,4,5,false,$('#birthPlacePaurasavaOrUnion'),'ইউনিয়ন / পৌরসভা নির্বাচন করুন')}else if(lvl===4||lvl===5){unionRow.classList.remove('hidden');wardRow.classList.remove('hidden');setHidden('wardKind','union');const opt=el.options[el.selectedIndex];const u=$('#birthPlacePaurasavaOrUnion');u.innerHTML='';const o=document.createElement('option');o.value=String(v);o.textContent=opt.textContent;o.dataset.nameEn=opt.dataset.nameEn||'';u.appendChild(o);setHidden('union',v);setHidden('locationId',v);await loadGeo(v,4,lvl,true,$('#birthPlaceWardInPaurasavaOrUnion'),'ওয়ার্ড নির্বাচন করুন')}else if(lvl===7){unionRow.classList.add('hidden');wardRow.classList.add('hidden');setHidden('wardKind','city');setHidden('locationId',v)}else if(lvl===8){unionRow.classList.add('hidden');wardRow.classList.add('hidden');setHidden('wardKind','city');setHidden('locationId',v)}else{unionRow.classList.remove('hidden');wardRow.classList.remove('hidden');setHidden('wardKind','union');await loadGeo(v,4,5,false,$('#birthPlacePaurasavaOrUnion'),'ইউনিয়ন / পৌরসভা নির্বাচন করুন')}}
async function onUnion(){const v=Number(val('birthPlacePaurasavaOrUnion'));setHidden('union',v);setHidden('locationId',v);setHidden('ward',0);clearSelect($('#birthPlaceWardInPaurasavaOrUnion'),'ওয়ার্ড নির্বাচন করুন');if(v>0){const lvl=Number(val('geoCityLevel'))||3;await loadGeo(v,4,lvl===4?4:5,true,$('#birthPlaceWardInPaurasavaOrUnion'),'ওয়ার্ড নির্বাচন করুন')}}
const voterAreas=[
{id:'101866',bn:'বাগবাড়ী',en:'Bagbari',ward:1},
{id:'101867',bn:'কুশারঘোপ',en:'Kusharghop',ward:1},
{id:'101868',bn:'চক সৈয়দপুর',en:'Chak Sayedpur',ward:1},
{id:'101869',bn:'জনার্দনপুর',en:'Jonardanpur',ward:1},
{id:'101870',bn:'পুরকায়েত',en:'Purkayet',ward:1},
{id:'101871',bn:'মোকন্দপুর',en:'Mokondopur',ward:1},
{id:'101872',bn:'রাখালগাছী',en:'Rakhalgachi',ward:1},
{id:'101873',bn:'গবারপাড়া',en:'Gobarpara',ward:3},
{id:'101874',bn:'ছোট বালুয়া',en:'Choto Balua',ward:3},
{id:'101875',bn:'দীঘিরপাড়া',en:'Dighirpara',ward:2},
{id:'101876',bn:'আগাবরগাছা, দাউদপুর, কর্ণীপাড়া',en:'Agaborgacha, Daudpur, Kornipara',ward:2},
{id:'101877',bn:'উত্তর আটকরিয়া',en:'Uttor Atkoria',ward:4},
{id:'101878',bn:'ঝিনারপাড়া',en:'Jhinarpara',ward:4},
{id:'101879',bn:'দক্ষিণ আটকরিয়া',en:'Dokkhin Atkoria',ward:5},
{id:'101880',bn:'বামুনিয়া',en:'Bamunia',ward:5},
{id:'101881',bn:'উত্তর দিঘলকান্দি',en:'Uttor Digholkandi',ward:6},
{id:'101882',bn:'বালুয়াপাড়া',en:'Baluapara',ward:6},
{id:'101883',bn:'হাড়িয়াকান্দি',en:'Hariakandi',ward:6},
{id:'101884',bn:'পাতিলাকুড়া',en:'Patilakura',ward:7},
{id:'101885',bn:'রশিদপুর',en:'Roshidpur',ward:7},
{id:'101886',bn:'রশিনাবাড়ী',en:'Roshinabari',ward:7},
{id:'101887',bn:'ধর্মকুল',en:'Dhormokul',ward:8},
{id:'101888',bn:'পুগলিয়া',en:'Puglia',ward:8},
{id:'101889',bn:'মহিষাবাড়ী',en:'Mohishabari',ward:8},
{id:'101890',bn:'নগরপাড়া',en:'Nogorpara',ward:9},
{id:'101891',bn:'বড় বালুয়া',en:'Boro Balua',ward:9},
{id:'101892',bn:'সমজাতাইড়',en:'Somjatair',ward:9}
];
function selectedWardNo(){const s=$('#birthPlaceWardInPaurasavaOrUnion');const o=s&&s.options[s.selectedIndex];return o&&o.value?Number(o.dataset.ward||0):0}
function resetVoterAreaFields(){const bn=$('#birthPlaceHouseRoadBn');const en=$('#birthPlaceHouseRoadEn');if(bn)bn.value='গ্রামঃ ';if(en)en.value='Village: ';const s=$('#voterArea');if(s)s.dataset.applied=''}
function fillVoterAreas(){const s=$('#voterArea');if(!s)return;const w=selectedWardNo();clearSelect(s,w?'এলাকা নির্বাচন করুন':'আগে ওয়ার্ড নির্বাচন করুন');s.disabled=!w;if(!w)return;voterAreas.filter(function(a){return a.ward===w}).forEach(function(a){const o=document.createElement('option');o.value=a.id;o.textContent=a.bn;o.dataset.bn=a.bn;o.dataset.en=a.en;s.appendChild(o)})}
function onWardVoterChange(){const s=$('#voterArea');if(s&&s.dataset.applied==='1')resetVoterAreaFields();fillVoterAreas()}
function applyVoterArea(){const s=$('#voterArea');const o=s&&s.options[s.selectedIndex];if(!o||!o.value){if(s&&s.dataset.applied==='1')resetVoterAreaFields();return}const bn=$('#birthPlaceHouseRoadBn');const en=$('#birthPlaceHouseRoadEn');if(bn)bn.value='গ্রামঃ '+o.dataset.bn;if(en)en.value='Village: '+o.dataset.en;s.dataset.applied='1'}
$('#birthPlaceDiv').addEventListener('change',onDivision);
$('#birthPlaceDist').addEventListener('change',onDistrict);
$('#birthPlaceCityCorpCantOrUpazila').addEventListener('change',onCity);
$('#birthPlacePaurasavaOrUnion').addEventListener('change',onUnion);
$('#birthPlaceWardInPaurasavaOrUnion').addEventListener('change',function(){setHidden('ward',Number(val('birthPlaceWardInPaurasavaOrUnion')))});
$('#birthPlaceWardInPaurasavaOrUnion').addEventListener('change',onWardVoterChange);
$('#voterArea').addEventListener('change',applyVoterArea);
['birthPlaceDiv','birthPlaceDist','birthPlaceCityCorpCantOrUpazila','birthPlacePaurasavaOrUnion','birthPlaceWardInPaurasavaOrUnion'].forEach(function(id){const el=document.getElementById(id);if(el)el.addEventListener('change',function(){setTimeout(syncGeoNames,0)})});

function setBusy(btn,on){if(!btn)return;btn.disabled=on;if(on){btn.dataset.old=btn.textContent;btn.textContent='অপেক্ষা করুন…'}else if(btn.dataset.old){btn.textContent=btn.dataset.old}}
function otpNames(){return {'personInfoForBirth.personNameBn':fullName('personFirstBn','personLastBn'),'personInfoForBirth.personNameEn':fullName('personFirstEn','personLastEn'),'personInfoForBirth.father.personNameBn':val('fatherNameBn'),'personInfoForBirth.father.personNameEn':val('fatherNameEn'),'personInfoForBirth.mother.personNameBn':val('motherNameBn'),'personInfoForBirth.mother.personNameEn':val('motherNameEn')}}
function otpPayload(){return {phone:val('phone'),email:val('email'),relation:val('relationWithApplicant'),applicantName:val('applicantName'),applicantBrn:val('applicantBrn'),applicantDob:val('applicantDob'),officeAddressType:'BIRTHPLACE',geoLocationId:Number(val('geoLocationId')),names:otpNames()}}
let verified=false;
let verifiedPhone='';
function requireBasic(){if(!bootReady){toast('BDRIS সেশন প্রস্তুত নয়, পেজ রিফ্রেশ করুন',true);return false}if(!fullName('personFirstEn','personLastEn')||!fullName('personFirstBn','personLastBn')){toast('শিশুর নাম আগে দিন',true);return false}if(!/^\d{11}$/.test(enDigits(val('phone')).replace(/\D/g,''))){toast('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন',true);return false}if(!val('relationWithApplicant')||!val('applicantName')){toast('আবেদনকারীর তথ্য দিন',true);return false}return true}
$('#sendOtpBtn').addEventListener('click',async function(){if(!requireBasic())return;const b=this;setBusy(b,true);try{const j=await post('send_otp',otpPayload());toast(j.message||'ওটিপি পাঠানো হয়েছে');$('#otpBox').classList.remove('hidden');$('#otp').focus();$('#verifyStatus').className='status';$('#verifyStatus').querySelector('b').textContent='ওটিপি পাঠানো হয়েছে'}catch(e){toast(e.message,true)}finally{setBusy(b,false)}});
$('#verifyOtpBtn').addEventListener('click',async function(){const code=enDigits(val('otp')).replace(/\D/g,'');if(code.length<4){toast('ওটিপি কোড দিন',true);return}const b=this;setBusy(b,true);try{const p=otpPayload();p.otp=code;const j=await post('verify_otp',p);verified=true;verifiedPhone=enDigits(val('phone')).replace(/\D/g,'');toast(j.message||'যাচাই হয়েছে');$('#verifyStatus').className='status ok';$('#verifyStatus').querySelector('b').textContent='যাচাই হয়েছে';$('#submitBtn').disabled=false;$('#submitHint').textContent='ওটিপি যাচাই হয়েছে, আবেদন জমা হচ্ছে…';setTimeout(function(){appForm.requestSubmit()},400)}catch(e){toast(e.message,true)}finally{setBusy(b,false)}});

$('#uploadBtn').addEventListener('click',function(){if(!bootReady){toast('BDRIS সেশন প্রস্তুত নয়, পেজ রিফ্রেশ করুন',true);return}const type=val('attachmentType');if(!type){toast('আগে জন্ম তারিখ দিন',true);return}if(uploaded.some(function(a){return Number(a.type)===Number(type)})){toast('এই ধরনের সংযুক্তি আগেই আছে, মুছে আবার চেষ্টা করুন',true);return}$('#fileInput').click()});
$('#fileInput').addEventListener('change',async function(){const f=this.files&&this.files[0];if(!f)return;const sel=$('#attachmentType');const o=sel.options[sel.selectedIndex];const type=Number(sel.value);const typeName=o?o.textContent:'';const b=$('#uploadBtn');setBusy(b,true);try{const fd=new FormData();fd.append('file',f);fd.append('attachmentType',String(type));fd.append('attachmentName',typeName);const r=await fetch(api('upload'),{method:'POST',body:fd,headers:{'Accept':'application/json'}});let j=null;try{j=await r.json()}catch(e){}if(!r.ok||!j||!j.ok)throw new Error((j&&j.message)||'আপলোড হয়নি');uploaded.length=0;j.items.forEach(function(x){uploaded.push(x)});renderFiles();updateTypeHint();toast('সংযুক্তি যোগ হয়েছে')}catch(e){toast(e.message,true)}finally{$('#fileInput').value='';setBusy(b,false)}});

const appForm=$('#appForm');
appForm.addEventListener('submit',function(e){if(!verified){e.preventDefault();toast('আগে মোবাইল নম্বর যাচাই করুন',true);return}if(verifiedPhone&&verifiedPhone!==enDigits(val('phone')).replace(/\D/g,'')){e.preventDefault();toast('নম্বর বদলেছে, আবার ওটিপি যাচাই করুন',true);return}if(!uploaded.length){e.preventDefault();toast('প্রয়োজনীয় সংযুক্তি দিন',true);return}const sel=$('#attachmentType');const ids=Array.from(sel.options).filter(function(o){return o.value}).map(function(o){return Number(o.value)});const have=uploaded.map(function(a){return Number(a.type)});if(ids.some(function(id){return have.indexOf(id)<0})){e.preventDefault();toast('প্রতিটি প্রয়োজনীয় সংযুক্তি যোগ করুন',true);return}if(!appForm.checkValidity()){e.preventDefault();appForm.reportValidity();return}const b=$('#submitBtn');b.disabled=true;b.textContent='জমা হচ্ছে…';});

syncGeoNames();
fillVoterAreas();
renderFiles();
updateTypeHint();
if(val('dob'))loadTypes();
</script>
<?php ff_page_close(); ?>
