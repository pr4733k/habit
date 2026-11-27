'use client';

import { LoaderCircle } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { api, formatNumber, type Workout } from './model';
import { WorkoutSession } from './workout-session';
import { GarminSync } from './garmin-sync';
import { readSessionDraft, sessionStorageKey } from './workout-log-model';
import './today.css';

type Plan = {
  kind: 'lift' | 'run' | 'rest'; title: string; reason: string; venue: string | null; minutes: number | null;
  exercises: { name: string; sets: number | null; reps: string | null; load: string | null; load_basis: 'previous' | 'suggested' | 'unknown'; notes: string | null }[];
  run: { distance_m: number | null; duration_s: number | null; effort: string | null; notes: string | null } | null; notes: string | null;
};
type PlanData = { plan: Plan | null; generatedAt: string | null; generating: boolean };
type TodayData = PlanData & { day: { date: string } };
type WorkoutInputs = { focus: string; soreness: string; tomorrow_plan: string; effort: 'light' | 'regular' | 'intense' };

// Keep old bookmarks working while Daily owns the workout interface.
export function TodayRedirect() {
  useEffect(() => { window.location.replace('/daily?tab=workout'); }, []);
  return <main className="daily-shell"><a className="text-link" href="/daily?tab=workout">Open Workout →</a></main>;
}

