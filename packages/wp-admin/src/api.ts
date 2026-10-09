/**
 * Reading the API through WordPress's apiFetch (logged-in cookie and
 * nonce) and TanStack Query (cache, deduplication, refresh).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { createContext, useContext } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { QueryClient, keepPreviousData, useQuery } from '@tanstack/react-query';
import {
	apiArgs,
	singleEngine,
	type AbTestAnswer,
	type AbTestsAnswer,
	type AuditAnswer,
	type AuditFinding,
	type BacklinkKind,
	type BacklinksAnswer,
	type BreakdownAnswer,
	type ChangesAnswer,
	type ClickKind,
	type ClicksAnswer,
	type ContentAnswer,
	type ContentSort,
	type Dimension,
	type Experiment,
	type ExperimentInput,
	type ExperimentResult,
	type ExperimentsAnswer,
	type Funnel,
	type FunnelsAnswer,
	type Goal,
	type GoalStep,
	type GoalsAnswer,
	type IndexationAnswer,
	type IndexationKind,
	type InspectionsAnswer,
	type InspectionVerdict,
	type LinksAnswer,
	type LinksKind,
	type Marker,
	type MarkersAnswer,
	type OpportunitiesAnswer,
	type OpportunityKind,
	type PropertiesAnswer,
	type QueueAction,
	type QueueAnswer,
	type QueueFilter,
	type QueueItem,
	type RealtimeAnswer,
	type SearchAnswer,
	type SearchKind,
	type StatsAnswer,
	type TargetFilter,
	type TargetsAnswer,
	type TargetsImportAnswer,
	type TimeseriesAnswer,
	type ViewState,
} from '@seoprostats/core';
import { boot, type DataSet, type DemoStatus } from './boot';
import { useDataSet } from './data';

const NAMESPACE = '/seoprostats/v1';

type Args = Record<string, string | string[] | number>;

/** Public-shell transport. Never sends WordPress cookies or an admin nonce. */
export const shareAccess = { token: '', root: '', unlock: '', section: 'overview', hidden: [] as string[] };

/**
 * The shared report's section a subtree reads (each request names it), so
 * printing can show several sections at once. Null outside shared reports.
 */
export const ShareSection = createContext<string | null>(null);

/** Report arguments' key for the shared section: part of the cache key, never sent as an argument. */
const SECTION_ARG = '__section';

export function get<T>(route: string, args: Args = {}): Promise<T> {
    if (shareAccess.token) {
        const { [SECTION_ARG]: section, ...rest } = args;
        return shareFetch<T>(`${typeof section === 'string' && section ? section : shareAccess.section}/${route}`, 'GET', rest);
    }
	return apiFetch<T>({ path: addQueryArgs(`${NAMESPACE}/${route}`, args) });
}

export async function shareFetch<T>(route: string, method: 'GET' | 'POST', args: Record<string, unknown> = {}): Promise<T> {
    let url = `${shareAccess.root}share/${shareAccess.token}${route ? `/${route}` : ''}`;
    if (method === 'GET') {
        url = addQueryArgs(url, args);
    }
    const response = await fetch(url, {
        method,
        credentials: 'omit',
        cache: 'no-store',
        referrerPolicy: 'no-referrer',
        headers: { 'Content-Type': 'application/json', 'X-Seoprostats-Unlock': shareAccess.unlock },
        ...(method === 'POST' ? { body: JSON.stringify(args) } : {}),
    });
    const answer: unknown = await response.json();
    if (!response.ok) {
        throw answer;
    }
    return answer as T;
}

function send<T>(route: string, method: 'POST' | 'DELETE', data: Record<string, unknown> = {}): Promise<T> {
	return apiFetch<T>({ path: `${NAMESPACE}/${route}`, method, data });
}

/** Where a report reads: the data set, and in a shared report its section. */
interface ReportSource {
	set: DataSet;
	section: string | null;
}

