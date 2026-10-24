<?php
/**
 * نت سایت v5.0 — ساده، سریع، بدون هیچ وابستگی بیرونی (بدون CDN)
 * PHP 7.4+ · مناسب هاست‌های رایگان (InfinityFree و ...)
 * طراحی و توسعه: امیرحسین واقی — تمام حقوق محفوظ است.
 *
 * ساختار:
 *   users/<user>/info.json     (خصوصی)
 *   sites/<user>/<project>/…   (فایل‌های عمومی سایت)
 *
 * معماری: ۱) توابع پشت‌صحنه  ۲) عملیات (POST)  ۳) سه صفحه: داشبورد، مرورگر فایل، ویرایشگر
 */

/* =====================================================================
   ۱) تنظیمات و توابع پایه
   ===================================================================== */
ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('VER', '5.0');
define('USERS', __DIR__ . '/users');
define('SITES', __DIR__ . '/sites');
define('MAX_FILE', 3145728);        // 3MB هر فایل
define('MAX_ZIP', 31457280);        // 30MB هر آرشیو
define('MAX_ZIP_ITEMS', 1500);
define('QUOTA', 52428800);          // 50MB هر کاربر
define('MAX_PROJECTS', 100);
define('MAX_DEPTH', 12);
define('AJAX', ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['ajax'] ?? '') === '1'));
define('TEXT_EXT', ['html', 'htm', 'css', 'js', 'json', 'txt', 'md', 'svg', 'xml', 'webmanifest']);
define('OK_EXT', ['html', 'htm', 'css', 'js', 'json', 'txt', 'md', 'svg', 'xml', 'webmanifest',
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'mp3', 'mp4', 'webm', 'pdf']);

// خطای مرگبار در درخواست AJAX به‌صورت JSON گزارش می‌شود
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && AJAX && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        while (ob_get_level()) { @ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'خطای سرور: ' . substr($e['message'], 0, 200)], JSON_UNESCAPED_UNICODE);
    }
});

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function jjs($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }
function jout($a) {
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}
function flash($m, $t = 'ok') { $_SESSION['flash'] = [$m, $t]; }
function go($q = '') {
    $base = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    while (ob_get_level()) { @ob_end_clean(); }
    header('Location: ' . ($base ?: './') . $q);
    exit;
}
function back($p, $d = '') { go('?p=' . urlencode($p) . ($d !== '' ? '&d=' . urlencode($d) : '')); }
function fsize($b) {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024, 1) . ' KB';
    return (int)$b . ' B';
}
function ini_bytes($k) {
    $v = trim((string)ini_get($k));
    if ($v === '') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return (int)$n;
}

/* ---------- فایل‌سیستم ---------- */
function mk($p) {
    if (!is_dir($p) && !@mkdir($p, 0755, true) && !is_dir($p)) return false;
    @chmod($p, 0755);
    return true;
}
function put($p, $c) {
    if (@file_put_contents($p, $c, LOCK_EX) === false) return false;
    @chmod($p, 0644);
    return true;
}
function rmtree($p) {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (!is_dir($p)) return;
    foreach (scandir($p) as $i) { if ($i !== '.' && $i !== '..') rmtree($p . '/' . $i); }
    @rmdir($p);
}
function copytree($s, $d) {
    mk($d);
    foreach (scandir($s) as $i) {
        if ($i === '.' || $i === '..' || is_link($s . '/' . $i)) continue;
        if (is_dir($s . '/' . $i)) copytree($s . '/' . $i, $d . '/' . $i);
        else { @copy($s . '/' . $i, $d . '/' . $i); @chmod($d . '/' . $i, 0644); }
    }
}

mk(USERS); mk(SITES);
if (!is_file(USERS . '/.htaccess')) put(USERS . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
if (!is_file(USERS . '/index.html')) put(USERS . '/index.html', '');
if (!is_file(SITES . '/.htaccess')) put(SITES . '/.htaccess', <<<'HT'
Options -Indexes
AddDefaultCharset UTF-8
<IfModule mod_authz_core.c>
    <FilesMatch "\.(php[0-9]?|phtml|phar|pl|py|cgi|sh|asp|aspx|jsp)$">
        Require all denied
    </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
    <FilesMatch "\.(php[0-9]?|phtml|phar|pl|py|cgi|sh|asp|aspx|jsp)$">
        Order allow,deny
        Deny from all
    </FilesMatch>
</IfModule>
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set Content-Security-Policy "sandbox allow-scripts allow-forms allow-popups allow-modals"
</IfModule>
HT
);
ini_set('error_log', USERS . '/error.log');
if (!is_file(__DIR__ . '/.htaccess')) {
    $eu = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/' . basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $ht = ''; foreach ([400, 401, 403, 404, 500, 503] as $ec) $ht .= "ErrorDocument $ec $eu?err=$ec\n";
    put(__DIR__ . '/.htaccess', $ht);
}

/* ---------- نام‌ها (نرمال‌سازی می‌شوند، رد نمی‌شوند) ---------- */
function act_log($u, $m) {
    $f = USERS . '/' . $u . '/log.json';
    $a = json_decode((string)@file_get_contents($f), true);
    if (!is_array($a)) $a = [];
    array_unshift($a, [time(), $m]);
    put($f, json_encode(array_slice($a, 0, 40), JSON_UNESCAPED_UNICODE));
}
function snap($me, $proj, $path, $full, $raw) {
    if (!is_file($full) || md5_file($full) === md5($raw)) return;
    $d = USERS . '/' . $me . '/hist/' . $proj . '/' . md5($path);
    if (!mk($d)) return;
    @copy($full, $d . '/' . time() . '.bak');
    $l = glob($d . '/*.bak') ?: [];
    sort($l);
    while (count($l) > 10) @unlink(array_shift($l));
}
function rl($k, $max, $win) {
    $f = USERS . '/.rl-' . md5($k);
    $a = array_values(array_filter((array)json_decode((string)@file_get_contents($f), true), function ($t) use ($win) { return $t > time() - $win; }));
    if (count($a) >= $max) return false;
    $a[] = time();
    put($f, json_encode($a));
    return true;
}
function welcome($t) {
    return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t . '</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#0b1020;color:#e8ebf6;font-family:Tahoma,"Segoe UI",sans-serif;line-height:1.9}main{max-width:620px;width:100%;text-align:center}.ok{width:72px;height:72px;margin:0 auto 20px;border-radius:50%;background:#10b981;display:grid;place-items:center}h1{margin:0 0 8px;font-size:clamp(24px,5vw,34px)}p{margin:0 0 22px;color:#8f98bb}ol{list-style:none;margin:0;padding:0;display:grid;gap:10px;text-align:right}li{padding:14px 18px;border:1px solid #252f55;background:#121936;border-radius:12px}li b{color:#fff}footer{margin-top:28px;font-size:13px;color:#8f98bb}footer b{color:#e8ebf6}code{direction:ltr;unicode-bidi:embed;background:#0b1020;padding:1px 6px;border-radius:5px}</style></head><body><main><div class="ok"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div><h1>سایت شما با موفقیت ساخته شد</h1><p>پروژهٔ «' . $t . '» آماده و آنلاین است. حالا می‌توانید آن را ویرایش کنید.</p><ol><li><b>۱. ویرایش صفحه:</b> در نت سایت وارد پروژه شوید و فایل <code>index.html</code> را باز کنید.</li><li><b>۲. افزودن فایل:</b> تصویر، استایل و صفحه‌های دیگر را با کشیدن و رها کردن آپلود کنید.</li><li><b>۳. انتشار:</b> لینک سایت را کپی کنید و با دیگران به اشتراک بگذارید.</li></ol><footer>این سایت با <b>نت سایت</b> ساخته شده است</footer></main></body></html>';
}
function clean_user($s) {
    $s = strtolower(trim((string)$s));
    return preg_match('/^[a-z0-9_-]{3,30}$/', $s) ? $s : '';
}
function clean_project($s) {
    $s = preg_replace('/\s+/u', '-', trim((string)$s));
    return substr((string)preg_replace('/[^A-Za-z0-9_-]/', '', (string)$s), 0, 50);
}
function seg($s) {
    $s = preg_replace('/\s+/u', '_', trim((string)$s));
    $s = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$s);
    return substr(ltrim((string)$s, '.'), 0, 80);
}
function norm_dir($p, &$why = null, $allowEmpty = true) {
    $out = [];
    foreach (explode('/', str_replace('\\', '/', trim((string)$p))) as $x) {
        $x = trim($x);
        if ($x === '' || $x === '.') continue;
        if ($x === '..') { $why = 'مسیر نامعتبر'; return ''; }
        $c = seg($x);
        $out[] = $c !== '' ? $c : 'd' . substr(md5($x), 0, 6);
    }
    if (!$out) { if (!$allowEmpty) $why = 'نام پوشه خالی است'; return ''; }
    if (count($out) > MAX_DEPTH) { $why = 'پوشه‌ها بیش از حد تو در تو هستند'; return ''; }
    return implode('/', $out);
}
function norm_path($p, &$why = null) {
    $segs = [];
    foreach (explode('/', str_replace('\\', '/', trim((string)$p))) as $x) {
        $x = trim($x);
        if ($x === '' || $x === '.') continue;
        if ($x === '..') { $why = 'مسیر نامعتبر'; return ''; }
        $segs[] = $x;
    }
    if (!$segs) { $why = 'نام فایل خالی است'; return ''; }
    if (count($segs) > MAX_DEPTH + 1) { $why = 'پوشه‌ها بیش از حد تو در تو هستند'; return ''; }
    $last = count($segs) - 1; $out = [];
    foreach ($segs as $i => $x) {
        if ($i === $last) {
            $ext = strtolower(pathinfo($x, PATHINFO_EXTENSION));
            if (!in_array($ext, OK_EXT, true)) { $why = $ext === '' ? 'فایل پسوند ندارد' : 'پسوند «' . $ext . '» مجاز نیست'; return ''; }
            $b = seg(pathinfo($x, PATHINFO_FILENAME));
            $out[] = ($b !== '' ? $b : 'f' . substr(md5($x), 0, 6)) . '.' . $ext;
        } else {
            $c = seg($x);
            $out[] = $c !== '' ? $c : 'd' . substr(md5($x), 0, 6);
        }
    }
    return implode('/', $out);
}
function is_text($p) { return in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), TEXT_EXT, true); }

