/**
 * Search → Content: which pages earn their search traffic. Per page, the
 * chosen engine's (or every engine's, Combined) clicks, position and CTR, with the visits from
 * search that started on the page (bounce rate, time) and how many of them
 * reached a goal. A page that ranks but whose visits leave needs better
 * content or a clearer next step; one that converts but gets few clicks is
 * worth ranking higher.
 *
 * Totals as tiles, then the pages, sorted by clicks, visits or
 * conversions (the column headers). Choosing a page opens it in Rankings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState, type ReactNode } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	apiArgs,
	formatDecimal,
	formatDuration,
	formatNumber,
	formatPercent,
	SEARCH_METRICS,
	type ContentAnswer,
	type ContentMetrics,
	type ContentRow,
	type ContentSort,
	type SearchEngineChoice,
} from '@seoprostats/core';
import { errorMessage, useContent } from './api';
import { locale } from './boot';
import { longLabel } from './dates';
import { PageCell } from './Opportunities';
import { PeriodLine } from './Overview';
import { Change } from './components/Change';
import { SearchSetup, sourceName, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);
const percent = (value: number) => formatPercent(value, locale);
const place = (row: { impressions: number; position: number }) => (row.impressions ? formatDecimal(row.position, locale) : '–');

type ContentProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Content({ state, update, open, onEngines }: ContentProps) {
	const sort: ContentSort = state.sort ?? 'clicks';
	const goal = state.goal ?? '';
	const engine: SearchEngineChoice = state.engine ?? 'google';
	// Back to the first rows when the period, filters, engine, order or goal change.
	const scope = JSON.stringify([apiArgs(state), engine, sort, goal]);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useContent(state, sort, goal, PER_PAGE, offset);
	const answer = query.data;
	useReportEngines(answer, onEngines);
	// The engine answered for: Combined with fewer than two engines with data answers as the one with data.
	const answered: SearchEngineChoice = answer?.engine ?? engine;
	const rows = answer?.rows ?? [];
	const counted = answer?.goal ?? null;

	return (
		<>
			{query.isError && (
				<Notice status="error" isDismissible={false} className="spst-notice">
					{errorMessage(query.error, __('The content report could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}
			{answer && <SearchSetup answer={answer} />}
			{answer && answer.ignored.length > 0 && (
				<Notice status="warning" isDismissible={false} className="spst-notice">
					{__('Search data has no visits, so only page filters apply here; the other filters are left out.', 'seoprostats')}
				</Notice>
			)}
			{answer && answer.through && answer.partial && (
				<Notice status="info" isDismissible={false} className="spst-notice">
					{answer.landings_from
						? sprintf(
								/* translators: %s: a day, e.g. "Sun 4 Oct 2026". */
								__('Visits from search are counted from %s. Older days are still being added; they appear within a few minutes.', 'seoprostats'),
								longLabel(answer.landings_from, 'day')
							)
						: __('Visits from search are still being added; they appear within a few minutes.', 'seoprostats')}
				</Notice>
			)}

			<Card className="spst-summary">
				<div className="spst-search__head">
					<div>
						<h2 className="spst-card__title">{__('Content performance', 'seoprostats')}</h2>
						{answer && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
						{answer?.through && (
							<p className="spst-meta">
								{sprintf(
									/* translators: 1: a source, e.g. "Google Search Console", 2: a day, e.g. "Sun 4 Oct 2026". */
									__('%1$s, final days through %2$s, with the visits from search of the same days', 'seoprostats'),
									sourceName(answer.engine, answer.engines),
									longLabel(answer.through, 'day')
								)}
							</p>
						)}
					</div>
					{answer && answer.goals.length > 0 && (
						<div className="spst-properties__event">
							<SelectControl
								__nextHasNoMarginBottom
								label={__('Conversions of', 'seoprostats')}
								value={counted?.id ?? ''}
								options={answer.goals.map((g) => ({ value: g.id, label: g.name }))}
								onChange={(next) => update({ goal: next === answer.goals[0]?.id ? undefined : next })}
							/>
						</div>
					)}
				</div>
				<Tiles answer={answer} engine={answered} />
			</Card>

			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<h2 className="spst-card__title">{__('Pages', 'seoprostats')}</h2>
				</CardHeader>
				<CardBody className="spst-card__body">
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && !rows.length && (
						<div className="spst-empty">
							<p>{answer.through ? __('No page had search clicks or visits from search in this period.', 'seoprostats') : __('No search data yet.', 'seoprostats')}</p>
						</div>
					)}
					{answer && rows.length > 0 && (
						<PageTable answer={answer} sort={sort} setSort={(next) => update({ sort: next === 'clicks' ? undefined : next })} open={open} refreshing={query.isFetching} />
					)}
					{answer && answer.through && (
						<div className="spst-note">
							<p>
								{answered === 'all'
									? __(
											'Clicks and position are every search engine’s added up, Bing’s from its pages by week; visits from search are those from any search engine that started on the page, so the two differ. Conversions are those visits that reached the goal.',
											'seoprostats'
										)
									: answered === 'bing'
									? __(
											'Clicks and position are Bing’s, from its pages by week; visits from search are those from any search engine that started on the page, so the two differ. Conversions are those visits that reached the goal.',
											'seoprostats'
										)
									: __(
											'Clicks and position are Google’s; visits from search are those from any search engine that started on the page, so the two differ. Conversions are those visits that reached the goal.',
											'seoprostats'
										)}
							</p>
							{answer.goals.length === 0 && (
								<p>
									{__('Add a goal to count conversions:', 'seoprostats')} <a href="#/goals">{__('Goals', 'seoprostats')}</a>
								</p>
							)}
						</div>
					)}
					{answer && answer.total > PER_PAGE && (
						<nav className="spst-changes__pager" aria-label={__('Pages of the content report', 'seoprostats')}>
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
		</>
	);
}

