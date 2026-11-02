const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

export function unit(commodity) {
  return commodity === 'gas' ? 'Smc' : 'kWh';
}

export function euro(n, digits = 0) {
  if (n == null || Number.isNaN(n)) return '—';
  return n.toLocaleString('it-IT', { style: 'currency', currency: 'EUR', minimumFractionDigits: digits, maximumFractionDigits: digits });
}

export function price(n) {
  if (n == null) return '—';
  return `${n.toLocaleString('it-IT', { minimumFractionDigits: 3, maximumFractionDigits: 4 })} €`;
}

export function int(n) {
  return n == null ? '—' : Math.round(n).toLocaleString('it-IT');
}

export function formatDate(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  return `${d} ${MONTHS[m - 1]} ${y}`;
}

export function shortDate(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-');
  return `${d}/${m}/${y}`;
}

export function monthLabel(ym) {
  if (!ym) return '';
  const [y, m] = ym.split('-').map(Number);
  return `${MONTHS[m - 1]} ${y}`;
}

/** Numero scritto all'italiana ("0,169", "1.234,5") o col punto; vuoto → null. */
export function parseNum(v) {
  if (v === '' || v == null) return null;
  if (typeof v === 'number') return v;
  let s = String(v).trim().replace(/[^\d,.-]/g, '');
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
}

export const COMMODITY_LABEL = { luce: 'Luce', gas: 'Gas' };
