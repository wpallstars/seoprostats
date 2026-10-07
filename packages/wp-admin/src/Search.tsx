/**
 * Search: clicks, impressions, CTR and average position from Google
 * Search Console's imported days, as tiles that pick the chart's metric,
 * with the changes on the timeline under it; then the top search queries,
 * pages, countries and devices. Choose a page to see its queries, or a
 * query to see its pages. Search days are final only (about three days
 * old), so the period stops at the newest one.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useId, useState, type KeyboardEvent } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import {
	formatMetric,
	SEARCH_METRICS,
	type Marker,
	type SearchAnswer,
	type SearchKind,
	type SearchMetricKey,
	type SearchRow,
	type ViewState,
} from '@seoprostats/core';
import { errorMessage, useMarkers, useSearch } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import { longLabel } from './dates';
import type { ViewProps } from './App';
import { PeriodLine } from './Overview';
import { Change } from './components/Change';
import { MainChart } from './components/MainChart';
import { TableScroll } from './components/TableScroll';

/** No changes (one list, so the chart is not redrawn for a new empty one). */
const NO_MARKERS: Marker[] = [];

const METRIC_ORDER: SearchMetricKey[] = ['clicks', 'impressions', 'ctr', 'position'];

function metricName(metric: SearchMetricKey): string {
	const names: Record<SearchMetricKey, string> = {
		clicks: __('Clicks', 'seoprostats'),
		impressions: __('Impressions', 'seoprostats'),
		ctr: __('CTR', 'seoprostats'),
		position: __('Average position', 'seoprostats'),
	};
	return names[metric];
}

function metricFoot(metric: SearchMetricKey): string {
	const feet: Record<SearchMetricKey, string> = {
		clicks: __('Visits from Google Search', 'seoprostats'),
		impressions: __('Times shown in results', 'seoprostats'),
		ctr: __('Clicks per impression', 'seoprostats'),
		position: __('Lower is better', 'seoprostats'),
	};
	return feet[metric];
}

function kindName(kind: SearchKind): string {
	const names: Record<SearchKind, string> = {
		queries: __('Queries', 'seoprostats'),
		pages: __('Pages', 'seoprostats'),
		countries: __('Countries', 'seoprostats'),
		devices: __('Devices', 'seoprostats'),
	};
	return names[kind];
}

/** A metric's value; position "–" without impressions. */
function value(metric: SearchMetricKey, row: { impressions: number } & Record<SearchMetricKey, number>): string {
	if (metric === 'position' && !row.impressions) {
		return '–';
	}
	return formatMetric(row[metric], SEARCH_METRICS[metric].format, locale);
}

/** The title: the site, a page, a query, or both. */
function title(page: string, query: string): string {
	if (page && query) {
		/* translators: 1: a search query, 2: a page path. */
		return sprintf(__('Google Search: “%1$s” showing %2$s', 'seoprostats'), query, page);
	}
	if (page) {
		/* translators: %s: a page path. */
		return sprintf(__('Google Search: %s', 'seoprostats'), page);
	}
	if (query) {
		/* translators: %s: a search query. */
		return sprintf(__('Google Search: “%s”', 'seoprostats'), query);
	}
	return __('Google Search', 'seoprostats');
}

function Setup({ answer }: { answer: SearchAnswer }) {
	const demo = useDataSet() === 'demo';
	if (demo || answer.through) {
		return null;
	}
	const connections = boot.canManage && boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'connections' }) : '';
	return (
		<Notice status="info" isDismissible={false} className="spst-notice">
			{answer.connected
				? __('Search Console is connected. Its days are imported in the background, the newest about three days old; they show here as they arrive.', 'seoprostats')
				: __('Connect Google Search Console to see the searches that show your pages: clicks, impressions, CTR and average position, next to your visits and changes.', 'seoprostats')}{' '}
			{!answer.connected && connections && <a href={connections}>{__('Settings → Connections', 'seoprostats')}</a>}
		</Notice>
	);
}

