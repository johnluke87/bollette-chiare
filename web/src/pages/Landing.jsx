import { Link } from 'react-router-dom';
import { useApp } from '../App.jsx';
import { Icon } from '../ui.jsx';
import { int, formatDate } from '../util.js';

const FEATURES = [
  ['doc', 'Legge la bolletta per te', 'Carichi il PDF: consumi, fasce, prezzo e quota fissa li tiro fuori io. Tu controlli e basta.'],
  ['compare', 'Tutte le offerte, non le solite tre', 'Ogni notte scarico le offerte del Portale Offerte di ARERA, compresi i PLACET, e le metto in fila per quello che spendi tu.'],
  ['bell', 'Ti avviso io', 'Con l’account guardo il mercato ogni giorno e ti scrivo (email o Telegram) quando un’offerta ti fa risparmiare davvero.'],
];

export default function Landing() {
  const { config } = useApp();
  const off = config?.offers;
  return (
    <main className="main">
      <section className="hero">
        <div>
          <h1>Stai pagando troppo la luce e il gas?</h1>
          <p className="lead">
            Carica la bolletta: la leggo, la confronto con {off?.luce ? `${int(off.luce + off.gas)} offerte` : 'tutte le offerte'} del mercato
            e ti dico quanto risparmi e con chi. Gratis, senza pubblicità, senza venderti niente.
          </p>
          <div className="row" style={{ marginTop: 24 }}>
            <Link to="/prova" className="btn primary"><Icon name="upload" width="18" />Carica la bolletta</Link>
            <Link to="/prova?mano=luce" className="btn">Inserisci i dati a mano</Link>
          </div>
          <p className="note" style={{ marginTop: 14 }}>Per provare non serve l’account e non salviamo niente.</p>
        </div>
        <BillSample />
      </section>

      <section className="features">
        {FEATURES.map(([icon, t, d]) => (
          <div key={t}>
            <Icon name={icon} />
            <h3>{t}</h3>
            <p className="muted" style={{ margin: 0 }}>{d}</p>
          </div>
        ))}
      </section>

      <section className="honest">
        <h2>Onesto per costruzione</h2>
        <p className="muted" style={{ margin: 0 }}>
          Non prendo commissioni dai venditori, quindi la classifica è solo per prezzo. Confronto la parte della bolletta che cambia
          da un venditore all’altro (energia, quote di vendita, sconti senza condizioni), IVA compresa: trasporto e oneri sono uguali per tutti.
          {off?.updated_luce && ` Offerte aggiornate il ${formatDate(off.updated_luce)}.`}
        </p>
      </section>
    </main>
  );
}

/** Un pezzo di bolletta inventato: mostra quale riga confrontiamo. */
function BillSample() {
  return (
    <figure className="bill" style={{ margin: 0 }} aria-label="Esempio di bolletta della luce">
      <span className="bill-tag">Esempio</span>
      <div className="bill-head"><b>Bolletta luce</b><span>luglio – agosto</span></div>
      <div className="bill-row"><span className="hl swipe">Spesa per la materia energia</span><span className="hl swipe">186,40 €</span></div>
      <div className="bill-row"><span>Trasporto e gestione del contatore</span><span>48,10 €</span></div>
      <div className="bill-row"><span>Oneri di sistema</span><span>22,75 €</span></div>
      <div className="bill-row"><span>Imposte e IVA</span><span>31,20 €</span></div>
      <div className="bill-row total"><span>Totale da pagare</span><span>288,45 €</span></div>
      <figcaption className="bill-note">
        La riga evidenziata dipende dal venditore. Con l’offerta migliore paghi <b>209 €</b> in meno l’anno.
      </figcaption>
    </figure>
  );
}
