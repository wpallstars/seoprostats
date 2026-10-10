/**
 * A card of top values for a few related dimensions (tabs). Choosing a row
 * filters the whole view by it; choosing it again takes the filter out.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { type KeyboardEvent } from 'react';
import { Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber, formatPercent, hasFilterValue, toggleFilterValue, type Dimension, type ViewCard, type ViewState } from '@seoprostats/core';
import { errorMessage, shareAccess, useBreakdown } from '../api';
import { locale } from '../boot';
import { dimensionLabel, metricLabel, valueLabel } from '../labels';
import { usePrintAll } from '../printAll';

export interface Tab {
	dimension: Dimension;
	title: string;
}

interface Props {
	card: ViewCard;
	title: string;
	tabs: Tab[];
	state: ViewState;
	update: (patch: Partial<ViewState>) => void;
	/** Span the grid's full width. */
	wide?: boolean;
}

const PAGE_DIMENSIONS: ReadonlySet<Dimension> = new Set<Dimension>(['page', 'entry', 'exit', 'not_found']);

/** Dimensions of pageviews: their rows count views (searches, for site search). */
const VIEW_DIMENSIONS: ReadonlySet<Dimension> = new Set<Dimension>(['page', 'not_found', 'search', 'no_results', 'author', 'category', 'post_type']);

/** Dimensions whose values are IDs: a row filters by its name, which the API also takes, so the filter reads well. */
const NAMED_DIMENSIONS: ReadonlySet<Dimension> = new Set<Dimension>(['author', 'category']);

/** What a row counts: pageviews for pages and content, events for events, otherwise visits. */
function countOf(dimension: Dimension): 'pageviews' | 'events' | 'visits' {
	if (VIEW_DIMENSIONS.has(dimension)) {
		return 'pageviews';
	}
	return dimension === 'event' ? 'events' : 'visits';
}

/** What an empty list says. */
function emptyText(dimension: Dimension): string {
	switch (dimension) {
		case 'event':
			return __('No events in this period. Outbound links, file downloads and your own events show here.', 'seoprostats');
		case 'not_found':
			return __('No visits to pages that were not found in this period.', 'seoprostats');
		case 'search':
			return __('No site searches in this period.', 'seoprostats');
		case 'no_results':
			return __('Every site search in this period found something.', 'seoprostats');
		case 'author':
		case 'category':
		case 'post_type':
			return __('No views of posts or pages in this period.', 'seoprostats');
		case 'variant':
			return __('No visits saw an A/B test in this period. Add an A/B test block to a page to start one.', 'seoprostats');
		default:
			return __('Nothing in this period.', 'seoprostats');
	}
}

/** The value a row filters by. */
function filterValue(dimension: Dimension, row: { value: string; label: string }): string {
	return NAMED_DIMENSIONS.has(dimension) && row.value !== '0' && row.label ? row.label : row.value;
}

function Rows({ dimension, state, update }: Readonly<{ dimension: Dimension; state: ViewState; update: Props['update'] }>) {
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
		return <p className="spst-empty">{emptyText(dimension)}</p>;
	}
	const countRow = (r: (typeof answer.rows)[number]) => (metric === 'visits' ? r.visits : r[metric] ?? 0);
	const top = Math.max(...answer.rows.map(countRow), 1);
	const isPath = PAGE_DIMENSIONS.has(dimension);

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
					const rate = isEvent ? (row.conversion_rate ?? row.share) : row.share;
					const share = byPageviews ? '' : formatPercent(rate, locale);
					// A second click on a row that is already a filter takes it out.
					const value = filterValue(dimension, row);
					const active = hasFilterValue(state.filters, dimension, value);
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
								onClick={() => update({ filters: toggleFilterValue(state.filters, dimension, value) })}
							>
								<span className="spst-row__bar" style={{ width: `${(count / top) * 100}%` }} aria-hidden="true" />
								<span className={`spst-row__label${isPath ? ' is-path' : ''}`}>
									{label}
									{row.ab_test && (
										<span className="spst-badge" title={__('This page has a running A/B test.', 'seoprostats')}>
											{__('A/B', 'seoprostats')}
										</span>
									)}
								</span>
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

export function BreakdownCard({ card, title, tabs: all, state, update, wide = false }: Readonly<Props>) {
	const printAll = usePrintAll();
	// A shared report that hides some breakdowns shows no tab for them (and no card when none are left).
	const tabs = all.filter((tab) => !shareAccess.hidden.includes(tab.dimension));
	const chosen = state.tabs?.[card];
	const active = tabs.some((tab) => tab.dimension === chosen) ? chosen! : (tabs[0]?.dimension ?? 'channel');
	const setActive = (dimension: Dimension) => update({ tabs: { ...state.tabs, [card]: dimension } });
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
		// Past either end wraps round (next is -1 to tabs.length).
		const target = tabs[(next + tabs.length) % tabs.length];
		if (!target) {
			return;
		}
		setActive(target.dimension);
		document.getElementById(`${id}-${target.dimension}`)?.focus();
	};

	if (!tabs.length) {
		return null;
	}

	// Paper: every tab, each under its name.
	if (printAll && tabs.length > 1) {
		return (
			<Card className={`spst-card is-print-all${wide ? ' is-wide' : ''}`} size="small">
				<CardHeader className="spst-card__header">
					<h2 className="spst-card__title">{title}</h2>
				</CardHeader>
				<CardBody className="spst-card__body">
					{tabs.map((tab) => (
						<section key={tab.dimension} className="spst-print-tab">
							<h3 className="spst-print-tab__title">{tab.title}</h3>
							<Rows dimension={tab.dimension} state={state} update={update} />
						</section>
					))}
				</CardBody>
			</Card>
		);
	}

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
