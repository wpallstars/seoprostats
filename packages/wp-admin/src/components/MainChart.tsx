/**
 * The chosen metric over time, with the comparison period as a dashed
 * line, and the changes in the range in a markers lane under it, with
 * search engine updates' rollouts as bars. The chart
 * is drawn for sight; a table carries the same numbers and changes for
 * screen readers, and the lane's markers are buttons.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	createMarkersLane,
	createTimeseries,
	type ChartSeries,
	type MarkersLane,
	type MarkersLaneConfig,
	type TimeseriesChart,
	type TimeseriesConfig,
} from '@seoprostats/charts';
import { formatMetric, type ChartData, type Marker, type MetricFormat } from '@seoprostats/core';
import { locale } from '../boot';
import { changesByPoint, chartMarkers, chartSpans, groupColors } from '../changelog';
import { axisLabel, longLabel } from '../dates';
import { compareLabel } from '../labels';
import type { MarkerPick } from './ChangesModal';

interface Props<K extends string> {
	/** Visits (timeseries) or search (the search report's points). */
	series: ChartData<{ t: string } & Record<K, number>>;
	metric: K;
	/** The metric's name and format. */
	label: string;
	format: MetricFormat;
	height?: number;
	/** Changes in the range, for the markers lane; none: no lane. */
	markers?: Marker[];
	/** A marker chosen: the changes it covers and their days. */
	onMarker?: (pick: MarkerPick) => void;
}

/**
 * The days some points cover (site time zone, as the points' times carry
 * it): a month's run to its last day, but not past the answer's day; a
 * week's to the day before the next point (or six days on).
 */
function pointDays(series: ChartData, indexes: number[]): { from: string; to: string } {
	const first = Math.min(...indexes);
	const last = Math.max(...indexes);
	const from = (series.points[first]?.t ?? '').slice(0, 10);
	let to = (series.points[last]?.t ?? '').slice(0, 10);
	if (series.grain === 'week' && to) {
		const next = (series.points[last + 1]?.t ?? '').slice(0, 10);
		const end = new Date(`${next || to}T00:00:00Z`);
		end.setUTCDate(end.getUTCDate() + (next ? -1 : 6));
		to = end.toISOString().slice(0, 10);
	}
	if (series.grain === 'month' && to) {
		const [y, m] = to.split('-').map(Number);
		to = new Date(Date.UTC(y ?? 1970, m ?? 1, 0)).toISOString().slice(0, 10);
		const today = (series.generated ?? '').slice(0, 10);
		if (today && today < to) {
			to = today;
		}
	}
	return { from, to };
}

/**
 * A computed colour the regexes don't read (oklch(), lab()) as sRGB
 * channels: painted on a one-pixel canvas, which converts it.
 */
function paintedChannels(computed: string): number[] | undefined {
	const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
	if (!context) {
		return undefined;
	}
	context.fillStyle = '#000';
	context.fillStyle = computed;
	context.fillRect(0, 0, 1, 1);
	return Array.from(context.getImageData(0, 0, 1, 1).data.slice(0, 3));
}

/**
 * A CSS colour as #rrggbb, which the chart can fade for its area. Plain hex
 * passes through; anything else (the lighter accent in dark mode, a named
 * colour) is resolved by the browser on a hidden probe inside the element.
 */
function hexColor(el: HTMLElement, value: string, fallback: string): string {
	if (/^#[0-9a-f]{6}$/i.test(value)) {
		return value;
	}
	const probe = document.createElement('span');
	probe.style.color = value;
	if (!value || !probe.style.color) {
		return fallback;
	}
	probe.style.display = 'none';
	el.appendChild(probe);
	const computed = getComputedStyle(probe).color;
	probe.remove();
	const rgb = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/.exec(computed);
	const srgb = rgb ? null : /^color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/.exec(computed);
	const channels = rgb ? rgb.slice(1, 4).map(Number) : srgb?.slice(1, 4).map((v) => Number(v) * 255) ?? paintedChannels(computed);
	if (!channels || channels.some((v) => Number.isNaN(v))) {
		return fallback;
	}
	return `#${channels.map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('')}`;
}

