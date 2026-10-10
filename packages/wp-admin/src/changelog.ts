/**
 * The change log in the dashboard: group names and colours, the page the
 * reports are filtered to, and changes as chart markers.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import type { ChartMarker, ChartSpan } from '@seoprostats/charts';
import { CHANGE_GROUPS, type ChangeGroup, type ChartData, type Filter, type Marker } from '@seoprostats/core';

export { CHANGE_GROUPS };

export function groupLabel(group: ChangeGroup): string {
	const labels: Record<ChangeGroup, string> = {
		content: __('Content', 'seoprostats'),
		seo: __('SEO', 'seoprostats'),
		product: __('Products', 'seoprostats'),
		site: __('Site', 'seoprostats'),
		search: __('Search engines', 'seoprostats'),
		note: __('Notes', 'seoprostats'),
	};
	return labels[group] ?? group;
}

/** Fallbacks for the --spst-mark-* colours in common.css (DESIGN.md → Markers). */
const COLORS: Record<ChangeGroup, string> = {
	content: '#3858e9',
	seo: '#8a3fd1',
	product: '#008a20',
	site: '#996800',
	search: '#c9356e',
	note: '#1d2327',
};

/**
 * A search engine update's rollout: its end ('' while rolling out), or
 * undefined for a change that does not last (an announcement, a post).
 */
export function rolloutEnd(marker: Marker): string | undefined {
	const ended = marker.kind === 'search_update' ? marker.meta.ended : undefined;
	return typeof ended === 'string' ? ended : undefined;
}

/** A search engine update's address at its source, if any. */
export function sourceUrl(marker: Marker): string | undefined {
	const url = marker.kind === 'search_update' ? marker.meta.url : undefined;
	return typeof url === 'string' && /^https?:\/\//.test(url) ? url : undefined;
}

/** Each group's colour, from the stylesheet when it can be read. */
export function groupColors(el: Element | null): Record<ChangeGroup, string> {
	const style = el ? getComputedStyle(el) : null;
	const out = { ...COLORS };
	for (const group of CHANGE_GROUPS) {
		const value = style?.getPropertyValue(`--spst-mark-${group}`).trim();
		if (value) {
			out[group] = value;
		}
	}
	return out;
}

/**
 * The page the reports are filtered to, as the change log takes it (a path,
 * * for any text): one page filter with one value. Otherwise '' (all).
 */
export function filteredPage(filters: Filter[]): string {
	const pages = filters.filter((f) => f.dimension === 'page');
	const only = pages.length === 1 ? pages[0] : undefined;
	if (only?.values.length !== 1) {
		return '';
	}
	const value = only.values[0] ?? '';
	if (only.op === 'is' || only.op === 'matches') {
		return value;
	}
	return only.op === 'contains' ? `*${value}*` : '';
}

/** The point (by index) a change falls in: the last point that starts at or before it. */
export function pointIndex(series: ChartData, t: string): number {
	const at = Date.parse(t);
	const end = Date.parse(series.range.to);
	if (!Number.isFinite(at) || at >= end) {
		return -1;
	}
	let found = -1;
	for (let i = 0; i < series.points.length; i++) {
		if (Date.parse(series.points[i]?.t ?? '') <= at) {
			found = i;
		} else {
			break;
		}
	}
	return found;
}

/** Whether a time falls before the series' first point. */
function beforeRange(series: ChartData, t: string): boolean {
	const first = Date.parse(series.points[0]?.t ?? '');
	return Number.isFinite(first) && Date.parse(t) < first;
}

/**
 * Changes by point, for the chart's lane and table. A rollout that began
 * before the range (the API adds those still running) shows on its first
 * point.
 */
export function changesByPoint(series: ChartData, markers: Marker[]): Map<number, Marker[]> {
	const out = new Map<number, Marker[]>();
	for (const marker of markers) {
		let i = pointIndex(series, marker.t);
		if (i < 0 && rolloutEnd(marker) !== undefined && beforeRange(series, marker.t) && series.points.length) {
			i = 0;
		}
		if (i >= 0) {
			out.set(i, [...(out.get(i) ?? []), marker]);
		}
	}
	return out;
}

/**
 * Rollouts as spans: from the point they began in (or the first) to the
 * point they ended in, or to now while rolling out.
 */
export function chartSpans(series: ChartData, markers: Marker[], colors: Record<ChangeGroup, string>): ChartSpan[] {
	const last = series.points.length - 1;
	if (last < 0) {
		return [];
	}
	const out: ChartSpan[] = [];
	for (const marker of markers) {
		const ended = rolloutEnd(marker);
		if (ended === undefined) {
			continue;
		}
		const start = beforeRange(series, marker.t) ? 0 : pointIndex(series, marker.t);
		const until = ended === '' ? series.generated ?? new Date().toISOString() : ended;
		if (start < 0 || beforeRange(series, until)) {
			continue;
		}
		const end = pointIndex(series, until);
		out.push({ from: start, to: end < 0 ? last : end, color: colors[marker.group] ?? COLORS.search });
	}
	return out;
}

export function chartMarkers(byPoint: Map<number, Marker[]>, colors: Record<ChangeGroup, string>): ChartMarker[] {
	return [...byPoint.entries()].map(([index, list]) => ({
		index,
		items: list.map((m) => ({ label: m.label, color: colors[m.group] ?? COLORS.site })),
	}));
}
