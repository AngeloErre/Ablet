// Grafici SVG senza dipendenze. Palette categoriale e rampa sequenziale dalla palette di riferimento (blu, arancio);
// i colori identificano la serie, il testo resta in inchiostro neutro; ogni grafico ha legenda (se ≥ 2 serie),
// etichette al passaggio, vista tabellare ed esportazione SVG/PNG.
import { h } from './app.js';
const NS = 'http://www.w3.org/2000/svg';
export const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'];
const RAMP = ['#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#104281'];
const INK = '#0b0b0b', INK2 = '#52514e', GRID = '#e4e2dc';
const s = (tag, a = {}, text) => { const e = document.createElementNS(NS, tag); for (const k in a) e.setAttribute(k, a[k]); if (text != null) e.textContent = text; return e; };
const fmt = (v) => v == null ? 'n.d.' : (Math.round(v * 100) / 100).toLocaleString('it-IT');
const cut = (t, n) => t.length > n ? t.slice(0, n - 1) + '…' : t;

function frame(title, svg, table, legend) {
  const tip = h('div', { class: 'tip hidden' });
  const box = h('figure', { class: 'chart card' }, h('figcaption', {}, h('h3', {}, title)), legend, svg, tip,
    h('div', { class: 'tools' },
      h('button', { onclick: () => download(title, svg, 'svg') }, 'Esporta SVG'), h('button', { onclick: () => download(title, svg, 'png') }, 'Esporta PNG')),
    h('details', {}, h('summary', { class: 'small' }, 'Dati in tabella'), h('div', { class: 'scroll' }, table)));
  svg.addEventListener('pointermove', (e) => {
    const t = e.target.closest('[data-tip]');
    if (!t) return tip.classList.add('hidden');
    const r = box.getBoundingClientRect();
    tip.textContent = t.getAttribute('data-tip'); tip.style.left = (e.clientX - r.left) + 'px'; tip.style.top = (e.clientY - r.top) + 'px'; tip.classList.remove('hidden');
  });
  svg.addEventListener('pointerleave', () => tip.classList.add('hidden'));
  return box;
}
const legendOf = (series) => series.length < 2 ? null : h('div', { class: 'legend' }, series.map((x, i) => { const sw = h('i'); sw.style.background = x.color || SERIES[i]; return h('span', {}, sw, x.name); }));

/** Barre (anche raggruppate, fino a 4 serie). Valori nulli = «non disponibile», mai disegnati come zero. */
export function barChart({ title, labels, series, unit = '', min = 0, max = null, diverging = false }) {
  const W = 720, H = 320, L = 46, R = 12, T = 14, B = 78, pw = W - L - R, ph = H - T - B;
  const vals = series.flatMap(x => x.values).filter(v => v != null);
  const lo = diverging ? -1 : Math.min(min, ...vals, 0), hi = max ?? Math.max(1, ...vals) * 1.08;
  const y = (v) => T + ph - (v - lo) / (hi - lo) * ph;
  const svg = s('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': title });
  for (let i = 0; i <= 4; i++) { const v = lo + (hi - lo) * i / 4; svg.append(s('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), stroke: GRID }), s('text', { x: L - 6, y: y(v) + 4, 'text-anchor': 'end', 'font-size': 11, fill: INK2 }, fmt(v))); }
  svg.append(s('line', { x1: L, x2: W - R, y1: y(0), y2: y(0), stroke: INK2 }));
  if (unit) svg.append(s('text', { x: 4, y: 11, 'font-size': 11, fill: INK2 }, unit));
  const n = Math.max(1, labels.length), gw = pw / n, bw = Math.min(34, (gw - 8) / series.length - 2);
  labels.forEach((lab, i) => {
    const gx = L + gw * i + gw / 2;
    series.forEach((se, k) => {
      const v = se.values[i], x = gx - (series.length * (bw + 2)) / 2 + k * (bw + 2) + 1;
      if (v == null) { svg.append(s('text', { x: x + bw / 2, y: y(0) - 4, 'text-anchor': 'middle', 'font-size': 10, fill: INK2, 'data-tip': `${lab} · ${se.name}: non disponibile` }, '–')); return; }
      const top = Math.min(y(v), y(0)), hgt = Math.max(1.5, Math.abs(y(v) - y(0)));
      const color = diverging ? (v >= 0 ? SERIES[0] : SERIES[1]) : (se.color || SERIES[k]);
      svg.append(s('rect', { x, y: top, width: bw, height: hgt, rx: Math.min(4, hgt / 2), fill: color, 'data-tip': `${lab} · ${se.name}: ${fmt(v)}${unit ? ' ' + unit : ''}` }));
    });
    if (n <= 24 || i % Math.ceil(n / 24) === 0) svg.append(s('text', { x: gx, y: H - B + 14, 'text-anchor': 'end', 'font-size': 10.5, fill: INK2, transform: `rotate(-35 ${gx} ${H - B + 14})` }, cut(String(lab), 16)));
  });
  if (!vals.length) svg.append(s('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', fill: INK2, 'font-size': 14 }, 'Nessun dato disponibile'));
  const table = h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, ''), series.map(x => h('th', {}, x.name)))), h('tbody', {}, labels.map((l, i) => h('tr', {}, h('td', {}, l), series.map(x => h('td', {}, fmt(x.values[i])))))));
  return frame(title, svg, table, diverging ? h('div', { class: 'legend' }, ['termini positivi prevalenti', 'termini negativi prevalenti'].map((t, i) => { const sw = h('i'); sw.style.background = SERIES[i]; return h('span', {}, sw, t); })) : legendOf(series));
}

