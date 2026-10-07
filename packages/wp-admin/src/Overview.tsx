/**
 * Overview: headline metrics, the chart, and where visits came from, what
 * they viewed (pages, pages not found, content by author, category and
 * type), what they searched for, where and on what, and what they did
 * (events).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Card, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage, useMarkers, useStats, useTimeseries } from './api';
import { filteredPage } from './changelog';
import { rangeText } from './dates';
import type { ViewProps } from './App';
import { BreakdownCard } from './components/BreakdownCard';
import { MainChart } from './components/MainChart';
import { MetricTiles } from './components/MetricTiles';
import { metricLabel } from './labels';
import { METRICS, type Marker } from '@seoprostats/core';

/** No changes (one list, so the chart is not redrawn for a new empty one). */
const NO_MARKERS: Marker[] = [];

export function Overview({ state, update }: ViewProps) {
	const stats = useStats(state);
	const series = useTimeseries(state);
	const markers = useMarkers(state, filteredPage(state.filters));
	const failed = stats.isError ? stats.error : series.isError ? series.error : null;
	const answer = stats.data;
	const empty = answer && answer.metrics.visits === 0;

	return (
		<>
			{failed && (
				<Notice status="error" isDismissible={false}>
					{errorMessage(failed, __('The statistics could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}

			<Card className="spst-summary">
				{answer && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
				<MetricTiles
					stats={answer}
					series={series.data}
					selected={state.metric}
					onSelect={(metric) => update({ metric })}
					loading={stats.isPending}
				/>
				<div className={`spst-summary__chart${series.isFetching && series.data ? ' is-refreshing' : ''}`}>
					{series.data ? (
						<MainChart
							series={series.data}
							metric={state.metric}
							label={metricLabel(state.metric)}
							format={METRICS[state.metric].format}
							markers={markers.data?.markers ?? NO_MARKERS}
							onMarker={() => update({ view: 'changes' })}
						/>
					) : (
						<div className="spst-chart-placeholder" />
					)}
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
					card="sources"
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
					card="pages"
					title={__('Pages', 'seoprostats')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'page', title: __('Top pages', 'seoprostats') },
						{ dimension: 'entry', title: __('Entry pages', 'seoprostats') },
						{ dimension: 'exit', title: __('Exit pages', 'seoprostats') },
						{ dimension: 'not_found', title: __('Not found', 'seoprostats') },
					]}
				/>
				<BreakdownCard
					card="content"
					title={__('Content', 'seoprostats')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'author', title: __('Authors', 'seoprostats') },
						{ dimension: 'category', title: __('Categories', 'seoprostats') },
						{ dimension: 'post_type', title: __('Post types', 'seoprostats') },
					]}
				/>
				<BreakdownCard
					card="search"
					title={__('Site search', 'seoprostats')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'search', title: __('Searches', 'seoprostats') },
						{ dimension: 'no_results', title: __('No results', 'seoprostats') },
					]}
				/>
				<BreakdownCard
					card="locations"
					title={__('Locations', 'seoprostats')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'country', title: __('Countries', 'seoprostats') },
						{ dimension: 'language', title: __('Languages', 'seoprostats') },
					]}
				/>
				<BreakdownCard
					card="devices"
					title={__('Devices', 'seoprostats')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'device', title: __('Devices', 'seoprostats') },
						{ dimension: 'browser', title: __('Browsers', 'seoprostats') },
						{ dimension: 'os', title: __('Systems', 'seoprostats') },
						{ dimension: 'login', title: __('Logged in', 'seoprostats') },
					]}
				/>
				<BreakdownCard
					card="events"
					title={__('Events', 'seoprostats')}
					state={state}
					update={update}
					wide
					tabs={[{ dimension: 'event', title: __('Events', 'seoprostats') }]}
				/>
			</div>
		</>
	);
}

/** The period shown, and the one it is compared with. */
export function PeriodLine({ range, compare }: { range: { from: string; to: string }; compare?: { from: string; to: string } }) {
	return (
		<p className="spst-period">
			{rangeText(range.from, range.to)}
			{compare && (
				<span className="spst-period__compare">
					{sprintf(
						/* translators: %s: the comparison period's days, e.g. "1 Aug – 31 Aug 2026". */
						__('compared with %s', 'seoprostats'),
						rangeText(compare.from, compare.to)
					)}
				</span>
			)}
		</p>
	);
}
