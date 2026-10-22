<?php
// Melyik órára lehet most bejelentkezni?
require_once __DIR__ . '/db.php';

function active_course_id() {
    $id = (int)setting('active_course_id', 0);
    if ($id && qv('SELECT id FROM course WHERE id = ?', [$id])) return $id;
    return (int)qv('SELECT id FROM course ORDER BY id DESC LIMIT 1');
}

/** Ma nyitott órák + (auto módban) az aktív kurzus „virtuális” mai órája, ha még nincs. */
function checkin_targets() {
    $today = date('Y-m-d');
    $mode  = setting('checkin_mode', 'auto');
    $out   = [];
    $rows = q('SELECT l.id, l.course_id, l.lecture_date, l.title, l.is_open, c.name AS course_name, c.code,
                      (SELECT COUNT(*) FROM attendance a WHERE a.lecture_id = l.id) AS n
               FROM lecture l JOIN course c ON c.id = l.course_id
               WHERE l.lecture_date = ? ORDER BY c.name', [$today])->fetchAll();
    $closedActive = false;
    $active = active_course_id();
    foreach ($rows as $r) {
        if ($r['is_open']) $out[] = $r;
        elseif ((int)$r['course_id'] === $active) $closedActive = true;
    }
    if ($mode === 'auto' && $active && !$closedActive) {
        $has = false;
        foreach ($out as $r) if ((int)$r['course_id'] === $active) $has = true;
        if (!$has) {
            $c = q1('SELECT name, code FROM course WHERE id = ?', [$active]);
            $out[] = ['id' => 'auto', 'course_id' => $active, 'lecture_date' => $today, 'title' => '', 'is_open' => 1,
                      'course_name' => $c['name'], 'code' => $c['code'], 'n' => 0];
        }
    }
    return $out;
}

function resolve_lecture_id($target) {
    if ($target === 'auto') {
        $active = active_course_id();
        $today = date('Y-m-d');
        $id = qv('SELECT id FROM lecture WHERE course_id = ? AND lecture_date = ?', [$active, $today]);
        if (!$id) {
            try {
                q('INSERT INTO lecture (course_id, lecture_date, is_open) VALUES (?, ?, 1)', [$active, $today]);
                $id = db()->lastInsertId();
            } catch (PDOException $e) {      // párhuzamos első bejelentkezés
                $id = qv('SELECT id FROM lecture WHERE course_id = ? AND lecture_date = ?', [$active, $today]);
            }
        }
        return (int)$id;
    }
    return (int)$target;
}

/** @return array [ok(bool), message, extra] */
function do_checkin($target, $rawName, $rawNeptun, $source = 'web') {
    $name = normalize_name($rawName);
    $nep  = normalize_neptun($rawNeptun);
    if ($name === '' || mb_strlen($name) < 3) return [false, 'Add meg a teljes neved.'];
    if (!valid_neptun($nep)) return [false, 'A Neptun-kód 6 karakter (betűk és számok).'];

    $allowed = false;
    $active = active_course_id();
    foreach (checkin_targets() as $t)
        if ((string)$t['id'] === (string)$target || ($target === 'auto' && (int)$t['course_id'] === $active)) $allowed = true;
    if ($source === 'web' && !$allowed) return [false, 'Erre az órára most nem lehet bejelentkezni.'];

    $lid = resolve_lecture_id($target);
    if (!$lid) return [false, 'Nincs ilyen óra.'];

    $s = q1('SELECT id, name FROM student WHERE neptun = ?', [$nep]);
    if (!$s) {
        try { q('INSERT INTO student (neptun, name) VALUES (?, ?)', [$nep, $name]); }
        catch (PDOException $e) { /* versenyhelyzet: már létrejött */ }
        $s = q1('SELECT id, name FROM student WHERE neptun = ?', [$nep]);
    } elseif ($s['name'] === '') {
        q('UPDATE student SET name = ? WHERE id = ?', [$name, $s['id']]);
    }
    $sid = (int)$s['id'];

    $prev = q1('SELECT checked_at FROM attendance WHERE lecture_id = ? AND student_id = ?', [$lid, $sid]);
    if ($prev) return [true, 'Már szerepelsz a mai jelenléti íven (' . substr($prev['checked_at'], 11, 5) . ').', ['sid' => $sid, 'lid' => $lid, 'again' => true, 'name' => $name, 'neptun' => $nep]];

    try {
        q('INSERT INTO attendance (lecture_id, student_id, checked_at, source, name_given, ip) VALUES (?, ?, ?, ?, ?, ?)',
          [$lid, $sid, now(), $source, mb_substr(trim($rawName), 0, 200), $source === 'web' ? substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45) : '']);
    } catch (PDOException $e) {
        return [true, 'Már szerepelsz a mai jelenléti íven.', ['sid' => $sid, 'lid' => $lid, 'again' => true, 'name' => $name, 'neptun' => $nep]];
    }
    return [true, 'Rögzítve.', ['sid' => $sid, 'lid' => $lid, 'again' => false, 'name' => $name, 'neptun' => $nep]];
}
