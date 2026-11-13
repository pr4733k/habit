'use client';

import { Tooltip } from '@base-ui/react/tooltip';
import { BookOpen, ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useMemo, useState, type CSSProperties } from 'react';
import { activityIcons, DayDetail } from './day-detail';
import {
  api, calendarDays, dateLabel, formatNumber, goalsOn, macroKeys, macroLabels, previousDay, shiftMonth, todayIn,
  summarize, summarizeMonth, type CalendarEntry, type DaySummary, type Goals, type GoalTarget, type Month, type Workout,
} from './model';

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const activityLabels = { gym: 'Gym', run: 'Run', sport: 'Sport' };
const activityOrder: Workout['type'][] = ['gym', 'run', 'sport'];

function DayTile({ date, today, day, goals, select }: {
  date: string; today: string; day: DaySummary; goals: Goals | null; select: () => void;
}) {
  const activities = activityOrder.filter(type => day.workouts.some(workout => workout.type === type));
  const water = goals && day.waterMl ? Math.min(100, day.waterMl / goals.water_ml * 100) : 0;
  const hasAlcohol = day.alcohol.length > 0;
  const hasMacros = macroKeys.some(key => day.totals[key] !== null);
  const future = date > today;
  const summary = [
    dateLabel(date, { weekday: 'long', month: 'long', day: 'numeric' }),
    ...activities.map(type => activityLabels[type]),
    ...(day.water.length ? [`Water ${day.waterMl} mL`] : []),
    ...(hasAlcohol ? ['Alcohol logged'] : []),
    ...(day.study.length ? ['Study logged'] : []),
  ];
  const tileProps = {
    type: 'button',
    className: 'daily-tile',
    'data-today': date === today || undefined,
    'data-alcohol': hasAlcohol || undefined,
    'data-future': future || undefined,
    disabled: future,
    'aria-current': date === today ? 'date' : undefined,
    'aria-label': summary.join(', '),
    onClick: select,
  } as const;
  const tileContent = (
    <>
      {water > 0 && <span className="daily-wave" data-full={water === 100 || undefined}
        style={{ '--water-line': `${100 - water}%` } as CSSProperties} aria-hidden="true" />}
      <span className="daily-tile-head">
        <span className="daily-date">{Number(date.slice(-2))}</span>
        <span className="daily-activities" aria-hidden="true">
          {activities.map(type => {
            const Icon = activityIcons[type];
            return <Icon key={type} />;
          })}
        </span>
      </span>
      {goals && hasMacros && <span className="daily-rings" aria-hidden="true">
        {macroKeys.map((key, index) => {
          const progress = Math.min(1, (day.totals[key] ?? 0) / goals[key]);
          return <span className={`daily-ring daily-${key}`} key={key} data-full={progress === 1 || undefined}
            style={{ '--progress': `${progress}turn`, '--inset': `${index * 12.5}%` } as CSSProperties} />;
        })}
      </span>}
      <span className="daily-tile-foot" aria-hidden="true">
        {day.study.length > 0 && <span className="daily-study-icon"><BookOpen /></span>}
      </span>
    </>
  );

  if (future || !hasMacros) return <button {...tileProps}>{tileContent}</button>;

  const tooltipId = `daily-macros-${date}`;
  return (
    <Tooltip.Root>
      <Tooltip.Trigger {...tileProps} delay={180}
        render={(props, { open }) => <button {...props} aria-describedby={open ? tooltipId : undefined} />}>
        {tileContent}
      </Tooltip.Trigger>
      <Tooltip.Portal>
        <Tooltip.Positioner className="daily-macro-tooltip-positioner" sideOffset={6} collisionPadding={8}>
          <Tooltip.Popup id={tooltipId} role="tooltip" className="daily-macro-tooltip">
            <dl>
              {macroKeys.map(key => {
                const value = day.totals[key];
                return <div key={key}>
                  <dt>{macroLabels[key]}</dt>
                  <dd>{value === null ? '—' : `${formatNumber(value)} g`}</dd>
                </div>;
              })}
            </dl>
          </Tooltip.Popup>
        </Tooltip.Positioner>
      </Tooltip.Portal>
    </Tooltip.Root>
  );
}

const emptyDay = summarize([]);
const periodFormat = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });

