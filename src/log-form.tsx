'use client';

import { FoodTools } from './food-tools';
import { AddRepeatFoods } from './repeat-foods';
import { GarminSync } from './garmin-sync';
import { Camera } from 'lucide-react';

import { Check, Clock3, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { api, dateLabel, macroKeys, macroLabels, previousDay, todayIn, type Goals, type Note } from './model';

type DateMode = 'today' | 'yesterday' | 'custom';
type SyncState = 'idle' | 'queued' | 'waiting' | 'claimed' | 'sent' | 'complete' | 'failed';
const syncMessages: Record<SyncState, string> = {
  idle: '',
  queued: 'Connecting to your PC…',
  waiting: 'Pending · Waiting for your PC',
  claimed: 'Sending to Codex…',
  sent: 'Sent to Codex · Waiting for sync',
  complete: 'All notes processed',
  failed: 'Pending · Sync didn’t finish. Try again or check Codex.',
};

export function LogForm({ timeZone, autoSync, processor }: { timeZone: string; autoSync: boolean; processor?: string }) {
  const externalSync = autoSync && processor !== 'openrouter';
  const [mode, setMode] = useState<DateMode>('today');
  const [customDate, setCustomDate] = useState('');
  const [text, setText] = useState('');
  const [photoRequest, setPhotoRequest] = useState(0);
  const [editing, setEditing] = useState<Note | null>(null);
  const [saved, setSaved] = useState<Note | null>(null);
  const [busy, setBusy] = useState(false);
  const [loadFailed, setLoadFailed] = useState(false);
  const [error, setError] = useState('');
  const [dayNotes, setDayNotes] = useState<Note[]>([]);
  const [notesError, setNotesError] = useState('');
  const [syncState, setSyncState] = useState<SyncState>('idle');
  const [syncBusy, setSyncBusy] = useState(false);
  const [syncError, setSyncError] = useState('');
  const createId = useRef<string | null>(null);
  const today = todayIn(timeZone);
  const date = mode === 'custom' ? customDate : mode === 'yesterday' ? previousDay(today) : today;
  const syncing = syncState === 'queued' || syncState === 'waiting' || syncState === 'claimed' || syncState === 'sent';
  const syncComplete = syncState === 'complete';

  useEffect(() => {
    if (!externalSync) return;
    let active = true;
    api<{ state: SyncState }>('sync').then(({ state }) => {
      if (active) setSyncState(current => current === 'idle' ? state : current);
    }).catch(() => {});
    return () => { active = false; };
  }, [externalSync]);

  useEffect(() => {
    if (!syncing) return;
    let active = true;
    const timer = window.setInterval(() => {
      api<{ state: SyncState }>('sync').then(({ state }) => {
        if (active) setSyncState(state);
      }).catch(() => {});
    }, 5000);
    return () => { active = false; window.clearInterval(timer); };
  }, [syncing]);

  useEffect(() => {
    const query = new URLSearchParams(window.location.search);
    const id = query.get('id');
    const preset = query.get('date');
    if (id) {
      setBusy(true);
      api<{ note: Note }>('note', { id }).then(({ note }) => {
        setEditing(note);
        setText(note.text);
        setCustomDate(note.date);
        setMode('custom');
      }).catch(error => {
        setLoadFailed(true);
        setError(error.message);
      }).finally(() => setBusy(false));
    } else if (preset && /^\d{4}-\d{2}-\d{2}$/.test(preset) && preset <= todayIn(timeZone) && Number.isFinite(Date.parse(preset))) {
      setCustomDate(preset);
      setMode('custom');
    }
  }, [timeZone]);

  useEffect(() => {
    let active = true;
    setDayNotes([]);
    setNotesError('');
    if (date) {
      api<{ notes: Note[] }>('notes', { date })
        .then(({ notes }) => { if (active) setDayNotes(notes); })
        .catch(error => { if (active) setNotesError(error.message); });
    }
    return () => { active = false; };
  }, [date, saved, syncComplete]);

  const waiting = dayNotes.some(note => !note.parsed && note.processing?.state !== 'failed');
  useEffect(() => {
    if (!waiting || !date) return;
    let active = true;
    const started = Date.now();
    let lastRefresh = 0;
    const timer = window.setInterval(async () => {
      if (document.hidden) return;
      const now = Date.now();
      if (now - started > 600000) { window.clearInterval(timer); return; }
      if (now - started > 30000 && now - lastRefresh < 10000) return;
      lastRefresh = now;
      try {
        const { notes } = await api<{ notes: Note[] }>('notes', { date });
        if (!active) return;
        setDayNotes(notes);
        setSaved(previous => previous ? notes.find(n => n.id === previous.id) ?? previous : previous);
      } catch { /* Preserve the saved receipt if a refresh fails. */ }
    }, 2500);
    return () => { active = false; window.clearInterval(timer); };
  }, [waiting, date]);

  async function retry(note: Note) {
    setBusy(true); setError('');
    try {
      const result = await api<{ note: Note }>('retry-note', {}, { id: note.id });
      setSaved(result.note);
    } catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    if (busy || !text.trim() || !date) return;
    setBusy(true);
    setSaved(null);
    setError('');
    try {
      createId.current ??= crypto.randomUUID();
      const { note, sync } = await api<{ note: Note; sync: { state: SyncState } | null }>('save', {}, {
        id: editing?.id ?? createId.current, revision: editing?.revision, text, date,
      });
      setSaved(note);
      if (sync) setSyncState(sync.state);
      setSyncError('');
      if (editing) setEditing(note);
      else {
        createId.current = null;
        setText('');
      }
    } catch (error) {
      setError((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function requestSync() {
    if (syncBusy || syncing) return;
    setSyncBusy(true);
    setSyncError('');
    try {
      const { state } = await api<{ state: SyncState }>('sync', {}, {});
      setSyncState(state);
    } catch (error) {
      setSyncError((error as Error).message);
    } finally {
      setSyncBusy(false);
    }
  }

  return (
    <>
    <div className="daily-log-form">
      <div className="daily-form-date">
        <div>
          <label className="section-label" htmlFor="daily-date-mode">/date</label>
          <select id="daily-date-mode" value={mode} disabled={busy}
            onChange={event => {
              const value = event.target.value as DateMode;
              if (value === 'custom' && !customDate) setCustomDate(date || today);
              setMode(value);
              setSaved(null);
            }}>
            <option value="today">Today</option>
            <option value="yesterday">Yesterday</option>
            <option value="custom">Custom date</option>
          </select>
        </div>
        {mode === 'custom'
          ? <input aria-label="Custom date" type="date" value={customDate} max={today} required disabled={busy}
              onChange={event => { setCustomDate(event.target.value); setSaved(null); }} />
          : <time dateTime={date}>{dateLabel(date, { weekday: 'short', day: 'numeric', month: 'long' })}</time>}
      </div>
    </div>
    {!editing && <GarminSync />}
    {!editing && <FoodTools key={date} date={date} onSaved={setSaved} photoRequest={photoRequest} />}
    <form className="daily-log-form" onSubmit={save}>
      <div className="daily-note-heading"><label className="section-label" htmlFor="daily-note">/note</label>{!editing && <button type="button" className="text-link daily-retry" onClick={() => { setPhotoRequest(value => value + 1); document.querySelector('[aria-label="Quick food logging"]')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }}><Camera aria-hidden="true" />Nutrition label</button>}</div>
      <textarea id="daily-note" value={text} required maxLength={4000} rows={6} disabled={busy || loadFailed}
        placeholder={'Lunch: 2 rotis, dal and 500 ml water.\nGym: bench press 3 × 8 at 50 kg.'}
        onChange={event => { setText(event.target.value); setSaved(null); }} />
      {error && <p className="daily-error" role="alert">{error}</p>}
      <div className="daily-log-actions">
        <button className="daily-button daily-primary" disabled={busy || loadFailed || !text.trim()}>
          <Check aria-hidden="true" /> {busy ? 'Saving…' : editing ? 'Save changes' : 'Save note'}
        </button>
        {syncState === 'failed' && <button type="button" className="daily-button" disabled={busy || syncBusy} onClick={requestSync}>
          <RefreshCw aria-hidden="true" /> Retry sync
        </button>}
        {editing && <a className="text-link" href={`/log?date=${date}`}>New note</a>}
        {saved && <div className="daily-receipt" role="status">
          {saved.parsed || syncComplete ? <Check aria-hidden="true" /> : <Clock3 aria-hidden="true" />}
          <span>{processor === 'openrouter' ? noteStatus(saved) : syncComplete ? 'Synced' : !autoSync && !saved.parsed ? 'Saved · Pending' : 'Saved'}</span>
          <a className="text-link" href={`/daily?date=${saved.date}`}>View day</a>
        </div>}
      </div>
      {(syncBusy || syncMessages[syncState]) && <p className="daily-muted" role="status">
        {syncBusy ? 'Requesting sync…' : syncMessages[syncState]}
      </p>}
      {syncError && <p className="daily-error" role="alert">{syncError}</p>}
    </form>
    <AddRepeatFoods note={saved ? dayNotes.find(note => note.id === saved.id) ?? saved : null} />
    {notesError && <p className="daily-error" role="alert">{notesError}</p>}
    {dayNotes.length > 0 && <details className="daily-owner-section">
      <summary className="section-label">Saved notes ({dayNotes.length})</summary>
      <ul>{dayNotes.map(note => <li className="daily-original-note" key={note.id}>
        <p>{note.text}</p>
        <div>
          <span data-pending={!note.parsed || !!note.parsed.review || undefined}>
            {note.parsed?.review ? 'Needs review' : processor === 'openrouter' ? noteStatus(note) : !note.parsed ? (syncing && syncState !== 'waiting' ? 'Syncing…' : 'Pending') : 'Processed'}
          </span>
          <a className="text-link" href={`/log?id=${note.id}`}>Edit</a>
          {!note.parsed && note.processing?.state === 'failed' && <button className="text-link daily-retry" disabled={busy} onClick={() => retry(note)}>Retry</button>}
        </div>
        {note.processing?.error && !note.parsed && <p className="daily-muted">{note.processing.error}</p>}
        <AddRepeatFoods note={note} />
        {note.parsed?.review && <p className="daily-muted">{note.parsed.review}</p>}
      </li>)}</ul>
    </details>}
    <LogGoals />
    </>
  );
}

function noteStatus(note: Note) {
  if (note.parsed) return 'Logged';
  if (note.processing?.state === 'failed') return 'Needs attention';
  if (note.processing && (note.processing.attempts ?? 0) > 0 && note.processing.state === 'queued') return 'Saved · Retry scheduled';
  return note.processing ? 'Saved · Processing' : 'Saved · Pending';
}

const goalFields = [...macroKeys, 'water_ml'] as const;

function LogGoals() {
  const [goals, setGoals] = useState<Goals | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    api<{ goals: Goals | null }>('goals').then(result => {
      setGoals(result.goals);
      setLoaded(true);
    }).catch(error => setError(error.message));
  }, []);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const fields = new FormData(event.currentTarget);
    setBusy(true);
    setError('');
    setStatus('');
    try {
      const values = Object.fromEntries(goalFields.map(key => [key, Number(fields.get(key))]));
      await api('goals', {}, values);
      setStatus('Goals saved.');
    } catch (error) {
      setError((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <details className="daily-owner-section">
      <summary className="section-label">Daily goals</summary>
      {error && <p className="daily-error" role="alert">{error}</p>}
      {loaded && <form className="daily-goals-form" onSubmit={save}>
        <p className="daily-muted">Goal changes take effect today.</p>
        {goalFields.map(key => <label key={key}>
          {key === 'water_ml' ? 'Water (mL)' : `${macroLabels[key]} (g)`}
          <input name={key} type="number" min="1" max={key === 'water_ml' ? 30000 : 2000}
            required defaultValue={goals?.[key]} />
        </label>)}
        <button className="daily-button daily-primary" disabled={busy}>{busy ? 'Saving…' : 'Save goals'}</button>
        {status && <p className="daily-muted" role="status">{status}</p>}
      </form>}
    </details>
  );
}