export function WorkoutPlanner({ active, workouts, onLoggingChange }: { active: boolean; workouts: Workout[]; onLoggingChange: (logging: boolean) => void }) {
  const [data, setData] = useState<TodayData | null>(null);
  const [canGenerate, setCanGenerate] = useState(false);
  const [canLog, setCanLog] = useState(false);
  const [logging, setLogging] = useState(false);
  const [error, setError] = useState('');
  const [planError, setPlanError] = useState('');
  const [busy, setBusy] = useState(false);
  const [inputs, setInputs] = useState<WorkoutInputs>({ focus: '', soreness: '', tomorrow_plan: '', effort: 'regular' });

  useEffect(() => {
    if (!active || busy) return;
    let mounted = true;
    async function load() {
      if (document.hidden) return;
      try { const next = await api<TodayData>('today'); if (mounted) { setData(next); setError(''); } }
      catch (error) { if (mounted) setError((error as Error).message); }
    }
    void load();
    const timer = window.setInterval(load, 15000);
    document.addEventListener('visibilitychange', load);
    Promise.all([api<{ signedIn: boolean }>('session'), api<{ processor?: string }>('settings')])
      .then(([session, settings]) => { if (mounted) { setCanLog(session.signedIn); setCanGenerate(session.signedIn && settings.processor === 'openrouter'); } }).catch(() => {});
    return () => { mounted = false; window.clearInterval(timer); document.removeEventListener('visibilitychange', load); };
  }, [active, busy]);

  const date = data?.day.date;
  useEffect(() => {
    if (!date) return;
    try { setLogging(readSessionDraft(window.localStorage.getItem(sessionStorageKey(date)))?.active === true); } catch { /* Browser storage is optional. */ }
  }, [date]);
  useEffect(() => {
    const active = logging && canLog;
    onLoggingChange(active);
    window.dispatchEvent(new CustomEvent('daily:workout-session', { detail: { active } }));
  }, [logging, canLog, onLoggingChange]);
  useEffect(() => {
    if (!canLog || !data || new URLSearchParams(window.location.search).get('start') !== '1') return;
    if (data.plan?.kind === 'lift' || workouts.some(w => w.type === 'gym')) setLogging(true);
    const url = new URL(window.location.href); url.searchParams.delete('start');
    window.history.replaceState(null, '', url);
  }, [canLog, data, workouts]);

  async function generate(event: FormEvent) {
    event.preventDefault();
    if (busy || data?.generating) return;
    setBusy(true); setPlanError('');
    try {
      const result = await api<PlanData>('generate-workout', {}, { regenerate: !!data?.plan, inputs });
      setData(previous => previous ? { ...previous, ...result } : previous);
      if (!result.plan && !result.generating) setPlanError('Please wait a minute before trying again.');
    } catch (error) { setPlanError((error as Error).message); }
    finally { setBusy(false); }
  }

  if (!data) return <>{error ? <p className="daily-error" role="alert">{error}</p> : <p className="daily-muted" role="status">Loading today’s plan…</p>}</>;
  if (logging && canLog) return <WorkoutSession key={data.day.date} date={data.day.date} workouts={workouts} close={() => setLogging(false)} />;
  const { plan } = data;
  const generating = busy || data.generating;
  const fields = [
    { key: 'focus', label: 'Anything you want to target?', placeholder: 'Areas, muscles or exercises' },
    { key: 'soreness', label: 'Any soreness?', placeholder: 'Where and how much?' },
    { key: 'tomorrow_plan', label: 'Anything fixed for tomorrow?', placeholder: 'For example, squats / legs at Troop' },
  ] as const;

  return <section className="habit-workout-planner" aria-labelledby="habit-plan-heading">
    <div className="habit-plan-heading"><h2 id="habit-plan-heading" className="section-label">/plan</h2>
      {canGenerate && <span className="daily-muted">All inputs optional</span>}
    </div>
    {canLog && <GarminSync />}
    {error && <p className="daily-error" role="alert">{error}</p>}
    {canGenerate && <form className="habit-plan-form" onSubmit={generate}>
      <div className="habit-plan-inputs">{fields.map(field => <label key={field.key} htmlFor={`habit-plan-${field.key}`}>
        <span>{field.label}</span>
        <input id={`habit-plan-${field.key}`} type="text" maxLength={600} value={inputs[field.key]} autoComplete="off"
          placeholder={field.placeholder} disabled={generating}
          onChange={event => setInputs(previous => ({ ...previous, [field.key]: event.target.value }))} />
      </label>)}</div>
      <fieldset className="habit-plan-effort" disabled={generating}>
        <legend>How much do you want to push today?</legend>
        <div className="habit-effort-options">{(['light', 'regular', 'intense'] as const).map(effort => <label key={effort}>
          <input type="radio" name="workout-effort" value={effort} checked={inputs.effort === effort}
            onChange={() => setInputs(previous => ({ ...previous, effort }))} />
          <span>{effort === 'light' ? 'Light' : effort === 'intense' ? 'Intense' : 'Regular'}</span>
        </label>)}</div>
        <p className="daily-muted">{inputs.effort === 'light' ? 'Around 3 exercises, easier sets.' : inputs.effort === 'intense' ? 'Aim for 5–6 exercises, with more volume.' : 'Around 4–5 exercises, steady effort.'}</p>
      </fieldset>
      <div className="habit-plan-actions"><button type="submit" className="daily-button daily-primary" disabled={generating}>
        {generating && <LoaderCircle className="habit-spinning" aria-hidden="true" />}{generating ? 'Generating…' : plan ? 'Regenerate workout' : 'Generate workout'}
      </button></div>
    </form>}
    {planError && <p className="daily-error" role="alert">{planError}</p>}
    {generating && <p className="daily-muted" role="status">Looking at recent training and upcoming sessions…</p>}
    {!plan && !generating && <p className="daily-muted">No workout planned for today yet.</p>}
    {plan && <article className="habit-workout-plan">
      <p className="habit-plan-kind">Planned · {plan.kind === 'lift' ? 'Lift' : plan.kind === 'run' ? 'Run' : 'Rest'}{plan.venue && ` · ${plan.venue}`}{plan.minutes !== null && ` · ${plan.minutes} min`}</p>
      <h3>{plan.title}</h3><p className="habit-plan-reason">{plan.reason}</p>
      {canLog && (plan.kind === 'lift' || workouts.some(workout => workout.type === 'gym')) && <p className="habit-start-workout"><button type="button" className="daily-button daily-primary" disabled={generating} onClick={() => setLogging(true)}>
        {workouts.some(workout => workout.type === 'gym') ? 'Open workout log' : 'Start workout'}
      </button></p>}
      {plan.exercises.length > 0 && <ol>{plan.exercises.map((exercise, i) => <li key={i}><div><strong>{exercise.name}</strong><span>{[exercise.sets !== null ? `${exercise.sets} sets` : null, exercise.reps].filter(Boolean).join(' · ')}</span></div>
        {exercise.load && <p className="habit-plan-load">{exercise.load}<small>{exercise.load_basis === 'previous' ? 'Previously logged' : exercise.load_basis === 'suggested' ? 'Suggested load' : 'Load to choose'}</small></p>}
        {exercise.notes && <p className="daily-muted">{exercise.notes}</p>}
      </li>)}</ol>}
      {plan.run && <div className="habit-plan-run">{plan.run.distance_m !== null && <strong>{formatNumber(plan.run.distance_m / 1000)} km</strong>}{plan.run.duration_s !== null && <span>{formatNumber(plan.run.duration_s / 60)} min</span>}{plan.run.effort && <p>{plan.run.effort}</p>}{plan.run.notes && <p>{plan.run.notes}</p>}</div>}
      {plan.notes && <p className="daily-muted">{plan.notes}</p>}
    </article>}
  </section>;
}
