<?php
// Oktatói belépés (catalogadmin/index.php)
$G = 'Belépés és biztonság';

test($G, 'Továbbirányítás csak a catalogadmin oldalaira (next paraméter)', function () {
    same('stats.php', safe_next('stats.php'));
    same('admin.php?lecture=3', safe_next('admin.php?lecture=3'));
    same('student.php?id=12', safe_next('student.php?id=12'));
    foreach (['https://rossz.hu/x', '//rossz.hu', '../catalog/index.php', '/etc/passwd', 'javascript:alert(1)',
              'stats.php?x=<script>', "stats.php\r\nLocation: x", '', 'stats'] as $bad)
        same('stats.php', safe_next($bad), 'elutasítandó: ' . $bad);
});

test($G, 'Az autotestet csak belépett oktató futtathatja', function () {
    check(is_admin(), 'a futtató munkamenete nem admin');
});

test($G, 'A hallgatói oldalon nincs link az oktatói felületre', function () {
    $src = file_get_contents(dirname(__DIR__, 2) . '/' . CATALOG_DIRNAME . '/index.php');
    check(stripos($src, 'catalogadmin') === false, 'a catalog/index.php hivatkozik a catalogadmin-ra');
    check(stripos($src, 'Oktatói felület') === false, '„Oktatói felület” link a hallgatói oldalon');
});

test($G, 'Az oktatói oldalak mind belépést követelnek', function () {
    $dir = dirname(__DIR__);
    $public = ['index.php', 'boot.php', 'lib.php'];
    foreach (glob("$dir/*.php") as $f) {
        $b = basename($f);
        if (in_array($b, $public, true)) continue;
        $src = file_get_contents($f);
        check(strpos($src, 'require_admin()') !== false || strpos($src, 'is_admin()') !== false, "$b nem ellenőrzi a belépést");
    }
});

test($G, 'Az admin műveletek CSRF-védettek', function () {
    $dir = dirname(__DIR__);
    foreach (['admin.php', 'student.php', 'migrate.php', 'install.php', 'autotest.php'] as $b) {
        $src = file_get_contents("$dir/$b");
        check(strpos($src, 'check_csrf()') !== false, "$b: hiányzik a check_csrf()");
    }
});
