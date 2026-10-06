/**
 * The chosen metric over time, with the comparison period as a dashed
 * line. The chart is drawn for sight; a table carries the same numbers for
 * screen readers.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useMemo, useRef } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { createTimeseries, type ChartSeries, type TimeseriesChart, type TimeseriesConfig } from '@seoprostats/charts';
import { formatMetric, METRICS, type MetricKey, type TimeseriesAnswer } from '@seoprostats/core';
import { locale } from '../boot';
import { axisLabel, longLabel } from '../dates';
import { compareLabel, metricLabel } from '../labels';

interface Props {
	series: TimeseriesAnswer;
	metric: MetricKey;
	height?: number;
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
export function partialIndex(series: TimeseriesAnswer): number | undefined {
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
function pointLabel(t: string, grain: TimeseriesAnswer['grain'], partial: boolean): string {
	const label = longLabel(t, grain);
	/* translators: %s: a day, hour or month still in progress, e.g. "Tue 6 Oct 2026". */
	return partial ? sprintf(__('%s (so far)', 'seoprostats'), label) : label;
}

export function MainChart({ series, metric, height = 260 }: Props) {
	const holder = useRef<HTMLDivElement>(null);
	const chart = useRef<TimeseriesChart | null>(null);
	const spec = METRICS[metric];
	const partial = useMemo(() => partialIndex(series), [series]);

	const config = useMemo((): Omit<TimeseriesConfig, 'axisColor' | 'gridColor'> => {
		const grain = series.grain;
		// Colours are filled in where the element's styles can be read.
		const list: ChartSeries[] = [
			{
				label: metricLabel(metric),
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
			formatValue: (v: number) => formatMetric(v, spec.format, locale),
		};
	}, [series, metric, height, spec.format, partial]);

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
		};
		if (chart.current) {
			chart.current.update(full);
		} else {
			chart.current = createTimeseries(el, full);
		}
	}, [config]);

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
			chart.current?.destroy();
			chart.current = null;
		};
	}, []);

	const compare = series.compare;
	return (
		<figure className="spst-chart">
			<div ref={holder} className="spst-chart__plot" aria-hidden="true" style={{ minHeight: height }} />
			<figcaption className="screen-reader-text">
				{metricLabel(metric)}
			</figcaption>
			<table className="screen-reader-text">
				<thead>
					<tr>
						<th scope="col">{__('Period', 'seoprostats')}</th>
						<th scope="col">{metricLabel(metric)}</th>
						{compare && <th scope="col">{compareLabel(compare.range.key === 'year' ? 'year' : 'prev')}</th>}
					</tr>
				</thead>
				<tbody>
					{series.points.map((p, i) => {
						const before = compare?.points[i];
						return (
							<tr key={p.t}>
								<th scope="row">{pointLabel(p.t, series.grain, i === partial)}</th>
								<td>{formatMetric(p[metric], spec.format, locale)}</td>
								{compare && <td>{before ? formatMetric(before[metric], spec.format, locale) : '—'}</td>}
							</tr>
						);
					})}
				</tbody>
			</table>
		</figure>
	);
}
