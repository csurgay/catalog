<?php
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';
// ---- Cél adatbázis (új) ----
// Hozd létre a tárhely-kezelőben a csurgay_catalog adatbázist + felhasználót,
// és írd be ide a jelszót.
define('DB_HOST', 'localhost');
define('DB_NAME', 'csurgay_catalog');
define('DB_USER', 'csurgay_catalog');
define('DB_PASS', '');            // <-- TÖLTSD KI

// ---- Forrás adatbázis (régi, csak a migrációhoz kell) ----
define('SRC_HOST', 'localhost');
define('SRC_NAME', 'csurgay_tintin');
define('SRC_USER', 'csurgay_tintin');
define('SRC_PASS', 'Tintin11');

// ---- Admin / statisztika PIN ----
define('ADMIN_PIN', '1234');      // <-- CSERÉLD LE

define('APP_TZ', 'Europe/Budapest');
define('APP_TITLE', 'Labor katalógus');

// Csak helyi teszthez: ha meg van adva, PDO DSN-ként ezt használja (pl. sqlite:/tmp/x.db)
if (!defined('DB_DSN'))  define('DB_DSN', '');
if (!defined('SRC_DSN')) define('SRC_DSN', '');
