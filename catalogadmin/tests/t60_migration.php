<?php
// Migráció a régi tintin log táblából (catalogadmin/migrate.php) – ideiglenes forrástáblán
$G = 'Migráció (tintin → catalog)';

/** Tipikus régi log: 3 nap, elírások, dupla beküldés, hibás kódok, más esemény. */
function mig_fixture(&$n) {
    $n = [tn(), tn(), tn()];
    $d1 = day(-20); $d2 = day(-13); $d3 = day(-6);
    return fx_srclog([
        ['Kovács Anna',  'neptun', $n[0],                    "$d1 10:01:00"],
        ['Nagy Béla',    'neptun', strtolower($n[1]),        "$d1 10:02:00"],
        ['Nagy Béla',    'neptun', $n[1],                    "$d1 10:02:30"],   // dupla beküldés
        ['teszt',        'neptun', 'XYZ12',                  "$d1 10:03:00"],   // hibás kód
        ['Üres',         'neptun', '',                       "$d1 10:04:00"],
        ['kovács anna',  'neptun', ' ' . $n[0],              "$d2 10:05:00"],
        ['Kovács Anna',  'neptun', $n[0],                    "$d3 10:06:00"],
        ['Nagy Béla',    'neptun', $n[1],                    "$d3 10:07:00"],
        ['Kiss Cecil',   'neptun', $n[2],                    "$d3 10:08:00"],
        ['valaki',       'login',  'x',                      "$d2 09:00:00"],   // más esemény
    ]);
}

test($G, 'Forrás oszlopainak és időbélyeg-oszlopának felismerése', function () {
    $t = fx_srclog([]);
    $cols = src_columns(db(), $t);
    foreach (['id', 'logtime', 'username', 'eventname', 'key1', 'value1'] as $c) check(isset($cols[$c]), "hiányzik: $c");
    same('logtime', guess_ts_col($cols));
    same('created', guess_ts_col(['id' => 'int', 'created' => 'varchar(20)', 'username' => 'varchar(9)']), 'név alapján');
    same(null, guess_ts_col(['id' => 'int', 'username' => 'varchar(9)']), 'nincs');
});

test($G, 'Hiányzó kötelező oszlop érthető hibát ad', function () {
    // ALTER TABLE-t nem használunk: MySQL-en az lezárná (commitolná) a teszt tranzakcióját
    $t = 'autotest_log_bad';
    if (is_mysql()) {
        db()->exec("CREATE TEMPORARY TABLE $t (id INT PRIMARY KEY, logtime TIMESTAMP NULL, username VARCHAR(50), eventname VARCHAR(20)) DEFAULT CHARSET=utf8mb4");
        on_cleanup(fn() => db()->exec("DROP TEMPORARY TABLE IF EXISTS $t"));
    } else {
        db()->exec("CREATE TEMP TABLE $t (id INTEGER PRIMARY KEY, logtime TIMESTAMP, username VARCHAR(50), eventname VARCHAR(20))");
        on_cleanup(fn() => db()->exec("DROP TABLE IF EXISTS temp.$t"));
    }
    throws(fn() => migrate_analyze(db(), $t), 'UserError', 'key1');
    throws(fn() => migrate_analyze(db(), 'autotest_nincs_ilyen_tabla'), 'Throwable');
});

test($G, 'Előnézet: érvényes/hibás sorok, napok, névváltozatok', function () {
    $t = mig_fixture($n);
    $an = migrate_analyze(db(), $t);
    same('logtime', $an['tsCol']);
    same(9, $an['rows'], 'neptun esemény sorai');
    same(7, count($an['items']), 'átvehető');
    same(2, count($an['invalid']), 'hibás');
    same(0, $an['already']);
    same(3, $an['distinctNeptun']);
    same([day(-20), day(-13), day(-6)], array_keys($an['dates']));
    same(2, count($an['dates'][day(-20)]), 'első nap: 2 fő (dupla beküldés egyszer)');
    check(isset($an['conflicts'][$n[0]]) === false, 'kisbetűs név normalizálva ugyanaz → nem ütközés');
    $ec = array_map('intval', array_column($an['eventCounts'], 'n', 'eventname')); ksort($ec);
    same(['login' => 1, 'neptun' => 9], $ec, 'események');
});

test($G, 'Átvétel: órák, hallgatók, jelenlétek, ismétlés összevonva', function () {
    $t = mig_fixture($n); $c = fx_course();
    $an = migrate_analyze(db(), $t);
    $res = migrate_run($an, array_keys($an['dates']), $c);
    same(6, $res['imported'], 'átvett'); same(1, $res['duplicate'], 'összevont');
    same(3, $res['lectures_new']); same(3, $res['students_new']);
    same(3, cnt('SELECT COUNT(*) FROM lecture WHERE course_id = ?', [$c]));
    same(0, cnt('SELECT COUNT(*) FROM lecture WHERE course_id = ? AND is_open = 1', [$c]), 'múltbeli órák lezárva');
    $s = q1('SELECT * FROM student WHERE neptun = ?', [$n[0]]);
    same('Kovács Anna', $s['name']);
    same(3, cnt('SELECT COUNT(*) FROM attendance WHERE student_id = ?', [$s['id']]));
    same('tintin', qv('SELECT DISTINCT source FROM attendance WHERE student_id = ?', [$s['id']]));
    same(day(-20) . ' 10:01:00', qv('SELECT MIN(checked_at) FROM attendance WHERE student_id = ?', [$s['id']]), 'eredeti időpont');
    same(7, cnt("SELECT COUNT(*) FROM import_raw WHERE legacy_key LIKE 'autotest_log:%'"));
});

