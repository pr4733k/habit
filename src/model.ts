export const mealGroups = ['Breakfast', 'Lunch', 'Dinner', 'Snack', 'Other'] as const;
export const macroKeys = ['protein', 'carbs', 'fat'] as const;
export const macroLabels = { protein: 'Protein', carbs: 'Carbs', fat: 'Fat' };

export type Macro = typeof macroKeys[number];
export type Goals = Record<Macro, number> & { water_ml: number };
export type GoalTarget = { effectiveDate: string; goals: Goals };
export type Nutrition = Record<Macro | 'kcal', number | null> & { estimated: boolean };
export type Intake = { name: string; quantity: string | null; nutrition: Nutrition | null };
export type Workout = {
  type: 'gym' | 'run' | 'sport';
  name: string;
  details: string | null;
  source?: 'garmin';
  distance_m?: number | null;
  duration_s?: number | null;
  pace_s_per_km?: number | null;
  avg_hr_bpm?: number | null;
};
export type Parsed = {
  meals: (Intake & { meal: typeof mealGroups[number] })[];
  workouts: Workout[];
  water: { mode: 'add' | 'total'; ml: number }[];
  alcohol: Intake[];
  study: { name: string; minutes: number | null }[];
  review: string | null;
};
export type Note = { id: string; date: string; text: string; revision: number; parsed: Parsed | null };
export type CalendarEntry = { date: string; parsed: Omit<Parsed, 'review'> };
export type Month = { entries: CalendarEntry[]; goalTargets: GoalTarget[]; startDate: string };

export function todayIn(timeZone: string) {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date());
}

export function dateLabel(date: string, options: Intl.DateTimeFormatOptions) {
  return new Intl.DateTimeFormat('en-GB', { ...options, timeZone: 'UTC' }).format(new Date(date));
}

export function previousDay(date: string) {
  const value = new Date(date);
  value.setUTCDate(value.getUTCDate() - 1);
  return value.toISOString().slice(0, 10);
}

export function shiftMonth(month: string, by: number) {
  const value = new Date(month + '-01');
  value.setUTCMonth(value.getUTCMonth() + by);
  return value.toISOString().slice(0, 7);
}

export function calendarDays(month: string) {
  const first = new Date(month + '-01');
  const offset = (first.getUTCDay() + 6) % 7;
  const count = new Date(new Date(shiftMonth(month, 1) + '-01').getTime() - 86400000).getUTCDate();
  return Array.from({ length: Math.ceil((offset + count) / 7) * 7 }, (_, index) => {
    const day = index - offset + 1;
    return day < 1 || day > count ? null : `${month}-${String(day).padStart(2, '0')}`;
  });
}

export function runDistance(workout: Workout): number | null {
  if (workout.distance_m != null) return workout.distance_m;
  const match = workout.details?.match(/\b(\d+(?:\.\d+)?)\s*(km|kilomet(?:er|re)s?)\b/i);
  return match ? Math.round(Number(match[1]) * 1000) : null;
}

function mergeWorkouts(workouts: Workout[]) {
  const unmatched = workouts.filter(workout => workout.source === 'garmin');
  return workouts.filter(workout => {
    if (workout.type !== 'run' || workout.source === 'garmin') return true;
    const distance = runDistance(workout);
    if (distance === null) return true;
    const match = unmatched.findIndex(run => run.distance_m != null && Math.abs(run.distance_m - distance) <= 50
      && (workout.duration_s == null || run.duration_s == null || Math.abs(workout.duration_s - run.duration_s) <= 60));
    if (match < 0) return true;
    unmatched.splice(match, 1);
    return false;
  });
}

