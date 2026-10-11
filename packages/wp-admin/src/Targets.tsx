/**
 * Search → Targets: the searches the site chose to win and the page meant
 * for each, with how search treats them now: the query's position, clicks
 * and impressions on any page, the page search shows most for it, and a
 * state (the page meant for it ranks, another page does, none is chosen
 * yet, or search does not show it). Highest priority first.
 *
 * Administrators add targets three ways: the SEO plugin's focus keywords
 * (Suggest from SEO plugin), Add as target on search report rows, or a
 * pasted list (CSV or tab-separated text, JSON, or the aidevops search
 * targets table); and delete them. Rows that cannot be read are skipped
 * and listed, never guessed. Plan lists open targets
 * shown with the wrong page, and high-priority ones in striking distance.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, CheckboxControl, Notice, SelectControl, TextareaControl, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	allintitleQuery,
	apiArgs,
	formatDecimal,
	formatNumber,
	researchUrl,
	TARGET_FILTERS,
	singleEngine,
	type SearchEngine,
	type TargetFilter,
	type TargetRow,
	type TargetsAnswer,
	type TargetsImportAnswer,
	type TargetState,
	type TargetStatus,
	type TargetSuggestion,
	type TargetSuggestionsAnswer,
	type TargetSuggestionState,
} from '@seoprostats/core';
import { deleteTargets, errorMessage, importTargets, importTargetSuggestions, scopeKey, useTargets, useTargetSuggestions } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import { longLabel } from './dates';
import { PeriodLine } from './Overview';
import { PageCell } from './Opportunities';
import { SearchSetup, sourceName, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';
import { ResearchMenu } from './components/ResearchMenu';

const PER_PAGE = 50;

const number = (value: number) => formatNumber(value, locale, false);
const decimal = (value: number) => formatDecimal(value, locale);
/** A share (0–1) as a whole percentage, e.g. "80%". */
const percentText = (share: number) => `${number(Math.round(share * 100))}%`;

/** With no rows: none in the status picked, or none yet for someone who cannot add them (administrators see Sources). */
function emptyText(empty: boolean): string {
	return empty ? __('No search targets yet. An administrator can add them from the SEO plugin\'s focus keywords, the search reports or a keyword list.', 'seoprostats') : __('No target in this status.', 'seoprostats');
}

/** Where targets come from, for administrators with none yet: each way in, with its button. */
function Sources({ suggest, paste }: Readonly<{ suggest: () => void; paste: () => void }>) {
	return (
		<div className="spst-empty spst-targets__sources">
			<p>
				<strong>{__('No search targets yet.', 'seoprostats')}</strong>{' '}
				{__('A target is a search the site means to win, with the page meant for it. There are three ways to add them:', 'seoprostats')}
			</p>
			<ul>
				<li>
					<strong>{__('From your SEO plugin:', 'seoprostats')}</strong>{' '}
					{__('the focus keyword each published page has in Rank Math, Yoast SEO, SEOPress or All in One SEO, with that page. You choose which to import.', 'seoprostats')}{' '}
					<Button variant="link" onClick={suggest}>
						{__('Suggest from SEO plugin', 'seoprostats')}
					</Button>
				</li>
				<li>
					<strong>{__('From the search reports:', 'seoprostats')}</strong>{' '}
					{__('Add as target after a search in Rankings, or in Opportunities → Striking distance and Overlapping pages, adds it as a candidate: in Striking distance with the page that ranks, in Rankings with the page picked (if any), and in Overlapping pages with none chosen, so you choose which page it is for.', 'seoprostats')}
				</li>
				<li>
					<strong>{__('From keyword research:', 'seoprostats')}</strong>{' '}
					{__('paste a list from a spreadsheet or a keyword tool (CSV, tab-separated or JSON), or the aidevops search targets table.', 'seoprostats')}{' '}
					<Button variant="link" onClick={paste}>
						{__('Import targets', 'seoprostats')}
					</Button>
				</li>
			</ul>
		</div>
	);
}

