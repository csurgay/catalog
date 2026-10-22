<?php
// Migráció: csurgay_tintin.log (eventname='neptun') -> csurgay_catalog
//
// A régi submitted.php ezt írta:  INSERT INTO log (username, eventname, key1, value1)
//   username = név, eventname = 'neptun', key1 = Neptun-kód, value1 = üres
// Az időpontot az INSERT nem adja meg, tehát a log táblában van egy alapértelmezett
// (CURRENT_TIMESTAMP) időbélyeg-oszlop – ennek nevét a script futáskor deríti ki.
// Ismételten futtatható: a már feldolgozott sorokat az import_raw tábla alapján kihagyja.
require_once __DIR__ . '/db.php';
require_admin();
set_time_limit(300);

function src_db() {
    static $p = null;
    if ($p === null) $p = pdo_connect(SRC_DSN, SRC_HOST, SRC_NAME, SRC_USER, SRC_PASS);
    return $p;
}
function src_columns(PDO $p) {
    $cols = [];
    if ($p->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        foreach ($p->query('PRAGMA table_info(log)') as $r) $cols[$r['name']] = strtolower($r['type']);
    } else {
        foreach ($p->query('SHOW COLUMNS FROM `log`') as $r) $cols[$r['Field']] = strtolower($r['Type']);
    }
    return $cols;
}
function guess_ts_col($cols) {
    foreach ($cols as $c => $t) if (preg_match('/timestamp|datetime/', $t)) return $c;
    foreach ($cols as $c => $t) if (preg_match('/time|date|created|^ts$|when/i', $c)) return $c;
    return null;
}

$error = null; $result = null;
try {
    qv('SELECT COUNT(*) FROM course');
} catch (Exception $e) {
    redirect('install.php');
}

