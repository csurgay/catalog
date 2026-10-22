<?php
// Hallgatói bejelentkezés (catalog/index.php → do_checkin)
$G = 'Hallgatói bejelentkezés';

function at_auto_setup() {
    $c = fx_course(); settings_save($c, 'auto');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    return $c;
}

test($G, 'Automatikus mód: a mai óra megjelenik, az első bejelentkezés létrehozza', function () {
    $c = at_auto_setup();
    $t = targets_of($c);
    same(1, count($t), 'cél a bejelentkező oldalon');
    same('auto', (string)$t[0]['id']);
    $n = tn();
    [$ok, $msg, $x] = do_checkin('auto', 'teszt elek', $n);
    check($ok, $msg);
    same('Rögzítve.', $msg);
    $l = q1('SELECT * FROM lecture WHERE id = ?', [$x['lid']]);
    same($c, (int)$l['course_id'], 'kurzus');
    same(date('Y-m-d'), $l['lecture_date'], 'dátum');
    same(1, (int)$l['is_open'], 'nyitott');
});

test($G, 'A rekord tartalma: idő, forrás, megadott név, IP-cím', function () {
    at_auto_setup(); $n = tn();
    [, , $x] = do_checkin('auto', '  teszt   elek ', strtolower($n));
    $a = q1('SELECT * FROM attendance WHERE lecture_id = ? AND student_id = ?', [$x['lid'], $x['sid']]);
    same('web', $a['source']);
    same('teszt   elek', $a['name_given'], 'nyers név');
    same('203.0.113.7', $a['ip'], 'IP');
    check(abs(strtotime($a['checked_at']) - time()) < 120, 'időpont ≈ most');
    $s = q1('SELECT * FROM student WHERE id = ?', [$x['sid']]);
    same($n, $s['neptun'], 'normalizált Neptun');
    same('Teszt Elek', $s['name'], 'normalizált név');
});

test($G, 'Ismételt bejelentkezés ugyanarra az órára nem duplikál', function () {
    at_auto_setup(); $n = tn();
    [, , $x] = do_checkin('auto', 'Teszt Elek', $n);
    [$ok, $msg, $y] = do_checkin('auto', 'Teszt Elek', " " . strtolower($n));
    check($ok, $msg);
    check($y['again'], 'again jelzés');
    contains('Már szerepelsz', $msg);
    same(1, cnt('SELECT COUNT(*) FROM attendance WHERE lecture_id = ? AND student_id = ?', [$x['lid'], $x['sid']]));
});

test($G, 'Hibás bevitel elutasítva (rövid név, rossz Neptun)', function () {
    at_auto_setup();
    [$ok, $msg] = do_checkin('auto', 'X', tn());
    check(!$ok); contains('teljes neved', $msg);
    [$ok, $msg] = do_checkin('auto', 'Teszt Elek', 'AB12');
    check(!$ok); contains('6 karakter', $msg);
    [$ok] = do_checkin('auto', 'Teszt Elek', '');
    check(!$ok);
});

test($G, 'Lezárt mai órára nem lehet bejelentkezni (automatikus módban sem)', function () {
    $c = at_auto_setup();
    [, , $x] = do_checkin('auto', 'Teszt Elek', tn());
    lecture_toggle($x['lid']);
    same([], targets_of($c), 'nincs cél');
    [$ok, $msg] = do_checkin('auto', 'Más Diák', tn());
    check(!$ok, 'nem szabadna sikerülnie');
    contains('most nem lehet', $msg);
});

test($G, 'Kézi mód: csak felvett és nyitott mai óra', function () {
    $c = fx_course(); settings_save($c, 'manual');
    same([], targets_of($c), 'óra nélkül nincs cél');
    $l = fx_lecture($c, day(0), 0);
    same([], targets_of($c), 'lezárt órára nincs cél');
    lecture_toggle($l);
    $t = targets_of($c);
    same(1, count($t)); same($l, (int)$t[0]['id']);
    [$ok, $msg] = do_checkin((string)$l, 'Teszt Elek', tn());
    check($ok, $msg);
    $tAuto = array_filter(checkin_targets(), fn($t) => (string)$t['id'] === 'auto');
    check(!$tAuto, 'kézi módban nincs automatikus cél');
});

test($G, 'Jövőbeli óra nyitva sem fogad bejelentkezést', function () {
    $c = fx_course(); settings_save($c, 'manual');
    $l = fx_lecture($c, day(7), 1);
    same([], targets_of($c));
    [$ok, $msg] = do_checkin((string)$l, 'Teszt Elek', tn());
    check(!$ok, 'nem szabadna sikerülnie');
});

test($G, 'Több nyitott mai óra (több kurzus) mind választható', function () {
    $c1 = fx_course('A'); $c2 = fx_course('B'); settings_save($c1, 'manual');
    fx_lecture($c1, day(0), 1); fx_lecture($c2, day(0), 1);
    same(1, count(targets_of($c1))); same(1, count(targets_of($c2)));
});

test($G, 'Meglévő hallgató neve nem íródik felül, üres név kitöltődik', function () {
    at_auto_setup();
    $n1 = tn(); fx_student('Eredeti Név', $n1);
    [, , $x] = do_checkin('auto', 'Másik Név', $n1);
    same('Eredeti Név', qv('SELECT name FROM student WHERE id = ?', [$x['sid']]));
    $n2 = tn(); fx_student('', $n2);
    [, , $y] = do_checkin('auto', 'Új Név', $n2);
    same('Új Név', qv('SELECT name FROM student WHERE id = ?', [$y['sid']]));
});

test($G, 'Oktatói kézi felvétel lezárt órára is működik, IP nélkül', function () {
    $c = fx_course(); settings_save($c, 'manual');
    $l = fx_lecture($c, day(-3), 0);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    [$ok, $msg, $x] = do_checkin((string)$l, 'kézi kati', tn(), 'manual');
    check($ok, $msg);
    $a = q1('SELECT * FROM attendance WHERE lecture_id = ? AND student_id = ?', [$l, $x['sid']]);
    same('manual', $a['source']); same('', $a['ip']);
});

test($G, 'Bejelentkezés után a saját statisztika (jelenlét/óraszám) helyes', function () {
    $c = at_auto_setup(); $s = fx_student(); $n = qv('SELECT neptun FROM student WHERE id = ?', [$s]);
    $l1 = fx_lecture($c, day(-14)); $l2 = fx_lecture($c, day(-7)); fx_att($l1, $s);
    do_checkin('auto', 'Teszt Elek', $n);
    same(2, cnt('SELECT COUNT(*) FROM attendance a JOIN lecture l ON l.id = a.lecture_id WHERE a.student_id = ? AND l.course_id = ?', [$s, $c]), 'jelenlét');
    same(3, cnt('SELECT COUNT(*) FROM lecture WHERE course_id = ?', [$c]), 'óraszám');
});
