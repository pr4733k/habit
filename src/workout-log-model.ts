import { type Workout } from './model';

export const weightBases = ['total', 'per_dumbbell', 'per_side', 'bodyweight', 'assistance', 'added_weight'] as const;
export type WeightBasis = typeof weightBases[number] | null;
export type SetGroup = { weight: number | null; reps: number | null; sets: number; weight_unit: 'kg' | 'lb' | null; weight_basis: WeightBasis };
export type ExerciseEntry = { id: string; date: string; revision: number; key: string; name: string; groups: SetGroup[]; workout: Workout & { venue?: string | null }; external?: boolean };
export type SessionExercise = {
  progression?: string | null;
  key: string; name: string; planned: { sets: number | null; reps: string | null; load: string | null; notes: string | null } | null;
  previous: { date: string; name: string; groups: SetGroup[]; complete: boolean; details: string | null } | null;
  saved: ExerciseEntry | null;
};
export type WorkoutSessionData = { date: string; title: string; venue: string | null; minutes: number | null; exercises: SessionExercise[] };
export type DraftGroup = { weight: string; reps: string; sets: string; weight_unit: 'kg' | 'lb'; weight_basis: WeightBasis };
export type ExerciseDraft = { groups: DraftGroup[]; revision: number | null; dirty: boolean };
export type SessionDraft = { version: 1; active: boolean; open: string | null; exercises: Record<string, ExerciseDraft> };

const basisLabels: Record<Exclude<WeightBasis, null>, string> = {
  total: 'total', per_dumbbell: 'per dumbbell', per_side: 'per side', bodyweight: 'Bodyweight', assistance: 'assistance', added_weight: 'added weight',
};
export function loadLabel(unit: string | null, basis: WeightBasis) {
  return basis === 'bodyweight' ? 'Bodyweight' : [unit ?? 'Weight', basis && basisLabels[basis]].filter(Boolean).join(' ');
}
export function groupSummary(groups: SetGroup[]) {
  return groups.map(group => `${group.weight_basis === 'bodyweight' ? 'Bodyweight' : `${group.weight ?? '—'} ${loadLabel(group.weight_unit, group.weight_basis)}`} × ${group.reps ?? '—'} reps × ${group.sets}`).join(' · ');
}
export function exerciseDraft(exercise: SessionExercise): ExerciseDraft {
  const previous = exercise.saved?.groups ?? (exercise.previous?.complete ? exercise.previous.groups : []);
  const groups: DraftGroup[] = previous.length > 0 && previous.length <= 20 ? previous.map(group => ({
    weight: group.weight !== null && group.weight_unit !== null ? String(group.weight) : '',
    reps: group.reps === null ? '' : String(group.reps), sets: String(group.sets),
    weight_unit: group.weight_unit ?? 'kg', weight_basis: group.weight_basis,
  })) : [{ weight: '', reps: '', sets: String(exercise.planned?.sets ?? 1), weight_unit: 'kg', weight_basis: null }];
  return { groups, revision: exercise.saved?.revision ?? null, dirty: false };
}
export function exercisePayload(groups: DraftGroup[]): SetGroup[] {
  let count = 0;
  if (!groups.length || groups.length > 20) throw new Error('Add between 1 and 20 set groups.');
  return groups.map(group => {
    const number = (text: string, max: number, whole = false): number | null => {
      if (!text.trim()) return null;
      if (!/^\d+(?:\.\d+)?$/.test(text.trim())) throw new Error('Use numbers for weights, reps and sets.');
      const value = Number(text);
      if (!Number.isFinite(value) || value < 0 || value > max || (whole && !Number.isInteger(value))) throw new Error('Check the weights, reps and sets.');
      return value;
    };
    const sets = number(group.sets, 30, true);
    const reps = number(group.reps, 1000, true);
    if (!sets || reps === 0) throw new Error('Enter at least one rep and set.');
    count += sets;
    if (count > 100) throw new Error('Record at most 100 sets per exercise.');
    return { weight: group.weight_basis === 'bodyweight' ? null : number(group.weight, 3000), reps, sets,
      weight_unit: group.weight_basis === 'bodyweight' ? null : group.weight_unit, weight_basis: group.weight_basis };
  });
}
export const sessionStorageKey = (date: string) => `habit:workout-session:${date}`;
export function readSessionDraft(value: string | null): SessionDraft | null {
  if (!value || value.length > 128000) return null;
  try {
    const data = JSON.parse(value) as SessionDraft;
    if (data.version !== 1 || typeof data.active !== 'boolean' || !data.exercises || typeof data.exercises !== 'object'
      || (data.open !== null && typeof data.open !== 'string') || Object.keys(data.exercises).length > 100) return null;
    for (const draft of Object.values(data.exercises)) {
      if (!draft || typeof draft.dirty !== 'boolean' || !(draft.revision === null || Number.isInteger(draft.revision) && draft.revision > 0)
        || !Array.isArray(draft.groups) || !draft.groups.length || draft.groups.length > 20) return null;
      for (const group of draft.groups) {
        if (!group || !['weight', 'reps', 'sets'].every(key => typeof group[key as keyof DraftGroup] === 'string' && String(group[key as keyof DraftGroup]).length <= 32)
          || !['kg', 'lb'].includes(group.weight_unit) || !(group.weight_basis === null || weightBases.includes(group.weight_basis))) return null;
      }
    }
    return data;
  } catch { return null; }
}
