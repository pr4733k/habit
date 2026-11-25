import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
const source = ts.transpileModule(await readFile(new URL('../src/workout-log-model.ts', import.meta.url), 'utf8'),
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } }).outputText;
const model = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const group = { weight: 15, reps: 10, sets: 3, weight_unit: 'kg', weight_basis: 'per_dumbbell' };
const exercise = { key: 'press', name: 'Dumbbell chest press', planned: { sets: 3, reps: '8–10' }, previous: { date: '2026-11-11', groups: [group], complete: true }, saved: null };
test('Previous values populate drafts without becoming completed sets', () => {
  const draft = model.exerciseDraft(exercise);
  assert.equal(draft.dirty, false); assert.equal(draft.revision, null);
  assert.deepEqual(draft.groups[0], { weight: '15', reps: '10', sets: '3', weight_unit: 'kg', weight_basis: 'per_dumbbell' });
  assert.equal(exercise.saved, null);
});
test('Missing reps/units and incompletely parsed records never produce invented values', () => {
  const draft = model.exerciseDraft({ ...exercise, previous: { ...exercise.previous, groups: [{ ...group, reps: null, weight_unit: null, weight_basis: null }] } });
  assert.equal(draft.groups[0].weight, ''); assert.equal(draft.groups[0].reps, ''); assert.equal(draft.groups[0].weight_basis, null);
  const partial = model.exerciseDraft({ ...exercise, previous: { ...exercise.previous, complete: false } });
  assert.equal(partial.groups[0].weight, ''); assert.equal(partial.groups[0].reps, '');
});
test('Saving separate groups preserves decimals, load basis and bodyweight', () => {
  const groups = model.exerciseDraft(exercise).groups;
  assert.deepEqual(model.exercisePayload([...groups, { ...groups[0], weight: '17.5', reps: '8', sets: '1' }]), [group, { ...group, weight: 17.5, reps: 8, sets: 1 }]);
  assert.deepEqual(model.exercisePayload([{ ...groups[0], weight: '', weight_basis: 'bodyweight' }])[0], { ...group, weight: null, weight_unit: null, weight_basis: 'bodyweight' });
  assert.equal(model.exercisePayload([{ ...groups[0], weight: '', reps: '' }])[0].reps, null);
});
test('Invalid values fail before a save request', () => {
  const draft = model.exerciseDraft(exercise).groups[0];
  for (const change of [{ weight: '-1' }, { weight: 'Infinity' }, { reps: '1.5' }, { reps: '0' }, { sets: '0' }, { sets: '31' }]) assert.throws(() => model.exercisePayload([{ ...draft, ...change }]));
});
test('Drafts round-trip across refreshes with their original edit revision', () => {
  const state = { version: 1, active: true, open: 'press', exercises: { press: { ...model.exerciseDraft(exercise), revision: 2, dirty: true } } };
  assert.deepEqual(model.readSessionDraft(JSON.stringify(state)), state);
  assert.notEqual(model.sessionStorageKey('2026-11-25'), model.sessionStorageKey('2026-11-26'));
  for (const input of [null, '{', '{}', JSON.stringify({ ...state, exercises: { press: { groups: [] } } }), JSON.stringify({ ...state, version: 9 })]) assert.equal(model.readSessionDraft(input), null);
});
