/**
 * Search → Opportunities: where search effort pays, from the chosen
 * engine's imported days (Search Console or Bing), in three cards.
 *
 * - Striking distance: a page's query at position 4–20; the clicks it
 *   could gain in the top three.
 * - Low CTR: a page's query in the top 10 chosen less often than the
 *   site's own CTR at that position; the clicks it misses.
 * - Losing clicks: pages with fewer clicks than the earlier period, with
 *   the likely cause, the queries that lost most and what changed on
 *   the page.
 *
 * Choosing a row opens it in Rankings. The period is cut at the newest
 * search day and to its newest 91 days.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState, type ReactNode } from 'react';
import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	apiArgs,
	formatDecimal,
	formatNumber,
	formatPercent,
	SEARCH_METRICS,
	type DecayCause,
	type Marker,
	type OpportunitiesAnswer,
	type OpportunityDecay,
	type OpportunityKind,
	type OpportunityPage,
	type OpportunityPair,
} from '@seoprostats/core';
import { errorMessage, useOpportunities } from './api';
import { locale } from './boot';
import { longLabel } from './dates';
import type { ViewProps } from './App';
import { PeriodLine } from './Overview';
import { Change } from './components/Change';
import { SearchSetup, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 10;

const number = (value: number) => formatNumber(value, locale, false);
const percent = (value: number) => formatPercent(value, locale);
const place = (value: number | null) => (value === null ? '–' : formatDecimal(value, locale));

function causeName(cause: DecayCause): string {
	const names: Record<DecayCause, string> = {
		position: __('Position', 'seoprostats'),
		demand: __('Demand', 'seoprostats'),
		ctr: __('CTR', 'seoprostats'),
		gone: __('Not shown', 'seoprostats'),
	};
	return names[cause];
}

/** A day of a change: Tue 16 Sep 2026. */
function day(marker: Marker): string {
	return longLabel(marker.t, 'day');
}

type OpportunitiesProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Opportunities({ state, open, onEngines }: OpportunitiesProps) {
	// The striking card's own request (the same arguments, so fetched once).
	const striking = useOpportunities(state, 'striking', PER_PAGE, 0);
	const answer = striking.data;
	useReportEngines(answer, onEngines);
	return (
		<>
			{answer && <SearchSetup answer={answer} />}
			{answer && answer.ignored.length > 0 && (
				<Notice status="warning" isDismissible={false} className="spst-notice">
					{__('Search data has no visits, so only page filters apply here; the other filters are left out.', 'seoprostats')}
				</Notice>
			)}
			<div className="spst-opportunities">
				<KindCard state={state} kind="striking" open={open} />
				<KindCard state={state} kind="ctr" open={open} />
				<KindCard state={state} kind="decay" open={open} />
			</div>
		</>
	);
}

function kindTitle(kind: OpportunityKind): string {
	const titles: Record<OpportunityKind, string> = {
		striking: __('Striking distance', 'seoprostats'),
		ctr: __('Low CTR', 'seoprostats'),
		decay: __('Losing clicks', 'seoprostats'),
	};
	return titles[kind];
}

/** What a card lists, with its thresholds and the CTR aimed for. */
function kindIntro(answer: OpportunitiesAnswer): string {
	const rules = answer.rules;
	if (answer.kind === 'striking') {
		return sprintf(
			/* translators: 1: lowest position, 2: highest position, 3: minimum impressions, 4: target position, 5: the site's CTR at that position. */
			__(
				'Queries a page ranks for at positions %1$s–%2$s with at least %3$s impressions. Reaching position %4$s, where this site’s CTR is %5$s, would bring about the clicks shown.',
				'seoprostats'
			),
			number(rules.position_from ?? 4),
			number(rules.position_to ?? 20),
			number(rules.min_impressions ?? 0),
			number(rules.target ?? 3),
			percent(answer.curve?.ctr[String(rules.target ?? 3)] ?? 0)
		);
	}
	if (answer.kind === 'ctr') {
		return sprintf(
			/* translators: 1: highest position, 2: minimum impressions, 3: share, e.g. 60%. */
			__(
				'Queries where a page is in the top %1$s (at least %2$s impressions) but its CTR is under %3$s of this site’s own CTR at that position. A clearer title and description may win the missed clicks.',
				'seoprostats'
			),
			number(rules.position_to ?? 10),
			number(rules.min_impressions ?? 0),
			percent(rules.under ?? 0.6)
		);
	}
	return sprintf(
		/* translators: 1: share of clicks lost, e.g. 20%, 2: minimum clicks lost. */
		__(
			'Pages with at least %1$s fewer clicks (and %2$s or more) than in the earlier period, with the likely cause, the queries that lost most and what changed on the page.',
			'seoprostats'
		),
		percent(rules.min_share ?? 0.2),
		number(rules.min_lost ?? 0)
	);
}