/* ---------- کاربران و پروژه‌ها ---------- */
function load_user($u) {
    if ($u === '' || $u === null) return null;
    $f = USERS . '/' . $u . '/info.json';
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function save_user($u, $d) { return put(USERS . '/' . $u . '/info.json', json_encode($d, JSON_UNESCAPED_UNICODE)); }
function projects($u) {
    $o = [];
    foreach ((array)@scandir(SITES . '/' . $u) as $i) {
        if ($i === '.' || $i === '..' || $i[0] === '.') continue;
        if (is_dir(SITES . '/' . $u . '/' . $i)) $o[] = $i;
    }
    return $o;
}
/** آمار بازگشتی: [فایل، پوشه، بایت] */
function stats($base) {
    $f = 0; $d = 0; $b = 0;
    if (!is_dir($base)) return [0, 0, 0];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $x) {
        if ($x->isLink()) continue;
        if ($x->isDir()) $d++; elseif ($x->isFile()) { $f++; $b += (int)$x->getSize(); }
    }
    return [$f, $d, $b];
}
function usage($u) {
    $t = 0;
    foreach (projects($u) as $p) $t += stats(SITES . '/' . $u . '/' . $p)[2];
    return $t;
}
/** محتوای یک پوشه (یک سطح) */
function ls($base, $rel) {
    $d = $rel === '' ? $base : $base . '/' . $rel;
    $dirs = []; $files = [];
    foreach ((array)@scandir($d) as $n) {
        if ($n === '.' || $n === '..' || $n[0] === '.') continue;
        $p = $d . '/' . $n;
        if (is_link($p)) continue;
        if (is_dir($p)) $dirs[] = $n; elseif (is_file($p)) $files[] = ['n' => $n, 's' => (int)filesize($p)];
    }
    natcasesort($dirs);
    usort($files, function ($a, $b) { return strnatcasecmp($a['n'], $b['n']); });
    return [array_values($dirs), $files];
}
function gate($me, $proj) {
    if ($proj === '') return 'نام پروژه نامعتبر است (فقط حروف انگلیسی، عدد، - و _).';
    $ex = projects($me);
    if (!in_array($proj, $ex, true) && count($ex) >= MAX_PROJECTS) return 'به سقف تعداد پروژه‌ها رسیده‌اید.';
    return '';
}
/** ذخیرهٔ یک فایل با رعایت سهمیه */
function store($me, $proj, $rel, $raw, &$used, &$skip) {
    $why = '';
    $path = norm_path($rel, $why);
    if ($path === '') { $skip[] = [(string)$rel, $why]; return false; }
    $n = strlen($raw);
    if ($n > MAX_FILE) { $skip[] = [(string)$rel, 'بیش از ' . fsize(MAX_FILE)]; return false; }
    $full = SITES . '/' . $me . '/' . $proj . '/' . $path;
    $old = is_file($full) ? (int)filesize($full) : 0;
    if ($used - $old + $n > QUOTA) { $skip[] = [(string)$rel, 'فضای حساب پر است']; return false; }
    if (!mk(dirname($full)) || !put($full, $raw)) { $skip[] = [(string)$rel, 'نوشتن روی هاست ناموفق بود']; return false; }
    $used += $n - $old;
    return true;
}
/** استخراج امن ZIP روی سرور → [فایل، پوشه، ردشده‌ها، خطای کلی] */
function unzip($me, $proj, $zipPath, $prefix, $strip, &$used) {
    $skip = [];
    if (!class_exists('ZipArchive')) return [0, 0, $skip, 'روی این هاست ZIP فعال نیست؛ آرشیو را روی کامپیوتر باز کنید و پوشه را آپلود کنید.'];
    $z = new ZipArchive();
    $r = $z->open($zipPath);
    if ($r !== true) return [0, 0, $skip, 'فایل ZIP معتبر نیست یا خراب است.'];
    if ($z->numFiles > MAX_ZIP_ITEMS) { $z->close(); return [0, 0, $skip, 'تعداد آیتم‌های ZIP بیشتر از ' . MAX_ZIP_ITEMS . ' است.']; }
    $files = []; $dirs = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $nm = $z->getNameIndex($i);
        if ($nm === false) continue;
        $nm = ltrim(str_replace('\\', '/', $nm), '/');
        if (preg_match('~(^|/)(__MACOSX|\.DS_Store|Thumbs\.db)(/|$)~', $nm)) continue;
        if (substr($nm, -1) === '/') $dirs[] = rtrim($nm, '/'); else $files[$i] = $nm;
    }
    $root = '';
    if ($strip && $files) {
        $first = null; $same = true;
        foreach ($files as $nm) {
            if (strpos($nm, '/') === false) { $same = false; break; }
            $f = explode('/', $nm)[0];
            if ($first === null) $first = $f; elseif ($first !== $f) { $same = false; break; }
        }
        if ($same) $root = $first . '/';
    }
    $cut = function ($nm) use ($root) { return $root !== '' && strpos($nm . '/', $root) === 0 ? substr($nm . '/', strlen($root)) : $nm; };
    $join = function ($nm) use ($prefix) { return $prefix !== '' ? $prefix . '/' . $nm : $nm; };
    $saved = 0; $folders = 0;
    foreach ($dirs as $dn) {
        $dn = rtrim($cut($dn), '/');
        if ($dn === '') continue;
        $why = '';
        $d = norm_dir($join($dn), $why);
        if ($d !== '' && mk(SITES . '/' . $me . '/' . $proj . '/' . $d)) $folders++;
    }
    foreach ($files as $i => $nm) {
        $rel = $join($cut($nm));
        $st = $z->statIndex($i);
        if ($st && $st['size'] > MAX_FILE) { $skip[] = [$nm, 'بیش از ' . fsize(MAX_FILE)]; continue; }
        $why = '';
        if (norm_path($rel, $why) === '') { $skip[] = [$nm, $why]; continue; }
        $c = $z->getFromIndex($i);
        if ($c === false) { $skip[] = [$nm, 'خواندن فایل ممکن نشد']; continue; }
        if (store($me, $proj, $rel, $c, $used, $skip)) $saved++;
    }
    $z->close();
    return [$saved, $folders, $skip, ''];
}

/* =====================================================================
   PWA (فقط manifest و آیکون؛ Service Worker قدیمی خودش را حذف می‌کند)
   ===================================================================== */
if (isset($_GET['err'])) {
    $codes = [400 => ['درخواست نامعتبر', 'درخواست شما قابل پردازش نیست.'], 401 => ['نیاز به ورود', 'برای دیدن این صفحه باید وارد حساب خود شوید.'], 403 => ['دسترسی ممنوع', 'اجازهٔ دیدن این صفحه را ندارید.'], 404 => ['صفحه پیدا نشد', 'آدرس واردشده وجود ندارد یا جابه‌جا شده است.'], 500 => ['خطای سرور', 'مشکلی در سرور پیش آمد؛ کمی بعد دوباره تلاش کنید.'], 503 => ['سرویس در دسترس نیست', 'سرور موقتاً پاسخ نمی‌دهد؛ کمی بعد برگردید.']];
    $c = (int)$_GET['err']; if (!isset($codes[$c])) $c = 404;
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($c);
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    $home = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $c . ' — ' . $codes[$c][0] . '</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#0b1020;color:#e8ebf6;font-family:Tahoma,"Segoe UI",sans-serif;text-align:center}@media(prefers-color-scheme:light){body{background:#f3f5fa;color:#111827}}.n{font-size:clamp(80px,22vw,150px);font-weight:900;line-height:1;background:linear-gradient(180deg,#818cf8,#4338ca);-webkit-background-clip:text;background-clip:text;color:transparent}h1{margin:8px 0}p{color:#8f98bb;margin:0 0 24px}a{display:inline-block;padding:11px 26px;border-radius:10px;background:#4f46e5;color:#fff;text-decoration:none;font-weight:700}small{display:block;margin-top:28px;color:#8f98bb}</style></head><body><main><div class="n">' . $c . '</div><h1>' . $codes[$c][0] . '</h1><p>' . $codes[$c][1] . '</p><a href="' . h($home) . '">بازگشت به نت سایت</a><small>نت سایت</small></main></body></html>';
    exit;
}
if (isset($_GET['pwa'])) {
    while (ob_get_level()) { @ob_end_clean(); }
    $k = $_GET['pwa'];
    if ($k === 'manifest') {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode(['name' => 'نت سایت', 'short_name' => 'نت سایت', 'start_url' => './', 'scope' => './',
            'display' => 'standalone', 'dir' => 'rtl', 'lang' => 'fa', 'theme_color' => '#4f46e5', 'background_color' => '#0b1020',
            'icons' => [['src' => '?pwa=png&s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'], ['src' => '?pwa=png&s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'], ['src' => '?pwa=icon', 'sizes' => 'any', 'type' => 'image/svg+xml']]], JSON_UNESCAPED_UNICODE);
    } elseif ($k === 'icon') {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4f46e5"/><stop offset=".55" stop-color="#9333ea"/><stop offset="1" stop-color="#db2777"/></linearGradient></defs><rect width="512" height="512" fill="url(#g)"/><g fill="none" stroke="#fff" stroke-width="26" stroke-linecap="round"><circle cx="256" cy="256" r="130"/><ellipse cx="256" cy="256" rx="58" ry="130"/><path d="M126 256h260"/></g></svg>';
    } elseif ($k === 'sw') {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store');
        echo "self.addEventListener('install',function(){self.skipWaiting()});self.addEventListener('activate',function(e){e.waitUntil(caches.keys().then(function(k){return Promise.all(k.map(function(n){return caches.delete(n)}))}).then(function(){return self.registration.unregister()}))});";
    } elseif ($k === 'png' && function_exists('imagepng')) {
        $z = (int)($_GET['s'] ?? 192); $z = in_array($z, [180, 192, 512], true) ? $z : 192;
        $im = imagecreatetruecolor($z, $z);
        for ($y = 0; $y < $z; $y++) imagefilledrectangle($im, 0, $y, $z, $y, imagecolorallocate($im, 79 - (int)(30 * $y / $z), 70 - (int)(20 * $y / $z), 229 - (int)(60 * $y / $z)));
        $w = imagecolorallocate($im, 255, 255, 255); $c = (int)($z / 2);
        imagefilledellipse($im, $c, $c, (int)($z * .56), (int)($z * .56), $w);
        imagefilledellipse($im, $c, $c, (int)($z * .42), (int)($z * .42), imagecolorallocate($im, 62, 56, 200));
        imagefilledrectangle($im, (int)($c - $z * .02), (int)($z * .14), (int)($c + $z * .02), (int)($z * .86), $w);
        header('Content-Type: image/png'); header('Cache-Control: public, max-age=86400'); imagepng($im);
    } else http_response_code(404);
    exit;
}

/* =====================================================================
   نشست
   ===================================================================== */
