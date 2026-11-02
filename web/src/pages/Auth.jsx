import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api.js';
import { useApp } from '../App.jsx';
import { Alert, Field } from '../ui.jsx';

function useForm(initial) {
  const [v, setV] = useState(initial);
  const bind = (k) => ({
    value: v[k],
    onChange: (e) => setV((s) => ({ ...s, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })),
  });
  return [v, bind, setV];
}

export function Login() {
  const { login } = useApp();
  const nav = useNavigate();
  const loc = useLocation();
  const [v, bind] = useForm({ email: '', password: '' });
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setErr('');
    try {
      const r = await api('/auth/login', { method: 'POST', body: v });
      login(r.token, r.user);
      nav(loc.state?.from || '/casa', { replace: true });
    } catch (ex) {
      setErr(ex.message);
      setBusy(false);
    }
  }

  return (
    <main className="main narrow">
      <h1>Entra</h1>
      <form className="card" onSubmit={submit}>
        <Alert>{err}</Alert>
        <Field label="Email"><input type="email" autoComplete="email" required {...bind('email')} /></Field>
        <Field label="Password"><input type="password" autoComplete="current-password" required {...bind('password')} /></Field>
        <button className="btn primary block" disabled={busy}>{busy ? 'Accesso…' : 'Entra'}</button>
        <p style={{ marginTop: 14, marginBottom: 0 }}>
          <Link to="/password">Password dimenticata?</Link> · <Link to="/registrati">Crea un account</Link>
        </p>
      </form>
    </main>
  );
}

export function Register() {
  const { login } = useApp();
  const nav = useNavigate();
  const [v, bind] = useForm({ name: '', email: '', password: '', privacy: false });
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setErr('');
    try {
      const r = await api('/auth/register', { method: 'POST', body: v });
      if (r.dev_verify_url) console.info('Link di verifica (sviluppo):', r.dev_verify_url);
      login(r.token, r.user);
      nav('/casa', { replace: true });
    } catch (ex) {
      setErr(ex.message);
      setBusy(false);
    }
  }

  return (
    <main className="main narrow">
      <h1>Crea il tuo account</h1>
      <form className="card" onSubmit={submit}>
        <Alert>{err}</Alert>
        <Field label="Come ti chiami"><input autoComplete="given-name" required maxLength={80} {...bind('name')} /></Field>
        <Field label="Email" hint="Ti mandiamo un link per confermarla: serve per gli avvisi sulle offerte.">
          <input type="email" autoComplete="email" required {...bind('email')} />
        </Field>
        <Field label="Password" hint="Almeno 8 caratteri.">
          <input type="password" autoComplete="new-password" minLength={8} required {...bind('password')} />
        </Field>
        <label className="check">
          <input type="checkbox" required checked={v.privacy} onChange={bind('privacy').onChange} />
          <span>Ho letto l’<Link to="/privacy" target="_blank">informativa privacy</Link> e acconsento al trattamento dei dati per il servizio.</span>
        </label>
        <button className="btn primary block" disabled={busy}>{busy ? 'Creazione…' : 'Registrati'}</button>
        <p style={{ marginTop: 14, marginBottom: 0 }}>Hai già un account? <Link to="/entra">Entra</Link></p>
      </form>
    </main>
  );
}

export function Verify() {
  const { token } = useParams();
  const { user, setUser } = useApp();
  const [state, setState] = useState({ ok: false, err: '', done: false });
  const sent = useRef(false);

  useEffect(() => {
    if (sent.current) return;
    sent.current = true;
    api('/auth/verify', { method: 'POST', body: { token } })
      .then(() => {
        setState({ ok: true, err: '', done: true });
        if (user) setUser({ ...user, email_verified: true });
      })
      .catch((e) => setState({ ok: false, err: e.message, done: true }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  return (
    <main className="main narrow">
      <h1>Verifica email</h1>
      <div className="card">
        {!state.done && <p role="status">Verifica in corso…</p>}
        {state.ok && <Alert kind="ok">Email confermata. Da ora ricevi gli avvisi quando c’è un’offerta migliore.</Alert>}
        <Alert>{state.err}</Alert>
        {state.done && <Link className="btn primary" to={user ? '/casa' : '/entra'}>Continua</Link>}
      </div>
    </main>
  );
}

export function Forgot() {
  const [email, setEmail] = useState('');
  const [msg, setMsg] = useState('');
  const [err, setErr] = useState('');
  const [devUrl, setDevUrl] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setErr('');
    try {
      const r = await api('/auth/forgot', { method: 'POST', body: { email } });
      setMsg('Se l’email è registrata, ti abbiamo mandato un link valido 60 minuti.');
      if (r.dev_reset_url) setDevUrl(r.dev_reset_url);
    } catch (ex) {
      setErr(ex.message);
    }
    setBusy(false);
  }

  return (
    <main className="main narrow">
      <h1>Password dimenticata</h1>
      <form className="card" onSubmit={submit}>
        <Alert>{err}</Alert>
        <Alert kind="ok">{msg}</Alert>
        {devUrl && <Alert kind="info">Sviluppo: <a href={devUrl}>apri il link di reset</a></Alert>}
        <Field label="Email"><input type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
        <button className="btn primary block" disabled={busy}>Mandami il link</button>
        <p style={{ marginTop: 14, marginBottom: 0 }}><Link to="/entra">Torna all’accesso</Link></p>
      </form>
    </main>
  );
}

export function Reset() {
  const { token } = useParams();
  const { logout } = useApp();
  const [password, setPassword] = useState('');
  const [ok, setOk] = useState(false);
  const [err, setErr] = useState('');

  async function submit(e) {
    e.preventDefault();
    setErr('');
    try {
      await api('/auth/reset', { method: 'POST', body: { token, password } });
      setOk(true);
    } catch (ex) {
      setErr(ex.message);
    }
  }

  return (
    <main className="main narrow">
      <h1>Nuova password</h1>
      <form className="card" onSubmit={submit}>
        <Alert>{err}</Alert>
        {ok ? (
          <>
            <Alert kind="ok">Password aggiornata. Le altre sessioni sono state chiuse.</Alert>
            <button type="button" className="btn primary" onClick={logout}>Entra</button>
          </>
        ) : (
          <>
            <Field label="Nuova password" hint="Almeno 8 caratteri.">
              <input type="password" autoComplete="new-password" minLength={8} required value={password} onChange={(e) => setPassword(e.target.value)} />
            </Field>
            <button className="btn primary block">Salva</button>
          </>
        )}
      </form>
    </main>
  );
}