export function summarize(entries: CalendarEntry[]) {
  const parsed = entries.map(entry => entry.parsed);
  const meals = parsed.flatMap(entry => entry.meals);
  const alcohol = parsed.flatMap(entry => entry.alcohol);
  const workouts = mergeWorkouts(parsed.flatMap(entry => entry.workouts));
  const study = parsed.flatMap(entry => entry.study);
  const intake = [...meals, ...alcohol];
  const totals = { kcal: null, protein: null, carbs: null, fat: null } as Record<Macro | 'kcal', number | null>;
  const nutrients = ['kcal', ...macroKeys] as const;
  for (const key of nutrients) {
    const values = intake.flatMap(item => item.nutrition?.[key] == null ? [] : [item.nutrition[key]]);
    if (values.length) totals[key] = values.reduce((sum, value) => sum + value, 0);
  }
  const water = parsed.flatMap(entry => entry.water);
  const waterMl = water.reduce((sum, item) => item.mode === 'total' ? item.ml : sum + item.ml, 0);
  const studyMinutes = study.reduce((sum, item) => sum + (item.minutes ?? 0), 0);
  return {
    meals, workouts, study, alcohol, totals, water, waterMl, studyMinutes,
    estimated: intake.some(item => item.nutrition?.estimated),
    partial: intake.some(item => nutrients.some(key => item.nutrition?.[key] == null)),
  };
}

export type DaySummary = ReturnType<typeof summarize>;

export function goalsOn(date: string, targets: GoalTarget[]) {
  for (let index = targets.length - 1; index >= 0; index--) {
    if (targets[index].effectiveDate <= date) return targets[index].goals;
  }
  return null;
}

export function summarizeMonth(days: ReadonlyMap<string, DaySummary>, targets: GoalTarget[]) {
  const datedDays = [...days].map(([date, day]) => ({ day, goals: goalsOn(date, targets) }));
  const metrics = ([...macroKeys, 'water_ml'] as const).map(field => {
    const isWater = field === 'water_ml';
    const comparisons = datedDays.flatMap(({ day, goals }) => {
      const value = isWater ? (day.water.length ? day.waterMl : null) : day.totals[field];
      return goals && value !== null ? [value >= goals[field]] : [];
    });
    return {
      key: isWater ? 'water' : field,
      label: isWater ? 'Water' : macroLabels[field],
      hit: targets.length ? comparisons.filter(Boolean).length : null,
    };
  });
  const summaries = [...days.values()];
  const runs = summaries.map(day => day.workouts.filter(workout => workout.type === 'run'));
  return {
    metrics,
    gymDays: summaries.filter(day => day.workouts.some(workout => workout.type === 'gym')).length,
    runDays: runs.filter(day => day.length > 0).length,
    distanceKm: runs.flat().reduce((sum, workout) => sum + (runDistance(workout) ?? 0), 0) / 1000,
    sportDays: summaries.filter(day => day.workouts.some(workout => workout.type === 'sport')).length,
    alcoholDays: summaries.filter(day => day.alcohol.length > 0).length,
    studyDays: summaries.filter(day => day.study.length > 0).length,
  };
}

export function durationLabel(seconds: number) {
  const whole = Math.round(seconds);
  const minutes = Math.floor(whole / 60);
  const rest = String(whole % 60).padStart(2, '0');
  return minutes >= 60
    ? `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}:${rest}`
    : `${minutes}:${rest}`;
}

export const formatNumber = (number: number, decimals = 1) =>
  new Intl.NumberFormat('en', { maximumFractionDigits: decimals }).format(number);
export const hoursLabel = (minutes: number) => minutes >= 60 ? `${formatNumber(minutes / 60)} h` : `${minutes}m`;

export async function api<T>(action: string, params: Record<string, string> = {}, body?: object): Promise<T> {
  const query = new URLSearchParams({ action, ...params });
  let response: Response;
  try {
    response = await fetch('/daily/api.php?' + query, {
      cache: 'no-store',
      ...(body && {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Daily-Request': '1' },
        body: JSON.stringify(body),
      }),
    });
  } catch {
    throw new Error('The daily tracker API is unavailable.');
  }
  const result = await response.json().catch(() => null) as (T & { error?: string }) | null;
  if (!response.ok || !result) throw new Error(result?.error ?? 'The daily tracker API is unavailable.');
  return result;
}