/** The totals: search clicks, visits from search, their bounce rate, conversions. */
function Tiles({ answer, engine }: { answer: ContentAnswer | undefined; engine: SearchEngineChoice }) {
	const totals = answer?.totals;
	const then = answer?.compare?.totals;
	const change = answer?.compare?.change;
	const tile = (label: string, foot: string, shown: string, delta: ReactNode) => (
		<div className="spst-tile is-static">
			<span className="spst-tile__label">{label}</span>
			<span className="spst-tile__value">{totals ? shown : '–'}</span>
			<span className="spst-tile__foot">
				<span className="spst-muted">{foot}</span>
				{delta}
			</span>
		</div>
	);
	return (
		<div className="spst-tiles" role="group" aria-label={__('Totals', 'seoprostats')}>
			{tile(
				__('Clicks', 'seoprostats'),
				engine === 'all' ? __('From search engines', 'seoprostats') : engine === 'bing' ? __('From Bing', 'seoprostats') : __('From Google Search', 'seoprostats'),
				totals ? number(totals.clicks) : '',
				change && <Change change={change.clicks} better={SEARCH_METRICS.clicks.better} previous={then ? number(then.clicks) : undefined} />
			)}
			{tile(
				__('Visits from search', 'seoprostats'),
				__('Started on these pages', 'seoprostats'),
				totals ? number(totals.visits) : '',
				change && <Change change={change.visits} metric="visits" previous={then ? number(then.visits) : undefined} />
			)}
			{tile(
				__('Bounce rate', 'seoprostats'),
				__('Of the visits from search', 'seoprostats'),
				totals ? percent(totals.bounce_rate) : '',
				change && <Change change={change.bounce_rate} metric="bounce_rate" previous={then ? percent(then.bounce_rate) : undefined} />
			)}
			{answer?.goal &&
				tile(
					__('Conversions', 'seoprostats'),
					sprintf(
						/* translators: 1: goal name, 2: conversion rate, e.g. 2.5%. */
						__('%1$s · %2$s', 'seoprostats'),
						answer.goal.name,
						percent(totals?.conversion_rate ?? 0)
					),
					totals ? number(totals.conversions ?? 0) : '',
					change && <Change change={change.conversions} previous={then ? number(then.conversions ?? 0) : undefined} />
				)}
		</div>
	);
}

interface PageTableProps {
	answer: ContentAnswer;
	sort: ContentSort;
	setSort: (sort: ContentSort) => void;
	open: ContentProps['open'];
	refreshing: boolean;
}

function PageTable({ answer, sort, setSort, open, refreshing }: PageTableProps) {
	const goal = answer.goal !== null;
	const sortable = (key: ContentSort, label: string) => (
		<th scope="col" className="num" aria-sort={sort === key ? 'descending' : undefined}>
			<button
				type="button"
				className={`spst-sort${sort === key ? ' is-active' : ''}`}
				title={sprintf(/* translators: %s: column name, e.g. "Clicks". */ __('Sort by %s, most first', 'seoprostats'), label)}
				onClick={() => setSort(key)}
			>
				{label}
				{sort === key && <span aria-hidden="true"> ↓</span>}
			</button>
		</th>
	);
	return (
		<TableScroll label={__('Pages', 'seoprostats')}>
			<table className={`widefat striped spst-table spst-content${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoprostats')}</th>
						{sortable('clicks', __('Clicks', 'seoprostats'))}
						<th scope="col" className="num">
							{__('Position', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('CTR', 'seoprostats')}
						</th>
						{sortable('visits', __('Visits from search', 'seoprostats'))}
						<th scope="col" className="num">
							{__('Bounce rate', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Visit duration', 'seoprostats')}
						</th>
						{goal && sortable('conversions', __('Conversions', 'seoprostats'))}
						{goal && (
							<th scope="col" className="num">
								{__('Conversion rate', 'seoprostats')}
							</th>
						)}
					</tr>
				</thead>
				<tbody>
					{answer.rows.map((row) => (
						<PageRow key={row.path_id} row={row} goal={goal} open={open} />
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

function PageRow({ row, goal, open }: { row: ContentRow; goal: boolean; open: ContentProps['open'] }) {
	const then: ContentMetrics | undefined = row.compare;
	const change = row.compare?.change;
	return (
		<tr>
			<td>
				<PageCell row={row} query="" open={open} />
			</td>
			<td className="num">
				{number(row.clicks)}
				{change && <Change change={change.clicks} better={SEARCH_METRICS.clicks.better} previous={then ? number(then.clicks) : undefined} />}
			</td>
			<td className="num">
				{place(row)}
				{change && then && row.impressions > 0 && then.impressions > 0 && (
					<Change change={change.position} places better={SEARCH_METRICS.position.better} previous={place(then)} />
				)}
			</td>
			<td className="num">{row.impressions ? percent(row.ctr) : '–'}</td>
			<td className="num">
				{number(row.visits)}
				{change && <Change change={change.visits} metric="visits" previous={then ? number(then.visits) : undefined} />}
			</td>
			<td className="num">{row.visits ? percent(row.bounce_rate) : '–'}</td>
			<td className="num">{row.visits ? formatDuration(row.visit_duration) : '–'}</td>
			{goal && (
				<td className="num">
					{number(row.conversions ?? 0)}
					{change && then && (then.conversions ?? 0) + (row.conversions ?? 0) > 0 && (
						<Change change={change.conversions} previous={number(then.conversions ?? 0)} />
					)}
				</td>
			)}
			{goal && <td className="num">{row.visits ? percent(row.conversion_rate ?? 0) : '–'}</td>}
		</tr>
	);
}
