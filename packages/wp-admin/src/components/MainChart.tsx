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

import { useEffect, useMemo, useRef } from 'react';
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
	/** A marker chosen: the changes it covers. */
	onMarker?: (markers: Marker[]) => void;
}

/** The admin colour scheme's accent, read from WordPress's variable. */
function themeColor(el: HTMLElement | null): string {
	const value = el ? getComputedStyle(el).getPropertyValue('--wp-admin-theme-color').trim() : '';
	return value || '#2271b1';
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

export function MainChart<K extends string>({ series, metric, label, format, height = 260, markers, onMarker }: Props<K>) {
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
			formatValue: (v: number) => formatMetric(v, format, locale),
		};
	}, [series, metric, label, height, format, partial]);

	useEffect(() => {
		const el = holder.current;
		if (!el) {
			return;
		}
		const accent = themeColor(el);
		const full: TimeseriesConfig = {
			...config,
			series: config.series.map((s, i) => ({ ...s, color: i === 0 ? accent : '#8c8f94' })),
			axisColor: '#50575e',
			gridColor: 'rgba(0, 0, 0, 0.06)',
			onDraw: layoutLane,
		};
		if (chart.current) {
			chart.current.update(full);
		} else {
			chart.current = createTimeseries(el, full);
		}
		// layoutLane reads refs only.
	}, [config]);

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
			onSelect: (indexes) => pick.current?.(indexes.flatMap((i) => byPoint.get(i) ?? [])),
			onHover: (i) => chart.current?.guide(i),
		};
		if (lane.current) {
			lane.current.update(laneConfig);
		} else {
			lane.current = createMarkersLane(el, laneConfig);
		}
		layoutLane();
	}, [byPoint, series, markers]);

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