function MonthSummary({ days, targets, first, last }: {
  days: ReadonlyMap<string, DaySummary>; targets: GoalTarget[]; first: string; last: string;
}) {
  const summary = summarizeMonth(new Map([...days].filter(([date]) => date >= first && date <= last)), targets);
  const firstDate = new Date(first);
  const lastDate = new Date(last);
  const dayCount = Math.max(0, (lastDate.getTime() - firstDate.getTime()) / 86_400_000 + 1);
  const period = dayCount ? `${periodFormat.formatRange(firstDate, lastDate)} · ` : '';
  const activity = [
    { key: 'gym', label: 'Gym', count: summary.gymDays, Icon: activityIcons.gym },
    { key: 'run', label: 'Run', count: summary.runDays, Icon: activityIcons.run, detail: `${formatNumber(summary.distanceKm, 2)} km` },
    ...(summary.sportDays > 0 ? [{ key: 'sport', label: 'Sport', count: summary.sportDays, Icon: activityIcons.sport }] : []),
    { key: 'study', label: 'Study', count: summary.studyDays, Icon: BookOpen },
    { key: 'alcohol', label: 'Alcohol', count: summary.alcoholDays, Icon: null },
  ];
  return (
    <div className="daily-month-summary">
      <div className="daily-summary-heading">
        <h3 className="section-label">/goals-hit</h3>
        <span>{period}{dayCount} {dayCount === 1 ? 'day' : 'days'}</span>
      </div>
      <ul className="daily-goal-key" aria-label="Days each goal was hit">
        {summary.metrics.map(({ key, label, hit }) => <li className={`daily-key-chip daily-${key}`} key={key}>
          {label}
          {hit !== null && <strong className="daily-key-count">{hit}<span className="sr-only"> {hit === 1 ? 'day' : 'days'}</span></strong>}
        </li>)}
      </ul>
      <ul className="daily-goal-key" aria-label="Days each activity was logged">
        {activity.map(({ key, label, count, Icon, detail }) => (
          <li className={`daily-key-chip daily-${key}`} key={key} title={key === 'alcohol' ? 'Red marks an alcohol day' : undefined}>
            {Icon && <Icon aria-hidden="true" />}
            {label}{detail && <span className="daily-key-detail">{detail}</span>}
            <strong className="daily-key-count">{count}<span className="sr-only"> {count === 1 ? 'day' : 'days'}</span></strong>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function Calendar({ timeZone }: { timeZone: string }) {
  const today = todayIn(timeZone);
  const currentMonth = today.slice(0, 7);
  const [month, setMonth] = useState(currentMonth);
  const [selected, setSelected] = useState<string | null>(null);
  const [data, setData] = useState<Month | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    const query = new URLSearchParams(window.location.search);
    const date = query.get('date');
    const shownMonth = query.get('month');
    const latestDate = todayIn(timeZone);
    if (date && date <= latestDate && /^\d{4}-\d{2}-\d{2}$/.test(date) && Number.isFinite(Date.parse(date))) {
      setMonth(date.slice(0, 7));
      setSelected(date);
    } else if (shownMonth && shownMonth <= latestDate.slice(0, 7) && /^\d{4}-(0[1-9]|1[0-2])$/.test(shownMonth)) {
      setMonth(shownMonth);
    }
  }, [timeZone]);

  useEffect(() => {
    let active = true;
    let sequence = 0;
    setData(null);
    setError('');
    async function load() {
      const request = ++sequence;
      try {
        const result = await api<Month>('month', { month });
        if (active && request === sequence) {
          const firstMonth = result.startDate.slice(0, 7);
          if (month < firstMonth) {
            setSelected(null);
            setMonth(firstMonth);
            return;
          }
          setData(result);
          setError('');
        }
      } catch (error) {
        if (active && request === sequence) setError((error as Error).message);
      }
    }
    void load();
    window.addEventListener('focus', load);
    const interval = window.setInterval(() => { if (!document.hidden) void load(); }, 30000);
    return () => {
      active = false;
      window.removeEventListener('focus', load);
      window.clearInterval(interval);
    };
  }, [month]);

  const days = useMemo(() => {
    const result = new Map<string, CalendarEntry[]>();
    for (const entry of data?.entries ?? []) {
      const list = result.get(entry.date) ?? [];
      list.push(entry);
      result.set(entry.date, list);
    }
    return result;
  }, [data]);
  const summaries = useMemo(() => new Map([...days].map(([date, entries]) => [date, summarize(entries)])), [days]);

  const monthDays = data ? calendarDays(month) : [];
  const start = data?.startDate;
  const firstMonth = start?.slice(0, 7);
  const targets = data?.goalTargets ?? [];
  const leadingWeeks = start ? Math.max(0, Math.floor(monthDays.indexOf(start) / 7)) : 0;
  const cells = monthDays.slice(leadingWeeks * 7);
  const monthStart = month + '-01';
  const summaryStart = start && start > monthStart ? start : monthStart;
  const summaryEnd = previousDay(month === currentMonth ? today : shiftMonth(month, 1) + '-01');

  function navigate(by: number) {
    const next = shiftMonth(month, by);
    if (!firstMonth || next < firstMonth || next > currentMonth) return;
    setSelected(null);
    setMonth(next);
  }

  return (
    <section aria-labelledby="daily-month-heading">
      <div className="daily-calendar-toolbar" data-month-navigation={!!firstMonth && firstMonth < currentMonth || undefined}>
        <div className="daily-month">
          <h2 id="daily-month-heading">{dateLabel(month + '-01', { month: 'long', year: 'numeric' })}</h2>
          <div className="daily-month-arrows">
            <button type="button" className="daily-icon-button" onClick={() => navigate(-1)}
              disabled={!firstMonth || month <= firstMonth} aria-label="Previous month">
              <ChevronLeft aria-hidden="true" />
            </button>
            <button type="button" className="daily-icon-button" onClick={() => navigate(1)}
              disabled={month >= currentMonth} aria-label="Next month">
              <ChevronRight aria-hidden="true" />
            </button>
          </div>
        </div>
        <div className="daily-month-actions">
          <button type="button" className="daily-button" onClick={() => { setSelected(null); setMonth(currentMonth); }}>Today</button>
        </div>
      </div>
      {error && <p className="daily-error" role="alert">{error}</p>}
      {!data && !error && <p className="daily-muted" role="status">Loading calendar…</p>}
      <div className="daily-weekdays" aria-hidden="true">
        {weekdays.map(day => <span key={day}>{day}</span>)}
      </div>
      <div className="daily-grid" aria-busy={!data && !error}>
        {cells.map((date, index) => date && start && date >= start
          ? <DayTile key={date} date={date} today={today} day={summaries.get(date) ?? emptyDay}
              goals={goalsOn(date, targets)} select={() => setSelected(date)} />
          : <span className="daily-tile daily-spacer" key={index} aria-hidden="true" />)}
      </div>
      {data && <MonthSummary days={summaries} targets={targets} first={summaryStart} last={summaryEnd} />}
      {selected && start && selected >= start && selected <= today && <DayDetail key={selected} date={selected} goals={goalsOn(selected, targets)}
        entries={days.get(selected) ?? []} close={() => setSelected(null)} />}
    </section>
  );
}
