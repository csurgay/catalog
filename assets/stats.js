/* Labor katalógus – jelenléti statisztika
 * Nyers adat: api.php  →  származtatott mutatók: computeStats()  →  render*()
 * Nincs külső függőség; a grafikonok kézzel rajzolt SVG-k.
 */
(function () {
'use strict';

/* ------------------------------------------------------------------ utils */
const $ = (s, el = document) => el.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const pct = (x, d = 0) => (x == null || isNaN(x)) ? '–' : (100 * x).toFixed(d) + '%';
const DAYS = ['vas', 'hét', 'kedd', 'szer', 'csüt', 'pén', 'szo'];
const huDate = (d, withDay) => { const [y, m, dd] = d.split('-'); return `${y}.${m}.${dd}.` + (withDay ? ' ' + DAYS[new Date(d + 'T12:00').getDay()] : ''); };
const shortDate = d => d.slice(5).replace('-', '.') + '.';
const toMin = hm => { const [h, m] = hm.split(':'); return +h * 60 + +m; };
const fromMin = m => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(Math.round(m % 60)).padStart(2, '0')}`;
const daysBetween = (a, b) => Math.round((new Date(b + 'T12:00') - new Date(a + 'T12:00')) / 864e5);
const median = arr => { if (!arr.length) return null; const s = [...arr].sort((a, b) => a - b), k = s.length >> 1; return s.length % 2 ? s[k] : (s[k - 1] + s[k]) / 2; };
const collator = new Intl.Collator('hu', { sensitivity: 'base', numeric: true });
const norm = s => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const store = {
  get(k, d) { try { const v = localStorage.getItem('catalog.' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
  set(k, v) { try { localStorage.setItem('catalog.' + k, JSON.stringify(v)); } catch (e) {} },
};

/* ------------------------------------------------------------- statuses */
const STATUS = {
  regular: { label: 'Rendszeres',     icon: '✓', cls: 'st-good',     hint: 'aktív arány ≥ 75%' },
  shaky:   { label: 'Ingadozó',       icon: '!', cls: 'st-warn',     hint: 'aktív arány 50–75%' },
  risk:    { label: 'Kockázatos',     icon: '▲', cls: 'st-serious',  hint: 'aktív arány < 50%' },
  dropped: { label: 'Lemorzsolódott', icon: '✕', cls: 'st-critical', hint: 'az utolsó 3+ óráról hiányzott' },
  fresh:   { label: 'Új',             icon: '★', cls: 'st-new',      hint: 'az utolsó 2 órán jelent meg először' },
};
const STATUS_ORDER = ['regular', 'shaky', 'risk', 'dropped', 'fresh'];

/* -------------------------------------------------------------- compute */
function computeStats(raw, { courseId, from, to, skipToday }) {
  const L = raw.lectures.filter(l => l.c === courseId && (!from || l.d >= from) && (!to || l.d <= to) && !(skipToday && l.d === raw.today));
  const li = new Map(L.map((l, i) => [l.id, i]));
  const N = L.length;
  const studentsById = new Map(raw.students.map(s => [s.id, s]));
  const per = new Map();               // sid -> {times: Array(N)}
  const lecTimes = L.map(() => []);
  for (const [sid, lid, hm, manual] of raw.att) {
    const i = li.get(lid);
    if (i === undefined) continue;
    if (!per.has(sid)) per.set(sid, { times: new Array(N).fill(null), manual: new Array(N).fill(0) });
    const p = per.get(sid);
    p.times[i] = hm; p.manual[i] = manual;
    lecTimes[i].push(toMin(hm));
  }
  const lecMedian = lecTimes.map(median);

  const lastDate = N ? L[N - 1].d : raw.today;
  const students = [];
  for (const [sid, p] of per) {
    const s = studentsById.get(sid) || { id: sid, k: '?', n: '?' };
    const pres = p.times.map(t => t !== null);
    const attended = pres.filter(Boolean).length;
    const first = pres.indexOf(true), last = pres.lastIndexOf(true);
    const span = N - first;
    let longest = 0, run = 0;
    for (const x of pres) { run = x ? run + 1 : 0; longest = Math.max(longest, run); }
    let cur = 0; for (let i = N - 1; i >= 0 && pres[i]; i--) cur++;
    let absent = 0; for (let i = N - 1; i >= 0 && !pres[i]; i--) absent++;
    const recentK = Math.min(4, span);
    const recent = pres.slice(N - recentK).filter(Boolean).length / recentK;
    const rate = attended / N, activeRate = attended / span;
    const mins = [], offs = [];
    p.times.forEach((t, i) => { if (t) { const m = toMin(t); mins.push(m); offs.push(m - lecMedian[i]); } });
    const avgArr = mins.reduce((a, b) => a + b, 0) / mins.length;
    const avgOff = offs.reduce((a, b) => a + b, 0) / offs.length;
    let status;
    if (absent >= 3) status = 'dropped';
    else if (N >= 4 && first >= N - 2) status = 'fresh';
    else if (activeRate >= 0.75) status = 'regular';
    else if (activeRate >= 0.5) status = 'shaky';
    else status = 'risk';
    students.push({
      id: sid, neptun: s.k, name: s.n || '(névtelen)', note: s.m || '',
      pres, times: p.times, manual: p.manual, attended, rate, activeRate, recent, trend: recent - activeRate,
      first, last, firstDate: L[first].d, lastDate: L[last].d, daysSince: daysBetween(L[last].d, raw.today),
      longest, cur, absent, avgArr, avgOff, status,
    });
  }

  const lectures = L.map((l, i) => {
    const present = students.filter(s => s.pres[i]);
    const fresh = present.filter(s => s.first === i).length;
    let retention = null;
    if (i > 0) {
      const prev = students.filter(s => s.pres[i - 1]);
      if (prev.length) retention = prev.filter(s => s.pres[i]).length / prev.length;
    }
    return { ...l, n: present.length, fresh, returning: present.length - fresh, retention, median: lecMedian[i] };
  });

  const ns = lectures.map(l => l.n);
  const avgN = N ? ns.reduce((a, b) => a + b, 0) / N : 0;
  const k = {
    students: students.length,
    lectures: N,
    avgN,
    peak: N ? Math.max(...ns) : 0,
    avgRate: students.length ? students.reduce((a, s) => a + s.rate, 0) / students.length : null,
    avgActive: students.length ? students.reduce((a, s) => a + s.activeRate, 0) / students.length : null,
    // "mag": legalább az órák 75%-án ott volt
    core: students.filter(s => s.rate >= 0.75).length,
    lastN: N ? ns[N - 1] : null,
    prevN: N > 1 ? ns[N - 2] : null,
    avgRetention: (() => { const r = lectures.map(l => l.retention).filter(x => x != null); return r.length ? r.reduce((a, b) => a + b, 0) / r.length : null; })(),
    atRisk: students.filter(s => s.status === 'risk' || s.status === 'dropped').length,
    lastDate,
  };
  const statusCounts = Object.fromEntries(STATUS_ORDER.map(st => [st, students.filter(s => s.status === st).length]));
  return { L: lectures, students, k, statusCounts, N };
}

/* ------------------------------------------------------------- tooltip */
const tip = document.createElement('div');
tip.className = 'viz-tip'; tip.setAttribute('role', 'tooltip'); tip.hidden = true;
document.body.appendChild(tip);
function showTip(html, ev) {
  tip.innerHTML = html; tip.hidden = false;
  const r = tip.getBoundingClientRect(), pad = 14;
  let x = ev.clientX + pad, y = ev.clientY + pad;
  if (x + r.width > innerWidth - 8) x = ev.clientX - r.width - pad;
  if (y + r.height > innerHeight - 8) y = ev.clientY - r.height - pad;
  tip.style.transform = `translate(${Math.max(8, x)}px, ${Math.max(8, y)}px)`;
}
function hideTip() { tip.hidden = true; }
function bindTips(root) {
  root.querySelectorAll('[data-tip]').forEach(el => {
    el.addEventListener('pointermove', ev => showTip(el.dataset.tip, ev));
    el.addEventListener('pointerleave', hideTip);
  });
}

/* --------------------------------------------------------------- charts */
const niceMax = v => { if (v <= 5) return Math.max(1, Math.ceil(v)); const p = 10 ** Math.floor(Math.log10(v)); const m = v / p; return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10) * p; };
const ticks = (max, n = 4) => { const step = niceMax(max / n); const out = []; for (let v = 0; v <= max + 1e-9; v += step) out.push(+v.toFixed(6)); return out; };

/** Oszlopdiagram (opcionálisan halmozott). series: [{key,label,cls}], rows: [{label, values:{key:v}, tip}] */
function barChart(el, { rows, series, height = 220, refLine, refLabel, yFmt = v => v, xEvery }) {
  const W = Math.max(280, el.clientWidth), H = height;
  const m = { t: 14, r: 12, b: 28, l: 34 };
  const iw = W - m.l - m.r, ih = H - m.t - m.b;
  const totals = rows.map(r => series.reduce((a, s) => a + (r.values[s.key] || 0), 0));
  const ymax = niceMax(Math.max(1, ...totals, refLine || 0));
  const y = v => m.t + ih - (v / ymax) * ih;
  const n = rows.length || 1, band = iw / n, bw = Math.max(2, Math.min(42, band * 0.72));
  const every = xEvery || Math.ceil(n / Math.max(1, Math.floor(iw / 46)));
  let g = '';
  for (const t of ticks(ymax)) g += `<line class="grid" x1="${m.l}" x2="${W - m.r}" y1="${y(t)}" y2="${y(t)}"/><text class="tick" x="${m.l - 6}" y="${y(t) + 3.5}" text-anchor="end">${yFmt(t)}</text>`;
  rows.forEach((r, i) => {
    const cx = m.l + band * i + band / 2, x0 = cx - bw / 2;
    let acc = 0; const segs = series.filter(s => r.values[s.key] > 0);
    segs.forEach((s, j) => {
      const v = r.values[s.key], top = y(acc + v), bot = y(acc), last = j === segs.length - 1;
      const gap = j > 0 ? 2 : 0;                                      // 2px felületi rés a szegmensek közt
      const h = Math.max(0, bot - top - gap), rr = last ? Math.min(4, bw / 2, h) : 0;
      g += `<path class="${s.cls}" d="M${x0},${top + h} V${top + rr} Q${x0},${top} ${x0 + rr},${top} H${x0 + bw - rr} Q${x0 + bw},${top} ${x0 + bw},${top + rr} V${top + h} Z" transform="translate(0,${0})"/>`;
      acc += v;
    });
    g += `<rect class="hit" x="${m.l + band * i}" y="${m.t}" width="${band}" height="${ih}" data-tip="${esc(r.tip)}"/>`;
    if (i % every === 0) g += `<text class="tick" x="${cx}" y="${H - 9}" text-anchor="middle">${esc(r.label)}</text>`;
  });
  g += `<line class="axis" x1="${m.l}" x2="${W - m.r}" y1="${y(0)}" y2="${y(0)}"/>`;
  if (refLine) g += `<line class="ref" x1="${m.l}" x2="${W - m.r}" y1="${y(refLine)}" y2="${y(refLine)}"/><text class="ref-label" x="${W - m.r}" y="${y(refLine) - 5}" text-anchor="end">${esc(refLabel)}</text>`;
  el.innerHTML = `<svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img">${g}</svg>`;
  bindTips(el);
}

