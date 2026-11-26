'use client';

import { useEffect, useRef, useState } from 'react';
import { api, formatNumber, type Note, type Nutrition } from './model';

type Unit = 'g' | 'piece' | 'ml' | 'serving';
type Component = { name: string; unit: Unit; default_unit?: Unit; amount: number | null; grams_per_piece: number | null; nutrition: Nutrition };
type Source = { source: string; index: number; key: string; name: string };
type Draft = Source & { components: Component[]; recipe_estimated: boolean };
type SavedFood = { id: string; name: string; components: Component[]; count: number; lastDate: string | null };
type Amount = { amount: number; unit: Unit };
const unitLabel = { g: 'g', piece: 'pieces', ml: 'mL', serving: 'servings' };
const changed = () => window.dispatchEvent(new Event('daily:repeat-foods-changed'));

function reference(note: Note, index: number): Source {
  const meal = note.parsed!.meals[index];
  return { source: note.id, index, name: meal.name, key: '' };
}

export function AddRepeatFoods({ note }: { note: Note | null }) {
  const [source, setSource] = useState<Source | null>(null);
  useEffect(() => { setSource(null); }, [note?.id, note?.revision]);
  if (!note?.parsed?.meals.some(meal => meal.nutrition) || note.text.startsWith('Repeat food:')) return null;
  return <div className="habit-save-repeat">
    <span className="daily-muted">Save a food for next time</span>
    <div className="habit-food-actions">{note.parsed.meals.map((meal, index) => meal.nutrition && <button type="button" className="text-link daily-retry" key={index} onClick={() => setSource(reference(note, index))}>{meal.name}</button>)}</div>
    {source && <RepeatFoodEditor key={`${source.source}:${source.index}`} source={source} close={() => setSource(null)} />}
  </div>;
}

function RepeatFoodEditor({ source, close }: { source: Source; close: () => void }) {
  const [draft, setDraft] = useState<Draft | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState('');
  const [retry, setRetry] = useState(0);
  const [referenceOpen, setReferenceOpen] = useState(false);
  const saveId = useRef(crypto.randomUUID());
  useEffect(() => {
    let active = true; setError('');
    api<{ draft: Draft }>('prepare-repeat-food', {}, source).then(data => { if (active) { setDraft(data.draft); setReferenceOpen(data.draft.recipe_estimated || data.draft.components.some(part => part.amount === null)); } }).catch(error => { if (active) setError(error.message); });
    return () => { active = false; };
  }, [source.source, source.index, retry]);
  async function save() {
    if (!draft || busy) return; setBusy(true); setError('');
    try {
      const { food } = await api<{ food: { name: string } }>('save-repeat-food', {}, { ...draft, id: saveId.current });
      setDone(`${food.name} saved to repeats.`); changed();
    } catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  }
  function update(index: number, patch: Partial<Component>) { setDraft(previous => previous && ({ ...previous, components: previous.components.map((part, i) => i === index ? { ...part, ...patch } : part) })); }
  return <div className="habit-repeat-editor" role="region" aria-label="Save food to repeats">
    {done ? <p role="status">{done} <button type="button" className="text-link daily-retry" onClick={close}>Done</button></p> : <>
      {!draft && !error && <p className="daily-muted" role="status">Setting up this food…</p>}
      {draft && <>
        <label>Food name<input type="text" value={draft.name} maxLength={160} disabled={busy} onChange={e => setDraft({ ...draft, name: e.target.value })} /></label>
        <p className="daily-muted">Only the food is saved. Enter how much you ate each time.</p>
        <details open={referenceOpen} onToggle={event => setReferenceOpen(event.currentTarget.open)}>
          <summary>Reference from the original log</summary>
          {draft.recipe_estimated && <p className="daily-muted">Check the egg count and the salad weight without eggs. The ingredient split is estimated.</p>}
          {draft.components.map((part, index) => <div className="habit-repeat-reference" key={index}>
            <strong>{part.name}</strong>
            <div className="habit-repeat-inputs"><label>Original amount<input aria-label={`Original amount of ${part.name}`} type="number" inputMode="decimal" min="0.01" max="30000" step="any" value={part.amount ?? ''} disabled={busy} onChange={e => update(index, { amount: e.target.value === '' ? null : Number(e.target.value) })} /></label>
              <label>Unit<select value={part.unit} disabled={busy} onChange={e => update(index, { unit: e.target.value as Unit, amount: null })}>{(['g', 'piece', ...(part.unit === 'ml' ? ['ml'] : []), ...(part.unit === 'serving' ? ['serving'] : [])] as Unit[]).map(unit => <option key={unit} value={unit}>{unitLabel[unit]}</option>)}</select></label></div>
            {['g', 'piece'].includes(part.unit) && <label>Grams per piece (optional)<input type="number" inputMode="decimal" min="0.01" max="10000" step="any" value={part.grams_per_piece ?? ''} disabled={busy} onChange={e => update(index, { grams_per_piece: e.target.value === '' ? null : Number(e.target.value) })} /></label>}
          </div>)}
        </details>
        <div className="habit-food-actions"><button type="button" className="daily-button daily-primary" disabled={busy || !draft.name.trim() || draft.components.some(part => !part.amount || part.amount <= 0)} onClick={save}>{busy ? 'Saving…' : 'Save to repeats'}</button>
          <button type="button" className="daily-button" disabled={busy} onClick={close}>Cancel</button></div>
      </>}
      {error && <p className="daily-error" role="alert">{error} {!draft && <button type="button" className="text-link daily-retry" onClick={() => setRetry(value => value + 1)}>Retry</button>}</p>}
    </>}
  </div>;
}

