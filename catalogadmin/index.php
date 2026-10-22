<?php
// Oktatói belépés (PIN) – a catalogadmin kezdőoldala.
require_once __DIR__ . '/boot.php';

if (isset($_GET['logout'])) { unset($_SESSION['catalog_admin']); redirect('index.php'); }

$next = safe_next($_GET['next'] ?? $_POST['next'] ?? 'stats.php');

if (is_admin()) redirect($next);

$err = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals(ADMIN_PIN, (string)($_POST['pin'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['catalog_admin'] = true;
        redirect($next);
    }
    $err = true; usleep(600000);
}
page_head('Oktatói belépés', '', 'center-page');
?>
<form method="post" class="card narrow login">
  <div class="logo big"></div>
  <h1>Oktatói belépés</h1>
  <input type="hidden" name="next" value="<?= h($next) ?>">
  <input class="pin" type="password" name="pin" inputmode="numeric" autocomplete="current-password" placeholder="PIN" autofocus>
  <?php if ($err): ?><p class="err-text">Hibás PIN.</p><?php endif; ?>
  <button class="btn block">Belépés</button>
</form>
<?php page_foot();
