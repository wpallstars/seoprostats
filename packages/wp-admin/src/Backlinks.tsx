/**
 * Search → Backlinks: pages of other sites that link to the site's pages,
 * found without an outside service. Once a day the site opens the pages
 * that sent visits and reads their links to it (SEOProStats_Backlinks), so
 * a link from a site that never sent a visit is not here.
 *
 * Five lists: live links (newest first), the sites linking (most visits
 * first), the site's pages linked to (most sites first), the links lost
 * in the period, and the referring pages link exports named (Settings →
 * Import → Links) with their check; a reported page's link count opens
 * its links (the page linked to, text and rel). The period counts new and lost links
 * and the sites' visits; filters and the engine do not apply. Choosing one
 * of the site's pages opens it in Rankings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Fragment, useState } from 'react';
import { addQueryArgs } from '@wordpress/url';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl, TextareaControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { BACKLINK_SOURCES, formatNumber, type BacklinkDecision, type BacklinkDomainRow, type BacklinkKind, type BacklinkPageRow, type BacklinkReportedRow, type BacklinkReportedState, type BacklinkRow, type BacklinkSource, type BacklinksAnswer } from '@seoprostats/core';
import { checkBacklinks, downloadDisavow, errorMessage, mergeDisavow, saveBacklinkDecision, scopeKey, shareAccess, useBacklinkReview, useBacklinks } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
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
		reported: __('Reported pages', 'seoprostats'),
	};
	return names[kind];
}

/** A list's count, from the totals. */
function count(kind: BacklinkKind, totals: BacklinksAnswer['totals']): number {
	return totals[kind];
}

/** The tiles, which pick the list: sites first. */
const TILE_ORDER: readonly BacklinkKind[] = ['domains', 'links', 'pages', 'lost', 'reported'];

/** Under a tile's figure: what is new in the period. */
function tileFoot(kind: BacklinkKind, totals: BacklinksAnswer['totals']): string {
	if (kind === 'reported') {
		/* translators: %s: number of reported pages opened by the check. */
		return sprintf(__('%s checked', 'seoprostats'), number(totals.reported_checked));
	}
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
	// In the address, so the Import tab's history links straight to an export's results.
	const source: BacklinkSource | '' = state.found ?? '';
	const setSource = (value: string) => update({ found: (value || undefined) as BacklinkSource | undefined });
	const [review, setReview] = useState(false);
	// Back to the first rows when the period or list change.
	const scope = scopeKey(state.range, state.from, state.to, kind, source);
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
				{boot.canManage && !shareAccess.token && <Button variant="secondary" aria-pressed={review} onClick={() => setReview(!review)}>{review ? __('Back to links', 'seoprostats') : __('Review', 'seoprostats')}</Button>}
			</CardHeader>
			{review ? <CardBody><BacklinkReview state={state} /></CardBody> : <>
			<div className="spst-tiles" role="group" aria-label={__('Show', 'seoprostats')}>{/* NOSONAR: a group of buttons; a fieldset would bring its own border, padding and min-width. */}
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
						<p>{empty(shown, answer, source)}</p>
					</div>
				)}
				{rows.length > 0 && shown === 'domains' && <DomainsTable rows={rows as BacklinkDomainRow[]} refreshing={query.isFetching} />}
				{rows.length > 0 && shown === 'pages' && <PagesTable rows={rows as BacklinkPageRow[]} open={open} refreshing={query.isFetching} />}
				{rows.length > 0 && (shown === 'links' || shown === 'lost') && <LinksTable rows={rows as BacklinkRow[]} kind={shown} open={open} refreshing={query.isFetching} />}
				{rows.length > 0 && shown === 'reported' && <ReportedTable rows={rows as BacklinkReportedRow[]} open={open} refreshing={query.isFetching} />}
				{answer && <CheckNow answer={answer} />}
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
			</>}
		</Card>
	);
}

