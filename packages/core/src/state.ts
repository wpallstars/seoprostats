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
	AUDIT_FINDINGS,
	BACKLINK_KINDS,
	BACKLINK_SOURCES,
	CHANGE_GROUPS,
	CLICK_KINDS,
	COMPARE_KEYS,
	CONTENT_SORTS,
	INDEXATION_KINDS,
	LINKS_KINDS,
	QUEUE_FILTERS,
	RANGE_KEYS,
	SEARCH_ANY_KINDS,
	SEARCH_ENGINE_CHOICES,
	SEARCH_KINDS,
	SEARCH_REPORTS,
	SEARCH_SORTS,
	SORT_ORDERS,
	TARGET_FILTERS,
	naturalOrder,
	searchSorts,
	singleEngine,
	type AuditFinding,
	type BacklinkKind,
	type BacklinkSource,
	type ChangeGroup,
	type ClickKind,
	type CompareKey,
	type ContentSort,
	type Dimension,
	type IndexationKind,
	type LinksKind,
	type MetricKey,
	type QueueFilter,
	type RangeKey,
	type SearchEngineChoice,
	type SearchKind,
	type SearchMetricKey,
	type SearchDaySort,
	type SearchReport,
	type SortOrder,
	type TargetFilter,
} from './types';
import { CHART_METRICS, SEARCH_METRICS } from './metrics';

export const VIEWS = ['overview', 'search', 'goals', 'funnels', 'properties', 'clicks', 'ab-tests', 'changes'] as const;
export type View = (typeof VIEWS)[number];
/** Every tab but A/B tests (the owner's work in progress) can be shared; Search once per engine. */
export const SHARE_VIEWS: readonly View[] = VIEWS.filter((view) => view !== 'ab-tests');
/** The Search reports a shared report shows (not Backlinks, Targets, Plan or Experiments: the owner's research, chosen searches, work list and notes). */
export const SHARE_SEARCH_REPORTS: readonly SearchReport[] = ['rankings', 'opportunities', 'audit', 'content'];

/** Normalize saved public views with exactly the address reader's rules. */
export function shareView(state: ViewState): ViewState | null {
    if (!SHARE_VIEWS.includes(state.view)) {
        return null;
    }
    const view = parseHash(buildHash(state));
    // A shared Search section is one engine's: Combined is not offered there.
    if (view.engine === 'all') {
        delete view.engine;
    }
    if (view.report && !SHARE_SEARCH_REPORTS.includes(view.report)) {
        delete view.report;
        delete view.status;
        delete view.change;
        delete view.goal;
        delete view.targets;
        delete view.backlinks;
        delete view.found;
    }
    return view;
}

/** What makes a section one of a kind in a shared report: its tab, and for Search its engine. */
export function shareSectionKey(state: Pick<ViewState, 'view' | 'engine'>): string {
    return state.view === 'search' ? `search:${singleEngine(state.engine)}` : state.view;
}

