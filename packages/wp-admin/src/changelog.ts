/**
 * The change log in the dashboard: group names and colours, the page the
 * reports are filtered to, and changes as chart markers.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import type { ChartMarker } from '@seoprostats/charts';
import { CHANGE_GROUPS, type ChangeGroup, type Filter, type Marker, type TimeseriesAnswer } from '@seoprostats/core';

export { CHANGE_GROUPS };

export function groupLabel(group: ChangeGroup): string {
	const labels: Record<ChangeGroup, string> = {
		content: __('Content', 'seoprostats'),
		seo: __('SEO', 'seoprostats'),
		product: __('Products', 'seoprostats'),
		site: __('Site', 'seoprostats'),
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
	note: '#1d2327',
};

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
	if (!only || only.values.length !== 1) {
		return '';
	}
	const value = only.values[0] ?? '';
	if (only.op === 'is' || only.op === 'matches') {
		return value;
	}
	return only.op === 'contains' ? `*${value}*` : '';
}

/** The point (by index) a change falls in: the last point that starts at or before it. */
export function pointIndex(series: TimeseriesAnswer, t: string): number {
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

/** Changes by point, for the chart's lane and table. */
export function changesByPoint(series: TimeseriesAnswer, markers: Marker[]): Map<number, Marker[]> {
	const out = new Map<number, Marker[]>();
	for (const marker of markers) {
		const i = pointIndex(series, marker.t);
		if (i >= 0) {
			out.set(i, [...(out.get(i) ?? []), marker]);
		}
	}
	return out;
}

export function chartMarkers(byPoint: Map<number, Marker[]>, colors: Record<ChangeGroup, string>): ChartMarker[] {
	return [...byPoint.entries()].map(([index, list]) => ({
		index,
		items: list.map((m) => ({ label: m.label, color: colors[m.group] ?? COLORS.site })),
	}));
}
