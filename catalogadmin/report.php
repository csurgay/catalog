<?php
// Régi riport végpont: ?key1=NEPTUN -> hallgató adatlap, egyébként statisztika
require_once __DIR__ . '/boot.php';
require_admin();
$k = normalize_neptun($_GET['key1'] ?? '');
$id = $k !== '' ? qv('SELECT id FROM student WHERE neptun = ?', [$k]) : null;
redirect($id ? 'student.php?id=' . $id : 'stats.php');
