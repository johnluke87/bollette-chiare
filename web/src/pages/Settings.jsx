import { useState } from 'react';
import { api } from '../api.js';
import { useApp } from '../App.jsx';
import { Alert, Field } from '../ui.jsx';
import Notifications from './Notifications.jsx';

export default function Settings() {
  const { user, setUser, logout } = useApp();
  const [profile, setProfile] = useState({ name: user.name, min_saving: user.min_saving });
  const [pw, setPw] = useState({ current: '', password: '' });
  const [msg, setMsg] = useState({ kind: 'ok', text: '' });

  const say = (text, kind = 'ok') => setMsg({ kind, text });

  async function saveProfile(e) {
    e.preventDefault();
    try {
      const r = await api('/me', { method: 'PUT', body: profile });
      setUser(r.user);
      say('Impostazioni salvate.');
    } catch (ex) {
      say(ex.message, 'error');
    }
  }

  async function savePassword(e) {
    e.preventDefault();
    try {
      await api('/me/password', { method: 'POST', body: pw });
      setPw({ current: '', password: '' });
      say('Password cambiata.');
    } catch (ex) {
      say(ex.message, 'error');
    }
  }

  async function exportJson() {
    try {
      const r = await api('/export');
      const blob = new Blob([JSON.stringify(r, null, 2)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'bollette-chiare.json';
      a.click();
      URL.revokeObjectURL(a.href);
    } catch (ex) {
      say(ex.message, 'error');
    }
  }

  async function deleteAccount() {
    if (!window.confirm('Cancellare l’account, le forniture e tutte le bollette? Non si può annullare.')) return;
    try {
      await api('/me', { method: 'DELETE' });
      logout();
    } catch (ex) {
      say(ex.message, 'error');
    }
  }

  return (
    <>
      <h1>Impostazioni</h1>
      <Alert kind={msg.kind}>{msg.text}</Alert>

      <Notifications />

      <form className="card" onSubmit={saveProfile}>
        <h2>Profilo e avvisi</h2>
        <Field label="Nome"><input required maxLength={80} value={profile.name} onChange={(e) => setProfile({ ...profile, name: e.target.value })} /></Field>
        <Field label="Email" hint={user.email_verified ? 'Confermata.' : 'Non ancora confermata: controlla la posta.'}>
          <input value={user.email} disabled />
        </Field>
        <Field label="Avvisami se posso risparmiare almeno (€ l’anno)" hint="Sotto questa cifra cambiare venditore non vale la fatica.">
          <input type="number" min={10} max={1000} step={10} value={profile.min_saving} onChange={(e) => setProfile({ ...profile, min_saving: e.target.value })} />
        </Field>
        <button className="btn primary">Salva</button>
      </form>

      <form className="card" onSubmit={savePassword}>
        <h2>Password</h2>
        <div className="grid2">
          <Field label="Attuale"><input type="password" autoComplete="current-password" required value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} /></Field>
          <Field label="Nuova"><input type="password" autoComplete="new-password" minLength={8} required value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} /></Field>
        </div>
        <button className="btn">Cambia password</button>
      </form>

      <div className="card">
        <h2>I tuoi dati</h2>
        <div className="row">
          <button type="button" className="btn" onClick={exportJson}>Esporta JSON</button>
          <button type="button" className="btn" onClick={logout}>Esci</button>
          <button type="button" className="btn danger" onClick={deleteAccount}>Cancella account</button>
        </div>
      </div>
    </>
  );
}
