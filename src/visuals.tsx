import { BookOpen, Dumbbell, Footprints, Volleyball } from 'lucide-react';
import type { CSSProperties } from 'react';
import { macroKeys, type DaySummary, type Goals } from './model';
import './visuals.css';

export const activityIcons = { gym: Dumbbell, run: Footprints, sport: Volleyball, study: BookOpen };
export const activityLabels = { gym: 'Gym', run: 'Run', sport: 'Sport', study: 'Study' };
export const workoutTypes = ['gym', 'run', 'sport'] as const;

export function MacroRings({ totals, goals }: { totals: DaySummary['totals']; goals: Goals | null }) {
  return (
    <span className="daily-rings" aria-hidden="true">
      {macroKeys.map((key, index) => {
        const progress = goals ? Math.min(1, (totals[key] ?? 0) / goals[key]) : 0;
        return <span className={`daily-ring daily-${key}`} key={key} data-full={progress === 1 || undefined}
          style={{ '--progress': `${progress}turn`, '--ring-index': index } as CSSProperties} />;
      })}
    </span>
  );
}

export function WaterWave({ waterMl, goals }: { waterMl: number; goals: Goals | null }) {
  if (!goals || waterMl <= 0) return null;
  const fill = Math.min(100, waterMl / goals.water_ml * 100);
  return <span className="daily-wave" data-full={fill === 100 || undefined}
    style={{ '--water-line': `${100 - fill}%` } as CSSProperties} aria-hidden="true" />;
}
