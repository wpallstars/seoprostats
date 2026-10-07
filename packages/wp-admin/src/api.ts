/**
 * Reading the API through WordPress's apiFetch (logged-in cookie and
 * nonce) and TanStack Query (cache, deduplication, refresh).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { QueryClient, keepPreviousData, useQuery } from '@tanstack/react-query';
import {
	apiArgs,
	type BreakdownAnswer,
	type ChangesAnswer,
	type ClickKind,
	type ClicksAnswer,
	type Dimension,
	type Funnel,
	type FunnelsAnswer,
	type Goal,
	type GoalStep,
	type GoalsAnswer,
	type Marker,
	type MarkersAnswer,
	type PropertiesAnswer,
	type RealtimeAnswer,
	type SearchAnswer,
	type SearchKind,
	type StatsAnswer,
	type TimeseriesAnswer,
	type ViewState,
} from '@seoprostats/core';
import { boot, type DataSet, type DemoStatus } from './boot';
import { useDataSet } from './data';

const NAMESPACE = '/seoprostats/v1';

type Args = Record<string, string | string[] | number>;

export function get<T>(route: string, args: Args = {}): Promise<T> {
	return apiFetch<T>({ path: addQueryArgs(`${NAMESPACE}/${route}`, args) });
}

function send<T>(route: string, method: 'POST' | 'DELETE', data: Record<string, unknown> = {}): Promise<T> {
	return apiFetch<T>({ path: `${NAMESPACE}/${route}`, method, data });
}

/** Report arguments for a data set: live is the default, so it is left out. */
function withData(args: Args, data: DataSet): Args {
	return data === 'demo' ? { ...args, data } : args;
}

export const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			// The server caches answers for five minutes; a minute here is plenty.
			staleTime: 60 * 1000,
			refetchOnWindowFocus: false,
			retry: 1,
		},
	},
});

type Scope = Pick<ViewState, 'range' | 'from' | 'to' | 'filters' | 'compare'>;

/** Reports wait while the chosen demo data is not made yet (they would fail). */
function useReportData(): { data: DataSet; enabled: boolean } {
	const data = useDataSet();
	const demo = useDemo();
	return { data, enabled: data === 'live' || demo.data?.status === 'ready' };
}

