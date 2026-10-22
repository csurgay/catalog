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
    try {
        switch ($a) {
            case 'settings':
                settings_save($_POST['active_course_id'] ?? 0, $_POST['checkin_mode'] ?? 'auto');
                flash('Beállítások mentve.');
                break;
            case 'add_course':
                $back = 'admin.php?course=' . course_add($_POST['code'] ?? '', $_POST['name'] ?? '', $_POST['semester'] ?? '');
                flash('Kurzus létrehozva.');
                break;
            case 'edit_course':
                course_edit($id, $_POST['code'] ?? '', $_POST['name'] ?? '', $_POST['semester'] ?? '');
                flash('Kurzus mentve.');
                break;
            case 'delete_course':
                course_delete($id, $_POST['confirm'] ?? '');
                $back = 'admin.php';
                flash('Kurzus és óráinak jelenlétei törölve.');
                break;
            case 'add_lecture':
                lecture_add((int)($_POST['course_id'] ?? 0), $_POST['date'] ?? '', $_POST['title'] ?? '');
                flash('Óra felvéve.');
                break;
            case 'edit_lecture':
                lecture_edit($id, $_POST['date'] ?? '', $_POST['title'] ?? '');
                flash('Óra mentve.');
                break;
            case 'toggle_lecture':
                lecture_toggle($id);
                break;
            case 'delete_lecture':
                $back = 'admin.php?course=' . lecture_delete($id);
                flash('Óra törölve.');
                break;
            case 'add_att':
                $res = do_checkin((string)$id, $_POST['name'] ?? '', $_POST['neptun'] ?? '', 'manual');
                flash($res[0] ? ($res[1] === 'Rögzítve.' ? 'Hozzáadva: ' . $res[2]['name'] : $res[1]) : $res[1], $res[0] ? 'ok' : 'err');
                break;
            case 'mark':
                att_mark($id, (int)($_POST['sid'] ?? 0));
                break;
            case 'del_att':
                att_delete((int)($_POST['att'] ?? 0));
                break;
        }
    } catch (UserError $e) {
        flash($e->getMessage(), 'err');
    }
    redirect($back);
}

$courses = q('SELECT c.*, (SELECT COUNT(*) FROM lecture l WHERE l.course_id = c.id) AS nl FROM course c ORDER BY c.id')->fetchAll();
$activeId = active_course_id();
$mode = setting('checkin_mode', 'auto');

// ---------- Egy óra jelenléti íve ----------
if (isset($_GET['lecture'])) {
    $sheet = lecture_sheet((int)$_GET['lecture']);
    if (!$sheet) redirect('admin.php');
    ['lecture' => $lec, 'att' => $att, 'absent' => $absent, 'ipCount' => $ipCount] = $sheet;
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
