/**
 * The view's state in the URL hash, so every view can be bookmarked and
 * shared, and the back button works:
 * `#/overview?range=30d&compare=prev&metric=visits&f=country:is:US`.
 *
 * One plain object (no WordPress, no React), so a stored view (a shared
 * read-only dashboard, later) uses the same format and checks. It holds only
 * what draws the view: never user IDs, nonces or settings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { parseFilter, serializeFilter, type Filter } from './filters';
import {
	CLICK_KINDS,
	COMPARE_KEYS,
	CONTENT_SORTS,
	QUEUE_FILTERS,
	RANGE_KEYS,
	SEARCH_ENGINES,
	SEARCH_KINDS,
	SEARCH_REPORTS,
	type ClickKind,
	type CompareKey,
	type ContentSort,
	type Dimension,
	type MetricKey,
	type QueueFilter,
	type RangeKey,
	type SearchEngine,
	type SearchKind,
	type SearchMetricKey,
	type SearchReport,
} from './types';
import { CHART_METRICS, SEARCH_METRICS } from './metrics';

export const VIEWS = ['overview', 'search', 'goals', 'funnels', 'properties', 'clicks', 'changes'] as const;
export type View = (typeof VIEWS)[number];
export const SHARE_VIEWS: readonly View[] = ['overview', 'goals', 'clicks'];

/** Normalize saved public views with exactly the address reader's rules. */
export function shareView(state: ViewState): ViewState | null {
    return SHARE_VIEWS.includes(state.view) ? parseHash(buildHash(state)) : null;
}

/** Overview's cards (stable names) and their tabs; the first tab is the default. */
export const VIEW_TABS = {
	sources: ['channel', 'source', 'utm_campaign'],
	pages: ['page', 'entry', 'exit', 'not_found'],
	content: ['author', 'category', 'post_type'],
	search: ['search', 'no_results'],
	locations: ['country', 'language'],
	devices: ['device', 'browser', 'os', 'login'],
	events: ['event'],
} as const satisfies Record<string, readonly Dimension[]>;
export type ViewCard = keyof typeof VIEW_TABS;

export interface ViewState {
	view: View;
	range: RangeKey;
	/** Custom range, YYYY-MM-DD, both days included. */
	from?: string;
	to?: string;
	compare: CompareKey;
	metric: MetricKey;
	filters: Filter[];
	/*
	 * Section choices, kept only for their section and left out when default.
	 * Applied choices only: text still being typed is not part of the view.
	 */
	/** Clicks: the table shown. */
	kind?: ClickKind;
	/** Clicks, Search and Changes: only this page (a path; * for any text). */
	page?: string;
	/** Properties: the property whose values are listed. */
	key?: string;
	/** Properties: only properties sent with this event. */
	event?: string;
	/** Search: Rankings (the default), Opportunities or Content. */
	report?: SearchReport;
	/** Search: the engine (Google when left out); kept across its reports. */
	engine?: SearchEngine;
	/** Search → Content: the order of the pages. */
	sort?: ContentSort;
	/** Search → Content: the goal counted; Search → Plan: the goal giving value (its ID); the first when left out. */
	goal?: string;
	/** Search → Plan: the items shown (open when left out). */
	status?: QueueFilter;
	/** Search: the table shown. */
	tab?: SearchKind;
	/** Search: the chart's metric. */
	chart?: SearchMetricKey;
	/** Search: only this search query (* for any text). */
	query?: string;
	/** Search → Experiments: start one on this change (its id), from Changes. */
	change?: string;
	/** Overview: each card's open tab. */
	tabs?: Partial<Record<ViewCard, Dimension>>;
}

/** The single-value section choices (Overview's tabs are a map); everything else is shared by every section. */
const SECTION_VALUES = ['kind', 'report', 'engine', 'sort', 'goal', 'status', 'tab', 'chart', 'key', 'event', 'page', 'query', 'change'] as const;

export const DEFAULT_STATE: ViewState = {
	view: 'overview',
	range: '30d',
	compare: 'prev',
	metric: 'visitors',
	filters: [],
};

const DAY = /^\d{4}-\d{2}-\d{2}$/;

function oneOf<T extends string>(list: readonly T[], value: string | null, fallback: T): T {
	return value !== null && (list as readonly string[]).includes(value) ? (value as T) : fallback;
}

/**
 * Pages, queries, properties and events are the site's own text, not a list:
 * trimmed, no control characters, no longer than the stored names (2048).
 */
function text(value: string | null): string | undefined {
	return value && value === value.trim() && value.length <= 2048 && !/[\u0000-\u001f\u007f]/.test(value) ? value : undefined;
}

/**
 * Read the section's choices from the address into the state; anything
 * unknown, invalid or default is left out. buildHash() writes through it
 * too, so a written address and a stored view obey the same rules.
 */
