import { useState } from 'react';
import { Seg } from './ui.jsx';
import { euro, price, int, unit, monthLabel, formatDate } from './util.js';

const IDX_LABEL = { PUN: 'PUN', PSV: 'PSV', Psbil: 'PSV bil.', PE: 'PE', CMEM: 'CMEM', Pfor: 'Pfor' };

/** Risultato del confronto: risparmio, offerta attuale e la classifica delle offerte. */
export default function Compare({ data }) {
  const [kind, setKind] = useState('tutte');
  const [showAll, setShowAll] = useState(false);
  const u = unit(data.commodity);
  const cur = data.current;
  const best = data.offers[0];
  const list = data.offers.filter((o) => kind === 'tutte' || o.price_type === kind || (kind === 'verde' && o.green));
  const shown = showAll ? list : list.slice(0, 10);
  const saving = data.best_saving;

  return (
    <>
      <div className={`card big-saving${saving != null && saving > 0 ? '' : ' none'}`}>
        {cur == null ? (
          <>
            <div className="muted">L’offerta più conveniente costa</div>
            <div className="amount">{euro(best?.cost_vat)}<small>l’anno</small></div>
            <p className="muted" style={{ marginTop: 8 }}>Indica il prezzo che paghi oggi (o carica una bolletta) per sapere quanto risparmi.</p>
          </>
        ) : saving > 0 ? (
          <>
            <div className="muted">Puoi risparmiare fino a</div>
            <div className="amount"><span className="hl swipe">{euro(saving)}</span><small>l’anno</small></div>
            <p className="muted" style={{ marginTop: 8 }}>
              Oggi spendi circa <b>{euro(cur.cost_vat)}</b> l’anno per la parte che dipende dal venditore; la migliore offerta costa {euro(best.cost_vat)}.
            </p>
          </>
        ) : (
          <>
            <div className="muted">Ottimo:</div>
            <div className="amount">la tua offerta è tra le migliori</div>
            <p className="muted" style={{ marginTop: 8 }}>Spendi circa {euro(cur.cost_vat)} l’anno: nessuna delle {int(data.count)} offerte fa meglio di te.</p>
          </>
        )}
        <p className="note">
          Su {int(data.annual_use)} {u} l’anno{data.commodity === 'luce' ? ` (F1 ${Math.round(data.split[0] * 100)}%, F2 ${Math.round(data.split[1] * 100)}%, F3 ${Math.round(data.split[2] * 100)}%)` : ''} · IVA compresa
          {cur && !cur.fixed_known && ' · manca la tua quota fissa: il risparmio potrebbe essere più basso'}
        </p>
      </div>

      <div className="section">
        <div className="section-head">
          <h2>Le offerte più convenienti</h2>
          <span className="muted">{int(list.length < data.offers.length ? list.length : data.count)} offerte adatte a te</span>
        </div>
        <Seg label="Tipo di prezzo" value={kind} onChange={(v) => { setKind(v); setShowAll(false); }}
          options={[['tutte', 'Tutte'], ['fisso', 'Fisso'], ['variabile', 'Variabile'], ['verde', 'Verdi']]} />
        {data.only_national && <div className="alert info">Senza il comune vedi solo le offerte valide in tutta Italia.</div>}
        {shown.length === 0 ? (
          <div className="empty">Nessuna offerta di questo tipo tra le prime {data.offers.length}.</div>
        ) : (
          <ol className="offers">
            {shown.map((o, i) => <Offer key={o.code} o={o} rank={i + 1} u={u} />)}
          </ol>
        )}
        {!showAll && list.length > 10 && (
          <button type="button" className="btn block" style={{ marginTop: 12 }} onClick={() => setShowAll(true)}>Mostra altre {list.length - 10}</button>
        )}
      </div>

      <div className="section note">
        <p>
          <b>Come calcoliamo.</b> Contiamo solo la parte della bolletta che cambia da un venditore all’altro: prezzo dell’energia o del gas,
          quote fisse di vendita e sconti senza condizioni. Trasporto, oneri di sistema e accise sono uguali per tutti. Per le offerte variabili usiamo
          l’ultimo indice pubblicato ({monthLabel(data.index.month)}: PUN {price(data.index.pun)}/kWh, PSV {price(data.index.psv)}/Smc), che però cambierà.
        </p>
        <p>
          Offerte dal Portale Offerte di ARERA – Acquirente Unico{data.updated ? `, aggiornate il ${formatDate(data.updated)}` : ''}. Prima di firmare leggi sempre
          le condizioni sul sito del venditore o su <a href="https://www.ilportaleofferte.it/" target="_blank" rel="noreferrer">ilportaleofferte.it</a>.
        </p>
      </div>
    </>
  );
}

function Offer({ o, rank, u }) {
  return (
    <li className="card offer">
      <span className="rank" aria-label={`${rank}° posto`}>{rank}</span>
      <div>
        <div className="who">{o.seller}</div>
        <div className="name">{o.name}</div>
      </div>
      <div className="cost">
        <b className="num">{euro(o.cost_vat)}</b>
        <span className="muted" style={{ fontSize: '0.8rem' }}>l’anno</span>
        {o.saving > 0 && <div><span className="badge save">−{euro(o.saving)}</span></div>}
      </div>
      <div className="tags">
        <span className="badge">{o.price_type === 'fisso' ? `Prezzo fisso${o.duration ? ` ${o.duration} mesi` : ''}` : `Variabile ${IDX_LABEL[o.idx] || ''}`}</span>
        {o.placet && <span className="badge">PLACET</span>}
        {o.green && <span className="badge green">Energia verde</span>}
        {o.local && <span className="badge">Solo nella tua zona</span>}
      </div>
      <div className="detail">
        {o.price_type === 'fisso' ? `${price(o.unit_price)}/${u}` : `indice + ${price(o.spread)}/${u} (oggi ${price(o.unit_price)}/${u})`}
        {' · '}quota fissa {euro(o.fixed_year)}/anno
        {o.discount_year > 0 && ` · sconto ${euro(o.discount_year)}`}
        {o.discount_unit > 0 && ` · sconto ${price(o.discount_unit)}/${u}${o.discount_cap ? ` sui primi ${int(o.discount_cap)} ${u}` : ''}`}
        {o.valid_to && ` · sottoscrivibile fino al ${formatDate(o.valid_to)}`}
        {o.url && <> · <a href={o.url} target="_blank" rel="noreferrer">sito dell’offerta</a></>}
      </div>
    </li>
  );
}