/** A target status's name. */
export function targetStatusName(status: TargetStatus): string {
	const names: Record<TargetStatus, string> = {
		candidate: __('Candidate', 'seoprostats'),
		targeted: __('Targeted', 'seoprostats'),
		live: __('Live', 'seoprostats'),
		won: __('Won', 'seoprostats'),
		retired: __('Retired', 'seoprostats'),
	};
	return names[status];
}

function filterName(filter: TargetFilter): string {
	if (filter === 'all') {
		return __('All targets', 'seoprostats');
	}
	if (filter === 'open') {
		return __('Open (candidate, targeted, live)', 'seoprostats');
	}
	return targetStatusName(filter);
}

function filterCount(answer: TargetsAnswer, filter: TargetFilter): number {
	const s = answer.statuses;
	if (filter === 'all') {
		return s.candidate + s.targeted + s.live + s.won + s.retired;
	}
	if (filter === 'open') {
		return s.candidate + s.targeted + s.live;
	}
	return s[filter];
}

/** How search treats a target, in a few words. */
function stateName(state: TargetState): string {
	const names: Record<TargetState, string> = {
		ranking: __('Ranking with its page', 'seoprostats'),
		wrong_page: __('Another page ranks', 'seoprostats'),
		no_page: __('No page chosen', 'seoprostats'),
		not_shown: __('Not shown', 'seoprostats'),
	};
	return names[state];
}

type TargetsProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Targets({ state, update, open, onEngines }: Readonly<TargetsProps>) {
	const status: TargetFilter = state.targets ?? 'all';
	const engine: SearchEngine = singleEngine(state.engine);
	// Back to the first rows when the period, engine or status change.
	const scope = scopeKey(apiArgs({ ...state, filters: [] }), engine, status);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	// Targets are bounded to 1,000; sort/filter the complete list before paging.
	const query = useTargets(state, status, 1000, 0);
	const answer = query.data;
	useReportEngines(answer, onEngines);
	const [kgrFilter, setKgrFilter] = useState('all');
	const [sortKgr, setSortKgr] = useState(false);
	const list = (answer?.rows ?? []).filter((row) => kgrFilter === 'all' || row.kgr_band === kgrFilter);
	if (sortKgr) list.sort((a, b) => (a.kgr ?? Infinity) - (b.kgr ?? Infinity) || a.query.localeCompare(b.query));
	const rows = list.slice(offset, offset + PER_PAGE);
	const [error, setError] = useState('');
	const [importing, setImporting] = useState(false);
	const [suggesting, setSuggesting] = useState(false);
	const empty = !!answer && filterCount(answer, 'all') === 0;
	const sources = empty && boot.canManage && !importing && !suggesting;

	return (
		<>
			{(query.isError || error) && (
				<Notice status="error" isDismissible={!!error} onRemove={() => setError('')} className="spst-notice">
					{error || errorMessage(query.error, __('The search targets could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}
			{answer && <SearchSetup answer={answer} />}

			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<div>
						<h2 className="spst-card__title">{__('Search targets', 'seoprostats')}</h2>
						{answer?.through && answer.days > 0 && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
						{answer?.through && (
							<p className="spst-meta">
								{sprintf(
									/* translators: 1: a source, e.g. "Google Search Console", 2: a day, e.g. "Sun 4 Oct 2026". */
									__('From %1$s, final days through %2$s', 'seoprostats'),
									sourceName(engine),
									longLabel(answer.through, 'day')
								)}
							</p>
						)}
					</div>
					<div className="spst-changes__filters spst-plan__filters">
						<SelectControl __nextHasNoMarginBottom label={__('KGR band', 'seoprostats')} value={kgrFilter} options={['all', 'good', 'possible', 'crowded', 'volume_too_high', 'unknown'].map((value) => ({ value, label: value === 'all' ? __('All', 'seoprostats') : kgrName(value) }))} onChange={(next: string) => { setKgrFilter(next); setOffset(0); }} />
						<CheckboxControl __nextHasNoMarginBottom label={__('Lowest KGR first', 'seoprostats')} checked={sortKgr} onChange={(next) => { setSortKgr(next); setOffset(0); }} />
						{answer && !empty && (
							<SelectControl
								__nextHasNoMarginBottom
								label={__('Show', 'seoprostats')}
								value={status}
								options={TARGET_FILTERS.map((f) => ({ value: f, label: `${filterName(f)} (${number(filterCount(answer, f))})` }))}
								onChange={(next: string) => update({ targets: next === 'all' ? undefined : (next as TargetFilter) })}
							/>
						)}
						{boot.canManage && !suggesting && (
							<Button variant="secondary" onClick={() => { setSuggesting(true); setImporting(false); }}>
								{__('Suggest from SEO plugin', 'seoprostats')}
							</Button>
						)}
						{boot.canManage && !importing && (
							<Button variant="secondary" onClick={() => { setImporting(true); setSuggesting(false); }}>
								{__('Import targets', 'seoprostats')}
							</Button>
						)}
					</div>
				</CardHeader>
				<CardBody className="spst-card__body">
					{suggesting && <SuggestForm onClose={() => setSuggesting(false)} />}
					{importing && <ImportForm onClose={() => setImporting(false)} />}
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && (
						<p className="spst-note spst-opportunities__intro">
							{__(
								'The searches the site chose to win and the page meant for each, highest priority first. Each shows its position, clicks and impressions on any page, and the page search shows most for it. Plan lists open targets where another page ranks, and high-priority ones in striking distance.',
								'seoprostats'
							)}
						</p>
					)}
					{sources && <Sources suggest={() => setSuggesting(true)} paste={() => setImporting(true)} />}
					{answer && !rows.length && !(empty && boot.canManage) && (
						<div className="spst-empty">
							<p>{emptyText(empty)}</p>
						</div>
					)}
					{rows.length > 0 && <RowsTable rows={rows} compared={!!answer?.compare} open={open} refreshing={query.isFetching} onError={setError} />}
					{answer && !empty && <Notes answer={answer} />}
					{answer && list.length > PER_PAGE && (
						<nav className="spst-changes__pager" aria-label={__('Pages of the search targets', 'seoprostats')}>
							<span className="spst-muted">
								{sprintf(
									/* translators: 1: first row shown, 2: last row shown, 3: number of rows. */
									__('%1$s–%2$s of %3$s', 'seoprostats'),
									number(offset + 1),
									number(Math.min(offset + PER_PAGE, list.length)),
									number(list.length)
								)}
							</span>
							<Button variant="secondary" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PER_PAGE))}>
								{__('Previous', 'seoprostats')}
							</Button>
							<Button variant="secondary" disabled={offset + PER_PAGE >= list.length} onClick={() => setOffset(offset + PER_PAGE)}>
								{__('Next', 'seoprostats')}
							</Button>
						</nav>
					)}
				</CardBody>
			</Card>
		</>
	);
}

/** Notes under the list: the counts by state, the period read and the queue's rules. */
function Notes({ answer }: Readonly<{ answer: TargetsAnswer }>) {
	const notes: string[] = [];
	const c = answer.counts;
	notes.push(
		sprintf(
			/* translators: 1: targets ranking with their page, 2: with another page, 3: with no page chosen, 4: not shown. */
			__('Ranking with their page: %1$s. Another page ranks: %2$s. No page chosen: %3$s. Not shown: %4$s.', 'seoprostats'),
			number(c.ranking),
			number(c.wrong_page),
			number(c.no_page),
			number(c.not_shown)
		)
	);
	if (answer.cut) {
		notes.push(
			sprintf(
				/* translators: %s: number of days. */
				_n('Read from the newest %s day of the period.', 'Read from the newest %s days of the period.', answer.days, 'seoprostats'),
				number(answer.days)
			)
		);
	}
	notes.push(
		sprintf(
			/* translators: 1: least priority, 2: first position, 3: last position. */
			__('Plan lists a target with priority %1$s or more ranking %2$s–%3$s with its page (or none chosen), and any open target where another page ranks.', 'seoprostats'),
			number(answer.rules.high_priority),
			number(answer.rules.striking_from),
			number(answer.rules.striking_to)
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

interface RowsTableProps {
	rows: TargetRow[];
	compared: boolean;
	open: TargetsProps['open'];
	refreshing: boolean;
	onError: (message: string) => void;
}

function RowsTable({ rows, compared, open, refreshing, onError }: Readonly<RowsTableProps>) {
	const data = useDataSet();
	const [busy, setBusy] = useState('');
	const remove = async (row: TargetRow) => {
		// eslint-disable-next-line no-alert -- a plain confirmation, as WordPress uses.
		if (!window.confirm(sprintf(/* translators: %s: a search query. */ __('Delete the target “%s”? Its search data stays.', 'seoprostats'), row.query))) {
			return;
		}
		setBusy(row.query);
		try {
			await deleteTargets(data, [row.query]);
			onError('');
		} catch (e) {
			onError(errorMessage(e, __('It could not be deleted. Try again.', 'seoprostats')));
		}
		setBusy('');
	};
	return (
		<TableScroll label={__('Search targets', 'seoprostats')}>
			<table className={`widefat striped spst-table spst-targets${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Search', 'seoprostats')}</th>
						<th scope="col" className="num">
							{__('Priority', 'seoprostats')}
						</th>
						<th scope="col">{__('Status', 'seoprostats')}</th>
						<th scope="col">{__('Allintitle results', 'seoprostats')}</th>
						<th scope="col">{__('Monthly volume', 'seoprostats')}</th>
						<th scope="col">{__('KGR', 'seoprostats')}</th>
						<th scope="col">{__('Page meant for it', 'seoprostats')}</th>
						<th scope="col">{__('Search shows', 'seoprostats')}</th>
						<th scope="col" className="num">
							{__('Position', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Clicks', 'seoprostats')}
						</th>
						<th scope="col" className="num">
							{__('Impressions', 'seoprostats')}
						</th>
						{boot.canManage && <th scope="col">{__('Actions', 'seoprostats')}</th>}
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.query}>
							<td>
								<button type="button" className="spst-link" title={__('Open in Rankings', 'seoprostats')} onClick={() => open({ page: '', query: row.query })}>
									{row.query}
								</button>
								<ResearchMenu query={row.query} />
							</td>
							<td className="num">{number(row.priority)}</td>
							<td>{targetStatusName(row.status)}</td>
							<td>
								{row.allintitle === null ? '–' : number(row.allintitle)}
								<span className="spst-meta">{row.measured.allintitle ?? __('Not measured', 'seoprostats')}</span>
								<a href={researchUrl('google', allintitleQuery(row.query))} target="_blank" rel="noopener noreferrer">{__('Check allintitle (new tab)', 'seoprostats')}</a>
							</td>
							<td>{row.volume === null ? '–' : number(row.volume)}<span className="spst-meta">{row.measured.volume ?? __('Not measured', 'seoprostats')}</span></td>
							<td>{row.kgr === null ? '–' : decimal(row.kgr)}<span className="spst-meta">{kgrName(row.kgr_band)}</span></td>
							<td>
								{row.page ? (
									<PageCell
										row={row.page}
										query=""
										open={open}
										extra={row.page.position !== null && row.state === 'wrong_page' ? sprintf(/* translators: %s: average position. */ __('position %s', 'seoprostats'), decimal(row.page.position)) : undefined}
									/>
								) : (
									<span className="spst-muted">{__('None chosen', 'seoprostats')}</span>
								)}
							</td>
							<td>
								<span className={`spst-badge spst-targets__state is-${row.state}`}>{stateName(row.state)}</span>
								{row.shown && row.state !== 'ranking' && (
									<span className="spst-meta">
										<button type="button" className="spst-link" title={__('Open in Rankings', 'seoprostats')} onClick={() => open({ page: row.shown?.path ?? '', query: row.query })}>
											{row.shown.path}
										</button>
										{row.shown.share !== null && ` · ${sprintf(/* translators: %s: share of the impressions, e.g. 80%. */ __('%s of impressions', 'seoprostats'), percentText(row.shown.share))}`}
									</span>
								)}
							</td>
							<td className="num">
								{row.position === null ? '–' : decimal(row.position)}
								{compared && row.then_position !== null && (
									<span className="spst-meta">{sprintf(/* translators: %s: average position before. */ __('was %s', 'seoprostats'), decimal(row.then_position))}</span>
								)}
							</td>
							<td className="num">
								{number(row.clicks)}
								{compared && row.then_clicks !== null && <span className="spst-meta">{sprintf(/* translators: %s: clicks before. */ __('was %s', 'seoprostats'), number(row.then_clicks))}</span>}
							</td>
							<td className="num">{number(row.impressions)}</td>
							{boot.canManage && (
								<td>
									<MeasurementForm row={row} onError={onError} />
									<Button variant="tertiary" size="small" isDestructive disabled={busy === row.query} onClick={() => void remove(row)}>
										{__('Delete', 'seoprostats')}
									</Button>
								</td>
							)}
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

function kgrName(band: string): string {
	const names: Record<string, string> = {
		good: __('Good (under 0.25)', 'seoprostats'), possible: __('May work (0.25–1)', 'seoprostats'), crowded: __('Crowded (over 1)', 'seoprostats'),
		volume_too_high: __('Volume too high for KGR', 'seoprostats'), unknown: __('Not enough data for KGR', 'seoprostats'),
	};
	return names[band] ?? band;
}

function MeasurementForm({ row, onError }: { row: TargetRow; onError: (message: string) => void }) {
	const data = useDataSet();
	const [editing, setEditing] = useState(false);
	const [count, setCount] = useState(String(row.allintitle ?? ''));
	const [volume, setVolume] = useState(String(row.volume ?? ''));
	const [countDate, setCountDate] = useState('');
	const [volumeDate, setVolumeDate] = useState('');
	const [busy, setBusy] = useState(false);
	const save = async () => {
		setBusy(true);
		try {
			const fields: Record<string, unknown> = { query: row.query, page: row.page?.path ?? '', priority: row.priority, status: row.status };
			if (count !== String(row.allintitle ?? '') || countDate) { fields.allintitle = count === '' ? null : count; if (countDate) fields.allintitle_measured = countDate; }
			if (volume !== String(row.volume ?? '') || volumeDate) { fields.volume = volume === '' ? null : volume; if (volumeDate) fields.volume_measured = volumeDate; }
			const done = await importTargets(data, JSON.stringify([fields]), false);
			const [skipped] = done.skipped;
			if (skipped) throw new Error(skipped.message);
			onError(''); setEditing(false);
		} catch (error) { onError(errorMessage(error, __('Measurements could not be saved.', 'seoprostats'))); }
		setBusy(false);
	};
	if (!editing) return <Button variant="tertiary" size="small" onClick={() => { setCount(String(row.allintitle ?? '')); setVolume(String(row.volume ?? '')); setCountDate(''); setVolumeDate(''); setEditing(true); }}>{__('Edit research', 'seoprostats')}</Button>;
	return <div>
		<TextControl __nextHasNoMarginBottom label={__('Allintitle results', 'seoprostats')} type="number" min={0} step={1} value={count} onChange={setCount} />
		<TextControl __nextHasNoMarginBottom label={__('Count measured on', 'seoprostats')} type="date" value={countDate} onChange={setCountDate} help={__('Today when changed without a date', 'seoprostats')} />
		<TextControl __nextHasNoMarginBottom label={__('Monthly volume', 'seoprostats')} type="number" min={0} step={1} value={volume} onChange={setVolume} />
		<TextControl __nextHasNoMarginBottom label={__('Volume measured on', 'seoprostats')} type="date" value={volumeDate} onChange={setVolumeDate} />
		<Button variant="secondary" disabled={busy} isBusy={busy} onClick={() => void save()}>{__('Save research', 'seoprostats')}</Button>
		<Button variant="tertiary" disabled={busy} onClick={() => setEditing(false)}>{__('Cancel', 'seoprostats')}</Button>
	</div>;
}

/** Import a list as text: the result, with the rows skipped and why. */
function ImportForm({ onClose }: Readonly<{ onClose: () => void }>) {
	const data = useDataSet();
	const [text, setText] = useState('');
	const [replace, setReplace] = useState(false);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [done, setDone] = useState<TargetsImportAnswer | null>(null);
	const send = async () => {
		setBusy(true);
		setError('');
		try {
			setDone(await importTargets(data, text, replace));
			setText('');
		} catch (e) {
			setError(errorMessage(e, __('The list could not be imported. Try again.', 'seoprostats')));
		}
		setBusy(false);
	};
	return (
		<div className="spst-targets__import">
			{error && (
				<Notice status="error" isDismissible={false} className="spst-notice">
					{error}
				</Notice>
			)}
			{done && <ImportDone done={done} />}
			<p className="spst-note">
				{__('Paste searches from keyword research: a spreadsheet, a keyword tool\'s export, or the aidevops search targets table. For focus keywords already set on your pages, use Suggest from SEO plugin instead.', 'seoprostats')}
			</p>
			<TextareaControl
				__nextHasNoMarginBottom
				label={__('Targets', 'seoprostats')}
				help={__('One search a row: query, page (a path such as /pricing/ or an address on this site; empty for none chosen yet), priority (0–100, or high, medium, low) and status (candidate, targeted, live, won or retired), as CSV or tab-separated text with or without a header row. JSON and the aidevops search targets table (TOON) work too. Searches already listed are updated.', 'seoprostats')}
				value={text}
				rows={8}
				onChange={setText}
			/>
			<CheckboxControl __nextHasNoMarginBottom label={__('Delete the targets that are not in this list', 'seoprostats')} checked={replace} onChange={setReplace} />
			<div className="spst-plan__actions">
				<Button variant="primary" disabled={busy || !text.trim()} isBusy={busy} onClick={() => void send()}>
					{__('Import', 'seoprostats')}
				</Button>
				<Button variant="tertiary" disabled={busy} onClick={onClose}>
					{__('Close', 'seoprostats')}
				</Button>
			</div>
		</div>
	);
}

/** An import's result: the counts, and the rows skipped and why. */
export function ImportDone({ done }: Readonly<{ done: TargetsImportAnswer }>) {
	return (
		<Notice status={done.skipped.length ? 'warning' : 'success'} isDismissible={false} className="spst-notice">
			<p>
				{sprintf(
					/* translators: 1: targets added, 2: updated, 3: deleted, 4: rows skipped, 5: targets now. */
					__('Added %1$s, updated %2$s, deleted %3$s, skipped %4$s; %5$s targets now.', 'seoprostats'),
					number(done.added),
					number(done.updated),
					number(done.removed),
					number(done.skipped.length),
					number(done.total)
				)}
			</p>
			{done.skipped.length > 0 && (
				<ul>
					{done.skipped.slice(0, 20).map((skip) => (
						<li key={skip.row}>
							{sprintf(
								/* translators: 1: row number, 2: a search query, 3: why it was skipped. */
								__('Row %1$s (%2$s): %3$s', 'seoprostats'),
								number(skip.row),
								skip.query || '–',
								skip.message
							)}
						</li>
					))}
				</ul>
			)}
		</Notice>
	);
}

/** The SEO plugin's name, as the suggestions answer names it. */
function pluginName(plugin: string): string {
	const names: Record<string, string> = {
		'rank-math': 'Rank Math',
		yoast: 'Yoast SEO',
		seopress: 'SEOPress',
		aioseo: 'All in One SEO',
		demo: __('Demo pages', 'seoprostats'),
	};
	return names[plugin] ?? __('No SEO plugin active', 'seoprostats');
}

/** A suggestion's state, in a few words. */
function suggestionStateName(state: TargetSuggestionState): string {
	const names: Record<TargetSuggestionState, string> = {
		new: __('New', 'seoprostats'),
		clash: __('More than one page', 'seoprostats'),
		targeted: __('Already a target', 'seoprostats'),
	};
	return names[state];
}

/** What the suggestions read: the SEO plugin, its pages and each state's count. */
function SuggestSummary({ answer }: Readonly<{ answer: TargetSuggestionsAnswer }>) {
	return (
		<p className="spst-meta">
			{sprintf(
				/* translators: 1: an SEO plugin, e.g. "Yoast SEO", 2: pages with focus keywords, 3: new, 4: on more than one page, 5: already targets. */
				__('%1$s: %2$s pages with focus keywords. New: %3$s. More than one page: %4$s. Already targets: %5$s.', 'seoprostats'),
				pluginName(answer.plugin),
				number(answer.pages),
				number(answer.counts.new),
				number(answer.counts.clash),
				number(answer.counts.targeted)
			)}
			{answer.more && ` ${sprintf(/* translators: %s: number of posts. */ __('Only the first %s posts with focus keywords are read.', 'seoprostats'), number(answer.max_posts))}`}
		</p>
	);
}

/** No focus keywords to suggest, and what to do about it. */
function SuggestEmpty({ plugin }: Readonly<{ plugin: string }>) {
	return (
		<div className="spst-empty">
			<p>
				{plugin === ''
					? __('No SEO plugin is active, and no page has a focus keyword. With Rank Math, Yoast SEO, SEOPress or All in One SEO, set a focus keyword on each page, then look again; or add targets from the search reports or a keyword list.', 'seoprostats')
					: __('No published page has a focus keyword yet. Set one on each page in the SEO plugin, then look again; or add targets from the search reports or a keyword list.', 'seoprostats')}
			</p>
		</div>
	);
}

/** One focus keyword: its tick if new, its pages with Edit, and its state. */
function SuggestRow({ row, checked, onToggle }: Readonly<{ row: TargetSuggestion; checked: boolean; onToggle: (on: boolean) => void }>) {
	return (
		<tr>
			<td>
				{row.state === 'new' && (
					// WordPress's list-table check box: the search beside it names it, so the label is for screen readers.
					<input
						type="checkbox"
						aria-label={sprintf(/* translators: %s: a search query. */ __('Import “%s”', 'seoprostats'), row.query)}
						checked={checked}
						onChange={(event) => onToggle(event.target.checked)}
					/>
				)}
			</td>
			<td>{row.query}</td>
			<td>
				{row.pages.map((page) => (
					<span key={page.path} className="spst-targets__suggest-page">
						<a href={page.url} target="_blank" rel="noopener noreferrer" title={page.title}>
							{page.path}
						</a>
						{page.edit_url && (
							<>
								{' · '}
								<a href={page.edit_url} target="_blank" rel="noopener noreferrer">
									{__('Edit', 'seoprostats')}
								</a>
							</>
						)}
					</span>
				))}
			</td>
			<td>
				<span className={`spst-badge spst-targets__suggestion is-${row.state}`}>{suggestionStateName(row.state)}</span>
				{row.target && (
					<span className="spst-meta">
						{targetStatusName(row.target.status)}
						{row.target.page ? ` · ${row.target.page}` : ''}
					</span>
				)}
			</td>
		</tr>
	);
}

/**
 * The SEO plugin's focus keywords as targets: the new ones to tick and
 * import (as targeted, priority 50, with their page), those of more than
 * one page to choose for by hand, and those already targets, left alone.
 */
function SuggestForm({ onClose }: Readonly<{ onClose: () => void }>) {
	const data = useDataSet();
	const [allKeywords, setAllKeywords] = useState(false);
	const query = useTargetSuggestions(true, allKeywords);
	const answer = query.data;
	const [picked, setPicked] = useState<Set<string> | null>(null);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [done, setDone] = useState<TargetsImportAnswer | null>(null);
	const fresh = (answer?.rows ?? []).filter((row) => row.state === 'new');
	// Every new suggestion is ticked until someone changes the ticks.
	const chosen = picked ?? new Set(fresh.map((row) => row.query));
	const toggle = (q: string, on: boolean) => {
		const next = new Set(chosen);
		if (on) next.add(q);
		else next.delete(q);
		setPicked(next);
	};
	const send = async () => {
		setBusy(true);
		setError('');
		try {
			const queries = fresh.filter((row) => chosen.has(row.query)).map((row) => row.query);
			setDone(await importTargetSuggestions(data, queries, allKeywords));
			setPicked(null);
		} catch (e) {
			setError(errorMessage(e, __('The focus keywords could not be imported. Try again.', 'seoprostats')));
		}
		setBusy(false);
	};
	const count = fresh.filter((row) => chosen.has(row.query)).length;
	return (
		<div className="spst-targets__import spst-targets__suggest">
			{(error || query.isError) && (
				<Notice status="error" isDismissible={false} className="spst-notice">
					{error || errorMessage(query.error, __('The focus keywords could not be read. Try again.', 'seoprostats'))}
				</Notice>
			)}
			{done && <ImportDone done={done} />}
			<p className="spst-note">
				{__('The focus keyword each published page has in its SEO plugin, with that page as the page meant for it. Ticked ones are imported as targeted, priority 50; searches that are targets already are left as they are. A keyword set on more than one page is not imported: choose its page and add it with Import targets.', 'seoprostats')}
			</p>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={__('Include each page\'s other focus keywords, not only its main one', 'seoprostats')}
				checked={allKeywords}
				onChange={(next) => { setAllKeywords(next); setPicked(null); setDone(null); }}
			/>
			{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
			{answer && <SuggestSummary answer={answer} />}
			{answer && !answer.rows.length && <SuggestEmpty plugin={answer.plugin} />}
			{answer && answer.rows.length > 0 && (
				<TableScroll label={__('Focus keywords', 'seoprostats')}>
					<table className={`widefat striped spst-table${query.isFetching ? ' is-refreshing' : ''}`}>
						<thead>
							<tr>
								<th scope="col">
									{fresh.length > 0 && (
										<CheckboxControl
											__nextHasNoMarginBottom
											label={__('Import', 'seoprostats')}
											checked={count === fresh.length}
											indeterminate={count > 0 && count < fresh.length}
											onChange={(on) => setPicked(new Set(on ? fresh.map((row) => row.query) : []))}
										/>
									)}
								</th>
								<th scope="col">{__('Focus keyword', 'seoprostats')}</th>
								<th scope="col">{__('Page', 'seoprostats')}</th>
								<th scope="col">{__('State', 'seoprostats')}</th>
							</tr>
						</thead>
						<tbody>
							{answer.rows.map((row) => (
								<SuggestRow key={row.query} row={row} checked={chosen.has(row.query)} onToggle={(on) => toggle(row.query, on)} />
							))}
						</tbody>
					</table>
				</TableScroll>
			)}
			<div className="spst-plan__actions">
				<Button variant="primary" disabled={busy || count === 0} isBusy={busy} onClick={() => void send()}>
					{sprintf(/* translators: %s: number of focus keywords. */ _n('Import %s focus keyword', 'Import %s focus keywords', count, 'seoprostats'), number(count))}
				</Button>
				<Button variant="tertiary" disabled={busy} onClick={onClose}>
					{__('Close', 'seoprostats')}
				</Button>
			</div>
		</div>
	);
}