/* --------------------------------------------------------------- render */
let RAW = null, S = null;
const state = {
  course: null, from: '', to: '', skipToday: store.get('skipToday', true),
  q: '', statuses: new Set(), minRate: 0,
  sort: store.get('sort', { key: 'rate', dir: -1 }),
};

function kpi(label, value, sub, cls = '') {
  return `<div class="kpi ${cls}"><span>${label}</span><b>${value}</b>${sub ? `<small>${sub}</small>` : ''}</div>`;
}

function renderKpis() {
  const k = S.k;
  const delta = (k.lastN != null && k.prevN != null) ? k.lastN - k.prevN : null;
  const dHtml = delta == null ? '' : `<em class="${delta >= 0 ? 'up' : 'down'}">${delta >= 0 ? '▲' : '▼'} ${Math.abs(delta)}</em> az előzőhöz képest`;
  $('#kpis').innerHTML = [
    kpi('Hallgatók', k.students, `${k.core} fős törzs (≥75%)`),
    kpi('Órák', k.lectures, k.lectures ? `utolsó: ${huDate(k.lastDate)}` : ''),
    kpi('Átlagos létszám', k.avgN.toFixed(1), `csúcs: ${k.peak} fő`),
    kpi('Utolsó óra', k.lastN ?? '–', dHtml),
    kpi('Átlagos jelenléti arány', pct(k.avgRate), `aktív arány: ${pct(k.avgActive)}`),
    kpi('Visszatérési arány', pct(k.avgRetention), 'előző óra résztvevőiből'),
    kpi('Figyelendő', k.atRisk, 'kockázatos + lemorzsolódott', k.atRisk ? 'alert' : ''),
  ].join('');
}

