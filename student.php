<?php
require_once __DIR__ . '/db.php';
require_admin();
$id = (int)($_GET['id'] ?? 0);
$s = q1('SELECT * FROM student WHERE id = ?', [$id]);
if (!$s) { flash('Nincs ilyen hallgató.', 'err'); redirect('stats.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    switch ($_POST['a'] ?? '') {
        case 'save':
            $nep = normalize_neptun($_POST['neptun']);
            if (!valid_neptun($nep)) { flash('Érvénytelen Neptun-kód.', 'err'); break; }
            $other = qv('SELECT id FROM student WHERE neptun = ? AND id <> ?', [$nep, $id]);
            if ($other) { flash("Ez a Neptun-kód már egy másik hallgatóé – használd az összevonást.", 'err'); break; }
            q('UPDATE student SET name = ?, neptun = ?, note = ? WHERE id = ?', [normalize_name($_POST['name']), $nep, trim($_POST['note']), $id]);
            flash('Mentve.');
            break;
        case 'merge':
            // a megadott Neptunú (elírt) rekord jelenléteit ide költözteti, majd azt törli
            $src = q1('SELECT * FROM student WHERE neptun = ? AND id <> ?', [normalize_neptun($_POST['other']), $id]);
            if (!$src) { flash('Nincs ilyen Neptun-kódú másik hallgató.', 'err'); break; }
            $db = db(); $db->beginTransaction();
            $moved = 0;
            foreach (q('SELECT id, lecture_id FROM attendance WHERE student_id = ?', [$src['id']])->fetchAll() as $a) {
                if (qv('SELECT id FROM attendance WHERE student_id = ? AND lecture_id = ?', [$id, $a['lecture_id']])) continue;
                q('UPDATE attendance SET student_id = ? WHERE id = ?', [$id, $a['id']]); $moved++;
            }
            q('DELETE FROM student WHERE id = ?', [$src['id']]);
            $db->commit();
            flash("Összevonva: {$src['neptun']} ({$src['name']}) → {$moved} jelenlét átkerült.");
            break;
        case 'delete':
            q('DELETE FROM student WHERE id = ?', [$id]);
            flash('Hallgató és jelenlétei törölve.');
            redirect('stats.php');
    }
    redirect('student.php?id=' . $id);
}
$names = q('SELECT name_given, COUNT(*) AS n FROM attendance WHERE student_id = ? AND name_given <> \'\' GROUP BY name_given ORDER BY n DESC', [$id])->fetchAll();
page_head($s['name'], 'stats');
?>
<p class="crumbs"><a href="stats.php">← Statisztika</a></p>
<div class="page-head">
  <div>
    <h1><?= h($s['name'] ?: '(névtelen)') ?> <span class="mono muted"><?= h($s['neptun']) ?></span></h1>
    <?php if ($s['note']): ?><p class="lead"><?= h($s['note']) ?></p><?php endif; ?>
  </div>
</div>

<div id="sdetail" data-sid="<?= $id ?>"><p class="muted">Betöltés…</p></div>

<div class="grid2">
  <details class="card">
    <summary>Adatok szerkesztése</summary>
    <form method="post" class="stack-form">
      <?= csrf_field() ?><input type="hidden" name="a" value="save">
      <label>Név <input name="name" value="<?= h($s['name']) ?>" required></label>
      <label>Neptun <input name="neptun" value="<?= h($s['neptun']) ?>" class="mono upper" required></label>
      <label>Megjegyzés <input name="note" value="<?= h($s['note']) ?>" placeholder="pl. ismétlő, felmentett…"></label>
      <button class="btn">Mentés</button>
    </form>
    <?php if (count($names) > 1 || ($names && $names[0]['name_given'] !== $s['name'])): ?>
      <p class="muted small">Beíráskor használt névalakok: <?php foreach ($names as $n) echo '„' . h($n['name_given']) . '” (' . (int)$n['n'] . ') '; ?></p>
    <?php endif; ?>
  </details>
  <details class="card">
    <summary>Összevonás / törlés</summary>
    <form method="post" class="stack-form">
      <?= csrf_field() ?><input type="hidden" name="a" value="merge">
      <label>Elírt Neptun-kódú másik rekord beolvasztása ebbe <input name="other" class="mono upper" placeholder="ABC12X" required></label>
      <button class="btn ghost">Összevonás</button>
    </form>
    <form method="post" onsubmit="return confirm('Biztosan törlöd a hallgatót és minden jelenlétét?')" class="top-gap">
      <?= csrf_field() ?><input type="hidden" name="a" value="delete">
      <button class="btn danger">Hallgató törlése</button>
    </form>
  </details>
