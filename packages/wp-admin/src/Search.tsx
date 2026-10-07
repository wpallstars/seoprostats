/**
 * Search, from an engine's imported days (Google Search Console, or Bing
 * Webmaster Tools once it has data), in three tabs; the engine switch
 * sits beside them and stays across the tabs.
 *
 * Rankings: clicks, impressions, CTR and average position as tiles that
 * pick the chart's metric, with the changes on the timeline under it;
 * then the top search queries, pages, countries and devices (Google
 * only). Choose a page to see its queries, or a query to see its pages;
 * Bing's are by week. Search days are final only (some days old), so the
 * period stops at the newest one.
 *
 * Opportunities (./Opportunities): where search effort pays; Content
 * (./Content): each page's search clicks with its visits from search and
 * their conversions. Choosing a row in either opens it in Rankings.
 * Plan (./Plan): one ranked list of what to do next, made from
 * Opportunities. Experiments (./Experiments): changes measured against
 * unchanged pages.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useCallback, useEffect, useId, useState, type KeyboardEvent } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	formatMetric,
	SEARCH_METRICS,
	SEARCH_REPORTS,
	type Marker,
	type SearchEngine,
	type SearchKind,
	type SearchMetricKey,
	type SearchReport,
	type SearchRow,
	type ViewState,
} from '@seoprostats/core';
import { errorMessage, useMarkers, useSearch } from './api';
import { locale } from './boot';
import { longLabel } from './dates';
import type { ViewProps } from './App';
import { PeriodLine } from './Overview';
import { Change } from './components/Change';
import { useChangesModal } from './components/ChangesModal';
import { MainChart } from './components/MainChart';
import { EngineSwitch, SearchSetup as Setup, sourceName, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';
import { Opportunities } from './Opportunities';
import { Content } from './Content';
import { Experiments } from './Experiments';
import { Plan } from './Plan';

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

function metricFoot(metric: SearchMetricKey, engine: SearchEngine): string {
	const feet: Record<SearchMetricKey, string> = {
		clicks: engine === 'bing' ? __('Visits from Bing', 'seoprostats') : __('Visits from Google Search', 'seoprostats'),
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

/** The title: the site, a page, a query, or both, on an engine. */
function title(page: string, query: string, engine: SearchEngine): string {
	const name = engine === 'bing' ? __('Bing Search', 'seoprostats') : __('Google Search', 'seoprostats');
	if (page && query) {
		/* translators: 1: a search engine, e.g. "Google Search", 2: a search query, 3: a page path. */
		return sprintf(__('%1$s: “%2$s” showing %3$s', 'seoprostats'), name, query, page);
	}
	if (page) {
		/* translators: 1: a search engine, e.g. "Google Search", 2: a page path. */
		return sprintf(__('%1$s: %2$s', 'seoprostats'), name, page);
	}
	if (query) {
		/* translators: 1: a search engine, e.g. "Google Search", 2: a search query. */
		return sprintf(__('%1$s: “%2$s”', 'seoprostats'), name, query);
	}
	return name;
}

/** An × in a text box: empties it and applies at once. */
function ClearButton({ label, onClear }: { label: string; onClear: () => void }) {
	return (
		<button type="button" className="spst-clearable__clear" aria-label={label} title={label} onClick={onClear}>
			<span className="dashicons dashicons-no-alt" aria-hidden="true" />
		</button>
	);
}

