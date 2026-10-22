/* Autotest oldal: a szerveroldali (PHP) tesztek futtatása + a böngészőben futó statisztika-tesztek.
 * Új böngészős teszt: jsTest('Csoport', 'Mit ellenőriz', () => { ... }) lent a JS_TESTS részben.
 */
(function () {
'use strict';
const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ------------------------------------------------------------ JS tesztkeret */
const JS = [];
const jsTest = (group, name, fn) => JS.push({ group, name, fn });
class Fail extends Error {}
const check = (c, m = 'A feltétel nem teljesült') => { if (!c) throw new Fail(m); };
const show = v => JSON.stringify(v);
const same = (exp, act, m = '') => {
  const ok = typeof exp === 'object' ? show(exp) === show(act) : exp === act;
  if (!ok) throw new Fail((m ? m + ': ' : '') + `várt ${show(exp)}, kapott ${show(act)}`);
};
const near = (exp, act, m = '', eps = 1e-9) => { if (Math.abs(exp - act) > eps) throw new Fail((m ? m + ': ' : '') + `várt ${exp}, kapott ${act}`); };

function runJsTests() {
  return JS.map(t => {
    const s = performance.now();
    let status = 'pass', msg = '';
    try { t.fn(); } catch (e) { status = 'fail'; msg = (e instanceof Fail ? '' : e.name + ': ') + e.message; }
    return { group: t.group, name: t.name, status, msg, ms: +(performance.now() - s).toFixed(1) };
  });
}

/* ---------------------------------------------------------------- JS_TESTS */
// Fixture: 5 óra az 1. kurzusban, 1 óra a 2.-ban; öt jellegzetes hallgató.
//   A: mind az 5 · B: az első 2, aztán eltűnt · C: a 4. órától jár · D: 1., 3., 5. · E: 1. és 5. (kézi)
const L = ['2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30'];
const RAW = () => ({
  courses: [{ id: 1, name: 'K1' }, { id: 2, name: 'K2' }],
  lectures: [...L.map((d, i) => ({ id: i + 1, c: 1, d, t: '', o: 0 })), { id: 6, c: 2, d: '2026-03-09', t: '', o: 0 }],
  students: ['A', 'B', 'C', 'D', 'E', 'F'].map((n, i) => ({ id: i + 1, k: 'QTEST' + n, n: n + ' Hallgató', m: '' })),
  att: [
    [1, 1, '10:00', 0], [1, 2, '10:00', 0], [1, 3, '10:00', 0], [1, 4, '10:00', 0], [1, 5, '10:00', 0],
    [2, 1, '10:10', 0], [2, 2, '10:10', 0],
    [3, 4, '10:20', 0], [3, 5, '10:20', 0],
    [4, 1, '10:20', 0], [4, 3, '10:20', 0], [4, 5, '10:20', 0],
    [5, 1, '10:30', 0], [5, 5, '10:30', 1],
    [6, 6, '09:00', 0],
  ],
  active: 1, today: '2026-04-15',
});
const CS = () => window.CatalogStats;
const stat = (opts = {}) => CS().computeStats(Object.assign(RAW(), opts.raw || {}), Object.assign({ courseId: 1 }, opts));
const by = (S, letter) => S.students.find(s => s.neptun === 'QTEST' + letter);

const G1 = 'Statisztika számítások (böngésző)';
jsTest(G1, 'A stats.js betöltődött', () => check(CS() && typeof CS().computeStats === 'function', 'window.CatalogStats hiányzik'));

jsTest(G1, 'Óraszám, hallgatószám, létszámok óránként', () => {
  const S = stat();
  same(5, S.N, 'órák'); same(5, S.k.students, 'hallgatók (a másik kurzusé nélkül)');
  same([4, 2, 2, 2, 4], S.L.map(l => l.n), 'létszámok');
  same([4, 0, 0, 1, 0], S.L.map(l => l.fresh), 'először megjelenők');
  near(2.8, S.k.avgN, 'átlagos létszám'); same(4, S.k.peak, 'csúcs');
  same(4, S.k.lastN); same(2, S.k.prevN);
});

jsTest(G1, 'Jelenléti arány, aktív arány, törzs (≥75%)', () => {
  const S = stat();
  near(1, by(S, 'A').rate); near(0.4, by(S, 'B').rate); near(0.4, by(S, 'C').rate); near(0.6, by(S, 'D').rate);
  near(1, by(S, 'C').activeRate, 'C a 4. órától jár → aktív arány 100%');
  near(0.4, by(S, 'B').activeRate);
  near(0.56, S.k.avgRate, 'átlagos arány');
  same(1, S.k.core, 'törzs');
});

jsTest(G1, 'Sorozatok, hiányzás, utolsó jelenlét', () => {
  const S = stat(), A = by(S, 'A'), B = by(S, 'B'), D = by(S, 'D');
  same([5, 5, 0], [A.longest, A.cur, A.absent], 'A');
  same([2, 0, 3], [B.longest, B.cur, B.absent], 'B');
  same([1, 1, 0], [D.longest, D.cur, D.absent], 'D');
  same('2026-03-09', B.lastDate); same('2026-03-02', B.firstDate);
});

jsTest(G1, 'Trend: utolsó 4 óra a saját átlaghoz képest', () => {
  const S = stat();
  near(0, by(S, 'A').trend, 'A');
  near(-0.1, by(S, 'D').trend, 'D: 2/4 − 3/5');
});

jsTest(G1, 'Státuszok: rendszeres, ingadozó, kockázatos, lemorzsolódott, új', () => {
  const S = stat();
  same({ A: 'regular', B: 'dropped', C: 'fresh', D: 'shaky', E: 'risk' },
    Object.fromEntries(['A', 'B', 'C', 'D', 'E'].map(x => [x, by(S, x).status])));
  same({ regular: 1, shaky: 1, risk: 1, dropped: 1, fresh: 1 }, S.statusCounts);
  same(2, S.k.atRisk, 'figyelendő');
});

jsTest(G1, 'Visszatérési arány óráról órára', () => {
  const S = stat();
  same([null, 0.5, 0.5, 0.5, 1], S.L.map(l => l.retention));
  near(0.625, S.k.avgRetention);
});

jsTest(G1, 'Érkezés az óra mediánjához képest', () => {
  const S = stat();
  same(615, S.L[0].median, '1. óra mediánja (10:15)');
  near(-12, by(S, 'A').avgOff, 'A átlagosan 12 perccel korábban');
  check(by(S, 'E').avgOff > 0, 'E később érkezik');
});

jsTest(G1, 'Kézi jelölés megkülönböztetve', () => {
  const S = stat();
  same([0, 0, 0, 0, 1], by(S, 'E').manual);
});

jsTest(G1, 'Időszak szűrés (ettől–eddig)', () => {
  const S = stat({ from: '2026-03-16' });
  same(3, S.N); same(4, S.k.students, 'B kiesik');
  check(!by(S, 'B'), 'B nem szerepel');
  same(2, stat({ from: '2026-03-09', to: '2026-03-16' }).N);
});

jsTest(G1, 'A mai, folyamatban lévő óra kihagyható', () => {
  const S = stat({ raw: { today: '2026-03-30' }, skipToday: true });
  same(4, S.N);
  same('fresh', by(S, 'C').status);
  same(5, stat({ raw: { today: '2026-03-30' }, skipToday: false }).N);
});

jsTest(G1, 'Kurzusok elkülönülnek', () => {
  const S = stat({ courseId: 2 });
  same(1, S.N); same(['QTESTF'], S.students.map(s => s.neptun));
});

jsTest(G1, 'Üres kurzus nem okoz hibát', () => {
  const S = stat({ courseId: 99 });
  same(0, S.N); same(0, S.k.students); same(null, S.k.avgRate);
});

const G2 = 'Megjelenítés (böngésző)';
jsTest(G2, 'Dátum- és százalékformázás', () => {
  same('2026.10.22. csüt', CS().huDate('2026-10-22', true));
  same('2026.10.22.', CS().huDate('2026-10-22'));
  same('10.22.', CS().shortDate('2026-10-22'));
  same('46%', CS().pct(0.456)); same('45.6%', CS().pct(0.456, 1)); same('–', CS().pct(null));
});

jsTest(G2, 'Oszlopdiagram: oszlopok, átlagvonal, súgó', () => {
  const el = document.createElement('div');
  el.style.cssText = 'position:absolute;left:-9999px;width:600px';
  document.body.appendChild(el);
  try {
    CS().barChart(el, {
      series: [{ key: 'a', cls: 's1' }, { key: 'b', cls: 's2' }],
      rows: [{ label: 'x', values: { a: 3, b: 1 }, tip: 'T1' }, { label: 'y', values: { a: 2, b: 0 }, tip: 'T2' }, { label: 'z', values: { a: 0, b: 0 }, tip: 'T3' }],
      refLine: 2, refLabel: 'átlag',
    });
    same(3, el.querySelectorAll('.hit').length, 'oszlopok');
    same(3, el.querySelectorAll('path').length, 'szegmensek (üres nincs)');
    same(1, el.querySelectorAll('.ref').length, 'átlagvonal');
    same('T2', el.querySelectorAll('.hit')[1].dataset.tip);
  } finally { el.remove(); }
});

jsTest(G2, 'Státusz-címkék ikonnal és felirattal', () => {
  const st = CS().STATUS;
  for (const k of ['regular', 'shaky', 'risk', 'dropped', 'fresh']) check(st[k] && st[k].icon && st[k].label && st[k].hint, k);
});

/* ------------------------------------------------------------- megjelenítés */
const ICON = { pass: '✓', fail: '✕', warn: '!', skip: '–' };
const LABEL = { pass: 'sikeres', fail: 'hibás', warn: 'figyelmeztetés', skip: 'kihagyva' };

function render(results, meta) {
  const counts = { pass: 0, fail: 0, warn: 0, skip: 0 };
  results.forEach(r => counts[r.status]++);
  const ok = counts.fail === 0;
  const sum = $('#atSummary');
  sum.hidden = false;
  sum.className = 'card at-summary ' + (ok ? 'ok' : 'bad');
  sum.innerHTML = `
    <div class="at-big"><span class="at-ico ${ok ? 'pass' : 'fail'}">${ok ? '✓' : '✕'}</span>
      <div><b>${ok ? 'Minden teszt sikeres' : counts.fail + ' teszt hibás'}</b>
      <small>${counts.pass} / ${results.length} sikeres${counts.warn ? ` · ${counts.warn} figyelmeztetés` : ''}${counts.skip ? ` · ${counts.skip} kihagyva` : ''} · ${meta.ms} ms</small></div></div>
    <div class="at-meta muted small">PHP ${esc(meta.env.php)} · ${esc(meta.env.db)} · ${esc(meta.env.time)}</div>
    <label class="at-only"><input type="checkbox" id="onlyBad"> Csak a hibás / figyelmeztető tesztek</label>`;
  const groups = new Map();
  results.forEach(r => { if (!groups.has(r.group)) groups.set(r.group, []); groups.get(r.group).push(r); });
  $('#atResults').innerHTML = [...groups].map(([g, rs]) => {
    const bad = rs.filter(r => r.status === 'fail').length, warn = rs.filter(r => r.status === 'warn').length;
    return `<section class="card at-group">
      <div class="card-head"><h2>${esc(g)}</h2>
        <span class="at-count ${bad ? 'fail' : warn ? 'warn' : 'pass'}">${rs.filter(r => r.status === 'pass').length}/${rs.length}</span></div>
      <ul class="at-list">${rs.map(r => `<li class="at-${r.status}">
        <span class="at-ico ${r.status}" title="${LABEL[r.status]}">${ICON[r.status]}</span>
        <span class="at-name">${esc(r.name)}${r.msg ? `<span class="at-msg">${esc(r.msg)}</span>` : ''}</span>
        <span class="at-ms">${r.ms} ms</span></li>`).join('')}</ul></section>`;
  }).join('');
  $('#onlyBad').onchange = e => document.body.classList.toggle('at-onlybad', e.target.checked);
}

async function runAll() {
  const btn = $('#runBtn');
  btn.disabled = true; btn.textContent = 'Futnak a tesztek…';
  $('#atResults').innerHTML = '<p class="muted">Szerveroldali tesztek futnak…</p>';
  const t0 = performance.now();
  let server;
  try {
    const r = await fetch('autotest.php', { method: 'POST', body: new FormData($('#atForm')), credentials: 'same-origin', cache: 'no-store' });
    const txt = await r.text();
    try { server = JSON.parse(txt); }
    catch (e) { throw new Error('A szerver nem JSON-t adott vissza (HTTP ' + r.status + '): ' + txt.replace(/<[^>]+>/g, ' ').trim().slice(0, 400)); }
  } catch (e) {
    server = { results: [{ group: 'Futtatás', name: 'A szerveroldali tesztek lefutottak', status: 'fail', msg: e.message, ms: 0 }], env: { php: '?', db: '?', time: '' } };
  }
  const js = runJsTests();
  const results = [...server.results.filter(r => r.group !== 'Takarítás'), ...js, ...server.results.filter(r => r.group === 'Takarítás')];
  render(results, { ms: Math.round(performance.now() - t0), env: server.env });
  btn.disabled = false; btn.textContent = '↻ Újrafuttatás';
}

$('#runBtn').addEventListener('click', runAll);
window.CatalogAutotest = { runJsTests };
})();