function renderCharts() {
  const L = S.L;
  barChart($('#chAttendance'), {
    series: [{ key: 'ret', cls: 's1' }, { key: 'fresh', cls: 's2' }],
    rows: L.map(l => ({
      label: shortDate(l.d), values: { ret: l.returning, fresh: l.fresh },
      tip: `<b>${huDate(l.d, true)}</b>${l.t ? '<br>' + esc(l.t) : ''}<br>Létszám: <b>${l.n}</b><br>Visszatérő: ${l.returning} · Új: ${l.fresh}` +
           (l.retention != null ? `<br>Az előző óra résztvevőiből ${pct(l.retention)} jött el` : '') +
           (l.median != null ? `<br>Bejelentkezés mediánja: ${fromMin(l.median)}` : ''),
    })),
    refLine: S.k.avgN, refLabel: `átlag ${S.k.avgN.toFixed(1)}`,
  });

  // jelenléti arány eloszlás
  const bins = Array.from({ length: 10 }, (_, i) => ({ lo: i * 10, n: 0 }));
  S.students.forEach(s => bins[Math.min(9, Math.floor(s.rate * 10 + 1e-9))].n++);
  barChart($('#chRate'), {
    series: [{ key: 'n', cls: 's1' }], height: 200, xEvery: 1,
    rows: bins.map(b => ({ label: b.lo + '', values: { n: b.n }, tip: `${b.lo}–${b.lo + 10}% jelenlét<br><b>${b.n} hallgató</b>` })),
  });

  // bejelentkezés időpontja (15 perces sávok)
  const mins = [];
  S.students.forEach(s => s.times.forEach((t, i) => { if (t && !s.manual[i]) mins.push(toMin(t)); }));
  const chTime = $('#chTime');
  if (mins.length) {
    const lo = Math.floor(Math.min(...mins) / 15) * 15, hi = Math.floor(Math.max(...mins) / 15) * 15;
    const tb = []; for (let m = lo; m <= hi; m += 15) tb.push({ m, n: 0 });
    mins.forEach(m => tb[Math.floor((m - lo) / 15)].n++);
    barChart(chTime, {
      series: [{ key: 'n', cls: 's1' }], height: 200,
      rows: tb.map(b => ({ label: fromMin(b.m), values: { n: b.n }, tip: `${fromMin(b.m)}–${fromMin(b.m + 15)}<br><b>${b.n} bejelentkezés</b>` })),
    });
  } else chTime.innerHTML = '<p class="muted">Nincs időadat.</p>';

  // státusz megoszlás – kattintható szűrő
  const tot = Math.max(1, S.students.length);
  $('#chStatus').innerHTML = STATUS_ORDER.map(st => {
    const c = S.statusCounts[st], on = state.statuses.has(st);
    return `<button class="status-row ${on ? 'on' : ''}" data-st="${st}" title="${STATUS[st].hint} — kattints a szűréshez">
      <span class="st-badge ${STATUS[st].cls}"><i>${STATUS[st].icon}</i>${STATUS[st].label}</span>
      <span class="st-track"><span class="st-bar ${STATUS[st].cls}" style="width:${(100 * c / tot).toFixed(1)}%"></span></span>
      <b class="num">${c}</b></button>`;
  }).join('');
  $('#chStatus').querySelectorAll('.status-row').forEach(b => b.onclick = () => {
    const st = b.dataset.st; state.statuses.has(st) ? state.statuses.delete(st) : state.statuses.add(st);
    syncChips(); renderCharts(); renderTable();
  });
}

