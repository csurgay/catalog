<?php
require_once __DIR__ . '/db.php';
require_admin();
try { qv('SELECT 1 FROM course LIMIT 1'); } catch (Exception $e) { redirect('install.php'); }
page_head('Statisztika', 'stats');
?>
<div class="filterbar card">
  <label>Kurzus <select id="fCourse"></select></label>
  <label>Ettől <input type="date" id="fFrom"></label>
  <label>Eddig <input type="date" id="fTo"></label>
  <button class="btn ghost small" id="fReset" title="Teljes időszak">Teljes időszak</button>
  <label class="inline" id="fTodayWrap" hidden title="A ma még folyamatban lévő óra mindenkinél hiányzásnak számítana"><span><input type="checkbox" id="fToday"> Mai (folyamatban lévő) óra kihagyása</span></label>
</div>

<p id="loading" class="muted">Betöltés…</p>
<div id="empty" class="card" hidden><h2>Ebben a kurzusban/időszakban még nincs óra.</h2><p class="muted">Futtasd a <a href="migrate.php">migrációt</a>, vagy várd meg az első bejelentkezéseket.</p></div>

<div id="dash" hidden>
  <section class="kpis" id="kpis"></section>

  <section class="charts">
    <figure class="card chart wide">
      <figcaption><h2>Létszám óránként</h2>
        <span class="legend"><i class="sw s1"></i>Visszatérő <i class="sw s2"></i>Először jelen <i class="sw ref"></i>Átlag</span></figcaption>
      <div id="chAttendance" class="plot"></div>
    </figure>
    <figure class="card chart side">
      <figcaption><h2>Státusz</h2><span class="muted small">kattints a szűréshez</span></figcaption>
      <div id="chStatus" class="status-list"></div>
    </figure>
    <figure class="card chart">
      <figcaption><h2>Jelenléti arány eloszlása</h2><span class="muted small">hallgatók száma, % sávok</span></figcaption>
      <div id="chRate" class="plot"></div>
    </figure>
    <figure class="card chart">
      <figcaption><h2>Bejelentkezés időpontja</h2><span class="muted small">15 perces sávok</span></figcaption>
      <div id="chTime" class="plot"></div>
    </figure>
  </section>

  <section class="card">
    <div class="table-tools">
      <h2>Hallgatók <span id="count" class="muted small"></span></h2>
      <input type="search" id="q" placeholder="Keresés név, Neptun, megjegyzés…" autocomplete="off">
      <div id="chips" class="chips"></div>
      <label class="range">Min. arány <input type="range" id="minRate" min="0" max="100" step="5" value="0"> <b id="minRateV">0%</b></label>
      <button class="btn ghost small" id="csv">CSV export</button>
    </div>
    <div class="table-scroll"><table class="tbl students" id="tbl"></table></div>
    <details class="legend-help">
      <summary>Mit jelentenek a mutatók?</summary>
      <dl>
        <dt>Arány</dt><dd>jelenlétek / a kiválasztott időszak összes órája.</dd>
        <dt>Aktív arány</dt><dd>jelenlétek / az első megjelenése óta tartott órák – a később csatlakozókat nem bünteti.</dd>
        <dt>Trend</dt><dd>az utolsó (legfeljebb) 4 óra aránya mínusz az aktív arány, százalékpontban. ▲ = javul, ▼ = romlik.</dd>
        <dt>Sorozat</dt><dd>egymást követő órák, amelyeken jelen volt (zárójelben a mostani sorozat).</dd>
        <dt>Hiányzik</dt><dd>hány egymást követő legutóbbi óráról hiányzik.</dd>
        <dt>Érkezés</dt><dd>átlagosan hány perccel jelentkezik be az adott óra mediánja előtt (−) vagy után (+).</dd>
        <dt>Visszatérési arány</dt><dd>az előző óra résztvevőinek hány százaléka jött el a következőre (átlag).</dd>
        <dt>Státusz</dt><dd>Lemorzsolódott: az utolsó 3+ órát kihagyta · Új: az utolsó 2 órán jelent meg először · különben az aktív arány alapján Rendszeres (≥75%), Ingadozó (50–75%), Kockázatos (&lt;50%).</dd>
      </dl>
    </details>
  </section>
</div>
<?php page_foot(['assets/stats.js?v=1']);
