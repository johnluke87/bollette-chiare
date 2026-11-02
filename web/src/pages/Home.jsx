import { Link } from 'react-router-dom';
import { api } from '../api.js';
import { useApp } from '../App.jsx';
import { Alert, Icon, Loading, useLoad } from '../ui.jsx';
import { euro, int, unit, formatDate, monthLabel, price } from '../util.js';

export default function Home() {
  const { user, config } = useApp();
  const { data, error, loading } = useLoad(() => api('/supplies'));
  const off = config?.offers;

  return (
    <>
      <div className="section-head">
        <h1 style={{ margin: 0 }}>Ciao {user.name}</h1>
        <Link className="btn primary" to="/carica"><Icon name="upload" width="18" />Carica bolletta</Link>
      </div>
      <Alert>{error}</Alert>
      {loading && !data ? <Loading /> : data && (
        data.supplies.length === 0 ? (
          <div className="card" style={{ marginTop: 16 }}>
            <h2>Cominciamo dalla prima bolletta</h2>
            <p>Carica il PDF di una bolletta di luce o gas: leggo consumi e prezzi, poi la confronto con tutte le offerte del mercato.</p>
            <div className="row">
              <Link className="btn primary" to="/carica">Carica il PDF</Link>
              <Link className="btn" to="/carica?mano=luce">Inserisci a mano</Link>
            </div>
          </div>
        ) : (
          <div className="supplies" style={{ marginTop: 16 }}>
            {data.supplies.map((s) => <SupplyCard key={s.id} s={s} />)}
          </div>
        )
      )}
      {off && (
        <p className="note section">
          Nel confronto: {int(off.luce)} offerte luce e {int(off.gas)} gas del Portale Offerte ARERA
          {off.updated_luce ? `, aggiornate il ${formatDate(off.updated_luce)}` : ''}.
          {off.index_month && ` Indici di ${monthLabel(off.index_month)}: PUN ${price(off.pun)}/kWh, PSV ${price(off.psv)}/Smc.`}
        </p>
      )}
    </>
  );
}

function SupplyCard({ s }) {
  return (
    <Link to={`/fornitura/${s.id}`} className={`card supply ${s.commodity}`}>
      <div className="kind">
        <Icon name={s.commodity} />
        <h3>{s.name}</h3>
        <span className={`badge ${s.commodity}`}>{s.commodity === 'gas' ? 'Gas' : 'Luce'}</span>
      </div>
      <dl className="kv">
        <dt>Venditore</dt><dd>{s.seller || '—'}{s.offer_name ? ` · ${s.offer_name}` : ''}</dd>
        <dt>Consumo annuo</dt><dd>{s.annual_use ? `${int(s.annual_use)} ${unit(s.commodity)}` : 'serve una bolletta'}</dd>
        <dt>Bollette</dt><dd>{s.bills_count}{s.last_bill ? ` · ultima fino al ${formatDate(s.last_bill.period_to)}` : ''}</dd>
      </dl>
      {s.best_saving != null && (
        s.best_saving > 0
          ? <div className="saving"><Icon name="compare" width="22" />Puoi risparmiare <b>{euro(s.best_saving)}</b> l’anno</div>
          : <div className="saving none">La tua offerta è tra le migliori</div>
      )}
      {s.best_saving == null && s.annual_use && <div className="saving none">Apri per vedere il confronto</div>}
    </Link>
  );
}