/* ---------------------------------------------------------------- table */
const COLS = [
  { key: 'name',       label: 'Név · Neptun',   get: s => s.name, str: true },
  { key: 'attended',   label: 'Jelen',          get: s => s.attended, title: 'Részvételek száma / órák száma' },
  { key: 'rate',       label: 'Arány',          get: s => s.rate, title: 'Jelenlét az összes órához képest' },
  { key: 'activeRate', label: 'Aktív arány',    get: s => s.activeRate, title: 'Jelenlét az első megjelenése óta tartott órákhoz képest' },
  { key: 'trend',      label: 'Trend',          get: s => s.trend, title: 'Utolsó (max.) 4 óra aránya − aktív arány' },
  { key: 'longest',    label: 'Sorozat', get: s => s.longest, title: 'Leghosszabb egymás utáni jelenlét-sorozat' },
  { key: 'absent',     label: 'Hiányzik',       get: s => s.absent, title: 'Hány óra óta nem jött (egymás után, a legutolsóig)' },
  { key: 'lastDate',   label: 'Utoljára',       get: s => s.lastDate, str: true },
  { key: 'avgOff',     label: 'Érkezés',        get: s => s.avgOff, title: 'Átlagos bejelentkezési idő az óra mediánjához képest (perc; negatív = korábban)' },
  { key: 'status',     label: 'Státusz',        get: s => STATUS_ORDER.indexOf(s.status) },
  { key: 'pattern',    label: 'Minta',          get: s => s.pres.map(x => x ? 1 : 0).join(''), str: true, title: 'Órák időrendben: teli = jelen' },
];