</div>

<script src="assets/stats.js?v=1"></script>
<script>
(async function () {
  const { computeStats, STATUS, huDate, pct } = window.CatalogStats;
  const box = document.getElementById('sdetail'), sid = +box.dataset.sid;
  const raw = await (await fetch('api.php', { credentials: 'same-origin' })).json();
  const lecCourse = new Map(raw.lectures.map(l => [l.id, l.c]));
  const courseIds = [...new Set(raw.att.filter(a => a[0] === sid).map(a => lecCourse.get(a[1])))];
  if (!courseIds.length) { box.innerHTML = '<p class="muted">Nincs rögzített jelenléte.</p>'; return; }
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  box.innerHTML = courseIds.map(cid => {
    const todayIds = new Set(raw.lectures.filter(l => l.d === raw.today).map(l => l.id));
    const hereToday = raw.att.some(a => a[0] === sid && todayIds.has(a[1]));
    // a ma folyamatban lévő óra csak akkor számít, ha már bejelentkezett rá
    const S = computeStats(raw, { courseId: cid, skipToday: !hereToday }), s = S.students.find(x => x.id === sid);
    const c = raw.courses.find(c => +c.id === cid);
    const rank = [...S.students].sort((a, b) => b.rate - a.rate).findIndex(x => x.id === sid) + 1;
    const st = STATUS[s.status];
    return `<section class="card">
      <div class="card-head"><h2>${esc(c.name)} <span class="muted">${esc(c.semester || '')}</span></h2>
        <span class="st-badge ${st.cls}" title="${st.hint}"><i>${st.icon}</i>${st.label}</span></div>
      <div class="kpis small">
        <div class="kpi"><span>Jelenlét</span><b>${s.attended}<small> / ${S.N}</small></b></div>
        <div class="kpi"><span>Arány</span><b>${pct(s.rate)}</b><small>kurzusátlag ${pct(S.k.avgRate)}</small></div>
        <div class="kpi"><span>Aktív arány</span><b>${pct(s.activeRate)}</b><small>első óta: ${huDate(s.firstDate)}</small></div>
        <div class="kpi"><span>Utolsó 4 óra</span><b>${pct(s.recent)}</b></div>
        <div class="kpi"><span>Leghosszabb sorozat</span><b>${s.longest}</b><small>most: ${s.cur}</small></div>
        <div class="kpi"><span>Helyezés</span><b>${rank}.</b><small>${S.students.length} hallgatóból</small></div>
      </div>
      <ol class="timeline">${S.L.map((l, i) => `<li class="${s.pres[i] ? 'p' : i < s.first ? 'pre' : 'a'}">
          <a href="admin.php?lecture=${l.id}"><b>${huDate(l.d, true)}</b></a>
          <span>${s.pres[i] ? 'jelen · ' + s.times[i] + (s.manual[i] ? ' (kézi)' : '') : i < s.first ? 'még nem járt' : 'hiányzott'}</span>
          <small class="muted">${l.n} fő${l.t ? ' · ' + esc(l.t) : ''}</small></li>`).join('')}</ol>
    </section>`;
  }).join('');
})();
</script>
<?php page_foot();