test($G, 'Újrafuttatás nem hoz át semmit kétszer', function () {
    $t = mig_fixture($n); $c = fx_course();
    $an = migrate_analyze(db(), $t);
    migrate_run($an, array_keys($an['dates']), $c);
    $an2 = migrate_analyze(db(), $t);
    same(7, $an2['already']); same([], $an2['items']);
    $res = migrate_run($an2, array_keys($an['dates']), $c);
    same(0, $res['imported']);
    same(6, cnt('SELECT COUNT(*) FROM attendance a JOIN lecture l ON l.id = a.lecture_id WHERE l.course_id = ?', [$c]));
});

test($G, 'Kihagyott (pl. teszt-) nap nem jön át, később még átvehető', function () {
    $t = mig_fixture($n); $c = fx_course();
    $an = migrate_analyze(db(), $t);
    $res = migrate_run($an, [day(-13), day(-6)], $c);
    same(3, $res['skipped_dates'], 'kihagyott nap sorai (a dupla beküldéssel együtt)');
    same(0, cnt('SELECT COUNT(*) FROM lecture WHERE course_id = ? AND lecture_date = ?', [$c, day(-20)]));
    $an2 = migrate_analyze(db(), $t);
    same([day(-20)], array_keys($an2['dates']));
});

test($G, 'Leggyakoribb névalak kerül a törzsbe', function () {
    $n = tn();
    $t = fx_srclog([
        ['Szabó Dóra', 'neptun', $n, day(-9) . ' 10:00:00'],
        ['Szabó Dóri', 'neptun', $n, day(-8) . ' 10:00:00'],
        ['Szabó Dóra', 'neptun', $n, day(-7) . ' 10:00:00'],
    ]);
    $an = migrate_analyze(db(), $t);
    check(isset($an['conflicts'][$n]), 'ütközés jelezve');
    migrate_run($an, array_keys($an['dates']), fx_course());
    same('Szabó Dóra', qv('SELECT name FROM student WHERE neptun = ?', [$n]));
    same('Szabó Dóra', best_name(['Szabó Dóri' => ['n' => 1, 'last' => '2'], 'Szabó Dóra' => ['n' => 1, 'last' => '3']]), 'döntetlen → legutóbbi');
});

test($G, 'Meglévő hallgató neve marad, üres név kitöltődik', function () {
    $n1 = tn(); $n2 = tn(); fx_student('Meglévő Név', $n1); fx_student('', $n2);
    $t = fx_srclog([['Log Név', 'neptun', $n1, day(-5) . ' 10:00:00'], ['Log Név Kettő', 'neptun', $n2, day(-5) . ' 10:01:00']]);
    $an = migrate_analyze(db(), $t);
    $res = migrate_run($an, array_keys($an['dates']), fx_course());
    same(0, $res['students_new']);
    same('Meglévő Név', qv('SELECT name FROM student WHERE neptun = ?', [$n1]));
    same('Log Név Kettő', qv('SELECT name FROM student WHERE neptun = ?', [$n2]));
});

test($G, 'Új kurzusba is átvehető; mai nap órája nyitott lesz', function () {
    $t = fx_srclog([['Ma Jött', 'neptun', tn(), day(0) . ' 08:00:00']]);
    $an = migrate_analyze(db(), $t);
    $res = migrate_run($an, array_keys($an['dates']), 0, ['code' => 'AT9', 'name' => '__AUTOTEST__ Új', 'semester' => 'x']);
    same('__AUTOTEST__ Új', qv('SELECT name FROM course WHERE id = ?', [$res['course_id']]));
    same(1, (int)qv('SELECT is_open FROM lecture WHERE course_id = ?', [$res['course_id']]));
});

test($G, 'Hiba esetén semmi nem íródik be félig (tranzakció)', function () {
    $t = mig_fixture($n); $c = fx_course();
    $an = migrate_analyze(db(), $t);
    $dup = $an['items'][0];                       // ugyanaz a forráskulcs még egyszer → az import_raw elsődleges kulcsa ütközik
    $dup['neptun'] = tn(); $dup['date'] = day(-6);
    $an['items'][] = $dup;
    $before = cnt('SELECT COUNT(*) FROM attendance');
    throws(fn() => migrate_run($an, array_keys($an['dates']), $c), 'PDOException');
    same($before, cnt('SELECT COUNT(*) FROM attendance'), 'jelenlétek száma változatlan');
    same(0, cnt('SELECT COUNT(*) FROM lecture WHERE course_id = ?', [$c]), 'nincs félkész óra');
    same(0, cnt("SELECT COUNT(*) FROM import_raw WHERE legacy_key LIKE 'autotest_log:%'"), 'nincs félkész import-nyom');
});