function sectionParams(state: ViewState, params: URLSearchParams): void {
	const set = <K extends (typeof SECTION_VALUES)[number]>(name: K, value: ViewState[K] | undefined): void => {
		if (value) {
			state[name] = value;
		}
	};
	if (state.view === 'clicks') {
		const kind = oneOf(CLICK_KINDS, params.get('kind'), 'elements');
		set('kind', kind === 'elements' ? undefined : kind);
		set('page', text(params.get('page')));
	} else if (state.view === 'changes') {
		set('page', text(params.get('page')));
	} else if (state.view === 'properties') {
		set('key', text(params.get('key')));
		set('event', text(params.get('event')));
	} else if (state.view === 'search') {
		const report = oneOf(SEARCH_REPORTS, params.get('report'), 'rankings');
		set('report', report === 'rankings' ? undefined : report);
		const engine = oneOf(SEARCH_ENGINES, params.get('engine'), 'google');
		set('engine', engine === 'google' ? undefined : engine);
		if (report === 'content') {
			const sort = oneOf(CONTENT_SORTS, params.get('sort'), 'clicks');
			set('sort', sort === 'clicks' ? undefined : sort);
			set('goal', text(params.get('goal')));
		}
		if (report === 'plan') {
			const status = oneOf(QUEUE_FILTERS, params.get('status'), 'open');
			set('status', status === 'open' ? undefined : status);
			set('goal', text(params.get('goal')));
		}
		if (report === 'experiments') {
			const change = params.get('change') ?? '';
			set('change', /^[1-9]\d{0,9}$/.test(change) ? change : undefined);
		}
		// Bing has no countries or devices.
		const tabs = engine === 'google' ? SEARCH_KINDS : SEARCH_KINDS.filter((k) => k === 'queries' || k === 'pages');
		const tab = oneOf(tabs, params.get('tab'), 'queries');
		const chart = oneOf(Object.keys(SEARCH_METRICS) as SearchMetricKey[], params.get('chart'), 'clicks');
		set('tab', tab === 'queries' ? undefined : tab);
		set('chart', chart === 'clicks' ? undefined : chart);
		set('page', text(params.get('page')));
		set('query', text(params.get('query')));
	} else if (state.view === 'overview') {
		for (const card of Object.keys(VIEW_TABS) as ViewCard[]) {
			const allowed: readonly Dimension[] = VIEW_TABS[card];
			const tab = oneOf(allowed, params.get(`tab.${card}`), allowed[0]!);
			if (tab !== allowed[0]) {
				state.tabs ??= {};
				state.tabs[card] = tab;
			}
		}
	}
}

/** Read a hash such as `#/overview?range=7d`; anything unknown takes the default. */
export function parseHash(hash: string): ViewState {
	const clean = hash.replace(/^#\/?/, '');
	const q = clean.indexOf('?');
	const path = q < 0 ? clean : clean.slice(0, q);
	const params = new URLSearchParams(q < 0 ? '' : clean.slice(q + 1));

	const state: ViewState = {
		view: oneOf(VIEWS, path || null, DEFAULT_STATE.view),
		range: oneOf(RANGE_KEYS, params.get('range'), DEFAULT_STATE.range),
		compare: oneOf(COMPARE_KEYS, params.get('compare'), DEFAULT_STATE.compare),
		metric: oneOf(CHART_METRICS, params.get('metric'), DEFAULT_STATE.metric),
		filters: params
			.getAll('f')
			.map(parseFilter)
			.filter((f): f is Filter => f !== null),
	};
	if (state.range === 'custom') {
		const from = params.get('from') ?? '';
		const to = params.get('to') ?? '';
		if (DAY.test(from) && DAY.test(to) && from <= to) {
			state.from = from;
			state.to = to;
		} else {
			state.range = DEFAULT_STATE.range;
		}
	}
	sectionParams(state, params);
	return state;
}

/** Write the state as a hash, leaving out defaults. */
export function buildHash(state: ViewState): string {
	const params = new URLSearchParams();
	if (state.range !== DEFAULT_STATE.range) {
		params.set('range', state.range);
	}
	if (state.range === 'custom' && state.from && state.to) {
		params.set('from', state.from);
		params.set('to', state.to);
	}
	if (state.compare !== DEFAULT_STATE.compare) {
		params.set('compare', state.compare);
	}
	if (state.metric !== DEFAULT_STATE.metric) {
		params.set('metric', state.metric);
	}
	for (const filter of state.filters) {
		params.append('f', serializeFilter(filter));
	}
	// Only this section's valid, non-default choices (the reader's rules).
	const choices = new URLSearchParams();
	writeSection(state, choices);
	const valid: ViewState = { ...DEFAULT_STATE, view: state.view };
	sectionParams(valid, choices);
	writeSection(valid, params);
	const query = params.toString();
	return `#/${state.view}${query ? `?${query}` : ''}`;
}

function writeSection(state: ViewState, params: URLSearchParams): void {
	for (const name of SECTION_VALUES) {
		const value = state[name];
		if (value) {
			params.set(name, value);
		}
	}
	for (const card of Object.keys(VIEW_TABS) as ViewCard[]) {
		const tab = state.tabs?.[card];
		if (tab) {
			params.set(`tab.${card}`, tab);
		}
	}
}

/**
 * The state for showing another section: the period, comparison, metric
 * and filters stay; the section's own choices are left behind.
 */
export function switchView(state: ViewState, view: View): ViewState {
	if (view === state.view) {
		return state;
	}
	const next: ViewState = { ...state, view };
	for (const name of SECTION_VALUES) {
		delete next[name];
	}
	delete next.tabs;
	return next;
}

/** Query arguments for the API's range, compare and filters parameters. */
export function apiArgs(
	state: Pick<ViewState, 'range' | 'from' | 'to' | 'filters'> & { compare?: CompareKey }
): Record<string, string | string[]> {
	const args: Record<string, string | string[]> = { range: state.range };
	if (state.range === 'custom' && state.from && state.to) {
		args.from = state.from;
		args.to = state.to;
	}
	if (state.compare && state.compare !== 'none') {
		args.compare = state.compare;
	}
	if (state.filters.length) {
		args.filters = state.filters.map(serializeFilter);
	}
	return args;
}
