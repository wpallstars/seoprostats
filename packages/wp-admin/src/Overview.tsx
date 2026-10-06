/**
 * Overview: headline metrics, the chart, and where visits came from, what
 * they viewed, where and on what, and what they did (events).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Card, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage, useDemo, useStats, useTimeseries } from './api';
import { useDataSet } from './data';
import { rangeText } from './dates';
import { useViewState } from './hash';
import { BreakdownCard } from './components/BreakdownCard';
import { Controls } from './components/Controls';
import { DemoNotice, DemoSwitch } from './components/Demo';
import { FilterBar } from './components/FilterBar';
import { MainChart } from './components/MainChart';
import { MetricTiles } from './components/MetricTiles';
import { Realtime } from './components/Realtime';

export function Overview() {
	const [state, update] = useViewState();
	const data = useDataSet();
	const demo = useDemo();
	const stats = useStats(state);
	const series = useTimeseries(state);
	const failed = stats.isError ? stats.error : series.isError ? series.error : null;
	const answer = stats.data;
	const empty = answer && answer.metrics.visits === 0;

	if (data === 'demo' && demo.data.status !== 'ready') {
		return (
			<div className="spst-app">
				<div className="spst-toolbar">
					<DemoSwitch />
				</div>
				<DemoNotice />
			</div>
		);
	}

	return (
		<div className="spst-app">
			<div className="spst-toolbar">
				<Controls state={state} update={update} />
				<div className="spst-toolbar__end">
					<Realtime />
					<DemoSwitch />
				</div>
			</div>

			<DemoNotice />

			<FilterBar filters={state.filters} update={update} />

			{failed && (
				<Notice status="error" isDismissible={false}>
					{errorMessage(failed, __('The statistics could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}

			<Card className="spst-summary">
				{answer && (
					<p className="spst-period">
						{rangeText(answer.range.from, answer.range.to)}
						{answer.compare && (
							<span className="spst-period__compare">
								{sprintf(
									/* translators: %s: the comparison period's days, e.g. "1 Aug – 31 Aug 2026". */
									__('compared with %s', 'seoprostats'),
									rangeText(answer.compare.range.from, answer.compare.range.to)
								)}
							</span>
						)}
					</p>
				)}
				<MetricTiles
					stats={answer}
					series={series.data}
					selected={state.metric}
					onSelect={(metric) => update({ metric })}
					loading={stats.isPending}
				/>
				<div className={`spst-summary__chart${series.isFetching && series.data ? ' is-refreshing' : ''}`}>
					{series.data ? <MainChart series={series.data} metric={state.metric} /> : <div className="spst-chart-placeholder" />}
				</div>
			</Card>

			{empty && (
				<Notice status="info" isDismissible={false} className="spst-notice">
					{state.filters.length
						? __('No visits match these filters in this period.', 'seoprostats')
						: __('No visits in this period yet. Visits show here within a minute or two of happening.', 'seoprostats')}
				</Notice>
			)}

			<div className="spst-grid">
				<BreakdownCard
					title={__('Sources', 'seoprostats')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'channel', title: __('Channels', 'seoprostats') },
						{ dimension: 'source', title: __('Sources', 'seoprostats') },
						{ dimension: 'utm_campaign', title: __('Campaigns', 'seoprostats') },
					]}
				/>
				<BreakdownCard
					title={__('Pages', 'seoprostats')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'page', title: __('Top pages', 'seoprostats') },
						{ dimension: 'entry', title: __('Entry pages', 'seoprostats') },
						{ dimension: 'exit', title: __('Exit pages', 'seoprostats') },
					]}
				/>
				<BreakdownCard
					title={__('Locations', 'seoprostats')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'country', title: __('Countries', 'seoprostats') },
						{ dimension: 'language', title: __('Languages', 'seoprostats') },
					]}
				/>
				<BreakdownCard
					title={__('Devices', 'seoprostats')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'device', title: __('Devices', 'seoprostats') },
						{ dimension: 'browser', title: __('Browsers', 'seoprostats') },
						{ dimension: 'os', title: __('Systems', 'seoprostats') },
					]}
				/>
				<BreakdownCard
					title={__('Events', 'seoprostats')}
					state={state}
					update={update}
					wide
					tabs={[{ dimension: 'event', title: __('Events', 'seoprostats') }]}
				/>
			</div>
		</div>
	);
}
