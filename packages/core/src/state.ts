/**
 * The view's state in the URL hash, so every view can be bookmarked and
 * shared, and the back button works:
 * `#/overview?range=30d&compare=prev&metric=visits&f=country:is:US`.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { parseFilter, serializeFilter, type Filter } from './filters';
import { COMPARE_KEYS, RANGE_KEYS, type CompareKey, type MetricKey, type RangeKey } from './types';
import { CHART_METRICS } from './metrics';

export const VIEWS = ['overview', 'search', 'goals', 'funnels', 'properties', 'clicks', 'changes'] as const;
export type View = (typeof VIEWS)[number];

export interface ViewState {
	view: View;
	range: RangeKey;
	/** Custom range, YYYY-MM-DD, both days included. */
	from?: string;
	to?: string;
	compare: CompareKey;
	metric: MetricKey;
	filters: Filter[];
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
