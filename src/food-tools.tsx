'use client';

import { useEffect, useRef, useState } from 'react';
import { Camera, ImagePlus } from 'lucide-react';
import { api, formatNumber, type Note, type Nutrition } from './model';
import './food-tools.css';

type Repeat = { key: string; source: string; index: number; lastDate: string; count: number; food: { name: string; quantity: string | null; nutrition: Nutrition } };
type Label = { readable: boolean; name: string; unit: 'g' | 'ml' | 'serving'; basis_amount: number; kcal: number | null; protein: number | null; carbs: number | null; fat: number | null; notes: string | null };
const nutrients = ['kcal', 'protein', 'carbs', 'fat'] as const;

export function FoodTools({ date, onSaved, photoRequest }: { date: string; onSaved: (note: Note) => void; photoRequest: number }) {
  const [repeats, setRepeats] = useState<Repeat[]>([]);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [reading, setReading] = useState(false);
  const [error, setError] = useState('');
  const [label, setLabel] = useState<Label | null>(null);
  const [amount, setAmount] = useState('');
  const [receipt, setReceipt] = useState<Note | null>(null);
  const input = useRef<HTMLInputElement>(null);
  const camera = useRef<HTMLInputElement>(null);
  const [photoOptions, setPhotoOptions] = useState(false);
  useEffect(() => { if (photoRequest) setPhotoOptions(true); }, [photoRequest]);
  useEffect(() => { const mode = new URLSearchParams(window.location.search).get('food'); if (mode === 'label') setPhotoOptions(true); if (mode === 'repeat') setOpen(true); }, []);
  const lock = useRef(false);
  const attempt = useRef<{ fingerprint: string; id: string } | null>(null);
  useEffect(() => { let active = true; api<{ meals: Repeat[] }>('repeat-meals').then(data => { if (active) setRepeats(data.meals); }).catch(() => {}); return () => { active = false; }; }, []);
  function id(fingerprint: string) {
    if (!attempt.current) attempt.current = { fingerprint, id: crypto.randomUUID() };
    return attempt.current.id;
  }
  async function save(action: string, data: object) {
    if (lock.current || !date) return;
    lock.current = true; setBusy(true); setError(''); setReceipt(null);
    try {
      const { note } = await api<{ note: Note }>(action, {}, { ...data, date, id: id(JSON.stringify([date, action, data])) });
      attempt.current = null; setReceipt(note); onSaved(note);
      window.dispatchEvent(new Event('daily:updated'));
      if (action === 'save-label') { setLabel(null); setAmount(''); }
    } catch (error) { setError((error as Error).message); }
    finally { lock.current = false; setBusy(false); }
  }
  async function scan(file: File) {
    if (lock.current) return;
    lock.current = true; setBusy(true); setReading(true); setError(''); setReceipt(null); setLabel(null);
    try {
      const image = await compressedLabel(file);
      const body = new FormData(); body.append('image', image, 'label.jpg');
      const response = await fetch('/daily/api.php?action=scan-label', { method: 'POST', credentials: 'same-origin', headers: { 'X-Daily-Request': '1' }, body });
      const data = await response.json() as { label?: Label; error?: string };
      if (!response.ok) throw new Error(data.error || 'Could not read this label. Try again.');
      if (!data.label) throw new Error('No label was returned. Try a clearer photo.');
      setLabel(data.label); setPhotoOptions(false); setAmount('');
    } catch (error) { setError((error as Error).message); }
    finally { lock.current = false; setBusy(false); setReading(false); if (input.current) input.current.value = ''; if (camera.current) camera.current.value = ''; }
  }
  const consumed = Number(amount);
  return <section className="habit-food-tools" aria-label="Quick food logging" aria-busy={busy}>
    <div className="habit-food-actions">
      <button type="button" className="daily-button" disabled={busy} onClick={() => setOpen(value => !value)} aria-expanded={open}>Repeat meal</button>
      <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={event => { const file = event.target.files?.[0]; if (file) void scan(file); }} />
      <input ref={camera} type="file" accept="image/*" capture="environment" hidden onChange={event => { const file = event.target.files?.[0]; if (file) void scan(file); }} />
    </div>
    {open && <div className="habit-food-repeat-list">{repeats.length ? repeats.map(item => <button type="button" key={item.key} disabled={busy} onClick={() => save('repeat-meal', { source: item.source, index: item.index, key: item.key })}>
      <strong>{item.food.name}</strong><span>{item.food.quantity || 'Same logged portion'} · {formatNumber(item.food.nutrition.kcal ?? 0)} kcal{item.food.nutrition.estimated && ' · Estimate'}</span>
    </button>) : <p className="daily-muted">Meals with saved macros will appear here after you log them.</p>}</div>}
    {photoOptions && <div className="habit-photo-options"><p>Photograph the nutrition panel and serving size.</p><div className="habit-food-actions">
      <button type="button" className="daily-button" disabled={busy} onClick={() => camera.current?.click()}><Camera aria-hidden="true" />Take photo</button>
      <button type="button" className="daily-button" disabled={busy} onClick={() => input.current?.click()}><ImagePlus aria-hidden="true" />Choose photo</button>
      <button type="button" className="text-link daily-retry" disabled={busy} onClick={() => { setPhotoOptions(false); setLabel(null); }}>Close</button>
    </div></div>}
    {busy && <p className="daily-muted" role="status">{reading ? 'Reading nutrition label…' : 'Saving…'}</p>}
    {label && <div className="habit-label-preview">
      <strong>{label.name}</strong><p className="daily-muted">Label values per {formatNumber(label.basis_amount)} {label.unit === 'serving' ? 'serving' : label.unit}</p>
      <div className="habit-food-macros">{nutrients.map(key => <span className={`daily-${key}`} key={key}>{label[key] === null ? '—' : formatNumber(label[key]!)} {key === 'kcal' ? 'kcal' : `${key === 'protein' ? 'P' : key === 'carbs' ? 'C' : 'F'} g`}</span>)}</div>
      <details className="habit-label-corrections"><summary>Edit label values</summary>
      <label>Food<input value={label.name} maxLength={160} disabled={busy} onChange={e => setLabel({ ...label, name: e.target.value })} /></label>
      <div className="habit-label-basis"><label>Label values per<input aria-label="Label basis amount" type="number" min="0.01" step="any" value={label.basis_amount} disabled={busy} onChange={e => setLabel({ ...label, basis_amount: Number(e.target.value) })} /></label>
        <label>Unit<select value={label.unit} disabled={busy} onChange={e => setLabel({ ...label, unit: e.target.value as Label['unit'] })}><option value="g">g</option><option value="ml">mL</option><option value="serving">serving</option></select></label></div>
      <div className="habit-label-nutrients">{nutrients.map(key => <label key={key}>{key === 'kcal' ? 'Calories' : key === 'protein' ? 'Protein (g)' : key === 'carbs' ? 'Carbs (g)' : 'Fat (g)'}<input type="number" min="0" step="any" disabled={busy} value={label[key] ?? ''} onChange={e => setLabel({ ...label, [key]: e.target.value === '' ? null : Number(e.target.value) })} /></label>)}</div>
      </details>
      {label.notes && <p className="daily-muted">{label.notes}</p>}
      <p className="daily-muted">Check the label values before saving. Photos go to OpenRouter to read the label and aren’t stored on your Habit server.</p>
      <label>How much did you eat? ({label.unit === 'serving' ? 'servings' : label.unit})<input aria-label="Amount eaten" type="number" inputMode="decimal" min="0.01" step="any" value={amount} disabled={busy} onChange={e => setAmount(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); if (consumed > 0) void save('save-label', { label, amount: consumed }); } }} /></label>
      {consumed > 0 && label.basis_amount > 0 && <div className="habit-food-macros">{nutrients.map(key => <span key={key} className={`daily-${key}`}>{label[key] === null ? '—' : formatNumber(label[key]! * consumed / label.basis_amount)} {key === 'kcal' ? 'kcal' : `${key === 'protein' ? 'P' : key === 'carbs' ? 'C' : 'F'} g`}</span>)}</div>}
      <div className="habit-food-actions"><button type="button" className="daily-button daily-primary" disabled={busy || !label.name.trim() || !label.basis_amount || consumed <= 0} onClick={() => save('save-label', { label, amount: consumed })}>Log food</button>
        <button type="button" className="daily-button" disabled={busy} onClick={() => setLabel(null)}>Cancel</button></div>
    </div>}
    {error && <p className="daily-error" role="alert">{error}</p>}
    {receipt && <p className="daily-receipt" role="status">Logged · {receipt.parsed?.meals[0]?.name} <a className="text-link" href={`/daily?date=${receipt.date}`}>View day</a></p>}
  </section>;
}

async function compressedLabel(file: File): Promise<Blob> {
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 20 * 1024 * 1024) throw new Error('Choose a JPEG, PNG or WebP photo under 20 MB.');
  const bitmap = await createImageBitmap(file);
  try {
    const scale = Math.min(1, 1800 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas'); canvas.width = Math.max(1, Math.round(bitmap.width * scale)); canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext('2d'); if (!context) throw new Error('Your browser could not prepare this photo.');
    context.fillStyle = '#fff'; context.fillRect(0, 0, canvas.width, canvas.height); context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', .88));
    if (!blob || blob.size > 1.9 * 1024 * 1024) throw new Error('This photo is too large. Crop to the nutrition panel and try again.');
    return blob;
  } finally { bitmap.close(); }
}
