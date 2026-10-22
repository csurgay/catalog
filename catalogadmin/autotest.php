<?php
// Autotest: az összes funkció ellenőrzése egy gombnyomásra.
// Az adatbázis-tesztek EGY tranzakcióban futnak, amit a végén visszagörgetünk – az éles adatok nem változnak.
// Minden teszt külön SAVEPOINT-ban fut, így egymástól függetlenek.
// Új teszt: catalogadmin/tests/tNN_*.php (lásd tests/_framework.php).
require_once __DIR__ . '/boot.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
    check_csrf();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    set_time_limit(180);
    echo json_encode(run_autotests(), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** A hiba helye a tesztfájlban (nem a keretrendszerben). */
function test_where(Throwable $e) {
    $at = basename($e->getFile()) . ':' . $e->getLine();
    foreach (array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace()) as $f)
        if (isset($f['file']) && preg_match('~[/\\\\]tests[/\\\\]t\d+[^/\\\\]*\.php$~', $f['file'])) return basename($f['file']) . ':' . $f['line'];
    return $at;
}

function run_autotests() {
    require_once __DIR__ . '/tests/_framework.php';
    $files = glob(__DIR__ . '/tests/t*.php'); sort($files);
    foreach ($files as $f) require $f;

    // a HTTP-tesztek a saját munkamenetünkkel hívják az oldalakat – a session-zárat el kell engedni
    $GLOBALS['__session_id'] = session_id();
    session_write_close();

    $db = db();
    $tables = ['course', 'lecture', 'student', 'attendance', 'import_raw', 'setting'];
    $before = []; $settingsBefore = [];
    try {
        foreach ($tables as $t) $before[$t] = (int)qv("SELECT COUNT(*) FROM $t");
        foreach (q('SELECT k, v FROM setting')->fetchAll() as $r) $settingsBefore[$r['k']] = $r['v'];
    } catch (Throwable $e) { /* a séma-teszt majd jelzi */ }

    $results = [];
    $t0 = microtime(true);
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $broken = null;

    $run = function ($t) use (&$results, $remote) {
        $GLOBALS['__cleanup'] = [];
        set_error_handler(function ($no, $str, $file, $line) {
            if (!(error_reporting() & $no)) return false;
            throw new ErrorException("$str (" . basename($file) . ":$line)", 0, $no, $file, $line);
        });
        $s = microtime(true); $status = 'pass'; $msg = '';
        try { ($t['fn'])(); }
        catch (TestSkipped $e) { $status = 'skip'; $msg = $e->getMessage(); }
        catch (TestWarning $e) { $status = 'warn'; $msg = $e->getMessage(); }
        catch (TestFailed $e)  { $status = 'fail'; $msg = $e->getMessage() . ' (' . test_where($e) . ')'; }
        catch (Throwable $e)   { $status = 'fail'; $msg = get_class($e) . ': ' . $e->getMessage() . ' (' . test_where($e) . ')'; }
        foreach (array_reverse($GLOBALS['__cleanup']) as $c) { try { $c(); } catch (Throwable $e) { } }
        restore_error_handler();
        $_SERVER['REMOTE_ADDR'] = $remote;
        $results[] = ['group' => $t['group'], 'name' => $t['name'], 'status' => $status, 'msg' => $msg, 'ms' => round(1000 * (microtime(true) - $s), 1)];
    };

    // 1) adatbázis-tesztek: egy nagy tranzakcióban, tesztenként savepointtal, a végén visszagörgetve
    $db->beginTransaction();
    try {
        foreach ($GLOBALS['__tests'] as $t) {
            if (!$t['db']) continue;
            if ($broken) { $results[] = ['group' => $t['group'], 'name' => $t['name'], 'status' => 'skip', 'msg' => 'Kihagyva: ' . $broken, 'ms' => 0]; continue; }
            $db->exec('SAVEPOINT autotest');
            $run($t);
            try { $db->exec('ROLLBACK TO SAVEPOINT autotest'); }
            catch (Throwable $e) {
                $broken = 'a teszt-tranzakció megszakadt (' . $e->getMessage() . ')';
                $results[count($results) - 1]['status'] = 'fail';
                $results[count($results) - 1]['msg'] .= ' · ' . $broken;
            }
        }
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }

    // 2) tranzakción kívüli (csak olvasó) tesztek, pl. HTTP
    foreach ($GLOBALS['__tests'] as $t) if (!$t['db']) $run($t);

    // 3) biztonsági ellenőrzés: tényleg nem maradt nyoma a teszteknek
    $s = microtime(true); $status = 'pass'; $msg = '';
    try {
        $left = [];
        if ($n = (int)qv("SELECT COUNT(*) FROM course WHERE SUBSTR(name, 1, 12) = '__AUTOTEST__'")) $left[] = "$n teszt-kurzus";
        if ($n = (int)qv("SELECT COUNT(*) FROM import_raw WHERE legacy_key LIKE 'autotest%'")) $left[] = "$n teszt import-nyom";
        $codes = array_keys($GLOBALS['__tn'] ?? []);
        foreach (array_chunk($codes, 200) as $ch)
            if ($n = (int)qv('SELECT COUNT(*) FROM student WHERE neptun IN (' . implode(',', array_fill(0, count($ch), '?')) . ')', $ch)) $left[] = "$n teszt-hallgató";
        $settingsAfter = [];
        foreach (q('SELECT k, v FROM setting')->fetchAll() as $r) $settingsAfter[$r['k']] = $r['v'];
        if ($settingsAfter != $settingsBefore) $left[] = 'megváltozott beállítások';
        if ($left) { $status = 'fail'; $msg = 'Visszamaradt: ' . implode(', ', $left); }
        else {
            $diff = [];
            foreach ($tables as $t) { $n = (int)qv("SELECT COUNT(*) FROM $t"); if (isset($before[$t]) && $n !== $before[$t]) $diff[] = "$t: {$before[$t]} → $n"; }
            if ($diff) { $status = 'warn'; $msg = 'Sorszám változott futás közben (valaki közben bejelentkezett?): ' . implode(', ', $diff); }
        }
    } catch (Throwable $e) { $status = 'fail'; $msg = $e->getMessage(); }
    $results[] = ['group' => 'Takarítás', 'name' => 'A tesztek nem hagytak nyomot az adatbázisban', 'status' => $status, 'msg' => $msg, 'ms' => round(1000 * (microtime(true) - $s), 1)];

    return [
        'results' => $results,
        'ms' => round(1000 * (microtime(true) - $t0)),
        'env' => ['php' => PHP_VERSION, 'db' => $db->getAttribute(PDO::ATTR_DRIVER_NAME) . ' ' . $db->getAttribute(PDO::ATTR_SERVER_VERSION), 'time' => date('Y-m-d H:i:s')],
    ];
}

page_head('Autotest', 'autotest');
?>
<div class="page-head">
  <div>
    <h1>Autotest</h1>
    <p class="lead">Az app összes funkciójának ellenőrzése. Az adatbázis-tesztek egy tranzakcióban futnak, amit a végén visszagörgetünk – az éles adatok nem változnak.</p>
  </div>
  <button class="btn big" id="runBtn">▶ Összes teszt futtatása</button>
</div>

<section class="card at-summary" id="atSummary" hidden></section>
<div id="atResults"></div>

<form id="atForm" hidden><?= csrf_field() ?><input type="hidden" name="run" value="1"></form>
<script src="assets/stats.js?v=2"></script>
<script src="assets/autotest.js?v=1"></script>
<?php page_foot();
