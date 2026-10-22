# catalog repó – munkaszabályok

- Két mappa egymás mellett: `catalog/` (hallgatói oldal + közös kód) és `catalogadmin/` (oktatói oldalak, `boot.php`-n át a `../catalog` kódját használja).
- **Minden új vagy módosított funkcióhoz írj autotestet** (`catalogadmin/tests/tNN_*.php`, böngészős logikához `catalogadmin/assets/autotest.js`), és futtasd le az Autotest oldalon. Részletek: `catalog/README.md` → Autotest.
- Az üzleti logika függvényekben legyen (`catalogadmin/lib.php`, `catalog/checkin_lib.php`), hogy tesztelhető legyen; az oldalak csak hívják.
- Tesztekben ne legyen nem ideiglenes táblán DDL (MySQL implicit commit → a teszt-tranzakció lezárulna).
- A `catalog/config.php` jelszavakat tartalmaz.