export function SavedFoods({ date, onSaved }: { date: string; onSaved: (note: Note) => void }) {
  const [open, setOpen] = useState(false);
  const [foods, setFoods] = useState<SavedFood[]>([]);
  const [query, setQuery] = useState('');
  const [history, setHistory] = useState(false);
  const [candidates, setCandidates] = useState<Source[]>([]);
  const [source, setSource] = useState<Source | null>(null);
  const [selected, setSelected] = useState<SavedFood | null>(null);
  const [amounts, setAmounts] = useState<{ text: string; unit: Unit }[]>([]);
  const [busy, setBusy] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState('');
  const [receipt, setReceipt] = useState('');
  const [revision, setRevision] = useState(0);
  const attempt = useRef<string | null>(null);
  const saving = useRef(false);
  useEffect(() => { if (new URLSearchParams(window.location.search).get('food') === 'repeat') setOpen(true); }, []);
  useEffect(() => { const update = () => setRevision(value => value + 1); window.addEventListener('daily:repeat-foods-changed', update); return () => window.removeEventListener('daily:repeat-foods-changed', update); }, []);
  useEffect(() => {
    if (!open) return; let active = true;
    api<{ foods: SavedFood[] }>('saved-foods').then(data => { if (active) { setFoods(data.foods); setLoaded(true); setError(''); } }).catch(error => { if (active) setError(error.message); });
    return () => { active = false; };
  }, [open, revision]);
  useEffect(() => {
    let active = true; setCandidates([]);
    if (!history || query.trim().length < 2) return;
    const timer = window.setTimeout(() => { api<{ foods: Source[] }>('repeat-food-candidates', { query }).then(data => { if (active) setCandidates(data.foods); }).catch(error => { if (active) setError(error.message); }); }, 200);
    return () => { active = false; window.clearTimeout(timer); };
  }, [query, history]);
  function choose(food: SavedFood) { setSelected(food); setReceipt(''); setError(''); attempt.current = null; setAmounts(food.components.map(part => ({ text: '', unit: part.default_unit ?? part.unit }))); }
  async function log() {
    if (!selected || saving.current) return;
    const values = amounts.map(value => ({ amount: Number(value.text), unit: value.unit }));
    if (values.some(value => !Number.isFinite(value.amount) || value.amount <= 0)) { setError('Enter how much you ate.'); return; }
    saving.current = true; setBusy(true); setError(''); attempt.current ??= crypto.randomUUID();
    try {
      const { note } = await api<{ note: Note }>('log-repeat-food', {}, { date, id: attempt.current, food_id: selected.id, amounts: values });
      onSaved(note); setReceipt(`${selected.name} logged.`); setSelected(null); attempt.current = null; changed(); window.dispatchEvent(new Event('daily:updated'));
    } catch (error) { setError((error as Error).message); }
    finally { saving.current = false; setBusy(false); }
  }
  async function remove() {
    if (!selected || busy) return; setBusy(true); setError('');
    try { await api('remove-repeat-food', {}, { id: selected.id }); setSelected(null); setReceipt('Removed from repeats. Past logs are unchanged.'); changed(); }
    catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  }
  const results = foods.filter(food => food.name.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase())).slice(0, 6);
  const nutrition = selected ? repeatNutrition(selected, amounts) : null;
  return <div className="habit-saved-foods">
    <button type="button" className="daily-button" onClick={() => setOpen(value => !value)} aria-expanded={open}>Repeat meal</button>
    {open && <div className="habit-repeat-panel">
      <label className="sr-only" htmlFor="habit-repeat-search">{history ? 'Search past food logs' : 'Search saved foods'}</label>
      <input id="habit-repeat-search" type="search" placeholder={history ? 'Find a food in past logs…' : 'Search saved foods…'} value={query} onChange={e => { setQuery(e.target.value); setSelected(null); setSource(null); }} disabled={busy} maxLength={120} />
      {history ? <>
        <p className="daily-muted">Choose a food to save. Nothing is logged again.</p>
        <div className="habit-food-repeat-list">{candidates.map(item => <button type="button" key={`${item.source}:${item.index}`} onClick={() => setSource(item)}><strong>{item.name}</strong><span>Save to repeats</span></button>)}</div>
        {query.trim().length >= 2 && !candidates.length && <p className="daily-muted">No matching foods yet.</p>}
        {source && <RepeatFoodEditor key={`${source.source}:${source.index}`} source={source} close={() => { setSource(null); setHistory(false); setQuery(''); changed(); }} />}
        <button type="button" className="text-link daily-retry" onClick={() => { setHistory(false); setSource(null); setQuery(''); }}>Back to saved foods</button>
      </> : <>
        {!loaded && !error && <p className="daily-muted" role="status">Loading saved foods…</p>}
        {!selected && <>
          {!query && foods.length > 0 && <p className="daily-muted">Most logged</p>}
          <div className="habit-food-repeat-list">{results.map(food => <button type="button" key={food.id} onClick={() => choose(food)}><strong>{food.name}</strong><span>{food.count} {food.count === 1 ? 'time' : 'times'} logged</span></button>)}</div>
          {loaded && !results.length && <p className="daily-muted">{foods.length ? 'No saved foods match this search.' : 'Save a food after logging it, or add one from past logs.'}</p>}
          <button type="button" className="text-link daily-retry" onClick={() => { setHistory(true); setQuery(''); }}>Add a food from past logs</button>
        </>}
        {selected && <div className="habit-repeat-editor">
          <strong>{selected.name}</strong>
          {selected.components.map((part, index) => <div key={index}>
            <div className="habit-repeat-inputs"><label>{selected.components.length > 1 ? part.name : 'How much did you eat?'}<input autoFocus={index === 0} aria-label={`${part.name} quantity`} type="number" inputMode="decimal" min="0.01" max="30000" step="any" placeholder="Quantity" disabled={busy} value={amounts[index]?.text ?? ''} onChange={e => setAmounts(previous => previous.map((value, i) => i === index ? { ...value, text: e.target.value } : value))} onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); void log(); } }} /></label>
              <label>Unit<select aria-label={`${part.name} unit`} disabled={busy} value={amounts[index]?.unit ?? part.unit} onChange={e => setAmounts(previous => previous.map((value, i) => i === index ? { ...value, unit: e.target.value as Unit } : value))}>
                {(['g', 'piece', ...(part.unit === 'ml' ? ['ml'] : []), ...(part.unit === 'serving' ? ['serving'] : [])] as Unit[]).map(unit => <option key={unit} value={unit} disabled={unit !== part.unit && !(part.grams_per_piece && ['g', 'piece'].includes(unit) && ['g', 'piece'].includes(part.unit))}>{unitLabel[unit]}</option>)}
              </select></label></div>
          </div>)}
          {nutrition && <div className="habit-food-macros">{(['kcal', 'protein', 'carbs', 'fat'] as const).map(key => <span className={`daily-${key}`} key={key}>{nutrition[key] === null ? '—' : formatNumber(nutrition[key]!)} {key === 'kcal' ? 'kcal' : `${key === 'protein' ? 'P' : key === 'carbs' ? 'C' : 'F'} g`}</span>)}</div>}
          <div className="habit-food-actions"><button type="button" className="daily-button daily-primary" onClick={log} disabled={busy || amounts.some(value => !value.text || Number(value.text) <= 0)}>{busy ? 'Logging…' : 'Log food'}</button>
            <button type="button" className="daily-button" disabled={busy} onClick={() => setSelected(null)}>Choose another</button></div>
          <button type="button" className="text-link daily-retry habit-repeat-remove" disabled={busy} onClick={remove}>Remove from repeats</button>
        </div>}
      </>}
      {error && <p className="daily-error" role="alert">{error}</p>}
      {receipt && <p className="daily-receipt" role="status">{receipt}</p>}
    </div>}
  </div>;
}

function repeatNutrition(food: SavedFood, values: { text: string; unit: Unit }[]): Nutrition | null {
  const result: Nutrition = { kcal: 0, protein: 0, carbs: 0, fat: 0, estimated: false };
  for (const [index, part] of food.components.entries()) {
    const value = Number(values[index]?.text); const unit = values[index]?.unit;
    if (!Number.isFinite(value) || value <= 0 || !part.amount) return null;
    let ratio = value / part.amount;
    if (unit !== part.unit) {
      if (!part.grams_per_piece || !['g', 'piece'].includes(unit) || !['g', 'piece'].includes(part.unit)) return null;
      ratio = value * (unit === 'piece' ? part.grams_per_piece : 1) / (part.amount * (part.unit === 'piece' ? part.grams_per_piece : 1));
    }
    result.estimated ||= part.nutrition.estimated;
    for (const key of ['kcal', 'protein', 'carbs', 'fat'] as const) result[key] = result[key] === null || part.nutrition[key] === null ? null : result[key]! + part.nutrition[key]! * ratio;
  }
  for (const key of ['kcal', 'protein', 'carbs', 'fat'] as const) if (result[key] !== null) result[key] = Math.round(result[key]! * 10) / 10;
  return result;
}
