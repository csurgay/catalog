# Labor katalógus

Jelenléti ív egyetemi órákhoz/laborokhoz (PHP + MySQL, külső függőség nélkül).
Két, egymás mellett lévő mappa:

```
catalog/        hallgatói oldal + közös kód (config, db, séma, stílus)
catalogadmin/   oktatói oldalak (PIN-nel védve), a ../catalog kódját használja
```

| Oldal | Mire jó |
|---|---|
| `catalog/index.php` | Hallgatói bejelentkezés (Név + Neptun). A régi `submitted.php` is ide vezet. |
| `catalogadmin/index.php` | Oktatói belépés (PIN) |
| `catalogadmin/stats.php` | Dashboard + kereshető, szűrhető, rendezhető hallgatólista, CSV export |
| `catalogadmin/student.php` | Egy hallgató idővonala, mutatói; név/Neptun javítás, elírt rekord összevonása |
| `catalogadmin/admin.php` | Kurzusok, órák, jelenléti ívek (nyit/zár, kézi felvétel, IP-címek, törlés) |
| `catalogadmin/migrate.php` | Átvétel a régi `csurgay_tintin.log` táblából (ismételhető) |
| `catalogadmin/install.php` | Táblák létrehozása (`catalog/schema.sql`) |

A hallgatói oldalon nincs link az oktatói részre. Ha a szerveren a `catalog` mappa más nevet kap,
a `catalogadmin/boot.php` egyetlen sorát kell átírni.

## Telepítés

1. Tárhely-kezelőben: új adatbázis `csurgay_catalog` + felhasználó `csurgay_catalog` (minden jog).
2. `catalog/config.php`: `DB_PASS` és `ADMIN_PIN` kitöltése.
3. Mindkét mappa feltöltése egymás mellé, majd `…/catalogadmin/` (PIN) → `install.php` → *Táblák létrehozása*.
4. `…/catalogadmin/migrate.php`: előnézet → teszt-napok kipipálása → *Migrálás indítása*.

## Migráció

A régi `submitted.php` ezt írta: `INSERT INTO log (username, eventname, key1, value1)`, `eventname='neptun'`,
`username` = név, `key1` = Neptun. Az időbélyeg oszlopot (alapértelmezett `CURRENT_TIMESTAMP`) a script
automatikusan felismeri, felülírható. Minden nap egy óra lesz; azonos Neptun egy napon belül egyszer számít;
eltérő névalakokból a leggyakoribb kerül a törzsbe; a nem 6 karakteres kódok kimaradnak (listázza őket).
A feldolgozott sorok az `import_raw` táblába kerülnek, így újrafuttatáskor csak az új sorokat hozza át.

## Bejelentkezési módok (Órák oldal)

* **Automatikus** – bármelyik nap az aktív kurzusra jelentkeznek, az első bejelentkezés létrehozza a napi órát. Lezárt óra után aznap már nem lehet.
* **Kézi** – csak a felvett és nyitott órákra lehet bejelentkezni.

## Mutatók

Arány, aktív arány (első megjelenés óta), utolsó 4 óra / trend, leghosszabb és aktuális sorozat,
egymás utáni hiányzás, érkezés az óra mediánjához képest, visszatérési arány, státusz
(Rendszeres / Ingadozó / Kockázatos / Lemorzsolódott / Új). Részletek a statisztika oldal alján.
