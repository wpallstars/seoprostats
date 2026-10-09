/**
 * Search → Backlinks: pages of other sites that link to the site's pages,
 * found without an outside service. Once a day the site opens the pages
 * that sent visits and reads their links to it (SEOProStats_Backlinks), so
 * a link from a site that never sent a visit is not here.
 *
 * Four lists: live links (newest first), the sites linking (most visits
 * first), the site's pages linked to (most sites first) and the links lost
 * in the period. The period counts new and lost links and the sites'
 * visits; filters and the engine do not apply. Choosing one of the site's
 * pages opens it in Rankings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { BACKLINK_SOURCES, formatNumber, type BacklinkDomainRow, type BacklinkKind, type BacklinkPageRow, type BacklinkRow, type BacklinksAnswer } from '@seoprostats/core';
import { errorMessage, useBacklinks } from './api';
import { locale } from './boot';
import { longLabel } from './dates';
import type { SearchPick, SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);

/** A list's name. */
export function backlinkKindName(kind: BacklinkKind): string {
	const names: Record<BacklinkKind, string> = {
		links: __('Links', 'seoprostats'),
		domains: __('Sites linking', 'seoprostats'),
		pages: __('Pages linked to', 'seoprostats'),
		lost: __('Lost links', 'seoprostats'),
	};
	return names[kind];
}

/** A list's count, from the totals. */
function count(kind: BacklinkKind, totals: BacklinksAnswer['totals']): number {
	return kind === 'domains' ? totals.domains : kind === 'pages' ? totals.pages : kind === 'lost' ? totals.lost : totals.links;
}

/** The tiles, which pick the list: sites first. */
const TILE_ORDER: readonly BacklinkKind[] = ['domains', 'links', 'pages', 'lost'];

/** Under a tile's figure: what is new in the period. */
function tileFoot(kind: BacklinkKind, totals: BacklinksAnswer['totals']): string {
	if (kind === 'domains') {
		/* translators: %s: number of sites first linking in the period. */
		return sprintf(__('%s new in the period', 'seoprostats'), number(totals.new_domains));
	}
	if (kind === 'links') {
		/* translators: %s: number of links first found in the period. */
		return sprintf(__('%s new in the period', 'seoprostats'), number(totals.new));
	}
	if (kind === 'lost') {
		return __('In the period', 'seoprostats');
	}
	return __('Of this site', 'seoprostats');
}

/** An ISO time as a day, or –. */
function day(iso: string | null | undefined): string {
	return iso ? longLabel(iso.slice(0, 10), 'day') : '–';
}

type BacklinksProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Backlinks({ state, update, open }: Readonly<BacklinksProps>) {
	const kind: BacklinkKind = state.backlinks ?? 'links';
	const [source, setSource] = useState<(typeof BACKLINK_SOURCES)[number] | ''>('');
	// Back to the first rows when the period or list change.
	const scope = JSON.stringify([state.range, state.from, state.to, kind, source]);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useBacklinks(state, kind, PER_PAGE, offset, source);
	const answer = query.data;
	const rows = answer?.rows ?? [];
	// While another list loads, the last answer stays: its rows are drawn as its own kind.
	const shown: BacklinkKind = answer?.kind ?? kind;

	return (
		<Card className="spst-card is-wide spst-section" size="small">
			<CardHeader className="spst-card__header">
				<h2 className="spst-card__title">{__('Backlinks', 'seoprostats')}</h2>
			</CardHeader>
			<div className="spst-tiles" role="group" aria-label={__('Show', 'seoprostats')}>
				{TILE_ORDER.map((k) => (
					<button
						key={k}
						type="button"
						className={`spst-tile${kind === k ? ' is-selected' : ''}`}
						aria-pressed={kind === k}
						onClick={() => update({ backlinks: k === 'links' ? undefined : k })}
					>
						<span className="spst-tile__label">{backlinkKindName(k)}</span>
						<span className="spst-tile__value">{answer ? number(count(k, answer.totals)) : '–'}</span>
						<span className="spst-tile__foot">
							<span className="spst-muted">{answer ? tileFoot(k, answer.totals) : ''}</span>
						</span>
					</button>
				))}
			</div>
			<CardBody className="spst-card__body">
				<SelectControl label={__('How found', 'seoprostats')} value={source} onChange={setSource} options={[{ label: __('All sources', 'seoprostats'), value: '' }, ...BACKLINK_SOURCES.map((value) => ({ value, label: foundLabel([value]) }))]} />
				{query.isError && (
					<Notice status="error" isDismissible={false} className="spst-notice">
						{errorMessage(query.error, __('The backlinks could not be loaded. Reload the page to try again.', 'seoprostats'))}
					</Notice>
				)}
				{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
				{answer && <p className="spst-note spst-opportunities__intro">{intro(answer)}</p>}
				{answer && !rows.length && (
					<div className="spst-empty">
						<p>{empty(shown, answer)}</p>
					</div>
				)}
				{rows.length > 0 && shown === 'domains' && <DomainsTable rows={rows as BacklinkDomainRow[]} refreshing={query.isFetching} />}
				{rows.length > 0 && shown === 'pages' && <PagesTable rows={rows as BacklinkPageRow[]} open={open} refreshing={query.isFetching} />}
				{rows.length > 0 && (shown === 'links' || shown === 'lost') && <LinksTable rows={rows as BacklinkRow[]} kind={shown} open={open} refreshing={query.isFetching} />}
				{answer && <Notes answer={answer} />}
				{answer && answer.total > PER_PAGE && (
					<nav className="spst-changes__pager" aria-label={__('Pages of the backlinks list', 'seoprostats')}>
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

/** The totals in a sentence. */
function intro(answer: BacklinksAnswer): string {
	const t = answer.totals;
	return sprintf(
		/* translators: 1: live links, 2: sites linking, 3: pages of this site linked to, 4: new links in the period, 5: lost links in the period. */
		__('%1$s links from %2$s sites to %3$s of this site’s pages. In the period: %4$s new, %5$s lost.', 'seoprostats'),
		number(t.links),
		number(t.domains),
		number(t.pages),
		number(t.new),
		number(t.lost)
	);
}

function empty(kind: BacklinkKind, answer: BacklinksAnswer): string {
	if (!answer.read.enabled) {
		return __('The check is off: turn on “Check pages that send visitors for links” in Settings → Data.', 'seoprostats');
	}
	if (!answer.read.pages) {
		return __('No other site has sent a visit from a page that could be checked yet. Pages that send visits are checked once a day.', 'seoprostats');
	}
	if (kind === 'lost') {
		return __('No link was lost in the period.', 'seoprostats');
	}
	if (answer.read.checked < answer.read.pages) {
		return __('No link to this site found yet; the rest of the pages that sent visits are checked in the daily run.', 'seoprostats');
	}
	return __('The pages that sent visits do not link to this site (the visits came from a link elsewhere, or one the browser did not name).', 'seoprostats');
}

/** Notes under the list: what was checked, and how. */
function Notes({ answer }: Readonly<{ answer: BacklinksAnswer }>) {
	const notes: string[] = [];
	notes.push(
		sprintf(
			/* translators: 1: pages checked, 2: pages known. */
			_n('%1$s of %2$s page that sent visits checked.', '%1$s of %2$s pages that sent visits checked.', answer.read.pages, 'seoprostats'),
			number(answer.read.checked),
			number(answer.read.pages)
		) +
			(answer.read.last
				? ' ' +
					sprintf(
						/* translators: %s: day of the last check. */
						__('Last run %s.', 'seoprostats'),
						day(answer.read.last)
					)
				: '')
	);
	notes.push(
		sprintf(
			/* translators: 1: number of days, 2: number of checks. */
			__('Found from visits and imported link exports. While the check is on, referring pages are opened daily and each again every %1$s days. A link missing on %2$s checks in a row, or on a page that is gone, is lost. Exports are samples: missing rows never mean lost links.', 'seoprostats'),
			number(answer.rules.recheck_days),
			number(answer.rules.misses)
		)
	);
	return (
		<div className="spst-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
		</div>
	);
}

/** rel words, or followed. */
function relLabel(rel: BacklinkRow['rel']): string {
	return rel.length ? rel.join(', ') : __('followed', 'seoprostats');
}

/** How a link was found. */
function foundLabel(found: string[]): string {
	const names: Record<string, string> = { referrer: __('A visit', 'seoprostats'), dataforseo: 'DataForSEO', gsc: 'Search Console export', ahrefs: 'Ahrefs export', semrush: 'Semrush export', majestic: 'Majestic export', moz: 'Moz export', bing: 'Bing export', generic: __('CSV export', 'seoprostats'), verified: __('Page check', 'seoprostats') };
	return found.map((how) => names[how] ?? how).join(', ') || '–';
}

/** The site's page: opens in Rankings. */
function PageButton({ page, open }: Readonly<{ page: string; open: BacklinksProps['open'] }>) {
	return (
		<button type="button" className="spst-link" title={__('Open in Rankings', 'seoprostats')} onClick={() => open({ page, query: '' })}>
			{page}
		</button>
	);
}

/** The other site's page, in a new tab, never followed from here. */
function SourceLink({ url }: Readonly<{ url: string }>) {
	return (
		<a href={url} target="_blank" rel="noopener noreferrer nofollow" className="spst-link">
			{url.replace(/^https:\/\//, '')}
			<span className="screen-reader-text">{__('(opens in a new tab)', 'seoprostats')}</span>
		</a>
	);
}

function LinksTable({ rows, kind, open, refreshing }: Readonly<{ rows: BacklinkRow[]; kind: BacklinkKind; open: BacklinksProps['open']; refreshing: boolean }>) {
	return (
		<TableScroll label={backlinkKindName(kind)}>
			<table className={`widefat striped spst-table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Linking page', 'seoprostats')}</th>
						<th scope="col">{__('Links to', 'seoprostats')}</th>
						<th scope="col">{__('Text', 'seoprostats')}</th>
						<th scope="col">{__('Rel', 'seoprostats')}</th>
						<th scope="col">{__('First found', 'seoprostats')}</th>
						<th scope="col">{kind === 'lost' ? __('Lost', 'seoprostats') : __('Last found', 'seoprostats')}</th>
						<th scope="col">{__('Found by', 'seoprostats')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={`${row.source} ${row.page}`}>
							<td>
								<SourceLink url={row.source} />
								{row.new && kind === 'links' && <span className="spst-meta">{__('New in the period', 'seoprostats')}</span>}
							</td>
							<td>
								<PageButton page={row.page} open={open} />
							</td>
							<td>{row.anchor || '–'}</td>
							<td>{relLabel(row.rel)}</td>
							<td>{day(row.first_seen)}</td>
							<td>{day(kind === 'lost' ? row.lost : row.last_seen)}</td>
							<td>{foundLabel(row.found)}{Object.entries(row.providers ?? {}).map(([provider, facts]) => <span className="spst-meta" key={provider}>{foundLabel([provider])}: {facts.authority ?? '–'} · {day(facts.last_seen)}</span>)}</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

function DomainsTable({ rows, refreshing }: Readonly<{ rows: BacklinkDomainRow[]; refreshing: boolean }>) {
	return (
		<TableScroll label={backlinkKindName('domains')}>
			<table className={`widefat striped spst-table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Site', 'seoprostats')}</th>
						<th scope="col" className="num">
							{__('Links', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Followed', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Pages linked to', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('New', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Lost', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Visits', 'seoprostats')}
						</th>
						<th scope="col">{__('First found', 'seoprostats')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.host}>
							<td>{row.host}</td>
							<td className="num">{number(row.links)}</td>
							<td className="num">{number(row.followed)}</td>
							<td className="num">{number(row.pages)}</td>
							<td className="num">{number(row.new)}</td>
							<td className="num">{number(row.lost)}</td>
							<td className="num">{number(row.visits)}</td>
							<td>{day(row.first_seen)}</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

function PagesTable({ rows, open, refreshing }: Readonly<{ rows: BacklinkPageRow[]; open: BacklinksProps['open']; refreshing: boolean }>) {
	return (
		<TableScroll label={backlinkKindName('pages')}>
			<table className={`widefat striped spst-table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoprostats')}</th>
						<th scope="col" className="num">
							{__('Sites linking', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Links', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('New', 'seoprostats')}
						</th>
						<th scope="col">{__('First found', 'seoprostats')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.page}>
							<td>
								<PageButton page={row.page} open={open} />
							</td>
							<td className="num">{number(row.domains)}</td>
							<td className="num">{number(row.links)}</td>
							<td className="num">{number(row.new)}</td>
							<td>{day(row.first_seen)}</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}