try {
    $src  = src_db();
    $cols = src_columns($src);
    if (!$cols) throw new Exception('A forrás adatbázisban nincs „log” tábla.');
    foreach (['username', 'eventname', 'key1'] as $need)
        if (!isset($cols[$need])) throw new Exception("A log táblából hiányzik a(z) „{$need}” oszlop. Oszlopok: " . implode(', ', array_keys($cols)));

    $tsCol = $_REQUEST['ts'] ?? guess_ts_col($cols);
    if ($tsCol !== null && !isset($cols[$tsCol])) $tsCol = null;
    $idCol = isset($cols['id']) ? 'id' : null;
    $event = $_REQUEST['event'] ?? 'neptun';

    $eventCounts = $src->query('SELECT eventname, COUNT(*) AS n FROM `log` GROUP BY eventname ORDER BY n DESC')->fetchAll();

    $rows = [];
    if ($tsCol) {
        $st = $src->prepare('SELECT ' . ($idCol ? "`$idCol` AS _id, " : '') . "`username`, `key1`, `$tsCol` AS _ts FROM `log` WHERE eventname = ? ORDER BY `$tsCol`" . ($idCol ? ", `$idCol`" : ''));
        $st->execute([$event]);
        $rows = $st->fetchAll();
    }

    // már feldolgozott kulcsok
    $done = [];
    foreach (q('SELECT legacy_key FROM import_raw') as $r) $done[$r['legacy_key']] = true;

    // ---- elemzés ----
    $items = []; $dates = []; $names = []; $invalid = []; $already = 0;
    foreach ($rows as $r) {
        $ts  = $r['_ts'] ? date('Y-m-d H:i:s', strtotime($r['_ts'])) : null;
        $key = $idCol ? 'id:' . $r['_id'] : 'h:' . sha1($r['username'] . '|' . $r['key1'] . '|' . $r['_ts']);
        $nep = normalize_neptun($r['key1']);
        $nam = normalize_name($r['username']);
        $it  = ['key' => $key, 'raw_name' => $r['username'], 'raw_key' => $r['key1'], 'ts' => $ts,
                'date' => $ts ? substr($ts, 0, 10) : null, 'neptun' => $nep, 'name' => $nam];
        if (isset($done[$key])) { $already++; continue; }
        if (!valid_neptun($nep) || !$ts) { $invalid[] = $it; continue; }
        $items[] = $it;
        $dates[$it['date']][$nep] = true;
        if ($nam !== '') {
            $names[$nep][$nam]['n'] = ($names[$nep][$nam]['n'] ?? 0) + 1;
            $names[$nep][$nam]['last'] = $ts;
        }
    }
    ksort($dates);
    $conflicts = array_filter($names, fn($v) => count($v) > 1);
    $distinctNeptun = count(array_unique(array_column($items, 'neptun')));

    // ---- végrehajtás ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
        check_csrf();
        $selDates = array_flip($_POST['dates'] ?? []);
        $courseId = (int)($_POST['course_id'] ?? 0);
        $db = db();
        $db->beginTransaction();
        try {
            if ($courseId === 0) {
                $cn = trim($_POST['new_course'] ?? '') ?: 'Labor';
                q('INSERT INTO course (code, name, semester) VALUES (?, ?, ?)', [trim($_POST['new_code'] ?? ''), $cn, trim($_POST['new_semester'] ?? '')]);
                $courseId = (int)$db->lastInsertId();
                if (!setting('active_course_id')) set_setting('active_course_id', $courseId);
            }
            $today = date('Y-m-d');
            $lectureIds = []; $studentIds = [];
            $res = ['imported' => 0, 'duplicate' => 0, 'lectures_new' => 0, 'students_new' => 0, 'skipped_dates' => 0];

            foreach ($items as $it) {
                if (!isset($selDates[$it['date']])) { $res['skipped_dates']++; continue; }
                $d = $it['date'];
                if (!isset($lectureIds[$d])) {
                    $lid = qv('SELECT id FROM lecture WHERE course_id = ? AND lecture_date = ?', [$courseId, $d]);
                    if (!$lid) {
                        q('INSERT INTO lecture (course_id, lecture_date, title, is_open) VALUES (?, ?, ?, ?)', [$courseId, $d, '', $d === $today ? 1 : 0]);
                        $lid = $db->lastInsertId(); $res['lectures_new']++;
                    }
                    $lectureIds[$d] = (int)$lid;
                }
                $nep = $it['neptun'];
                if (!isset($studentIds[$nep])) {
                    // leggyakoribb névalak (döntetlennél a legutóbbi)
                    $best = '';
                    if (!empty($names[$nep])) {
                        $cand = $names[$nep];
                        uasort($cand, fn($a, $b) => [$b['n'], $b['last']] <=> [$a['n'], $a['last']]);
                        $best = array_key_first($cand);
                    }
                    $s = q1('SELECT id, name FROM student WHERE neptun = ?', [$nep]);
                    if (!$s) {
                        q('INSERT INTO student (neptun, name) VALUES (?, ?)', [$nep, $best]);
                        $studentIds[$nep] = (int)$db->lastInsertId(); $res['students_new']++;
                    } else {
                        $studentIds[$nep] = (int)$s['id'];
                        if ($s['name'] === '' && $best !== '') q('UPDATE student SET name = ? WHERE id = ?', [$best, $s['id']]);
                    }
                }
                $lid = $lectureIds[$d]; $sid = $studentIds[$nep];
                $aid = qv('SELECT id FROM attendance WHERE lecture_id = ? AND student_id = ?', [$lid, $sid]);
                if ($aid) {
                    $status = 'duplicate'; $res['duplicate']++;
                } else {
                    q('INSERT INTO attendance (lecture_id, student_id, checked_at, source, name_given) VALUES (?, ?, ?, ?, ?)',
                      [$lid, $sid, $it['ts'], 'tintin', mb_substr($it['raw_name'], 0, 200)]);
                    $aid = $db->lastInsertId(); $status = 'imported'; $res['imported']++;
                }
                q('INSERT INTO import_raw (legacy_key, username, key1, logged_at, status, attendance_id) VALUES (?, ?, ?, ?, ?, ?)',
                  [$it['key'], mb_substr($it['raw_name'], 0, 255), mb_substr($it['raw_key'], 0, 255), $it['ts'], $status, $aid]);
            }
            $db->commit();
            flash("Kész: {$res['imported']} jelenlét átvéve, {$res['duplicate']} ismétlés összevonva, {$res['students_new']} új hallgató, {$res['lectures_new']} új óra.");
            redirect('migrate.php?event=' . urlencode($event) . '&ts=' . urlencode($tsCol) . '&done=1');
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$courses = [];
try { $courses = q('SELECT * FROM course ORDER BY id')->fetchAll(); } catch (Exception $e) {}
$imported = (int)qv("SELECT COUNT(*) FROM import_raw");

page_head('Migráció', 'migrate');
?>
<h1>Migráció a régi tintin adatbázisból</h1>
<p class="lead">Forrás: <code><?= h(SRC_NAME) ?>.log</code> → cél: <code><?= h(DB_NAME) ?></code>. A futtatás ismételhető: a már átvett sorokat (<?= $imported ?> db) nem hozza át újra.</p>

<?php if ($error): ?>
  <div class="flash flash-err"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!empty($cols)): ?>
<section class="card">
  <h2>1. Forrás tábla szerkezete</h2>
  <form method="get" class="row-form">
    <label>Esemény (eventname)
      <select name="event">
        <?php foreach ($eventCounts as $ec): ?>
          <option value="<?= h($ec['eventname']) ?>" <?= $ec['eventname'] === $event ? 'selected' : '' ?>><?= h($ec['eventname']) ?> (<?= (int)$ec['n'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Időbélyeg oszlop
      <select name="ts">
        <?php foreach ($cols as $c => $t): ?>
          <option value="<?= h($c) ?>" <?= $c === $tsCol ? 'selected' : '' ?>><?= h($c) ?> — <?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn ghost">Újraelemzés</button>
  </form>
  <p class="muted small">Felismert oszlopok: <?php foreach ($cols as $c => $t) echo '<code>' . h($c) . '</code> '; ?>
     <?= $idCol ? '· azonosító: <code>id</code>' : '· nincs id oszlop, a sorokat tartalom-hash azonosítja' ?></p>
</section>

<?php if ($tsCol): ?>
<section class="card">
  <h2>2. Előnézet</h2>
  <div class="kpis small">
    <div class="kpi"><span>Log sorok (<?= h($event) ?>)</span><b><?= count($rows) ?></b></div>
    <div class="kpi"><span>Már átvéve</span><b><?= $already ?></b></div>
    <div class="kpi"><span>Átvehető</span><b><?= count($items) ?></b></div>
    <div class="kpi"><span>Hibás Neptun</span><b class="<?= $invalid ? 'warn-text' : '' ?>"><?= count($invalid) ?></b></div>
    <div class="kpi"><span>Különböző hallgató</span><b><?= $distinctNeptun ?></b></div>
    <div class="kpi"><span>Napok (= órák)</span><b><?= count($dates) ?></b></div>
  </div>

  <?php if ($conflicts): ?>
  <details class="sub">
    <summary>Eltérő névalakok ugyanarra a Neptunra: <?= count($conflicts) ?> — a leggyakoribb alak kerül a törzsbe</summary>
    <table class="tbl compact"><thead><tr><th>Neptun</th><th>Névalakok (db)</th></tr></thead><tbody>
    <?php foreach ($conflicts as $nep => $vs): ?>
      <tr><td class="mono"><?= h($nep) ?></td><td><?php foreach ($vs as $n => $v) echo h($n) . ' <span class="muted">(' . $v['n'] . ')</span>; '; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </details>
  <?php endif; ?>

  <?php if ($invalid): ?>
  <details class="sub">
    <summary>Kihagyandó sorok (nem 6 karakteres Neptun-kód vagy hiányzó idő): <?= count($invalid) ?></summary>
    <table class="tbl compact"><thead><tr><th>Időpont</th><th>Név</th><th>key1 (nyers)</th></tr></thead><tbody>
    <?php foreach ($invalid as $it): ?>
      <tr><td class="mono"><?= h($it['ts']) ?></td><td><?= h($it['raw_name']) ?></td><td class="mono"><?= h($it['raw_key']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="muted small">Ezeket később kézzel is felveheted az óra oldalán.</p>
  </details>
  <?php endif; ?>
</section>

<?php if ($items): ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="event" value="<?= h($event) ?>">
  <input type="hidden" name="ts" value="<?= h($tsCol) ?>">
  <h2>3. Átvétel</h2>
  <p>Minden nap egy óra lesz a választott kurzusban. A teszt-napokat vedd ki a pipából.</p>
  <div class="date-grid">
    <?php foreach ($dates as $d => $set): ?>
      <label class="date-pill"><input type="checkbox" name="dates[]" value="<?= h($d) ?>" checked>
        <span><?= h(hu_date($d)) ?></span><b><?= count($set) ?> fő</b></label>
    <?php endforeach; ?>
  </div>
  <div class="row-form">
    <label>Cél kurzus
      <select name="course_id" id="courseSel">
        <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h(trim($c['code'] . ' ' . $c['name'] . ' ' . $c['semester'])) ?></option><?php endforeach; ?>
        <option value="0" <?= $courses ? '' : 'selected' ?>>+ új kurzus…</option>
      </select>
    </label>
    <label class="newc">Kód <input name="new_code" placeholder="pl. VIHIAC01" size="10"></label>
    <label class="newc">Név <input name="new_course" placeholder="pl. Labor 1" size="18"></label>
    <label class="newc">Félév <input name="new_semester" placeholder="2026/27/1" size="9"></label>
  </div>
  <button class="btn" name="run" value="1">Migrálás indítása (<?= count($items) ?> sor)</button>
</form>
<script>
  const cs = document.getElementById('courseSel');
  const tog = () => document.querySelectorAll('.newc').forEach(e => e.style.display = cs.value === '0' ? '' : 'none');
  cs.addEventListener('change', tog); tog();
</script>
<?php elseif (isset($_GET['done'])): ?>
  <p><a class="btn" href="stats.php">Statisztika megnyitása →</a></p>
<?php endif; ?>
<?php else: ?>
  <div class="flash flash-err">Nem találtam időbélyeg oszlopot – válaszd ki fent kézzel.</div>
<?php endif; ?>
<?php endif; ?>
<?php page_foot();
