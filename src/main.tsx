import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource-variable/inter';
import './globals.css';
import './daily.css';
import { TodayRedirect } from './today';
import { Calendar } from './calendar';
import { LogForm } from './log-form';
import { api } from './model';
import { DailyShell } from './shell';

type Settings = { timeZone: string; publicCalendar: boolean; autoSync: boolean; processor?: string };

function App() {
  const path = window.location.pathname.replace(/\/$/, '');
  const isLog = path === '/log' || path === '/daily/log';
  const [settings, setSettings] = useState<Settings>();
  const [error, setError] = useState('');

  useEffect(() => {
    api<Settings>('settings')
      .then(setSettings)
      .catch(error => setError(error.message));
  }, []);

  if (path === '/today') return <TodayRedirect />;
  return <DailyShell view={isLog ? 'log' : 'calendar'}
    locked={!!settings && (isLog || !settings.publicCalendar)}>
    {error && <p className="daily-error" role="alert">{error}</p>}
    {!error && !settings && <p className="daily-muted" role="status">Opening Habit…</p>}
    {settings && (isLog ? <LogForm timeZone={settings.timeZone} autoSync={settings.autoSync} processor={settings.processor} />
      : <Calendar timeZone={settings.timeZone} />)}
  </DailyShell>;
}

createRoot(document.getElementById('root')!).render(<App />);
