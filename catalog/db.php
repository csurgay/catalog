<?php
// Közös segédfüggvények: DB kapcsolat, admin session, layout
require_once __DIR__ . '/config.php';
define('CATALOG_DIRNAME', basename(__DIR__));
date_default_timezone_set(APP_TZ);
ini_set('default_charset', 'UTF-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function pdo_connect($dsn, $host, $name, $user, $pass) {
    $dsn = $dsn ?: "mysql:host=$host;dbname=$name;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $off = (new DateTime())->format('P');          // MySQL NOW() a PHP időzónájában
        $pdo->exec("SET time_zone = '$off'");
    } else {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function db() {
    static $pdo = null;
    if ($pdo === null) $pdo = pdo_connect(DB_DSN, DB_HOST, DB_NAME, DB_USER, DB_PASS);
    return $pdo;
}

function q($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function q1($sql, $params = []) { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function qv($sql, $params = []) { $v = q($sql, $params)->fetchColumn(); return $v === false ? null : $v; }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function now() { return date('Y-m-d H:i:s'); }

function setting($k, $default = null) {
    try { $v = qv('SELECT v FROM setting WHERE k = ?', [$k]); } catch (Exception $e) { return $default; }
    return $v === null ? $default : $v;
}
function set_setting($k, $v) {
    q('DELETE FROM setting WHERE k = ?', [$k]);
    q('INSERT INTO setting (k, v) VALUES (?, ?)', [$k, (string)$v]);
}

// ---- Neptun / név normalizálás ----
function normalize_neptun($s) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s)); }
function valid_neptun($s)     { return (bool)preg_match('/^[A-Z0-9]{6}$/', $s); }
function normalize_name($s) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    // csupa kis/nagybetűs bevitel -> Szó Szerinti Nagybetűsítés
    if ($s !== '' && ($s === mb_strtolower($s, 'UTF-8') || $s === mb_strtoupper($s, 'UTF-8')))
        $s = mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    return $s;
}

// ---- Admin ----
function is_admin() { return !empty($_SESSION['catalog_admin']); }
function require_admin() {
    if (!is_admin()) {
        $next = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'stats.php');
        $qs = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        header('Location: index.php?next=' . urlencode($next . ($qs ? '?' . $qs : '')));
        exit;
    }
}
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function check_csrf() {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) { http_response_code(400); exit('Érvénytelen űrlap (CSRF). Töltsd újra az oldalt.'); }
}
function flash($msg = null, $kind = 'ok') {
    if ($msg !== null) { $_SESSION['flash'][] = [$kind, $msg]; return ''; }
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$k, $m]) $out .= '<div class="flash flash-' . h($k) . '">' . h($m) . '</div>';
    unset($_SESSION['flash']);
    return $out;
}
function redirect($url) { header('Location: ' . $url); exit; }

function hu_date($ymd, $withDay = true) {
    static $days = ['vas', 'hét', 'kedd', 'szer', 'csüt', 'pén', 'szo'];
    $t = strtotime($ymd);
    return date('Y.m.d.', $t) . ($withDay ? ' ' . $days[(int)date('w', $t)] : '');
}

// ---- Layout ----
// A hallgatói app (catalog) mappájának relatív útja az épp futó oldalhoz képest:
// a catalog-ból '', a mellette lévő catalogadmin-ból '../catalog/'.
function app_base() {
    $here = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
    return $here === realpath(__DIR__) ? '' : '../' . basename(__DIR__) . '/';
}

function page_head($title, $active = '', $bodyClass = '') {
    $base = app_base();
    $nav = [
        'stats' => ['stats.php', 'Statisztika'],
        'admin' => ['admin.php', 'Órák'],
        'migrate' => ['migrate.php', 'Migráció'],
    ];
    ?><!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h(APP_TITLE) ?></title>
<link rel="stylesheet" href="<?= h($base) ?>assets/style.css?v=2">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232a78d6'/%3E%3Cpath d='M9 16.5l4.5 4.5L23 11' stroke='white' stroke-width='3.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
</head>
<body class="<?= h($bodyClass) ?>">
<?php if (is_admin() && $base !== ''): /* csak az oktatói (catalogadmin) oldalakon */ ?>
<header class="topbar">
  <a class="brand" href="stats.php"><span class="logo"></span><?= h(APP_TITLE) ?></a>
  <nav>
    <?php foreach ($nav as $k => [$href, $label]): ?>
      <a href="<?= $href ?>" class="<?= $k === $active ? 'on' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
    <a href="<?= h($base) ?>" target="_blank" title="Hallgatói bejelentkező oldal">Check-in ↗</a>
    <a href="index.php?logout=1" class="muted">Kilépés</a>
  </nav>
</header>
<?php endif; ?>
<main class="<?= is_admin() && $base !== '' ? 'wrap' : '' ?>">
<?= flash() ?>
<?php
}
function page_foot($scripts = []) {
    echo "</main>\n";
    foreach ($scripts as $s) echo '<script src="' . h($s) . '"></script>' . "\n";
    echo "</body>\n</html>";
}
