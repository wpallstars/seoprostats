/**
 * A card of top values for a few related dimensions (tabs). Choosing a row
 * filters the whole view by it; choosing it again takes the filter out.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState, type KeyboardEvent } from 'react';
import { Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber, formatPercent, hasFilterValue, toggleFilterValue, type Dimension, type ViewState } from '@seoprostats/core';
import { errorMessage, useBreakdown } from '../api';
import { locale } from '../boot';
import { dimensionLabel, metricLabel, valueLabel } from '../labels';

export interface Tab {
	dimension: Dimension;
	title: string;
}

interface Props {
	title: string;
	tabs: Tab[];
	state: ViewState;
	update: (patch: Partial<ViewState>) => void;
	/** Span the grid's full width. */
	wide?: boolean;
}

const PAGE_DIMENSIONS: Dimension[] = ['page', 'entry', 'exit'];

/** What a row counts: pageviews for top pages, events for events, otherwise visits. */
function countOf(dimension: Dimension): 'pageviews' | 'events' | 'visits' {
	return dimension === 'page' ? 'pageviews' : dimension === 'event' ? 'events' : 'visits';
}

function Rows({ dimension, state, update }: { dimension: Dimension; state: ViewState; update: Props['update'] }) {
	const query = useBreakdown(state, dimension);
	const metric = countOf(dimension);
	const byPageviews = metric === 'pageviews';
	const isEvent = metric === 'events';

	if (query.isError) {
		return (
			<Notice status="error" isDismissible={false}>
				{errorMessage(query.error, __('The list could not be loaded.', 'seoprostats'))}
			</Notice>
		);
	}
	const answer = query.data;
	if (!answer) {
		return (
			<ol className="spst-rows is-loading" aria-busy="true">
				{[0, 1, 2, 3, 4].map((i) => (
					<li key={i} className="spst-row">
						<span className="spst-skeleton" />
					</li>
				))}
			</ol>
		);
	}
	if (!answer.rows.length) {
		return (
			<p className="spst-empty">
				{isEvent
					? __('No events in this period. Outbound links, file downloads and your own events show here.', 'seoprostats')
					: __('Nothing in this period.', 'seoprostats')}
			</p>
		);
	}
	const countRow = (r: (typeof answer.rows)[number]) => (metric === 'visits' ? r.visits : r[metric] ?? 0);
	const top = Math.max(...answer.rows.map(countRow), 1);
	const isPath = PAGE_DIMENSIONS.includes(dimension);

	return (
		<>
			<div className="spst-rows__head" aria-hidden="true">
				<span>{dimensionLabel(dimension)}</span>
				<span>{metricLabel(metric)}</span>
			</div>
			<ol className={`spst-rows${query.isFetching ? ' is-refreshing' : ''}`}>
				{answer.rows.map((row) => {
					const count = countRow(row);
					const label = valueLabel(dimension, row.value, row.label);
					// Events: the share of visits with the event (its conversion rate).
					const share = byPageviews ? '' : formatPercent(isEvent ? row.conversion_rate ?? row.share : row.share, locale);
					// A second click on a row that is already a filter takes it out.
					const active = hasFilterValue(state.filters, dimension, row.value);
					return (
						<li key={row.value} className="spst-row">
							<button
								type="button"
								className={`spst-row__button${active ? ' is-active' : ''}`}
								aria-pressed={active}
								title={
									active
										? sprintf(/* translators: %s: a value such as a country or page. */ __('Remove the filter for %s', 'seoprostats'), label)
										: sprintf(/* translators: %s: a value such as a country or page. */ __('Show only visits with %s', 'seoprostats'), label)
								}
								onClick={() => update({ filters: toggleFilterValue(state.filters, dimension, row.value) })}
							>
								<span className="spst-row__bar" style={{ width: `${(count / top) * 100}%` }} aria-hidden="true" />
								<span className={`spst-row__label${isPath ? ' is-path' : ''}`}>{label}</span>
								<span className="spst-row__value">
									{formatNumber(count, locale)}
									<span className="screen-reader-text"> {metricLabel(metric)}</span>
									{share && (
										<span className="spst-row__share" title={isEvent ? __('Visits with this event, of all visits', 'seoprostats') : undefined}>
											{share}
											{isEvent && <span className="screen-reader-text"> {__('of visits', 'seoprostats')}</span>}
										</span>
									)}
								</span>
							</button>
						</li>
					);
				})}
			</ol>
		</>
	);
}

export function BreakdownCard({ title, tabs, state, update, wide = false }: Props) {
	const [active, setActive] = useState<Dimension>(tabs[0]?.dimension ?? 'channel');
	const id = `spst-card-${tabs[0]?.dimension ?? 'x'}`;

	// Tabs pattern: one tab stop; arrows, Home and End move and select.
	const onTabKey = (event: KeyboardEvent<HTMLButtonElement>) => {
		const index = tabs.findIndex((tab) => tab.dimension === active);
		const last = tabs.length - 1;
		const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: last }[event.key];
		if (next === undefined) {
			return;
		}
		event.preventDefault();
		const target = tabs[next > last ? 0 : next < 0 ? last : next];
		if (!target) {
			return;
		}
		setActive(target.dimension);
		document.getElementById(`${id}-${target.dimension}`)?.focus();
	};

	return (
		<Card className={`spst-card${wide ? ' is-wide' : ''}`} size="small">
			<CardHeader className="spst-card__header">
				<h2 className="spst-card__title" id={id}>
					{title}
				</h2>
				{tabs.length > 1 && (
					<div className="spst-tabs" role="tablist" aria-labelledby={id}>
						{tabs.map((tab) => (
							<button
								key={tab.dimension}
								type="button"
								role="tab"
								id={`${id}-${tab.dimension}`}
								aria-selected={active === tab.dimension}
								aria-controls={`${id}-panel`}
								tabIndex={active === tab.dimension ? 0 : -1}
								className={`spst-tab${active === tab.dimension ? ' is-active' : ''}`}
								onClick={() => setActive(tab.dimension)}
								onKeyDown={onTabKey}
							>
								{tab.title}
							</button>
						))}
					</div>
				)}
			</CardHeader>
			<CardBody className="spst-card__body" id={`${id}-panel`} role="tabpanel" aria-labelledby={`${id}-${active}`}>
				<Rows dimension={active} state={state} update={update} />
			</CardBody>
		</Card>
	);
}