function KindCard({ state, kind, open }: { state: ViewProps['state']; kind: OpportunityKind; open: OpportunitiesProps['open'] }) {
	// Back to the first rows when the period, filters or engine change.
	const scope = JSON.stringify({ ...apiArgs(state), engine: state.engine ?? 'google' });
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useOpportunities(state, kind, PER_PAGE, offset);
	const answer = query.data?.kind === kind ? query.data : undefined;
	const rows = answer?.rows ?? [];
	const titleId = `spst-opportunities-${kind}`;

	let table: ReactNode = null;
	if (answer && rows.length > 0) {
		table =
			kind === 'decay' ? (
				<DecayTable rows={rows as OpportunityDecay[]} open={open} refreshing={query.isFetching} label={kindTitle(kind)} />
			) : (
				<PairTable kind={kind} rows={rows as OpportunityPair[]} open={open} refreshing={query.isFetching} label={kindTitle(kind)} />
			);
	}

	return (
		<Card className="spst-card is-wide spst-section" size="small">
			<CardHeader className="spst-card__header">
				<div>
					<h2 className="spst-card__title" id={titleId}>
						{kindTitle(kind)}
					</h2>
					{answer && answer.through && answer.days > 0 && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
				</div>
			</CardHeader>
			<CardBody className="spst-card__body">
				{query.isError && (
					<Notice status="error" isDismissible={false}>
						{errorMessage(query.error, __('The opportunities could not be loaded. Reload the page to try again.', 'seoprostats'))}
					</Notice>
				)}
				{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
				{answer && answer.through && <p className="spst-note spst-opportunities__intro">{kindIntro(answer)}</p>}
				{answer && !rows.length && (
					<div className="spst-empty">
						<p>
							{!answer.through
								? __('No search data yet.', 'seoprostats')
								: kind === 'decay'
									? __('No page lost clicks this way in this period.', 'seoprostats')
									: __('Nothing of this kind in this period.', 'seoprostats')}
						</p>
					</div>
				)}
				{table}
				{answer && answer.through && <Notes answer={answer} />}
				{answer && answer.total > PER_PAGE && (
					<nav className="spst-changes__pager" aria-label={sprintf(/* translators: %s: card title, e.g. "Low CTR". */ __('Pages of %s', 'seoprostats'), kindTitle(kind))}>
						<span className="spst-muted">
							{sprintf(
								/* translators: 1: first row shown, 2: last row shown, 3: number of rows. */
								__('%1$s–%2$s of %3$s', 'seoprostats'),
								number(offset + 1),
								number(Math.min(offset + PER_PAGE, answer.total)),
								number(answer.total)
							)}
						</span>
						<Button variant="secondary" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PER_PAGE))}>
							{__('Previous', 'seoprostats')}
						</Button>
						<Button variant="secondary" disabled={!answer.more} onClick={() => setOffset(offset + PER_PAGE)}>
							{__('Next', 'seoprostats')}
						</Button>
					</nav>
				)}
			</CardBody>
		</Card>
	);
}

/** Notes under a card: the period read, the CTR curve's source, search engine updates. */
function Notes({ answer }: { answer: OpportunitiesAnswer }) {
	const notes: string[] = [];
	if (answer.cut) {
		notes.push(
			sprintf(
				/* translators: %s: number of days. */
				_n('Read from the newest %s day of the period.', 'Read from the newest %s days of the period.', answer.days, 'seoprostats'),
				number(answer.days)
			)
		);
	}
	if (answer.curve?.source === 'default') {
		notes.push(__('This site has too few impressions to measure its own CTR by position yet, so a cautious typical CTR is used.', 'seoprostats'));
	} else if (answer.curve?.source === 'mixed') {
		notes.push(__('Expected CTR is this site’s own by position; positions with too few impressions use a cautious typical CTR.', 'seoprostats'));
	}
	const updates = answer.updates ?? [];
	if (!notes.length && !updates.length) {
		return null;
	}
	return (
		<div className="spst-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
			{updates.length > 0 && (
				<p>
					{__('Search engine updates in these periods:', 'seoprostats')}{' '}
					{updates.map((u, i) => (
						<span key={u.id}>
							{i > 0 && '; '}
							{u.label} ({day(u)})
						</span>
					))}
				</p>
			)}
		</div>
	);
}

