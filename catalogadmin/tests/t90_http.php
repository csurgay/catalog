<?php
// Élő oldalak HTTP-n keresztül (csak olvasás). Ha a szerver nem éri el saját magát, kihagyja.
$G = 'Oldalak (HTTP)';

function http_get($url, $cookie = null) {
    if (!function_exists('curl_init')) skip('Nincs curl bővítmény');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    if ($cookie) curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); skip("A szerver nem éri el saját magát ($e)"); }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $head = substr($raw, 0, $hs); $body = substr($raw, $hs);
    preg_match('/^Location:\s*(.+)$/mi', $head, $m);
    return ['code' => $code, 'body' => $body, 'location' => trim($m[1] ?? '')];
}
function base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir . '/';
}
function no_php_errors($body, $where) {
    foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $bad)
        check(stripos($body, $bad) === false, "$where: PHP hibaüzenet az oldalon ($bad)");
}
$ADM = fn() => session_name() . '=' . $GLOBALS['__session_id'];

test($G, 'Hallgatói bejelentkező oldal betölt, nincs rajta oktatói link', function () {
    $r = http_get(base_url() . '../' . CATALOG_DIRNAME . '/');
    same(200, $r['code']);
    no_php_errors($r['body'], 'catalog/');
    check(preg_match('/Jelen vagyok|nincs nyitott jelenléti ív|nincs telepítve/u', $r['body']) === 1, 'nem a várt tartalom');
    check(stripos($r['body'], 'catalogadmin') === false, 'oktatói link a hallgatói oldalon');
}, ['db' => false]);

test($G, 'Régi címek átirányítanak (catalog/login.php, report.php)', function () {
    $r = http_get(base_url() . '../' . CATALOG_DIRNAME . '/login.php');
    same(302, $r['code']); contains('catalogadmin/', $r['location']);
    $r = http_get(base_url() . '../' . CATALOG_DIRNAME . '/submitted.php');
    same(200, $r['code'], 'submitted.php GET');
}, ['db' => false]);

test($G, 'Belépés nélkül az oktatói oldalak a PIN-oldalra visznek, az API 403', function () {
    foreach (['stats.php', 'admin.php', 'migrate.php', 'install.php', 'autotest.php', 'student.php?id=1'] as $p) {
        $r = http_get(base_url() . $p);
        same(302, $r['code'], $p);
        contains('index.php?next=', $r['location'], $p);
    }
    same(403, http_get(base_url() . 'api.php')['code'], 'api.php');
    $r = http_get(base_url());
    same(200, $r['code']); contains('Oktatói belépés', $r['body']);
}, ['db' => false]);

test($G, 'Belépve az oktatói oldalak hibátlanul betöltenek', function () use ($ADM) {
    foreach (['stats.php' => 'id="dash"', 'admin.php' => 'Órák és jelenléti ívek', 'migrate.php' => 'Migráció',
              'install.php' => 'Telepítés', 'autotest.php' => 'Autotest'] as $p => $mark) {
        $r = http_get(base_url() . $p, $ADM());
        same(200, $r['code'], $p);
        no_php_errors($r['body'], $p);
        contains($mark, $r['body'], $p);
    }
    $r = http_get(base_url() . 'api.php', $ADM());
    same(200, $r['code'], 'api.php');
    $j = json_decode($r['body'], true);
    check(is_array($j) && isset($j['courses'], $j['att']), 'api.php nem érvényes JSON');
}, ['db' => false]);

test($G, 'Egy valódi óra és hallgató oldala betölt', function () use ($ADM) {
    $l = qv('SELECT id FROM lecture ORDER BY lecture_date DESC LIMIT 1');
    $s = qv('SELECT id FROM student ORDER BY id LIMIT 1');
    if (!$l || !$s) skip('Még nincs óra vagy hallgató az adatbázisban');
    foreach (["admin.php?lecture=$l" => 'Jelen voltak', "student.php?id=$s" => 'sdetail'] as $p => $mark) {
        $r = http_get(base_url() . $p, $ADM());
        same(200, $r['code'], $p); no_php_errors($r['body'], $p); contains($mark, $r['body'], $p);
    }
}, ['db' => false]);
