/**
 * Headline metrics. Each tile picks the metric the chart shows.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import { CHART_METRICS, formatMetric, METRICS, type MetricKey, type StatsAnswer, type TimeseriesAnswer } from '@seoprostats/core';
import { locale } from '../boot';
import { metricHelp, metricLabel } from '../labels';
import { Change } from './Change';
import { Sparkline } from './Sparkline';

interface Props {
	stats?: StatsAnswer;
	series?: TimeseriesAnswer;
	selected: MetricKey;
	onSelect: (metric: MetricKey) => void;
	loading: boolean;
}

export function MetricTiles({ stats, series, selected, onSelect, loading }: Readonly<Props>) {
	return (
		<div className="spst-tiles" role="group" aria-label={__('Chart a metric', 'seoprostats')}>
			{CHART_METRICS.map((key) => {
				const spec = METRICS[key];
				const value = stats?.metrics[key];
				const previous = stats?.compare?.metrics[key];
				const help = metricHelp(key);
				return (
					<button
						key={key}
						type="button"
						className={`spst-tile${selected === key ? ' is-selected' : ''}`}
						aria-pressed={selected === key}
						title={help || undefined}
						onClick={() => onSelect(key)}
					>
						<span className="spst-tile__label">{metricLabel(key)}</span>
						<span className="spst-tile__value">
							{value === undefined ? (loading ? <span className="spst-skeleton" /> : '—') : formatMetric(value, spec.format, locale)}
						</span>
						<span className="spst-tile__foot">
							{stats?.compare && (
								<Change
									metric={key}
									change={stats.compare.change[key]}
									previous={previous === undefined ? undefined : formatMetric(previous, spec.format, locale)}
								/>
							)}
							{series && <Sparkline values={series.points.map((p) => p[key])} width={72} height={22} />}
						</span>
					</button>
				);
			})}
		</div>
	);
}
