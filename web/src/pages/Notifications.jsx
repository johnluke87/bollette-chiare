import { useEffect, useRef, useState } from 'react';
import { api } from '../api.js';
import { useApp } from '../App.jsx';
import { Alert } from '../ui.jsx';

/** Dove arrivano gli avvisi sulle offerte: email, Telegram o tutti e due. */
export default function Notifications() {
  const { user, setUser, config } = useApp();
  const [err, setErr] = useState('');
  const [waiting, setWaiting] = useState(false);
  const poll = useRef(null);
  const bot = config.telegram_bot;

  useEffect(() => () => clearInterval(poll.current), []);
  useEffect(() => {
    if (user.telegram_linked && waiting) {
      clearInterval(poll.current);
      setWaiting(false);
    }
  }, [user.telegram_linked, waiting]);

  async function toggle(key, value) {
    setErr('');
    try {
      const r = await api('/me', { method: 'PUT', body: { [key]: value } });
      setUser(r.user);
    } catch (ex) {
      setErr(ex.message);
    }
  }

  async function link() {
    setErr('');
    // la finestra si apre subito (al clic), poi le diamo l'indirizzo: così i browser non la bloccano
    const win = window.open('', '_blank');
    try {
      const r = await api('/me/telegram', { method: 'POST' });
      if (win) win.location.href = r.url;
      else window.location.href = r.url;
      setWaiting(true);
      // il collegamento lo fa il bot: controllo ogni 3 secondi per 5 minuti
      let n = 0;
      clearInterval(poll.current);
      poll.current = setInterval(async () => {
        n += 1;
        if (n > 100) {
          clearInterval(poll.current);
          setWaiting(false);
          return;
        }
        try {
          const me = await api('/me');
          if (me.user.telegram_linked) setUser(me.user);
        } catch {
          /* riprova al giro dopo */
        }
      }, 3000);
    } catch (ex) {
      if (win) win.close();
      setErr(ex.message);
    }
  }

  async function unlink() {
    if (!window.confirm('Scollegare Telegram? Gli avvisi non arriveranno più lì.')) return;
    try {
      const r = await api('/me/telegram', { method: 'DELETE' });
      setUser(r.user);
    } catch (ex) {
      setErr(ex.message);
    }
  }

  return (
    <div className="card">
      <h2>Notifiche</h2>
      <p className="muted">Ti scriviamo quando esce un’offerta che ti fa risparmiare almeno la soglia che scegli (al massimo una volta a settimana per fornitura).</p>
      <Alert>{err}</Alert>

      <label className="check">
        <input type="checkbox" checked={user.notify_email} onChange={(e) => toggle('notify_email', e.target.checked)} />
        <span>
          <b>Email</b> a {user.email}
          {!user.email_verified && <><br /><small className="muted">Prima conferma l’email con il link che ti abbiamo mandato.</small></>}
        </span>
      </label>

      <div style={{ borderTop: '1px solid var(--border)', paddingTop: 12 }}>
        {!bot ? (
          <p className="muted" style={{ margin: 0 }}><b>Telegram</b>: non ancora attivo.</p>
        ) : user.telegram_linked ? (
          <>
            <label className="check">
              <input type="checkbox" checked={user.notify_telegram} onChange={(e) => toggle('notify_telegram', e.target.checked)} />
              <span><b>Telegram</b> · collegato a @{bot} ✓</span>
            </label>
            <button type="button" className="btn small" onClick={unlink}>Scollega Telegram</button>
          </>
        ) : (
          <>
            <p style={{ marginBottom: 10 }}>
              <b>Telegram</b>: ricevi gli avvisi anche in chat con @{bot}.
            </p>
            <button type="button" className="btn primary" onClick={link}>Collega Telegram</button>
            {waiting && (
              <Alert kind="info">
                Nella chat con il bot premi <b>Avvia</b> (o <b>Start</b>). Questa pagina si aggiorna da sola appena è collegato.
              </Alert>
            )}
          </>
        )}
      </div>
    </div>
  );
}
