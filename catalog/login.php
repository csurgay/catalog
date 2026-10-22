<?php
// Régi belépési cím – átirányít a mellette lévő catalogadmin mappába.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../catalogadmin/' . ($qs !== '' ? '?' . $qs : ''));
exit;