/** Owner-only local decisions. A score never selects disavow automatically. */
function BacklinkReview({ state }: Readonly<{ state: SearchReportProps['state'] }>) {
	const data = useDataSet();
	const scope = JSON.stringify([data, state.range, state.from, state.to]);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const query = useBacklinkReview(state, offset);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [notice, setNotice] = useState('');
	const [text, setText] = useState('');
	async function run(action: () => Promise<unknown>) {
		setBusy(true);
		setError('');
		setNotice('');
		try { await action(); } catch (caught) { setError(errorMessage(caught, __('The action could not finish.', 'seoprostats'))); } finally { setBusy(false); }
	}
	function decision(row: BacklinkDecision) {
		return <SelectControl label={`${row.scope === 'domain' ? __('Site decision', 'seoprostats') : __('URL decision', 'seoprostats')}: ${row.target}`} value={row.decision} disabled={busy || query.isFetching} options={[{ value: 'undecided', label: __('Undecided', 'seoprostats') }, { value: 'keep', label: __('Keep — never flag again', 'seoprostats') }, { value: 'disavow', label: __('Disavow in local file', 'seoprostats') }]} onChange={(value) => { void run(async () => { await saveBacklinkDecision({ ...row, decision: value as BacklinkDecision['decision'] }, data); setNotice(__('Decision saved locally. Nothing was submitted.', 'seoprostats')); }); }} help={row.reviewed ? `${row.imported ? __('From prior list', 'seoprostats') : __('Reviewed', 'seoprostats')} · ${new Date(row.reviewed * 1000).toLocaleString()} · ${__('User', 'seoprostats')} ${row.user_id}` : undefined} />;
	}
	return <div>
		<p>{__('Most sites never need to disavow links. Google recommends it only for many spammy or artificial links that caused, or are likely to cause, a manual action. These scores are review hints, not proof or a recommendation to disavow. Language and TLD alone are weak signals.', 'seoprostats')}</p>
		<p>{__('Download and upload manually in Search Console’s Disavow Links tool for the matching URL-prefix property, not a Domain property. Every upload replaces the old list: merge your current file first. Bing removed its disavow tool and API in October 2023. Nothing here is sent to any engine. A domain decision covers every URL of that domain; a URL keep cannot override a disavowed domain.', 'seoprostats')}</p>
		<TextareaControl label={__('Current disavow list (optional)', 'seoprostats')} value={text} onChange={setText} disabled={busy} help={__('UTF-8 .txt; at most 100,000 lines and 2 MB including export comments; URLs up to 2,048 characters.', 'seoprostats')} />
		<label>{__('Or load a text file', 'seoprostats')} <input type="file" accept=".txt,text/plain" disabled={busy} onChange={(event) => { const file = event.target.files?.[0]; if (file) { void run(async () => { if (file.size > 2097152) { throw new Error(__('The list exceeds 2 MB.', 'seoprostats')); } const buffer = await file.arrayBuffer(); setText(new TextDecoder('utf-8', { fatal: true }).decode(buffer)); }); } }} /></label>
		<p><Button variant="secondary" disabled={busy || !text.trim()} onClick={() => { void run(async () => { const result = await mergeDisavow(text, data); setNotice(sprintf(/* translators: %d: entries read from a prior file. */ __('%d prior entries read. Existing explicit decisions are kept.', 'seoprostats'), result.entries)); setText(''); }); }}>{__('Merge prior list locally', 'seoprostats')}</Button> <Button variant="secondary" disabled={busy || !!text.trim()} onClick={() => { void run(() => downloadDisavow(data)); }}>{__('Download disavow.txt', 'seoprostats')}</Button></p>
		{(error || query.isError) && <Notice status="error" isDismissible={false}>{error || errorMessage(query.error, __('Review could not load.', 'seoprostats'))}</Notice>}
		{notice && <Notice status="success" isDismissible={false}>{notice}</Notice>}
		<p className="spst-note">{__('Sites are ordered by score. Each signal contributes its displayed weight once per site, capped at 100. Missing facts add nothing. Live and lost links use the bounded backlink sample and selected period; previous decisions remain visible even outside that sample.', 'seoprostats')}</p>
		{query.data?.rows.map((site) => <section key={site.host}>
			<h3>{site.host} — {site.score}/100</h3>
			{decision(site.decision)}
			<ul>{site.reasons.map((reason) => <li key={reason.signal}>{reason.reason} (+{reason.weight})</li>)}</ul>
			<details><summary>{__('Links, anchors and URL decisions', 'seoprostats')} ({site.links.length})</summary>
				{site.links.map((link) => <div key={`${link.source} ${link.page}`}><p><SourceLink url={link.source} /> → {link.page} · {link.anchor || '–'} · {link.score}/100</p><ul>{link.reasons.map((reason) => <li key={reason.signal}>{reason.reason} (+{reason.weight})</li>)}</ul>{decision(link.decision)}</div>)}
				{site.url_decisions.filter((row) => !site.links.some((link) => link.source === row.target)).map((row) => <div key={row.target}>{decision(row)}</div>)}
			</details>
		</section>)}
		{query.data && !query.data.rows.length && <p>{__('No backlinks or prior decisions in this sample.', 'seoprostats')}</p>}
		<nav aria-label={__('Review pages', 'seoprostats')}><Button variant="secondary" disabled={busy || query.isFetching || offset === 0} onClick={() => setAt({ scope, offset: Math.max(0, offset - 25) })}>{__('Previous', 'seoprostats')}</Button> <Button variant="secondary" disabled={busy || query.isFetching || !query.data?.more} onClick={() => setAt({ scope, offset: offset + 25 })}>{__('Next', 'seoprostats')}</Button></nav>
	</div>;
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

function empty(kind: BacklinkKind, answer: BacklinksAnswer, source: string): string {
	if (kind === 'reported') {
		return source
			? __('No page an export of this source named.', 'seoprostats')
			: __('No link export imported yet: import one in Settings → Import → Links.', 'seoprostats');
	}
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
		return answer.read.next
			? __('No link to this site found yet; the pages a link export named are being opened, a batch a minute (see Reported pages).', 'seoprostats')
			: __('No link to this site found yet; the rest of the pages that sent visits are checked in the daily run.', 'seoprostats');
	}
	return __('The pages that sent visits do not link to this site (the visits came from a link elsewhere, or one the browser did not name).', 'seoprostats');
}

