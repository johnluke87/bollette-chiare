import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { Routes, Route, Navigate, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { api, getToken, setToken } from './api.js';
import { Brand, Icon, Loading } from './ui.jsx';
import Landing from './pages/Landing.jsx';
import { Login, Register, Verify, Forgot, Reset } from './pages/Auth.jsx';
import Home from './pages/Home.jsx';
import Upload from './pages/Upload.jsx';
import Supply from './pages/Supply.jsx';
import Settings from './pages/Settings.jsx';
import Privacy from './pages/Privacy.jsx';

const Ctx = createContext(null);
export const useApp = () => useContext(Ctx);

export default function App() {
  const [user, setUser] = useState(null);
  const [config, setConfig] = useState(null);
  const [booting, setBooting] = useState(true);
  const navigate = useNavigate();

  useEffect(() => {
    const cfg = api('/config').then(setConfig).catch(() => setConfig({}));
    const me = getToken()
      ? api('/me').then((r) => setUser(r.user)).catch(() => setUser(null))
      : Promise.resolve();
    Promise.all([cfg, me]).finally(() => setBooting(false));
  }, []);

  const logout = useCallback(() => {
    setToken(null);
    setUser(null);
    navigate('/entra');
  }, [navigate]);

  useEffect(() => {
    const onLogout = () => setUser(null);
    window.addEventListener('bc:logout', onLogout);
    return () => window.removeEventListener('bc:logout', onLogout);
  }, []);

  const login = useCallback((token, u) => {
    setToken(token);
    setUser(u);
  }, []);

  if (booting) {
    return <main className="main narrow"><Loading /></main>;
  }

  return (
    <Ctx.Provider value={{ user, setUser, config, login, logout }}>
      <Routes>
        <Route element={<PublicShell />}>
          <Route path="/" element={user ? <Navigate to="/casa" replace /> : <Landing />} />
          <Route path="/prova" element={user ? <Navigate to="/carica" replace /> : <Upload />} />
          <Route path="/entra" element={user ? <Navigate to="/casa" replace /> : <Login />} />
          <Route path="/registrati" element={user ? <Navigate to="/casa" replace /> : <Register />} />
          <Route path="/verifica/:token" element={<Verify />} />
          <Route path="/password" element={<Forgot />} />
          <Route path="/reset/:token" element={<Reset />} />
          <Route path="/privacy" element={<Privacy />} />
        </Route>
        <Route element={<RequireUser />}>
          <Route element={<AppShell />}>
            <Route path="/casa" element={<Home />} />
            <Route path="/carica" element={<Upload />} />
            <Route path="/fornitura/:id" element={<Supply />} />
            <Route path="/impostazioni" element={<Settings />} />
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Ctx.Provider>
  );
}

function RequireUser() {
  const { user } = useApp();
  const loc = useLocation();
  if (!user) return <Navigate to="/entra" replace state={{ from: loc.pathname }} />;
  return <Outlet />;
}

function PublicShell() {
  const { user } = useApp();
  return (
    <div className="shell">
      <header className="topbar">
        <Brand />
        <nav className="row">
          {user ? (
            <NavLink className="btn small primary" to="/casa">Apri l’app</NavLink>
          ) : (
            <>
              <NavLink className="btn small ghost" to="/entra">Entra</NavLink>
              <NavLink className="btn small primary" to="/registrati">Registrati</NavLink>
            </>
          )}
        </nav>
      </header>
      <Outlet />
      <Footer />
    </div>
  );
}

const NAV = [
  ['/casa', 'home', 'Casa'],
  ['/carica', 'upload', 'Carica'],
  ['/impostazioni', 'settings', 'Impostazioni'],
];

function AppShell() {
  const { user, logout } = useApp();
  return (
    <div className="shell app">
      <nav className="sidenav" aria-label="Menu principale">
        <Brand to="/casa" />
        {NAV.map(([to, icon, label]) => (
          <NavLink key={to} to={to} className="nav"><Icon name={icon} />{label}</NavLink>
        ))}
        <div className="spacer" />
        <button type="button" className="btn ghost small" onClick={logout}><Icon name="logout" width="18" />Esci</button>
      </nav>
      <div className="col">
        <header className="topbar">
          <Brand to="/casa" />
          <span className="muted" style={{ fontSize: '0.9rem' }}>{user.name}</span>
        </header>
        {!user.email_verified && (
          <div className="alert warn" style={{ margin: '12px 16px 0', borderRadius: 10 }}>
            Conferma la tua email per ricevere gli avvisi sulle offerte: ti abbiamo mandato un link a <b>{user.email}</b>.
          </div>
        )}
        <main className="main" id="contenuto">
          <Outlet />
        </main>
        <Footer />
      </div>
      <nav className="bottomnav" aria-label="Menu" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
        {NAV.map(([to, icon, label]) => (
          <NavLink key={to} to={to}><Icon name={icon} />{label}</NavLink>
        ))}
      </nav>
    </div>
  );
}

// Caffè, "scrivimi" e preferenze cookie li dà il pulsante comune di supporto/tip.js
function Footer() {
  return (
    <footer className="footer">
      Bollette Chiare · gratis, senza pubblicità e senza commissioni · dati del Portale Offerte ARERA ·{' '}
      <NavLink to="/privacy">Privacy</NavLink>
    </footer>
  );
}
