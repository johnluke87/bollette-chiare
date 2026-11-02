import { useEffect, useState, useCallback, useRef } from 'react';
import { Link } from 'react-router-dom';
import { api } from './api.js';
import { unit } from './util.js';

const paths = {
  home: 'M4 11l8-7 8 7v9H4zM10 20v-5h4v5',
  upload: 'M12 16V4M7 9l5-5 5 5M4 16v4h16v-4',
  plus: 'M12 5v14M5 12h14',
  settings: 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.6 1.6 0 00-2.7 1.1V21a2 2 0 11-4 0v-.1a1.6 1.6 0 00-2.7-1.1l-.1.1a2 2 0 11-2.8-2.8l.1-.1A1.6 1.6 0 004.6 15H4.5a2 2 0 110-4h.1a1.6 1.6 0 001.1-2.7l-.1-.1a2 2 0 112.8-2.8l.1.1A1.6 1.6 0 0011 4.6V4.5a2 2 0 114 0v.1a1.6 1.6 0 002.7 1.1l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 001.1 2.7h.1a2 2 0 110 4h-.1a1.6 1.6 0 00-1.2.8z',
  luce: 'M9 18h6M10 21h4M12 3a6 6 0 00-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0012 3z',
  gas: 'M12 3c1 3 5 5.5 5 10a5 5 0 01-10 0c0-2.5 1.5-4 2.5-5 .3 1.6 1 2.6 2 3 0-3 .5-5.5.5-8z',
  compare: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  bell: 'M6 16V11a6 6 0 1112 0v5l2 2H4zM10 21h4',
  doc: 'M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h7',
  logout: 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11',
  leaf: 'M5 19c0-8 5-14 15-15-1 10-7 15-15 15zM5 19l7-7',
  bolt: 'M13 2L4 14h7l-1 8 9-12h-7z',
};

export function Icon({ name, ...props }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>
      <path d={paths[name]} />
    </svg>
  );
}

export function Brand({ to = '/' }) {
  return (
    <Link to={to} className="brand">
      <span className="brand-mark"><Icon name="bolt" width="18" height="18" strokeWidth="2.4" /></span>
      Bollette Chiare
    </Link>
  );
}

export function Alert({ kind = 'error', children }) {
  if (!children) return null;
  return <div className={`alert ${kind}`} role={kind === 'error' ? 'alert' : 'status'}>{children}</div>;
}

export function Field({ label, hint, children }) {
  return (
    <label className="field">
      <span>{label}</span>
      {children}
      {hint && <small className="muted">{hint}</small>}
    </label>
  );
}

export function Loading({ text = 'Caricamento…' }) {
  return <p className="muted" role="status">{text}</p>;
}

export function useLoad(fn, deps = []) {
  const [state, setState] = useState({ data: null, error: '', loading: true });
  const load = useCallback(() => {
    setState((s) => ({ ...s, loading: true }));
    return fn()
      .then((data) => setState({ data, error: '', loading: false }))
      .catch((e) => setState({ data: null, error: e.message, loading: false }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  useEffect(() => {
    load();
  }, [load]);
  return { ...state, reload: load };
}

export function Seg({ value, onChange, options, label }) {
  return (
    <div className="seg" role="group" aria-label={label}>
      {options.map(([v, text]) => (
        <button key={v} type="button" className={value === v ? 'on' : ''} aria-pressed={value === v} onClick={() => onChange(v)}>{text}</button>
      ))}
    </div>
  );
}

/** Ricerca del comune (nome o CAP) per sapere quali offerte valgono lì. */
export function ComuneSearch({ value, onPick, label = 'Comune della fornitura' }) {
  const [q, setQ] = useState(value?.label || value?.comune || '');
  const [list, setList] = useState([]);
  const timer = useRef(null);
  const picked = useRef(value?.label || '');

  useEffect(() => {
    setQ(value?.label || value?.comune || '');
    picked.current = value?.label || value?.comune || '';
  }, [value?.istat]); // eslint-disable-line react-hooks/exhaustive-deps

  function change(e) {
    const v = e.target.value;
    setQ(v);
    clearTimeout(timer.current);
    if (v.trim().length < 2 || v === picked.current) {
      setList([]);
      return;
    }
    timer.current = setTimeout(() => {
      api(`/comuni?q=${encodeURIComponent(v.trim())}`).then((r) => setList(r.results)).catch(() => setList([]));
    }, 200);
  }

  return (
    <>
      <Field label={label} hint="Nome o CAP: alcune offerte valgono solo in certe zone.">
        <input value={q} onChange={change} autoComplete="off" placeholder="Es. Treviso o 31100" />
      </Field>
      {list.length > 0 && (
        <ul className="suggest">
          {list.map((c) => (
            <li key={c.istat}>
              <button type="button" onClick={() => { picked.current = c.label; setQ(c.label); setList([]); onPick(c); }}>{c.label}</button>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

/** Consumi bolletta per bolletta, come colonne (le stimate più chiare). */
export function ConsumptionChart({ bills, commodity }) {
  if (!bills.length) return null;
  const items = bills.slice(-12);
  const perDay = items.map((b) => {
    const days = (new Date(b.period_to) - new Date(b.period_from)) / 86400000 + 1;
    return b.consumption / days;
  });
  const max = Math.max(...perDay, 0.001);
  const W = 640;
  const H = 200;
  const pad = { l: 8, r: 8, t: 22, b: 30 };
  const bw = Math.min((W - pad.l - pad.r) / items.length, 90); // con poche bollette le colonne restano strette
  return (
    <figure style={{ margin: 0 }}>
      <svg className="chart" viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`Consumo medio al giorno per bolletta, in ${unit(commodity)}`}>
        <line className="grid" x1={pad.l} x2={W - pad.r} y1={H - pad.b} y2={H - pad.b} />
        {items.map((b, i) => {
          const h = (perDay[i] / max) * (H - pad.t - pad.b);
          const x = pad.l + i * bw + bw * 0.15;
          const y = H - pad.b - h;
          const d = new Date(b.period_from);
          return (
            <g key={b.id ?? i}>
              <rect className={`bar ${commodity}${b.estimated ? ' est' : ''}`} x={x} y={y} width={bw * 0.7} height={Math.max(h, 1)} rx="4">
                <title>{`${b.period_from} → ${b.period_to}: ${b.consumption} ${unit(commodity)}${b.estimated ? ' (stimato)' : ''}`}</title>
              </rect>
              <text className="val" x={x + bw * 0.35} y={y - 6} textAnchor="middle">{perDay[i].toFixed(1)}</text>
              <text x={x + bw * 0.35} y={H - 10} textAnchor="middle">{d.toLocaleDateString('it-IT', { month: 'short', year: '2-digit' })}</text>
            </g>
          );
        })}
      </svg>
      <figcaption className="note">{unit(commodity)} al giorno, in media, per ogni bolletta{items.some((b) => b.estimated) ? ' · più chiare: letture stimate' : ''}.</figcaption>
    </figure>
  );
}
