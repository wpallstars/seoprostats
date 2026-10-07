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
import { formatNumber, formatPercent, type ClickKind, type ClickRow, type ClickTotals } from '@seoprostats/core';
import { errorMessage, useBreakdown, useClicks } from './api';
import { locale } from './boot';
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
				formatNumber(t.affiliate, locale)
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
			return __('No clicks in this period. Clicks are counted when "Count clicks and form submits" is on (Settings → Tracking), and kept for the months set in Settings → Data.', 'seoprostats');
	}
}

/** The element as people saw it: its label, or its tag#id.class. */
function Element({ row }: { row: ClickRow }) {
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

function Flags({ row }: { row: ClickRow }) {
	const flags = [
		row.affiliate && __('Affiliate', 'seoprostats'),
		row.outbound && !row.affiliate && __('Outbound', 'seoprostats'),
		row.download && __('File', 'seoprostats'),
	].filter(Boolean);
	return flags.length ? <span className="spst-meta">{flags.join(' · ')}</span> : null;
}

export function Clicks({ state, update }: ViewProps) {
	const kind = state.kind ?? 'elements';
	const page = state.page ?? '';
	const [typed, setTyped] = useState(page);
	useEffect(() => setTyped(page), [page]);
	const query = useClicks(state, kind, page);
	const pages = useBreakdown(state, 'page', 100);
	const list = useId();
	const answer = query.data;
	const rows = answer?.kind === kind ? answer.rows : [];
	const top = Math.max(...rows.map((r) => r.count), 1);
	const totals = answer?.totals;
	const change = answer?.compare?.change;
	const all = tiles();
	const tile = all.find((t) => t.kind === kind) ?? all[0]!;

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
							{(pages.data?.rows ?? []).filter((r) => r.value !== '').map((r) => (
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

				<div className="spst-tiles" role="group" aria-label={__('Show', 'seoprostats')}>
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
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && answer.kind === kind && !rows.length && (
						<div className="spst-empty">
							<p>{emptyText(kind)}</p>
						</div>
					)}
					{rows.length > 0 && (
						<TableScroll label={tile.label}>
							<table className={`widefat striped spst-table${query.isFetching ? ' is-refreshing' : ''}`}>
								<thead>
									<tr>
										<th scope="col">
											{kind === 'links' || kind === 'downloads'
												? __('Destination', 'seoprostats')
												: kind === 'forms'
													? __('Form', 'seoprostats')
													: __('Element', 'seoprostats')}
										</th>
										{kind === 'forms' && <th scope="col">{__('Sent to', 'seoprostats')}</th>}
										{kind === 'forms' && <th scope="col" className="num">{__('Fields', 'seoprostats')}</th>}
										<th scope="col" className="num">{kind === 'forms' ? __('Sent', 'seoprostats') : __('Clicks', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Visits', 'seoprostats')}</th>
										{kind === 'elements' && <th scope="col" className="num">{__('Dead', 'seoprostats')}</th>}
									</tr>
								</thead>
								<tbody>
									{rows.map((row) => (
										<tr key={`${row.selector}|${row.label}|${row.target}`}>
											<td className="spst-table__bar-cell">
												<span className="spst-table__bar" style={{ width: `${(row.count / top) * 100}%` }} aria-hidden="true" />
												{kind === 'links' || kind === 'downloads' ? (
													<>
														<span>{row.target}</span>
														{row.label && <span className="spst-meta">{row.label}</span>}
														<Flags row={row} />
													</>
												) : (
													<Element row={row} />
												)}
											</td>
											{kind === 'forms' && <td>{row.target ? <code>{row.target}</code> : <span className="spst-muted">–</span>}</td>}
											{kind === 'forms' && <td className="num">{formatNumber(row.fields, locale)}</td>}
											<td className="num">{formatNumber(row.count, locale)}</td>
											<td className="num">{formatNumber(row.visits, locale)}</td>
											{kind === 'elements' && (
												<td className="num" title={formatPercent(row.dead_rate, locale)}>
													{row.dead ? formatNumber(row.dead, locale) : <span className="spst-muted">–</span>}
												</td>
											)}
										</tr>
									))}
								</tbody>
							</table>
						</TableScroll>
					)}
					{answer && (
						<p className="spst-note">
							{kind === 'dead'
								? __('A dead click is one on something that looks clickable, after which nothing on the page changed for a second. Many on one element usually mean people expect it to do something.', 'seoprostats')
								: kind === 'forms'
									? __('Forms are counted by name, destination and number of fields. What people type or choose is never collected.', 'seoprostats')
									: __('Labels hide email addresses and long numbers. Add data-sps-mask to an element to leave out its text.', 'seoprostats')}
						</p>
					)}
				</CardBody>
			</Card>
		</>
	);
}
