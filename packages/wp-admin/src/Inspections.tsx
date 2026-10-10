/**
 * Search → Audit, Google's index: Search Console's sitemaps as Google read
 * them (errors, warnings, last download), and Google's URL Inspection of
 * the site's pages (the version in Google's index: its verdict, reason in
 * Google's words, last crawl and canonical), newest first. Inspections run
 * in the hourly import, within a daily cap set in Settings → Data; each
 * page again after a fortnight. Google only.
 *
 * Choosing a page opens it in Rankings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, ExternalLink, Notice, SelectControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	formatNumber,
	INSPECTION_VERDICTS,
	serializeFilter,
	type InspectionGoogle,
	type InspectionRow,
	type InspectionsAnswer,
	type InspectionVerdict,
	type SearchSitemap,
	type SitemapProblem,
} from '@seoprostats/core';
import { errorMessage, scopeKey, useInspections } from './api';
import { locale } from './boot';
import { findingName } from './Audit';
import { longLabel } from './dates';
import { PageCell } from './Opportunities';
import type { SearchPick, SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);
const day = (iso: string | null) => (iso ? longLabel(iso.slice(0, 10), 'day') : '–');

/** A verdict's name. */
export function verdictName(verdict: InspectionVerdict): string {
	const names: Record<InspectionVerdict, string> = {
		PASS: __('Indexed', 'seoprostats'),
		PARTIAL: __('Indexed, with warnings', 'seoprostats'),
		FAIL: __('Error', 'seoprostats'),
		NEUTRAL: __('Not indexed', 'seoprostats'),
	};
	return names[verdict];
}

/** A sitemap problem's name. */
function problemName(problem: SitemapProblem): string {
	const names: Record<SitemapProblem, string> = {
		errors: __('Errors', 'seoprostats'),
		stale: __('Not downloaded lately', 'seoprostats'),
		warnings: __('Warnings', 'seoprostats'),
	};
	return names[problem];
}

/** Google's view of a page in a few words: its reason and last crawl (Indexation, Inspections). */
export function GoogleCell({ google }: Readonly<{ google: InspectionGoogle | null }>) {
	if (!google) {
		return <span className="spst-muted">{__('Not inspected yet', 'seoprostats')}</span>;
	}
	if (google.error) {
		return <span className="spst-muted">{google.error}</span>;
	}
	return (
		<>
			<strong className={`spst-cause${google.verdict === 'PASS' ? '' : ' is-gone'}`}>{google.coverage ?? (google.verdict ? verdictName(google.verdict) : '–')}</strong>
			<span className="spst-meta">
				{google.last_crawl
					? sprintf(/* translators: %s: a day. */ __('Crawled %s', 'seoprostats'), day(google.last_crawl))
					: __('Not crawled', 'seoprostats')}
				{' · '}
				{sprintf(/* translators: %s: a day. */ __('inspected %s', 'seoprostats'), day(google.checked))}
			</span>
		</>
	);
}

type InspectionsProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Inspections({ state, open }: Readonly<InspectionsProps>) {
	const [verdict, setVerdict] = useState<InspectionVerdict | ''>('');
	// Back to the first rows when the filters or verdict change.
	const scope = scopeKey(state.filters.map(serializeFilter), verdict);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useInspections(state, verdict, PER_PAGE, offset);
	const answer = query.data;
	const rows = answer?.rows ?? [];

	return (
		<Card className="spst-card is-wide spst-section" size="small">
			<CardHeader className="spst-card__header">
				<h2 className="spst-card__title">{__('Google’s index', 'seoprostats')}</h2>
				{answer && answer.inspected > 0 && (
					<div className="spst-changes__filters spst-plan__filters">
						<SelectControl
							__nextHasNoMarginBottom
							label={__('Show', 'seoprostats')}
							value={verdict}
							options={[
								{ value: '', label: `${__('Every page inspected', 'seoprostats')} (${number(answer.inspected)})` },
								...INSPECTION_VERDICTS.filter((v) => v !== 'PARTIAL' || answer.counts.PARTIAL > 0).map((v) => ({
									value: v,
									label: `${verdictName(v)} (${number(answer.counts[v] ?? 0)})`,
								})),
							]}
							onChange={(next: string) => setVerdict(next as InspectionVerdict | '')}
						/>
					</div>
				)}
			</CardHeader>
			<CardBody className="spst-card__body">
				{query.isError && (
					<Notice status="error" isDismissible={false} className="spst-notice">
						{errorMessage(query.error, __('Google’s index could not be loaded. Reload the page to try again.', 'seoprostats'))}
					</Notice>
				)}
				{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
				{answer && !answer.connected && (
					<div className="spst-empty">
						<p>{__('Connect Google Search Console on the Connections tab to see its sitemaps and how Google indexed each page.', 'seoprostats')}</p>
					</div>
				)}
				{answer?.connected && (
					<>
						<Sitemaps answer={answer} />
						<h3 className="spst-subtitle">{__('Pages inspected', 'seoprostats')}</h3>
						<p className="spst-note spst-opportunities__intro">
							{__(
								'Google’s URL Inspection of each page: whether the version in Google’s index is indexed, Google’s reason in its words, when it last crawled the page, and the canonical it chose. Problems are also Audit findings and Plan items. Newest first.',
								'seoprostats'
							)}
						</p>
						{answer.progress.error && (
							<Notice status="warning" isDismissible={false} className="spst-notice">
								{sprintf(
									/* translators: 1: a day, 2: Google's message. */
									__('Google stopped the inspections on %1$s: %2$s', 'seoprostats'),
									day(answer.progress.error_at),
									answer.progress.error
								)}
							</Notice>
						)}
						{!rows.length && (
							<div className="spst-empty">
								<p>{emptyText(answer)}</p>
							</div>
						)}
						{rows.length > 0 && <PagesTable rows={rows} open={open} refreshing={query.isFetching} />}
						<Notes answer={answer} />
						{answer.total > PER_PAGE && (
							<nav className="spst-changes__pager" aria-label={__('Pages of the inspections', 'seoprostats')}>
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
					</>
				)}
			</CardBody>
		</Card>
	);
}

/** Search Console's sitemaps for the property, as Google read them. */
function Sitemaps({ answer }: Readonly<{ answer: InspectionsAnswer }>) {
	const s = answer.sitemaps;
	return (
		<>
			<h3 className="spst-subtitle">{__('Sitemaps in Search Console', 'seoprostats')}</h3>
			{s.error && (
				<Notice status="warning" isDismissible={false} className="spst-notice">
					{sprintf(/* translators: %s: Google's message. */ __('Search Console’s sitemaps could not be read: %s', 'seoprostats'), s.error)}
				</Notice>
			)}
			{!s.submitted && s.own && (
				<Notice status="warning" isDismissible={false} className="spst-notice">
					{sprintf(
						/* translators: %s: the site's sitemap address. */
						__('The site’s sitemap %s is not submitted in Search Console, so Google may find new pages late. Submit it under Sitemaps in Search Console.', 'seoprostats'),
						s.own
					)}
				</Notice>
			)}
			{!s.read && !s.error && (
				<div className="spst-empty">
					<p>{__('Search Console’s sitemaps have not been read yet; they are read in the daily import.', 'seoprostats')}</p>
				</div>
			)}
			{s.read && !s.rows.length && !s.error && (
				<div className="spst-empty">
					<p>{__('No sitemap is submitted for the property.', 'seoprostats')}</p>
				</div>
			)}
			{s.rows.length > 0 && <SitemapsTable rows={s.rows} />}
			{s.read && (
				<p className="spst-meta">
					{sprintf(
						/* translators: 1: a day, 2: number of days. */
						__('Read from Search Console on %1$s. A sitemap Google has not downloaded for over %2$s days is listed as not downloaded lately.', 'seoprostats'),
						day(s.read),
						number(s.stale_days)
					)}
				</p>
			)}
		</>
	);
}

function SitemapsTable({ rows }: Readonly<{ rows: SearchSitemap[] }>) {
	return (
		<TableScroll label={__('Sitemaps in Search Console', 'seoprostats')}>
			<table className="widefat striped spst-table spst-decay">
				<thead>
					<tr>
						<th scope="col">{__('Sitemap', 'seoprostats')}</th>
						<th scope="col">{__('Submitted', 'seoprostats')}</th>
						<th scope="col">{__('Last read by Google', 'seoprostats')}</th>
						<th scope="col" className="num">
							{__('Addresses', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Errors', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Warnings', 'seoprostats')}
						</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.path}>
							<td>
								{/^https?:\/\//.test(row.path) ? <ExternalLink href={row.path}>{row.path}</ExternalLink> : row.path}
								<span className="spst-meta">
									{[row.index ? __('Sitemap index', 'seoprostats') : '', row.pending ? __('Pending', 'seoprostats') : '', ...row.problems.map(problemName)].filter(Boolean).join(' · ')}
								</span>
							</td>
							<td>{day(row.submitted)}</td>
							<td>{day(row.downloaded)}</td>
							<td className="num">{row.contents.length ? number(row.contents.reduce((sum, c) => sum + c.submitted, 0)) : '–'}</td>
							<td className="num">{row.errors ? <strong className="spst-cause is-gone">{number(row.errors)}</strong> : number(0)}</td>
							<td className="num">{number(row.warnings)}</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

/** With no rows: no match, none inspected yet, or inspections off. */
function emptyText(answer: InspectionsAnswer): string {
	if (answer.inspected) {
		return __('No page inspected has this verdict.', 'seoprostats');
	}
	return answer.progress.daily
		? __('No page has been inspected yet. Pages are inspected in the hourly import, within the daily cap.', 'seoprostats')
		: __('Inspections are off. Set how many pages to inspect a day in Settings → Data.', 'seoprostats');
}

/** Notes under the list: the daily cap, today's use and the last run. */
function Notes({ answer }: Readonly<{ answer: InspectionsAnswer }>) {
	const p = answer.progress;
	const r = answer.rules;
	const notes: string[] = [
		sprintf(
			/* translators: 1: pages inspected, 2: inspections today, 3: daily cap. */
			_n(
				'%1$s page inspected. Today %2$s of %3$s inspections a day (Google’s day, Pacific time).',
				'%1$s pages inspected. Today %2$s of %3$s inspections a day (Google’s day, Pacific time).',
				answer.inspected,
				'seoprostats'
			),
			number(answer.inspected),
			number(p.used),
			number(p.daily)
		),
		sprintf(
			/* translators: 1: number of days, 2: most inspections a day. */
			__('Pages are inspected in the hourly import: first those Indexation lists, then those with most search impressions, each again after %1$s days. The cap is set in Settings → Data, up to %2$s a day (Google’s own limit).', 'seoprostats'),
			number(r.recheck_days),
			number(r.max_daily)
		),
	];
	if (p.last) {
		/* translators: %s: a day. */
		notes.push(sprintf(__('Last run %s.', 'seoprostats'), day(p.last)));
	}
	if (answer.ignored.length > 0) {
		notes.push(__('Only page filters apply here; the other filters are left out.', 'seoprostats'));
	}
	return (
		<div className="spst-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
		</div>
	);
}

function PagesTable({ rows, open, refreshing }: Readonly<{ rows: InspectionRow[]; open: InspectionsProps['open']; refreshing: boolean }>) {
	return (
		<TableScroll label={__('Pages inspected', 'seoprostats')}>
			<table className={`widefat striped spst-table spst-decay${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoprostats')}</th>
						<th scope="col">{__('Google', 'seoprostats')}</th>
						<th scope="col">{__('Findings', 'seoprostats')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.path_id}>
							<td>
								<PageCell row={row} query="" open={open} />
							</td>
							<td>
								<GoogleCell google={row} />
								{row.link?.startsWith('https://') && (
									<span className="spst-meta">
										<ExternalLink href={row.link}>{__('Open in Search Console', 'seoprostats')}</ExternalLink>
									</span>
								)}
							</td>
							<td>
								{row.findings.length ? (
									<ul className="spst-decay__list">
										{row.findings.map((f) => (
											<li key={f}>
												<strong className={`spst-cause${f === 'rich_errors' ? '' : ' is-gone'}`}>{findingName(f)}</strong>
												{f === 'google_canonical' && row.google_canonical && <span className="spst-meta">{row.google_canonical}</span>}
											</li>
										))}
									</ul>
								) : (
									<span className="spst-muted">{__('None', 'seoprostats')}</span>
								)}
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}
