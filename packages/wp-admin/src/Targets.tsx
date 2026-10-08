/**
 * Search → Targets: the searches the site chose to win and the page meant
 * for each, with how search treats them now: the query's position, clicks
 * and impressions on any page, the page search shows most for it, and a
 * state (the page meant for it ranks, another page does, none is chosen
 * yet, or search does not show it). Highest priority first.
 *
 * Administrators import a list (CSV or tab-separated text, JSON, or the
 * aidevops search targets table) and delete targets; rows that cannot be
 * read are skipped and listed, never guessed. Plan lists open targets
 * shown with the wrong page, and high-priority ones in striking distance.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, CheckboxControl, Notice, SelectControl, TextareaControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	apiArgs,
	formatDecimal,
	formatNumber,
	TARGET_FILTERS,
	type SearchEngine,
	type TargetFilter,
	type TargetRow,
	type TargetsAnswer,
	type TargetsImportAnswer,
	type TargetState,
	type TargetStatus,
} from '@seoprostats/core';
import { deleteTargets, errorMessage, importTargets, useTargets } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import { longLabel } from './dates';
import { PeriodLine } from './Overview';
import { PageCell } from './Opportunities';
import { SearchSetup, sourceName, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 50;

const number = (value: number) => formatNumber(value, locale, false);
const decimal = (value: number) => formatDecimal(value, locale);

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

export function Targets({ state, update, open, onEngines }: TargetsProps) {
	const status: TargetFilter = state.targets ?? 'all';
	const engine: SearchEngine = state.engine ?? 'google';
	// Back to the first rows when the period, engine or status change.
	const scope = JSON.stringify([apiArgs({ ...state, filters: [] }), engine, status]);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useTargets(state, status, PER_PAGE, offset);
	const answer = query.data;
	useReportEngines(answer, onEngines);
	const rows = answer?.rows ?? [];
	const [error, setError] = useState('');
	const [importing, setImporting] = useState(false);
	const empty = !!answer && filterCount(answer, 'all') === 0;

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
						{answer && answer.through && answer.days > 0 && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
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
						{answer && !empty && (
							<SelectControl
								__nextHasNoMarginBottom
								label={__('Show', 'seoprostats')}
								value={status}
								options={TARGET_FILTERS.map((f) => ({ value: f, label: `${filterName(f)} (${number(filterCount(answer, f))})` }))}
								onChange={(next: string) => update({ targets: next === 'all' ? undefined : (next as TargetFilter) })}
							/>
						)}
						{boot.canManage && !importing && (
							<Button variant="secondary" onClick={() => setImporting(true)}>
								{__('Import targets', 'seoprostats')}
							</Button>
						)}
					</div>
				</CardHeader>
				<CardBody className="spst-card__body">
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
					{answer && !rows.length && (
						<div className="spst-empty">
							<p>
								{empty
									? boot.canManage
										? __('No search targets yet. Import a list of searches with the page meant for each: CSV, tab-separated text, JSON, or the aidevops search targets table.', 'seoprostats')
										: __('No search targets yet. An administrator can import them.', 'seoprostats')
									: __('No target in this status.', 'seoprostats')}
							</p>
						</div>
					)}
					{rows.length > 0 && <RowsTable rows={rows} compared={!!answer?.compare} open={open} refreshing={query.isFetching} onError={setError} />}
					{answer && !empty && <Notes answer={answer} />}
					{answer && answer.total > PER_PAGE && (
						<nav className="spst-changes__pager" aria-label={__('Pages of the search targets', 'seoprostats')}>
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
		</>
	);
}

/** Notes under the list: the counts by state, the period read and the queue's rules. */
function Notes({ answer }: { answer: TargetsAnswer }) {
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

function RowsTable({ rows, compared, open, refreshing, onError }: RowsTableProps) {
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
							</td>
							<td className="num">{number(row.priority)}</td>
							<td>{targetStatusName(row.status)}</td>
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
										{row.shown.share !== null && ` · ${sprintf(/* translators: %s: share of the impressions, e.g. 80%. */ __('%s of impressions', 'seoprostats'), `${number(Math.round(row.shown.share * 100))}%`)}`}
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

/** Import a list as text: the result, with the rows skipped and why. */
function ImportForm({ onClose }: { onClose: () => void }) {
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
			{done && (
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
			)}
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
