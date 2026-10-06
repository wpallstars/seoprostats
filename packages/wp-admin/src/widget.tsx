/**
 * The Dashboard widget: today so far against yesterday at the same time,
 * the last 7 days' trend, and a link to the full statistics.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { formatMetric, formatNumber, METRICS, type MetricKey } from '@seoprostats/core';
import { errorMessage, useStats, useTimeseries } from './api';
import { boot, locale } from './boot';
import { Change } from './components/Change';
import { Sparkline } from './components/Sparkline';
import { metricLabel } from './labels';
import { mount } from './mount';
import './widget.css';

const TODAY_METRICS: MetricKey[] = ['visitors', 'pageviews', 'bounce_rate'];

function Widget() {
	const today = useStats({ range: 'today', compare: 'prev', filters: [] });
	const week = useTimeseries({ range: '7d', compare: 'none', filters: [] });

	if (today.isError) {
		return (
			<Notice status="error" isDismissible={false}>
				{errorMessage(today.error, __('The statistics could not be loaded.', 'seoprostats'))}
			</Notice>
		);
	}
	const answer = today.data;
	const visitors = week.data?.points.map((p) => p.visitors) ?? [];
	const weekTotal = visitors.reduce((a, b) => a + b, 0);

	return (
		<div className="spst-widget">
			<h3 className="spst-widget__heading">{__('Today so far', 'seoprostats')}</h3>
			<dl className="spst-widget__metrics">
				{TODAY_METRICS.map((key) => (
					<div key={key} className="spst-widget__metric">
						<dt>{metricLabel(key)}</dt>
						<dd>
							<span className="spst-widget__value">
								{answer ? formatMetric(answer.metrics[key], METRICS[key].format, locale) : <span className="spst-skeleton" />}
							</span>
							{answer?.compare && (
								<Change
									metric={key}
									change={answer.compare.change[key]}
									previous={formatMetric(answer.compare.metrics[key], METRICS[key].format, locale)}
								/>
							)}
						</dd>
					</div>
				))}
			</dl>
			{answer?.compare && <p className="spst-widget__note">{__('Compared with yesterday at the same time.', 'seoprostats')}</p>}
			<div className="spst-widget__week">
				<div>
					<span className="spst-widget__week-label">{__('Visitors, last 7 days', 'seoprostats')}</span>
					<strong className="spst-widget__week-value">{week.data ? formatNumber(weekTotal, locale) : '…'}</strong>
				</div>
				<Sparkline values={visitors} width={160} height={36} />
			</div>
			{boot.dashboardUrl && (
				<p className="spst-widget__more">
					<a className="button" href={boot.dashboardUrl}>
						{__('View statistics', 'seoprostats')}
					</a>
				</p>
			)}
		</div>
	);
}

mount('spst-widget', <Widget />);
