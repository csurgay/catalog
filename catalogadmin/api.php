<?php
// Nyers adatok a statisztika oldalnak (a származtatott mutatókat a böngésző számolja: assets/stats.js)
require_once __DIR__ . '/boot.php';
if (!is_admin()) { http_response_code(403); exit('{"error":"auth"}'); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(api_payload(), JSON_UNESCAPED_UNICODE);