function filtered() {
  const qn = norm(state.q.trim());
  return S.students.filter(s =>
    (!qn || norm(s.name).includes(qn) || norm(s.neptun).includes(qn) || norm(s.note).includes(qn)) &&
    (!state.statuses.size || state.statuses.has(s.status)) &&
    s.rate * 100 >= state.minRate - 1e-9);
}
function sorted(list) {
  const c = COLS.find(c => c.key === state.sort.key) || COLS[3], d = state.sort.dir;
  return [...list].sort((a, b) => {
    const va = c.get(a), vb = c.get(b);
    const r = c.str ? collator.compare(va, vb) : (va - vb);
    return r * d || collator.compare(a.name, b.name);
  });
}

function rateCell(x) {
  return `<span class="ratebar"><span style="width:${(100 * x).toFixed(1)}%"></span></span><span class="num">${pct(x)}</span>`;
}
function trendCell(t) {
  if (Math.abs(t) < 0.05) return '<span class="muted">—</span>';
  return `<span class="${t > 0 ? 'up' : 'down'}">${t > 0 ? '▲' : '▼'} ${Math.round(Math.abs(t) * 100)}</span>`;
}
function patternCell(s) {
  return '<span class="strip">' + s.pres.map((p, i) => {
    const l = S.L[i];
    const t = `${huDate(l.d, true)}: ${p ? 'jelen ' + s.times[i] + (s.manual[i] ? ' (kézi)' : '') : (i < s.first ? 'még nem járt' : 'hiányzott')}`;
    return `<i class="${p ? 'p' : i < s.first ? 'pre' : 'a'}" title="${esc(t)}"></i>`;
  }).join('') + '</span>';
}

