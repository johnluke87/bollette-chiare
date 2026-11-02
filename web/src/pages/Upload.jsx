import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../api.js';
import { useApp } from '../App.jsx';
import { Alert, Field, Icon, Loading } from '../ui.jsx';
import { PartForm, emptyPart, partFromReader, supplyBody, billBody } from '../forms.jsx';
import { pdfText } from '../pdf.js';
import Compare from '../Compare.jsx';

/**
 * Carica una bolletta (PDF) o scrivila a mano. Con l'account si salva sulla fornitura;
 * senza account si vede subito il confronto e niente resta sul server.
 */
export default function Upload() {
  const { user, config } = useApp();
  const nav = useNavigate();
  const [params] = useSearchParams();
  const [step, setStep] = useState('pick');
  const [parts, setParts] = useState([]);
  const [err, setErr] = useState('');
  const [over, setOver] = useState(false);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);
  const [supplies, setSupplies] = useState([]);
  const [target, setTarget] = useState({});
  const input = useRef(null);

  useEffect(() => {
    if (!user) return;
    api('/supplies').then((r) => setSupplies(r.supplies)).catch(() => {});
  }, [user]);

  useEffect(() => {
    if (params.get('mano')) manual(params.get('mano') === 'gas' ? 'gas' : 'luce');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // a quale fornitura va ogni parte: quella con lo stesso POD/PDR, altrimenti l'unica dello stesso tipo
  useEffect(() => {
    const t = {};
    parts.forEach((p, i) => {
      const same = supplies.filter((s) => s.commodity === p.commodity);
      const byCode = p.code && same.find((s) => s.code && s.code === p.code.replace(/\s/g, '').toUpperCase());
      const sid = params.get('fornitura');
      const fromUrl = sid && same.find((s) => String(s.id) === sid);
      t[i] = String((byCode || fromUrl || (same.length === 1 ? same[0] : null))?.id || 'new');
    });
    setTarget(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [parts.length, supplies]);

  function manual(commodity = 'luce') {
    setParts([emptyPart(commodity)]);
    setErr('');
    setStep('review');
  }

  async function read(file) {
    if (!file) return;
    setErr('');
    if (file.type && file.type !== 'application/pdf') {
      setErr('Serve il PDF della bolletta (quello che scarichi dall’area clienti o ricevi per email).');
      return;
    }
    setStep('reading');
    try {
      const text = await pdfText(file);
      const r = await api('/bills/read', { method: 'POST', body: { text } });
      setParts(r.parts.map(partFromReader));
      setStep('review');
    } catch (ex) {
      setErr(ex.message || 'Non riesco ad aprire il PDF.');
      setParts([emptyPart()]);
      setStep('review');
    }
  }

  async function save(e) {
    e.preventDefault();
    setBusy(true);
    setErr('');
    try {
      if (!user) {
        const p = parts[0];
        const r = await api('/compare', { method: 'POST', body: { ...supplyBody(p), bill: billBody(p) } });
        setResult(r);
        setStep('result');
        window.scrollTo(0, 0);
      } else {
        let last = null;
        for (const [i, p] of parts.entries()) {
          const sid = target[i] && target[i] !== 'new' ? Number(target[i]) : 0;
          last = await api('/bills', {
            method: 'POST',
            body: { supply_id: sid, supply: supplyBody(p), update_offer: sid ? p.updateOffer !== false : false, bill: billBody(p) },
          });
        }
        nav(parts.length === 1 ? `/fornitura/${last.supply_id}` : '/casa');
      }
    } catch (ex) {
      setErr(ex.message);
    }
    setBusy(false);
  }

  if (step === 'result') {
    return (
      <main className="main medium">
        <h1>Il tuo confronto</h1>
        <Compare data={result} />
        <div className="card" style={{ marginTop: 20 }}>
          <h2>Vuoi che ci pensiamo noi?</h2>
          <p>Crea un account gratuito: salviamo le tue bollette, vedi i consumi nel tempo e ti avvisiamo quando esce un’offerta che ti fa risparmiare.</p>
          <div className="row">
            <Link className="btn primary" to="/registrati">Crea l’account</Link>
            <button type="button" className="btn" onClick={() => setStep('review')}>Correggi i dati</button>
          </div>
        </div>
      </main>
    );
  }

  const Wrap = user ? 'div' : 'main'; // dentro l'app il <main> c'è già
  return (
    <Wrap className={user ? undefined : 'main medium'}>
      <h1>{step === 'review' && parts[0]?.found ? 'Controlla i dati letti' : 'Carica una bolletta'}</h1>

      {step === 'pick' && (
        <>
          <label
            className={`drop${over ? ' over' : ''}`}
            onDragOver={(e) => { e.preventDefault(); setOver(true); }}
            onDragLeave={() => setOver(false)}
            onDrop={(e) => { e.preventDefault(); setOver(false); read(e.dataTransfer.files[0]); }}
          >
            <Icon name="upload" />
            <b>Trascina qui il PDF della bolletta</b>
            <span className="muted">oppure tocca per sceglierlo</span>
            <input ref={input} type="file" accept="application/pdf,.pdf" onChange={(e) => read(e.target.files[0])} />
          </label>
          <Alert>{err}</Alert>
          {config?.reader === false && <Alert kind="warn">La lettura automatica oggi non è attiva: puoi inserire i dati a mano.</Alert>}
          <p className="note" style={{ marginTop: 12 }}>
            Il PDF resta nel tuo dispositivo: al server mandiamo solo il testo, che un modello di intelligenza artificiale (Groq) trasforma nei dati da controllare.
            Non lo salviamo.
          </p>
          <p className="row">
            <button type="button" className="btn" onClick={() => manual('luce')}>Inserisci a mano la luce</button>
            <button type="button" className="btn" onClick={() => manual('gas')}>Inserisci a mano il gas</button>
          </p>
        </>
      )}

      {step === 'reading' && (
        <div className="card"><Loading text="Leggo la bolletta… (qualche secondo)" /></div>
      )}

      {step === 'review' && (
        <form onSubmit={save}>
          <Alert>{err}</Alert>
          {parts[0]?.found && (
            <Alert kind="info">I campi evidenziati li ho letti dalla bolletta: controllali e completa quelli vuoti.</Alert>
          )}
          {parts.map((p, i) => (
            <div key={i} className={`card part ${p.commodity}`} style={{ marginBottom: 12 }}>
              {parts.length > 1 && <h2>{p.commodity === 'gas' ? 'Gas' : 'Luce'}</h2>}
              {user && (
                <>
                  <Field label="Fornitura">
                    <select value={target[i] || 'new'} onChange={(e) => setTarget({ ...target, [i]: e.target.value })}>
                      {supplies.filter((s) => s.commodity === p.commodity).map((s) => (
                        <option key={s.id} value={s.id}>{s.name}{s.code ? ` · ${s.code}` : ''}</option>
                      ))}
                      <option value="new">Nuova fornitura</option>
                    </select>
                  </Field>
                  {target[i] && target[i] !== 'new' && (
                    <label className="check">
                      <input type="checkbox" checked={p.updateOffer !== false}
                        onChange={(e) => setParts(parts.map((x, j) => (j === i ? { ...x, updateOffer: e.target.checked } : x)))} />
                      <span>Aggiorna i prezzi della mia offerta con quelli di questa bolletta</span>
                    </label>
                  )}
                </>
              )}
              <PartForm part={p} fixedCommodity={!!p.found} onChange={(np) => setParts(parts.map((x, j) => (j === i ? np : x)))} />
            </div>
          ))}
          <div className="row">
            <button className="btn primary" disabled={busy}>{busy ? 'Un attimo…' : user ? 'Salva la bolletta' : 'Confronta le offerte'}</button>
            <button type="button" className="btn ghost" onClick={() => { setStep('pick'); setErr(''); }}>Ricomincia</button>
          </div>
        </form>
      )}
    </Wrap>
  );
}