/** Notes under the list: what was checked, and how. */
function Notes({ answer }: Readonly<{ answer: BacklinksAnswer }>) {
	const notes: string[] = [
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
				: ''),
		sprintf(
			/* translators: 1: number of days, 2: number of checks. */
			__('Found from visits and imported link exports. While the check is on, referring pages are opened daily and each again every %1$s days. A link missing on %2$s checks in a row, or on a page that is gone, is lost. Exports are samples: missing rows never mean lost links.', 'seoprostats'),
			number(answer.rules.recheck_days),
			number(answer.rules.misses)
		),
	];
	if (answer.totals.reported) {
		notes.push(
			sprintf(
				/* translators: 1: pages link exports named, 2: their sites, 3: of those, the pages opened. */
				__('Link exports named %1$s referring pages on %2$s sites; %3$s opened so far. Search Console names the page linking, not the page it links to, so its links show here once their page is opened; links an export reported are dated from the export, not marked new.', 'seoprostats'),
				number(answer.totals.reported),
				number(answer.totals.reported_domains),
				number(answer.totals.reported_checked)
			)
		);
	}
	if (answer.read.next) {
		notes.push(
			sprintf(
				/* translators: %s: time of the next catch-up check. */
				__('Catching up: the next batch of reported pages opens at %s.', 'seoprostats'),
				new Date(answer.read.next).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' })
			)
		);
	}
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

/**
 * The owner's Check now (live data), and where link exports are imported.
 * The check opens pages for up to 20 seconds; the catch-up carries on.
 */
function CheckNow({ answer }: Readonly<{ answer: BacklinksAnswer }>) {
	const data = useDataSet();
	const [busy, setBusy] = useState(false);
	const [message, setMessage] = useState<{ status: 'success' | 'error'; text: string } | null>(null);
	if (!boot.canManage || shareAccess.token || data !== 'live') {
		return null;
	}
	const importUrl = boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'import' }) : '';
	async function check() {
		setBusy(true);
		setMessage(null);
		try {
			const done = await checkBacklinks();
			setMessage({
				status: 'success',
				text:
					sprintf(
						/* translators: 1: links found new, 2: links lost. */
						__('Checked: %1$s new links, %2$s lost.', 'seoprostats'),
						number(done.links_new),
						number(done.links_lost)
					) +
					(done.waiting
						? ' ' +
							sprintf(
								/* translators: %s: reported pages not opened yet. */
								_n('%s reported page waits; it opens in the background.', '%s reported pages wait; they open in the background, a batch a minute.', done.waiting, 'seoprostats'),
								number(done.waiting)
							)
						: ''),
			});
		} catch (caught) {
			setMessage({ status: 'error', text: errorMessage(caught, __('The check could not run.', 'seoprostats')) });
		} finally {
			setBusy(false);
		}
	}
	const pending = answer.totals.reported - answer.totals.reported_checked;
	return (
		<div className="spst-backlinks__actions">
			<p>
				<Button variant="secondary" isBusy={busy} disabled={busy} onClick={() => void check()}>
					{__('Check now', 'seoprostats')}
				</Button>{' '}
				{importUrl && <a href={importUrl}>{__('Import a link export', 'seoprostats')}</a>}
			</p>
			{!message && pending > 0 && !answer.read.enabled && (
				<Notice status="warning" isDismissible={false} className="spst-notice">
					{__('Reported pages are opened only while “Check pages that send visitors for links” is on in Settings → Data; Check now opens a batch once.', 'seoprostats')}
				</Notice>
			)}
			{message && (
				<Notice status={message.status} isDismissible={false} className="spst-notice">
					{message.text}
				</Notice>
			)}
		</div>
	);
}

