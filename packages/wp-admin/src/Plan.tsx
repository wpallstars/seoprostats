/**
 * Search → Plan: one ranked list of what to do next, made from what
 * Opportunities finds (low CTR, missing from the page, striking distance,
 * losing clicks, overlapping pages) and the content audit's findings
 * (./Audit). Each item says why it is listed, what to do, and how
 * its score is made:
 *
 *     potential clicks per 28 days × value × confidence ÷ effort
 *
 * Administrators accept an item, mark it done (which opens an experiment
 * on its page, so the change is measured), dismiss it (hidden for 90
 * days), restore it, or set its effort and a note. Pages with a running
 * experiment get no new items: a second change would spoil the
 * measurement. Done items show their experiment's result.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Fragment, useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	apiArgs,
	formatChange,
	formatDecimal,
	formatNumber,
	formatPlaces,
	QUEUE_FILTERS,
	type QueueAction,
	type QueueAnswer,
	type QueueFilter,
	type QueueItem,
	type QueueKind,
	type QueueStatus,
	type SearchEngine,
} from '@seoprostats/core';
import { errorMessage, updateQueueItem, useQueue } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import { longLabel } from './dates';
import { PeriodLine } from './Overview';
import { PageCell } from './Opportunities';
import { findingName } from './Audit';
import { metricLabel, resultLabel } from './Experiments';
import { SearchSetup, sourceName, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);
const decimal = (value: number) => formatDecimal(value, locale);

export function kindName(kind: QueueKind): string {
	const names: Record<QueueKind, string> = {
		ctr: __('Low CTR', 'seoprostats'),
		missing: __('Missing from the page', 'seoprostats'),
		striking: __('Striking distance', 'seoprostats'),
		decay: __('Losing clicks', 'seoprostats'),
		overlap: __('Overlapping pages', 'seoprostats'),
		audit: __('Content audit', 'seoprostats'),
	};
	return names[kind];
}

/** An item's kind, with the finding for an audit item. */
function itemKind(item: QueueItem): string {
	return item.kind === 'audit' && item.finding
		? sprintf(/* translators: 1: "Content audit", 2: a finding, e.g. "No description". */ __('%1$s: %2$s', 'seoprostats'), kindName(item.kind), findingName(item.finding))
		: kindName(item.kind);
}

function statusName(status: QueueStatus): string {
	const names: Record<QueueStatus, string> = {
		new: __('New', 'seoprostats'),
		accepted: __('Accepted', 'seoprostats'),
		done: __('Done', 'seoprostats'),
		dismissed: __('Dismissed', 'seoprostats'),
	};
	return names[status];
}

function filterName(filter: QueueFilter): string {
	const names: Record<QueueFilter, string> = {
		open: __('To do (new and accepted)', 'seoprostats'),
		new: __('New', 'seoprostats'),
		accepted: __('Accepted', 'seoprostats'),
		done: __('Done', 'seoprostats'),
		dismissed: __('Dismissed', 'seoprostats'),
		all: __('All', 'seoprostats'),
	};
	return names[filter];
}

/** How many items a filter holds, from the answer's counts. */
function filterCount(answer: QueueAnswer, filter: QueueFilter): number {
	const c = answer.counts;
	switch (filter) {
		case 'open':
			return c.new + c.accepted;
		case 'all':
			return c.new + c.accepted + c.done + c.dismissed;
		default:
			return c[filter];
	}
}

type PlanProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Plan({ state, update, open, onEngines }: PlanProps) {
	const status: QueueFilter = state.status ?? 'open';
	const goal = state.goal ?? '';
	const engine: SearchEngine = state.engine ?? 'google';
	// Back to the first items when the period, filters, engine, state or goal change.
	const scope = JSON.stringify([apiArgs(state), engine, status, goal]);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useQueue(state, status, goal, PER_PAGE, offset);
	const answer = query.data;
	useReportEngines(answer, onEngines);
	const items = answer?.items ?? [];
	const [error, setError] = useState('');

	return (
		<>
			{(query.isError || error) && (
				<Notice status="error" isDismissible={!!error} onRemove={() => setError('')} className="spst-notice">
					{error || errorMessage(query.error, __('The plan could not be loaded. Reload the page to try again.', 'seoprostats'))}
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
						<h2 className="spst-card__title">{__('Plan', 'seoprostats')}</h2>
						{answer && answer.through && answer.days > 0 && <PeriodLine range={answer.range} />}
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
					{answer && (
						<div className="spst-changes__filters spst-plan__filters">
							<SelectControl
								__nextHasNoMarginBottom
								label={__('Show', 'seoprostats')}
								value={status}
								options={QUEUE_FILTERS.map((f) => ({ value: f, label: `${filterName(f)} (${number(filterCount(answer, f))})` }))}
								onChange={(next: string) => update({ status: next === 'open' ? undefined : (next as QueueFilter) })}
							/>
							{answer.goals.length > 0 && (
								<SelectControl
									__nextHasNoMarginBottom
									label={__('Value from conversions of', 'seoprostats')}
									value={answer.goal?.id ?? ''}
									options={answer.goals.map((g) => ({ value: g.id, label: g.name }))}
									onChange={(next: string) => update({ goal: next === answer.goals[0]?.id ? undefined : next })}
								/>
							)}
						</div>
					)}
				</CardHeader>
				<CardBody className="spst-card__body">
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && answer.through && (
						<p className="spst-note spst-opportunities__intro">
							{__(
								'What to do next, best first. Score = potential clicks per 28 days × value (how well the page’s visits from search convert, against the site) × confidence (the kind’s, weighed by impressions) ÷ effort. Choose a score to see its parts.',
								'seoprostats'
							)}
						</p>
					)}
					{answer && !items.length && (
						<div className="spst-empty">
							<p>
								{!answer.through
									? __('No search data yet.', 'seoprostats')
									: status === 'open'
										? __('Nothing to do in this period: no opportunity was found, or every one is done or dismissed.', 'seoprostats')
										: __('No item in this state.', 'seoprostats')}
							</p>
						</div>
					)}
					{answer && items.length > 0 && (
						<ItemTable answer={answer} items={items} offset={offset} state={state} goal={goal} open={open} refreshing={query.isFetching} onError={setError} showExperiments={() => update({ report: 'experiments', status: undefined, goal: undefined })} />
					)}
					{answer && answer.through && <Notes answer={answer} />}
					{answer && answer.total > PER_PAGE && (
						<nav className="spst-changes__pager" aria-label={__('Pages of the plan', 'seoprostats')}>
							<span className="spst-muted">
								{sprintf(
									/* translators: 1: first item shown, 2: last item shown, 3: number of items. */
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

/** Notes under the list: pages left out, the period read, where value comes from. */
function Notes({ answer }: { answer: QueueAnswer }) {
	const notes: string[] = [];
	if (answer.left_out > 0) {
		notes.push(
			sprintf(
				/* translators: %s: number of items. */
				_n(
					'%s item is left out because its page has a running experiment: another change there now would spoil its measurement.',
					'%s items are left out because their pages have a running experiment: another change there now would spoil its measurement.',
					answer.left_out,
					'seoprostats'
				),
				number(answer.left_out)
			)
		);
	}
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
		answer.goal
			? sprintf(
					/* translators: %s: a goal's name. */
					__('Value: conversions of “%s” by visits from search to the page, against the site’s rate (1 for a page without them).', 'seoprostats'),
					answer.goal.name
				)
			: __('Without a goal, every page has value 1. Add a goal so pages that convert rank higher.', 'seoprostats')
	);
	notes.push(
		sprintf(
			/* translators: %s: number of days. */
			__('Dismissed items come back after %s days if they are still found. Items accepted or done stay listed when they are no longer found (marked “no longer found”).', 'seoprostats'),
			number(answer.rules.hide_days)
		)
	);
	return (
		<div className="spst-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
			{!answer.goal && (
				<p>
					<a href="#/goals">{__('Goals', 'seoprostats')}</a>
				</p>
			)}
		</div>
	);
}

interface ItemTableProps {
	answer: QueueAnswer;
	items: QueueItem[];
	offset: number;
	state: PlanProps['state'];
	goal: string;
	open: PlanProps['open'];
	refreshing: boolean;
	onError: (message: string) => void;
	showExperiments: () => void;
}

function ItemTable({ answer, items, offset, state, goal, open, refreshing, onError, showExperiments }: ItemTableProps) {
	const [shown, setShown] = useState<string | null>(null);
	return (
		<TableScroll label={__('Plan', 'seoprostats')}>
			<table className={`widefat striped spst-table spst-plan${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col" className="num">
							#
						</th>
						<th scope="col">{__('Item', 'seoprostats')}</th>
						<th scope="col">{__('Why, and what to do', 'seoprostats')}</th>
						<th scope="col" className="num">
							{__('Score', 'seoprostats')}
						</th>
						<th scope="col">{__('State', 'seoprostats')}</th>
						{boot.canManage && <th scope="col">{__('Actions', 'seoprostats')}</th>}
					</tr>
				</thead>
				<tbody>
					{items.map((item, i) => (
						<Fragment key={item.key}>
							<tr className={shown === item.key ? 'is-selected' : ''}>
								<td className="num">{number(offset + i + 1)}</td>
								<td>
									<strong className="spst-plan__kind">{itemKind(item)}</strong>
									<PageCell row={item} query={item.query ?? ''} open={open} />
								</td>
								<td>
									{item.why}
									<span className="spst-meta">{item.todo}</span>
								</td>
								<td className="num">
									<button
										type="button"
										className="spst-link"
										aria-expanded={shown === item.key}
										title={__('How the score is made', 'seoprostats')}
										onClick={() => setShown(shown === item.key ? null : item.key)}
									>
										{decimal(item.score)}
									</button>
								</td>
								<td>
									<StateCell item={item} showExperiments={showExperiments} />
								</td>
								{boot.canManage && (
									<td>
										<Actions item={item} state={state} goal={goal} onError={onError} />
									</td>
								)}
							</tr>
							{shown === item.key && (
								<tr className="spst-plan__detail">
									<td />
									<td colSpan={boot.canManage ? 5 : 4}>
										<Detail answer={answer} item={item} state={state} goal={goal} onError={onError} />
									</td>
								</tr>
							)}
						</Fragment>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

/** An item's state, who acted and, when done, its experiment. */
function StateCell({ item, showExperiments }: { item: QueueItem; showExperiments: () => void }) {
	const exp = item.experiment;
	let result = '';
	if (exp) {
		if (exp.status === 'decided' && exp.result) {
			/* translators: %s: a result, e.g. "Keep". */
			result = sprintf(__('Decided: %s', 'seoprostats'), resultLabel(exp.result));
		} else if (exp.status === 'cancelled') {
			result = __('Experiment cancelled', 'seoprostats');
		} else if (exp.due && exp.suggested) {
			/* translators: %s: a result, e.g. "Keep". */
			result = sprintf(__('Due for review (suggested: %s)', 'seoprostats'), resultLabel(exp.suggested));
		} else {
			/* translators: %s: a day. */
			result = sprintf(__('Measuring until %s', 'seoprostats'), longLabel(exp.review, 'day'));
		}
	}
	const effect = exp && exp.effect !== null ? (exp.unit === 'places' ? formatPlaces(-exp.effect, locale) : formatChange(exp.effect, locale)) : '';
	return (
		<>
			{statusName(item.status)}
			{!item.found && <span className="spst-meta">{__('No longer found', 'seoprostats')}</span>}
			{exp && (
				<span className="spst-meta">
					<button type="button" className="spst-link" onClick={showExperiments}>
						{result}
					</button>
					{effect && ` · ${metricLabel(exp.metric)} ${effect}`}
				</span>
			)}
			{item.note && <span className="spst-meta">{item.note}</span>}
			{item.updated && (
				<span className="spst-meta">
					{item.user
						? sprintf(/* translators: 1: a person, 2: a day. */ __('%1$s, %2$s', 'seoprostats'), item.user, longLabel(item.updated.slice(0, 10), 'day'))
						: longLabel(item.updated.slice(0, 10), 'day')}
				</span>
			)}
		</>
	);
}

interface ActProps {
	item: QueueItem;
	state: PlanProps['state'];
	goal: string;
	onError: (message: string) => void;
}

/** Run an action on an item; errors go to the notice above the list. */
function useAct({ item, state, goal, onError }: ActProps) {
	const data = useDataSet();
	const [busy, setBusy] = useState(false);
	const act = async (action: QueueAction, extra: { effort?: number; note?: string } = {}) => {
		setBusy(true);
		try {
			await updateQueueItem(data, state, goal, item.key, { action, ...extra });
			onError('');
		} catch (e) {
			onError(errorMessage(e, __('It could not be saved. Try again.', 'seoprostats')));
		}
		setBusy(false);
	};
	return { busy, act };
}

function Actions(props: ActProps) {
	const { item } = props;
	const { busy, act } = useAct(props);
	const done = () => {
		const question = sprintf(
			/* translators: 1: a measure, e.g. "CTR", 2: a page path. */
			__('Mark it done? An experiment starts now on %2$s and measures %1$s over the days before and after. Mark it done once the change is live.', 'seoprostats'),
			metricLabel(item.metric),
			item.figures.pages?.length ? item.figures.pages.map((page) => page.path).join(', ') : item.path
		);
		// eslint-disable-next-line no-alert -- a plain confirmation, as WordPress uses.
		if (window.confirm(question)) {
			void act('done');
		}
	};
	return (
		<div className="spst-plan__actions">
			{item.status === 'new' && (
				<Button variant="secondary" size="small" disabled={busy} onClick={() => void act('accept')}>
					{__('Accept', 'seoprostats')}
				</Button>
			)}
			{(item.status === 'new' || item.status === 'accepted') && (
				<Button variant="primary" size="small" disabled={busy} onClick={done}>
					{__('Done', 'seoprostats')}
				</Button>
			)}
			{(item.status === 'new' || item.status === 'accepted') && (
				<Button variant="tertiary" size="small" disabled={busy} onClick={() => void act('dismiss')}>
					{__('Dismiss', 'seoprostats')}
				</Button>
			)}
			{(item.status === 'dismissed' || item.status === 'accepted') && (
				<Button variant="tertiary" size="small" disabled={busy} onClick={() => void act('restore')}>
					{__('Restore', 'seoprostats')}
				</Button>
			)}
		</div>
	);
}

/** The score's parts, the item's figures, and (administrators) its effort and note. */
function Detail({ answer, item, state, goal, onError }: { answer: QueueAnswer } & ActProps) {
	const { busy, act } = useAct({ item, state, goal, onError });
	const [note, setNote] = useState(item.note);
	const p = item.parts;
	const kindEffort = (item.kind === 'audit' && item.finding ? answer.rules.audit_effort?.[item.finding] : undefined) ?? answer.rules.effort[item.kind];
	const f = item.figures;
	return (
		<div className="spst-plan__parts">
			<p>
				{sprintf(
					/* translators: 1: potential clicks, 2: value, 3: confidence, 4: effort, 5: score. */
					__('%1$s clicks × %2$s value × %3$s confidence ÷ %4$s effort = %5$s', 'seoprostats'),
					decimal(p.clicks),
					decimal(p.value),
					decimal(p.confidence),
					number(p.effort),
					decimal(item.score)
				)}
			</p>
			<ul className="spst-experiment__facts">
				<li>
					{item.kind === 'decay'
						? sprintf(
								/* translators: 1: clicks before, 2: clicks now. */
								__('Potential clicks: those lost, scaled to 28 days (%1$s → %2$s clicks).', 'seoprostats'),
								number(f.then_clicks ?? 0),
								number(f.clicks)
							)
						: item.kind === 'missing'
							? sprintf(
									/* translators: 1: share, e.g. 30%. */
									__('Potential clicks: impressions × the site’s CTR at its position × %1$s, scaled to 28 days.', 'seoprostats'),
									`${number(answer.rules.missing_share * 100)}%`
								)
							: item.kind === 'overlap'
								? __('Potential clicks: those the search would have if all its pages’ impressions had the best of their CTRs, scaled to 28 days.', 'seoprostats')
								: item.kind === 'audit'
									? sprintf(
											/* translators: 1: share, e.g. 15%. */
											__('Potential clicks: the page’s impressions × the site’s CTR at its position × %1$s (what this finding puts at stake), scaled to 28 days.', 'seoprostats'),
											`${number((f.share ?? 0) * 100)}%`
										)
									: __('Potential clicks: those the opportunity names, scaled to 28 days.', 'seoprostats')}
				</li>
				<li>
					{answer.site_rate !== null
						? __('Value: the page’s conversion rate of visits from search against the site’s, from 1 to 5.', 'seoprostats')
						: __('Value: 1, as there is no goal or no visits from search.', 'seoprostats')}
				</li>
				<li>
					{sprintf(
						/* translators: 1: the kind's confidence, 2: impressions for full confidence. */
						__('Confidence: the kind’s %1$s, less when there are fewer than %2$s impressions per 28 days.', 'seoprostats'),
						decimal(answer.rules.confidence[item.kind]),
						number(answer.rules.full_impressions)
					)}
				</li>
				<li>
					{item.effort_set
						? sprintf(/* translators: %s: the kind's effort. */ __('Effort: set by hand (the kind’s is %s).', 'seoprostats'), number(kindEffort))
						: __('Effort: the kind’s, from 1 (least) to 5.', 'seoprostats')}
				</li>
				<li>
					{sprintf(
						/* translators: %s: a measure, e.g. "CTR". */
						__('Done opens an experiment measuring %s.', 'seoprostats'),
						metricLabel(item.metric)
					)}
				</li>
			</ul>
			{boot.canManage && item.status !== 'done' && (
				<div className="spst-changes__filters spst-plan__edit">
					<SelectControl
						__nextHasNoMarginBottom
						label={__('Effort', 'seoprostats')}
						value={String(p.effort)}
						disabled={busy}
						options={[1, 2, 3, 4, 5].map((n) => ({ value: String(n), label: n === kindEffort ? sprintf(/* translators: %s: effort. */ __('%s (the kind’s)', 'seoprostats'), number(n)) : number(n) }))}
						onChange={(next: string) => void act('effort', { effort: Number(next) })}
					/>
					<TextControl __nextHasNoMarginBottom label={__('Note', 'seoprostats')} value={note} maxLength={190} onChange={setNote} />
					<Button variant="secondary" disabled={busy || note === item.note} onClick={() => void act('note', { note })}>
						{__('Save the note', 'seoprostats')}
					</Button>
				</div>
			)}
		</div>
	);
}
