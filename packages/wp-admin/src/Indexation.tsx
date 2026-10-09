/**
 * Search → Audit, Indexation: pages search engines do not seem to show,
 * from the site's own search data, in two lists: published pages with no
 * impressions in the engine's newest days (published before them), and
 * other addresses in the site's sitemaps (category, tag and author
 * archives) with none. Never shown first, then the newest; each row also
 * goes to Plan. With Google, each page's URL Inspection (./Inspections)
 * gives Google's reason and last crawl.
 *
 * Choosing a page opens it in Rankings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber, INDEXATION_KINDS, serializeFilter, singleEngine, type IndexationAnswer, type IndexationKind, type IndexationRow, type SearchEngine, type SitemapSource } from '@seoprostats/core';
import { errorMessage, useIndexation } from './api';
import { locale } from './boot';
import { GoogleCell } from './Inspections';
import { longLabel } from './dates';
import { PageCell } from './Opportunities';
import type { SearchPick, SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);

/** A list's name, short enough for a list. */
export function indexationName(kind: IndexationKind): string {
	const names: Record<IndexationKind, string> = {
		pages: __('Published pages not shown', 'seoprostats'),
		sitemap: __('Sitemap addresses not shown', 'seoprostats'),
	};
	return names[kind];
}

/** Where a sitemap address comes from. */
function sourceLabel(source: SitemapSource | undefined): string {
	if (source === 'taxonomies') {
		return __('Category or tag', 'seoprostats');
	}
	if (source === 'users') {
		return __('Author', 'seoprostats');
	}
	return __('Other', 'seoprostats');
}

type IndexationProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Indexation({ state, update, open }: IndexationProps) {
	const kind: IndexationKind = state.index ?? 'pages';
	const engine: SearchEngine = singleEngine(state.engine);
	// Back to the first rows when the filters, engine or list change.
	const scope = JSON.stringify([state.filters.map(serializeFilter), engine, kind]);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useIndexation(state, kind, PER_PAGE, offset);
	const answer = query.data;
	const rows = answer?.rows ?? [];

	return (
		<Card className="spst-card is-wide spst-section" size="small">
			<CardHeader className="spst-card__header">
				<h2 className="spst-card__title">{__('Indexation', 'seoprostats')}</h2>
				{answer && (
					<div className="spst-changes__filters spst-plan__filters">
						<SelectControl
							__nextHasNoMarginBottom
							label={__('Show', 'seoprostats')}
							value={kind}
							options={INDEXATION_KINDS.map((k) => ({ value: k, label: `${indexationName(k)} (${number(answer.counts[k] ?? 0)})` }))}
							onChange={(next: string) => update({ index: next === 'pages' ? undefined : (next as IndexationKind) })}
						/>
					</div>
				)}
			</CardHeader>
			<CardBody className="spst-card__body">
				{query.isError && (
					<Notice status="error" isDismissible={false} className="spst-notice">
						{errorMessage(query.error, __('The indexation list could not be loaded. Reload the page to try again.', 'seoprostats'))}
					</Notice>
				)}
				{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
				{answer && <p className="spst-note spst-opportunities__intro">{intro(kind, answer)}</p>}
				{answer && !rows.length && (
					<div className="spst-empty">
						<p>{empty(kind, answer)}</p>
					</div>
				)}
				{rows.length > 0 && <RowsTable rows={rows} kind={kind} open={open} refreshing={query.isFetching} google={answer?.inspections != null} />}
				{answer && <Notes answer={answer} />}
				{answer && answer.total > PER_PAGE && (
					<nav className="spst-changes__pager" aria-label={__('Pages of the indexation list', 'seoprostats')}>
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

/** What a list shows and what to do about it. */
function intro(kind: IndexationKind, answer: IndexationAnswer): string {
	if (kind === 'sitemap') {
		return sprintf(
			/* translators: %s: number of days. */
			__(
				'Addresses in the site’s own sitemaps besides its posts, such as category, tag and author archives, listed for %s days or more with no search impressions in that time. Make each worth showing, or leave it out of the sitemap. Never shown first.',
				'seoprostats'
			),
			number(answer.days)
		);
	}
	return sprintf(
		/* translators: %s: number of days. */
		__(
			'Published pages with no search impressions in the newest %s days of search data, though they were published before them. Check that search engines may index each, that related pages link to it and that it is in the sitemap, then ask them to crawl it. Never shown first, then the newest.',
			'seoprostats'
		),
		number(answer.days)
	);
}

function empty(kind: IndexationKind, answer: IndexationAnswer): string {
	if (!answer.through) {
		return __('There is no search data yet. Connect a search engine on the Connections tab.', 'seoprostats');
	}
	if (kind === 'sitemap') {
		if (answer.read.sitemap.read && !answer.read.sitemap.enabled) {
			return __('WordPress’s sitemaps are off, perhaps because an SEO plugin makes its own, so there are no other addresses to check.', 'seoprostats');
		}
		if (!answer.read.sitemap.read) {
			return __('The site’s sitemaps have not been read yet; they are read daily.', 'seoprostats');
		}
		return __('Search has shown every sitemap address listed long enough to tell.', 'seoprostats');
	}
	if (!answer.read.published) {
		return __('No page’s published time has been read yet. Pages are read when they are saved, and in a daily batch.', 'seoprostats');
	}
	return __('Search has shown every published page old enough to tell.', 'seoprostats');
}

/** Notes under the list: what was read, the window and the rules. */
function Notes({ answer }: { answer: IndexationAnswer }) {
	const notes: string[] = [];
	if (answer.through) {
		notes.push(
			sprintf(
				/* translators: 1: number of days, 2: the newest day with search data. */
				__('Impressions in the newest %1$s days of search data, through %2$s; the period chosen above does not apply.', 'seoprostats'),
				number(answer.days),
				longLabel(answer.through, 'day')
			)
		);
	}
	notes.push(
		answer.read.complete
			? sprintf(
					/* translators: %s: number of pages. */
					_n('Published time read on the %s published page.', 'Published times read on all %s published pages.', answer.read.pages, 'seoprostats'),
					number(answer.read.pages)
				)
			: sprintf(
					/* translators: 1: pages read, 2: published pages. */
					__('Published times read on %1$s of %2$s published pages so far; the rest are read in the daily batch.', 'seoprostats'),
					number(answer.read.published),
					number(answer.read.pages)
				)
	);
	const skipped = answer.skipped.noindex + answer.skipped.canonical;
	if (skipped) {
		notes.push(
			sprintf(
				/* translators: %s: number of pages. */
				_n(
					'%s page is left out: it asks search engines not to index it, or names another page as canonical.',
					'%s pages are left out: they ask search engines not to index them, or name another page as canonical.',
					skipped,
					'seoprostats'
				),
				number(skipped)
			)
		);
	}
	if (answer.inspections) {
		notes.push(
			sprintf(
				/* translators: %s: number of pages. */
				_n(
					'From the site’s own search data; Google’s column is its URL Inspection (%s page inspected so far, see Google’s index below).',
					'From the site’s own search data; Google’s column is its URL Inspection (%s pages inspected so far, see Google’s index below).',
					answer.inspections.inspected,
					'seoprostats'
				),
				number(answer.inspections.inspected)
			)
		);
	} else {
		notes.push(__('From the site’s own search data; search engines’ URL inspection is not used.', 'seoprostats'));
	}
	return (
		<div className="spst-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
		</div>
	);
}

function RowsTable({ rows, kind, open, refreshing, google }: { rows: IndexationRow[]; kind: IndexationKind; open: IndexationProps['open']; refreshing: boolean; google: boolean }) {
	return (
		<TableScroll label={indexationName(kind)}>
			<table className={`widefat striped spst-table spst-decay${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{kind === 'sitemap' ? __('Address', 'seoprostats') : __('Page', 'seoprostats')}</th>
						<th scope="col">{__('Search', 'seoprostats')}</th>
						{google && <th scope="col">{__('Google', 'seoprostats')}</th>}
						<th scope="col">{kind === 'sitemap' ? __('First listed', 'seoprostats') : __('Published', 'seoprostats')}</th>
						{kind === 'sitemap' ? (
							<th scope="col">{__('Kind', 'seoprostats')}</th>
						) : (
							<>
								<th scope="col" className="num">
									{__('Words', 'seoprostats')}
								</th>
								<th scope="col" className="num">
									{__('Links in', 'seoprostats')}
								</th>
							</>
						)}
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => {
						const since = kind === 'sitemap' ? row.first_seen : row.published;
						return (
							<tr key={row.path_id}>
								<td>
									<PageCell row={row} query="" open={open} />
								</td>
								<td>
									{row.state === 'never'
										? __('Never shown', 'seoprostats')
										: sprintf(
												/* translators: %s: the last day search showed the page. */
												__('Last shown %s', 'seoprostats'),
												row.last_impression ? longLabel(row.last_impression, 'day') : '–'
											)}
								</td>
								{google && (
									<td>
										<GoogleCell google={row.google} />
									</td>
								)}
								<td>
									{since ? longLabel(since, 'day') : '–'}
									<span className="spst-meta">
										{sprintf(
											/* translators: %s: number of days. */
											_n('%s day', '%s days', row.age, 'seoprostats'),
											number(row.age)
										)}
									</span>
								</td>
								{kind === 'sitemap' ? (
									<td>{sourceLabel(row.source)}</td>
								) : (
									<>
										<td className="num">{number(row.words ?? 0)}</td>
										<td className="num">{number(row.links_in ?? 0)}</td>
									</>
								)}
							</tr>
						);
					})}
				</tbody>
			</table>
		</TableScroll>
	);
}