/** A reported page's check, in words. */
function stateLabel(state: BacklinkReportedState, links: number): string {
	switch (state) {
		case 'links':
			/* translators: %s: links to this site on the page. */
			return sprintf(_n('%s link to this site', '%s links to this site', links, 'seoprostats'), number(links));
		case 'none':
			// The check saw no link, but links added by scripts or hidden from automated visits can still be there.
			return __('Visit the link to check for backlinks', 'seoprostats');
		case 'error':
			return __('Could not be opened; tried again later', 'seoprostats');
		case 'gone':
			return __('Gone', 'seoprostats');
		default:
			return __('Not checked yet', 'seoprostats');
	}
}

/**
 * The reported pages. A page with links to the site opens a row under it
 * with each link: the page of this site it links to, its text and rel.
 */
function ReportedTable({ rows, open, refreshing }: Readonly<{ rows: BacklinkReportedRow[]; open: BacklinksProps['open']; refreshing: boolean }>) {
	const [shown, setShown] = useState<string | null>(null);
	return (
		<TableScroll label={backlinkKindName('reported')}>
			<table className={`widefat striped spst-table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Linking page', 'seoprostats')}</th>
						<th scope="col">{__('Site', 'seoprostats')}</th>
						<th scope="col">{__('Check', 'seoprostats')}</th>
						<th scope="col">{__('Reported', 'seoprostats')}</th>
						<th scope="col">{__('Checked', 'seoprostats')}</th>
						<th scope="col">{__('Found by', 'seoprostats')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => {
						const targets = row.state === 'links' ? row.targets : [];
						const isShown = shown === row.source && targets.length > 0;
						return (
							<Fragment key={row.source}>
								<tr className={isShown ? 'is-selected' : ''}>
									<td>
										<SourceLink url={row.source} />
									</td>
									<td>{row.host}</td>
									<td>
										{targets.length > 0 ? (
											<button type="button" className="spst-link" aria-expanded={isShown} title={__('Show the links', 'seoprostats')} onClick={() => setShown(isShown ? null : row.source)}>
												{stateLabel(row.state, row.links)}
											</button>
										) : (
											stateLabel(row.state, row.links)
										)}
									</td>
									<td>{day(row.reported)}</td>
									<td>{day(row.checked)}</td>
									<td>{foundLabel(row.found)}</td>
								</tr>
								{isShown && (
									<tr className="spst-backlinks__detail">
										<td colSpan={6}>
											<ReportedTargets targets={targets} open={open} />
										</td>
									</tr>
								)}
							</Fragment>
						);
					})}
				</tbody>
			</table>
		</TableScroll>
	);
}

/** A reported page's links to the site. */
function ReportedTargets({ targets, open }: Readonly<{ targets: BacklinkReportedRow['targets']; open: BacklinksProps['open'] }>) {
	return (
		<table className="widefat spst-table">
			<thead>
				<tr>
					<th scope="col">{__('Links to', 'seoprostats')}</th>
					<th scope="col">{__('Text', 'seoprostats')}</th>
					<th scope="col">{__('Rel', 'seoprostats')}</th>
					<th scope="col">{__('First found', 'seoprostats')}</th>
				</tr>
			</thead>
			<tbody>
				{targets.map((target) => (
					<tr key={target.page}>
						<td>
							<PageButton page={target.page} open={open} />
						</td>
						<td>{target.anchor || '–'}</td>
						<td>{relLabel(target.rel)}</td>
						<td>{day(target.first_seen)}</td>
					</tr>
				))}
			</tbody>
		</table>
	);
}