/** Overview's cards (stable names) and their tabs; the first tab is the default. */
export const VIEW_TABS = {
	sources: ['channel', 'source', 'utm_campaign'],
	pages: ['page', 'entry', 'exit', 'not_found'],
	content: ['author', 'category', 'post_type'],
	search: ['search', 'no_results'],
	locations: ['country', 'language'],
	devices: ['device', 'browser', 'os', 'login'],
	events: ['event', 'variant'],
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
	/** Changes: only this group (all when left out). */
	group?: ChangeGroup;
	/** Properties: the property whose values are listed. */
	key?: string;
	/** Properties: only properties sent with this event. */
	event?: string;
	/** Search: Rankings (the default), Opportunities, Audit, Content, Backlinks, Targets, Plan or Experiments. */
	report?: SearchReport;
	/** Search → Backlinks: the list shown (links when left out). */
	backlinks?: BacklinkKind;
	/** Search → Backlinks: only what this source found (every source when left out). */
	found?: BacklinkSource;
	/** Search → Targets: the targets shown (all when left out). */
	targets?: TargetFilter;
	/** Search → Audit: only pages with this finding (all when left out). */
	finding?: AuditFinding;
	/** Search → Audit: the internal links list shown (orphans when left out). */
	links?: LinksKind;
	/** Search → Audit: the indexation list shown (pages when left out). */
	index?: IndexationKind;
	/** Search: the engine, or all for Combined (Google when left out); kept across its reports. */
	engine?: SearchEngineChoice;
	/** Search → Rankings, Content and Audit: the column the table is sorted by (the table's default when left out). */
	sort?: ContentSort | SearchDaySort;
	/** The sorted table's direction (the column's natural one when left out: lowest first for position, most first for the rest). */
	order?: SortOrder;
	/** Search → Content and Audit (internal links): the goal counted; Search → Plan: the goal giving value (its ID); the first when left out. */
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
	/** A/B tests: the test shown (its id); the list when left out. */
	test?: string;
	/** Overview: each card's open tab. */
	tabs?: Partial<Record<ViewCard, Dimension>>;
}

/** The single-value section choices (Overview's tabs are a map); everything else is shared by every section. */
const SECTION_VALUES = ['kind', 'report', 'engine', 'sort', 'order', 'goal', 'status', 'targets', 'backlinks', 'found', 'finding', 'links', 'index', 'tab', 'chart', 'key', 'event', 'page', 'query', 'change', 'group', 'test'] as const;

export const DEFAULT_STATE: ViewState = {
	view: 'overview',
	// Whole weeks, so the previous period meets the same weekdays.
	range: '91d',
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
	if (!value) {
		return undefined;
	}
	// eslint-disable-next-line no-control-regex -- matching control characters is the point: they are refused.
	return value === value.trim() && value.length <= 2048 && !/[\u0000-\u001f\u007f]/.test(value) ? value : undefined;
}

/** Sets one section choice when it has a value. */
type SectionSetter = <K extends (typeof SECTION_VALUES)[number]>(name: K, value: ViewState[K] | undefined) => void;

/** A choice, or undefined when it is the default (defaults are left out of the address). */
function unlessDefault<T extends string>(value: T, fallback: T): T | undefined {
	return value === fallback ? undefined : value;
}

/** A sorted table: its column (the first is the default) and direction (the column's natural one is the default). */
function sortParams(params: URLSearchParams, set: SectionSetter, sorts: readonly (ContentSort | SearchDaySort)[]): void {
	const asked = params.get('sort');
	const sort = oneOf(sorts, asked, sorts[0]!);
	set('sort', unlessDefault(sort, sorts[0]!));
	// A direction belongs to its column: a column this table does not have takes the default's natural one.
	const natural = naturalOrder(sort);
	const order = asked === null || asked === sort ? oneOf(SORT_ORDERS, params.get('order'), natural) : natural;
	set('order', unlessDefault(order, natural));
}

/** Search → Audit: the finding, the internal links and indexation lists, the goal and the sort. */
function auditParams(params: URLSearchParams, set: SectionSetter): void {
	const finding = params.get('finding');
	set('finding', finding !== null && (AUDIT_FINDINGS as readonly string[]).includes(finding) ? (finding as AuditFinding) : undefined);
	set('links', unlessDefault(oneOf(LINKS_KINDS, params.get('links'), 'orphans'), 'orphans'));
	set('index', unlessDefault(oneOf(INDEXATION_KINDS, params.get('index'), 'pages'), 'pages'));
	set('goal', text(params.get('goal')));
	sortParams(params, set, SEARCH_SORTS);
}

