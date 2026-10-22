<?php
// Egyszeri telepítés: létrehozza a táblákat a csurgay_catalog adatbázisban (ismételten is futtatható).
require_once __DIR__ . '/boot.php';
require_admin();

$msgs = []; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $sql = file_get_contents(dirname(__DIR__) . '/' . CATALOG_DIRNAME . '/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            db()->exec($stmt);
            if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $stmt, $m)) $msgs[] = "✓ {$m[1]}";
        }
        if (!qv('SELECT COUNT(*) FROM course')) {
            q('INSERT INTO course (code, name, semester) VALUES (?, ?, ?)', ['', 'Labor', '']);
            set_setting('active_course_id', db()->lastInsertId());
            $msgs[] = '✓ alapértelmezett kurzus: „Labor” (átnevezhető az Órák oldalon)';
        }
        if (setting('checkin_mode') === null) set_setting('checkin_mode', 'auto');
    } catch (Exception $e) { $err = $e->getMessage(); }
}
$tables = [];
try { foreach (['course','lecture','student','attendance','import_raw','setting'] as $t) { $tables[$t] = qv("SELECT COUNT(*) FROM $t"); } }
catch (Exception $e) { }

page_head('Telepítés', 'migrate');
?>
<h1>Telepítés</h1>
<div class="card">
  <p>Cél adatbázis: <code><?= h(DB_NAME) ?></code> @ <code><?= h(DB_HOST) ?></code></p>
  <?php if ($err): ?><div class="flash flash-err"><?= h($err) ?></div><?php endif; ?>
  <?php if ($msgs): ?><ul class="plain"><?php foreach ($msgs as $m) echo '<li>' . h($m) . '</li>'; ?></ul><?php endif; ?>
  <?php if (count($tables) === 6): ?>
    <p class="ok-line">Minden tábla megvan:
      <?php foreach ($tables as $t => $n) echo '<span class="chip">' . h($t) . ' · ' . (int)$n . '</span> '; ?></p>
    <p><a class="btn" href="migrate.php">Tovább a migrációhoz →</a></p>
  <?php else: ?>
    <form method="post"><?= csrf_field() ?><button class="btn">Táblák létrehozása</button></form>
  <?php endif; ?>
</div>
<?php page_foot();
