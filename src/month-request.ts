import { api, type Month } from './model';
export const loadCalendarMonth = (month: string) => api<Month>('month', { month });
