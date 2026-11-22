'use client';

import { ArrowLeft, PenLine } from 'lucide-react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { api } from './model';

export function DailyShell({ view, locked, children }: { view: 'calendar' | 'log'; locked: boolean; children: ReactNode }) {
  const isLog = view === 'log';
  return (
    <main className={`daily-shell ${isLog ? 'daily-narrow' : ''}`}>
      {(isLog || locked) && <nav className="daily-site-nav" aria-label="Habit">
        <a className="daily-home" href={isLog ? '/daily' : '/log'}>
          {isLog ? <><ArrowLeft aria-hidden="true" /> Habit</> : <><PenLine aria-hidden="true" /> Quick log</>}
        </a>
      </nav>}
      <header className="daily-heading"><h1>{isLog ? 'Quick log' : 'Habit'}</h1></header>
      {locked ? <LogUnlock>{children}</LogUnlock> : children}
    </main>
  );
}

export function LogUnlock({ children }: { children: ReactNode }) {
  const [signedIn, setSignedIn] = useState<boolean | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [password, setPassword] = useState('');

  useEffect(() => {
    api<{ signedIn: boolean }>('session')
      .then(result => setSignedIn(result.signedIn))
      .catch(error => setError(error.message));
  }, []);

  async function login(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      await api('login', {}, { password });
      setPassword('');
      setSignedIn(true);
    } catch (error) {
      setError((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      {error && <p className="daily-error" role="alert">{error}</p>}
      {signedIn === null && !error && <p className="daily-muted" role="status">Checking access…</p>}
      {signedIn === false && (
        <form className="daily-signin" onSubmit={login}>
          <label htmlFor="daily-password">Habit password</label>
          <input id="daily-password" type="password" value={password} required autoComplete="current-password"
            onChange={event => setPassword(event.target.value)} />
          <button className="daily-button daily-primary" disabled={busy}>{busy ? 'Unlocking…' : 'Unlock'}</button>
        </form>
      )}
      {signedIn && children}
    </>
  );
}