/** A page: its path (opens Rankings) and View and Edit links; also Content's. */
export function PageCell({ row, query, open }: { row: OpportunityPage; query: string; open: OpportunitiesProps['open'] }) {
	return (
		<>
			<button type="button" className="spst-link" title={__('Open in Rankings', 'seoprostats')} onClick={() => open({ page: row.path, query })}>
				{query || row.path}
			</button>
			{(query || row.url || row.edit_url) && (
				<span className="spst-meta">
					{query && row.path}
					{query && (row.url || row.edit_url) && ' · '}
					{row.url && (
						<a
							href={row.url}
							target="_blank"
							rel="noopener noreferrer"
							aria-label={sprintf(/* translators: %s: page path. */ __('View %s (opens in a new tab)', 'seoprostats'), row.path)}
						>
							<span className="dashicons dashicons-external" aria-hidden="true" /> {__('View page', 'seoprostats')}
						</a>
					)}
					{row.edit_url && (
						<>
							{row.url && ' · '}
							<a href={row.edit_url} aria-label={sprintf(/* translators: %s: page path. */ __('Edit %s', 'seoprostats'), row.path)}>
								<span className="dashicons dashicons-edit" aria-hidden="true" /> {__('Edit', 'seoprostats')}
							</a>
						</>
					)}
				</span>
			)}
		</>
	);
}

interface PairTableProps {
	kind: OpportunityKind;
	rows: OpportunityPair[];
	open: OpportunitiesProps['open'];
	refreshing: boolean;
	label: string;
}

function PairTable({ kind, rows, open, refreshing, label }: PairTableProps) {
	return (
		<TableScroll label={label}>
			<table className={`widefat striped spst-table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Query and page', 'seoprostats')}</th>
						<th scope="col" className="num">
							{__('Position', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Impressions', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Clicks', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('CTR', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{kind === 'striking' ? __('CTR at top 3', 'seoprostats') : __('Expected CTR', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{kind === 'striking' ? __('Clicks to gain', 'seoprostats') : __('Clicks missed', 'seoprostats')}
						</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={`${row.path_id}:${row.query}`}>
							<td>
								<PageCell row={row} query={row.query} open={open} />
							</td>
							<td className="num">{place(row.position)}</td>
							<td className="num">{number(row.impressions)}</td>
							<td className="num">{number(row.clicks)}</td>
							<td className="num">{percent(row.ctr)}</td>
							<td className="num">{percent(row.expected_ctr)}</td>
							<td className="num">
								<strong>+{number(row.potential)}</strong>
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

interface DecayTableProps {
	rows: OpportunityDecay[];
	open: OpportunitiesProps['open'];
	refreshing: boolean;
	label: string;
}

function DecayTable({ rows, open, refreshing, label }: DecayTableProps) {
	return (
		<TableScroll label={label}>
			<table className={`widefat striped spst-table spst-decay${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoprostats')}</th>
						<th scope="col" className="num">
							{__('Clicks', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Position', 'seoprostats')}
						</th>
						<th scope="col">{__('Likely cause', 'seoprostats')}</th>
						<th scope="col">{__('Queries that lost most', 'seoprostats')}</th>
						<th scope="col">{__('Changed on the page', 'seoprostats')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.path_id}>
							<td>
								<PageCell row={row} query="" open={open} />
							</td>
							<td className="num">
								{number(row.clicks)}
								<Change change={row.compare.change.clicks} better={SEARCH_METRICS.clicks.better} previous={number(row.compare.clicks)} />
								<span className="spst-meta">
									{sprintf(/* translators: %s: number of clicks. */ __('%s lost', 'seoprostats'), number(row.lost))}
								</span>
							</td>
							<td className="num">
								{row.impressions ? place(row.position) : '–'}
								{row.impressions > 0 && row.compare.impressions > 0 && (
									<Change change={row.compare.change.position} places better={SEARCH_METRICS.position.better} previous={place(row.compare.position)} />
								)}
							</td>
							<td>
								<strong className={`spst-cause is-${row.cause}`}>{causeName(row.cause)}</strong>
								<span className="spst-meta">{row.why}</span>
							</td>
							<td>
								{row.queries.length ? (
									<ul className="spst-decay__list">
										{row.queries.map((q) => (
											<li key={q.query}>
												<button type="button" className="spst-link" title={__('Open in Rankings', 'seoprostats')} onClick={() => open({ page: row.path, query: q.query })}>
													{q.query}
												</button>
												<span className="spst-meta">
													{sprintf(
														/* translators: 1: clicks before, 2: clicks now, 3: position before, 4: position now. */
														__('%1$s → %2$s clicks · position %3$s → %4$s', 'seoprostats'),
														number(q.then_clicks),
														number(q.clicks),
														place(q.then_position),
														place(q.position)
													)}
												</span>
											</li>
										))}
									</ul>
								) : (
									<span className="spst-muted">–</span>
								)}
							</td>
							<td>
								{row.changes.length ? (
									<ul className="spst-decay__list">
										{row.changes.map((c) => (
											<li key={c.id}>
												<span className="spst-changes__group">
													<span className="spst-chart-lane__dot" style={{ background: `var(--spst-mark-${c.group})` }} aria-hidden="true" />
													<span>{c.label}</span>
												</span>
												<span className="spst-meta">{day(c)}</span>
											</li>
										))}
									</ul>
								) : (
									<span className="spst-muted">{__('No change recorded', 'seoprostats')}</span>
								)}
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}
