'use client';

import { useEffect, useMemo, useState } from 'react';
import { api, dateLabel, summarize, type Month } from './model';
const recentTrainingDates = (today: string, start: string) => Array.from({ length: 7 }, (_, i) => { const d = new Date(today + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() - 6 + i); return d.toISOString().slice(0, 10); }).filter(d => d >= start);
import { activityIcons, activityLabels, workoutTypes } from './visuals';
import { WorkoutPlanner } from './today';

export function RecentMuscles({ today, month, data, active }: { today: string; month: string; data: Month; active: boolean }) {
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [otherMonths, setOtherMonths] = useState<ReadonlyMap<string, Month>>(new Map());
  const [error, setError] = useState(false);
  const [logging, setLogging] = useState(false);
  const dates = recentTrainingDates(today, data.startDate);
  const dateKey = dates.join(',');
  const requiredMonths = [...new Set(dates.filter(date => date >= data.startDate).map(date => date.slice(0, 7)))];

  useEffect(() => {
    let active = true;
    setError(false);
    const missing = requiredMonths.filter(key => key !== month);
    void Promise.all(missing.map(async key => [key, await api<Month>('month', { month: key })] as const))
      .then(results => { if (active) setOtherMonths(new Map(results)); })
      .catch(() => { if (active) setError(true); });
    return () => { active = false; };
  }, [dateKey, month, data]);

  const entries = useMemo(() => requiredMonths.flatMap(key =>
    (key === month ? data : otherMonths.get(key))?.entries.filter(entry => dates.includes(entry.date)) ?? [],
  ), [dateKey, month, data, otherMonths]);
  const days = useMemo(() => new Map(dates.map(date =>
    [date, summarize(entries.filter(entry => entry.date === date))],
  )), [dateKey, entries]);
  const ready = requiredMonths.every(key => key === month || otherMonths.has(key));
  const activeDate = selectedDate && dates.includes(selectedDate) ? selectedDate : today;
  const workouts = days.get(activeDate)?.workouts ?? [];
  const dateText = dateLabel(activeDate, { weekday: 'short', day: 'numeric', month: 'short' });
  const datePicker = <div className="daily-muscle-dates" role="group" aria-label="Recent workout dates">
    {dates.map(date => {
      const activities = ready ? workoutTypes.filter(type => days.get(date)?.workouts.some(workout => workout.type === type)) : [];
      const label = dateLabel(date, { weekday: 'long', day: 'numeric', month: 'long' });
      return <button type="button" key={date} aria-pressed={date === activeDate} disabled={!ready}
        aria-current={date === today ? 'date' : undefined}
        aria-label={`${date === today ? 'Today, ' : ''}${label}${ready ? ` · ${activities.map(type => activityLabels[type]).join(', ') || 'No workout or run'}` : ''}`}
        onClick={() => setSelectedDate(date)}>
        <span>{date === today ? 'Today' : dateLabel(date, { weekday: 'short' })}</span>
        <strong>{dateLabel(date, { day: 'numeric' })}</strong>
        <span className="daily-muscle-date-icons" aria-hidden="true">{activities.map(type => {
          const Icon = activityIcons[type];
          return <Icon key={type} />;
        })}</span>
      </button>;
    })}
  </div>;

  return (
    <div className="daily-recent-muscles">
      <div hidden={logging && activeDate === today}>{datePicker}</div>
      <div hidden={activeDate !== today}><WorkoutPlanner active={active && activeDate === today} workouts={days.get(today)?.workouts ?? []} onLoggingChange={setLogging} /></div>
      {ready && <div hidden={logging && activeDate === today}><section><h3 className="section-label">/workout</h3><p className="daily-muted">{dateText}</p>{workouts.length ? workouts.map((w,i) => <article key={i}><h4>{w.name}</h4><p style={{whiteSpace:"pre-line"}}>{w.details}</p></article>) : <p className="daily-muted">No workout or run logged for this day.</p>}</section></div>}
      {!ready && <p className="daily-muscle-note" role="status">{error ? 'Could not load muscles for these dates.' : 'Loading muscles…'}</p>}
    </div>
  );
}
