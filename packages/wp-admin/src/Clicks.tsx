/**
 * Clicks: what people click and which forms they send (autocapture).
 * Totals as tiles that switch the table: clicked elements, dead clicks
 * (nothing happened), link destinations, file links and forms. Optionally
 * on one page. Never what anyone typed or chose in a form.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useId, useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber, formatPercent, type ClickKind, type ClickRow, type ClicksAnswer, type ClickTotals, type ClickPageInfo } from '@seoprostats/core';
import { errorMessage, shareAccess, useBreakdown, useClicks } from './api';
import { locale } from './boot';
import { usePrintAll } from './printAll';
import type { ViewProps } from './App';
import { PeriodLine } from './Overview';
import { Change } from './components/Change';
import { TableScroll } from './components/TableScroll';

interface Tile {
	kind: ClickKind;
	label: string;
	total: keyof ClickTotals;
	better?: 'down';
	foot: (t: ClickTotals) => string;
}

const tiles = (): Tile[] => [
	{
		kind: 'pages',
		label: __('Pages', 'seoprostats'),
		total: 'clicks',
		foot: () => __('Where people click', 'seoprostats'),
	},
	{
		kind: 'elements',
		label: __('Clicks', 'seoprostats'),
		total: 'clicks',
		foot: (t) =>
			sprintf(
				/* translators: %s: number of visits. */
				_n('in %s visit', 'in %s visits', t.visits, 'seoprostats'),
				formatNumber(t.visits, locale),
			),
	},
	{
		kind: 'dead',
		label: __('Dead clicks', 'seoprostats'),
		total: 'dead',
		better: 'down',
		foot: (t) => sprintf(/* translators: %s: percentage of clicks. */ __('%s of clicks', 'seoprostats'), formatPercent(t.dead_rate, locale)),
	},
	{
		kind: 'links',
		label: __('Link clicks', 'seoprostats'),
		total: 'links',
		foot: (t) =>
			sprintf(
				/* translators: 1: outbound link clicks, 2: affiliate link clicks. */
				__('%1$s outbound · %2$s affiliate', 'seoprostats'),
				formatNumber(t.outbound, locale),
				formatNumber(t.affiliate, locale),
			),
	},
	{
		kind: 'downloads',
		label: __('File links', 'seoprostats'),
		total: 'downloads',
		foot: () => '',
	},
	{
		kind: 'forms',
		label: __('Forms sent', 'seoprostats'),
		total: 'forms',
		foot: () => '',
	},
];

function emptyText(kind: ClickKind): string {
	switch (kind) {
		case 'dead':
			return __('No dead clicks in this period: everything people clicked did something.', 'seoprostats');
		case 'links':
			return __('No link clicks in this period.', 'seoprostats');
		case 'downloads':
			return __('No file links clicked in this period.', 'seoprostats');
		case 'forms':
			return __('No forms sent in this period.', 'seoprostats');
		default:
			// A shared report's readers cannot change the settings.
			return shareAccess.token
				? __('No clicks in this period.', 'seoprostats')
				: __(
						'No clicks in this period. Clicks are counted when "Count clicks and form submits" is on (Settings → Tracking), and kept for the months set in Settings → Data.',
						'seoprostats',
					);
	}
}

/** The element as people saw it: its label, or its tag#id.class. */
function Element({ row }: Readonly<{ row: ClickRow }>) {
	return (
		<>
			<span>{row.label || <span className="spst-muted">{__('(no text)', 'seoprostats')}</span>}</span>
			{row.selector && (
				<span className="spst-meta">
					<code>{row.selector}</code>
				</span>
			)}
		</>
	);
}

function Flags({ row }: Readonly<{ row: ClickRow }>) {
	const flags = [
		row.affiliate && __('Affiliate', 'seoprostats'),
		row.outbound && !row.affiliate && __('Outbound', 'seoprostats'),
		row.download && __('File', 'seoprostats'),
	].filter(Boolean);
	return flags.length ? <span className="spst-meta">{flags.join(' · ')}</span> : null;
}

function PageLinks({ info }: Readonly<{ info: Pick<ClickPageInfo, 'path' | 'url' | 'edit_url'> }>) {
	return (
		<span className="spst-meta">
			{info.url && (
				<a
					href={info.url}
					target="_blank"
					rel="noopener noreferrer"
					aria-label={sprintf(/* translators: %s: page path. */ __('View %s (opens in a new tab)', 'seoprostats'), info.path)}
				>
					<span className="dashicons dashicons-external" aria-hidden="true" /> {__('View page', 'seoprostats')}
				</a>
			)}
			{info.edit_url && (
				<>
					{' '}
					·{' '}
					<a href={info.edit_url} aria-label={sprintf(/* translators: %s: page path. */ __('Edit %s', 'seoprostats'), info.path)}>
						<span className="dashicons dashicons-edit" aria-hidden="true" /> {__('Edit', 'seoprostats')}
					</a>
				</>
			)}
		</span>
	);
}