/** Report arguments for a data set (live is the default, so it is left out) and a shared section. */
function withData(args: Args, data: ReportSource): Args {
	const out = data.set === 'demo' ? { ...args, data: data.set } : args;
	return data.section ? { ...out, [SECTION_ARG]: data.section } : out;
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
function useReportData(): { data: ReportSource; enabled: boolean } {
	const set = useDataSet();
	const section = useContext(ShareSection);
	const demo = useDemo();
	return { data: { set, section }, enabled: set === 'live' || demo.data?.status === 'ready' };
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

/** A search report's scope: the engine too (Google when left out). */
type SearchScope = Scope & Pick<ViewState, 'engine'>;

/**
 * The engine argument: Google is the default, so it is left out. Combined
 * (all) goes to Rankings, Opportunities and Content only (`combined`); the
 * other reports read one engine, Google for Combined.
 */
function engineArg(scope: SearchScope, combined = false): Args {
	const engine = combined ? (scope.engine ?? 'google') : singleEngine(scope.engine);
	return engine !== 'google' ? { engine } : {};
}

/** Search (an engine's imported days): totals, points and rows of one kind; optionally one page's or one query's. */
export function useSearch(scope: SearchScope, kind: SearchKind, page: string, query: string, limit = 50, offset = 0) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs(scope), ...engineArg(scope, true), kind, limit, ...(offset ? { offset } : {}), ...(page ? { page } : {}), ...(query ? { query } : {}) }, data);
	return useQuery({
		queryKey: ['search', args],
		queryFn: () => get<SearchAnswer>('search', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** Search opportunities of one kind (decay always against an earlier period: the previous one unless a year ago is chosen). */
export function useOpportunities(scope: SearchScope, kind: OpportunityKind, limit = 10, offset = 0) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs({ ...scope, compare: scope.compare === 'year' ? 'year' : 'prev' }), ...engineArg(scope, true), kind, limit, offset }, data);
	return useQuery({
		queryKey: ['opportunities', args],
		queryFn: () => get<OpportunitiesAnswer>('opportunities', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** The content audit: pages with findings ('' for all), most impressions first; the comparison does not apply. */
export function useAudit(scope: SearchScope, finding: AuditFinding | '', limit = 25, offset = 0) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs({ ...scope, compare: 'none' }), ...engineArg(scope), limit, offset, ...(finding ? { finding } : {}) }, data);
	return useQuery({
		queryKey: ['audit', args],
		queryFn: () => get<AuditAnswer>('audit', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** Internal links: orphan pages, converting pages with few links in, or missing links; conversions of a goal ('' for the first); the comparison does not apply. */
export function useLinks(scope: SearchScope, kind: LinksKind, goal: string, limit = 25, offset = 0) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs({ ...scope, compare: 'none' }), ...engineArg(scope), kind, limit, offset, ...(goal ? { goal } : {}) }, data);
	return useQuery({
		queryKey: ['links', args],
		queryFn: () => get<LinksAnswer>('links', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** Indexation: published pages or sitemap addresses search has not shown in its newest days; the period and comparison do not apply. */
export function useIndexation(scope: SearchScope, kind: IndexationKind, limit = 25, offset = 0) {
	const { data, enabled } = useReportData();
	// The window is the engine's newest days, whatever the period: only the page filters count.
	const args: Args = withData({ ...apiArgs({ range: '30d', filters: scope.filters }), ...engineArg(scope), kind, limit, offset }, data);
	return useQuery({
		queryKey: ['indexation', args],
		queryFn: () => get<IndexationAnswer>('indexation', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** Google's URL Inspection of the site's pages, newest first, with Search Console's sitemaps; only page filters apply (Google only). */
export function useInspections(scope: SearchScope, verdict: InspectionVerdict | '', limit = 25, offset = 0, on = true) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs({ range: '30d', filters: scope.filters }), limit, offset, ...(verdict ? { verdict } : {}) }, data);
	return useQuery({
		queryKey: ['inspections', args],
		queryFn: () => get<InspectionsAnswer>('inspections', args),
		placeholderData: keepPreviousData,
		enabled: enabled && on,
	});
}

/** Backlinks: live links, the sites linking, the site's pages linked to, or links lost; the period counts new and lost links and the sites' visits; filters, engine and comparison do not apply. */
export function useBacklinks(scope: SearchScope, kind: BacklinkKind, limit = 25, offset = 0, source = '') {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs({ ...scope, compare: 'none', filters: [] }), kind, limit, offset, source }, data);
	return useQuery({
		queryKey: ['backlinks', args],
		queryFn: () => get<BacklinksAnswer>('backlinks', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** Search targets: each with its position, clicks and the page that ranks; filters do not apply (targets are searches). */
export function useTargets(scope: SearchScope, status: TargetFilter, limit = 50, offset = 0) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs({ ...scope, filters: [] }), ...engineArg(scope), status, limit, offset }, data);
	return useQuery({
		queryKey: ['targets', args],
		queryFn: () => get<TargetsAnswer>('targets', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** After targets change: the list, and the plan (its target items). */
function refreshTargets(): void {
	void queryClient.invalidateQueries({ queryKey: ['targets'] });
	void queryClient.invalidateQueries({ queryKey: ['queue'] });
}

/** Import targets from text (CSV, tab-separated, JSON or the aidevops TOON table) (administrators). */
export async function importTargets(data: DataSet, text: string, replace: boolean): Promise<TargetsImportAnswer> {
	const done = await send<TargetsImportAnswer>('targets', 'POST', { text, replace, data });
	refreshTargets();
	return done;
}

/** Delete targets by their searches, or every target (administrators). */
export async function deleteTargets(data: DataSet, queries: string[], all = false): Promise<{ deleted: number; total: number }> {
	const done = await send<{ deleted: number; total: number }>('targets', 'DELETE', { queries, all, data });
	refreshTargets();
	return done;
}

/** Content performance: each page's search figures, visits from search and conversions of a goal ('' for the first). */
export function useContent(scope: SearchScope, sort: ContentSort, goal: string, limit = 25, offset = 0) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs(scope), ...engineArg(scope, true), sort, limit, offset, ...(goal ? { goal } : {}) }, data);
	return useQuery({
		queryKey: ['content', args],
		queryFn: () => get<ContentAnswer>('content', args),
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

/** Experiments, due ones first (measured now, or as decided). */
export function useExperiments() {
	const { data, enabled } = useReportData();
	const args = withData({}, data);
	return useQuery({
		queryKey: ['experiments', args],
		queryFn: () => get<ExperimentsAnswer>('experiments', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** A/B tests over each one's life (not the period), running first; the view's filters apply. */
export function useAbTests(filters: ViewState['filters']) {
	const { data, enabled } = useReportData();
	const args = withData(filters.length ? { filters: apiArgs({ range: '30d', filters }).filters! } : {}, data);
	return useQuery({
		queryKey: ['ab-tests', args],
		queryFn: () => get<AbTestsAnswer>('ab-tests', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** One A/B test's variants side by side. */
export function useAbTest(id: string, filters: ViewState['filters']) {
	const { data, enabled } = useReportData();
	const args = withData(filters.length ? { filters: apiArgs({ range: '30d', filters }).filters! } : {}, data);
	return useQuery({
		queryKey: ['ab-test', id, args],
		queryFn: () => get<AbTestAnswer>(`ab-tests/${encodeURIComponent(id)}`, args),
		// The last answer while filters change, never another test's.
		placeholderData: (previous, last) => (last?.queryKey[1] === id ? previous : undefined),
		enabled: enabled && id !== '',
	});
}

/** After an experiment is added, decided or deleted: the list, the plan (its pages, done items' results), and the markers and change log (its start). */
function refreshExperiments(): void {
	void queryClient.invalidateQueries({ queryKey: ['experiments'] });
	void queryClient.invalidateQueries({ queryKey: ['queue'] });
	refreshChanges();
}

/** Record an experiment (administrators); its start shows on the timeline. */
export async function addExperiment(data: DataSet, input: ExperimentInput): Promise<Experiment> {
	const saved = await send<Experiment>('experiments', 'POST', { ...input, data });
	refreshExperiments();
	return saved;
}

/** Decide (with a result), change the note of, or cancel an experiment. */
export async function updateExperiment(
	data: DataSet,
	id: number,
	change: { action: 'decide' | 'note' | 'cancel'; result?: ExperimentResult; note?: string }
): Promise<Experiment> {
	const saved = await send<Experiment>(`experiments/${id}`, 'POST', { ...change, data });
	refreshExperiments();
	return saved;
}

export async function deleteExperiment(data: DataSet, id: number): Promise<void> {
	await apiFetch({ path: addQueryArgs(`${NAMESPACE}/experiments/${id}`, { data }), method: 'DELETE' });
	refreshExperiments();
}

/** The decision queue's period and choices: page filters apply; the comparison does not. */
function queueArgs(scope: SearchScope, goal: string): Args {
	return { ...apiArgs({ ...scope, compare: 'none' }), ...engineArg(scope), ...(goal ? { goal } : {}) };
}

/** The decision queue: items in a state (open: new and accepted), best first. */
export function useQueue(scope: SearchScope, status: QueueFilter, goal: string, limit = 50, offset = 0) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...queueArgs(scope, goal), status, limit, offset }, data);
	return useQuery({
		queryKey: ['queue', args],
		queryFn: () => get<QueueAnswer>('queue', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

/** Act on a queue item (administrators); done opens an experiment, so experiments and markers are asked again too. */
export async function updateQueueItem(
	data: DataSet,
	scope: SearchScope,
	goal: string,
	key: string,
	change: { action: QueueAction; effort?: number; note?: string }
): Promise<QueueItem> {
	const saved = await send<QueueItem>(`queue/${key}`, 'POST', { ...queueArgs(scope, goal), ...change, data });
	void queryClient.invalidateQueries({ queryKey: ['queue'] });
	if (change.action === 'done') {
		refreshExperiments();
	}
	return saved;
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