/** Search: the choices only one report has (Rankings' sort depends on the tab, so the caller reads it). */
function searchReportParams(report: SearchReport, params: URLSearchParams, set: SectionSetter): void {
	switch (report) {
		case 'content':
			sortParams(params, set, CONTENT_SORTS);
			set('goal', text(params.get('goal')));
			break;
		case 'plan':
			set('status', unlessDefault(oneOf(QUEUE_FILTERS, params.get('status'), 'open'), 'open'));
			set('goal', text(params.get('goal')));
			break;
		case 'targets':
			set('targets', unlessDefault(oneOf(TARGET_FILTERS, params.get('targets'), 'all'), 'all'));
			break;
		case 'backlinks': {
			set('backlinks', unlessDefault(oneOf(BACKLINK_KINDS, params.get('backlinks'), 'links'), 'links'));
			const found = params.get('found');
			set('found', found !== null && (BACKLINK_SOURCES as readonly string[]).includes(found) ? (found as BacklinkSource) : undefined);
			break;
		}
		case 'audit':
			auditParams(params, set);
			break;
		case 'experiments': {
			const change = params.get('change') ?? '';
			set('change', /^[1-9]\d{0,9}$/.test(change) ? change : undefined);
			break;
		}
		default:
			break;
	}
}

/** Search: the report and engine, the report's own choices, the tab, chart, page and query. */
function searchParams(params: URLSearchParams, set: SectionSetter): void {
	const report = oneOf(SEARCH_REPORTS, params.get('report'), 'rankings');
	set('report', unlessDefault(report, 'rankings'));
	const engine = oneOf(SEARCH_ENGINE_CHOICES, params.get('engine'), 'google');
	set('engine', unlessDefault(engine, 'google'));
	searchReportParams(report, params, set);
	// Only Google has countries, devices and search appearances (not Bing, so not Combined).
	const tabs = engine === 'google' ? SEARCH_KINDS : SEARCH_ANY_KINDS;
	const tab = oneOf(tabs, params.get('tab'), 'queries');
	const chart = oneOf(Object.keys(SEARCH_METRICS) as SearchMetricKey[], params.get('chart'), 'clicks');
	set('tab', unlessDefault(tab, 'queries'));
	set('chart', unlessDefault(chart, 'clicks'));
	// Rankings' top searches: the orders of the table shown (days also by day).
	if (report === 'rankings') {
		sortParams(params, set, searchSorts(tab));
	}
	set('page', text(params.get('page')));
	set('query', text(params.get('query')));
}

/** Overview: each card's open tab, kept when it is not the card's first. */
function overviewParams(state: ViewState, params: URLSearchParams): void {
	for (const card of Object.keys(VIEW_TABS) as ViewCard[]) {
		const allowed: readonly Dimension[] = VIEW_TABS[card];
		const tab = oneOf(allowed, params.get(`tab.${card}`), allowed[0]!);
		if (tab !== allowed[0]) {
			state.tabs ??= {};
			state.tabs[card] = tab;
		}
	}
}

/**
 * Read the section's choices from the address into the state; anything
 * unknown, invalid or default is left out. buildHash() writes through it
 * too, so a written address and a stored view obey the same rules.
 */
function sectionParams(state: ViewState, params: URLSearchParams): void {
	const set: SectionSetter = (name, value) => {
		if (value) {
			state[name] = value;
		}
	};
	switch (state.view) {
		case 'clicks':
			set('kind', unlessDefault(oneOf(CLICK_KINDS, params.get('kind'), 'elements'), 'elements'));
			set('page', text(params.get('page')));
			break;
		case 'changes':
			set('page', text(params.get('page')));
			set('group', oneOf([...CHANGE_GROUPS, ''] as const, params.get('group'), '') || undefined);
			break;
		case 'ab-tests': {
			const test = params.get('test') ?? '';
			set('test', /^[a-z0-9]{6,32}$/.test(test) ? test : undefined);
			break;
		}
		case 'properties':
			set('key', text(params.get('key')));
			set('event', text(params.get('event')));
			break;
		case 'search':
			searchParams(params, set);
			break;
		case 'overview':
			overviewParams(state, params);
			break;
		default:
			break;
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
	const search = query ? '?' + query : '';
	return `#/${state.view}${search}`;
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