export function Clicks({ state, update }: Readonly<ViewProps>) {
	const kind: ClickKind = state.kind ?? 'elements';
	const page = state.page ?? '';
	// The box is a draft until Apply; it follows the address (back button, links).
	const [typed, setTyped] = useState(page);
	useEffect(() => setTyped(page), [page]);
	const query = useClicks(state, kind, page);
	const pages = useBreakdown(state, 'page', 100);
	const list = useId();
	const printAll = usePrintAll();
	const answer = query.data;
	const totals = answer?.totals;
	const change = answer?.compare?.change;
	const all = tiles();
	const tile = all.find((t) => t.kind === kind) ?? all[0]!;
	const pageInfo = answer?.page === page ? answer.page_info : null;

	return (
		<>
			{query.isError && (
				<Notice status="error" isDismissible={false}>
					{errorMessage(query.error, __('The clicks could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}

			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<div>
						<h2 className="spst-card__title">
							{page ? sprintf(/* translators: %s: a page path. */ __('Clicks on %s', 'seoprostats'), page) : __('Clicks', 'seoprostats')}
						</h2>
						{answer && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
						{pageInfo && <PageLinks info={pageInfo} />}
					</div>
					<form
						className="spst-properties__event"
						onSubmit={(e) => {
							e.preventDefault();
							update({ page: typed.trim() });
						}}
					>
						<TextControl
							__nextHasNoMarginBottom
							label={__('Only on page', 'seoprostats')}
							value={typed}
							list={list}
							autoComplete="off"
							placeholder={__('Any page; * for any text', 'seoprostats')}
							onChange={setTyped}
						/>
						<datalist id={list}>
							{(pages.data?.rows ?? [])
								.filter((r) => r.value !== '')
								.map((r) => (
									<option key={r.value} value={r.value} />
								))}
						</datalist>
						<Button variant="secondary" type="submit">
							{__('Apply', 'seoprostats')}
						</Button>
						{page && (
							<Button
								variant="link"
								onClick={() => {
									setTyped('');
									update({ page: '' });
								}}
							>
								{__('Any page', 'seoprostats')}
							</Button>
						)}
					</form>
				</CardHeader>

				<div className="spst-tiles" role="group" aria-label={__('Show', 'seoprostats')}>{/* NOSONAR: a group of buttons; a fieldset would bring its own border, padding and min-width. */}
					{all.map((t) => (
						<button
							key={t.kind}
							type="button"
							className={`spst-tile${kind === t.kind ? ' is-selected' : ''}`}
							aria-pressed={kind === t.kind}
							onClick={() => update({ kind: t.kind })}
						>
							<span className="spst-tile__label">{t.label}</span>
							<span className="spst-tile__value">{totals ? formatNumber(totals[t.total], locale) : '–'}</span>
							<span className="spst-tile__foot">
								<span className="spst-muted">{totals ? t.foot(totals) : ''}</span>
								{change && <Change change={change[t.total]} better={t.better} />}
							</span>
						</button>
					))}
				</div>

				<CardBody className="spst-card__body">
					{printAll ? (
						all.map((t) => (
							<PrintedKind key={t.kind} state={state} tile={t} page={page} update={update} />
						))
					) : (
						<ClickRows answer={answer} kind={kind} label={tile.label} failed={query.isError} fetching={query.isFetching} page={page} update={update} />
					)}
				</CardBody>
			</Card>
		</>
	);
}

/** Paper: one kind's table under its name. */
function PrintedKind({ state, tile, page, update }: Readonly<{ state: ViewProps['state']; tile: Tile; page: string; update: ViewProps['update'] }>) {
	const query = useClicks(state, tile.kind, page);
	return (
		<section className="spst-print-tab">
			<h3 className="spst-print-tab__title">{tile.label}</h3>
			<ClickRows answer={query.data} kind={tile.kind} label={tile.label} failed={query.isError} fetching={query.isFetching} page={page} update={update} />
		</section>
	);
}

interface ClickRowsProps {
	answer: ClicksAnswer | undefined;
	kind: ClickKind;
	label: string;
	failed: boolean;
	fetching: boolean;
	page: string;
	update: ViewProps['update'];
}

/** One kind of clicks: loading, empty, or its table, and what it counts. */
function ClickRows({ answer, kind, label, failed, fetching, page, update }: Readonly<ClickRowsProps>) {
	const rows = answer?.kind === kind ? answer.rows : [];
	const top = Math.max(...rows.map((r) => r.count), 1);
	return (
		<>
		{!answer && !failed && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
		{answer && answer.kind === kind && !rows.length && (
			<div className="spst-empty">
				<p>{emptyText(kind)}</p>
			</div>
		)}
		{rows.length > 0 && (
			<TableScroll label={label}>
				<table className={`widefat striped spst-table${fetching ? ' is-refreshing' : ''}`}>
					<thead>
						<tr>
							<th scope="col">{firstColumn(kind)}</th>
							{kind === 'forms' && <th scope="col">{__('Sent to', 'seoprostats')}</th>}
							{kind === 'forms' && (
								<th scope="col" className="num">
									{__('Fields', 'seoprostats')}
								</th>
							)}
							<th scope="col" className="num">
								{kind === 'forms' ? __('Sent', 'seoprostats') : __('Clicks', 'seoprostats')}
							</th>
							<th scope="col" className="num">
								{__('Visits', 'seoprostats')}
							</th>
							{(kind === 'elements' || kind === 'pages') && (
								<th scope="col" className="num">
									{__('Dead', 'seoprostats')}
								</th>
							)}
							{kind === 'pages' && (
								<>
									<th scope="col" className="num">
										{__('Dead-click rate', 'seoprostats')}
									</th>
									<th scope="col" className="num">
										{__('Link clicks', 'seoprostats')}
									</th>
									<th scope="col" className="num">
										{__('Forms sent', 'seoprostats')}
									</th>
								</>
							)}
						</tr>
					</thead>
					<tbody>
						{rows.map((row) => (
							<tr key={row.path ?? `${row.selector}|${row.label}|${row.target}`} className={kind === 'pages' && page === row.path ? 'is-selected' : ''}>
								<td className="spst-table__bar-cell">
									<span className="spst-table__bar" style={{ width: `${(row.count / top) * 100}%` }} aria-hidden="true" />
									<RowName row={row} kind={kind} page={page} update={update} />
								</td>
								{kind === 'forms' && <td>{row.target ? <code>{row.target}</code> : <span className="spst-muted">–</span>}</td>}
								{kind === 'forms' && <td className="num">{formatNumber(row.fields, locale)}</td>}
								<td className="num">{formatNumber(row.count, locale)}</td>
								<td className="num">{formatNumber(row.visits, locale)}</td>
								{(kind === 'elements' || kind === 'pages') && (
									<td className="num" title={formatPercent(row.dead_rate, locale)}>
										{row.dead ? formatNumber(row.dead, locale) : <span className="spst-muted">–</span>}
									</td>
								)}
								{kind === 'pages' && (
									<>
										<td className="num">{formatPercent(row.dead_rate, locale)}</td>
										<td className="num">{formatNumber(row.links ?? 0, locale)}</td>
										<td className="num">{formatNumber(row.forms ?? 0, locale)}</td>
									</>
								)}
							</tr>
						))}
					</tbody>
				</table>
			</TableScroll>
		)}
		{answer && (
			<p className="spst-note">{footNote(kind)}</p>
		)}
		</>
	);
}

/** The first column's heading for a kind. */
function firstColumn(kind: ClickKind): string {
	if (kind === 'pages') {
		return __('Page', 'seoprostats');
	}
	if (kind === 'links' || kind === 'downloads') {
		return __('Destination', 'seoprostats');
	}
	return kind === 'forms' ? __('Form', 'seoprostats') : __('Element', 'seoprostats');
}

/** What the table counts, under it. */
function footNote(kind: ClickKind): string {
	if (kind === 'dead') {
		return __(
			'A dead click is one on something that looks clickable, after which nothing on the page changed for a second. Many on one element usually mean people expect it to do something.',
			'seoprostats',
		);
	}
	return kind === 'forms'
		? __('Forms are counted by name, destination and number of fields. What people type or choose is never collected.', 'seoprostats')
		: __('Labels hide email addresses and long numbers. Add data-sps-mask to an element to leave out its text.', 'seoprostats');
}

/** A row's first cell: a page (picks it), a destination, or an element. */
function RowName({ row, kind, page, update }: Readonly<Pick<ClickRowsProps, 'kind' | 'page' | 'update'> & { row: ClickRow }>) {
	if (kind === 'pages') {
		return (
			<>
				<Button
					variant="link"
					aria-pressed={page === row.path}
					onClick={() => {
						update({ page: page === row.path ? '' : (row.path ?? '') });
					}}
				>
					{row.path}
				</Button>
				<PageLinks
					info={{
						path: row.path ?? '',
						url: row.url ?? '',
						edit_url: row.edit_url,
					}}
				/>
			</>
		);
	}
	if (kind === 'links' || kind === 'downloads') {
		return (
			<>
				<span>{row.target}</span>
				{row.label && <span className="spst-meta">{row.label}</span>}
				<Flags row={row} />
			</>
		);
	}
	return <Element row={row} />;
}
