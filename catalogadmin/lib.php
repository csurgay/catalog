<?php
// Az oktatói oldalak üzleti logikája – külön függvényekben, hogy az autotest is hívhassa.
// A függvények hiba esetén UserError-t dobnak, aminek az üzenete mehet a felhasználónak.

class UserError extends Exception {}

/* ------------------------------------------------------------------ belépés */

/** Csak a catalogadmin mappán belüli oldalra enged továbblépni (pl. stats.php, admin.php?lecture=3). */
function safe_next($next) {
    $next = (string)$next;
    return preg_match('~^[a-z_]+\.php(\?[\w=&%.-]*)?$~', $next) ? $next : 'stats.php';
}

/* --------------------------------------------------------------- beállítások */

function settings_save($activeCourseId, $mode) {
    set_setting('active_course_id', (int)$activeCourseId);
    set_setting('checkin_mode', $mode === 'manual' ? 'manual' : 'auto');
}

/* ------------------------------------------------------------------- kurzus */

function course_add($code, $name, $semester) {
    $name = trim((string)$name);
    if ($name === '') throw new UserError('A kurzus neve kötelező.');
    q('INSERT INTO course (code, name, semester) VALUES (?, ?, ?)', [trim((string)$code), $name, trim((string)$semester)]);
    return (int)db()->lastInsertId();
}

function course_edit($id, $code, $name, $semester) {
    $name = trim((string)$name);
    if ($name === '') throw new UserError('A kurzus neve kötelező.');
    q('UPDATE course SET code = ?, name = ?, semester = ? WHERE id = ?', [trim((string)$code), $name, trim((string)$semester), (int)$id]);
}

function course_delete($id, $confirm) {
    if ($confirm !== 'TÖRÖL') throw new UserError('A törléshez írd be: TÖRÖL');
    q('DELETE FROM course WHERE id = ?', [(int)$id]);
}

/* --------------------------------------------------------------------- óra */

function lecture_add($courseId, $date, $title = '') {
    if (!preg_match('/^\d{4}-\d\d-\d\d$/', (string)$date) || !strtotime($date)) throw new UserError('Hibás dátum.');
    if (!qv('SELECT id FROM course WHERE id = ?', [(int)$courseId])) throw new UserError('Nincs ilyen kurzus.');
    if (qv('SELECT id FROM lecture WHERE course_id = ? AND lecture_date = ?', [(int)$courseId, $date]))
        throw new UserError('Erre a napra már van óra ebben a kurzusban.');
    q('INSERT INTO lecture (course_id, lecture_date, title, is_open) VALUES (?, ?, ?, ?)',
      [(int)$courseId, $date, trim((string)$title), $date === date('Y-m-d') ? 1 : 0]);
    return (int)db()->lastInsertId();
}

function lecture_edit($id, $date, $title) {
    if (!preg_match('/^\d{4}-\d\d-\d\d$/', (string)$date) || !strtotime($date)) throw new UserError('Hibás dátum.');
    $cid = qv('SELECT course_id FROM lecture WHERE id = ?', [(int)$id]);
    if (qv('SELECT id FROM lecture WHERE course_id = ? AND lecture_date = ? AND id <> ?', [$cid, $date, (int)$id]))
        throw new UserError('Erre a napra már van óra.');
    q('UPDATE lecture SET title = ?, lecture_date = ? WHERE id = ?', [trim((string)$title), $date, (int)$id]);
}

/** @return int az új állapot (1 = nyitott) */
function lecture_toggle($id) {
    q('UPDATE lecture SET is_open = 1 - is_open WHERE id = ?', [(int)$id]);
    return (int)qv('SELECT is_open FROM lecture WHERE id = ?', [(int)$id]);
}

/** @return int|null a kurzus azonosítója */
function lecture_delete($id) {
    $cid = qv('SELECT course_id FROM lecture WHERE id = ?', [(int)$id]);
    q('DELETE FROM lecture WHERE id = ?', [(int)$id]);
    return $cid === null ? null : (int)$cid;
}

