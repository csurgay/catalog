<?php
// Hallgató adatlap: szerkesztés, összevonás, törlés (catalogadmin/student.php)
$G = 'Hallgatók';

test($G, 'Adatok mentése normalizálva', function () {
    $s = fx_student('Régi Név'); $n = tn();
    student_save($s, 'új név', strtolower($n), ' ismétlő ');
    same(['Új Név', $n, 'ismétlő'], array_values(q1('SELECT name, neptun, note FROM student WHERE id = ?', [$s])));
});

test($G, 'Érvénytelen vagy már foglalt Neptun-kód nem menthető', function () {
    $s = fx_student(); $o = fx_student(); $on = qv('SELECT neptun FROM student WHERE id = ?', [$o]);
    throws(fn() => student_save($s, 'X Y', 'AB1', ''), 'UserError', 'Érvénytelen');
    throws(fn() => student_save($s, 'X Y', $on, ''), 'UserError', 'másik hallgatóé');
});

test($G, 'Összevonás: jelenlétek átkerülnek, átfedés nem duplázódik, a másik törlődik', function () {
    $c = fx_course(); $l1 = fx_lecture($c, day(-14)); $l2 = fx_lecture($c, day(-7)); $l3 = fx_lecture($c, day(-1));
    $good = fx_student('Jó Kód'); $typo = fx_student('Elírt Kód'); $tn = qv('SELECT neptun FROM student WHERE id = ?', [$typo]);
    fx_att($l1, $good); fx_att($l2, $typo); fx_att($l1, $typo); fx_att($l3, $typo);
    [$src, $moved] = student_merge($good, strtolower($tn));
    same((int)$typo, (int)$src['id']);
    same(2, $moved, 'átköltöztetett');
    same(3, cnt('SELECT COUNT(*) FROM attendance WHERE student_id = ?', [$good]), 'összes jelenlét');
    same(0, cnt('SELECT COUNT(*) FROM student WHERE id = ?', [$typo]), 'a másik rekord törölve');
    same(0, cnt('SELECT COUNT(*) FROM attendance WHERE student_id = ?', [$typo]));
});

test($G, 'Összevonás: nem létező kód és önmaga hibát ad', function () {
    $s = fx_student(); $n = qv('SELECT neptun FROM student WHERE id = ?', [$s]);
    throws(fn() => student_merge($s, tn()), 'UserError', 'Nincs ilyen');
    throws(fn() => student_merge($s, $n), 'UserError', 'Nincs ilyen');
});

test($G, 'Hallgató törlése a jelenléteivel együtt', function () {
    $c = fx_course(); $l = fx_lecture($c, day(-1)); $s = fx_student(); fx_att($l, $s);
    student_delete($s);
    same(0, cnt('SELECT COUNT(*) FROM student WHERE id = ?', [$s]));
    same(0, cnt('SELECT COUNT(*) FROM attendance WHERE student_id = ?', [$s]));
});
