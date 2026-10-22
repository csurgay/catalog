<?php
// Környezet, konfiguráció, adatbázis-séma
$G = 'Környezet és adatbázis';

test($G, 'PHP verzió ≥ 7.4 és a szükséges bővítmények', function () {
    check(version_compare(PHP_VERSION, '7.4', '>='), 'PHP ' . PHP_VERSION . ' túl régi (kell: 7.4+)');
    foreach (['pdo', 'mbstring', 'json'] as $ext) check(extension_loaded($ext), "Hiányzó PHP bővítmény: $ext");
});

test($G, 'Csatlakozás a catalog adatbázishoz', function () {
    same('1', (string)qv('SELECT 1'));
    if (!is_mysql()) warn('Nem MySQL-en fut (' . db()->getAttribute(PDO::ATTR_DRIVER_NAME) . ') – helyi teszt környezet?');
});

test($G, 'Minden tábla és oszlop megvan', function () {
    $need = [
        'course'     => ['id', 'code', 'name', 'semester', 'created_at'],
        'lecture'    => ['id', 'course_id', 'lecture_date', 'title', 'is_open', 'created_at'],
        'student'    => ['id', 'neptun', 'name', 'note', 'created_at'],
        'attendance' => ['id', 'lecture_id', 'student_id', 'checked_at', 'source', 'name_given', 'ip'],
        'import_raw' => ['legacy_key', 'username', 'key1', 'logged_at', 'status', 'attendance_id', 'imported_at'],
        'setting'    => ['k', 'v'],
    ];
    foreach ($need as $t => $cols) {
        $have = array_keys(src_columns(db(), $t));
        check($have, "Hiányzik a(z) $t tábla – futtasd az install.php-t");
        $miss = array_diff($cols, $have);
        check(!$miss, "$t: hiányzó oszlop(ok): " . implode(', ', $miss));
    }
});

test($G, 'Ékezetek és emoji (utf8mb4) épen visszajönnek', function () {
    $name = 'Őrült Űrhajós Árvíztűrő Tükörfúrógép 🎓';
    $sid = fx_student($name);
    same($name, qv('SELECT name FROM student WHERE id = ?', [$sid]));
});

test($G, 'Az adatbázis és a PHP órája egyezik (időzóna)', function () {
    if (!is_mysql()) skip('Csak MySQL-en értelmes');
    $diff = abs(strtotime((string)qv('SELECT NOW()')) - time());
    check($diff < 120, "A MySQL NOW() és a PHP idő eltérése $diff másodperc – rossz időzóna?");
});

test($G, 'Egyedi kulcsok: óra/nap/kurzus és jelenlét/óra/hallgató', function () {
    $c = fx_course(); $l = fx_lecture($c, day(-1)); $s = fx_student();
    fx_att($l, $s);
    throws(fn() => fx_att($l, $s), 'PDOException');
    throws(fn() => fx_lecture($c, day(-1)), 'PDOException');
    throws(fn() => fx_student('Más Név', qv('SELECT neptun FROM student WHERE id = ?', [$s])), 'PDOException');
});

test($G, 'Kaszkád törlés: kurzus → órák → jelenlétek', function () {
    $c = fx_course(); $l = fx_lecture($c, day(-1)); $s = fx_student(); fx_att($l, $s);
    q('DELETE FROM course WHERE id = ?', [$c]);
    same(0, cnt('SELECT COUNT(*) FROM lecture WHERE id = ?', [$l]), 'óra');
    same(0, cnt('SELECT COUNT(*) FROM attendance WHERE lecture_id = ?', [$l]), 'jelenlét');
    same(1, cnt('SELECT COUNT(*) FROM student WHERE id = ?', [$s]), 'a hallgató megmarad');
});

test($G, 'Beállítások: admin PIN nem az alapértelmezett', function () {
    if (ADMIN_PIN === '1234' || ADMIN_PIN === '') warn('Az ADMIN_PIN még az alapértelmezett – cseréld le a config.php-ban');
    check(strlen(ADMIN_PIN) >= 4, 'Túl rövid PIN');
});

test($G, 'A régi tintin adatbázis elérhető (migrációhoz)', function () {
    try { $cols = src_columns(src_db(), 'log'); }
    catch (Throwable $e) { warn('Nem érhető el: ' . $e->getMessage() . ' – a migráció után ez rendben van'); }
    check(isset($cols['username'], $cols['key1'], $cols['eventname']), 'A log tábla szerkezete nem a várt');
});
