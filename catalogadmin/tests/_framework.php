<?php
// Mini tesztkeret az autotest oldalhoz.
// Új teszt: tegyél egy test('Csoport', 'Mit ellenőriz', function () { ... }); hívást
// egy tests/tNN_valami.php fájlba – a futtató minden tests/t*.php fájlt betölt.

class TestFailed extends Exception {}
class TestSkipped extends Exception {}
class TestWarning extends Exception {}

$GLOBALS['__tests'] = [];
$GLOBALS['__cleanup'] = [];

/** Teszt utáni takarítás (pl. ideiglenes tábla eldobása) – akkor is lefut, ha a teszt elbukik. */
function on_cleanup(callable $fn) { $GLOBALS['__cleanup'][] = $fn; }

/** $opts['db'] = false: a teszt a tranzakción kívül fut (pl. HTTP-hívások). */
function test($group, $name, callable $fn, array $opts = []) {
    $GLOBALS['__tests'][] = ['group' => $group, 'name' => $name, 'fn' => $fn, 'db' => $opts['db'] ?? true];
}

function check($cond, $msg = 'A feltétel nem teljesült') { if (!$cond) throw new TestFailed($msg); }
function skip($msg) { throw new TestSkipped($msg); }
function warn($msg) { throw new TestWarning($msg); }

function show($v) {
    if (is_string($v)) return '"' . (mb_strlen($v) > 120 ? mb_substr($v, 0, 120) . '…' : $v) . '"';
    if (is_array($v)) { $j = json_encode($v, JSON_UNESCAPED_UNICODE); return mb_strlen($j) > 160 ? mb_substr($j, 0, 160) . '…' : $j; }
    return var_export($v, true);
}
function same($expected, $actual, $msg = '') {
    if ($expected !== $actual) throw new TestFailed(($msg ? "$msg: " : '') . 'várt ' . show($expected) . ', kapott ' . show($actual));
}
function contains($needle, $haystack, $msg = '') {
    if (mb_strpos((string)$haystack, (string)$needle) === false) throw new TestFailed(($msg ? "$msg: " : '') . 'nem tartalmazza: ' . show($needle));
}
/** A $fn hívásnak $class kivételt kell dobnia (opcionálisan $msgPart-ot tartalmazó üzenettel). */
function throws(callable $fn, $class = 'UserError', $msgPart = null) {
    try { $fn(); }
    catch (Throwable $e) {
        if (!($e instanceof $class)) throw new TestFailed("$class helyett " . get_class($e) . ': ' . $e->getMessage());
        if ($msgPart !== null && mb_strpos($e->getMessage(), $msgPart) === false) throw new TestFailed('Más hibaüzenet: ' . show($e->getMessage()));
        return $e;
    }
    throw new TestFailed("Nem dobott $class kivételt");
}

/* ---------------------------------------------------------------- fixture-ök */

/** Egyedi, valós Neptun-kódokkal nem ütköző teszt-kód (pl. Q7K2ZX) – a DB-ben még nem létező. */
function tn() {
    do {
        $c = 'Q';
        for ($i = 0; $i < 5; $i++) $c .= 'ABCDEFGHJKLMNPRSTUVWXYZ23456789'[random_int(0, 30)];
    } while (qv('SELECT id FROM student WHERE neptun = ?', [$c]));
    $GLOBALS['__tn'][$c] = true;     // a végén ellenőrizzük, hogy egyik sem maradt az adatbázisban
    return $c;
}
function fx_course($name = 'Kurzus') {
    return course_add('AT', '__AUTOTEST__ ' . $name, 'teszt');
}
function fx_student($name = 'Teszt Elek', $neptun = null) {
    q('INSERT INTO student (neptun, name) VALUES (?, ?)', [$neptun ?? tn(), $name]);
    return (int)db()->lastInsertId();
}
function fx_lecture($courseId, $date, $open = 0) {
    q('INSERT INTO lecture (course_id, lecture_date, is_open) VALUES (?, ?, ?)', [$courseId, $date, $open]);
    return (int)db()->lastInsertId();
}
function fx_att($lectureId, $studentId, $time = '10:00:00', $ip = '', $source = 'web') {
    $d = qv('SELECT lecture_date FROM lecture WHERE id = ?', [$lectureId]);
    q('INSERT INTO attendance (lecture_id, student_id, checked_at, source, ip) VALUES (?, ?, ?, ?, ?)', [$lectureId, $studentId, "$d $time", $source, $ip]);
    return (int)db()->lastInsertId();
}
function day($offset) { return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' day')); }
function rows($sql, $p = []) { return q($sql, $p)->fetchAll(); }
function cnt($sql, $p = []) { return (int)qv($sql, $p); }
function is_mysql() { return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
/** Csak a megadott kurzus céljai a bejelentkező oldal listájából. */
function targets_of($courseId) { return array_values(array_filter(checkin_targets(), fn($t) => (int)$t['course_id'] === (int)$courseId)); }

/**
 * Ideiglenes „régi tintin log” tábla a migrációs tesztekhez (a valódi tintin adatbázist nem érinti).
 * $rows: [[username, eventname, key1, 'Y-m-d H:i:s'], ...]  @return string a tábla neve
 */
function fx_srclog(array $rows, $withTs = true) {
    $t = 'autotest_log';
    if (is_mysql()) {
        db()->exec("DROP TEMPORARY TABLE IF EXISTS $t");
        db()->exec("CREATE TEMPORARY TABLE $t (id INT AUTO_INCREMENT PRIMARY KEY, " . ($withTs ? 'logtime TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, ' : '') . "username VARCHAR(255), eventname VARCHAR(64), key1 VARCHAR(255), value1 VARCHAR(255)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci");
        on_cleanup(fn() => db()->exec("DROP TEMPORARY TABLE IF EXISTS $t"));
    } else {
        db()->exec("DROP TABLE IF EXISTS temp.$t");
        db()->exec("CREATE TEMP TABLE $t (id INTEGER PRIMARY KEY AUTOINCREMENT, " . ($withTs ? 'logtime TIMESTAMP, ' : '') . "username VARCHAR(255), eventname VARCHAR(64), key1 VARCHAR(255), value1 VARCHAR(255))");
        on_cleanup(fn() => db()->exec("DROP TABLE IF EXISTS temp.$t"));
    }
    foreach ($rows as $r) {
        if ($withTs) q("INSERT INTO $t (username, eventname, key1, value1, logtime) VALUES (?, ?, ?, '', ?)", [$r[0], $r[1], $r[2], $r[3]]);
        else q("INSERT INTO $t (username, eventname, key1, value1) VALUES (?, ?, ?, '')", [$r[0], $r[1], $r[2]]);
    }
    return $t;
}
