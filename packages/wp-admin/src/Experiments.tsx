/**
 * Search → Experiments: a change to a page and what it should do, written
 * down before the result is known, then measured. Equal windows before and
 * after the change are compared against pages that were left alone, with
 * the usual spread of those pages, the search engine updates and the other
 * changes of the time beside it. The plugin suggests keep, revise, undo or
 * inconclusive; a person decides, and the numbers decided on are kept.
 *
 * Due ones first, then running, decided and cancelled. Administrators
 * start one here or from a row in Changes (the change and its page filled
 * in), and decide, cancel or delete them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl, TextControl, TextareaControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	EXPERIMENT_METRICS,
	EXPERIMENT_RESULTS,
	EXPERIMENT_WINDOWS,
	formatChange,
	formatDecimal,
	formatNumber,
	formatPercent,
	formatPlaces,
	type Experiment,
	type ExperimentFigures,
	type ExperimentMeasured,
	type ExperimentMetric,
	type ExperimentReason,
	type ExperimentResult,
	type Marker,
	singleEngine,
	type SearchEngine,
} from '@seoprostats/core';
import { addExperiment, deleteExperiment, errorMessage, updateExperiment, useExperiments, useGoals } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import { longLabel, momentLabel } from './dates';
import { DefinitionModal } from './components/DefinitionModal';
import { TableScroll } from './components/TableScroll';
import type { SearchReportProps } from './components/SearchSetup';

/** A change chosen in Changes, to show while starting an experiment on it (the address keeps only its id). */
let chosenChange: Marker | null = null;

/** Remember the change an experiment is started from (Changes → Start an experiment). */
export function startFrom(change: Marker): void {
	chosenChange = change;
}

export function metricLabel(metric: ExperimentMetric): string {
	const names: Record<ExperimentMetric, string> = {
		clicks: __('Clicks', 'seoprostats'),
		impressions: __('Impressions', 'seoprostats'),
		ctr: __('CTR', 'seoprostats'),
		position: __('Position', 'seoprostats'),
		visits: __('Visits from search', 'seoprostats'),
		conversions: __('Conversions', 'seoprostats'),
	};
	return names[metric];
}

export function resultLabel(result: ExperimentResult): string {
	const names: Record<ExperimentResult, string> = {
		keep: __('Keep', 'seoprostats'),
		revise: __('Revise', 'seoprostats'),
		undo: __('Undo', 'seoprostats'),
		inconclusive: __('Inconclusive', 'seoprostats'),
	};
	return names[result];
}

/** The data counted for "enough", in words. */
function unitText(unit: string): string {
	const texts: Record<string, string> = {
		clicks: __('clicks', 'seoprostats'),
		impressions: __('impressions', 'seoprostats'),
		visits: __('visits', 'seoprostats'),
		conversions: __('conversions', 'seoprostats'),
	};
	return texts[unit] ?? unit;
}

function reasonText(reason: ExperimentReason): string {
	const texts: Record<ExperimentReason, string> = {
		no_group: __('too few unchanged pages to compare with, so the change is only associated with the change made', 'seoprostats'),
		search_update: __('a search engine update rolled out in a window', 'seoprostats'),
		too_little_data: __('too little data on its pages to judge', 'seoprostats'),
		no_measure: __('no figures before the change', 'seoprostats'),
		within_noise: __('within the usual spread of unchanged pages', 'seoprostats'),
		at_threshold: __('the expected way, by at least the smallest change that counts', 'seoprostats'),
		under_threshold: __('the expected way, but by less than the smallest change that counts', 'seoprostats'),
		opposite: __('the opposite way to the one expected', 'seoprostats'),
		no_change: __('no change', 'seoprostats'),
	};
	return texts[reason];
}

/** An effect: places for position (shown as places climbed), else percent. */
function effectText(metric: ExperimentMetric, effect: number | null | undefined): string {
	if (effect === null || effect === undefined) {
		return '—';
	}
	return metric === 'position' ? formatPlaces(-effect, locale) : formatChange(effect, locale);
}

/** The effect's tone: good when it went the way expected. */
function tone(m: ExperimentMeasured): string {
	if (m.improvement === null || m.improvement === 0 || !m.enough.ok || m.beyond_noise === false) {
		return 'is-flat';
	}
	return m.improvement > 0 ? 'is-good' : 'is-bad';
}