/**
 * The accent: the screen's (lighter in dark mode, a report's own on a
 * shared report), else the admin colour scheme's.
 */
function themeColor(el: HTMLElement): string {
	const styles = getComputedStyle(el);
	const value = styles.getPropertyValue('--spst-accent').trim() || styles.getPropertyValue('--wp-admin-theme-color').trim();
	return hexColor(el, value, '#2271b1');
}

/**
 * Index of the point still being counted: the one whose period holds the
 * answer's time (today, this hour, this month). Times carry the site's
 * offset, so they compare as instants. Undefined when the range is over.
 */
export function partialIndex(series: ChartData): number | undefined {
	const now = Date.parse(series.generated ?? '') || Date.now();
	const points = series.points;
	for (let i = points.length - 1; i >= 0; i--) {
		const start = Date.parse(points[i]?.t ?? '');
		const end = Date.parse(i + 1 < points.length ? points[i + 1]?.t ?? '' : series.range.to);
		if (start <= now && now < end) {
			return i;
		}
		if (start <= now) {
			return undefined;
		}
	}
	return undefined;
}

/** A point's long label, marked "so far" while it is still being counted. */
function pointLabel(t: string, grain: ChartData['grain'], partial: boolean): string {
	const label = longLabel(t, grain);
	/* translators: %s: a day, hour or month still in progress, e.g. "Tue 6 Oct 2026". */
	return partial ? sprintf(__('%s (so far)', 'seoprostats'), label) : label;
}

