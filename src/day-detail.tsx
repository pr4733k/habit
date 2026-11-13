'use client';

import { BookOpen, Droplets, Dumbbell, Footprints, Volleyball, X } from 'lucide-react';
import { useEffect, useRef } from 'react';
import {
  dateLabel, durationLabel, formatNumber, hoursLabel, macroKeys, macroLabels, mealGroups, summarize,
  type CalendarEntry, type Goals, type Workout,
} from './model';

export const activityIcons = { gym: Dumbbell, run: Footprints, sport: Volleyball };

function runDetails(run: Workout) {
  const metrics = [
    run.distance_m == null ? null : `${formatNumber(run.distance_m / 1000, 2)} km`,
    run.duration_s == null ? null : durationLabel(run.duration_s),
    run.pace_s_per_km == null ? null : `${durationLabel(run.pace_s_per_km)}/km`,
    run.avg_hr_bpm == null ? null : `${run.avg_hr_bpm} bpm avg`,
  ].filter(Boolean);
  return metrics.length ? metrics.join(' · ') : run.details;
}

export function DayDetail({ date, entries, goals, close }: {
  date: string; entries: CalendarEntry[]; goals: Goals | null; close: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const day = summarize(entries);
  const runs = day.workouts.filter(workout => workout.type === 'run');
  const training = day.workouts.filter(workout => workout.type !== 'run');
  useEffect(() => { dialog.current?.showModal(); }, []);

  return (
    <dialog ref={dialog} className="daily-dialog daily-drawer" aria-labelledby="daily-day-heading"
      onClose={close} onClick={event => { if (event.target === event.currentTarget) dialog.current?.close(); }}>
      <header className="daily-dialog-header daily-drawer-header">
        <div>
          <p className="section-label">{dateLabel(date, { weekday: 'long' })}</p>
          <h2 id="daily-day-heading">{dateLabel(date, { day: 'numeric', month: 'long' })}</h2>
        </div>
        <button className="daily-icon-button" type="button" aria-label="Close day" onClick={() => dialog.current?.close()}><X /></button>
      </header>
      <div className="daily-detail-body">
        {entries.length === 0 && <p className="daily-muted">Nothing logged for this day yet.</p>}
        {(day.meals.length > 0 || day.alcohol.length > 0) && (
          <section className="daily-detail-section">
            <h3 className="section-label">/nutrition</h3>
            <div className="daily-nutrition">
              <div><span>Calories</span><strong>{day.totals.kcal === null ? '—' : formatNumber(day.totals.kcal)}</strong><small>kcal</small></div>
              {macroKeys.map(key => <div className={`daily-${key}`} key={key}>
                <span className="daily-macro-name">{macroLabels[key]}</span>
                <strong>{day.totals[key] === null ? '—' : formatNumber(day.totals[key])}</strong>
                <small>{goals ? `/ ${goals[key]} g` : 'g'}</small>
              </div>)}
            </div>
            {(day.estimated || day.partial) && <p className="daily-muted daily-nutrition-note">
              {[day.estimated && 'Includes estimates', day.partial && 'Some values are missing'].filter(Boolean).join(' · ')}
            </p>}
            {mealGroups.map(group => {
              const items = day.meals.filter(meal => meal.meal === group);
              return items.length > 0 && <div className="daily-detail-group" key={group}>
                <h4>{group}</h4>
                <ul>
                  {items.map((item, index) => <li className="daily-detail-row" key={index}>
                    <div><strong>{item.name}</strong>{item.quantity && <small>{item.quantity}</small>}</div>
                    {item.nutrition?.kcal != null && <span>{item.nutrition.estimated ? '~' : ''}{formatNumber(item.nutrition.kcal)} kcal</span>}
                  </li>)}
                </ul>
              </div>;
            })}
          </section>
        )}
        {training.length > 0 && <section className="daily-detail-section">
          <h3 className="section-label">/workouts</h3>
          <ul>{training.map((workout, index) => {
            const Icon = activityIcons[workout.type];
            return <li className="daily-detail-row daily-detail-icon-row" key={index}>
              <Icon aria-hidden="true" /><div><strong>{workout.name}</strong>{workout.details && <small className="daily-set-details">{workout.details}</small>}</div>
            </li>;
          })}</ul>
        </section>}
        {runs.length > 0 && <section className="daily-detail-section">
          <h3 className="section-label">/runs</h3>
          <ul>{runs.map((run, index) => {
            const details = runDetails(run);
            return <li className="daily-detail-row daily-detail-icon-row" key={index}>
              <Footprints aria-hidden="true" /><div><strong>{run.name}</strong>{details && <small>{details}</small>}</div>
            </li>;
          })}</ul>
        </section>}
        {(day.water.length > 0 || day.alcohol.length > 0 || day.study.length > 0) && <section className="daily-detail-section">
          <h3 className="section-label">/other</h3>
          <ul>
            {day.water.length > 0 && <li className="daily-detail-row daily-detail-icon-row">
              <Droplets aria-hidden="true" /><div><strong>Water</strong><small>
                {formatNumber(day.waterMl)}{goals ? ` / ${formatNumber(goals.water_ml)}` : ''} mL
              </small></div>
            </li>}
            {day.alcohol.map((item, index) => <li className="daily-detail-row" key={`drink-${index}`}>
              <div><strong>{item.name}</strong>{item.quantity && <small>{item.quantity}</small>}</div><span>Alcohol</span>
            </li>)}
            {day.study.map((item, index) => <li className="daily-detail-row daily-detail-icon-row" key={`study-${index}`}>
              <BookOpen aria-hidden="true" /><div><strong>{item.name}</strong>{item.minutes !== null && <small>{hoursLabel(item.minutes)}</small>}</div>
            </li>)}
          </ul>
        </section>}
      </div>
    </dialog>
  );
}
