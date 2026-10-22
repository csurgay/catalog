<?php
// Oktatói felület: kurzusok, órák, jelenléti ív (catalogadmin/admin.php)
$G = 'Kurzusok és órák';

test($G, 'Kurzus létrehozása, szerkesztése; üres név hiba', function () {
    $c = course_add(' K1 ', ' Labor A ', ' 2026/27/1 ');
    $r = q1('SELECT * FROM course WHERE id = ?', [$c]);
    same(['K1', 'Labor A', '2026/27/1'], [$r['code'], $r['name'], $r['semester']]);
    course_edit($c, 'K2', 'Labor B', 'x');
    same('Labor B', qv('SELECT name FROM course WHERE id = ?', [$c]));
    throws(fn() => course_add('', '  ', ''), 'UserError', 'kötelező');
    throws(fn() => course_edit($c, '', '', ''), 'UserError', 'kötelező');
});

test($G, 'Kurzus törlése csak „TÖRÖL” megerősítéssel', function () {
    $c = fx_course();
    throws(fn() => course_delete($c, 'torol'), 'UserError', 'TÖRÖL');
    same(1, cnt('SELECT COUNT(*) FROM course WHERE id = ?', [$c]));
    course_delete($c, 'TÖRÖL');
    same(0, cnt('SELECT COUNT(*) FROM course WHERE id = ?', [$c]));
});

test($G, 'Óra felvétele: mai nyitott, más napi lezárt; hibás dátum és duplikátum hiba', function () {
    $c = fx_course();
    $t = lecture_add($c, day(0), 'Bevezetés');
    $f = lecture_add($c, day(7));
    $p = lecture_add($c, day(-7));
    same(1, (int)qv('SELECT is_open FROM lecture WHERE id = ?', [$t]), 'mai');
    same(0, (int)qv('SELECT is_open FROM lecture WHERE id = ?', [$f]), 'jövőbeli');
    same(0, (int)qv('SELECT is_open FROM lecture WHERE id = ?', [$p]), 'múltbeli');
    same('Bevezetés', qv('SELECT title FROM lecture WHERE id = ?', [$t]));
    throws(fn() => lecture_add($c, day(7)), 'UserError', 'már van óra');
    throws(fn() => lecture_add($c, '2026-13-45'), 'UserError', 'Hibás dátum');
    throws(fn() => lecture_add($c, 'holnap'), 'UserError', 'Hibás dátum');
    throws(fn() => lecture_add(-1, day(1)), 'UserError', 'Nincs ilyen kurzus');
});

test($G, 'Óra szerkesztése; ütköző dátumra nem tehető át', function () {
    $c = fx_course(); $a = lecture_add($c, day(-14)); $b = lecture_add($c, day(-7));
    lecture_edit($a, day(-10), 'Új téma');
    same([day(-10), 'Új téma'], array_values(q1('SELECT lecture_date, title FROM lecture WHERE id = ?', [$a])));
    throws(fn() => lecture_edit($a, day(-7), ''), 'UserError', 'már van óra');
    lecture_edit($b, day(-7), 'saját nap marad');
});

test($G, 'Nyitás/zárás váltó', function () {
    $c = fx_course(); $l = fx_lecture($c, day(-1), 0);
    same(1, lecture_toggle($l)); same(0, lecture_toggle($l)); same(1, lecture_toggle($l));
});

test($G, 'Óra törlése a jelenlétekkel együtt, a hallgatók maradnak', function () {
    $c = fx_course(); $l = fx_lecture($c, day(-1)); $s = fx_student(); fx_att($l, $s);
    same($c, lecture_delete($l));
    same(0, cnt('SELECT COUNT(*) FROM lecture WHERE id = ?', [$l]));
    same(0, cnt('SELECT COUNT(*) FROM attendance WHERE lecture_id = ?', [$l]));
    same(1, cnt('SELECT COUNT(*) FROM student WHERE id = ?', [$s]));
});

test($G, 'Beállítások mentése (aktív kurzus, mód)', function () {
    $c = fx_course();
    settings_save($c, 'manual');
    same((string)$c, setting('active_course_id')); same('manual', setting('checkin_mode'));
    same($c, active_course_id());
    settings_save($c, 'bármi más');
    same('auto', setting('checkin_mode'), 'ismeretlen mód → auto');
});

$G = 'Jelenléti ív';

test($G, 'Jelenlévők, hiányzók és darabszámok', function () {
    $c = fx_course(); $l1 = fx_lecture($c, day(-14)); $l2 = fx_lecture($c, day(-7)); $l3 = fx_lecture($c, day(-1));
    $a = fx_student('Aladár'); $b = fx_student('Béla'); $d = fx_student('Dénes');
    fx_att($l1, $a); fx_att($l2, $a); fx_att($l1, $b); fx_att($l2, $b); fx_att($l3, $b); fx_att($l1, $d);
    fx_att($l3, $a, '10:05:00');
    $sh = lecture_sheet($l3);
    same([$b, $a], array_map(fn($r) => (int)$r['student_id'], $sh['att']), 'jelenlévők időrendben');
    same([$d], array_map(fn($r) => (int)$r['id'], $sh['absent']), 'hiányzó');
    same(1, (int)$sh['absent'][0]['n'], 'a hiányzó eddigi jelenléte');
    same(null, lecture_sheet(-1), 'nem létező óra');
});

test($G, 'IP-címek gyakorisága (×N jelzés), üres IP nem számít', function () {
    $c = fx_course(); $l = fx_lecture($c, day(-1));
    fx_att($l, fx_student(), '10:00:00', '198.51.100.1');
    fx_att($l, fx_student(), '10:01:00', '198.51.100.1');
    fx_att($l, fx_student(), '10:02:00', '198.51.100.2');
    fx_att($l, fx_student(), '10:03:00', '', 'manual');
    same(['198.51.100.1' => 2, '198.51.100.2' => 1], lecture_sheet($l)['ipCount']);
});

test($G, 'Hiányzó jelenlévőnek jelölése egyszer; jelenlét törlése', function () {
    $c = fx_course(); $l = fx_lecture($c, day(-1)); $s = fx_student();
    same(true, att_mark($l, $s)); same(false, att_mark($l, $s), 'második jelölés');
    $a = q1('SELECT * FROM attendance WHERE lecture_id = ? AND student_id = ?', [$l, $s]);
    same('manual', $a['source']);
    att_delete($a['id']);
    same(0, cnt('SELECT COUNT(*) FROM attendance WHERE id = ?', [$a['id']]));
});
