<?php
// Migráció: csurgay_tintin.log (eventname='neptun') -> csurgay_catalog
//
// A régi submitted.php ezt írta:  INSERT INTO log (username, eventname, key1, value1)
//   username = név, eventname = 'neptun', key1 = Neptun-kód, value1 = üres
// Az időpontot az INSERT nem adja meg, tehát a log táblában van egy alapértelmezett
// (CURRENT_TIMESTAMP) időbélyeg-oszlop – ennek nevét a script futáskor deríti ki.
// Ismételten futtatható: a már feldolgozott sorokat az import_raw tábla alapján kihagyja.
require_once __DIR__ . '/boot.php';
require_admin();
set_time_limit(300);

$error = null;
try {
    qv('SELECT COUNT(*) FROM course');
} catch (Exception $e) {
    redirect('install.php');
}

$event = $_REQUEST['event'] ?? 'neptun';
try {
    $an = migrate_analyze(src_db(), 'log', $event, $_REQUEST['ts'] ?? null);
    ['cols' => $cols, 'tsCol' => $tsCol, 'idCol' => $idCol, 'eventCounts' => $eventCounts, 'already' => $already,
     'items' => $items, 'invalid' => $invalid, 'dates' => $dates, 'conflicts' => $conflicts, 'distinctNeptun' => $distinctNeptun] = $an;
    $rowsN = $an['rows'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
        check_csrf();
        $res = migrate_run($an, $_POST['dates'] ?? [], (int)($_POST['course_id'] ?? 0),
                           ['code' => $_POST['new_code'] ?? '', 'name' => $_POST['new_course'] ?? '', 'semester' => $_POST['new_semester'] ?? '']);
        flash("Kész: {$res['imported']} jelenlét átvéve, {$res['duplicate']} ismétlés összevonva, {$res['students_new']} új hallgató, {$res['lectures_new']} új óra.");
        redirect('migrate.php?event=' . urlencode($event) . '&ts=' . urlencode($tsCol) . '&done=1');
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
    <div class="kpi"><span>Log sorok (<?= h($event) ?>)</span><b><?= $rowsN ?></b></div>
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