/** Search: Rankings (what happened), Opportunities (where effort pays) and Content (what search visits do), as `report` in the address. */
export function Search(props: ViewProps) {
	const { state, update } = props;
	const report: SearchReport = state.report ?? 'rankings';
	const engine: SearchEngine = state.engine ?? 'google';
	const id = useId();
	// The engines with data, as the last answer listed them (every report's answer does).
	const [engines, setEngines] = useState<SearchEngine[]>(['google']);
	const onEngines = useCallback((list: SearchEngine[]) => setEngines((was) => (was.join() === list.join() ? was : list)), []);
	// Google is the default, so it is left out of the address; Bing has no countries or devices.
	const chooseEngine = (next: SearchEngine) =>
		update({ engine: next === 'google' ? undefined : next, tab: next === 'bing' && (state.tab === 'countries' || state.tab === 'devices') ? undefined : state.tab });
	const names: Record<SearchReport, string> = {
		rankings: __('Rankings', 'seoprostats'),
		opportunities: __('Opportunities', 'seoprostats'),
		content: __('Content', 'seoprostats'),
		plan: __('Plan', 'seoprostats'),
		experiments: __('Experiments', 'seoprostats'),
	};
	// Rankings is the default, so it is left out of the address; Content's order and goal go with Content, Plan's state and goal with Plan, a change with Experiments.
	const show = (next: SearchReport) => update({ report: next === 'rankings' ? undefined : next, sort: undefined, goal: undefined, status: undefined, change: undefined });

	const onKey = (event: KeyboardEvent<HTMLButtonElement>) => {
		const at = SEARCH_REPORTS.indexOf(report);
		const next = event.key === 'ArrowRight' ? at + 1 : event.key === 'ArrowLeft' ? at - 1 : null;
		if (next === null) {
			return;
		}
		event.preventDefault();
		const target = SEARCH_REPORTS[(next + SEARCH_REPORTS.length) % SEARCH_REPORTS.length] ?? 'rankings';
		show(target);
		document.getElementById(`${id}-${target}`)?.focus();
	};

	// A row of Opportunities or Content opens in Rankings: a page shows its queries; a query alone, its pages.
	const open = (pick: SearchPick) =>
		update({
			report: undefined,
			sort: undefined,
			goal: undefined,
			status: undefined,
			page: pick.page || undefined,
			query: pick.query || undefined,
			tab: pick.query && !pick.page ? 'pages' : undefined,
		});

	const reportProps = { ...props, onEngines };

	return (
		<>
			<div className="spst-subnav">
			<div className="spst-tabs" role="tablist" aria-label={__('Search', 'seoprostats')}>
				{SEARCH_REPORTS.map((t) => (
					<button
						key={t}
						type="button"
						role="tab"
						id={`${id}-${t}`}
						aria-selected={report === t}
						aria-controls={`${id}-panel`}
						tabIndex={report === t ? 0 : -1}
						className={`spst-tab${report === t ? ' is-active' : ''}`}
						onClick={() => show(t)}
						onKeyDown={onKey}
					>
						{names[t]}
					</button>
				))}
			</div>
			<EngineSwitch engines={engines} engine={engine} choose={chooseEngine} />
			</div>
			<div id={`${id}-panel`} role="tabpanel" aria-labelledby={`${id}-${report}`} className="spst-subpanel">
				{report === 'rankings' && <Rankings {...reportProps} />}
				{report === 'opportunities' && <Opportunities {...reportProps} open={open} />}
				{report === 'content' && <Content {...reportProps} open={open} />}
				{report === 'plan' && <Plan {...reportProps} open={open} />}
				{report === 'experiments' && <Experiments {...reportProps} />}
			</div>
		</>
	);
}

function Rankings({ state, update, onEngines }: SearchReportProps) {
	const engine: SearchEngine = state.engine ?? 'google';
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
	// Countries and devices exist for the whole site only, and from Google only.
	const kinds: SearchKind[] = page || query || engine === 'bing' ? ['queries', 'pages'] : ['queries', 'pages', 'countries', 'devices'];
	const shown: SearchKind = kinds.includes(kind) ? kind : 'queries';
	const search = useSearch(state, shown, page, query);
	const markers = useMarkers(state, page);
	const changes = useChangesModal(update, page);
	const answer = search.data;
	useReportEngines(answer, onEngines);
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
						<h2 className="spst-card__title">{title(page, query, engine)}</h2>
						{answer && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
						{answer?.through && (
							<p className="spst-meta">
								{sprintf(
									/* translators: 1: a source, e.g. "Google Search Console", 2: a day, e.g. "Sun 4 Oct 2026". */
									__('%1$s, final days through %2$s', 'seoprostats'),
									sourceName(answer.engine ?? engine),
									longLabel(answer.through, 'day')
								)}
								{answer.grain === 'week' && ` · ${__('by week', 'seoprostats')}`}
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
						<div className="spst-clearable">
							<TextControl
								__nextHasNoMarginBottom
								label={__('Page', 'seoprostats')}
								value={typedPage}
								autoComplete="off"
								placeholder={__('Any page; * for any text', 'seoprostats')}
								onChange={setTypedPage}
							/>
							{typedPage && (
								<ClearButton
									label={__('Clear the page', 'seoprostats')}
									onClear={() => {
										setTypedPage('');
										choose({ page: '' });
									}}
								/>
							)}
						</div>
						<div className="spst-clearable">
							<TextControl
								__nextHasNoMarginBottom
								label={__('Query', 'seoprostats')}
								value={typedQuery}
								autoComplete="off"
								placeholder={__('Any query; * for any text', 'seoprostats')}
								onChange={setTypedQuery}
							/>
							{typedQuery && (
								<ClearButton
									label={__('Clear the query', 'seoprostats')}
									onClear={() => {
										setTypedQuery('');
										choose({ query: '' });
									}}
								/>
							)}
						</div>
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
								<span className="spst-muted">{metricFoot(key, engine)}</span>
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
								onMarker={changes.onMarker}
							/>
						) : (
							<div className="spst-chart-placeholder" />
						)}
					</div>
				)}
			</Card>
			{changes.modal}

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
							{engine === 'bing'
								? __(
										'Bing gives its top pages and queries by week, so a period’s rows cover the weeks that end in it, and they add up to less than the totals. Position is the average place shown, weighted by impressions; a day’s position is its week’s.',
										'seoprostats'
									)
								: __(
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
