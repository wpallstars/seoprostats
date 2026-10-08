/**
 * Search → Audit: what WordPress says about each published page (its
 * title, description, headings, length, images, noindex and canonical
 * address, from the post and the SEO plugin's fields), with the pages
 * that have a finding listed by their search impressions, so the pages
 * that matter most come first. Facts are read when a post is saved and
 * in daily batches; each finding also goes to Plan, weighed by search
 * and conversions. Below it, internal links (./Links), read from the same
 * text, and indexation (./Indexation): pages search has not shown.
 *
 * Choosing a page opens it in Rankings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	apiArgs,
	AUDIT_FINDINGS,
	formatDecimal,
	formatNumber,
	formatPercent,
	type AuditAnswer,
	type AuditFinding,
	type AuditRow,
	singleEngine,
	type SearchEngine,
} from '@seoprostats/core';
import { errorMessage, useAudit } from './api';
import { locale } from './boot';
import { Indexation } from './Indexation';
import { Links } from './Links';
import { longLabel } from './dates';
import { PeriodLine } from './Overview';
import { PageCell } from './Opportunities';
import { SearchSetup, sourceName, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);
const percent = (value: number) => formatPercent(value, locale);
const place = (value: number | null) => (value === null ? '–' : formatDecimal(value, locale));

/** A finding's name, short enough for a list. */
export function findingName(finding: AuditFinding): string {
	const names: Record<AuditFinding, string> = {
		noindex: __('Not indexed', 'seoprostats'),
		canonical: __('Canonical is another page', 'seoprostats'),
		thin: __('Thin content', 'seoprostats'),
		title_missing: __('No title', 'seoprostats'),
		title_duplicate: __('Same title as other pages', 'seoprostats'),
		title_long: __('Title too long', 'seoprostats'),
		description_missing: __('No description', 'seoprostats'),
		description_duplicate: __('Same description as other pages', 'seoprostats'),
		description_long: __('Description too long', 'seoprostats'),
		h1_none: __('No H1', 'seoprostats'),
		h1_several: __('Several H1s', 'seoprostats'),
		images_alt: __('Images without alt text', 'seoprostats'),
	};
	return names[finding];
}

/** Which findings stop a page showing in search, rather than weaken it. */
const SERIOUS: readonly AuditFinding[] = ['noindex', 'canonical'];

/** The fact behind a finding on a page, in a few words. */
function findingDetail(finding: AuditFinding, row: AuditRow): string {
	const f = row.facts;
	switch (finding) {
		case 'thin':
			/* translators: %s: number of words. */
			return sprintf(_n('%s word, no clicks', '%s words, no clicks', f.words, 'seoprostats'), number(f.words));
		case 'title_long':
			/* translators: %s: number of characters. */
			return sprintf(_n('%s character', '%s characters', f.title_length, 'seoprostats'), number(f.title_length));
		case 'description_long':
			/* translators: %s: number of characters. */
			return sprintf(_n('%s character', '%s characters', f.description_length, 'seoprostats'), number(f.description_length));
		case 'h1_several':
			/* translators: %s: number of H1 headings. */
			return sprintf(__('%s in the text', 'seoprostats'), number(f.h1));
		case 'images_alt':
			/* translators: 1: images without alt text, 2: images. */
			return sprintf(__('%1$s of %2$s', 'seoprostats'), number(f.images_no_alt), number(f.images));
		case 'title_duplicate':
			return row.same_title.join(', ');
		case 'description_duplicate':
			return row.same_description.join(', ');
		default:
			return '';
	}
}

/** The SEO plugin the facts were read from. */
function pluginName(plugin: string): string {
	const names: Record<string, string> = {
		'rank-math': 'Rank Math',
		yoast: 'Yoast SEO',
		seopress: 'SEOPress',
		aioseo: 'All in One SEO',
		demo: __('the demo data', 'seoprostats'),
	};
	return names[plugin] ?? '';
}

type AuditProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Audit({ state, update, open, onEngines }: AuditProps) {
	const finding: AuditFinding | '' = state.finding ?? '';
	const engine: SearchEngine = singleEngine(state.engine);
	// Back to the first rows when the period, filters, engine or finding change.
	const scope = JSON.stringify([apiArgs({ ...state, compare: 'none' }), engine, finding]);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useAudit(state, finding, PER_PAGE, offset);
	const answer = query.data;
	useReportEngines(answer, onEngines);
	const rows = answer?.rows ?? [];

	return (
		<>
			{query.isError && (
				<Notice status="error" isDismissible={false} className="spst-notice">
					{errorMessage(query.error, __('The audit could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}
			{answer && <SearchSetup answer={answer} />}
			{answer && answer.ignored.length > 0 && (
				<Notice status="warning" isDismissible={false} className="spst-notice">
					{__('Search data has no visits, so only page filters apply here; the other filters are left out.', 'seoprostats')}
				</Notice>
			)}

			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<div>
						<h2 className="spst-card__title">{__('Content audit', 'seoprostats')}</h2>
						{answer && answer.through && answer.days > 0 && <PeriodLine range={answer.range} />}
						{answer?.through && (
							<p className="spst-meta">
								{sprintf(
									/* translators: 1: a source, e.g. "Google Search Console", 2: a day, e.g. "Sun 4 Oct 2026". */
									__('Search figures from %1$s, final days through %2$s', 'seoprostats'),
									sourceName(engine),
									longLabel(answer.through, 'day')
								)}
							</p>
						)}
					</div>
					{answer && (
						<div className="spst-changes__filters spst-plan__filters">
							<SelectControl
								__nextHasNoMarginBottom
								label={__('Show', 'seoprostats')}
								value={finding}
								options={[
									{ value: '', label: `${__('Every finding', 'seoprostats')} (${number(answer.pages)})` },
									...AUDIT_FINDINGS.map((f) => ({ value: f, label: `${findingName(f)} (${number(answer.counts[f] ?? 0)})` })),
								]}
								onChange={(next: string) => update({ finding: next ? (next as AuditFinding) : undefined })}
							/>
						</div>
					)}
				</CardHeader>
				<CardBody className="spst-card__body">
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && answer.checked.pages > 0 && (
						<p className="spst-note spst-opportunities__intro">
							{__(
								'What WordPress says about each published page: its title and description (from the SEO plugin, else the post), headings, length, images, and whether it asks not to be indexed or names another page as canonical. Pages with most search impressions come first, so the fixes that matter most are at the top; each finding is also in Plan, weighed by search and conversions.',
								'seoprostats'
							)}
						</p>
					)}
					{answer && !rows.length && (
						<div className="spst-empty">
							<p>
								{!answer.checked.pages
									? __('No page has been read yet. Pages are read when they are saved, and in a daily batch.', 'seoprostats')
									: finding
										? __('No page has this finding.', 'seoprostats')
										: __('No page read has a finding.', 'seoprostats')}
							</p>
						</div>
					)}
					{rows.length > 0 && <AuditTable rows={rows} open={open} refreshing={query.isFetching} />}
					{answer && answer.checked.pages > 0 && <Notes answer={answer} />}
					{answer && answer.total > PER_PAGE && (
						<nav className="spst-changes__pager" aria-label={__('Pages of the audit', 'seoprostats')}>
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

			<Links state={state} update={update} open={open} onEngines={onEngines} />

			<Indexation state={state} update={update} open={open} onEngines={onEngines} />
		</>
	);
}

/** Notes under the list: what was read and when, and the rules. */
function Notes({ answer }: { answer: AuditAnswer }) {
	const r = answer.rules;
	const notes: string[] = [];
	const plugin = pluginName(answer.plugin);
	notes.push(
		answer.checked.oldest && answer.checked.newest
			? sprintf(
					/* translators: 1: number of pages, 2: a day, 3: a day. */
					_n('%1$s page read, from %2$s to %3$s.', '%1$s pages read, from %2$s to %3$s.', answer.checked.pages, 'seoprostats'),
					number(answer.checked.pages),
					longLabel(answer.checked.oldest.slice(0, 10), 'day'),
					longLabel(answer.checked.newest.slice(0, 10), 'day')
				)
			: sprintf(
					/* translators: %s: number of pages. */
					_n('%s page read.', '%s pages read.', answer.checked.pages, 'seoprostats'),
					number(answer.checked.pages)
				)
	);
	notes.push(
		plugin
			? sprintf(/* translators: %s: an SEO plugin's name, e.g. "Yoast SEO". */ __('Titles, descriptions, noindex and canonical addresses are read from %s.', 'seoprostats'), plugin)
			: __('No SEO plugin was found, so titles and descriptions are the post’s own title and excerpt.', 'seoprostats')
	);
	notes.push(
		sprintf(
			/* translators: 1: number of pages, 2: number of days. */
			__('A page is read again when it is saved; others in a daily batch of %1$s, each at least every %2$s days.', 'seoprostats'),
			number(r.batch),
			number(r.stale_days)
		)
	);
	notes.push(
		sprintf(
			/* translators: 1: most characters of a title, 2: most characters of a description, 3: fewest words, 4: impressions. */
			__('Titles over %1$s characters and descriptions over %2$s are long. Thin content is under %3$s words with %4$s or more impressions and no clicks. Not indexed and canonical are listed only for pages that still show in search.', 'seoprostats'),
			number(r.title_max),
			number(r.description_max),
			number(r.thin_words),
			number(r.thin_impressions)
		)
	);
	if (answer.cut) {
		notes.push(
			sprintf(
				/* translators: %s: number of days. */
				_n('Search figures from the newest %s day of the period.', 'Search figures from the newest %s days of the period.', answer.days, 'seoprostats'),
				number(answer.days)
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

function AuditTable({ rows, open, refreshing }: { rows: AuditRow[]; open: AuditProps['open']; refreshing: boolean }) {
	return (
		<TableScroll label={__('Content audit', 'seoprostats')}>
			<table className={`widefat striped spst-table spst-decay spst-audit${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoprostats')}</th>
						<th scope="col" className="spst-audit__findings">
							{__('Findings', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Impressions', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Clicks', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('CTR', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Position', 'seoprostats')}
						</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.path_id}>
							<td>
								<PageCell
									row={row}
									query=""
									open={open}
									extra={sprintf(
										/* translators: %s: number of words. */
										_n('%s word', '%s words', row.facts.words, 'seoprostats'),
										number(row.facts.words)
									)}
								/>
							</td>
							<td>
								<ul className="spst-decay__list">
									{row.findings.map((f) => {
										const detail = findingDetail(f, row);
										return (
											<li key={f}>
												<strong className={`spst-cause${SERIOUS.includes(f) ? ' is-gone' : ''}`}>{findingName(f)}</strong>
												{detail && <span className="spst-meta">{detail}</span>}
											</li>
										);
									})}
								</ul>
							</td>
							<td className="num">{number(row.impressions)}</td>
							<td className="num">{number(row.clicks)}</td>
							<td className="num">{row.impressions ? percent(row.ctr) : '–'}</td>
							<td className="num">{row.impressions ? place(row.position) : '–'}</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}
