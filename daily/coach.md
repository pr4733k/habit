# Workout coach feed

`GET /daily/coach.json?days=14` returns processed workout information in one server-rendered JSON response. It uses the same public-read access as the calendar. Raw notes, pending note text, meals, water, study, alcohol, and credentials are excluded. Private settings writes require the existing processor token or an unlocked browser with the existing request header.

`days` defaults to 14 and accepts whole numbers from 1 through 60. Every calendar date from `today - (days - 1)` through today is included, even before tracking started. Dates use `the configured timezone`. `upcoming_range` covers tomorrow through seven days from today, independently of the historical range. Planned sessions for today appear in that day's `planned` collection.

## Response

- `schema_version`: currently `1`.
- `timezone`, `today`, `range`: date context for the request.
- `last_updated_at`: persisted UTC timestamp of the latest settings save or successful note/run update since coach support was enabled. It can be `null` on an unconfigured installation. A GET does not change it.
- `days`: chronological entries with `date`, `training_status`, `rest_logged`, `completed`, `planned`, and `recovery`.
- `upcoming_fixed`: planned fixed or explicitly logged future sessions within `upcoming_range`.
- `preferences`: training goals, available session minutes, preferred gym venue, and weekly running goals. Unspecified values remain `null` or an empty goals list.

`training_status` is `completed` when a completed workout exists, `rest` only when rest was explicitly recorded, and `unlogged` otherwise. Food-only days stay `unlogged`. If rest and training were both recorded, completed activity is retained and `rest_logged` remains true.

Gym entries are grouped by date, type, and explicit session ID or venue. Legacy exercises with no session ID or venue are grouped into one session with unknown venue. A preferred gym is never used to invent historical locations. Durations are only those actually logged; calorie-estimation defaults are excluded.

Each exercise has ordered `sets`. A set includes `reps`, `weight`, `weight_unit` (`kg` or `lb`), `weight_basis`, and `warmup`, plus optional `rir`, effort from 0 to 10, and notes. Load bases are `total`, `per_dumbbell`, `per_side`, `bodyweight`, `assistance`, and `added_weight`. Missing fields are `null`. Unqualified legacy weights retain an unknown basis; explicit per-hand or per-side values are not doubled. A bodyweight set has no invented body mass. Warm-up status is unknown unless stated.

Known text formats such as `50 kg × 8 reps`, `2 sets × 10 reps at 15 kg per hand`, or `3 sets at 7.5 kg each leg` are expanded conservatively. Missing reps stay null. Unparsed or ambiguous descriptions, including `4 × 100 kg`, are preserved in exercise `details` with no invented sets.

Runs include distance in meters, duration in seconds, average pace in seconds/km, and average heart rate in bpm. Matching manual and Garmin runs use the calendar's distance/duration matching rule and appear once; optional effort and notes are retained. A date can include both running and lifting.

Recovery observations expose their `recorded_at` UTC timestamp and optional `observed_at` timestamp, muscle soreness, pain notes, sleep hours, fatigue and notes. Unknown measurements remain null. An empty recovery list means no observations were logged, not that recovery is good.

## Manage schedules and preferences

Use authenticated `GET /daily/api.php?action=coach-settings` to read settings and authenticated `POST` to replace them. Read the current settings before editing so other rules are preserved. The response includes `settings` and `updated_at`. The public coach feed cannot write settings.

```json
{
  "preferences": {
    "training_goals": [],
    "available_session_minutes": null,
    "gym_venue": null,
    "weekly_running_goal": null
  },
  "fixed_sessions": [
    {
      "id": "friday-lifting",
      "type": "gym",
      "venue": "My gym",
      "weekday": 5,
      "start_date": "2026-11-18",
      "end_date": null,
      "exercises": ["Squats"],
      "muscle_groups": ["quads", "glutes"],
      "exceptions": [
        {"date": "2026-11-20", "cancelled": true},
        {"date": "2026-11-27", "move_to": "2026-11-28", "venue": "Another gym"}
      ]
    }
  ]
}
```

Weekdays use ISO numbering: Monday 1 through Sunday 7. Start and end dates are inclusive; a null end stays active until changed. Exceptions can cancel, move, or override the venue, exercises, muscle groups or notes for one scheduled occurrence. A moved occurrence is included when its destination is in range, even if the original occurrence is outside the requested range.

A fixed session stays planned until a completed session is logged on the same date with a matching `fixed_session_id` or matching type and explicit venue. Expected exercises do not become completed sets automatically.

Apply `daily/schema.sql` when initializing an installation. The private settings save can also create its additive settings table on first use. Existing training records are read without being rewritten.

## Generated workout suggestions

A day can include an optional recommendation with status planned, source habit-ai, kind (lift, run, or rest), and the saved session details. It never changes completed training, rest_logged, or existing planned-session formats. Generating a suggestion requires the existing owner sign-in.