session_set_cookie_params(['lifetime' => 1209600, 'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
@session_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: text/html; charset=utf-8');
    exit('<h2 dir="rtl" style="font-family:Tahoma"> نشست (session) روی این هاست کار نمی‌کند. با پشتیبانی هاست تماس بگیرید.</h2>');
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

$me = $_SESSION['user'] ?? null;
if ($me && !load_user($me)) { unset($_SESSION['user']); $me = null; }

$upMax = ini_bytes('upload_max_filesize') ?: 2097152;
$postMax = ini_bytes('post_max_size') ?: 8388608;
$BATCH = (int)max(150000, min(4000000, $postMax * 0.6, $upMax * 0.9));
$HAS_ZIP = class_exists('ZipArchive');

/* =====================================================================
   ۲) عملیات
   ===================================================================== */
if ($me && isset($_GET['dl'])) {                       // دانلود ZIP پروژه
    $proj = clean_project($_GET['dl']);
    $base = SITES . '/' . $me . '/' . $proj;
    if ($proj === '' || !is_dir($base)) { http_response_code(404); exit('not found'); }
    if (!$HAS_ZIP) { http_response_code(501); exit('ZipArchive is not available'); }
    mk(USERS . '/' . $me . '/tmp');
    $tmp = USERS . '/' . $me . '/tmp/dl-' . bin2hex(random_bytes(6)) . '.zip';
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('zip failed'); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $x) {
        if ($x->isLink()) continue;
        $rel = str_replace('\\', '/', substr($x->getPathname(), strlen($base) + 1));
        if ($x->isDir()) $z->addEmptyDir($rel); else $z->addFile($x->getPathname(), $rel);
    }
    $z->close();
    if (!is_file($tmp)) { flash('پروژه خالی است و چیزی برای دانلود ندارد.', 'bad'); back($proj); }
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $proj . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

if ($me && isset($_GET['backup']) && $HAS_ZIP) {
    $root = SITES . '/' . $me;
    mk(USERS . '/' . $me . '/tmp');
    $tmp = USERS . '/' . $me . '/tmp/bk-' . bin2hex(random_bytes(6)) . '.zip';
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('zip failed'); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $x) {
        if ($x->isLink()) continue;
        $rel = str_replace('\\', '/', substr($x->getPathname(), strlen($root) + 1));
        if ($x->isDir()) $z->addEmptyDir($rel); else $z->addFile($x->getPathname(), $rel);
    }
    $z->close();
    if (!is_file($tmp)) { flash('پروژه‌ای برای پشتیبان‌گیری وجود ندارد.', 'bad'); go(); }
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="backup-' . $me . '-' . date('Ymd') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do = (string)($_POST['do'] ?? '');

    // حجم بیش از post_max_size باعث خالی شدن $_POST می‌شود
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $m = 'حجم درخواست از محدودیت هاست (' . fsize($postMax) . ') بیشتر است.';
        if (AJAX) jout(['ok' => false, 'msg' => $m]);
        flash($m, 'bad'); go();
    }

    /* --- ورود و ثبت‌نام (بدون CSRF تا هرگز قفل نشود) --- */
    if ($do === 'register') {
        $u = clean_user($_POST['username'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        if ($u === '') flash('نام کاربری باید ۳ تا ۳۰ حرف انگلیسی/عدد/_/- باشد.', 'bad');
        elseif (strlen($p) < 8) flash('رمز عبور باید حداقل ۸ کاراکتر باشد.', 'bad');
        elseif (is_dir(USERS . '/' . $u) || is_dir(SITES . '/' . $u)) flash('این نام کاربری قبلاً ثبت شده است.', 'bad');
        elseif (!rl('reg' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 3600)) flash('تعداد ثبت‌نام از این شبکه زیاد است؛ بعداً تلاش کنید.', 'bad');
        elseif (!mk(USERS . '/' . $u) || !mk(SITES . '/' . $u)
            || !save_user($u, ['username' => $u, 'password' => password_hash($p, PASSWORD_DEFAULT), 'created' => time(), 'fails' => 0, 'locked_until' => 0])) {
            rmtree(USERS . '/' . $u); rmtree(SITES . '/' . $u);
            flash('ساخت حساب روی هاست ناموفق بود (دسترسی نوشتن را بررسی کنید).', 'bad');
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = $u;
            flash('حساب شما ساخته شد. خوش آمدید ');
        }
        go();
    }
    if ($do === 'login') {
        $u = clean_user($_POST['username'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        $d = load_user($u);
        if ($d && (int)($d['locked_until'] ?? 0) > time()) {
            flash('تلاش‌های ناموفق زیاد بود؛ ' . max(1, (int)ceil(($d['locked_until'] - time()) / 60)) . ' دقیقهٔ دیگر امتحان کنید.', 'bad');
        } elseif ($d && !empty($d['password']) && password_verify($p, $d['password'])) {
            $d['fails'] = 0; $d['locked_until'] = 0;
            save_user($u, $d);
            act_log($u, 'ورود به حساب');
            session_regenerate_id(true);
            $_SESSION['user'] = $u;
            flash('خوش آمدید ');
        } else {
            if ($d) {
                $d['fails'] = (int)($d['fails'] ?? 0) + 1;
                if ($d['fails'] >= 8) { $d['locked_until'] = time() + 300; $d['fails'] = 0; }
                save_user($u, $d);
            }
            flash('نام کاربری یا رمز عبور اشتباه است.', 'bad');
        }
        go();
    }
    if ($do === 'logout') {
        $_SESSION = [];
        @session_destroy();
        @session_start();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        go();
    }

    /* --- از اینجا به بعد: ورود + توکن لازم است --- */
    if (!$me) { if (AJAX) jout(['ok' => false, 'msg' => 'نشست پایان یافته؛ صفحه را رفرش کنید و دوباره وارد شوید.']); go(); }
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        if (AJAX) jout(['ok' => false, 'msg' => 'نشست منقضی شده؛ صفحه را رفرش کنید (F5).']);
        flash('نشست منقضی شده بود؛ دوباره تلاش کنید.', 'bad'); go();
    }

    $proj = clean_project($_POST['project'] ?? '');
    $base = SITES . '/' . $me . '/' . $proj;
    $cur = norm_dir($_POST['dir'] ?? '');

    if ($do === 'new_project') {
        $g = gate($me, $proj);
        if ($g !== '') flash($g, 'bad');
        elseif (is_dir($base)) flash('پروژه‌ای با این نام وجود دارد.', 'bad');
        elseif (!mk($base)) flash('ساخت پروژه ناموفق بود.', 'bad');
        else {
            put($base . '/index.html', welcome($proj));
            flash('پروژهٔ «' . $proj . '» ساخته شد ');
            act_log($me, 'ساخت پروژه ' . $proj);
            back($proj);
        }
        go();
    }
    if ($do === 'del_project') {
        if ($proj !== '' && is_dir($base)) { rmtree($base); rmtree(USERS . '/' . $me . '/hist/' . $proj); act_log($me, 'حذف پروژه ' . $proj); flash('پروژه حذف شد.'); }
        go();
    }
    if ($do === 'dup_project') {
        if ($proj === '' || !is_dir($base)) { flash('پروژه پیدا نشد.', 'bad'); go(); }
        if (count(projects($me)) >= MAX_PROJECTS) { flash('به سقف تعداد پروژه‌ها رسیده‌اید.', 'bad'); go(); }
        if (usage($me) + stats($base)[2] > QUOTA) { flash('فضای حساب برای کپی کافی نیست.', 'bad'); go(); }
        $new = substr($proj, 0, 44) . '-copy'; $n = 2;
        while (is_dir(SITES . '/' . $me . '/' . $new)) { $new = substr($proj, 0, 42) . '-copy' . $n++; }
        copytree($base, SITES . '/' . $me . '/' . $new);
        flash('کپی ساخته شد: ' . $new);
        go();
    }
    if ($do === 'rename_project') {
        $new = clean_project($_POST['new_name'] ?? '');
        $nb = SITES . '/' . $me . '/' . $new;
        if ($proj === '' || !is_dir($base)) flash('پروژه پیدا نشد.', 'bad');
        elseif ($new === '') flash('نام جدید نامعتبر است.', 'bad');
        elseif (is_dir($nb)) flash('پروژه‌ای با این نام وجود دارد.', 'bad');
        elseif (!@rename($base, $nb)) flash('تغییر نام ناموفق بود.', 'bad');
        else {
            $hs = USERS . '/' . $me . '/hist/' . $proj;
            if (is_dir($hs) && !is_dir(USERS . '/' . $me . '/hist/' . $new)) { mk(USERS . '/' . $me . '/hist'); @rename($hs, USERS . '/' . $me . '/hist/' . $new); }
            flash('نام پروژه تغییر کرد.');
        }
        go();
    }

    // بقیهٔ عملیات روی یک پروژهٔ موجود هستند
    if ($proj === '' || !is_dir($base)) {
        if (AJAX) jout(['ok' => false, 'msg' => 'پروژه پیدا نشد.']);
        flash('پروژه پیدا نشد.', 'bad'); go();
    }

    if ($do === 'mkdir') {
        $why = '';
        $d = norm_dir($_POST['path'] ?? '', $why, false);
        if ($d === '') flash($why ?: 'نام پوشه نامعتبر است.', 'bad');
        elseif (is_dir($base . '/' . $d) || is_file($base . '/' . $d)) flash('این مسیر از قبل وجود دارد.', 'bad');
        elseif (!mk($base . '/' . $d)) flash('ساخت پوشه ناموفق بود.', 'bad');
        else flash('پوشه ساخته شد: ' . $d);
        back($proj, $cur);
    }
    if ($do === 'del') {
        $isDir = ($_POST['type'] ?? '') === 'dir';
        $why = '';
        $p = $isDir ? norm_dir($_POST['path'] ?? '', $why, false) : norm_path($_POST['path'] ?? '', $why);
        if ($p !== '' && file_exists($base . '/' . $p)) { rmtree($base . '/' . $p); flash('حذف شد.'); }
        else flash('مورد پیدا نشد.', 'bad');
        back($proj, $cur);
    }
    if ($do === 'rename') {
        $isDir = ($_POST['type'] ?? '') === 'dir';
        $w1 = ''; $w2 = '';
        if ($isDir) { $from = norm_dir($_POST['from'] ?? '', $w1, false); $to = norm_dir($_POST['to'] ?? '', $w2, false); }
        else { $from = norm_path($_POST['from'] ?? '', $w1); $to = norm_path($_POST['to'] ?? '', $w2); }
        if ($from === '' || $to === '') flash($w1 ?: ($w2 ?: 'مسیر نامعتبر است.'), 'bad');
        elseif (!file_exists($base . '/' . $from)) flash('مورد پیدا نشد.', 'bad');
        elseif ($from === $to) flash('نامی تغییر نکرد.', 'bad');
        elseif (file_exists($base . '/' . $to)) flash('مقصد از قبل وجود دارد.', 'bad');
        elseif ($isDir && strpos($to . '/', $from . '/') === 0) flash('نمی‌توان پوشه را داخل خودش برد.', 'bad');
        elseif (!mk(dirname($base . '/' . $to)) || !@rename($base . '/' . $from, $base . '/' . $to)) flash('انتقال ناموفق بود.', 'bad');
        else flash('انجام شد ');
        back($proj, $cur);
    }

    /* --- آپلود چندفایل / پوشه / ZIP (multipart استاندارد) --- */
    if ($do === 'upload') {
        $fl = $_FILES['files'] ?? null;
        if (!$fl || !is_array($fl['name'])) jout(['ok' => false, 'msg' => 'فایلی دریافت نشد.']);
        $paths = (array)($_POST['paths'] ?? []);
        $used = usage($me); $saved = 0; $folders = 0; $skip = [];
        foreach ($fl['name'] as $i => $name) {
            $rel = (string)($paths[$i] ?? $name);
            $err = (int)$fl['error'][$i];
            if ($err !== UPLOAD_ERR_OK) {
                $skip[] = [$rel, ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) ? 'بزرگ‌تر از محدودیت هاست (' . fsize($upMax) . ')' : 'آپلود ناقص بود'];
                continue;
            }
            $tmp = $fl['tmp_name'][$i];
            if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'zip') {
                if ($fl['size'][$i] > MAX_ZIP) { $skip[] = [$rel, 'آرشیو بیش از ' . fsize(MAX_ZIP)]; continue; }
                list($s, $f, $sk, $fatal) = unzip($me, $proj, $tmp, $cur, !empty($_POST['strip']), $used);
                if ($fatal !== '') $skip[] = [$rel, $fatal];
                $saved += $s; $folders += $f; $skip = array_merge($skip, array_slice($sk, 0, 50));
                continue;
            }
            if ($fl['size'][$i] > MAX_FILE) { $skip[] = [$rel, 'بیش از ' . fsize(MAX_FILE)]; continue; }
            $dest = $cur !== '' ? $cur . '/' . $rel : $rel;
            if (store($me, $proj, $dest, (string)file_get_contents($tmp), $used, $skip)) $saved++;
        }
        if ($saved + $folders > 0) act_log($me, 'آپلود ' . $saved . ' فایل در ' . $proj);
        jout(['ok' => ($saved + $folders) > 0, 'saved' => $saved, 'folders' => $folders, 'skipped' => array_slice($skip, 0, 60)]);
    }

    /* --- ذخیرهٔ فایل از ویرایشگر --- */
    if ($do === 'save') {
        $why = '';
        $path = norm_path($_POST['path'] ?? '', $why);
        if ($path === '') jout(['ok' => false, 'msg' => $why ?: 'نام فایل نامعتبر است.']);
        if (!is_text($path)) jout(['ok' => false, 'msg' => 'این نوع فایل قابل ویرایش نیست.']);
        $raw = base64_decode((string)($_POST['code_b64'] ?? ''), true);
        if ($raw === false) jout(['ok' => false, 'msg' => 'محتوای ارسالی خراب است.']);
        $used = usage($me); $skip = [];
        snap($me, $proj, $path, SITES . '/' . $me . '/' . $proj . '/' . $path, $raw);
        $ok = store($me, $proj, $path, $raw, $used, $skip);
        jout($ok ? ['ok' => true, 'msg' => 'ذخیره شد '] : ['ok' => false, 'msg' => $skip[0][1] ?? 'ذخیره ناموفق بود']);
    }

    // تغییر مشخصات و حذف حساب
    if ($do === 'hist') {
        $why = '';
        $path = norm_path($_POST['path'] ?? '', $why);
        if ($path === '') jout(['ok' => false, 'msg' => $why ?: 'مسیر نامعتبر']);
        $d = USERS . '/' . $me . '/hist/' . $proj . '/' . md5($path);
        $v = basename((string)($_POST['v'] ?? ''));
        if ($v !== '') jout(is_file($d . '/' . $v) ? ['ok' => true, 'text' => (string)file_get_contents($d . '/' . $v)] : ['ok' => false, 'msg' => 'نسخه پیدا نشد']);
        $l = glob($d . '/*.bak') ?: [];
        rsort($l);
        jout(['ok' => true, 'items' => array_map(function ($f) { return [basename($f), (int)basename($f, '.bak')]; }, array_slice($l, 0, 10))]);
    }
    if ($do === 'search') {
        $q = trim((string)($_POST['q'] ?? '')); $out = [];
        if (strlen($q) < 2) jout(['ok' => false, 'msg' => 'حداقل ۲ نویسه وارد کنید']);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $x) {
            if ($x->isLink() || !$x->isFile() || !is_text($x->getFilename()) || $x->getSize() > MAX_FILE) continue;
            $rel = str_replace('\\', '/', substr($x->getPathname(), strlen($base) + 1));
            $hit = stripos($rel, $q) !== false; $ln = 0;
            if (!$hit) foreach ((array)@file($x->getPathname()) as $i => $line) { if (stripos($line, $q) !== false) { $hit = true; $ln = $i + 1; break; } }
            if ($hit) $out[] = [$rel, $ln];
            if (count($out) >= 50) break;
        }
        jout(['ok' => true, 'items' => $out]);
    }
    if ($do === 'profile') {
        $d = load_user($me);
        $nu = clean_user($_POST['new_username'] ?? '');
        $np = (string)($_POST['new_password'] ?? '');
        if (!$d || !password_verify((string)($_POST['current_password'] ?? ''), $d['password'])) flash('رمز فعلی اشتباه است.', 'bad');
        elseif ($nu === '' && trim((string)($_POST['new_username'] ?? '')) !== '') flash('نام کاربری جدید نامعتبر است (۳ تا ۳۰ حرف انگلیسی/عدد/_/-).', 'bad');
        elseif ($np !== '' && strlen($np) < 8) flash('رمز جدید باید حداقل ۸ کاراکتر باشد.', 'bad');
        else {
            if ($np !== '') $d['password'] = password_hash($np, PASSWORD_DEFAULT);
            $target = $nu !== '' ? $nu : $me;
            if ($target !== $me) {
                if (is_dir(USERS . '/' . $target) || is_dir(SITES . '/' . $target)) { flash('این نام کاربری رزرو است.', 'bad'); go(); }
                if (!@rename(USERS . '/' . $me, USERS . '/' . $target)) { flash('تغییر نام کاربری ناموفق بود.', 'bad'); go(); }
                if (is_dir(SITES . '/' . $me) && !@rename(SITES . '/' . $me, SITES . '/' . $target)) {
                    @rename(USERS . '/' . $target, USERS . '/' . $me);
                    flash('انتقال پوشه سایت‌ها ناموفق بود؛ تغییری اعمال نشد.', 'bad'); go();
                }
            }
            $d['username'] = $target;
            save_user($target, $d);
            session_regenerate_id(true);
            $_SESSION['user'] = $target;
            flash('مشخصات ذخیره شد ');
        }
        go();
    }
    if ($do === 'delete_account') {
        $d = load_user($me);
        if (!$d || !password_verify((string)($_POST['current_password'] ?? ''), $d['password'])) { flash('رمز اشتباه است؛ حساب حذف نشد.', 'bad'); go(); }
        rmtree(USERS . '/' . $me); rmtree(SITES . '/' . $me);
        $_SESSION = []; @session_destroy(); @session_start();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        flash('حساب شما حذف شد.');
        go();
    }
    if (AJAX) jout(['ok' => false, 'msg' => 'درخواست ناشناخته.']);
    go();
}