function renderTable() {
  const list = sorted(filtered());
  const th = COLS.map(c => {
    const on = state.sort.key === c.key;
    return `<th data-k="${c.key}" class="${on ? 'sorted' : ''} ${c.str ? '' : 'r'}" title="${esc(c.title || 'Rendezés')}" tabindex="0" aria-sort="${on ? (state.sort.dir > 0 ? 'ascending' : 'descending') : 'none'}">${c.label}<span class="arr">${on ? (state.sort.dir > 0 ? '↑' : '↓') : '↕'}</span></th>`;
  }).join('');
  const rows = list.map(s => `<tr data-id="${s.id}">
    <td class="name"><a href="student.php?id=${s.id}">${esc(s.name)}</a><small class="mono muted">${esc(s.neptun)}${s.note ? ' · ' + esc(s.note) : ''}</small></td>
    <td class="r num">${s.attended}<span class="muted">/${S.N}</span></td>
    <td class="r rate">${rateCell(s.rate)}</td>
    <td class="r num">${pct(s.activeRate)}</td>
    <td class="r">${trendCell(s.trend)}</td>
    <td class="r num">${s.longest}${s.cur && s.cur !== s.longest ? `<span class="muted"> (most ${s.cur})</span>` : ''}</td>
    <td class="r num">${s.absent ? s.absent : '<span class="muted">0</span>'}</td>
    <td class="r nowrap">${shortDate(s.lastDate)} <span class="muted">${s.daysSince ? s.daysSince + ' n.' : 'ma'}</span></td>
    <td class="r num">${isNaN(s.avgOff) ? '–' : (s.avgOff > 0 ? '+' : '') + Math.round(s.avgOff) + "'"}</td>
    <td><span class="st-badge ${STATUS[s.status].cls}" title="${STATUS[s.status].hint}"><i>${STATUS[s.status].icon}</i>${STATUS[s.status].label}</span></td>
    <td>${patternCell(s)}</td></tr>`).join('');
  $('#tbl').innerHTML = `<thead><tr>${th}</tr></thead><tbody>${rows || `<tr><td colspan="${COLS.length}" class="muted empty">Nincs találat.</td></tr>`}</tbody>`;
  $('#count').textContent = `${list.length} / ${S.students.length} hallgató`;
  $('#tbl thead').querySelectorAll('th').forEach(th => {
    const go = () => {
      const k = th.dataset.k;
      state.sort = state.sort.key === k ? { key: k, dir: -state.sort.dir } : { key: k, dir: COLS.find(c => c.key === k).str ? 1 : -1 };
      store.set('sort', state.sort); renderTable();
    };
    th.onclick = go; th.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } };
  });
}

function syncChips() {
  document.querySelectorAll('#chips [data-st]').forEach(b => b.classList.toggle('on', state.statuses.has(b.dataset.st)));
}