/** Egy óra jelenléti íve: jelenlévők, a kurzus többi (most hiányzó) hallgatója, IP-gyakoriság. */
function lecture_sheet($id) {
    $lec = q1('SELECT l.*, c.name AS course_name FROM lecture l JOIN course c ON c.id = l.course_id WHERE l.id = ?', [(int)$id]);
    if (!$lec) return null;
    $att = q('SELECT a.*, s.name, s.neptun FROM attendance a JOIN student s ON s.id = a.student_id WHERE a.lecture_id = ? ORDER BY a.checked_at, a.id', [$lec['id']])->fetchAll();
    $absent = q('SELECT s.id, s.name, s.neptun, COUNT(*) AS n FROM student s
                 JOIN attendance a ON a.student_id = s.id JOIN lecture l ON l.id = a.lecture_id
                 WHERE l.course_id = ? AND s.id NOT IN (SELECT student_id FROM attendance WHERE lecture_id = ?)
                 GROUP BY s.id, s.name, s.neptun ORDER BY n DESC, s.name', [$lec['course_id'], $lec['id']])->fetchAll();
    return ['lecture' => $lec, 'att' => $att, 'absent' => $absent, 'ipCount' => ip_counts($att)];
}

/** Hány bejelentkezés jött az egyes IP-címekről (üres IP nélkül). */
function ip_counts(array $att) {
    return array_count_values(array_filter(array_map('strval', array_column($att, 'ip')), 'strlen'));
}

/* ---------------------------------------------------------------- jelenlét */

/** Hiányzó hallgató jelenlévőnek jelölése. @return bool új rekord jött-e létre */
function att_mark($lectureId, $studentId) {
    if (qv('SELECT id FROM attendance WHERE lecture_id = ? AND student_id = ?', [(int)$lectureId, (int)$studentId])) return false;
    q('INSERT INTO attendance (lecture_id, student_id, checked_at, source) VALUES (?, ?, ?, ?)', [(int)$lectureId, (int)$studentId, now(), 'manual']);
    return true;
}

function att_delete($attId) {
    q('DELETE FROM attendance WHERE id = ?', [(int)$attId]);
}

/* ---------------------------------------------------------------- hallgató */

function student_save($id, $name, $neptun, $note) {
    $nep = normalize_neptun($neptun);
    if (!valid_neptun($nep)) throw new UserError('Érvénytelen Neptun-kód.');
    if (qv('SELECT id FROM student WHERE neptun = ? AND id <> ?', [$nep, (int)$id]))
        throw new UserError('Ez a Neptun-kód már egy másik hallgatóé – használd az összevonást.');
    q('UPDATE student SET name = ?, neptun = ?, note = ? WHERE id = ?', [normalize_name($name), $nep, trim((string)$note), (int)$id]);
}

/**
 * Egy elírt Neptun-kódú másik rekord jelenléteit átköltözteti ebbe, majd azt törli.
 * Ahol mindkettő jelen volt ugyanazon az órán, ott a meglévő marad.
 * @return array [a beolvasztott rekord, átköltöztetett jelenlétek száma]
 */
function student_merge($id, $otherNeptun) {
    $src = q1('SELECT * FROM student WHERE neptun = ? AND id <> ?', [normalize_neptun($otherNeptun), (int)$id]);
    if (!$src) throw new UserError('Nincs ilyen Neptun-kódú másik hallgató.');
    $moved = tx(function () use ($src, $id) {
        $moved = 0;
        foreach (q('SELECT id, lecture_id FROM attendance WHERE student_id = ?', [$src['id']])->fetchAll() as $a) {
            if (qv('SELECT id FROM attendance WHERE student_id = ? AND lecture_id = ?', [(int)$id, $a['lecture_id']])) continue;
            q('UPDATE attendance SET student_id = ? WHERE id = ?', [(int)$id, $a['id']]);
            $moved++;
        }
        q('DELETE FROM student WHERE id = ?', [$src['id']]);
        return $moved;
    });
    return [$src, $moved];
}

function student_delete($id) {
    q('DELETE FROM student WHERE id = ?', [(int)$id]);
}

/* --------------------------------------------------------------------- API */

/** A statisztika oldal nyers adatai (a mutatókat a böngésző számolja: assets/stats.js). */
function api_payload() {
    return [
        'courses'  => q('SELECT id, code, name, semester FROM course ORDER BY id')->fetchAll(),
        'lectures' => array_map(fn($r) => ['id' => (int)$r['id'], 'c' => (int)$r['course_id'], 'd' => $r['lecture_date'], 't' => $r['title'], 'o' => (int)$r['is_open']],
                       q('SELECT id, course_id, lecture_date, title, is_open FROM lecture ORDER BY lecture_date, id')->fetchAll()),
        'students' => array_map(fn($r) => ['id' => (int)$r['id'], 'k' => $r['neptun'], 'n' => $r['name'], 'm' => $r['note']],
                       q('SELECT id, neptun, name, note FROM student')->fetchAll()),
        'att'      => array_map(fn($r) => [(int)$r['student_id'], (int)$r['lecture_id'], substr($r['checked_at'], 11, 5), $r['source'] === 'manual' ? 1 : 0],
                       q('SELECT student_id, lecture_id, checked_at, source FROM attendance')->fetchAll()),
        'active'   => active_course_id(),
        'today'    => date('Y-m-d'),
    ];
}

/* ---------------------------------------------------------------- migráció */
// Forrás: a régi tintin „log” tábla. A régi submitted.php ezt írta:
//   INSERT INTO log (username, eventname, key1, value1)  — username = név, key1 = Neptun.
// Az időbélyeg-oszlop nevét futáskor derítjük ki.

function src_db() {
    static $p = null;
    if ($p === null) $p = pdo_connect(SRC_DSN, SRC_HOST, SRC_NAME, SRC_USER, SRC_PASS);
    return $p;
}

function src_columns(PDO $p, $table = 'log') {
    $cols = [];
    if ($p->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        foreach ($p->query("PRAGMA table_info(`$table`)") as $r) $cols[$r['name']] = strtolower($r['type']);
    } else {
        foreach ($p->query("SHOW COLUMNS FROM `$table`") as $r) $cols[$r['Field']] = strtolower($r['Type']);
    }
    return $cols;
}

function guess_ts_col(array $cols) {
    foreach ($cols as $c => $t) if (preg_match('/timestamp|datetime/', $t)) return $c;
    foreach ($cols as $c => $t) if (preg_match('/time|date|created|^ts$|when/i', $c)) return $c;
    return null;
}

/** Előnézet: mi jönne át. Nem ír semmit. */
function migrate_analyze(PDO $src, $table = 'log', $event = 'neptun', $tsCol = null) {
    $cols = src_columns($src, $table);
    if (!$cols) throw new UserError("A forrás adatbázisban nincs „{$table}” tábla.");
    foreach (['username', 'eventname', 'key1'] as $need)
        if (!isset($cols[$need])) throw new UserError("A log táblából hiányzik a(z) „{$need}” oszlop. Oszlopok: " . implode(', ', array_keys($cols)));
    if ($tsCol === null || !isset($cols[$tsCol])) $tsCol = guess_ts_col($cols);
    $idCol = isset($cols['id']) ? 'id' : null;

    $eventCounts = $src->query("SELECT eventname, COUNT(*) AS n FROM `$table` GROUP BY eventname ORDER BY n DESC")->fetchAll();
    $rows = [];
    if ($tsCol) {
        $st = $src->prepare('SELECT ' . ($idCol ? "`$idCol` AS _id, " : '') . "`username`, `key1`, `$tsCol` AS _ts FROM `$table` WHERE eventname = ? ORDER BY `$tsCol`" . ($idCol ? ", `$idCol`" : ''));
        $st->execute([$event]);
        $rows = $st->fetchAll();
    }
    $done = [];
    foreach (q('SELECT legacy_key FROM import_raw') as $r) $done[$r['legacy_key']] = true;

    $kp = $table === 'log' ? '' : $table . ':';   // más forrástábla (pl. az autotesté) kulcsai ne ütközzenek
    $items = []; $dates = []; $names = []; $invalid = []; $already = 0;
    foreach ($rows as $r) {
        $ts  = $r['_ts'] ? date('Y-m-d H:i:s', strtotime($r['_ts'])) : null;
        $key = $kp . ($idCol ? 'id:' . $r['_id'] : 'h:' . sha1($r['username'] . '|' . $r['key1'] . '|' . $r['_ts']));
        $it  = ['key' => $key, 'raw_name' => (string)$r['username'], 'raw_key' => (string)$r['key1'], 'ts' => $ts,
                'date' => $ts ? substr($ts, 0, 10) : null, 'neptun' => normalize_neptun($r['key1']), 'name' => normalize_name($r['username'])];
        if (isset($done[$key])) { $already++; continue; }
        if (!valid_neptun($it['neptun']) || !$ts) { $invalid[] = $it; continue; }
        $items[] = $it;
        $dates[$it['date']][$it['neptun']] = true;
        if ($it['name'] !== '') {
            $names[$it['neptun']][$it['name']]['n'] = ($names[$it['neptun']][$it['name']]['n'] ?? 0) + 1;
            $names[$it['neptun']][$it['name']]['last'] = $ts;
        }
    }
    ksort($dates);
    return [
        'cols' => $cols, 'tsCol' => $tsCol, 'idCol' => $idCol, 'event' => $event, 'eventCounts' => $eventCounts,
        'rows' => count($rows), 'items' => $items, 'invalid' => $invalid, 'already' => $already,
        'dates' => $dates, 'names' => $names,
        'conflicts' => array_filter($names, fn($v) => count($v) > 1),
        'distinctNeptun' => count(array_unique(array_column($items, 'neptun'))),
    ];
}

/** A leggyakoribb névalak (döntetlennél a legutóbbi). */
function best_name(array $variants) {
    if (!$variants) return '';
    uasort($variants, fn($a, $b) => [$b['n'], $b['last']] <=> [$a['n'], $a['last']]);
    return (string)array_key_first($variants);
}

/**
 * Átvétel. $courseId = 0 esetén új kurzust hoz létre $newCourse = [code, name, semester] adatokkal.
 * Minden nap egy óra; azonos Neptun egy napon egyszer számít; ismételhető (import_raw).
 */
function migrate_run(array $an, array $selectedDates, $courseId, array $newCourse = []) {
    return tx(function () use ($an, $selectedDates, $courseId, $newCourse) {
        $sel = array_flip($selectedDates);
        $courseId = (int)$courseId;
        if ($courseId === 0) {
            $courseId = course_add($newCourse['code'] ?? '', trim($newCourse['name'] ?? '') ?: 'Labor', $newCourse['semester'] ?? '');
            if (!setting('active_course_id')) set_setting('active_course_id', $courseId);
        }
        $today = date('Y-m-d');
        $lectureIds = []; $studentIds = [];
        $res = ['course_id' => $courseId, 'imported' => 0, 'duplicate' => 0, 'lectures_new' => 0, 'students_new' => 0, 'skipped_dates' => 0];
        foreach ($an['items'] as $it) {
            if (!isset($sel[$it['date']])) { $res['skipped_dates']++; continue; }
            $d = $it['date'];
            if (!isset($lectureIds[$d])) {
                $lid = qv('SELECT id FROM lecture WHERE course_id = ? AND lecture_date = ?', [$courseId, $d]);
                if (!$lid) {
                    q('INSERT INTO lecture (course_id, lecture_date, title, is_open) VALUES (?, ?, ?, ?)', [$courseId, $d, '', $d === $today ? 1 : 0]);
                    $lid = db()->lastInsertId(); $res['lectures_new']++;
                }
                $lectureIds[$d] = (int)$lid;
            }
            $nep = $it['neptun'];
            if (!isset($studentIds[$nep])) {
                $best = best_name($an['names'][$nep] ?? []);
                $s = q1('SELECT id, name FROM student WHERE neptun = ?', [$nep]);
                if (!$s) {
                    q('INSERT INTO student (neptun, name) VALUES (?, ?)', [$nep, $best]);
                    $studentIds[$nep] = (int)db()->lastInsertId(); $res['students_new']++;
                } else {
                    $studentIds[$nep] = (int)$s['id'];
                    if ($s['name'] === '' && $best !== '') q('UPDATE student SET name = ? WHERE id = ?', [$best, $s['id']]);
                }
            }
            $lid = $lectureIds[$d]; $sid = $studentIds[$nep];
            $aid = qv('SELECT id FROM attendance WHERE lecture_id = ? AND student_id = ?', [$lid, $sid]);
            if ($aid) {
                $status = 'duplicate'; $res['duplicate']++;
            } else {
                q('INSERT INTO attendance (lecture_id, student_id, checked_at, source, name_given) VALUES (?, ?, ?, ?, ?)',
                  [$lid, $sid, $it['ts'], 'tintin', mb_substr($it['raw_name'], 0, 200)]);
                $aid = db()->lastInsertId(); $status = 'imported'; $res['imported']++;
            }
            q('INSERT INTO import_raw (legacy_key, username, key1, logged_at, status, attendance_id) VALUES (?, ?, ?, ?, ?, ?)',
              [$it['key'], mb_substr($it['raw_name'], 0, 255), mb_substr($it['raw_key'], 0, 255), $it['ts'], $status, $aid]);
        }
        return $res;
    });
}
