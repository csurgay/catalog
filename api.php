<?php
// Nyers adatok a statisztika oldalnak (a származtatott mutatókat a böngésző számolja: assets/stats.js)
require_once __DIR__ . '/checkin_lib.php';
if (!is_admin()) { http_response_code(403); exit('{"error":"auth"}'); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$courses = q('SELECT id, code, name, semester FROM course ORDER BY id')->fetchAll();
$lectures = array_map(fn($r) => ['id' => (int)$r['id'], 'c' => (int)$r['course_id'], 'd' => $r['lecture_date'], 't' => $r['title'], 'o' => (int)$r['is_open']],
    q('SELECT id, course_id, lecture_date, title, is_open FROM lecture ORDER BY lecture_date, id')->fetchAll());
$students = array_map(fn($r) => ['id' => (int)$r['id'], 'k' => $r['neptun'], 'n' => $r['name'], 'm' => $r['note']],
    q('SELECT id, neptun, name, note FROM student')->fetchAll());
$att = array_map(fn($r) => [(int)$r['student_id'], (int)$r['lecture_id'], substr($r['checked_at'], 11, 5), $r['source'] === 'manual' ? 1 : 0],
    q('SELECT student_id, lecture_id, checked_at, source FROM attendance')->fetchAll());

echo json_encode([
    'courses' => $courses, 'lectures' => $lectures, 'students' => $students, 'att' => $att,
    'active' => active_course_id(), 'today' => date('Y-m-d'),
], JSON_UNESCAPED_UNICODE);
