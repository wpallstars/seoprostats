/**
 * The view's state in the URL hash, so every view can be bookmarked and
 * shared, and the back button works:
 * `#/overview?range=30d&compare=prev&metric=visits&f=country:is:US`.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { parseFilter, serializeFilter, type Filter } from './filters';
import { CLICK_KINDS, COMPARE_KEYS, RANGE_KEYS, type ClickKind, type CompareKey, type Dimension, type MetricKey, type RangeKey } from './types';
import { CHART_METRICS } from './metrics';

export const VIEWS = ['overview', 'goals', 'funnels', 'properties', 'clicks', 'changes'] as const;
export type View = (typeof VIEWS)[number];

/** Stable card names and allowed dimensions; the first tab is the default. */
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
	/** Applied section choices only; drafts and user settings never belong here. */
	kind?: ClickKind;
	page?: string;
	key?: string;
	event?: string;
	tabs?: Partial<Record<ViewCard, Dimension>>;
}

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

/** Names are site-defined, not an enum. Reject controls and overlong values. */
function text(value: string | null, max: number): string | undefined {
	return value && value === value.trim() && value.length <= max && !/[\u0000-\u001f\u007f]/.test(value) ? value : undefined;
}

/** Used by both readers and writers, so stored views obey the same contract. */
function sectionParams(state: ViewState, params: URLSearchParams): void {
	if (state.view === 'clicks') {
		const kind = oneOf(CLICK_KINDS, params.get('kind'), 'elements');
		if (kind !== 'elements') state.kind = kind;
		const page = text(params.get('page'), 2048);
		if (page && /^[/*]/.test(page) && !/[?#]/.test(page)) state.page = page;
	} else if (state.view === 'properties') {
		const key = text(params.get('key'), 100);
		const event = text(params.get('event'), 120);
		if (key) state.key = key;
		if (event) state.event = event;
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
	const choices = new URLSearchParams();
	for (const name of ['kind', 'page', 'key', 'event'] as const) {
		if (state[name]) choices.set(name, state[name]);
	}
	for (const card of Object.keys(VIEW_TABS) as ViewCard[]) {
		if (state.tabs?.[card]) choices.set(`tab.${card}`, state.tabs[card]);
	}
	const valid: ViewState = { ...DEFAULT_STATE, view: state.view };
	sectionParams(valid, choices);
	for (const name of ['kind', 'page', 'key', 'event'] as const) {
		if (valid[name]) params.set(name, valid[name]);
	}
	for (const card of Object.keys(VIEW_TABS) as ViewCard[]) {
		if (valid.tabs?.[card]) params.set(`tab.${card}`, valid.tabs[card]);
	}
	const query = params.toString();
	return `#/${state.view}${query ? `?${query}` : ''}`;
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
