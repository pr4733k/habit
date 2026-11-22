'use client';

import { ArrowLeft, ArrowUpRight, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { api, dateLabel, formatNumber, macroKeys, macroLabels, mealGroups, type Goals, type Intake, type Parsed } from './model';
import { MacroRings, WaterWave, activityIcons, activityLabels } from './visuals';
import { LogUnlock } from './shell';
import './today.css';

type Plan = {
  kind: 'lift' | 'run' | 'rest'; title: string; reason: string; venue: string | null; minutes: number | null;
  exercises: { name: string; sets: number | null; reps: string | null; load: string | null; load_basis: 'previous' | 'suggested' | 'unknown'; notes: string | null }[];
  run: { distance_m: number | null; duration_s: number | null; effort: string | null; notes: string | null } | null; notes: string | null;
};
type TodayData = {
  day: { date: string; goals: Goals | null; totals: Record<'kcal' | typeof macroKeys[number], number | null>; waterMl: number | null } & Pick<Parsed, 'meals' | 'alcohol' | 'workouts' | 'study'>;
  plan: Plan | null; generatedAt: string | null; generating: boolean;
};

export function Today() {
  const [privateCalendar, setPrivateCalendar] = useState<boolean | null>(null);
  useEffect(() => { api<{ publicCalendar: boolean }>('settings').then(s => setPrivateCalendar(!s.publicCalendar)).catch(() => setPrivateCalendar(false)); }, []);
  return <main className="daily-shell habit-today">
    <nav className="daily-site-nav"><a className="daily-home" href="/daily"><ArrowLeft aria-hidden="true" />Daily</a></nav>
    {privateCalendar === null ? <p role="status" className="daily-muted">Opening today…</p>
      : privateCalendar ? <LogUnlock><TodayContent /></LogUnlock> : <TodayContent />}
  </main>;
}

function TodayContent() {
  const [data, setData] = useState<TodayData | null>(null);
  const [canGenerate, setCanGenerate] = useState(false);
  const [error, setError] = useState('');
  const [planError, setPlanError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let active = true;
    async function load() {
      if (document.hidden) return;
      try { const next = await api<TodayData>('today'); if (active) { setData(next); setError(''); } }
      catch (error) { if (active) setError((error as Error).message); }
    }
    void load();
    const timer = window.setInterval(load, 15000);
    document.addEventListener('visibilitychange', load);
    Promise.all([api<{ signedIn: boolean }>('session'), api<{ processor?: string }>('settings')])
      .then(([session, settings]) => { if (active) setCanGenerate(session.signedIn && settings.processor === 'openrouter'); }).catch(() => {});
    return () => { active = false; window.clearInterval(timer); document.removeEventListener('visibilitychange', load); };
  }, []);

  async function generate() {
    if (busy || data?.generating) return;
    setBusy(true); setPlanError('');
    try {
      const result = await api<Pick<TodayData, 'plan' | 'generatedAt' | 'generating'>>('generate-workout', {}, { regenerate: !!data?.plan });
      setData(previous => previous ? { ...previous, ...result } : previous);
      if (!result.plan && !result.generating) setPlanError('Please wait a minute before trying again.');
    } catch (error) { setPlanError((error as Error).message); }
    finally { setBusy(false); }
  }

  if (!data) return <>{error ? <p className="daily-error" role="alert">{error}</p> : <p className="daily-muted" role="status">Loading today…</p>}</>;
  const { day, plan } = data;
  const activities = [...new Set(day.workouts.map(w => w.type)), ...(day.study.length ? ['study' as const] : [])];
  const generating = busy || data.generating;
  return <>
    <header className="daily-heading habit-today-heading"><p className="section-label">{dateLabel(day.date, { weekday: 'long' })}</p><h1>{dateLabel(day.date, { day: 'numeric', month: 'long' })}</h1></header>
    {error && <p className="daily-error" role="alert">{error}</p>}
    <section className="habit-today-summary" aria-label="Today’s nutrition and activity">
      <div className="habit-today-date" data-alcohol={day.alcohol.length > 0 || undefined}>
        <WaterWave waterMl={day.waterMl ?? 0} goals={day.goals} />
        <MacroRings totals={day.totals} goals={day.goals} />
        <strong>{Number(day.date.slice(-2))}</strong>
      </div>
      <div className="habit-today-numbers">
        <p className="habit-today-calories"><strong>{day.totals.kcal === null ? '—' : formatNumber(day.totals.kcal, 0)}</strong> kcal</p>
        <dl>{macroKeys.map(key => <div className={`daily-${key}`} key={key}><dt>{macroLabels[key]}</dt><dd>{day.totals[key] === null ? '—' : formatNumber(day.totals[key])}<small>{day.goals ? ` / ${day.goals[key]} g` : ' g'}</small></dd></div>)}</dl>
        <p className="habit-today-water">Water <strong>{day.waterMl === null ? '—' : `${formatNumber(day.waterMl / 1000, 2)} L`}</strong>{day.goals && <span> / {formatNumber(day.goals.water_ml / 1000)} L</span>}</p>
        {activities.length > 0 && <div className="habit-today-activities">{activities.map(type => { const Icon = activityIcons[type]; return <span key={type}><Icon aria-hidden="true" />{activityLabels[type]}</span>; })}</div>}
      </div>
    </section>
    <section className="habit-today-workout" aria-labelledby="habit-plan-heading">
      <div className="habit-today-section-heading"><h2 id="habit-plan-heading" className="section-label">/workout</h2>
        {canGenerate && <button className="daily-button daily-primary" onClick={generate} disabled={generating}>
          {generating && <LoaderCircle className="habit-spinning" aria-hidden="true" />}{generating ? 'Generating…' : plan ? 'Regenerate' : 'Generate workout'}
        </button>}
      </div>
      {planError && <p className="daily-error" role="alert">{planError}</p>}
      {generating && <p className="daily-muted" role="status">Looking at recent training and upcoming sessions…</p>}
      {!plan && !generating && <p className="daily-muted">No workout planned for today yet.</p>}
      {plan && <article className="habit-workout-plan">
        <p className="habit-plan-kind">{plan.kind === 'lift' ? 'Lift' : plan.kind === 'run' ? 'Run' : 'Rest'}{plan.venue && ` · ${plan.venue}`}{plan.minutes !== null && ` · ${plan.minutes} min`}</p>
        <h3>{plan.title}</h3><p className="habit-plan-reason">{plan.reason}</p>
        {plan.exercises.length > 0 && <ol>{plan.exercises.map((exercise, i) => <li key={i}><div><strong>{exercise.name}</strong><span>{[exercise.sets !== null ? `${exercise.sets} sets` : null, exercise.reps].filter(Boolean).join(' · ')}</span></div>
          {exercise.load && <p className="habit-plan-load">{exercise.load}<small>{exercise.load_basis === 'previous' ? 'Previously logged' : exercise.load_basis === 'suggested' ? 'Suggested load' : 'Load to choose'}</small></p>}
          {exercise.notes && <p className="daily-muted">{exercise.notes}</p>}
        </li>)}</ol>}
        {plan.run && <div className="habit-plan-run">{plan.run.distance_m !== null && <strong>{formatNumber(plan.run.distance_m / 1000)} km</strong>}{plan.run.duration_s !== null && <span>{formatNumber(plan.run.duration_s / 60)} min</span>}{plan.run.effort && <p>{plan.run.effort}</p>}{plan.run.notes && <p>{plan.run.notes}</p>}</div>}
        {plan.notes && <p className="daily-muted">{plan.notes}</p>}
      </article>}
    </section>
    <section className="habit-today-food" aria-labelledby="habit-food-heading"><h2 id="habit-food-heading" className="section-label">/food</h2>
      {!day.meals.length && !day.alcohol.length && <p className="daily-muted">No food logged yet.</p>}
      {mealGroups.map(group => { const items = day.meals.filter(m => m.meal === group); return items.length ? <div className="daily-detail-group" key={group}><h3>{group}</h3><ul>{items.map((item, i) => <TodayFood item={item} key={i} />)}</ul></div> : null; })}
      {day.alcohol.length > 0 && <div className="daily-detail-group"><h3>Drinks</h3><ul>{day.alcohol.map((item, i) => <TodayFood key={i} item={item} />)}</ul></div>}
    </section>
    <p className="daily-coach-link"><a className="text-link" href={`/daily?date=${day.date}`}>Open day’s logs <ArrowUpRight size={14} aria-hidden="true" /></a></p>
  </>;
}

function TodayFood({ item }: { item: Intake }) {
  return <li className="daily-detail-row daily-food-row"><div><strong>{item.name}</strong>{item.quantity && <small>{item.quantity}</small>}</div>
    <div className="daily-food-nutrition"><span className="daily-food-calories">{item.nutrition?.kcal == null ? '—' : formatNumber(item.nutrition.kcal, 0)} kcal</span>
      <dl className="daily-food-macros">{macroKeys.map(key => <div className={`daily-${key}`} key={key}><dt>{macroLabels[key]}</dt><dd>{item.nutrition?.[key] == null ? '—' : `${formatNumber(item.nutrition[key])} g`}</dd></div>)}</dl>
    </div>
  </li>;
}