function exportCsv() {
  const list = sorted(filtered());
  const head = ['Név', 'Neptun', 'Jelen', 'Órák', 'Arány %', 'Aktív arány %', 'Utolsó 4 óra %', 'Leghosszabb sorozat', 'Aktuális sorozat', 'Hiányzik (óra)', 'Első', 'Utolsó', 'Érkezés (perc)', 'Státusz', ...S.L.map(l => l.d)];
  const rows = list.map(s => [s.name, s.neptun, s.attended, S.N, Math.round(s.rate * 100), Math.round(s.activeRate * 100), Math.round(s.recent * 100),
    s.longest, s.cur, s.absent, s.firstDate, s.lastDate, Math.round(s.avgOff), STATUS[s.status].label, ...s.pres.map(p => p ? 1 : 0)]);
  const csv = [head, ...rows].map(r => r.map(v => /[";\n]/.test(String(v)) ? `"${String(v).replace(/"/g, '""')}"` : v).join(';')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
  const c = RAW.courses.find(c => c.id == state.course);
  a.download = `jelenlet_${(c ? c.name : 'kurzus').replace(/\W+/g, '_')}_${RAW.today}.csv`;
  a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

function recompute() {
  S = computeStats(RAW, { courseId: state.course, from: state.from, to: state.to, skipToday: state.skipToday });
  $('#empty').hidden = S.N > 0;
  $('#dash').hidden = S.N === 0;
  if (!S.N) return;
  renderKpis(); renderCharts(); renderTable();
}

function initFilters() {
  const cs = $('#fCourse');
  cs.innerHTML = RAW.courses.map(c => `<option value="${c.id}">${esc([c.code, c.name, c.semester].filter(Boolean).join(' · '))}</option>`).join('');
  state.course = +store.get('course', RAW.active) || RAW.active;
  if (!RAW.courses.some(c => +c.id === state.course)) state.course = RAW.active;
  cs.value = state.course;
  cs.onchange = () => { state.course = +cs.value; store.set('course', state.course); state.from = state.to = ''; syncRange(); recompute(); };

  const syncRange = () => {
    const ds = RAW.lectures.filter(l => l.c === state.course).map(l => l.d);
    $('#fFrom').value = state.from || ds[0] || ''; $('#fTo').value = state.to || ds[ds.length - 1] || '';
  };
  syncRange();
  $('#fFrom').onchange = e => { state.from = e.target.value; recompute(); };
  $('#fTo').onchange = e => { state.to = e.target.value; recompute(); };
  $('#fReset').onclick = () => { state.from = state.to = ''; syncRange(); recompute(); };

  const st = $('#fToday'); st.checked = state.skipToday;
  st.onchange = () => { state.skipToday = st.checked; store.set('skipToday', st.checked); recompute(); };
  $('#fTodayWrap').hidden = !RAW.lectures.some(l => l.d === RAW.today);

  let t; $('#q').oninput = e => { clearTimeout(t); t = setTimeout(() => { state.q = e.target.value; renderTable(); }, 120); };
  $('#chips').innerHTML = STATUS_ORDER.map(st => `<button data-st="${st}" class="chip-btn" title="${STATUS[st].hint}"><span class="st-dot ${STATUS[st].cls}"></span>${STATUS[st].label}</button>`).join('');
  $('#chips').querySelectorAll('button').forEach(b => b.onclick = () => {
    const st = b.dataset.st; state.statuses.has(st) ? state.statuses.delete(st) : state.statuses.add(st);
    syncChips(); renderCharts(); renderTable();
  });
  const mr = $('#minRate');
  mr.oninput = () => { state.minRate = +mr.value; $('#minRateV').textContent = mr.value + '%'; renderTable(); };
  $('#csv').onclick = exportCsv;
}

let rz; addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => S && S.N && renderCharts(), 150); });

async function boot() {
  try {
    const r = await fetch('api.php', { credentials: 'same-origin', cache: 'no-store' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    RAW = await r.json();
  } catch (e) {
    $('#loading').textContent = 'Nem sikerült betölteni az adatokat: ' + e.message; return;
  }
  $('#loading').remove();
  initFilters(); recompute();
}

window.CatalogStats = { computeStats, STATUS, huDate, shortDate, pct, barChart, bindTips };
if (document.getElementById('dash')) boot();
})();