export function MainChart<K extends string>({ series, metric, label, format, height = 260, markers, onMarker }: Readonly<Props<K>>) {
	const holder = useRef<HTMLDivElement>(null);
	const laneHolder = useRef<HTMLDivElement>(null);
	const chart = useRef<TimeseriesChart | null>(null);
	const lane = useRef<MarkersLane | null>(null);
	const partial = useMemo(() => partialIndex(series), [series]);
	const byPoint = useMemo(() => changesByPoint(series, markers ?? []), [series, markers]);
	const pick = useRef(onMarker);
	pick.current = onMarker;

	// Place the lane's markers where the chart draws its points.
	const layoutLane = () => {
		const current = chart.current;
		if (current) {
			lane.current?.layout((i) => current.clientX(i));
		}
	};

	const config = useMemo((): Omit<TimeseriesConfig, 'axisColor' | 'gridColor'> => {
		const grain = series.grain;
		// Colours are filled in where the element's styles can be read.
		const list: ChartSeries[] = [
			{
				label,
				values: series.points.map((p) => p[metric]),
				color: '',
				fill: true,
				pointLabels: series.points.map((p, i) => pointLabel(p.t, grain, i === partial)),
				partialFrom: partial,
			},
		];
		if (series.compare) {
			const compare = series.compare;
			list.push({
				label: compareLabel(compare.range.key === 'year' ? 'year' : 'prev'),
				values: compare.points.map((p) => p[metric]),
				color: '',
				dashed: true,
				pointLabels: compare.points.map((p) => longLabel(p.t, grain)),
			});
		}
		return {
			labels: series.points.map((p) => axisLabel(p.t, grain)),
			series: list,
			height,
			integer: format === 'number',
			formatValue: (v: number) => formatMetric(v, format, locale),
		};
	}, [series, metric, label, height, format, partial]);

	// The palette changes without the data: a shared report's light/dark
	// switch, or wp-admin's colour mode (admin/js/seoprostats-theme.js).
	const [palette, setPalette] = useState(0);
	useEffect(() => {
		const repaint = () => setPalette((n) => n + 1);
		const report = holder.current?.closest('.spst-report');
		const observer = report ? new MutationObserver(repaint) : null;
		if (report) {
			observer?.observe(report, { attributes: true, attributeFilter: ['class'] });
		}
		document.addEventListener('spst-themechange', repaint);
		return () => {
			observer?.disconnect();
			document.removeEventListener('spst-themechange', repaint);
		};
	}, []);

	useEffect(() => {
		const el = holder.current;
		if (!el) {
			return;
		}
		const styles = getComputedStyle(el);
		const full: TimeseriesConfig = {
			...config,
			series: config.series.map((s, i) => ({ ...s, color: i === 0 ? themeColor(el) : hexColor(el, styles.getPropertyValue('--spst-compare').trim(), '#8c8f94') })),
			axisColor: styles.getPropertyValue('--spst-muted').trim() || '#50575e',
			gridColor: styles.getPropertyValue('--spst-grid').trim() || 'rgba(0, 0, 0, 0.06)',
			onDraw: layoutLane,
		};
		if (chart.current) {
			chart.current.update(full);
		} else {
			chart.current = createTimeseries(el, full);
		}
		// layoutLane reads refs only.
	}, [config, palette]);

	useEffect(() => {
		const el = laneHolder.current;
		if (!el) {
			return;
		}
		const colors = groupColors(el);
		const laneConfig: MarkersLaneConfig = {
			markers: chartMarkers(byPoint, colors),
			spans: chartSpans(series, markers ?? [], colors),
			label: __('Changes', 'seoprostats'),
			pointLabel: (i) => (series.points[i] ? longLabel(series.points[i].t, series.grain) : ''),
			/* translators: %s: number of changes not listed. */
			moreText: (n) => sprintf(_n('and %s more', 'and %s more', n, 'seoprostats'), n.toLocaleString(locale)),
			onSelect: (indexes) => {
				const sorted = [...indexes].sort((a, b) => a - b);
				const label = (i: number) => laneConfig.pointLabel(i);
				const first = label(sorted[0] ?? 0);
				const last = label(sorted[sorted.length - 1] ?? 0);
				pick.current?.({
					markers: sorted.flatMap((i) => byPoint.get(i) ?? []),
					...pointDays(series, sorted),
					when: first === last ? first : `${first} – ${last}`,
				});
			},
			onHover: (i) => chart.current?.guide(i),
		};
		if (lane.current) {
			lane.current.update(laneConfig);
		} else {
			lane.current = createMarkersLane(el, laneConfig);
		}
		layoutLane();
	}, [byPoint, series, markers, palette]);

	useEffect(() => {
		const el = holder.current;
		if (!el || typeof ResizeObserver === 'undefined') {
			return;
		}
		const observer = new ResizeObserver((entries) => {
			const width = Math.floor(entries[0]?.contentRect.width ?? 0);
			chart.current?.resize(width);
		});
		observer.observe(el);
		return () => {
			observer.disconnect();
			lane.current?.destroy();
			lane.current = null;
			chart.current?.destroy();
			chart.current = null;
		};
	}, []);

	const compare = series.compare;
	return (
		<figure className="spst-chart">
			<div ref={holder} className="spst-chart__plot" aria-hidden="true" style={{ minHeight: height }} />
			{markers !== undefined && <div ref={laneHolder} />}
			<figcaption className="screen-reader-text">
				{label}
			</figcaption>
			<table className="screen-reader-text">
				<thead>
					<tr>
						<th scope="col">{__('Period', 'seoprostats')}</th>
						<th scope="col">{label}</th>
						{compare && <th scope="col">{compareLabel(compare.range.key === 'year' ? 'year' : 'prev')}</th>}
						{markers !== undefined && <th scope="col">{__('Changes', 'seoprostats')}</th>}
					</tr>
				</thead>
				<tbody>
					{series.points.map((p, i) => {
						const before = compare?.points[i];
						return (
							<tr key={p.t}>
								<th scope="row">{pointLabel(p.t, series.grain, i === partial)}</th>
								<td>{formatMetric(p[metric], format, locale)}</td>
								{compare && <td>{before ? formatMetric(before[metric], format, locale) : '—'}</td>}
								{markers !== undefined && <td>{(byPoint.get(i) ?? []).map((m) => m.label).join('; ')}</td>}
							</tr>
						);
					})}
				</tbody>
			</table>
		</figure>
	);
}