/* =====================================================================
   ۳) نمایش
   ===================================================================== */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = $_SESSION['csrf'];
$self = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: './';

$view = 'auth'; $P = ''; $D = ''; $E = null; $myProjects = [];
if ($me) {
    $view = 'dash';
    $myProjects = projects($me);
    $P = clean_project($_GET['p'] ?? '');
    if ($P !== '' && in_array($P, $myProjects, true)) {
        $view = 'browse';
        $D = norm_dir($_GET['d'] ?? '');
        if (isset($_GET['edit'])) {
            $why = '';
            $ep = norm_path($_GET['edit'], $why);
            $full = SITES . '/' . $me . '/' . $P . '/' . $ep;
            if ($ep === '' || !is_text($ep)) { $flash = [$why ?: 'این فایل قابل ویرایش نیست.', 'bad']; }
            elseif (is_file($full) && filesize($full) <= MAX_FILE) { $view = 'edit'; $E = ['path' => $ep, 'text' => (string)file_get_contents($full), 'new' => false]; }
            elseif (!is_file($full) && !empty($_GET['new'])) { $view = 'edit'; $E = ['path' => $ep, 'text' => '', 'new' => true]; }
            else $flash = ['فایل پیدا نشد.', 'bad'];
        }
        if ($view === 'browse' && !is_dir(SITES . '/' . $me . '/' . $P . ($D !== '' ? '/' . $D : ''))) $D = '';
    } elseif ($P !== '') { $flash = ['پروژه پیدا نشد.', 'bad']; }
}
function surl($u, $p, $path) { return 'sites/' . implode('/', array_map('rawurlencode', array_merge([$u, $p], $path === '' ? [] : explode('/', $path)))); }
$totalFiles = 0; $totalDirs = 0; $totalBytes = 0; $cards = [];
if ($view === 'dash') {
    foreach ($myProjects as $pn) {
        list($f, $d, $b) = stats(SITES . '/' . $me . '/' . $pn);
        $cards[] = ['n' => $pn, 'f' => $f, 'd' => $d, 'b' => $b, 'idx' => is_file(SITES . '/' . $me . '/' . $pn . '/index.html'), 'm' => (int)@filemtime(SITES . '/' . $me . '/' . $pn)];
        $totalFiles += $f; $totalDirs += $d; $totalBytes += $b;
    }
}
$pal = [];
foreach ($myProjects as $pn) $pal[] = ['t' => ' ' . $pn, 'u' => '?p=' . urlencode($pn)];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>نت سایت — ساخت و انتشار وب‌سایت</title>
<meta name="theme-color" content="#4f46e5">
<link rel="manifest" href="?pwa=manifest" crossorigin="use-credentials">
<link rel="icon" type="image/svg+xml" href="?pwa=icon">
<link rel="apple-touch-icon" href="?pwa=png&amp;s=180">
<script>document.documentElement.className='js';(function(){try{if(location.search.indexOf('m=1')>-1)sessionStorage.m=1;if(!sessionStorage.m&&/Android.+Mobile|iPhone|iPod|Windows Phone/i.test(navigator.userAgent))document.documentElement.classList.add('mob');if(location.search.indexOf('debug')>-1)addEventListener('error',function(e){var d=document.getElementById('jerr')||document.body.appendChild(Object.assign(document.createElement('div'),{id:'jerr'}));d.textContent='JS error: '+e.message+' @'+(e.lineno||'')})}catch(e){}})();try{document.documentElement.dataset.theme=localStorage.getItem('th')||'dark'}catch(e){};window.tt=function(){var d=document.documentElement,t=d.dataset.theme==='light'?'dark':'light';d.dataset.theme=t;try{localStorage.setItem('th',t)}catch(e){}}</script>
<style>
:root{--bg:#05060f;--glass:rgba(20,22,45,.55);--line:rgba(255,255,255,.1);--text:#eef0ff;--mut:#9aa0c3;--p:#7c3aed;--c:#22d3ee;--pk:#f472b6;--ok:#34d399;--bad:#fb7185;--g:linear-gradient(135deg,#6366f1,#a855f7 50%,#ec4899)}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
[hidden]{display:none!important}
html{scroll-behavior:smooth}
body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font-family:Vazirmatn,Tahoma,"Segoe UI",sans-serif;line-height:1.8;overflow-x:hidden}
a{color:inherit;text-decoration:none}
#bg{position:fixed;inset:0;z-index:-2;pointer-events:none}
.aurora{position:fixed;inset:0;z-index:-3;overflow:hidden;pointer-events:none}
.aurora i{position:absolute;width:55vmax;height:55vmax;border-radius:50%;filter:blur(90px);opacity:.34;animation:float 22s ease-in-out infinite}
.aurora i:nth-child(1){background:#4f46e5;top:-20vmax;right:-15vmax}
.aurora i:nth-child(2){background:#db2777;bottom:-25vmax;left:-15vmax;animation-delay:-8s;opacity:.26}
.aurora i:nth-child(3){background:#0891b2;top:30%;left:35%;width:35vmax;height:35vmax;animation-delay:-15s;opacity:.18}
@keyframes float{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(6vmax,-4vmax) scale(1.12)}66%{transform:translate(-5vmax,5vmax) scale(.94)}}
#fx{position:fixed;inset:0;z-index:90;pointer-events:none}

.wrap{max-width:1180px;margin:0 auto;padding:18px 18px 0}
.nav{display:flex;align-items:center;gap:12px;padding:12px 16px;border:1px solid var(--line);background:var(--glass);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border-radius:20px;position:sticky;top:12px;z-index:20}
.logo{display:flex;align-items:center;gap:10px;font-weight:900;font-size:19px}
.logo i{width:38px;height:38px;border-radius:12px;background:var(--g);display:grid;place-items:center;font-style:normal;box-shadow:0 0 28px rgba(168,85,247,.6)}
.sp{flex:1}
.btn{border:0;cursor:pointer;color:#fff;background:var(--g);padding:10px 18px;border-radius:14px;font-weight:700;font-size:14px;font-family:inherit;display:inline-flex;align-items:center;gap:7px;justify-content:center;position:relative;transition:transform .15s,box-shadow .25s;box-shadow:0 8px 26px rgba(124,58,237,.35)}
.btn:hover{transform:translateY(-2px);box-shadow:0 12px 34px rgba(168,85,247,.55)}
.btn:active{transform:scale(.97)}
.btn:disabled{opacity:.5;cursor:wait}
.btn.gh{background:rgba(255,255,255,.05);border:1px solid var(--line);box-shadow:none}
.btn.gh:hover{background:rgba(255,255,255,.1);box-shadow:none}
.btn.bad{background:linear-gradient(135deg,#e11d48,#fb7185);box-shadow:0 8px 22px rgba(225,29,72,.3)}
.btn.ok{background:linear-gradient(135deg,#059669,#34d399);box-shadow:0 8px 22px rgba(5,150,105,.3)}
.btn.sm{padding:5px 11px;font-size:12px;border-radius:10px;box-shadow:none}
.kbd{font-size:11px;border:1px solid var(--line);padding:0 6px;border-radius:6px;color:var(--mut)}
input,textarea{width:100%;background:rgba(0,0,0,.35);border:1.5px solid var(--line);color:var(--text);border-radius:14px;padding:12px 15px;font-size:14px;font-family:inherit;outline:none;margin:0 0 12px;transition:.2s}
input:focus,textarea:focus{border-color:var(--p);box-shadow:0 0 0 4px rgba(124,58,237,.25)}
label{display:block;font-size:12.5px;color:var(--mut);margin:0 0 4px;font-weight:700}
.panel{border:1px solid var(--line);background:var(--glass);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border-radius:24px;padding:24px}
.mut{color:var(--mut);font-size:13px}
.mono{font-family:Consolas,monospace;direction:ltr;unicode-bidi:embed}
.flash{margin:16px 0 0;padding:13px 18px;border-radius:16px;font-weight:700;animation:pop .5s cubic-bezier(.2,1.4,.4,1)}
.flash.ok{background:rgba(52,211,153,.14);border:1px solid rgba(52,211,153,.4);color:var(--ok)}
.flash.bad{background:rgba(251,113,133,.14);border:1px solid rgba(251,113,133,.4);color:var(--bad)}
@keyframes pop{from{opacity:0;transform:translateY(-12px) scale(.96)}to{opacity:1;transform:none}}

/* احراز هویت */
.auth{min-height:calc(100vh - 140px);display:grid;grid-template-columns:1.15fr 1fr;gap:34px;align-items:center;padding:30px 0}
.orb{position:relative;width:150px;height:150px;margin-bottom:22px}
.orb b{position:absolute;inset:0;border-radius:50%;background:var(--g);display:grid;place-items:center;font-size:62px;box-shadow:0 0 70px rgba(168,85,247,.7);animation:pulse 3.5s ease-in-out infinite}
.orb s{position:absolute;inset:-14px;border-radius:50%;border:2px dashed rgba(255,255,255,.25);animation:spin 14s linear infinite}
.orb s+s{inset:-30px;border-color:rgba(34,211,238,.35);animation-duration:22s;animation-direction:reverse}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{50%{transform:scale(1.06);box-shadow:0 0 100px rgba(236,72,153,.7)}}
.hero h1{font-size:clamp(32px,5.5vw,58px);line-height:1.25;margin:0 0 10px;font-weight:900;background:linear-gradient(90deg,#fff,#c4b5fd,#f9a8d4,#fff);background-size:250% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:flow 6s linear infinite}
@keyframes flow{to{background-position:250% 0}}
.type{min-height:34px;font-size:19px;color:var(--c);font-weight:700}
.type:after{content:"▍";animation:blink 1s steps(2) infinite;margin-right:2px}
@keyframes blink{50%{opacity:0}}
.tabs{display:flex;gap:6px;padding:5px;background:rgba(0,0,0,.35);border-radius:14px;margin-bottom:18px}
.tabs button{flex:1;border:0;background:transparent;color:var(--mut);padding:10px;border-radius:10px;cursor:pointer;font-weight:700;font-size:14px;font-family:inherit}
.tabs button.on{background:var(--g);color:#fff}
.js .hide{display:none}
.nojs-sep{margin:20px 0 6px;color:var(--mut);font-size:12px;text-align:center}
.js .nojs-sep{display:none}

/* داشبورد */
.hello{padding:34px 0 10px}
.hello h2{font-size:clamp(24px,4vw,38px);margin:0;font-weight:900}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}
.stat{padding:16px 18px;border-radius:20px;border:1px solid var(--line);background:var(--glass);backdrop-filter:blur(14px)}
.stat b{display:block;font-size:30px;font-weight:900;line-height:1.3;background:var(--g);-webkit-background-clip:text;background-clip:text;color:transparent}
.stat span{font-size:12.5px;color:var(--mut)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(285px,1fr));gap:16px;perspective:1000px}
.card{position:relative;padding:20px;border-radius:24px;background:var(--glass);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid var(--line);transform-style:preserve-3d;transition:transform .15s ease-out,border-color .3s;overflow:hidden}
.card:before{content:"";position:absolute;inset:0;border-radius:inherit;padding:1.5px;background:conic-gradient(from var(--a,0deg),transparent 0 70%,#a855f7,#22d3ee,#ec4899,transparent);-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;opacity:0;transition:opacity .4s;animation:rot 4s linear infinite;pointer-events:none}
@property --a{syntax:'<angle>';initial-value:0deg;inherits:false}
@keyframes rot{to{--a:360deg}}
.card:hover:before{opacity:1}
.glare{position:absolute;inset:0;pointer-events:none;background:radial-gradient(260px circle at var(--mx,50%) var(--my,50%),rgba(255,255,255,.14),transparent 60%);opacity:0;transition:opacity .3s}
.card:hover .glare{opacity:1}
.card h3{margin:0 0 4px;font-size:19px;direction:ltr;text-align:right;word-break:break-all}
.tags{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 14px}
.tag{font-size:11.5px;padding:2px 10px;border-radius:99px;background:rgba(124,58,237,.2);color:#c4b5fd;font-weight:700}
.row{display:flex;gap:7px;flex-wrap:wrap}
.add{display:grid;place-items:center;text-align:center;min-height:180px;cursor:pointer;border:2px dashed rgba(255,255,255,.2);background:transparent}
.add .plus{font-size:50px;line-height:1;background:var(--g);-webkit-background-clip:text;background-clip:text;color:transparent;font-weight:900}
.empty{text-align:center;padding:50px 10px;color:var(--mut)}

/* مرورگر فایل */
.crumbs{display:flex;flex-wrap:wrap;gap:6px;align-items:center;direction:ltr;justify-content:flex-end;font-weight:700;margin-bottom:6px}
.crumbs a{padding:3px 10px;border-radius:99px;background:rgba(255,255,255,.07)}
.crumbs a:hover{background:var(--p)}
.files{margin-top:14px;border:1px solid var(--line);border-radius:18px;overflow:hidden}
.fr{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:10px 16px;border-top:1px solid var(--line);transition:background .2s}
.fr:first-child{border-top:0}
.fr:hover{background:rgba(255,255,255,.04)}
.fr .nm{display:flex;align-items:center;gap:10px;min-width:0;direction:ltr;font-family:Consolas,monospace;font-size:13.5px;word-break:break-all}
.drop{margin-top:16px;border:2px dashed rgba(255,255,255,.22);border-radius:22px;padding:34px 16px;text-align:center;cursor:pointer;transition:.25s;background:rgba(0,0,0,.2)}
.drop.over{border-color:var(--c);background:rgba(34,211,238,.1);transform:scale(1.015)}
.drop .big{font-size:46px;display:inline-block;animation:bob 2.6s ease-in-out infinite}
@keyframes bob{50%{transform:translateY(-8px)}}
.bar{height:8px;border-radius:99px;background:rgba(0,0,0,.4);overflow:hidden;margin:12px 0}
.bar i{display:block;height:100%;width:0;background:var(--g);transition:width .25s}
.log{font-size:12.5px;color:#fbbf24;max-height:180px;overflow:auto;margin-top:8px}

/* ویرایشگر */
.ed{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.ed.solo{grid-template-columns:1fr}
.ed textarea{height:66vh;min-height:360px;margin:0;font-family:Consolas,monospace;direction:ltr;text-align:left;font-size:13.5px;line-height:1.65;tab-size:2;resize:vertical}
.pv{width:100%;height:66vh;min-height:360px;border:1.5px solid var(--line);border-radius:14px;background:#fff}

/* پالت فرمان */
dialog{border:1px solid var(--line);background:rgba(12,13,30,.92);backdrop-filter:blur(24px);color:var(--text);border-radius:22px;padding:0;width:min(560px,92vw);box-shadow:0 30px 90px rgba(0,0,0,.7)}
dialog::backdrop{background:rgba(0,0,0,.55);backdrop-filter:blur(5px)}
.pal-in{padding:20px 20px 4px}
.pal-in input{margin:0;font-size:16px}
.pal-list{max-height:320px;overflow:auto;padding:6px 10px 14px}
.pal-list a,.pal-list button{display:block;width:100%;text-align:right;background:transparent;border:0;color:var(--text);font-size:14px;font-family:inherit;padding:10px 14px;border-radius:12px;cursor:pointer}
.pal-list .on,.pal-list a:hover,.pal-list button:hover{background:var(--g)}
.mod{padding:26px}
.mod h3{margin:0 0 14px}

.foot{margin:46px 0 0;padding:34px 10px 26px;text-align:center;border-top:1px solid var(--line);position:relative}
.foot:before{content:"";position:absolute;top:-1px;right:25%;left:25%;height:2px;background:var(--g);filter:blur(2px)}
.foot .au{font-size:20px;font-weight:900;background:linear-gradient(90deg,#818cf8,#f472b6,#fbbf24,#22d3ee,#818cf8);background-size:300% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:flow 5s linear infinite}
.foot .hr{color:#fb7185;display:inline-block;animation:beat 1.3s infinite}
@keyframes beat{0%,100%{transform:scale(1)}20%{transform:scale(1.35)}40%{transform:scale(1)}}
.foot p{margin:6px 0}
.toasts{position:fixed;bottom:18px;left:18px;display:flex;flex-direction:column;gap:8px;z-index:100}
.toast{padding:12px 18px;border-radius:14px;font-weight:700;font-size:13.5px;background:#2563eb;box-shadow:0 12px 30px rgba(0,0,0,.5);animation:pop .4s}
.toast.ok{background:#059669}.toast.bad{background:#dc2626}
.btn.gh{background:rgba(127,127,160,.1)}button{font-family:inherit}
body{overflow-x:hidden}.wrap{min-width:0}.nav .mono{max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hello h2{overflow-wrap:anywhere}.hello .mono{display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom}
.seg{background:var(--bg)}.seg button:hover{color:var(--text)}.seg button.on{background:var(--g);color:#fff;box-shadow:0 2px 8px rgba(79,70,229,.4)}
.tag{background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.4);color:#c7d2fe}html[data-theme=light] .tag{color:#3730a3}.tag.w{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.5);color:#fbbf24}
.th{width:30px;height:30px;object-fit:cover;border-radius:6px;border:1px solid var(--line)}
.pw{height:5px;border-radius:9px;background:var(--bg);margin:-6px 0 8px;overflow:hidden}.pw i{display:block;height:100%;width:0;transition:.2s}
#mob{display:none}html.mob #mob{display:grid;position:fixed;inset:0;z-index:999;place-items:center;text-align:center;padding:28px;background:var(--bg)}html.mob .wrap,html.mob .toasts{display:none}
#jerr{position:fixed;bottom:0;right:0;left:0;z-index:1000;background:#dc2626;color:#fff;padding:8px 14px;font:12px Consolas,monospace;direction:ltr}

/* --- اصلاحات: تم روشن، دکمهٔ تم، و کلاس‌هایی که استایل نداشتند --- */
html[data-theme=light]{--bg:#f3f5fa;--glass:rgba(255,255,255,.78);--line:rgba(15,23,42,.14);--text:#111827;--mut:#5b6385;--ok:#059669;--bad:#e11d48}
html[data-theme=light] input,html[data-theme=light] textarea{background:rgba(255,255,255,.9)}
html[data-theme=light] .tabs{background:rgba(15,23,42,.06)}
html[data-theme=light] .fr:hover{background:rgba(15,23,42,.04)}
html[data-theme=light] dialog{background:rgba(255,255,255,.97)}
html[data-theme=light] .btn.gh{background:rgba(15,23,42,.06)}
html[data-theme=light] .aurora i{opacity:.14}
.td{display:inline}.tl{display:none}
html[data-theme=light] .td{display:none}html[data-theme=light] .tl{display:inline}
html:not(.js) .hide-nojs{display:none}
.tool{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:14px 0}
.tool input{flex:1;min-width:180px;margin:0}
.seg{display:inline-flex;gap:4px;padding:4px;border:1px solid var(--line);border-radius:12px}
.seg button{border:0;background:transparent;color:var(--mut);padding:6px 12px;border-radius:9px;cursor:pointer;font-weight:700;font-size:13px;font-family:inherit}
.ext{display:inline-block;min-width:34px;padding:2px 6px;border-radius:7px;background:rgba(99,102,241,.2);color:#c7d2fe;font-size:11px;text-align:center;font-weight:700;text-transform:uppercase}
html[data-theme=light] .ext{color:#3730a3}
.acts{margin-top:10px;display:grid;gap:6px}
.acts>div{display:flex;gap:12px;align-items:center;font-size:13.5px}
@media(max-width:860px){.auth{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr}.ed{grid-template-columns:1fr}.kbd{display:none}}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<div id="mob"><div><svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4" width="19" height="12.5" rx="2"/><path d="M8 20.5h8M12 16.5v4"/></svg><h2 style="margin:14px 0 8px">فقط با کامپیوتر یا لپ‌تاپ</h2><p class="mut" style="max-width:340px;margin:0 auto">نت سایت برای ساخت و ویرایش سایت به صفحهٔ بزرگ نیاز دارد. لطفاً با لپ‌تاپ یا کامپیوتر وارد شوید.</p></div></div>
<div class="toasts" id="toasts"></div>

<div class="wrap">
<nav class="nav">
  <a class="logo" href="<?= h($self) ?>"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAIAAABt+uBvAAAWfmNhQlgAABZ+anVtYgAAAB5qdW1kYzJwYQARABCAAACqADibcQNjMnBhAAAAFlhqdW1iAAAAR2p1bWRjMm1hABEAEIAAAKoAOJtxA3VybjpjMnBhOmRiNWFiZGE2LWU0OTgtNGZlNy1hYTM3LTAwYmJkNmU2N2E5MQAAAAOTanVtYgAAAClqdW1kYzJhcwARABCAAACqADibcQNjMnBhLmFzc2VydGlvbnMAAAAAuGp1bWIAAABEanVtZGNib3IAEQAQgAAAqgA4m3ETYzJwYS5pbmdyZWRpZW50LnYzAAAAABhjMnNo4D+oeDfTVtXFJluwWqRhgwAAAGxjYm9yo2lkYzpmb3JtYXRpaW1hZ2UvcG5namluc3RhbmNlSUR4LHhtcDppaWQ6YjVlMzg4NDktMDk4Yi00OTE1LTkyZjUtZTVhMGExOTI3OTMxbHJlbGF0aW9uc2hpcGhwYXJlbnRPZgAAAeJqdW1iAAAAQWp1bWRjYm9yABEAEIAAAKoAOJtxE2MycGEuYWN0aW9ucy52MgAAAAAYYzJzaNwgUMRFctpQjEAmGZgAyG4AAAGZY2JvcqJnYWN0aW9uc4KiZmFjdGlvbmtjMnBhLm9wZW5lZGpwYXJhbWV0ZXJzoWtpbmdyZWRpZW50c4GiY3VybHgtc2VsZiNqdW1iZj1jMnBhLmFzc2VydGlvbnMvYzJwYS5pbmdyZWRpZW50LnYzZGhhc2hYIEe0FJf4TOWI0tCZ1Fa+aQ8j7DQeXMmk33VSme0IFVtxpGZhY3Rpb254HWNvbS5hbnRocm9waWMuY2xhdWRlLnByb3ZpZGVkanBhcmFtZXRlcnOheB9jb20uYW50aHJvcGljLm9yaWdpbi1jb25maWRlbmNlZ3Vua25vd25rZGVzY3JpcHRpb254ZkNsYXVkZSBwcm92aWRlZCB0aGlzIGZpbGUgYXQgdGhlIHJlcXVlc3Qgb2YgYSB1c2VyIGFuZCBtYXkgaGF2ZSBjcmVhdGVkIG9yIG1vZGlmaWVkIHRoZSBmaWxlIGNvbnRlbnRzLm1zb2Z0d2FyZUFnZW50oWRuYW1lZkNsYXVkZXJhbGxBY3Rpb25zSW5jbHVkZWT1AAAAyGp1bWIAAABAanVtZGNib3IAEQAQgAAAqgA4m3ETYzJwYS5oYXNoLmRhdGEAAAAAGGMyc2jZ7TUyJLWIt1iNV4d3ZrH6AAAAgGNib3KlY2FsZ2ZzaGEyNTZjcGFkTQAAAAAAAAAAAAAAAABkaGFzaFggDJq/no5BNCr3s1qoNKY/TkYlk6u5gnciih0w8v8oVDVkbmFtZW5qdW1iZiBtYW5pZmVzdGpleGNsdXNpb25zgaJlc3RhcnQYIWZsZW5ndGgZFooAAAI+anVtYgAAACdqdW1kYzJjbAARABCAAACqADibcQNjMnBhLmNsYWltLnYyAAAAAg9jYm9ypWNhbGdmc2hhMjU2aXNpZ25hdHVyZXhNc2VsZiNqdW1iZj0vYzJwYS91cm46YzJwYTpkYjVhYmRhNi1lNDk4LTRmZTctYWEzNy0wMGJiZDZlNjdhOTEvYzJwYS5zaWduYXR1cmVqaW5zdGFuY2VJRHgseG1wOmlpZDo4MjhmMGM2Yi01ODNiLTQ4MWEtYWU0ZS00MDNmYTI0MWI2NWVyY3JlYXRlZF9hc3NlcnRpb25zg6JjdXJseC1zZWxmI2p1bWJmPWMycGEuYXNzZXJ0aW9ucy9jMnBhLmluZ3JlZGllbnQudjNkaGFzaFggR7QUl/hM5YjS0JnUVr5pDyPsNB5cyaTfdVKZ7QgVW3GiY3VybHgqc2VsZiNqdW1iZj1jMnBhLmFzc2VydGlvbnMvYzJwYS5hY3Rpb25zLnYyZGhhc2hYINkVe6c+VaxtxFtBY7apqh+JXGPpuxyKo0V47XdJGgmeomN1cmx4KXNlbGYjanVtYmY9YzJwYS5hc3NlcnRpb25zL2MycGEuaGFzaC5kYXRhZGhhc2hYIHVMS7ww71hW9XKxrc4H8xVhWCeyaeb4iVV625c0ZLeydGNsYWltX2dlbmVyYXRvcl9pbmZvo2RuYW1lb0FudGhyb3BpYyBGaWxlc2d2ZXJzaW9uZTEuMC4wa3NwZWNWZXJzaW9uZTIuNC4wAAAQOGp1bWIAAAAoanVtZGMyY3MAEQAQgAAAqgA4m3EDYzJwYS5zaWduYXR1cmUAAAAQCGNib3LShFkCEqIBJhghWQIKMIICBjCCAY2gAwIBAgIUQOWgCu7COdC+uIP6BkIFPWdVEwAwCgYIKoZIzj0EAwMwSTEXMBUGA1UEChMOQW50aHJvcGljLCBQQkMxLjAsBgNVBAMTJUFudGhyb3BpYyBDb250ZW50IENyZWRlbnRpYWxzIFJvb3QgQ0EwHhcNMjYwODA3MTg0MzU2WhcNMjgwODA2MTk0MzU2WjBEMRcwFQYDVQQKEw5BbnRocm9waWMsIFBCQzEpMCcGA1UEAxMgQW50aHJvcGljIENsYXVkZSBDb250ZW50IFNpZ25pbmcwWTATBgcqhkjOPQIBBggqhkjOPQMBBwNCAASYegpry1AYBRTVNL1CpTlbROnY3dey+UrsF9C3phYrATN3ZHf93Mo8RQN0KOUuOn19P4oWNFWe5n2/She9N7eTo1gwVjAOBgNVHQ8BAf8EBAMCB4AwFQYDVR0lBA4wDAYKKwYBBAGD6F4CATAMBgNVHRMBAf8EAjAAMB8GA1UdIwQYMBaAFM5R4gSBTmRbI/jjxM+aPpzB11zCMAoGCCqGSM49BAMDA2cAMGQCMDFzHRSeAXrSy1WOzkbhPZ6Km2wGTmZ/2gK18k8BQGXyqz88Rdrz6CTX9flAnYNVxgIwcF9c3fVhqmJKpi+UhasNUMko69cyX6STPfta3Q8EjyzDjzoyrol46FP6VFHhvUcJoWNwYWRZDZ4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA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" width="34" height="34" alt="" style="border-radius:9px"><span>نت سایت</span></a>
  <span class="sp"></span>
  <button class="btn gh sm" type="button" id="thm" onclick="tt()"><span class="td">حالت روشن</span><span class="tl">حالت تیره</span></button>
  <?php if ($me): ?>
    <button class="btn gh sm" type="button" id="palbtn"> جستجو <span class="kbd">Ctrl K</span></button>
    <button class="btn gh sm" type="button" id="install" hidden> نصب</button>
    <span class="mut mono" style="margin:0 4px"><?= h($me) ?></span>
    <form method="POST" style="margin:0"><input type="hidden" name="do" value="logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn gh sm" type="submit"> خروج</button></form>
  <?php else: ?>
    <button class="btn gh sm" type="button" id="install" hidden> نصب</button>
  <?php endif; ?>
</nav>

<?php if ($flash): ?><div class="flash <?= h($flash[1]) ?>"><?= h($flash[0]) ?></div><?php endif; ?>

<?php if ($view === 'auth'): ?>
<!-- ===================== ورود ===================== -->
<section class="auth">
  <div class="hero"><img src="data:image/jpeg;base64,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" alt="نت سایت" width="240" height="240" style="max-width:60%;height:auto;border-radius:24px;margin-bottom:16px"><h1>پلتفرم ساخت و انتشار وب‌سایت</h1><p class="mut" style="max-width:460px">سایت خود را بسازید، مدیریت کنید و با یک لینک منتشر کنید؛ بدون نیاز به دانش سرور.</p><ul class="feat"><li>مدیریت فایل و پوشه با آپلود ZIP</li><li>ویرایشگر کد با پیش‌نمایش زنده</li><li>تاریخچهٔ نسخه‌ها و پشتیبان‌گیری کامل</li><li>قابل نصب به‌عنوان اپلیکیشن</li></ul></div>
  <div class="panel">
    <div class="tabs hide-nojs"><button type="button" class="on" data-t="login">ورود</button><button type="button" data-t="reg">ثبت‌نام</button></div>
    <form method="POST" id="f-login" autocomplete="on">
      <input type="hidden" name="do" value="login">
      <label>نام کاربری</label><input name="username" dir="ltr" required autocomplete="username" autocapitalize="off">
      <label>رمز عبور</label><input name="password" type="password" dir="ltr" required autocomplete="current-password">
      <button class="btn" style="width:100%" type="submit"> ورود</button>
    </form>
    <div class="nojs-sep">— یا حساب جدید بسازید —</div>
    <form method="POST" id="f-reg" class="hide" autocomplete="on">
      <input type="hidden" name="do" value="register">
      <label>نام کاربری (انگلیسی، ۳ تا ۳۰ کاراکتر)</label><input name="username" dir="ltr" required pattern="[A-Za-z0-9_-]{3,30}" autocomplete="username" autocapitalize="off">
      <label>رمز عبور (حداقل ۸ کاراکتر)</label><input name="password" type="password" dir="ltr" required minlength="8" autocomplete="new-password">
      <div class="pw"><i id="pwb"></i></div>
      <button class="btn" style="width:100%" type="submit"> ساخت حساب رایگان</button>
    </form>
  </div>
</section>

<?php elseif ($view === 'dash'): ?>
<!-- ===================== داشبورد ===================== -->
<div class="hello"><h2>سلام <span class="mono" style="background:var(--g);-webkit-background-clip:text;background-clip:text;color:transparent"><?= h($me) ?></span> </h2></div>
<div class="stats">
  <div class="stat"><b data-n="<?= count($cards) ?>">0</b><span>پروژه</span></div>
  <div class="stat"><b data-n="<?= $totalFiles ?>">0</b><span>فایل منتشرشده</span></div>
  <div class="stat"><b data-n="<?= $totalDirs ?>">0</b><span>پوشه</span></div>
  <div class="stat"><b><?= h(fsize($totalBytes)) ?></b><span>از <?= h(fsize(QUOTA)) ?> مصرف شده</span></div>
</div>
<div class="tool"><input id="q" type="search" placeholder="جستجوی پروژه…"><div class="seg" id="srt"><button type="button" class="on" data-k="n">نام</button><button type="button" data-k="s">حجم</button><button type="button" data-k="f">تعداد فایل</button></div><?php if ($HAS_ZIP): ?><a class="btn gh" href="?backup=1">پشتیبان کامل (ZIP)</a><?php endif; ?></div>
<div class="bar"><i style="width:<?= min(100, (int)round($totalBytes / QUOTA * 100)) ?>%"></i></div>
<div class="grid">
  <div class="card add tilt" id="newp" tabindex="0"><div><div class="plus">＋</div><b>پروژهٔ جدید</b><div class="mut">یک سایت تازه بسازید</div></div><div class="glare"></div></div>
  <?php foreach ($cards as $c): ?>
  <div class="card pc" data-nm="<?= h($c['n']) ?>" data-s="<?= (int)$c['b'] ?>" data-f="<?= (int)$c['f'] ?>">
    <h3> <?= h($c['n']) ?></h3>
    <div class="tags"><span class="tag"><?= $c['f'] ?> فایل</span><span class="tag"><?= $c['d'] ?> پوشه</span><span class="tag"><?= h(fsize($c['b'])) ?></span><?php if (!$c['idx']): ?><span class="tag w">بدون index.html</span><?php endif; ?><?php if ($c['m']): ?><span class="tag"><?= h(date('Y/m/d', $c['m'])) ?></span><?php endif; ?></div>
    <div class="row">
      <a class="btn sm" href="?p=<?= urlencode($c['n']) ?>"> مدیریت</a>
      <?php if ($c['idx']): ?><a class="btn ok sm" href="<?= h(surl($me, $c['n'], 'index.html')) ?>" target="_blank" rel="noopener"> مشاهده</a><?php endif; ?>
      <?php if ($HAS_ZIP): ?><a class="btn gh sm" href="?dl=<?= urlencode($c['n']) ?>"> ZIP</a><?php endif; ?>
      <?php if ($c['idx']): ?><button class="btn gh sm" type="button" data-copy="<?= h(surl($me, $c['n'], 'index.html')) ?>">کپی لینک</button><?php endif; ?><button class="btn gh sm" type="button" data-a="dup" data-p="<?= h($c['n']) ?>">تکثیر</button>
      <button class="btn gh sm" type="button" data-a="ren" data-p="<?= h($c['n']) ?>">تغییر نام</button>
      <button class="btn bad sm" type="button" data-a="delp" data-p="<?= h($c['n']) ?>">حذف</button>
    </div>
    <div class="glare"></div>
  </div>
  <?php endforeach; ?>
</div>
<?php if (!$cards): ?><div class="empty">هنوز پروژه‌ای ندارید — روی «پروژهٔ جدید» بزنید </div><?php endif; ?>

<?php $lg = json_decode((string)@file_get_contents(USERS . '/' . $me . '/log.json'), true) ?: []; if ($lg): ?>
<div class="panel" style="margin-top:26px"><b>فعالیت‌های اخیر</b><div class="acts"><?php foreach (array_slice($lg, 0, 8) as $a): ?><div><span class="mut mono"><?= h(date('Y/m/d H:i', (int)$a[0])) ?></span><span><?= h($a[1]) ?></span></div><?php endforeach; ?></div></div>
<?php endif; ?>
<details class="panel" style="margin-top:26px">
  <summary style="cursor:pointer;font-weight:800"> تنظیمات حساب</summary>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
    <form method="POST"><input type="hidden" name="do" value="profile"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <label>نام کاربری جدید (اختیاری)</label><input name="new_username" dir="ltr" placeholder="<?= h($me) ?>">
      <label>رمز جدید (اختیاری)</label><input name="new_password" type="password" dir="ltr" autocomplete="new-password">
      <label>رمز فعلی (الزامی)</label><input name="current_password" type="password" dir="ltr" required>
      <button class="btn" type="submit"> ذخیره</button></form>
    <form method="POST" onsubmit="return confirm('حساب و همهٔ سایت‌ها برای همیشه حذف شود؟')"><input type="hidden" name="do" value="delete_account"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <label style="color:var(--bad)">حذف دائمی حساب — رمز عبور</label><input name="current_password" type="password" dir="ltr" required>
      <button class="btn bad" type="submit"> حذف حساب</button></form>
  </div>
</details>

<?php elseif ($view === 'browse'):
    $baseP = SITES . '/' . $me . '/' . $P;
    list($dirs, $files) = ls($baseP, $D);
    $parts = $D === '' ? [] : explode('/', $D);
    $parent = count($parts) > 1 ? implode('/', array_slice($parts, 0, -1)) : '';
?>
<!-- ===================== مرورگر فایل ===================== -->
<div class="panel" style="margin-top:18px">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
    <div>
      <div class="crumbs"><a href="?p=<?= urlencode($P) ?>"> <?= h($P) ?></a>
      <?php $acc = ''; foreach ($parts as $x): $acc = $acc === '' ? $x : $acc . '/' . $x; ?><span>/</span><a href="?p=<?= urlencode($P) ?>&d=<?= urlencode($acc) ?>"><?= h($x) ?></a><?php endforeach; ?></div>
      <div class="mut"><?= count($dirs) ?> پوشه · <?= count($files) ?> فایل در این مسیر</div>
    </div>
    <div class="row">
      <a class="btn gh sm" href="<?= h($self) ?>">← داشبورد</a>
      <?php if (is_file($baseP . '/index.html')): ?><a class="btn ok sm" href="<?= h(surl($me, $P, 'index.html')) ?>" target="_blank" rel="noopener"> مشاهدهٔ سایت</a><?php endif; ?>
      <button class="btn sm" type="button" data-a="mkdir">＋ پوشه</button>
      <button class="btn sm" type="button" data-a="newfile">＋ فایل</button>
      <?php if ($HAS_ZIP): ?><a class="btn gh sm" href="?dl=<?= urlencode($P) ?>"> ZIP</a><?php endif; ?>
    </div>
  </div>

  <?php $uu = usage($me); ?><div class="mut" style="margin-top:14px">فضای مصرفی حساب: <?= h(fsize($uu)) ?> از <?= h(fsize(QUOTA)) ?></div><div class="bar" style="margin:6px 0 0"><i style="width:<?= min(100, (int)round($uu / QUOTA * 100)) ?>%"></i></div>
  <div class="tool" style="margin-top:14px"><input id="fq" placeholder="جستجو در نام و محتوای فایل‌های پروژه…"><button class="btn sm" type="button" id="fgo">جستجو</button></div><div class="files" id="sres" hidden></div>
  <div class="files">
    <?php if ($D !== '' ): ?><a class="fr" href="?p=<?= urlencode($P) ?><?= $parent !== '' ? '&d=' . urlencode($parent) : '' ?>"><span class="nm"> ..</span><span class="mut">بازگشت به پوشهٔ بالاتر</span></a><?php endif; ?>
    <?php foreach ($dirs as $n): $path = $D === '' ? $n : $D . '/' . $n; ?>
    <div class="fr"><a class="nm" href="?p=<?= urlencode($P) ?>&d=<?= urlencode($path) ?>"> <?= h($n) ?>/</a>
      <div class="row"><button class="btn gh sm" type="button" data-a="ren" data-t="dir" data-f="<?= h($path) ?>">تغییر نام</button><button class="btn bad sm" type="button" data-a="del" data-t="dir" data-f="<?= h($path) ?>">حذف</button></div></div>
    <?php endforeach; ?>
    <?php foreach ($files as $f): $path = $D === '' ? $f['n'] : $D . '/' . $f['n'];
        $fe = strtolower(pathinfo($f['n'], PATHINFO_EXTENSION));
        $ic = in_array($fe, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg'], true) ? '' : (in_array($fe, ['css'], true) ? '' : (in_array($fe, ['js', 'json'], true) ? '' : (in_array($fe, ['html', 'htm'], true) ? '' : ''))); ?>
    <div class="fr"><span class="nm"><?= in_array($fe, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg'], true) ? '<img class="th" loading="lazy" alt="" src="' . h(surl($me, $P, $path)) . '">' : '<span class="ext">' . h($fe ?: '—') . '</span>' ?> <?= h($f['n']) ?> <span class="mut"><?= h(fsize($f['s'])) ?></span></span>
      <div class="row">
        <?php if (is_text($f['n'])): ?><a class="btn sm" href="?p=<?= urlencode($P) ?>&edit=<?= urlencode($path) ?>"> ویرایش</a><?php endif; ?>
        <button class="btn gh sm" type="button" data-copy="<?= h(surl($me, $P, $path)) ?>">کپی لینک</button><a class="btn gh sm" href="<?= h(surl($me, $P, $path)) ?>" target="_blank" rel="noopener">باز کردن</a>
        <button class="btn gh sm" type="button" data-a="ren" data-t="file" data-f="<?= h($path) ?>">تغییر نام</button>
        <button class="btn bad sm" type="button" data-a="del" data-t="file" data-f="<?= h($path) ?>">حذف</button></div></div>
    <?php endforeach; ?>
    <?php if (!$dirs && !$files): ?><div class="empty">این پوشه خالی است. فایل یا پوشه‌ای در کادر زیر رها کنید </div><?php endif; ?>
  </div>

  <div class="drop" id="drop"><div class="big"></div>
    <p style="margin:6px 0;font-weight:800">فایل، پوشه یا ZIP را اینجا رها کنید</p>
    <div class="mut">آپلود در: <span class="mono"><?= h($P . ($D !== '' ? '/' . $D : '')) ?></span> · هر فایل تا <?= h(fsize(min(MAX_FILE, $upMax))) ?><?= $HAS_ZIP ? ' · ZIP تا ' . h(fsize(min(MAX_ZIP, $upMax, (int)($postMax * .8)))) : ' ·  ZIP روی این هاست فعال نیست' ?></div>
    <div class="row" style="justify-content:center;margin-top:12px"><button type="button" class="btn sm" id="pf">انتخاب فایل / ZIP</button><button type="button" class="btn sm" id="pd">انتخاب پوشه</button></div></div>
  <input type="file" id="fi" multiple hidden><input type="file" id="fd" webkitdirectory multiple hidden>
  <label style="margin-top:12px;display:flex;align-items:center;gap:8px"><input type="checkbox" id="strip" checked style="width:auto;margin:0">حذف نام پوشهٔ ریشه (my-site/index.html ← index.html)</label>
  <div class="bar" id="barw" hidden><i id="bar"></i></div>
  <div class="mut" id="st"></div><div class="log" id="log"></div>
</div>

<?php elseif ($view === 'edit'): ?>
<!-- ===================== ویرایشگر ===================== -->
<?php $ext = strtolower(pathinfo($E['path'], PATHINFO_EXTENSION)); $edir = strpos($E['path'], '/') !== false ? dirname($E['path']) : ''; ?>
<div class="panel" style="margin-top:18px">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
    <div><b class="mono"> <?= h($P . '/' . $E['path']) ?></b> <?= $E['new'] ? '<span class="tag">جدید</span>' : '' ?><div class="mut" id="meta"></div></div>
    <div class="row"><button class="btn ok" type="button" id="save">ذخیره (Ctrl+S)</button><button class="btn gh" type="button" id="hist">تاریخچه</button><?php if (in_array($ext, ['html', 'htm', 'svg'], true)): ?><button class="btn gh" type="button" id="dev">پیش‌نمایش موبایل</button><?php endif; ?>
      <a class="btn gh" target="_blank" rel="noopener" href="<?= h(surl($me, $P, $E['path'])) ?>"> باز کردن</a>
      <a class="btn gh" href="?p=<?= urlencode($P) ?><?= $edir !== '' ? '&d=' . urlencode($edir) : '' ?>">← بازگشت</a></div>
  </div>
  <div class="ed<?= in_array($ext, ['html', 'htm', 'svg'], true) ? '' : ' solo' ?>" id="ed">
    <textarea id="code" spellcheck="false">
<?= h($E['text']) ?></textarea>
    <?php if (in_array($ext, ['html', 'htm', 'svg'], true)): ?><iframe class="pv" id="pv" sandbox="allow-scripts allow-forms allow-popups allow-modals" title="پیش‌نمایش"></iframe><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<footer class="foot">
  <p style="font-weight:900;font-size:17px"> نت سایت <span class="tag">v<?= VER ?></span></p>
  <p class="mut">طراحی و توسعه با <span class="hr"></span> توسط <span class="au">امیرحسین واقی</span></p>
  <p style="font-weight:700;font-size:13px">© <span id="yr"></span> نت سایت — تمام حقوق محفوظ است.</p>
  <p class="mut" style="font-size:11.5px">هرگونه کپی‌برداری، بازنشر یا فروش بدون اجازهٔ کتبی سازنده ممنوع است.</p>
</footer>
</div>

<dialog id="pal"><div class="pal-in"><input id="pq" placeholder="نام پروژه یا دستور را بنویسید…" autocomplete="off"></div><div class="pal-list" id="pl"></div></dialog>
<dialog id="bld"><div class="mod" style="text-align:center"><h3>در حال ساخت سایت <span class="mono" id="bnm"></span></h3><div class="bar"><i id="bbar"></i></div><div class="mono" id="bpc" style="font-weight:700">0%</div><div class="mut" id="bst"></div><p class="mut" style="margin:14px 0 0">لطفاً تا پایان کار صفحه را نبندید.</p></div></dialog>
<dialog id="histd"><div class="mod"><h3>تاریخچهٔ نسخه‌ها</h3><div id="hl" class="pal-list"></div></div></dialog>
<dialog id="newd"><form method="POST" class="mod"><input type="hidden" name="do" value="new_project"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <h3> پروژهٔ جدید</h3><label>نام پروژه (انگلیسی؛ فاصله به - تبدیل می‌شود)</label><input name="project" dir="ltr" required autocomplete="off" id="npn">
  
  <div class="row" style="margin-top:14px"><button class="btn" type="submit">ساخت</button><button class="btn gh" type="button" onclick="document.getElementById('newd').close()">انصراف</button></div></form></dialog>

<script>
const ME=<?= jjs($me) ?>,CSRF=<?= jjs($csrf) ?>,VIEW=<?= jjs($view) ?>,P=<?= jjs($P) ?>,D=<?= jjs($D) ?>,
      PAL=<?= jjs($pal) ?>,LIM={batch:<?= $BATCH ?>,file:<?= (int)min(MAX_FILE, $upMax) ?>,zip:<?= (int)min(MAX_ZIP, $upMax, (int)($postMax * .8)) ?>},
      EDITP=<?= jjs($E ? $E['path'] : '') ?>,EXT=<?= jjs($E ? strtolower(pathinfo($E['path'], PATHINFO_EXTENSION)) : '') ?>,
      SURL=<?= jjs($me && $P !== '' ? surl($me, $P, '') . '/' : '') ?>;
const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)];
const esc=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmt=b=>b>=1048576?(b/1048576).toFixed(1)+' MB':b>=1024?(b/1024).toFixed(1)+' KB':b+' B';
function toast(m,t){const d=document.createElement('div');d.className='toast '+(t||'');d.textContent=m;$('#toasts').appendChild(d);setTimeout(()=>d.remove(),5000)}
$('#yr').textContent=new Intl.DateTimeFormat('fa-IR',{year:'numeric'}).format(new Date());

/* پاک‌سازی Service Worker و کش نسخه‌های قبلی (برای رفع قفل شدن صفحه) */
addEventListener('load',()=>{try{if(navigator.serviceWorker)navigator.serviceWorker.getRegistrations().then(rs=>rs.forEach(r=>r.unregister())).catch(()=>{});if(window.caches)caches.keys().then(ks=>ks.forEach(k=>caches.delete(k))).catch(()=>{})}catch(e){}});

/* ---------- افکت‌ها (هر کدام ایزوله؛ خطا هرگز صفحه را قفل نمی‌کند) ---------- */
(function(){try{$$('[data-n]').forEach(el=>{const n=+el.dataset.n;let k=0;const st=Math.max(1,Math.ceil(n/20));(function t(){k=Math.min(n,k+st);el.textContent=k;if(k<n)setTimeout(t,30)})()})}catch(e){}})();
function boom(){}
let dip=null;
addEventListener('beforeinstallprompt',e=>{e.preventDefault();dip=e;const b=$('#install');if(b)b.hidden=false});
$('#install')&&($('#install').onclick=async()=>{if(dip){dip.prompt();await dip.userChoice;dip=null;$('#install').hidden=true}});

/* ---------- ورود / ثبت‌نام ---------- */
$$('.tabs [data-t]').forEach(b=>b.onclick=()=>{$$('.tabs [data-t]').forEach(x=>x.classList.toggle('on',x===b));$('#f-login').classList.toggle('hide',b.dataset.t!=='login');$('#f-reg').classList.toggle('hide',b.dataset.t!=='reg')});

/* ---------- عملیات عمومی ---------- */
function act(f,msg){
  if(msg&&!confirm(msg))return;
  const form=document.createElement('form');form.method='POST';
  Object.entries({csrf:CSRF,...f}).forEach(([k,v])=>{const i=document.createElement('input');i.type='hidden';i.name=k;i.value=v;form.appendChild(i)});
  document.body.appendChild(form);form.submit();
}
const join=(d,n)=>d?d.replace(/\/+$/,'')+'/'+n:n;
document.addEventListener('click',e=>{
  const b=e.target.closest('[data-a]');if(!b)return;
  const a=b.dataset.a,p=b.dataset.p||P,f=b.dataset.f||'',t=b.dataset.t||'file';
  if(a==='dup')act({do:'dup_project',project:p});
  else if(a==='ren'&&VIEW==='dash'){const n=prompt('نام جدید پروژه (انگلیسی):',p);if(n&&n!==p)act({do:'rename_project',project:p,new_name:n})}
  else if(a==='delp')act({do:'del_project',project:p},'پروژهٔ «'+p+'» و همهٔ فایل‌هایش حذف شود؟');
  else if(a==='mkdir'){const n=prompt('نام پوشه (می‌توانید تو در تو بنویسید: assets/img):');if(n)act({do:'mkdir',project:P,dir:D,path:join(D,n)})}
  else if(a==='newfile'){const n=prompt('نام فایل — مثلاً about.html یا style.css:');if(n)location.href='?p='+encodeURIComponent(P)+'&edit='+encodeURIComponent(join(D,n))+'&new=1'}
  else if(a==='ren'){const n=prompt('مسیر جدید (برای انتقال، پوشه را هم بنویسید):',f);if(n&&n!==f)act({do:'rename',project:P,dir:D,type:t,from:f,to:n})}
  else if(a==='del')act({do:'del',project:P,dir:D,type:t,path:f},'«'+f+'» حذف شود؟');
});
if($('#newp')){const o=()=>{$('#newd').showModal();setTimeout(()=>$('#npn').focus(),50)};$('#newp').onclick=o;$('#newp').onkeydown=e=>{if(e.key==='Enter')o()}}

/* ---------- پالت فرمان (Ctrl+K) ---------- */
(function(){try{
  const dlg=$('#pal');if(!dlg)return;
  const items=[{t:' داشبورد',u:<?= jjs($self) ?>}].concat(PAL,[{t:'＋ پروژهٔ جدید',a:'new'},{t:' خروج',a:'out'}]);
  let sel=0,cur=items;
  function draw(){$('#pl').innerHTML=cur.map((x,i)=>'<'+(x.u?'a href="'+esc(x.u)+'"':'button type="button" data-i="'+i+'"')+' class="'+(i===sel?'on':'')+'">'+esc(x.t)+'</'+(x.u?'a':'button')+'>').join('')||'<div class="mut" style="padding:14px">چیزی پیدا نشد</div>'}
  function open(){sel=0;$('#pq').value='';cur=items;draw();dlg.showModal();$('#pq').focus()}
  function run(x){if(!x)return;if(x.u)location.href=x.u;else if(x.a==='new'){dlg.close();$('#newp')?$('#newp').click():location.href=<?= jjs($self) ?>}else if(x.a==='out'){act({do:'logout'})}}
  $('#palbtn').onclick=open;
  addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();open()}});
  $('#pq').oninput=e=>{const q=e.target.value.trim().toLowerCase();cur=items.filter(x=>x.t.toLowerCase().includes(q));sel=0;draw()};
  $('#pq').onkeydown=e=>{if(e.key==='ArrowDown'){sel=Math.min(cur.length-1,sel+1);draw();e.preventDefault()}else if(e.key==='ArrowUp'){sel=Math.max(0,sel-1);draw();e.preventDefault()}else if(e.key==='Enter')run(cur[sel])};
  $('#pl').onclick=e=>{const b=e.target.closest('[data-i]');if(b)run(cur[+b.dataset.i])};
  dlg.addEventListener('click',e=>{if(e.target===dlg)dlg.close()});
}catch(e){}})();

/* ---------- آپلود فایل / پوشه / ZIP ---------- */
if(VIEW==='browse'){
  const Q=[];let busy=false;
  const note=(s)=>$('#st').textContent=s;
  function add(file,path){if(/(^|\/)(__MACOSX|\.DS_Store|Thumbs\.db)(\/|$)/.test(path))return;Q.push({file,path:path.replace(/^\/+/,'')})}
  async function walk(en,pre){
    if(en.isFile){const f=await new Promise((r,j)=>en.file(r,j));add(f,pre+en.name)}
    else if(en.isDirectory){const rd=en.createReader();let all=[],bt;do{bt=await new Promise((r,j)=>rd.readEntries(r,j));all=all.concat(bt)}while(bt.length);for(const c of all)await walk(c,pre+en.name+'/')}
  }
  const dz=$('#drop');
  dz.ondragover=e=>{e.preventDefault();dz.classList.add('over')};
  dz.ondragleave=()=>dz.classList.remove('over');
  dz.ondrop=async e=>{e.preventDefault();dz.classList.remove('over');
    const ens=[...(e.dataTransfer.items||[])].map(i=>i.webkitGetAsEntry&&i.webkitGetAsEntry()).filter(Boolean),fl=[...e.dataTransfer.files];
    try{if(ens.length)for(const en of ens)await walk(en,'');else fl.forEach(f=>add(f,f.name))}catch(x){toast('خواندن پوشه ناموفق بود؛ از «انتخاب پوشه» استفاده کنید','bad');return}
    go()};
  dz.onclick=e=>{if(!e.target.closest('button'))$('#fi').click()};
  $('#pf').onclick=()=>$('#fi').click();$('#pd').onclick=()=>$('#fd').click();
  $('#fi').onchange=e=>{[...e.target.files].forEach(f=>add(f,f.name));e.target.value='';go()};
  $('#fd').onchange=e=>{[...e.target.files].forEach(f=>add(f,f.webkitRelativePath||f.name));e.target.value='';go()};

  async function send(files,paths){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('ajax','1');fd.append('do','upload');fd.append('project',P);fd.append('dir',D);fd.append('strip',$('#strip').checked?'1':'0');
    files.forEach((f,i)=>{fd.append('files[]',f,f.name);fd.append('paths[]',paths[i])});
    try{
      const r=await fetch(location.pathname,{method:'POST',body:fd,credentials:'same-origin'}),t=await r.text();
      try{return JSON.parse(t)}catch(e){return{ok:false,msg:'پاسخ نامعتبر از سرور (HTTP '+r.status+'): '+t.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,150)}}
    }catch(e){return{ok:false,msg:'ارتباط با سرور برقرار نشد.'}}
  }
  async function go(){
    if(busy||!Q.length)return;busy=true;
    const items=Q.splice(0,Q.length);
    // حذف ریشهٔ مشترک پوشه (فقط برای فایل‌های غیر ZIP)
    let paths=items.map(x=>x.path);
    const plain=items.filter(x=>!/\.zip$/i.test(x.path));
    if($('#strip').checked&&plain.length&&plain.every(x=>x.path.includes('/'))){const r=plain[0].path.split('/')[0];if(plain.every(x=>x.path.startsWith(r+'/')))paths=items.map(x=>/\.zip$/i.test(x.path)?x.path:x.path.slice(r.length+1))}
    $('#barw').hidden=false;$('#bar').style.width='3%';$('#log').innerHTML='';
    let saved=0,folders=0,skipped=[],batches=[],cur={f:[],p:[],b:0};
    items.forEach((it,i)=>{
      const zip=/\.zip$/i.test(it.path),lim=zip?LIM.zip:LIM.file;
      if(it.file.size>lim){skipped.push([it.path,'بزرگ‌تر از '+fmt(lim)+' (محدودیت هاست)']);return}
      if(cur.f.length&&(zip||cur.b+it.file.size>LIM.batch||cur.f.length>=60)){batches.push(cur);cur={f:[],p:[],b:0}}
      cur.f.push(it.file);cur.p.push(paths[i]);cur.b+=it.file.size;
      if(zip){batches.push(cur);cur={f:[],p:[],b:0}}
    });
    if(cur.f.length)batches.push(cur);
    for(let i=0;i<batches.length;i++){
      note('در حال ارسال… ('+(i+1)+' از '+batches.length+')');
      const r=await send(batches[i].f,batches[i].p);
      saved+=r.saved||0;folders+=r.folders||0;(r.skipped||[]).forEach(s=>skipped.push(s));
      if(!r.ok&&r.msg)skipped.push(['(درخواست)',r.msg]);
      $('#bar').style.width=Math.round((i+1)/batches.length*100)+'%';
    }
    busy=false;
    note(saved+' فایل'+(folders?' و '+folders+' پوشه':'')+' منتشر شد'+(skipped.length?' · '+skipped.length+' مورد ردشد':''));
    if(skipped.length)$('#log').innerHTML='<b>ردشده‌ها / خطاها:</b><br>'+skipped.slice(0,40).map(s=>'• <span class="mono">'+esc(s[0])+'</span> — '+esc(s[1])).join('<br>');
    if(saved+folders>0){toast(' منتشر شد','ok');boom();try{sessionStorage.setItem('boom','1')}catch(e){}if(!skipped.length)setTimeout(()=>location.reload(),1100)}
    else toast(skipped[0]?skipped[0][1]:'چیزی ذخیره نشد','bad');
  }
}

/* ---------- ویرایشگر ---------- */
if(VIEW==='edit'){
  const ta=$('#code'),pv=$('#pv');let dirty=false,tm;
  const dir=new URL(SURL+EDITP.replace(/[^/]*$/,''),location.href).href;
  const meta=()=>{$('#meta').textContent=ta.value.split('\n').length+' خط · '+ta.value.length+' نویسه'+(dirty?' · ذخیره‌نشده ●':'')};
  const render=()=>{if(!pv)return;pv.srcdoc=EXT==='svg'?'<body style="margin:0;display:grid;place-items:center;height:100vh">'+ta.value:'<base href="'+dir+'">'+ta.value};
  ta.addEventListener('input',()=>{dirty=true;meta();clearTimeout(tm);tm=setTimeout(render,350)});
  ta.addEventListener('keydown',e=>{if(e.key==='Tab'){e.preventDefault();ta.setRangeText('  ',ta.selectionStart,ta.selectionEnd,'end')}});
  addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){e.preventDefault();save()}});
  addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue=''}});
  function b64(s){const u=new TextEncoder().encode(s);let r='';for(let i=0;i<u.length;i+=0x8000)r+=String.fromCharCode.apply(null,u.subarray(i,i+0x8000));return btoa(r)}
  async function save(){
    const b=$('#save');b.disabled=true;
    try{
      const r=await fetch(location.pathname,{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf:CSRF,ajax:'1',do:'save',project:P,path:EDITP,code_b64:b64(ta.value)})});
      const t=await r.text();let j;try{j=JSON.parse(t)}catch(e){j={ok:false,msg:'پاسخ نامعتبر از سرور (HTTP '+r.status+')'}}
      if(j.ok){dirty=false;meta();boom();try{localStorage.removeItem('draft:'+P+'/'+EDITP)}catch(e){}}toast(j.msg||(j.ok?'ذخیره شد':'خطا'),j.ok?'ok':'bad');
    }catch(e){toast('ارتباط با سرور برقرار نشد','bad')}
    b.disabled=false;
  }
  $('#save').onclick=save;meta();render();
}

/* ---------- قابلیت‌های نسخهٔ ۵ ---------- */
document.addEventListener('click',e=>{const b=e.target.closest('[data-copy]');if(!b)return;const u=new URL(b.dataset.copy,location.href).href;(navigator.clipboard?navigator.clipboard.writeText(u):Promise.reject()).then(()=>toast('لینک کپی شد','ok'),()=>prompt('لینک سایت:',u))});
const api=async o=>{try{const r=await fetch(location.pathname,{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf:CSRF,ajax:'1',project:P,...o})});return JSON.parse(await r.text())}catch(e){return{ok:false,msg:'پاسخ نامعتبر از سرور'}}};
if(VIEW==='dash'&&$('#q')){const g=$('.grid'),cs=$$('.pc');const ap=()=>{const q=$('#q').value.trim().toLowerCase(),k=$('#srt .on').dataset.k;cs.forEach(c=>c.hidden=!c.dataset.nm.toLowerCase().includes(q));[...cs].sort((a,b)=>k==='n'?a.dataset.nm.localeCompare(b.dataset.nm):b.dataset[k]-a.dataset[k]).forEach(c=>g.appendChild(c))};$('#q').oninput=ap;$('#srt').onclick=e=>{const b=e.target.closest('[data-k]');if(!b)return;$$('#srt button').forEach(x=>x.classList.toggle('on',x===b));ap()}}
if(VIEW==='browse'){const doSearch=async()=>{const q=$('#fq').value.trim(),o=$('#sres');const j=await api({do:'search',q});o.hidden=false;o.innerHTML=j.ok?(j.items.length?j.items.map(x=>'<a class="fr" href="?p='+encodeURIComponent(P)+'&edit='+encodeURIComponent(x[0])+'"><span class="nm">'+esc(x[0])+'</span><span class="mut">'+(x[1]?'خط '+x[1]:'نام فایل')+'</span></a>').join(''):'<div class="empty">موردی پیدا نشد</div>'):'<div class="empty">'+esc(j.msg||'خطا')+'</div>'};$('#fgo').onclick=doSearch;$('#fq').onkeydown=e=>{if(e.key==='Enter')doSearch()}}
if(VIEW==='edit'){const ta=$('#code'),pv=$('#pv');
 $('#dev')&&($('#dev').onclick=()=>{const m=pv.style.maxWidth!=='';pv.style.maxWidth=m?'':'390px';pv.style.margin=m?'':'0 auto';$('#dev').textContent=m?'پیش‌نمایش موبایل':'پیش‌نمایش دسکتاپ'});
 $('#hist').onclick=async()=>{const j=await api({do:'hist',path:EDITP});$('#histd').showModal();$('#hl').innerHTML=j.ok&&j.items.length?j.items.map(x=>'<button type="button" data-v="'+esc(x[0])+'">'+new Date(x[1]*1000).toLocaleString('fa-IR')+'</button>').join(''):'<div class="mut" style="padding:12px">هنوز نسخه‌ای ذخیره نشده است</div>'};
 $('#hl').onclick=async e=>{const b=e.target.closest('[data-v]');if(!b)return;const j=await api({do:'hist',path:EDITP,v:b.dataset.v});if(!j.ok){toast(j.msg||'خطا','bad');return}if(confirm('این نسخه در ویرایشگر بارگذاری شود؟ (سپس ذخیره کنید)')){ta.value=j.text;ta.dispatchEvent(new Event('input'));$('#histd').close()}};
 const K='draft:'+P+'/'+EDITP;try{const d=localStorage.getItem(K);if(d&&d!==ta.value&&confirm('پیش‌نویس ذخیره‌نشده‌ای از این فایل پیدا شد. بازیابی شود؟')){ta.value=d;ta.dispatchEvent(new Event('input'))}ta.addEventListener('input',()=>localStorage.setItem(K,ta.value))}catch(e){}
}

(function(){const f=$('#newd form');if(!f)return;const T=2500,M=['ساخت پوشهٔ پروژه…','آماده‌سازی فایل‌ها…','تنظیم صفحهٔ اصلی…','انتشار سایت…','آخرین بررسی‌ها…'];
f.addEventListener('submit',e=>{e.preventDefault();if(!f.checkValidity()){f.reportValidity();return}
 const d=$('#bld'),t0=Date.now();$('#newd').close();$('#bnm').textContent=f.elements.project.value;d.addEventListener('cancel',x=>x.preventDefault());d.showModal();
 const iv=setInterval(()=>{const p=Math.min(1,(Date.now()-t0)/T);$('#bbar').style.width=p*100+'%';$('#bpc').textContent=Math.floor(p*100)+'%';$('#bst').textContent=M[Math.min(M.length-1,Math.floor(p*M.length))];if(p>=1){clearInterval(iv);f.submit()}},200)})})();
const pi=$('#f-reg input[name=password]');pi&&pi.addEventListener('input',()=>{const v=pi.value;let n=0;if(v.length>=8)n++;if(v.length>=12)n++;if(/[A-Z]/.test(v)&&/[a-z]/.test(v))n++;if(/\d/.test(v))n++;if(/[^A-Za-z0-9]/.test(v))n++;const b=$('#pwb');b.style.width=n*20+'%';b.style.background=n<3?'#ef4444':n<5?'#f59e0b':'#10b981'});
</script>
</body>
</html>