function dayText(day: string): string {
	return longLabel(day, 'day');
}

/** The state shown in the list. */
function statusText(item: Experiment): string {
	if (item.status === 'cancelled') {
		return __('Cancelled', 'seoprostats');
	}
	if (item.status === 'decided' && item.result) {
		/* translators: %s: a result, e.g. "Keep". */
		return sprintf(__('Decided: %s', 'seoprostats'), resultLabel(item.result));
	}
	if (item.due) {
		return __('Due for review', 'seoprostats');
	}
	const m = item.measurement;
	if (m?.state === 'running') {
		/* translators: 1: days of data so far, 2: days needed. */
		return sprintf(__('Running: %1$s of %2$s days', 'seoprostats'), formatNumber(m.so_far, locale, false), formatNumber(m.days, locale, false));
	}
	return __('Running', 'seoprostats');
}

export function Experiments({ state, update }: Readonly<SearchReportProps>) {
	const query = useExperiments();
	const answer = query.data;
	const list = answer?.experiments ?? [];
	const [open, setOpen] = useState<number | null>(null);
	const [adding, setAdding] = useState(!!state.change && boot.canManage);
	const [error, setError] = useState('');
	const shown = list.find((e) => e.id === open) ?? null;
	const engine: SearchEngine = singleEngine(state.engine);

	return (
		<>
			{(query.isError || error) && (
				<Notice status="error" isDismissible={!!error} onRemove={() => setError('')} className="spst-notice">
					{error || errorMessage(query.error, __('The experiments could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}
			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<div>
						<h2 className="spst-card__title">{__('Experiments', 'seoprostats')}</h2>
						<p className="spst-meta">
							{__('A change and what it should do, measured over equal days before and after against pages that were left alone.', 'seoprostats')}
						</p>
					</div>
					{boot.canManage && (
						<Button variant="secondary" onClick={() => setAdding(true)}>
							{__('Start an experiment', 'seoprostats')}
						</Button>
					)}
				</CardHeader>
				<CardBody className="spst-card__body">
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && !list.length && (
						<div className="spst-empty">
							<p>{__('No experiments yet.', 'seoprostats')}</p>
							<p>
								{__('Before changing a page’s title, description, content or links, write down what it should do: more clicks, a better place, a higher CTR. Once the data is in, the change is measured against pages that were left alone and a result is suggested.', 'seoprostats')}
							</p>
						</div>
					)}
					{list.length > 0 && (
						<TableScroll label={__('Experiments', 'seoprostats')}>
							<table className={`widefat striped spst-table${query.isFetching ? ' is-refreshing' : ''}`}>
								<thead>
									<tr>
										<th scope="col">{__('Experiment', 'seoprostats')}</th>
										<th scope="col">{__('Started', 'seoprostats')}</th>
										<th scope="col">{__('Measure', 'seoprostats')}</th>
										<th scope="col">{__('State', 'seoprostats')}</th>
										<th scope="col" className="num">
											{__('Effect', 'seoprostats')}
										</th>
										<th scope="col">{__('Suggested', 'seoprostats')}</th>
									</tr>
								</thead>
								<tbody>
									{list.map((item) => {
										const m = item.measurement?.state === 'ready' ? item.measurement : null;
										return (
											<tr key={item.id} className={open === item.id ? 'is-selected' : ''}>
												<td>
													<button type="button" className="spst-link" aria-expanded={open === item.id} onClick={() => setOpen(open === item.id ? null : item.id)}>
														{item.name}
													</button>
													<span className="spst-meta">{item.pages.join(', ')}</span>
												</td>
												<td className="spst-nowrap">{dayText(item.start.slice(0, 10))}</td>
												<td>
													{metricLabel(item.metric)} {item.direction === 'up' ? '↑' : '↓'}
													<span className="spst-meta">
														{item.engine === 'bing' ? __('Bing', 'seoprostats') : __('Google', 'seoprostats')} ·{' '}
														{sprintf(/* translators: %s: number of days. */ __('%s days each side', 'seoprostats'), formatNumber(item.days, locale, false))}
													</span>
												</td>
												<td>{statusText(item)}</td>
												<td className="num">{m ? <span className={`spst-change ${tone(m)}`}>{effectText(item.metric, m.effect)}</span> : '–'}</td>
												<td>{m ? resultLabel(m.suggested) : <span className="spst-muted">–</span>}</td>
											</tr>
										);
									})}
								</tbody>
							</table>
						</TableScroll>
					)}
				</CardBody>
			</Card>
			{shown && <Detail key={shown.id} item={shown} onError={setError} onDeleted={() => setOpen(null)} />}
			{adding && (
				<AddModal
					change={state.change ?? ''}
					engine={engine}
					state={state}
					onClose={() => {
						setAdding(false);
						// The change was for this one experiment.
						if (state.change) {
							update({ change: undefined });
						}
					}}
				/>
			)}
		</>
	);
}

/** One side's figures in a window, for the measure. */
function figure(metric: ExperimentMetric, figures: ExperimentFigures): string {
	switch (metric) {
		case 'ctr':
			return figures.ctr === null || figures.ctr === undefined ? '–' : formatPercent(figures.ctr, locale);
		case 'position':
			return figures.position === null || figures.position === undefined ? '–' : formatDecimal(figures.position, locale);
		default: {
			const value = figures[metric];
			return typeof value === 'number' ? formatNumber(value, locale, false) : '–';
		}
	}
}

function Detail({ item, onError, onDeleted }: Readonly<{ item: Experiment; onError: (message: string) => void; onDeleted: () => void }>) {
	const data = useDataSet();
	const m = item.measurement?.state === 'ready' ? item.measurement : null;
	const running = item.measurement?.state === 'running' ? item.measurement : null;
	const [result, setResult] = useState<ExperimentResult>(item.result ?? m?.suggested ?? 'inconclusive');
	const [note, setNote] = useState(item.note);
	const [busy, setBusy] = useState(false);

	const act = async (work: () => Promise<unknown>) => {
		setBusy(true);
		try {
			await work();
			onError('');
		} catch (e) {
			onError(errorMessage(e, __('It could not be saved. Try again.', 'seoprostats')));
		}
		setBusy(false);
	};
	const remove = () => {
		/* translators: %s: an experiment's name. */
		const question = sprintf(__('Delete the experiment “%s” and its mark on the timeline?', 'seoprostats'), item.name);
		// eslint-disable-next-line no-alert -- a plain confirmation, as WordPress uses for deleting.
		if (window.confirm(question)) {
			void act(async () => {
				await deleteExperiment(data, item.id);
				onDeleted();
			});
		}
	};
	const windows = m?.windows ?? running?.windows ?? item.windows;
	const confounders = m ? [...m.confounders.updates, ...m.confounders.site, ...m.confounders.pages] : [];

	return (
		<Card className="spst-card is-wide spst-section spst-experiment" size="small">
			<CardHeader className="spst-card__header">
				<div>
					<h2 className="spst-card__title">{item.name}</h2>
					<p className="spst-meta">
						{sprintf(
							/* translators: 1: pages, 2: when it started, 3: what it should change, 4: the smallest change that counts. */
							__('%1$s · started %2$s · %3$s, by at least %4$s', 'seoprostats'),
							item.pages.join(', '),
							momentLabel(item.start),
							`${metricLabel(item.metric)} ${item.direction === 'up' ? '↑' : '↓'}`,
							item.metric === 'position' ? sprintf(/* translators: %s: places. */ __('%s places', 'seoprostats'), formatDecimal(item.threshold, locale)) : `${formatNumber(item.threshold, locale, false)}%`
						)}
						{item.goal && ` · ${item.goal.name ?? item.goal.id}`}
					</p>
					<p className="spst-meta">
						{sprintf(
							/* translators: 1: first day before, 2: last day before, 3: first day after, 4: last day after. */
							__('Before: %1$s – %2$s. After: %3$s – %4$s.', 'seoprostats'),
							dayText(windows.before.from),
							dayText(windows.before.to),
							dayText(windows.after.from),
							dayText(windows.after.to)
						)}
					</p>
				</div>
			</CardHeader>
			<CardBody className="spst-card__body">
				{item.hypothesis && <p>{item.hypothesis}</p>}
				{running && (
					<p>
						{running.through
							? sprintf(
									/* translators: 1: days of data so far, 2: days needed, 3: the review day. */
									__('Running: %1$s of %2$s days of data after the change. It can be reviewed once the data reaches %3$s.', 'seoprostats'),
									formatNumber(running.so_far, locale, false),
									formatNumber(running.days, locale, false),
									dayText(running.review)
								)
							: __('Running: there is no search data yet.', 'seoprostats')}
					</p>
				)}
				{item.status === 'decided' && m && (
					<p className="spst-meta">
						{sprintf(
							/* translators: %s: when it was decided. */
							__('The numbers as they were when it was decided (%s).', 'seoprostats'),
							item.decided ? momentLabel(item.decided) : ''
						)}
					</p>
				)}
				{m && (
					<>
						<p className="spst-experiment__summary">
							<strong className={`spst-change ${tone(m)}`}>{effectText(item.metric, m.effect)}</strong> {m.summary}
						</p>
						<TableScroll label={__('Before and after', 'seoprostats')}>
							<table className="widefat striped spst-table">
								<thead>
									<tr>
										<th scope="col" />
										<th scope="col" className="num">
											{__('Pages', 'seoprostats')}
										</th>
										<th scope="col" className="num">
											{__('Before', 'seoprostats')}
										</th>
										<th scope="col" className="num">
											{__('After', 'seoprostats')}
										</th>
										<th scope="col" className="num">
											{__('Change', 'seoprostats')}
										</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<th scope="row">{__('Its pages', 'seoprostats')}</th>
										<td className="num">{formatNumber(m.pages.count, locale, false)}</td>
										<td className="num">{figure(item.metric, m.pages.before)}</td>
										<td className="num">{figure(item.metric, m.pages.after)}</td>
										<td className="num">{effectText(item.metric, m.change)}</td>
									</tr>
									<tr>
										<th scope="row">{__('Unchanged pages', 'seoprostats')}</th>
										<td className="num">{formatNumber(m.group.count, locale, false)}</td>
										<td className="num">{m.compared ? figure(item.metric, m.group.before) : '–'}</td>
										<td className="num">{m.compared ? figure(item.metric, m.group.after) : '–'}</td>
										<td className="num">{m.compared ? effectText(item.metric, m.group.change) : '–'}</td>
									</tr>
								</tbody>
							</table>
						</TableScroll>
						<ul className="spst-experiment__facts">
							{m.noise && (
								<li>
									{sprintf(
										/* translators: 1: low end of the spread, 2: high end, 3: number of pages. */
										_n(
											'Usual spread of unchanged pages: %1$s to %2$s (%3$s page).',
											'Usual spread of unchanged pages: %1$s to %2$s (%3$s pages).',
											m.noise.pages,
											'seoprostats'
										),
										effectText(item.metric, item.metric === 'position' ? m.noise.high : m.noise.low),
										effectText(item.metric, item.metric === 'position' ? m.noise.low : m.noise.high),
										formatNumber(m.noise.pages, locale, false)
									)}
								</li>
							)}
							<li>
								{sprintf(
									/* translators: 1: the data counted, e.g. "clicks", 2: before, 3: after, 4: needed in each window. */
									__('Data on its pages: %2$s %1$s before, %3$s after (%4$s needed in each).', 'seoprostats'),
									unitText(m.enough.unit),
									formatNumber(m.enough.before, locale, false),
									formatNumber(m.enough.after, locale, false),
									formatNumber(m.enough.needed, locale, false)
								)}
							</li>
							{m.coverage && (m.coverage.before < m.coverage.days || m.coverage.after < m.coverage.days) && (
								<li>
									{sprintf(
										/* translators: 1: days with data before, 2: days with data after, 3: days in each window. */
										__('Days with search data: %1$s before and %2$s after, of %3$s.', 'seoprostats'),
										formatNumber(m.coverage.before, locale, false),
										formatNumber(m.coverage.after, locale, false),
										formatNumber(m.coverage.days, locale, false)
									)}
								</li>
							)}
							<li>
								{sprintf(
									/* translators: 1: suggested result, 2: why. */
									__('Suggested: %1$s (%2$s).', 'seoprostats'),
									resultLabel(m.suggested),
									m.reasons.map(reasonText).join('; ')
								)}
							</li>
						</ul>
						{confounders.length > 0 && (
							<>
								<h3 className="spst-experiment__heading">
									{sprintf(
										/* translators: %s: number of changes. */
										_n('%s other change in the windows', '%s other changes in the windows', m.confounders.total, 'seoprostats'),
										formatNumber(m.confounders.total, locale, false)
									)}
								</h3>
								<ul className="spst-experiment__confounders">
									{confounders.map((c) => (
										<li key={c.id}>
											<span className="spst-chart-lane__dot" style={{ background: `var(--spst-mark-${c.group})` }} aria-hidden="true" /> {momentLabel(c.t)}: {c.label}
											{c.path && <span className="spst-muted"> · {c.path}</span>}
										</li>
									))}
								</ul>
							</>
						)}
					</>
				)}
				{item.note && item.status !== 'running' && <p className="spst-meta">{item.note}</p>}

				{boot.canManage && (
					<div className="spst-experiment__decide">
						{item.status !== 'cancelled' && (
							<>
								<SelectControl
									__nextHasNoMarginBottom
									label={__('Result', 'seoprostats')}
									value={result}
									options={EXPERIMENT_RESULTS.map((r) => ({
										value: r,
										label: m && r === m.suggested ? sprintf(/* translators: %s: a result. */ __('%s (suggested)', 'seoprostats'), resultLabel(r)) : resultLabel(r),
									}))}
									onChange={(next: string) => setResult(next as ExperimentResult)}
								/>
								<TextareaControl
									__nextHasNoMarginBottom
									label={__('Note', 'seoprostats')}
									help={__('Why, or what was learnt.', 'seoprostats')}
									value={note}
									rows={2}
									onChange={setNote}
								/>
							</>
						)}
						<div className="spst-form__actions">
							{item.status !== 'cancelled' && (
								<Button variant="primary" isBusy={busy} disabled={busy} onClick={() => void act(() => updateExperiment(data, item.id, { action: 'decide', result, note }))}>
									{item.status === 'decided' ? __('Change the decision', 'seoprostats') : __('Decide', 'seoprostats')}
								</Button>
							)}
							{item.status === 'running' && (
								<Button variant="secondary" disabled={busy} onClick={() => void act(() => updateExperiment(data, item.id, { action: 'cancel', note }))}>
									{__('Cancel the experiment', 'seoprostats')}
								</Button>
							)}
							<Button variant="link" isDestructive disabled={busy} onClick={remove}>
								{__('Delete', 'seoprostats')}
							</Button>
						</div>
					</div>
				)}
			</CardBody>
		</Card>
	);
}

/** Today in the site's time zone, for the date box. */
function today(): string {
	const parts = new Intl.DateTimeFormat('en-CA', { timeZone: boot.timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
	const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
	return `${get('year')}-${get('month')}-${get('day')}`;
}

function AddModal({ change, engine, state, onClose }: Readonly<{ change: string; engine: SearchEngine; state: SearchReportProps['state']; onClose: () => void }>) {
	const data = useDataSet();
	const from = chosenChange && String(chosenChange.id) === change ? chosenChange : null;
	const [name, setName] = useState('');
	const [useChange, setUseChange] = useState(change !== '');
	const [page, setPage] = useState(from?.path ?? state.page ?? '');
	const [start, setStart] = useState('');
	const [days, setDays] = useState('28');
	const [metric, setMetric] = useState<ExperimentMetric>('clicks');
	const [direction, setDirection] = useState<'up' | 'down'>('up');
	const [threshold, setThreshold] = useState('');
	const [goal, setGoal] = useState('');
	const [hypothesis, setHypothesis] = useState('');
	const goals = useGoals(state);
	const goalList = goals.data?.goals ?? [];
	const fromChange = useChange && change !== '';

	return (
		<DefinitionModal
			title={__('Start an experiment', 'seoprostats')}
			onClose={onClose}
			canSave={name.trim() !== '' && (fromChange || page.trim() !== '') && (metric !== 'conversions' || goal !== '')}
			onSave={() =>
				addExperiment(data, {
					name: name.trim(),
					...(fromChange ? { change: Number(change) } : { start: start.replace('T', ' ') }),
					...(page.trim() ? { page: page.trim() } : {}),
					days: Number(days),
					engine,
					metric,
					direction,
					...(threshold.trim() !== '' ? { threshold: Number(threshold) } : {}),
					...(metric === 'conversions' ? { goal } : {}),
					...(hypothesis.trim() ? { hypothesis: hypothesis.trim() } : {}),
				})
			}
		>
			{change !== '' && (
				<Notice status="info" isDismissible={false}>
					{from
						? sprintf(
								/* translators: 1: a change, 2: when. */
								__('From the change “%1$s” (%2$s): its time is the start, and its page the page unless you name others.', 'seoprostats'),
								from.label,
								momentLabel(from.t)
							)
						: sprintf(
								/* translators: %s: a change's number. */
								__('From change %s: its time is the start, and its page the page unless you name others.', 'seoprostats'),
								change
							)}
					{useChange && (
						<>
							{' '}
							<Button variant="link" onClick={() => setUseChange(false)}>
								{__('Give a time instead', 'seoprostats')}
							</Button>
						</>
					)}
				</Notice>
			)}
			<TextControl
				__nextHasNoMarginBottom
				label={__('The change and what it should do', 'seoprostats')}
				help={__('In one line, such as "A shorter title lifts CTR".', 'seoprostats')}
				value={name}
				maxLength={190}
				onChange={setName}
				required
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={fromChange ? __('Pages (optional)', 'seoprostats') : __('Pages', 'seoprostats')}
				help={__('A path such as /pricing/, or several, comma-separated (up to 50).', 'seoprostats')}
				value={page}
				onChange={setPage}
			/>
			{!fromChange && (
				<TextControl
					__nextHasNoMarginBottom
					type="datetime-local"
					label={__('When the change was made (optional)', 'seoprostats')}
					help={sprintf(/* translators: %s: the site's time zone. */ __('In the site time zone (%s). Leave empty for now.', 'seoprostats'), boot.timezone)}
					value={start}
					max={`${today()}T23:59`}
					onChange={setStart}
				/>
			)}
			<SelectControl
				__nextHasNoMarginBottom
				label={__('What it should change', 'seoprostats')}
				value={metric}
				options={EXPERIMENT_METRICS.map((key) => ({ value: key, label: metricLabel(key) }))}
				onChange={(next: string) => setMetric(next as ExperimentMetric)}
			/>
			{metric === 'conversions' && (
				<SelectControl
					__nextHasNoMarginBottom
					label={__('Goal', 'seoprostats')}
					value={goal}
					options={[{ value: '', label: __('Choose a goal', 'seoprostats') }, ...goalList.map((g) => ({ value: g.id, label: g.name }))]}
					onChange={setGoal}
				/>
			)}
			<SelectControl
				__nextHasNoMarginBottom
				label={__('Expected', 'seoprostats')}
				value={direction}
				options={[
					{ value: 'up', label: metric === 'position' ? __('A better place', 'seoprostats') : __('Up', 'seoprostats') },
					{ value: 'down', label: metric === 'position' ? __('A worse place', 'seoprostats') : __('Down', 'seoprostats') },
				]}
				onChange={(next: string) => setDirection(next === 'down' ? 'down' : 'up')}
			/>
			<TextControl
				__nextHasNoMarginBottom
				type="number"
				min={0}
				step={metric === 'position' ? 0.1 : 1}
				label={metric === 'position' ? __('Smallest change that counts, in places', 'seoprostats') : __('Smallest change that counts, in percent', 'seoprostats')}
				placeholder={metric === 'position' ? '1' : '10'}
				value={threshold}
				onChange={setThreshold}
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={__('Days before and after', 'seoprostats')}
				help={__('Whole weeks, so both sides hold each weekday equally often. Longer is surer for pages with few searches.', 'seoprostats')}
				value={days}
				options={EXPERIMENT_WINDOWS.map((d) => ({ value: String(d), label: formatNumber(d, locale, false) }))}
				onChange={setDays}
			/>
			<TextareaControl __nextHasNoMarginBottom label={__('Reasoning (optional)', 'seoprostats')} value={hypothesis} rows={2} onChange={setHypothesis} />
		</DefinitionModal>
	);
}
