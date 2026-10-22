<?php
// Kurzusok, órák, jelenléti ívek kezelése
require_once __DIR__ . '/boot.php';
require_admin();
try { qv('SELECT 1 FROM course LIMIT 1'); } catch (Exception $e) { redirect('install.php'); }

$back = $_SERVER['REQUEST_URI'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['a'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    switch ($a) {
        case 'settings':
            set_setting('active_course_id', (int)$_POST['active_course_id']);
            set_setting('checkin_mode', $_POST['checkin_mode'] === 'manual' ? 'manual' : 'auto');
            flash('Beállítások mentve.');
            break;
        case 'add_course':
            $n = trim($_POST['name'] ?? '');
            if ($n === '') { flash('A kurzus neve kötelező.', 'err'); break; }
            q('INSERT INTO course (code, name, semester) VALUES (?, ?, ?)', [trim($_POST['code']), $n, trim($_POST['semester'])]);
            $back = 'admin.php?course=' . db()->lastInsertId();
            flash('Kurzus létrehozva.');
            break;
        case 'edit_course':
            q('UPDATE course SET code = ?, name = ?, semester = ? WHERE id = ?', [trim($_POST['code']), trim($_POST['name']), trim($_POST['semester']), $id]);
            flash('Kurzus mentve.');
            break;
        case 'delete_course':
            if (($_POST['confirm'] ?? '') !== 'TÖRÖL') { flash('A törléshez írd be: TÖRÖL', 'err'); break; }
            q('DELETE FROM course WHERE id = ?', [$id]);
            $back = 'admin.php';
            flash('Kurzus és óráinak jelenlétei törölve.');
            break;
        case 'add_lecture':
            $d = $_POST['date'] ?? '';
            if (!preg_match('/^\d{4}-\d\d-\d\d$/', $d)) { flash('Hibás dátum.', 'err'); break; }
            try {
                q('INSERT INTO lecture (course_id, lecture_date, title, is_open) VALUES (?, ?, ?, ?)', [(int)$_POST['course_id'], $d, trim($_POST['title'] ?? ''), $d === date('Y-m-d') ? 1 : 0]);
                flash('Óra felvéve.');
            } catch (PDOException $e) { flash('Erre a napra már van óra ebben a kurzusban.', 'err'); }
            break;
        case 'edit_lecture':
            try {
                q('UPDATE lecture SET title = ?, lecture_date = ? WHERE id = ?', [trim($_POST['title'] ?? ''), $_POST['date'], $id]);
                flash('Óra mentve.');
            } catch (PDOException $e) { flash('Erre a napra már van óra.', 'err'); }
            break;
        case 'toggle_lecture':
            q('UPDATE lecture SET is_open = 1 - is_open WHERE id = ?', [$id]);
            break;
        case 'delete_lecture':
            $cid = qv('SELECT course_id FROM lecture WHERE id = ?', [$id]);
            q('DELETE FROM lecture WHERE id = ?', [$id]);
            $back = 'admin.php?course=' . $cid;
            flash('Óra törölve.');
            break;
        case 'add_att':
            $res = do_checkin((string)$id, $_POST['name'] ?? '', $_POST['neptun'] ?? '', 'manual');
            flash($res[0] ? ($res[1] === 'Rögzítve.' ? 'Hozzáadva: ' . $res[2]['name'] : $res[1]) : $res[1], $res[0] ? 'ok' : 'err');
            break;
        case 'mark':
            try { q('INSERT INTO attendance (lecture_id, student_id, checked_at, source) VALUES (?, ?, ?, ?)', [$id, (int)$_POST['sid'], now(), 'manual']); }
            catch (PDOException $e) {}
            break;
        case 'del_att':
            q('DELETE FROM attendance WHERE id = ?', [(int)$_POST['att']]);
            break;
    }
    redirect($back);
}

$courses = q('SELECT c.*, (SELECT COUNT(*) FROM lecture l WHERE l.course_id = c.id) AS nl FROM course c ORDER BY c.id')->fetchAll();
$activeId = active_course_id();
$mode = setting('checkin_mode', 'auto');

// ---------- Egy óra jelenléti íve ----------
if (isset($_GET['lecture'])) {
    $lec = q1('SELECT l.*, c.name AS course_name FROM lecture l JOIN course c ON c.id = l.course_id WHERE l.id = ?', [(int)$_GET['lecture']]);
    if (!$lec) redirect('admin.php');
    $att = q('SELECT a.*, s.name, s.neptun FROM attendance a JOIN student s ON s.id = a.student_id WHERE a.lecture_id = ? ORDER BY a.checked_at', [$lec['id']])->fetchAll();
    $ipCount = array_count_values(array_filter(array_column($att, 'ip')));
    $absent = q('SELECT s.id, s.name, s.neptun, COUNT(*) AS n FROM student s
                 JOIN attendance a ON a.student_id = s.id JOIN lecture l ON l.id = a.lecture_id
                 WHERE l.course_id = ? AND s.id NOT IN (SELECT student_id FROM attendance WHERE lecture_id = ?)
                 GROUP BY s.id, s.name, s.neptun ORDER BY n DESC, s.name', [$lec['course_id'], $lec['id']])->fetchAll();
    page_head('Jelenléti ív ' . $lec['lecture_date'], 'admin');
    ?>
    <p class="crumbs"><a href="admin.php?course=<?= (int)$lec['course_id'] ?>">← <?= h($lec['course_name']) ?></a></p>
    <div class="page-head">
      <div>
        <h1><?= h(hu_date($lec['lecture_date'])) ?> <?= $lec['title'] ? '· ' . h($lec['title']) : '' ?></h1>
        <p class="lead"><?= count($att) ?> jelenlévő · <?= count($absent) ?> hiányzó a kurzus korábbi résztvevői közül</p>
      </div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="toggle_lecture"><input type="hidden" name="id" value="<?= (int)$lec['id'] ?>">
        <button class="pill <?= $lec['is_open'] ? 'pill-open' : 'pill-closed' ?>" title="Kattintásra vált"><?= $lec['is_open'] ? '● Nyitott' : '○ Lezárt' ?></button></form>
    </div>

    <div class="grid2">
      <section class="card">
        <h2>Jelen voltak</h2>
        <table class="tbl">
          <thead><tr><th>Idő</th><th>Név</th><th>Neptun</th><th>IP</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($att as $r): ?>
            <tr>
              <td class="mono"><?= h(substr($r['checked_at'], 11, 5)) ?></td>
              <td><a href="student.php?id=<?= (int)$r['student_id'] ?>"><?= h($r['name']) ?></a>
                <?php if ($r['name_given'] && normalize_name($r['name_given']) !== $r['name']): ?><span class="muted small" title="Beíráskor megadott név">„<?= h($r['name_given']) ?>”</span><?php endif; ?>
                <?php if ($r['source'] === 'manual'): ?><span class="chip">kézi</span><?php endif; ?></td>
              <td class="mono"><?= h($r['neptun']) ?></td>
              <td class="mono small"><?php if ($r['ip'] !== ''): ?><?= h($r['ip']) ?><?php if ($ipCount[$r['ip']] > 1): ?> <span class="chip" title="Ugyanerről az IP-címről ennyi bejelentkezés jött ezen az órán">×<?= (int)$ipCount[$r['ip']] ?></span><?php endif; ?><?php else: ?><span class="muted">–</span><?php endif; ?></td>
              <td class="r"><form method="post" onsubmit="return confirm('Törlöd ezt a jelenlétet?')"><?= csrf_field() ?><input type="hidden" name="a" value="del_att"><input type="hidden" name="att" value="<?= (int)$r['id'] ?>"><button class="icon-btn" title="Törlés">✕</button></form></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$att): ?><tr><td colspan="5" class="muted">Még senki.</td></tr><?php endif; ?>
          </tbody>
        </table>
        <form method="post" class="row-form top-gap">
          <?= csrf_field() ?><input type="hidden" name="a" value="add_att"><input type="hidden" name="id" value="<?= (int)$lec['id'] ?>">
          <label>Név <input name="name" required></label>
          <label>Neptun <input name="neptun" required class="mono upper" size="8"></label>
          <button class="btn ghost">+ Kézi felvétel</button>
        </form>
      </section>

      <section class="card">
        <h2>Hiányzók <span class="muted small">(akik máskor jártak)</span></h2>
        <table class="tbl">
          <thead><tr><th>Név</th><th>Neptun</th><th class="r">Jelenlét</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($absent as $r): ?>
            <tr>
              <td><a href="student.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
              <td class="mono"><?= h($r['neptun']) ?></td>
              <td class="r num"><?= (int)$r['n'] ?></td>
              <td class="r"><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="mark"><input type="hidden" name="id" value="<?= (int)$lec['id'] ?>"><input type="hidden" name="sid" value="<?= (int)$r['id'] ?>"><button class="icon-btn" title="Jelenlévőnek jelöl">✓</button></form></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    </div>

    <details class="card danger-zone">
      <summary>Óra szerkesztése / törlése</summary>
      <form method="post" class="row-form">
        <?= csrf_field() ?><input type="hidden" name="a" value="edit_lecture"><input type="hidden" name="id" value="<?= (int)$lec['id'] ?>">
        <label>Dátum <input type="date" name="date" value="<?= h($lec['lecture_date']) ?>"></label>
        <label>Cím / téma <input name="title" value="<?= h($lec['title']) ?>" size="30"></label>
        <button class="btn ghost">Mentés</button>
      </form>
      <form method="post" onsubmit="return confirm('Biztosan törlöd az órát és minden jelenlétét?')">
        <?= csrf_field() ?><input type="hidden" name="a" value="delete_lecture"><input type="hidden" name="id" value="<?= (int)$lec['id'] ?>">
        <button class="btn danger">Óra törlése</button>
      </form>
    </details>
    <?php
    page_foot();
    exit;
}

// ---------- Kurzus óralista ----------
$cid = (int)($_GET['course'] ?? $activeId);
$course = null;
foreach ($courses as $c) if ((int)$c['id'] === $cid) $course = $c;
if (!$course && $courses) { $course = $courses[0]; $cid = (int)$course['id']; }
$lectures = $course ? q('SELECT l.*, (SELECT COUNT(*) FROM attendance a WHERE a.lecture_id = l.id) AS n FROM lecture l WHERE l.course_id = ? ORDER BY l.lecture_date DESC', [$cid])->fetchAll() : [];
$maxN = max(1, ...array_map(fn($l) => (int)$l['n'], $lectures ?: [['n' => 1]]));

page_head('Órák', 'admin');
?>
<div class="page-head">
  <h1>Órák és jelenléti ívek</h1>
  <form method="post" class="row-form settings-form">
    <?= csrf_field() ?><input type="hidden" name="a" value="settings">
    <label>Bejelentkezés ide
      <select name="active_course_id"><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $activeId ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select>
    </label>
    <label>Mód
      <select name="checkin_mode">
        <option value="auto" <?= $mode === 'auto' ? 'selected' : '' ?>>Automatikus – minden nap nyit</option>
        <option value="manual" <?= $mode === 'manual' ? 'selected' : '' ?>>Kézi – csak felvett, nyitott óra</option>
      </select>
    </label>
    <button class="btn ghost">Mentés</button>
  </form>
</div>

<nav class="tabs">
  <?php foreach ($courses as $c): ?>
    <a href="?course=<?= (int)$c['id'] ?>" class="<?= (int)$c['id'] === $cid ? 'on' : '' ?>"><?= h($c['name']) ?> <span class="muted"><?= (int)$c['nl'] ?></span><?= (int)$c['id'] === $activeId ? ' <span class="dot" title="Aktív bejelentkezés"></span>' : '' ?></a>
  <?php endforeach; ?>
  <a href="#newcourse" class="add" onclick="document.getElementById('newcourse').open = true">+ Új kurzus</a>
</nav>

<?php if ($course): ?>
<section class="card">
  <div class="card-head">
    <h2><?= h(trim($course['code'] . ' ' . $course['name'])) ?> <span class="muted"><?= h($course['semester']) ?></span></h2>
    <form method="post" class="row-form">
      <?= csrf_field() ?><input type="hidden" name="a" value="add_lecture"><input type="hidden" name="course_id" value="<?= $cid ?>">
      <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
      <input name="title" placeholder="Téma (opcionális)">
      <button class="btn ghost">+ Óra</button>
    </form>
  </div>
  <table class="tbl">
    <thead><tr><th>Dátum</th><th>Téma</th><th>Létszám</th><th>Állapot</th></tr></thead>
    <tbody>
    <?php foreach ($lectures as $l): ?>
      <tr class="clickable" onclick="location='admin.php?lecture=<?= (int)$l['id'] ?>'">
        <td class="nowrap"><a href="admin.php?lecture=<?= (int)$l['id'] ?>"><?= h(hu_date($l['lecture_date'])) ?></a></td>
        <td><?= h($l['title']) ?></td>
        <td class="barcell"><span class="hbar" style="width:<?= round(100 * $l['n'] / $maxN) ?>%"></span><b class="num"><?= (int)$l['n'] ?></b></td>
        <td onclick="event.stopPropagation()"><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="toggle_lecture"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button class="pill <?= $l['is_open'] ? 'pill-open' : 'pill-closed' ?>"><?= $l['is_open'] ? '● Nyitott' : '○ Lezárt' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lectures): ?><tr><td colspan="4" class="muted">Még nincs óra. Automatikus módban az első bejelentkezés létrehozza a mait.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<details class="card">
  <summary>Kurzus adatai</summary>
  <form method="post" class="row-form">
    <?= csrf_field() ?><input type="hidden" name="a" value="edit_course"><input type="hidden" name="id" value="<?= $cid ?>">
    <label>Kód <input name="code" value="<?= h($course['code']) ?>" size="10"></label>
    <label>Név <input name="name" value="<?= h($course['name']) ?>" required></label>
    <label>Félév <input name="semester" value="<?= h($course['semester']) ?>" size="10"></label>
    <button class="btn ghost">Mentés</button>
  </form>
  <form method="post" class="row-form top-gap">
    <?= csrf_field() ?><input type="hidden" name="a" value="delete_course"><input type="hidden" name="id" value="<?= $cid ?>">
    <label>Kurzus törlése minden órával együtt – írd be: TÖRÖL <input name="confirm" size="8"></label>
    <button class="btn danger">Törlés</button>
  </form>
</details>
<?php endif; ?>

<details class="card" id="newcourse">
  <summary>Új kurzus</summary>
  <form method="post" class="row-form">
    <?= csrf_field() ?><input type="hidden" name="a" value="add_course">
    <label>Kód <input name="code" size="10"></label>
    <label>Név <input name="name" required></label>
    <label>Félév <input name="semester" size="10" placeholder="2026/27/1"></label>
    <button class="btn">Létrehozás</button>
  </form>
</details>
<?php page_foot();
