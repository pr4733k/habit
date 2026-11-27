'use client';

import { useEffect, useRef, useState } from 'react';
import { api } from './model';

type SyncResult = { added: number; updated: number; checked: number; dates: string[] };

export function GarminSync() {
  const [connected, setConnected] = useState(false);
  const [busy, setBusy] = useState(false);
  const [receipt, setReceipt] = useState('');
  const [error, setError] = useState('');
  const running = useRef(false);
  useEffect(() => {
    let active = true;
    api<{ configured: boolean }>('garmin').then(result => { if (active) setConnected(result.configured); }).catch(() => {});
    return () => { active = false; };
  }, []);
  async function sync() {
    if (running.current) return;
    running.current = true; setBusy(true); setReceipt(''); setError('');
    try {
      const result = await api<SyncResult>('garmin-sync', {}, {});
      const messages = [];
      if (result.added) messages.push(`${result.added} ${result.added === 1 ? 'new run' : 'new runs'} added`);
      if (result.updated) messages.push(`${result.updated} updated`);
      setReceipt(messages.length ? `${messages.join(' · ')}. Saved to the run’s date.` : 'No new runs. You’re up to date.');
      window.dispatchEvent(new Event('daily:updated'));
    } catch (error) { setError((error as Error).message); }
    finally { running.current = false; setBusy(false); }
  }
  if (!connected) return null;
  return <div className="daily-garmin-sync">
    <button type="button" className="daily-button" disabled={busy} onClick={sync}>{busy ? 'Syncing Garmin…' : 'Sync Garmin run'}</button>
    {receipt && <p className="daily-receipt" role="status">{receipt}</p>}
    {error && <p className="daily-error" role="alert">{error} Tap again to retry.</p>}
  </div>;
}
