<?php
// Neptun-kód és név normalizálás, dátum formázás
$G = 'Normalizálás';

test($G, 'Neptun-kód: kisbetű, szóköz, írásjel → nagybetűs 6 karakter', function () {
    same('ABC123', normalize_neptun(' abc 123 '));
    same('ABC123', normalize_neptun('abc-123'));
    same('XY12ZQ', normalize_neptun("xy12zq\n"));
});

test($G, 'Neptun-kód érvényesség: pontosan 6 betű/szám', function () {
    check(valid_neptun('ABC123'));
    check(!valid_neptun('ABC12'), '5 karakter nem érvényes');
    check(!valid_neptun('ABC1234'), '7 karakter nem érvényes');
    check(!valid_neptun(''), 'üres nem érvényes');
    check(!valid_neptun('ÁBC123'), 'ékezetes nem érvényes');
});

test($G, 'Név: csupa kis/nagybetű → Nagy Kezdőbetűs, vegyes marad', function () {
    same('Kovács Anna', normalize_name('kovács anna'));
    same('Kovács Anna', normalize_name('KOVÁCS ANNA'));
    same('Őri Ödön', normalize_name('ŐRI ÖDÖN'));
    same('McDonald Péter', normalize_name('McDonald Péter'));
    same('Kiss Béla', normalize_name("  Kiss   Béla \t"));
    same('', normalize_name('   '));
});

test($G, 'Magyar dátumformátum', function () {
    same('2026.10.22. csüt', hu_date('2026-10-22'));
    same('2026.10.25. vas', hu_date('2026-10-25'));
    same('2026.10.22.', hu_date('2026-10-22', false));
});

test($G, 'HTML-escape', function () {
    same('&lt;b&gt;&quot;x&quot;&amp;&#039;', h('<b>"x"&\''));
});