/** Mappa di calore righe × colonne con rampa sequenziale a una tinta (valori 0–1). Le righe possono essere raggruppate. */
export function heatmap({ title, rows, cols, values, groups = null, unit = '' }) {
  const L = 150, T = 30, cw = 42, chh = 24, W = L + cols.length * cw + 12, H = T + rows.length * chh + 12;
  const svg = s('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': title });
  cols.forEach((c, j) => svg.append(s('text', { x: L + j * cw + cw / 2, y: T - 10, 'text-anchor': 'middle', 'font-size': 11, fill: INK2 }, c)));
  rows.forEach((r, i) => {
    svg.append(s('text', { x: L - 8, y: T + i * chh + 16, 'text-anchor': 'end', 'font-size': 11, fill: INK }, cut((groups ? `G${groups[i] + 1} · ` : '') + r, 24)));
    cols.forEach((c, j) => {
      const v = values[i][j] || 0;
      svg.append(s('rect', { x: L + j * cw + 1, y: T + i * chh + 1, width: cw - 2, height: chh - 2, rx: 3, fill: v === 0 ? '#f0efec' : RAMP[Math.min(5, Math.floor(v * 6))], 'data-tip': `${r} · ${c}: ${fmt(v * 100)}%${unit}` }));
      if (v >= 0.2) svg.append(s('text', { x: L + j * cw + cw / 2, y: T + i * chh + 16, 'text-anchor': 'middle', 'font-size': 10, fill: v >= 0.5 ? '#fff' : INK, 'pointer-events': 'none' }, Math.round(v * 100)));
    });
  });
  const table = h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, 'Sessione'), groups ? h('th', {}, 'Gruppo') : null, cols.map(c => h('th', {}, c)))),
    h('tbody', {}, rows.map((r, i) => h('tr', {}, h('td', {}, r), groups ? h('td', {}, 'G' + (groups[i] + 1)) : null, values[i].map(v => h('td', {}, Math.round(v * 100) + '%'))))));
  return frame(title, svg, table, h('div', { class: 'legend' }, h('span', {}, 'quota di segmenti codificati: '), RAMP.map((c, i) => { const sw = h('i'); sw.style.background = c; return h('span', {}, sw, i === 0 ? 'bassa' : i === 5 ? 'alta' : ''); })));
}

function download(title, svg, kind) {
  const clone = svg.cloneNode(true); clone.setAttribute('xmlns', NS);
  const [, , w, hh] = svg.getAttribute('viewBox').split(' ').map(Number);
  clone.setAttribute('width', w); clone.setAttribute('height', hh + 28);
  clone.setAttribute('viewBox', `0 -28 ${w} ${hh + 28}`);
  clone.prepend(s('rect', { x: 0, y: -28, width: w, height: hh + 28, fill: '#fcfcfb' }), s('text', { x: 8, y: -9, 'font-size': 14, 'font-weight': 700, fill: INK, 'font-family': 'sans-serif' }, title));
  clone.setAttribute('font-family', 'sans-serif');
  const data = new XMLSerializer().serializeToString(clone), name = 'ablet-' + title.toLowerCase().replace(/[^a-z0-9]+/g, '-').slice(0, 50);
  const save = (blob, ext) => { const a = h('a', { href: URL.createObjectURL(blob), download: `${name}.${ext}` }); document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 2000); };
  if (kind === 'svg') return save(new Blob([data], { type: 'image/svg+xml' }), 'svg');
  const img = new Image();
  img.onload = () => { const c = document.createElement('canvas'); c.width = w * 2; c.height = (hh + 28) * 2; const x = c.getContext('2d'); x.scale(2, 2); x.drawImage(img, 0, 0); c.toBlob(b => save(b, 'png'), 'image/png'); };
  img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(data);
}
export const downloadText = (name, text, mime = 'text/plain') => { const a = h('a', { href: URL.createObjectURL(new Blob([text], { type: mime + ';charset=utf-8' })), download: name }); document.body.append(a); a.click(); a.remove(); };
export const downloadJson = (name, obj) => { const a = h('a', { href: URL.createObjectURL(new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' })), download: name }); document.body.append(a); a.click(); a.remove(); };