export function useStats(scope: Scope) {
	const { data, enabled } = useReportData();
	const args = withData(apiArgs(scope), data);
	return useQuery({
		queryKey: ['stats', args],
		queryFn: () => get<StatsAnswer>('stats', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

export function useTimeseries(scope: Scope) {
	const { data, enabled } = useReportData();
	const args = withData(apiArgs(scope), data);
	return useQuery({
		queryKey: ['timeseries', args],
		queryFn: () => get<TimeseriesAnswer>('timeseries', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

export function useBreakdown(scope: Omit<Scope, 'compare'>, dimension: Dimension, limit = 10) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs(scope), dimension, limit }, data);
	return useQuery({
		queryKey: ['breakdown', args],
		queryFn: () => get<BreakdownAnswer>('breakdown', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

export function useRealtime() {
	const { data, enabled } = useReportData();
	const args = withData({}, data);
	return useQuery({
		queryKey: ['realtime', args],
		queryFn: () => get<RealtimeAnswer>('realtime', args),
		refetchInterval: 30 * 1000,
		staleTime: 0,
		enabled,
	});
}

export function useGoals(scope: Scope) {
	const { data, enabled } = useReportData();
	const args = withData(apiArgs(scope), data);
	return useQuery({
		queryKey: ['goals', args],
		queryFn: () => get<GoalsAnswer>('goals', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

export function useFunnels(scope: Scope) {
	const { data, enabled } = useReportData();
	const args = withData(apiArgs(scope), data);
	return useQuery({
		queryKey: ['funnels', args],
		queryFn: () => get<FunnelsAnswer>('funnels', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** Property keys (key ''), or one key's values; optionally of one event only. */
export function useProperties(scope: Omit<Scope, 'compare'>, key: string, event: string, limit = 50) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs(scope), limit, ...(key ? { key } : {}), ...(event ? { event } : {}) }, data);
	return useQuery({
		queryKey: ['properties', args],
		queryFn: () => get<PropertiesAnswer>('properties', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** Clicks and form submits: totals and rows of one kind; optionally on one page. */
export function useClicks(scope: Scope, kind: ClickKind, page: string, limit = 50) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs(scope), kind, limit, ...(page ? { page } : {}) }, data);
	return useQuery({
		queryKey: ['clicks', args],
		queryFn: () => get<ClicksAnswer>('clicks', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** Search (Search Console's days): totals, points and rows of one kind; optionally one page's or one query's. */
export function useSearch(scope: Scope, kind: SearchKind, page: string, query: string, limit = 50) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs(scope), kind, limit, ...(page ? { page } : {}), ...(query ? { query } : {}) }, data);
	return useQuery({
		queryKey: ['search', args],
		queryFn: () => get<SearchAnswer>('search', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/**
 * The changes in the range for the chart's markers lane, oldest first;
 * with a page, that page's and the site-wide ones.
 */
export function useMarkers(scope: Pick<Scope, 'range' | 'from' | 'to'>, page: string) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs({ ...scope, filters: [] }), ...(page ? { page } : {}) }, data);
	return useQuery({
		queryKey: ['markers', args],
		queryFn: () => get<MarkersAnswer>('markers', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** The change log in the range, newest first: optionally one page's, of some groups. */
export function useChanges(scope: Pick<Scope, 'range' | 'from' | 'to'>, page: string, kinds: string, limit: number, offset: number) {
	const { data, enabled } = useReportData();
	const args: Args = withData(
		{ ...apiArgs({ ...scope, filters: [] }), limit, offset, ...(page ? { page } : {}), ...(kinds ? { kinds } : {}) },
		data
	);
	return useQuery({
		queryKey: ['changes', args],
		queryFn: () => get<ChangesAnswer>('changes', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** After a note is added or deleted, the markers and the change log are asked again. */
function refreshChanges(): void {
	void queryClient.invalidateQueries({ queryKey: ['markers'] });
	void queryClient.invalidateQueries({ queryKey: ['changes'] });
}

/** Add a note to the timeline (administrators). time: "YYYY-MM-DD HH:MM" in the site's time zone. */
export async function addNote(data: DataSet, note: { note: string; page: string; time: string }): Promise<Marker> {
	const saved = await send<Marker>('annotations', 'POST', { ...note, data });
	refreshChanges();
	return saved;
}

export async function deleteNote(data: DataSet, id: number): Promise<void> {
	await apiFetch({ path: addQueryArgs(`${NAMESPACE}/annotations/${id}`, { data }), method: 'DELETE' });
	refreshChanges();
}

/** Goals and funnels belong to the data set shown (demo data has its own). */
function definitionPath(type: 'goals' | 'funnels', id?: string): string {
	return id ? `${type}/${id}` : type;
}

/** After a change, reports that use definitions are asked again. */
function refreshDefinitions(): void {
	void queryClient.invalidateQueries({ queryKey: ['goals'] });
	void queryClient.invalidateQueries({ queryKey: ['funnels'] });
}

/** Add a goal (no id) or change one. */
export async function saveGoal(data: DataSet, goal: GoalStep, id?: string): Promise<Goal> {
	const saved = await send<Goal>(definitionPath('goals', id), 'POST', { ...goal, data });
	refreshDefinitions();
	return saved;
}

/** Add a funnel (no id) or change one. */
export async function saveFunnel(data: DataSet, funnel: Omit<Funnel, 'id'>, id?: string): Promise<Funnel> {
	const saved = await send<Funnel>(definitionPath('funnels', id), 'POST', { ...funnel, data });
	refreshDefinitions();
	return saved;
}

export async function deleteDefinition(data: DataSet, type: 'goals' | 'funnels', id: string): Promise<void> {
	await apiFetch({ path: addQueryArgs(`${NAMESPACE}/${definitionPath(type, id)}`, { data }), method: 'DELETE' });
	refreshDefinitions();
}

/** Whether demo data is made: from the page at first, then the API. */
export function useDemo() {
	return useQuery({
		queryKey: ['demo'],
		queryFn: () => get<DemoStatus>('demo'),
		initialData: boot.demo,
		staleTime: Infinity,
	});
}

/** Make demo data, or carry on making it: one request does a slice of the work. */
export function makeDemo(restart = false): Promise<DemoStatus> {
	return send<DemoStatus>('demo', 'POST', restart ? { restart } : {});
}

export function removeDemo(): Promise<DemoStatus> {
	return send<DemoStatus>('demo', 'DELETE');
}

/** Save the data set this person sees. */
export function saveView(data: DataSet): Promise<{ data: DataSet }> {
	return send<{ data: DataSet }>('view', 'POST', { data });
}

/** The message of an API error, or a general one. */
export function errorMessage(error: unknown, fallback: string): string {
	if (error && typeof error === 'object' && 'message' in error && typeof error.message === 'string') {
		return error.message;
	}
	return fallback;
}
