'use client';

import { Check, LoaderCircle, Minus } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { api, dateLabel, type Workout } from './model';
import { exerciseDraft, exercisePayload, groupSummary, loadLabel, readSessionDraft, sessionStorageKey, weightBases,
  type DraftGroup, type ExerciseDraft, type ExerciseEntry, type SessionDraft, type WorkoutSessionData, type WeightBasis } from './workout-log-model';
import './workout-session.css';

const emptySession: SessionDraft = { version: 1, active: true, open: null, exercises: {} };

export function WorkoutSession({ date, workouts, close }: { date: string; workouts: Workout[]; close: () => void }) {
  const [data, setData] = useState<WorkoutSessionData | null>(null);
  const [draft, setDraft] = useState<SessionDraft>(emptySession);
  const draftRef = useRef(draft);
  const [loadError, setLoadError] = useState('');
  const [storageError, setStorageError] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<string | null>(null);
  const savingRef = useRef(false);
  const loadSequence = useRef(0);
  const [receipt, setReceipt] = useState('');
  const [retry, setRetry] = useState(0);

  function remember(next: SessionDraft) {
    draftRef.current = next;
    setDraft(next);
    try { window.localStorage.setItem(sessionStorageKey(date), JSON.stringify(next)); setStorageError(''); }
    catch { setStorageError('This browser couldn’t keep a local draft. Keep this page open until you save.'); }
  }

  useEffect(() => {
    let active = true;
    setLoadError('');
    let stored: SessionDraft | null = null;
    try { stored = readSessionDraft(window.localStorage.getItem(sessionStorageKey(date))); } catch { /* Saving still works without browser storage. */ }
    function load() {
    if (document.hidden || savingRef.current) return;
    stored = draftRef.current.active && Object.keys(draftRef.current.exercises).length ? draftRef.current : stored;
    const sequence = ++loadSequence.current;
    api<WorkoutSessionData>('workout-session', { date }).then(result => {
      if (!active || sequence !== loadSequence.current) return;
      const exercises: Record<string, ExerciseDraft> = {};
      for (const exercise of result.exercises) exercises[exercise.key] = stored?.exercises[exercise.key]?.dirty
        ? stored.exercises[exercise.key] : exerciseDraft(exercise);
      const open = result.exercises.some(e => e.key === stored?.open) ? stored!.open
        : result.exercises.find(e => !e.saved)?.key ?? result.exercises[0]?.key ?? null;
      setData(result);
      remember({ version: 1, active: true, open, exercises });
    }).catch(error => { if (active) setLoadError((error as Error).message); });
    }
    load();
    window.addEventListener('focus', load);
    window.addEventListener('daily:refresh-session', load);
    document.addEventListener('visibilitychange', load);
    return () => { active = false; window.removeEventListener('focus', load); window.removeEventListener('daily:refresh-session', load); document.removeEventListener('visibilitychange', load); };
  }, [date, retry]);

  function update(key: string, index: number, change: Partial<DraftGroup>) {
    const current = draftRef.current;
    const exercise = current.exercises[key];
    remember({ ...current, exercises: { ...current.exercises, [key]: { ...exercise, dirty: true,
      groups: exercise.groups.map((group, i) => i === index ? { ...group, ...change } : group) } } });
    setErrors(previous => ({ ...previous, [key]: '' }));
  }

  async function save(event: FormEvent, key: string) {
    event.preventDefault();
    if (!data || savingRef.current) return;
    const exercise = data.exercises.find(item => item.key === key)!;
    const submitted = draftRef.current.exercises[key];
    let groups;
    try { groups = exercisePayload(submitted.groups); }
    catch (error) { setErrors(previous => ({ ...previous, [key]: (error as Error).message })); return; }
    ++loadSequence.current; savingRef.current = true; setSaving(key); setErrors(previous => ({ ...previous, [key]: '' })); setReceipt('');
    try {
      const { entry } = await api<{ entry: ExerciseEntry }>('save-exercise', {}, {
        date, name: exercise.name, venue: exercise.saved?.workout.venue ?? data.venue, revision: submitted.revision, groups,
      });
      const nextExercises = data.exercises.map(item => item.key === key ? { ...item, saved: entry } : item);
      setData(previous => previous ? { ...previous, exercises: previous.exercises.map(item => item.key === key ? { ...item, saved: entry } : item) } : previous);
      const current = draftRef.current;
      const index = nextExercises.findIndex(item => item.key === key);
      const nextOpen = nextExercises.slice(index + 1).find(item => !item.saved)?.key ?? nextExercises.find(item => !item.saved)?.key ?? null;
      remember({ ...current, open: current.open === key ? nextOpen : current.open,
        exercises: { ...current.exercises, [key]: exerciseDraft({ ...exercise, saved: entry }) } });
      setReceipt(`${exercise.name} saved.`);
      window.dispatchEvent(new Event('daily:updated'));
    } catch (error) { setErrors(previous => ({ ...previous, [key]: `${(error as Error).message} Your draft is kept.` })); }
    finally { savingRef.current = false; setSaving(null); }
  }

  async function reloadSaved(key: string) {
    try {
      const latest = await api<WorkoutSessionData>('workout-session', { date });
      const exercise = latest.exercises.find(item => item.key === key);
      if (!exercise) throw new Error('This exercise is no longer in the session.');
      setData(latest);
      const current = draftRef.current;
      const exercises = { ...current.exercises };
      for (const item of latest.exercises) exercises[item.key] ??= exerciseDraft(item);
      exercises[key] = exerciseDraft(exercise);
      remember({ ...current, exercises });
      setErrors(previous => ({ ...previous, [key]: '' }));
    } catch (error) { setErrors(previous => ({ ...previous, [key]: (error as Error).message })); }
  }

  function leave() { remember({ ...draftRef.current, active: false }); close(); }
  const count = data?.exercises.filter(exercise => !!exercise.saved).length ?? 0;
  return <section className="habit-workout-session" aria-labelledby="habit-session-heading">
    <div className="habit-session-heading"><div>
      <p className="daily-muted">{dateLabel(date, { weekday: 'short', day: 'numeric', month: 'short' })}</p>
      <h2 id="habit-session-heading">{data?.title ?? 'Workout'}</h2>
    </div><button type="button" className="habit-session-link" disabled={!!saving} onClick={leave}>Back to plan</button></div>
    {loadError && <div role="alert" className="daily-error">{loadError} <button type="button" className="habit-session-link" onClick={() => setRetry(value => value + 1)}>Retry</button></div>}
    {!data && !loadError && <p className="daily-muted" role="status">Loading your exercises and previous sets…</p>}
    {storageError && <p className="daily-error" role="alert">{storageError}</p>}
    {data && <>
      <div className="habit-session-meta"><span>{[data.venue, data.minutes === null ? null : `${data.minutes} min`].filter(Boolean).join(' · ')}</span><span>{count} of {data.exercises.length} logged</span></div>
      <div className="habit-session-progress" aria-hidden="true">{data.exercises.map(exercise => <span key={exercise.key} data-saved={!!exercise.saved || undefined} />)}</div>
      {!data.exercises.length && <p className="daily-muted">Generate a lifting plan to start logging exercises.</p>}
      <div className="habit-session-exercises">{data.exercises.map((exercise, exerciseIndex) => {
        const value = draft.exercises[exercise.key];
        if (!value) return null;
        const isOpen = draft.open === exercise.key;
        const editorId = `habit-exercise-${exerciseIndex}`;
        const pending = saving === exercise.key;
        return <section className="habit-session-exercise" key={exercise.key}>
          <button type="button" className="habit-exercise-toggle" aria-expanded={isOpen} aria-controls={editorId}
            onClick={() => remember({ ...draftRef.current, open: isOpen ? null : exercise.key })}>
            <span className="habit-exercise-number" data-saved={!!exercise.saved || undefined}>{exercise.saved ? <Check aria-label="Logged" /> : exerciseIndex + 1}</span>
            <span className="habit-exercise-name"><strong>{exercise.name}</strong><small>{exercise.saved ? groupSummary(exercise.saved.groups)
              : [exercise.planned?.sets ? `${exercise.planned.sets} sets` : null, exercise.planned?.reps].filter(Boolean).join(' · ')}</small></span>
            <span className="habit-exercise-toggle-label">{exercise.saved?.external ? 'Logged' : exercise.saved ? 'Edit' : isOpen ? '−' : '+'}</span>
          </button>
          {exercise.saved?.external ? <div id={editorId} hidden={!isOpen} className="habit-exercise-editor"><p className="daily-muted">Saved in an earlier log today.</p></div> :
          <form id={editorId} hidden={!isOpen} className="habit-exercise-editor" onSubmit={event => save(event, exercise.key)}>
            {exercise.previous && <div className="habit-exercise-previous"><span>Last time · {dateLabel(exercise.previous.date, { day: 'numeric', month: 'short' })}</span>
              <p>{exercise.previous.complete ? groupSummary(exercise.previous.groups) : exercise.previous.details ?? 'Set details weren’t recorded.'}</p></div>}
            {!exercise.previous && !exercise.saved && <p className="habit-exercise-no-history daily-muted">No matching previous sets.</p>}
            {value.groups.map((group, groupIndex) => <div className="habit-exercise-group" key={groupIndex}>
              <div className="habit-exercise-load"><label><span className="sr-only">Load type, group {groupIndex + 1}</span>
                <select disabled={pending} value={group.weight_basis === 'bodyweight' ? 'bodyweight' : `${group.weight_unit}:${group.weight_basis ?? ''}`}
                  onChange={event => { const [unit, basis] = event.target.value.split(':'); update(exercise.key, groupIndex,
                    unit === 'bodyweight' ? { weight_basis: 'bodyweight', weight: '' } : { weight_unit: unit as 'kg' | 'lb', weight_basis: (basis || null) as WeightBasis }); }}>
                  <option value="bodyweight">Bodyweight</option>
                  {(['kg', 'lb'] as const).flatMap(unit => [null, ...weightBases.filter(basis => basis !== 'bodyweight')].map(basis =>
                    <option key={`${unit}:${basis}`} value={`${unit}:${basis ?? ''}`}>{loadLabel(unit, basis)}</option>))}
                </select></label>
                {value.groups.length > 1 && <button type="button" className="habit-remove-group" aria-label={`Remove set group ${groupIndex + 1}`} disabled={pending}
                  onClick={() => { const current = draftRef.current; const currentExercise = current.exercises[exercise.key];
                    remember({ ...current, exercises: { ...current.exercises, [exercise.key]: { ...currentExercise, dirty: true, groups: currentExercise.groups.filter((_, i) => i !== groupIndex) } } }); }}><Minus aria-hidden="true" /></button>}
              </div>
              <div className="habit-exercise-fields">
                {(['weight', 'reps', 'sets'] as const).map(field => <label key={field}><span>{field === 'weight' ? 'Weight' : field === 'reps' ? 'Reps' : 'Sets'}</span>
                  <input type="text" inputMode={field === 'weight' ? 'decimal' : 'numeric'} autoComplete="off" maxLength={12}
                    aria-label={`${field === 'weight' ? loadLabel(group.weight_unit, group.weight_basis) : field === 'reps' ? 'Reps' : 'Sets'}, group ${groupIndex + 1}`}
                    value={field === 'weight' && group.weight_basis === 'bodyweight' ? 'BW' : group[field]}
                    placeholder={field === 'sets' ? '1' : '—'} disabled={pending || field === 'weight' && group.weight_basis === 'bodyweight'}
                    onChange={event => update(exercise.key, groupIndex, { [field]: event.target.value })} /></label>)}
              </div>
            </div>)}
            <button type="button" className="habit-session-link" disabled={pending || value.groups.length >= 20}
              onClick={() => { const current = draftRef.current; const currentExercise = current.exercises[exercise.key]; const last = currentExercise.groups.at(-1)!;
                remember({ ...current, exercises: { ...current.exercises, [exercise.key]: { ...currentExercise, dirty: true, groups: [...currentExercise.groups, { ...last, sets: '1' }] } } }); }}>+ Different set</button>
            {errors[exercise.key] && <div className="habit-exercise-error" role="alert"><p>{errors[exercise.key]}</p>
              <button type="button" className="habit-session-link" disabled={pending} onClick={() => void reloadSaved(exercise.key)}>Reload saved values</button></div>}
            <button className="daily-button daily-primary habit-exercise-save" disabled={!!saving}>
              {pending && <LoaderCircle className="habit-spinning" aria-hidden="true" />}{pending ? 'Saving…' : errors[exercise.key] ? 'Retry save' : exercise.saved ? 'Save changes' : 'Save exercise'}
            </button>
          </form>}
        </section>;
      })}</div>
      <p className="habit-session-receipt" role="status" aria-live="polite">{receipt}</p>
      <div className="habit-session-footer"><span>Each exercise saves separately</span><button type="button" className="habit-session-link" disabled={!!saving} onClick={leave}>Finish session</button></div>

    </>}
  </section>;
}
