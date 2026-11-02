import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api.js';
import { Alert, ConsumptionChart, Icon, Loading, useLoad, Field } from '../ui.jsx';
import { PartForm, partFromReader, supplyBody, billBody } from '../forms.jsx';
import Compare from '../Compare.jsx';
import { euro, int, price, shortDate, unit } from '../util.js';

export default function Supply() {
  const { id } = useParams();
  const nav = useNavigate();
  const sup = useLoad(() => api(`/supplies/${id}`), [id]);
  const cmp = useLoad(() => api(`/supplies/${id}/compare`), [id]);
  const [tab, setTab] = useState('confronto');
  const [msg, setMsg] = useState({ kind: 'ok', text: '' });

  if (sup.loading && !sup.data) return <Loading />;
  if (sup.error) return <Alert>{sup.error}</Alert>;
  const { supply: s, bills } = sup.data;
  const u = unit(s.commodity);

  const reload = () => {
    sup.reload();
    cmp.reload();
  };

  async function remove() {
    if (!window.confirm(`Cancellare “${s.name}” e tutte le sue bollette?`)) return;
    await api(`/supplies/${id}`, { method: 'DELETE' });
    nav('/casa');
  }

  return (
    <>
      <div className="section-head">
        <h1 style={{ margin: 0 }}>{s.name}</h1>
        <span className={`badge ${s.commodity}`}>{s.commodity === 'gas' ? 'Gas' : 'Luce'}</span>
      </div>
      <p className="muted">
        {s.seller || 'Venditore non indicato'}{s.offer_name ? ` · ${s.offer_name}` : ''}
        {s.price_type === 'fisso' && s.unit_price != null && ` · ${price(s.unit_price)}/${u}`}
        {s.price_type === 'variabile' && s.spread != null && ` · indice + ${price(s.spread)}/${u}`}
        {s.fixed_year != null && ` · quota fissa ${euro(s.fixed_year)}/anno`}
      </p>
      <Alert kind={msg.kind}>{msg.text}</Alert>

      <div className="seg" role="tablist">
        {[['confronto', 'Confronto'], ['bollette', `Bollette (${bills.length})`], ['dati', 'Dati']].map(([k, t]) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>{t}</button>
        ))}
      </div>

      {tab === 'confronto' && (
        cmp.loading && !cmp.data ? <Loading text="Confronto con le offerte…" />
          : cmp.error ? (
            <div className="card">
              <Alert kind="warn">{cmp.error}</Alert>
              <Link className="btn primary" to={`/carica?fornitura=${s.id}`}>Carica una bolletta</Link>
            </div>
          ) : <Compare data={cmp.data} />
      )}

      {tab === 'bollette' && (
        <Bills s={s} bills={bills} onChange={reload} />
      )}

      {tab === 'dati' && (
        <SupplyEdit s={s} onSaved={() => { setMsg({ kind: 'ok', text: 'Dati salvati.' }); reload(); }} onRemove={remove} />
      )}
    </>
  );
}

function Bills({ s, bills, onChange }) {
  const [edit, setEdit] = useState(null);
  const [err, setErr] = useState('');
  const u = unit(s.commodity);

  async function del(b) {
    if (!window.confirm('Cancellare questa bolletta?')) return;
    await api(`/bills/${b.id}`, { method: 'DELETE' });
    onChange();
  }

  async function save(e) {
    e.preventDefault();
    setErr('');
    try {
      await api(`/bills/${edit.id}`, { method: 'PUT', body: billBody(edit) });
      setEdit(null);
      onChange();
    } catch (ex) {
      setErr(ex.message);
    }
  }

  if (edit) {
    return (
      <form className="card" onSubmit={save}>
        <h2>Bolletta {shortDate(edit.period_from)} – {shortDate(edit.period_to)}</h2>
        <Alert>{err}</Alert>
        <PartForm part={edit} onChange={setEdit} showSupply={false} fixedCommodity />
        <div className="row">
          <button className="btn primary">Salva</button>
          <button type="button" className="btn ghost" onClick={() => setEdit(null)}>Annulla</button>
        </div>
      </form>
    );
  }

  return (
    <>
      <div className="row" style={{ marginBottom: 12 }}>
        <Link className="btn primary" to={`/carica?fornitura=${s.id}`}><Icon name="upload" width="18" />Carica bolletta</Link>
      </div>
      {bills.length === 0 ? <div className="empty">Nessuna bolletta.</div> : (
        <>
          <div className="card"><ConsumptionChart bills={bills} commodity={s.commodity} /></div>
          <div className="card table-wrap" style={{ marginTop: 12 }}>
            <table className="list">
              <thead>
                <tr><th>Periodo</th><th className="r">Consumo</th><th className="r">Materia</th><th className="r">Totale</th><th /></tr>
              </thead>
              <tbody>
                {[...bills].reverse().map((b) => (
                  <tr key={b.id}>
                    <td>{shortDate(b.period_from)} – {shortDate(b.period_to)}{b.estimated && <span className="badge" style={{ marginLeft: 6 }}>stimato</span>}</td>
                    <td className="r num">{int(b.consumption)} {u}</td>
                    <td className="r num">{euro(b.energy_cost, 2)}</td>
                    <td className="r num">{euro(b.total, 2)}</td>
                    <td className="r">
                      <button type="button" className="btn small ghost" onClick={() => setEdit({ ...partFromReader({ ...b, commodity: s.commodity }), id: b.id, found: [] })}>Modifica</button>
                      <button type="button" className="btn small ghost" onClick={() => del(b)} aria-label="Cancella la bolletta">✕</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {s.annual_use_estimated && (
            <p className="note">Dalle bollette stimo un consumo di circa <b>{int(s.annual_use_estimated)} {u}</b> l’anno (tenendo conto delle stagioni).</p>
          )}
        </>
      )}
    </>
  );
}

function SupplyEdit({ s, onSaved, onRemove }) {
  const [part, setPart] = useState(() => ({
    ...partFromReader({ ...s, annual_use: s.annual_use_set, comune_label: s.comune ? s.comune : '' }),
    found: [],
  }));
  const [name, setName] = useState(s.name);
  const [err, setErr] = useState('');

  async function save(e) {
    e.preventDefault();
    setErr('');
    try {
      await api(`/supplies/${s.id}`, { method: 'PUT', body: { ...supplyBody(part), name } });
      onSaved();
    } catch (ex) {
      setErr(ex.message);
    }
  }

  return (
    <form className="card" onSubmit={save}>
      <Alert>{err}</Alert>
      <Field label="Nome"><input required maxLength={80} value={name} onChange={(e) => setName(e.target.value)} /></Field>
      <PartForm part={part} onChange={setPart} showBill={false} fixedCommodity />
      <div className="row between">
        <button className="btn primary">Salva</button>
        <button type="button" className="btn danger" onClick={onRemove}>Cancella fornitura</button>
      </div>
    </form>
  );
}
