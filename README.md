# Labor katalógus

Jelenléti ív egyetemi órákhoz/laborokhoz (PHP + MySQL, külső függőség nélkül).

| Oldal | Mire jó |
|---|---|
| `index.php` | Hallgatói bejelentkezés (Név + Neptun). A régi `submitted.php` is ide vezet. |
| `stats.php` | Dashboard + kereshető, szűrhető, rendezhető hallgatólista, CSV export |
| `student.php` | Egy hallgató idővonala, mutatói; név/Neptun javítás, elírt rekord összevonása |
| `admin.php` | Kurzusok, órák, jelenléti ívek (nyit/zár, kézi felvétel, törlés) |
| `migrate.php` | Átvétel a régi `csurgay_tintin.log` táblából (ismételhető) |
| `install.php` | Táblák létrehozása (`schema.sql`) |

Az oktatói oldalak a `config.php`-ban megadott PIN-nel nyílnak.

## Telepítés

1. Tárhely-kezelőben: új adatbázis `csurgay_catalog` + felhasználó `csurgay_catalog` (minden jog).
2. `config.php`: `DB_PASS` és `ADMIN_PIN` kitöltése.
3. Feltöltés, majd böngészőben `…/catalog/install.php` → *Táblák létrehozása*.
4. `migrate.php`: előnézet → teszt-napok kipipálása → *Migrálás indítása*.

## Migráció

A régi `submitted.php` ezt írta: `INSERT INTO log (username, eventname, key1, value1)`, `eventname='neptun'`,
`username` = név, `key1` = Neptun. Az időbélyeg oszlopot (alapértelmezett `CURRENT_TIMESTAMP`) a script
automatikusan felismeri, felülírható. Minden nap egy óra lesz; azonos Neptun egy napon belül egyszer számít;
eltérő névalakokból a leggyakoribb kerül a törzsbe; a nem 6 karakteres kódok kimaradnak (listázza őket).
A feldolgozott sorok az `import_raw` táblába kerülnek, így újrafuttatáskor csak az új sorokat hozza át.

## Bejelentkezési módok (Órák oldal)

* **Automatikus** – mint eddig: bármelyik nap az aktív kurzusra jelentkeznek, az első bejelentkezés létrehozza a napi órát. Lezárt óra után aznap már nem lehet.
* **Kézi** – csak a felvett és nyitott órákra lehet bejelentkezni.

## Mutatók

Arány, aktív arány (első megjelenés óta), utolsó 4 óra / trend, leghosszabb és aktuális sorozat,
egymás utáni hiányzás, érkezés az óra mediánjához képest, visszatérési arány, státusz
(Rendszeres / Ingadozó / Kockázatos / Lemorzsolódott / Új). Részletek a statisztika oldal alján.
