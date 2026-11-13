import { readFile } from 'node:fs/promises';
import { stdin } from 'node:process';
import { processorConfig } from './processor-config.mjs';

const [action, filename] = process.argv.slice(2);
if (!['day', 'pending', 'commit', 'finish', 'runs'].includes(action)) {
  console.error('Usage: node scripts/daily-sync.mjs <day [YYYY-MM-DD] | pending | finish [json-file] | commit [json-file] | runs <runs.json>>');
  process.exit(2);
}

const runNames = {
  easy: 'Easy run', recovery: 'Recovery run', long_run: 'Long run', steady: 'Steady run',
  tempo_threshold: 'Tempo run', intervals: 'Intervals', hills: 'Hill run', race: 'Race', time_trial: 'Time trial',
};
function runSummaries(book, runDate, today) {
  if (!Array.isArray(book.runs)) throw new Error('The import file must contain a runs array.');
  return book.runs.flatMap(run => {
    if (!run.started_at) return [];
    const date = runDate.format(new Date(run.started_at));
    if (date > today) return [];
    const metrics = run.measurements ?? {};
    return [{
      activity_id: run.activity_id,
      date,
      name: Object.hasOwn(runNames, run.workout_type) ? runNames[run.workout_type] : 'Run',
      distance_m: metrics.distance_m ?? null,
      duration_s: metrics.timer_time_s ?? null,
      pace_s_per_km: metrics.avg_pace_s_per_km ?? null,
      avg_hr_bpm: metrics.avg_hr_bpm ?? null,
    }];
  });
}

async function readPayload() {
  if (filename) return readFile(filename, 'utf8');
  stdin.setEncoding('utf8');
  let body = '';
  for await (const chunk of stdin) body += chunk;
  return body;
}

function pendingNotes(result) {
  if (!Array.isArray(result.notes)) throw new Error('The tracker did not return a pending queue.');
  return result.notes.map(({ id, date, revision, text }) => ({ id, date, revision, text }));
}

try {
  const { endpoint, token } = await processorConfig();
  async function request(operation, body, date) {
    const url = new URL(endpoint);
    url.searchParams.set('action', operation);
    if (date) url.searchParams.set('date', date);
    const headers = { Authorization: `Bearer ${token}` };
    if (body) headers['Content-Type'] = 'application/json';
    const response = await fetch(url, {
      method: body ? 'POST' : 'GET',
      headers, body, redirect: 'error', signal: AbortSignal.timeout(15000),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(`${response.status} ${result.error ?? 'Request failed'}`);
    return result;
  }
  if (action === 'day') {
    const date = filename ?? (await request('runs')).today;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) throw new Error('Use a date in YYYY-MM-DD format.');
    console.log(JSON.stringify(await request('day', undefined, date)));
  } else if (action === 'runs') {
    if (!filename) throw new Error('Supply the path to a runs JSON file.');
    const book = JSON.parse(await readFile(filename, 'utf8'));
    const [{ today }, { timeZone }] = await Promise.all([request('runs'), request('settings')]);
    const runDate = new Intl.DateTimeFormat('en-CA', {
      timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
    });
    const runs = runSummaries(book, runDate, today);
    let synced = 0;
    for (let index = 0; index < runs.length; index += 100) {
      const result = await request('runs', JSON.stringify({ runs: runs.slice(index, index + 100) }));
      synced += result.synced;
    }
    console.log(JSON.stringify({ synced }));
  } else if (action === 'finish') {
    const payload = JSON.parse(await readPayload());
    const entries = Array.isArray(payload) ? payload : [payload];
    if (!entries.length || entries.length > 25 || entries.some(entry => typeof entry?.id !== 'string' || !entry.id)) {
      throw new Error('Supply 1–25 parsed notes with their IDs.');
    }
    if (new Set(entries.map(entry => entry.id.toLowerCase())).size !== entries.length) {
      throw new Error('Each note ID must occur only once per batch.');
    }
    const summary = { synced: 0, failures: [] };
    for (let index = 0; index < entries.length; index += 4) {
      const group = entries.slice(index, index + 4);
      const receipts = await Promise.allSettled(group.map(async entry => {
        const { note } = await request('commit', JSON.stringify(entry));
        if (note?.id !== entry.id.toLowerCase() || note.revision !== entry.revision || !note.parsed) {
          throw new Error('The tracker did not confirm this note and revision.');
        }
      }));
      for (const [offset, receipt] of receipts.entries()) {
        if (receipt.status === 'fulfilled') {
          summary.synced++;
        } else {
          summary.failures.push({ id: group[offset].id, error: receipt.reason.message });
        }
      }
    }
    try {
      summary.pending = pendingNotes(await request('pending'));
    } catch (error) {
      summary.queueError = error.message;
    }
    console.log(JSON.stringify(summary));
    if (summary.failures.length || summary.queueError) process.exitCode = 1;
  } else if (action === 'commit') {
    const body = await readPayload();
    JSON.parse(body);
    const result = await request('commit', body);
    console.log(JSON.stringify({
      id: result.note.id,
      revision: result.note.revision,
      saved: true,
    }, null, 2));
  } else {
    const result = await request('pending');
    console.log(JSON.stringify({ notes: pendingNotes(result) }));
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
