import { readFile } from 'node:fs/promises';

export async function processorConfig() {
  const path = process.env.DAILY_PROCESSOR_CONFIG ?? new URL('../.daily-data/processor.json', import.meta.url);
  const { url, token } = JSON.parse(await readFile(path, 'utf8'));
  const endpoint = new URL(url);
  if (endpoint.protocol !== 'https:' && !(endpoint.protocol === 'http:' && endpoint.hostname === '127.0.0.1')) {
    throw new Error('Use HTTPS for a hosted tracker.');
  }
  if (endpoint.pathname !== '/daily/api.php' || endpoint.username || endpoint.password || !token) {
    throw new Error('Set the tracker’s /daily/api.php URL and processor token.');
  }
  return { endpoint, token };
}
