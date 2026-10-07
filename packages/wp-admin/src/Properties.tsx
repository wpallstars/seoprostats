/**
 * Properties: the details your site sends with events and pages (plan,
 * author, product…). First the keys, then a key's values with their visits
 * and the revenue of the events that carried them. Optionally of one event.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useId, useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber, formatPercent } from '@seoprostats/core';
import { errorMessage, useBreakdown, useProperties } from './api';
import { locale } from './boot';
import type { ViewProps } from './App';
import { PeriodLine } from './Overview';
import { Money } from './components/Money';
import { TableScroll } from './components/TableScroll';

export function Properties({ state, update }: ViewProps) {
	const key = state.key ?? '';
	const event = state.event ?? '';
	// The box is a draft until Apply; it follows the address (back button, links).
	const [typed, setTyped] = useState(event);
	useEffect(() => setTyped(event), [event]);
	const query = useProperties(state, key, event);
	const events = useBreakdown(state, 'event', 100);
	const list = useId();
	const answer = query.data;
	const rows = answer?.rows ?? [];
	const top = Math.max(...rows.map((r) => r.count), 1);
	const hasRevenue = rows.some((r) => r.revenue.length);

	return (
		<>
			{query.isError && (
				<Notice status="error" isDismissible={false}>
					{errorMessage(query.error, __('The properties could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}

			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<div>
						<h2 className="spst-card__title">
							{key
								? sprintf(/* translators: %s: a property key such as "plan". */ __('Property: %s', 'seoprostats'), key)
								: __('Properties', 'seoprostats')}
						</h2>
						{answer && <PeriodLine range={answer.range} />}
					</div>
					<form
						className="spst-properties__event"
						onSubmit={(e) => {
							e.preventDefault();
							update({ event: typed.trim() });
						}}
					>
						<TextControl
							__nextHasNoMarginBottom
							label={__('Only of event', 'seoprostats')}
							value={typed}
							list={list}
							autoComplete="off"
							placeholder={__('Any event or page', 'seoprostats')}
							onChange={setTyped}
						/>
						<datalist id={list}>
							{(events.data?.rows ?? []).filter((r) => r.value !== '').map((r) => (
								<option key={r.value} value={r.value} />
							))}
						</datalist>
						<Button variant="secondary" type="submit">
							{__('Apply', 'seoprostats')}
						</Button>
						{event && (
							<Button
								variant="link"
								onClick={() => {
									setTyped('');
									update({ event: '' });
								}}
							>
								{__('Any event', 'seoprostats')}
							</Button>
						)}
					</form>
				</CardHeader>
				<CardBody className="spst-card__body">
					{key && (
						<p>
							<Button variant="link" onClick={() => update({ key: '' })}>
								{__('← All properties', 'seoprostats')}
							</Button>
						</p>
					)}
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && !rows.length && (
						<div className="spst-empty">
							<p>
								{event
									? __('No properties came with this event in this period.', 'seoprostats')
									: __('No properties in this period. Properties are details your site sends with an event or page, such as a plan, author or product.', 'seoprostats')}
							</p>
						</div>
					)}
					{rows.length > 0 && (
						<TableScroll label={key ? sprintf(/* translators: %s: a property name. */ __('Values of %s', 'seoprostats'), key) : __('Properties', 'seoprostats')}>
							<table className={`widefat striped spst-table${query.isFetching ? ' is-refreshing' : ''}`}>
								<thead>
									<tr>
										<th scope="col">{key ? __('Value', 'seoprostats') : __('Property', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Times sent', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Visits', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Of all visits', 'seoprostats')}</th>
										{hasRevenue && <th scope="col" className="num">{__('Revenue', 'seoprostats')}</th>}
									</tr>
								</thead>
								<tbody>
									{rows.map((row) => (
										<tr key={row.value}>
											<td className="spst-table__bar-cell">
												<span className="spst-table__bar" style={{ width: `${(row.count / top) * 100}%` }} aria-hidden="true" />
												{key ? (
													<span>{row.label}</span>
												) : (
													<Button variant="link" onClick={() => update({ key: row.value })}>
														{row.label}
													</Button>
												)}
											</td>
											<td className="num">{formatNumber(row.count, locale)}</td>
											<td className="num">{formatNumber(row.visits, locale)}</td>
											<td className="num">{formatPercent(row.share, locale)}</td>
											{hasRevenue && (
												<td className="num">
													<Money revenue={row.revenue} />
												</td>
											)}
										</tr>
									))}
								</tbody>
							</table>
						</TableScroll>
					)}
					{!key && rows.length > 0 && <p className="spst-note">{__('Choose a property to see its values.', 'seoprostats')}</p>}
				</CardBody>
			</Card>
		</>
	);
}
