import { Field, ComuneSearch, Seg } from './ui.jsx';
import { unit, parseNum } from './util.js';

// Una "parte" è una bolletta di luce o di gas con i dati della sua fornitura (una bolletta dual ne ha due).
export const SUPPLY_KEYS = ['commodity', 'seller', 'offer_name', 'price_type', 'unit_price', 'spread', 'fixed_year', 'code', 'power_kw', 'istat', 'resident', 'annual_use'];
export const BILL_KEYS = ['period_from', 'period_to', 'consumption', 'f1', 'f2', 'f3', 'estimated', 'total', 'energy_cost', 'unit_price'];
const NUMERIC = ['unit_price', 'spread', 'fixed_year', 'power_kw', 'annual_use', 'consumption', 'f1', 'f2', 'f3', 'total', 'energy_cost'];

export function emptyPart(commodity = 'luce') {
  return {
    commodity, seller: '', offer_name: '', price_type: 'fisso', unit_price: '', spread: '', fixed_year: '', code: '',
    power_kw: commodity === 'luce' ? '3' : '', istat: '', comune_label: '', resident: true, annual_use: '',
    period_from: '', period_to: '', consumption: '', f1: '', f2: '', f3: '', estimated: false, total: '', energy_cost: '',
  };
}

/** Dal risultato della lettura: tutto in stringhe per i campi, più l'elenco dei campi trovati. */
export function partFromReader(r) {
  const p = emptyPart(r.commodity || 'luce');
  const found = [];
  for (const k of Object.keys(p)) {
    if (r[k] != null && r[k] !== '') {
      p[k] = typeof r[k] === 'number' ? String(r[k]).replace('.', ',') : r[k];
      found.push(k);
    }
  }
  return { ...p, found };
}

function numbers(p, keys) {
  const out = {};
  for (const k of keys) out[k] = NUMERIC.includes(k) ? parseNum(p[k]) : p[k];
  return out;
}

export function supplyBody(p) {
  const s = numbers(p, SUPPLY_KEYS);
  s.name = p.commodity === 'gas' ? 'Gas di casa' : 'Luce di casa';
  return s;
}

export function billBody(p) {
  return { ...numbers(p, BILL_KEYS), source: p.found?.length ? 'pdf' : 'manuale' };
}

export function PartForm({ part, onChange, showBill = true, showSupply = true, fixedCommodity = false }) {
  const set = (k) => (e) => onChange({ ...part, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });
  const cls = (k) => (part.found?.includes(k) ? 'found' : undefined);
  const u = unit(part.commodity);
  const inp = (k, props = {}) => <input className={cls(k)} value={part[k] ?? ''} onChange={set(k)} {...props} />;
  const num = (k, props = {}) => inp(k, { inputMode: 'decimal', ...props });

  return (
    <>
      {!fixedCommodity && (
        <Seg label="Tipo di fornitura" value={part.commodity} onChange={(v) => onChange({ ...part, commodity: v })} options={[['luce', 'Luce'], ['gas', 'Gas']]} />
      )}
      {showBill && (
        <>
          <h3>Periodo e consumi</h3>
          <div className="grid2">
            <Field label="Dal">{inp('period_from', { type: 'date' })}</Field>
            <Field label="Al">{inp('period_to', { type: 'date' })}</Field>
          </div>
          {part.commodity === 'luce' ? (
            <div className="grid3">
              <Field label="F1 (kWh)">{num('f1')}</Field>
              <Field label="F2 (kWh)">{num('f2')}</Field>
              <Field label="F3 (kWh)">{num('f3')}</Field>
            </div>
          ) : null}
          <div className="grid2">
            <Field label={`Consumo del periodo (${u})`} hint={part.commodity === 'luce' ? 'Se metti le fasce si calcola da solo.' : undefined}>{num('consumption')}</Field>
            <Field label="Totale bolletta (€)">{num('total')}</Field>
          </div>
          <div className="grid2">
            <Field label="Spesa per la materia (€)" hint={`La voce “spesa per la materia ${part.commodity === 'gas' ? 'gas naturale' : 'energia'}”.`}>{num('energy_cost')}</Field>
            <label className="check" style={{ alignSelf: 'center' }}>
              <input type="checkbox" checked={!!part.estimated} onChange={set('estimated')} />
              <span>Consumi stimati (non letti dal contatore)</span>
            </label>
          </div>
        </>
      )}
      {showSupply && (
        <>
          <h3>La tua offerta di oggi</h3>
          <div className="grid2">
            <Field label="Venditore">{inp('seller', { placeholder: 'Es. Enel Energia' })}</Field>
            <Field label="Nome dell’offerta">{inp('offer_name')}</Field>
          </div>
          <Seg label="Tipo di prezzo" value={part.price_type} onChange={(v) => onChange({ ...part, price_type: v })} options={[['fisso', 'Prezzo fisso'], ['variabile', 'Variabile (indicizzato)']]} />
          <div className="grid2">
            {part.price_type === 'fisso' ? (
              <Field label={`Prezzo ${part.commodity === 'gas' ? 'del gas' : 'dell’energia'} (€/${u})`} hint="Senza trasporto, oneri e imposte. Es. 0,145">{num('unit_price')}</Field>
            ) : (
              <Field label={`Spread sopra ${part.commodity === 'gas' ? 'PSV' : 'PUN'} (€/${u})`} hint="Quanto aggiunge il venditore all’indice. Es. 0,012">{num('spread')}</Field>
            )}
            <Field label="Quota fissa di vendita (€/anno)" hint="Se la bolletta la dà al mese, moltiplica per 12.">{num('fixed_year')}</Field>
          </div>
          <div className="grid2">
            <Field label={`Consumo annuo (${u})`} hint="Lo trovi in bolletta (“consumo annuo”). Vuoto: lo stimiamo dalle bollette.">{num('annual_use')}</Field>
            {part.commodity === 'luce'
              ? <Field label="Potenza impegnata (kW)">{num('power_kw')}</Field>
              : <Field label="PDR">{inp('code')}</Field>}
          </div>
          {part.commodity === 'luce' && <Field label="POD">{inp('code', { placeholder: 'IT001E…' })}</Field>}
          <ComuneSearch value={{ istat: part.istat, label: part.comune_label }} onPick={(c) => onChange({ ...part, istat: c.istat, comune_label: c.label })} />
          <label className="check">
            <input type="checkbox" checked={!!part.resident} onChange={set('resident')} />
            <span>È la casa dove ho la residenza</span>
          </label>
        </>
      )}
    </>
  );
}