export function Search({ state, update }: ViewProps) {
	const kind: SearchKind = state.tab ?? 'queries';
	const metric: SearchMetricKey = state.chart ?? 'clicks';
	const page = state.page ?? '';
	const query = state.query ?? '';
	// The boxes are drafts until Apply; they follow the address (back button, links).
	const [typedPage, setTypedPage] = useState(page);
	const [typedQuery, setTypedQuery] = useState(query);
	useEffect(() => setTypedPage(page), [page]);
	useEffect(() => setTypedQuery(query), [query]);
	const setKind = (tab: SearchKind) => update({ tab });
	const id = useId();
	// Countries and devices exist for the whole site only.
	const kinds: SearchKind[] = page || query ? ['queries', 'pages'] : ['queries', 'pages', 'countries', 'devices'];
	const shown: SearchKind = kinds.includes(kind) ? kind : 'queries';
	const search = useSearch(state, shown, page, query);
	const markers = useMarkers(state, page);
	const answer = search.data;
	const rows = answer?.kind === shown ? answer.rows : [];
	const top = Math.max(...rows.map((r) => r.clicks), 1);
	const totals = answer?.totals;
	const change = answer?.compare?.change;
	const then = answer?.compare?.totals;
	const pageInfo = answer?.page === page ? answer.page_info : null;

	// A page chosen shows its queries; a query chosen, its pages.
	const choose = (next: { page?: string; query?: string }) => {
		const patch: Partial<ViewState> = {};
		if (next.page !== undefined) {
			patch.page = next.page;
			if (next.page && next.query === undefined) {
				patch.tab = 'queries';
			}
		}
		if (next.query !== undefined) {
			patch.query = next.query;
			if (next.query && next.page === undefined) {
				patch.tab = 'pages';
			}
		}
		update(patch);
	};

	const onTabKey = (event: KeyboardEvent<HTMLButtonElement>) => {
		const at = kinds.indexOf(shown);
		const next = event.key === 'ArrowRight' ? at + 1 : event.key === 'ArrowLeft' ? at - 1 : null;
		if (next === null) {
			return;
		}
		event.preventDefault();
		const target = kinds[(next + kinds.length) % kinds.length] ?? 'queries';
		setKind(target);
		document.getElementById(`${id}-${target}`)?.focus();
	};

	return (
		<>
			{search.isError && (
				<Notice status="error" isDismissible={false}>
					{errorMessage(search.error, __('The search data could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}
			{answer && <Setup answer={answer} />}
			{answer && answer.ignored.length > 0 && (
				<Notice status="warning" isDismissible={false} className="spst-notice">
					{__('Search data has no visits, so only page filters apply here; the other filters are left out.', 'seoprostats')}
				</Notice>
			)}

			<Card className="spst-summary">
				<div className="spst-search__head">
					<div>
						<h2 className="spst-card__title">{title(page, query)}</h2>
						{answer && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
						{answer?.through && (
							<p className="spst-meta">
								{sprintf(
									/* translators: %s: a day, e.g. "Sun 4 Oct 2026". */
									__('Google Search Console, final days through %s', 'seoprostats'),
									longLabel(answer.through, 'day')
								)}
							</p>
						)}
						{pageInfo && (pageInfo.url || pageInfo.edit_url) && (
							<p className="spst-meta">
								{pageInfo.url && (
									<a href={pageInfo.url} target="_blank" rel="noopener noreferrer">
										<span className="dashicons dashicons-external" aria-hidden="true" /> {__('View page', 'seoprostats')}
									</a>
								)}
								{pageInfo.edit_url && (
									<>
										{' '}
										·{' '}
										<a href={pageInfo.edit_url}>
											<span className="dashicons dashicons-edit" aria-hidden="true" /> {__('Edit', 'seoprostats')}
										</a>
									</>
								)}
							</p>
						)}
					</div>
					<form
						className="spst-properties__event spst-search__boxes"
						onSubmit={(e) => {
							e.preventDefault();
							choose({ page: typedPage.trim(), query: typedQuery.trim() });
						}}
					>
						<TextControl
							__nextHasNoMarginBottom
							label={__('Page', 'seoprostats')}
							value={typedPage}
							autoComplete="off"
							placeholder={__('Any page; * for any text', 'seoprostats')}
							onChange={setTypedPage}
						/>
						<TextControl
							__nextHasNoMarginBottom
							label={__('Query', 'seoprostats')}
							value={typedQuery}
							autoComplete="off"
							placeholder={__('Any query; * for any text', 'seoprostats')}
							onChange={setTypedQuery}
						/>
						<Button variant="secondary" type="submit">
							{__('Apply', 'seoprostats')}
						</Button>
						{(page || query) && (
							<Button variant="link" onClick={() => choose({ page: '', query: '' })}>
								{__('Whole site', 'seoprostats')}
							</Button>
						)}
					</form>
				</div>

				<div className="spst-tiles" role="group" aria-label={__('Chart', 'seoprostats')}>
					{METRIC_ORDER.map((key) => (
						<button
							key={key}
							type="button"
							className={`spst-tile${metric === key ? ' is-selected' : ''}`}
							aria-pressed={metric === key}
							onClick={() => update({ chart: key })}
						>
							<span className="spst-tile__label">{metricName(key)}</span>
							<span className="spst-tile__value">{totals ? value(key, totals) : '–'}</span>
							<span className="spst-tile__foot">
								<span className="spst-muted">{metricFoot(key)}</span>
								{change && (
									<Change
										change={change[key]}
										places={key === 'position'}
										better={SEARCH_METRICS[key].better}
										previous={then ? value(key, then) : undefined}
									/>
								)}
							</span>
						</button>
					))}
				</div>
				{/* No search data yet: no chart of zeros. */}
				{!(answer && !answer.through) && (
					<div className={`spst-summary__chart${search.isFetching && answer ? ' is-refreshing' : ''}`}>
						{answer && answer.points.length > 0 ? (
							<MainChart
								series={answer}
								metric={metric}
								label={metricName(metric)}
								format={SEARCH_METRICS[metric].format}
								markers={markers.data?.markers ?? NO_MARKERS}
								onMarker={() => update({ view: 'changes' })}
							/>
						) : (
							<div className="spst-chart-placeholder" />
						)}
					</div>
				)}
			</Card>

			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<h2 className="spst-card__title" id={id}>
						{__('Top searches', 'seoprostats')}
					</h2>
					<div className="spst-tabs" role="tablist" aria-labelledby={id}>
						{kinds.map((k) => (
							<button
								key={k}
								type="button"
								role="tab"
								id={`${id}-${k}`}
								aria-selected={shown === k}
								aria-controls={`${id}-panel`}
								tabIndex={shown === k ? 0 : -1}
								className={`spst-tab${shown === k ? ' is-active' : ''}`}
								onClick={() => setKind(k)}
								onKeyDown={onTabKey}
							>
								{kindName(k)}
							</button>
						))}
					</div>
				</CardHeader>
				<CardBody className="spst-card__body" id={`${id}-panel`} role="tabpanel" aria-labelledby={`${id}-${shown}`}>
					{!answer && !search.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && answer.kind === shown && !rows.length && (
						<div className="spst-empty">
							<p>{answer.through ? __('No searches of this kind in this period.', 'seoprostats') : __('No search data yet.', 'seoprostats')}</p>
						</div>
					)}
					{rows.length > 0 && (
						<TableScroll label={kindName(shown)}>
							<table className={`widefat striped spst-table${search.isFetching ? ' is-refreshing' : ''}`}>
								<thead>
									<tr>
										<th scope="col">{shown === 'queries' ? __('Query', 'seoprostats') : shown === 'pages' ? __('Page', 'seoprostats') : shown === 'countries' ? __('Country', 'seoprostats') : __('Device', 'seoprostats')}</th>
										{METRIC_ORDER.map((key) => (
											<th key={key} scope="col" className="num">
												{key === 'position' ? __('Position', 'seoprostats') : metricName(key)}
											</th>
										))}
									</tr>
								</thead>
								<tbody>
									{rows.map((row) => (
										<Row key={row.id} row={row} kind={shown} top={top} page={page} query={query} choose={choose} />
									))}
								</tbody>
							</table>
						</TableScroll>
					)}
					{answer && rows.length > 0 && (
						<p className="spst-note">
							{__(
								'Search Console leaves out rare searches to protect searchers, so the rows add up to less than the totals. Position is the average of the highest place a page held each time it was shown.',
								'seoprostats'
							)}
						</p>
					)}
				</CardBody>
			</Card>
		</>
	);
}

interface RowProps {
	row: SearchRow;
	kind: SearchKind;
	top: number;
	page: string;
	query: string;
	choose: (next: { page?: string; query?: string }) => void;
}

function Row({ row, kind, top, page, query, choose }: RowProps) {
	const before = row.compare;
	let name = <span>{row.label}</span>;
	if (kind === 'queries') {
		name = (
			<Button variant="link" aria-pressed={query === row.value} onClick={() => choose({ query: query === row.value ? '' : row.value })}>
				{row.value}
			</Button>
		);
	} else if (kind === 'pages') {
		const path = row.path ?? row.value;
		name = (
			<>
				<Button variant="link" aria-pressed={page === path} onClick={() => choose({ page: page === path ? '' : path })}>
					{path}
				</Button>
				{row.url && (
					<span className="spst-meta">
						<a
							href={row.url}
							target="_blank"
							rel="noopener noreferrer"
							aria-label={sprintf(/* translators: %s: page path. */ __('View %s (opens in a new tab)', 'seoprostats'), path)}
						>
							<span className="dashicons dashicons-external" aria-hidden="true" /> {__('View page', 'seoprostats')}
						</a>
						{row.edit_url && (
							<>
								{' '}
								·{' '}
								<a href={row.edit_url} aria-label={sprintf(/* translators: %s: page path. */ __('Edit %s', 'seoprostats'), path)}>
									<span className="dashicons dashicons-edit" aria-hidden="true" /> {__('Edit', 'seoprostats')}
								</a>
							</>
						)}
					</span>
				)}
			</>
		);
	}
	return (
		<tr className={(kind === 'pages' && page === (row.path ?? row.value)) || (kind === 'queries' && query === row.value) ? 'is-selected' : ''}>
			<td className="spst-table__bar-cell">
				<span className="spst-table__bar" style={{ width: `${(row.clicks / top) * 100}%` }} aria-hidden="true" />
				{name}
			</td>
			{METRIC_ORDER.map((key) => (
				<td key={key} className="num">
					{value(key, row)}
					{before && <Change change={before.change[key]} places={key === 'position'} better={SEARCH_METRICS[key].better} previous={value(key, before)} />}
				</td>
			))}
		</tr>
	);
}
