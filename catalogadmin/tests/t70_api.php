<?php
// A statisztika oldal adatforrása (catalogadmin/api.php)
$G = 'Statisztika adatforrás (API)';

test($G, 'Felépítés: kurzusok, órák, hallgatók, jelenlétek, aktív kurzus, mai nap', function () {
    $p = api_payload();
    foreach (['courses', 'lectures', 'students', 'att', 'active', 'today'] as $k) check(array_key_exists($k, $p), "hiányzó kulcs: $k");
    same(date('Y-m-d'), $p['today']);
    check(json_encode($p, JSON_UNESCAPED_UNICODE) !== false, 'JSON-ná alakítható');
});

test($G, 'Rekordok formátuma és a kézi jelölés', function () {
    $c = fx_course(); settings_save($c, 'auto');
    $l = fx_lecture($c, day(-2), 0); $s = fx_student('Api Anna');
    fx_att($l, $s, '10:07:33'); $s2 = fx_student(); att_mark($l, $s2);
    $p = api_payload();
    same($c, $p['active']);
    $lec = array_values(array_filter($p['lectures'], fn($x) => $x['id'] === $l))[0];
    same(['id' => $l, 'c' => $c, 'd' => day(-2), 't' => '', 'o' => 0], $lec);
    $st = array_values(array_filter($p['students'], fn($x) => $x['id'] === $s))[0];
    same('Api Anna', $st['n']);
    $at = array_values(array_filter($p['att'], fn($x) => $x[1] === $l));
    usort($at, fn($a, $b) => $a[0] <=> $b[0]);
    same([$s, $l, '10:07', 0], $at[0], 'web jelenlét');
    same(1, $at[1][3], 'kézi jelölés');
});

test($G, 'Az órák dátum szerint rendezve jönnek', function () {
    $c = fx_course(); fx_lecture($c, day(-1)); fx_lecture($c, day(-9)); fx_lecture($c, day(-5));
    $ds = array_column(array_values(array_filter(api_payload()['lectures'], fn($x) => $x['c'] === $c)), 'd');
    same([day(-9), day(-5), day(-1)], $ds);
});
