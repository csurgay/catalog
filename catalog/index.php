<?php
require_once __DIR__ . '/checkin_lib.php';

$installed = true;
try { qv('SELECT 1 FROM course LIMIT 1'); } catch (Exception $e) { $installed = false; }

$error = null;
if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // a régi űrlap mezőnevei: nev, neptun
    [$ok, $msg, $x] = array_pad(do_checkin($_POST['lecture'] ?? 'auto', $_POST['nev'] ?? '', $_POST['neptun'] ?? ''), 3, null);
    if ($ok) {
        $_SESSION['checkin_done'] = ['msg' => $msg] + $x;
        redirect('index.php?ok=1');
    }
    $error = $msg;
}

$done = null;
if (isset($_GET['ok']) && !empty($_SESSION['checkin_done'])) {
    $done = $_SESSION['checkin_done'];
    $lec = q1('SELECT l.lecture_date, l.course_id, c.name AS course_name FROM lecture l JOIN course c ON c.id = l.course_id WHERE l.id = ?', [$done['lid']]);
    $done['course'] = $lec['course_name'] ?? '';
    $done['count'] = (int)qv('SELECT COUNT(*) FROM attendance a JOIN lecture l ON l.id = a.lecture_id WHERE a.student_id = ? AND l.course_id = ?', [$done['sid'], $lec['course_id']]);
    $done['total'] = (int)qv('SELECT COUNT(*) FROM lecture WHERE course_id = ?', [$lec['course_id']]);
}

$targets = $installed ? checkin_targets() : [];
page_head('Jelenléti ív', '', 'center-page checkin');
?>
<div class="checkin-card card">
<?php if (!$installed): ?>
  <div class="logo big"></div>
  <h1><?= h(APP_TITLE) ?></h1>
  <p class="muted">Az alkalmazás még nincs telepítve. <a href="../catalogadmin/install.php">Telepítés →</a></p>

<?php elseif ($done): ?>
  <div class="done-mark <?= $done['again'] ? 'again' : '' ?>" aria-hidden="true">
    <svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg>
  </div>
  <h1><?= $done['again'] ? 'Már rögzítve' : 'Köszönöm!' ?></h1>
  <p class="who"><b><?= h($done['name']) ?></b> <span class="mono"><?= h($done['neptun']) ?></span></p>
  <p class="muted"><?= h($done['msg']) ?></p>
  <div class="stat-line">
    <div><b><?= $done['count'] ?></b><span>jelenlét</span></div>
    <div><b><?= $done['total'] ?></b><span>óra eddig</span></div>
    <div><b><?= $done['total'] ? round(100 * $done['count'] / $done['total']) : 0 ?>%</b><span>arány</span></div>
  </div>
  <p class="muted small"><?= h($done['course']) ?> · <?= h(hu_date(date('Y-m-d'))) ?></p>
  <p class="muted small">Az ablakot bezárhatod.</p>

<?php elseif (!$targets): ?>
  <div class="logo big muted-logo"></div>
  <h1>Ma nincs nyitott jelenléti ív</h1>
  <p class="muted">Ha órán vagy, szólj az oktatónak.</p>

<?php else: ?>
  <div class="logo big"></div>
  <p class="eyebrow"><?= h(hu_date(date('Y-m-d'))) ?></p>
  <h1><?= count($targets) === 1 ? h($targets[0]['course_name']) : 'Jelenléti ív' ?></h1>
  <?php if (count($targets) === 1 && $targets[0]['title']): ?><p class="muted"><?= h($targets[0]['title']) ?></p><?php endif; ?>

  <form method="post" id="ci" autocomplete="on">
    <?php if (count($targets) === 1): ?>
      <input type="hidden" name="lecture" value="<?= h($targets[0]['id']) ?>">
    <?php else: ?>
      <fieldset class="seg">
        <?php foreach ($targets as $i => $t): ?>
          <label><input type="radio" name="lecture" value="<?= h($t['id']) ?>" <?= $i === 0 ? 'checked' : '' ?>><span><?= h($t['course_name']) ?></span></label>
        <?php endforeach; ?>
      </fieldset>
    <?php endif; ?>
    <label class="field">
      <span>Név</span>
      <input type="text" name="nev" id="nev" required minlength="3" autocomplete="name" placeholder="Vezetéknév Keresztnév" value="<?= h($_POST['nev'] ?? '') ?>" autofocus>
    </label>
    <label class="field">
      <span>Neptun-kód</span>
      <input type="text" name="neptun" id="neptun" required maxlength="8" autocapitalize="characters" spellcheck="false" class="mono upper" placeholder="ABC123" value="<?= h($_POST['neptun'] ?? '') ?>">
    </label>
    <?php if ($error): ?><p class="err-text"><?= h($error) ?></p><?php endif; ?>
    <label class="remember"><input type="checkbox" id="remember"> Emlékezz rám ezen az eszközön</label>
    <button class="btn block big" type="submit">Jelen vagyok</button>
  </form>
  <?php $n = array_sum(array_column($targets, 'n')); if ($n): ?>
    <p class="muted small live"><span class="dot"></span> Eddig <?= (int)$n ?> fő jelentkezett</p>
  <?php endif; ?>
  <script>
  (function () {
    const K = 'catalog_me', f = document.getElementById('ci'), nev = document.getElementById('nev'),
          nep = document.getElementById('neptun'), rem = document.getElementById('remember');
    try {
      const me = JSON.parse(localStorage.getItem(K) || 'null');
      if (me && !nev.value) { nev.value = me.n; nep.value = me.k; rem.checked = true; }
    } catch (e) {}
    nep.addEventListener('input', () => { const p = nep.selectionStart; nep.value = nep.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); nep.setSelectionRange(p, p); });
    f.addEventListener('submit', () => {
      try { rem.checked ? localStorage.setItem(K, JSON.stringify({ n: nev.value, k: nep.value })) : localStorage.removeItem(K); } catch (e) {}
      f.querySelector('button').disabled = true;
    });
  })();
  </script>
<?php endif; ?>
</div>
<?php page_foot();
