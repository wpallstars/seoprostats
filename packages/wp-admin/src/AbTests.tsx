/**
 * A/B tests: every test made with the A/B test block, with its visits per
 * variant and the leader; and one test's variants side by side (goals,
 * revenue, bounce rate, engaged time, clicks), each compared with the
 * control. Tests are started, paused and ended in the block editor.
 *
 * Numbers cover each test's life, not the period chosen above; the
 * filters apply. Not the Search → Experiments report (a change and its
 * expected effect on search).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	formatChange,
	formatDuration,
	formatNumber,
	formatPercent,
	hasFilterValue,
	toggleFilterValue,
	type AbComparison,
	type AbTestAnswer,
	type AbTestRow,
	type AbTestStatus,
	type AbTestVerdictCode,
	type AbThresholds,
	type AbVariantVerdict,
	type ViewState,
} from '@seoprostats/core';
import { errorMessage, useAbTest, useAbTests } from './api';
import { locale } from './boot';
import { longLabel } from './dates';
import type { ViewProps } from './App';
import { Money } from './components/Money';
import { TableScroll } from './components/TableScroll';

export function statusLabel(status: AbTestStatus): string {
	const labels: Record<AbTestStatus, string> = {
		draft: __('Draft', 'seoprostats'),
		running: __('Running', 'seoprostats'),
		paused: __('Paused', 'seoprostats'),
		ended: __('Ended', 'seoprostats'),
	};
	return labels[status];
}

function verdictLabel(verdict: AbVariantVerdict): string {
	const labels: Record<AbVariantVerdict, string> = {
		control: __('Control', 'seoprostats'),
		too_early: __('Too early', 'seoprostats'),
		better: __('Better', 'seoprostats'),
		worse: __('Worse', 'seoprostats'),
		unclear: __('No clear difference', 'seoprostats'),
	};
	return labels[verdict];
}

const VERDICT_TONES: Partial<Record<AbVariantVerdict, string>> = { better: 'is-good', worse: 'is-bad' };

function verdictTone(verdict: AbVariantVerdict): string {
	return VERDICT_TONES[verdict] ?? 'is-flat';
}

const NOTICE_STATUSES: Partial<Record<AbTestVerdictCode, 'success' | 'warning'>> = { winner: 'success', control: 'warning' };

/** The test verdict's notice: a winner is good news, the rest is information. */
function noticeStatus(code: AbTestVerdictCode): 'success' | 'info' | 'warning' {
	return NOTICE_STATUSES[code] ?? 'info';
}

/** good when the whole interval is above zero, bad when below, else flat. */
function intervalTone(interval: AbComparison['interval']): string {
	if (interval && interval[0] > 0) {
		return 'is-good';
	}
	return interval && interval[1] < 0 ? 'is-bad' : 'is-flat';
}

/** A probability as a whole percentage, never 0% or 100% (nothing is certain; 0 and 1 are rounded). */
function chance(p: number | null): string {
	if (p === null) {
		return '—';
	}
	if (p > 0.995) {
		return `>${formatPercent(0.99, locale)}`;
	}
	if (p < 0.005) {
		return `<${formatPercent(0.01, locale)}`;
	}
	return formatPercent(Math.round(p * 100) / 100, locale);
}

/** The uplift and its 95% interval: +12% (−3% to +29%). */
function Uplift({ value, control }: Readonly<{ value: AbComparison; control: boolean }>) {
	if (control) {
		return <span className="spst-muted">{__('Control', 'seoprostats')}</span>;
	}
	if (value.uplift === null) {
		return <span className="spst-muted">—</span>;
	}
	const tone = intervalTone(value.interval);
	return (
		<>
			<span className={`spst-change ${tone}`}>{formatChange(value.uplift, locale)}</span>
			{value.interval && (
				<span className="spst-meta" title={__('95% interval: the uplift is likely within this range.', 'seoprostats')}>
					{sprintf(
						/* translators: 1: lowest likely uplift, 2: highest, e.g. "−3% to +29%". */
						__('%1$s to %2$s', 'seoprostats'),
						formatChange(value.interval[0], locale),
						formatChange(value.interval[1], locale)
					)}
				</span>
			)}
		</>
	);
}

/** When a test ran: from its start to its end, or since its start. */
function whenText(started: string | null, ended: string | null): string {
	if (!started) {
		return __('Not started', 'seoprostats');
	}
	const from = longLabel(started.slice(0, 10), 'day');
	if (ended) {
		/* translators: 1: first day, 2: last day. */
		return sprintf(__('%1$s to %2$s', 'seoprostats'), from, longLabel(ended.slice(0, 10), 'day'));
	}
	/* translators: %s: a day. */
	return sprintf(__('Since %s', 'seoprostats'), from);
}

function thresholdsText(t: AbThresholds): string {
	return sprintf(
		/* translators: 1: probability, such as 95%, 2: visits, 3: conversions, 4: days. */
		__('A variant is called better or worse at a %1$s chance to beat the control, once each side has %2$s visits, the two have %3$s conversions between them, and the test has run %4$s days. Before that it is too early to call.', 'seoprostats'),
		formatPercent(t.confidence, locale),
		formatNumber(t.visits, locale, false),
		formatNumber(t.conversions, locale, false),
		formatNumber(t.days, locale, false)
	);
}

function PageCell({ test }: Readonly<{ test: Pick<AbTestRow, 'post' | 'removed'> }>) {
	const title = test.post.title || test.post.path || __('(no page)', 'seoprostats');
	return (
		<span className="spst-meta">
			{test.post.edit_url ? <a href={test.post.edit_url}>{title}</a> : title}
			{test.post.path && test.post.title && <span className="spst-muted"> · {test.post.path}</span>}
			{test.removed && <span className="spst-muted"> · {__('block removed', 'seoprostats')}</span>}
		</span>
	);
}

export function AbTests({ state, update }: Readonly<ViewProps>) {
	return state.test ? <Detail id={state.test} state={state} update={update} /> : <List state={state} update={update} />;
}

function List({ state, update }: Readonly<ViewProps>) {
	const query = useAbTests(state.filters);
	const answer = query.data;
	const tests = answer?.tests ?? [];

	return (
		<>
			{query.isError && (
				<Notice status="error" isDismissible={false} className="spst-notice">
					{errorMessage(query.error, __('The A/B tests could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}
			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<div>
						<h2 className="spst-card__title">{__('A/B tests', 'seoprostats')}</h2>
						<p className="spst-meta">
							{__('Each test over its whole life, not the period chosen above. Visits that saw more than one variant of a test are counted apart.', 'seoprostats')}
						</p>
					</div>
				</CardHeader>
				<CardBody className="spst-card__body">
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && !tests.length && (
						<div className="spst-empty">
							<p>{__('No A/B tests yet.', 'seoprostats')}</p>
							<p>
								{__('Add an A/B test block to a post or page, put two or more versions of a headline, button or price in it, choose the goals that count, and start it. Each visitor sees one version; this report says which does better.', 'seoprostats')}
							</p>
						</div>
					)}
					{tests.length > 0 && (
						<TableScroll label={__('A/B tests', 'seoprostats')}>
							<table className={`widefat striped spst-table${query.isFetching ? ' is-refreshing' : ''}`}>
								<thead>
									<tr>
										<th scope="col">{__('Test', 'seoprostats')}</th>
										<th scope="col">{__('State', 'seoprostats')}</th>
										<th scope="col">{__('Variants', 'seoprostats')}</th>
										<th scope="col" className="num">
											{__('Visits', 'seoprostats')}
										</th>
										<th scope="col">{__('Leader', 'seoprostats')}</th>
									</tr>
								</thead>
								<tbody>
									{tests.map((test) => (
										<tr key={test.id}>
											<td>
												<button type="button" className="spst-link" onClick={() => update({ test: test.id })}>
													{test.name || test.id}
												</button>
												<PageCell test={test} />
											</td>
											<td>
												{statusLabel(test.status)}
												<span className="spst-meta">{whenText(test.started, test.ended)}</span>
											</td>
											<td>
												<ul className="spst-ab-variants">
													{test.variants.map((v) => (
														<li key={v.slug}>
															<span>{v.label}</span>{' '}
															<span className="spst-muted">
																{sprintf(
																	/* translators: %s: number of visits. */
																	_n('%s visit', '%s visits', v.visits, 'seoprostats'),
																	formatNumber(v.visits, locale)
																)}
															</span>
														</li>
													))}
												</ul>
											</td>
											<td className="num">
												{formatNumber(test.visits, locale)}
												{test.mixed.visits > 0 && (
													<span className="spst-meta">
														{sprintf(/* translators: %s: number of visits. */ __('%s mixed', 'seoprostats'), formatNumber(test.mixed.visits, locale))}
													</span>
												)}
											</td>
											<td>
												{test.leader ? test.leader.label : <span className="spst-muted">—</span>}
												<span className="spst-meta">{test.verdict.text}</span>
											</td>
										</tr>
									))}
								</tbody>
							</table>
						</TableScroll>
					)}
					{answer && tests.length > 0 && <p className="spst-note">{thresholdsText(answer.thresholds)}</p>}
				</CardBody>
			</Card>
		</>
	);
}

/** Show reports for visits that saw one variant (choosing it again takes the filter out). */
function VariantFilter({ value, label, state, update }: Readonly<{ value: string; label: string; state: ViewState; update: ViewProps['update'] }>) {
	const active = hasFilterValue(state.filters, 'variant', value);
	return (
		<button
			type="button"
			className={`spst-link${active ? ' is-active' : ''}`}
			aria-pressed={active}
			title={
				active
					? __('Remove this filter', 'seoprostats')
					: __('Show only visits that saw this variant, here and in the other reports', 'seoprostats')
			}
			onClick={() => update({ filters: toggleFilterValue(state.filters, 'variant', value) })}
		>
			{label}
		</button>
	);
}

function Detail({ id, state, update }: Readonly<ViewProps & { id: string }>) {
	const query = useAbTest(id, state.filters);
	const answer = query.data;
	const back = (
		<Button variant="link" onClick={() => update({ test: undefined })}>
			{__('← All A/B tests', 'seoprostats')}
		</Button>
	);
	if (query.isError) {
		return (
			<>
				{back}
				<Notice status="error" isDismissible={false} className="spst-notice">
					{errorMessage(query.error, __('The A/B test could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			</>
		);
	}
	if (!answer) {
		return (
			<>
				{back}
				<div className="spst-skeleton spst-skeleton--table" aria-busy="true" />
			</>
		);
	}
	return (
		<>
			{back}
			<Report answer={answer} refreshing={query.isFetching} state={state} update={update} />
		</>
	);
}

function Report({ answer, refreshing, state, update }: Readonly<ViewProps & { answer: AbTestAnswer; refreshing: boolean }>) {
	const { test, variants, primary } = answer;
	const name = test.name || test.id;
	const table = `widefat striped spst-table${refreshing ? ' is-refreshing' : ''}`;
	const label = (v: (typeof variants)[number]) => (
		<>
			<VariantFilter value={`${test.id}:${v.slug}`} label={v.label} state={state} update={update} />
			{v.control && <span className="spst-badge">{__('Control', 'seoprostats')}</span>}
			{v.winner && <span className="spst-badge">{__('Chosen winner', 'seoprostats')}</span>}
		</>
	);

	return (
		<>
			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<div>
						<h2 className="spst-card__title">{name}</h2>
						<p className="spst-meta">
							{statusLabel(test.status)} · {whenText(test.started, test.ended)} ·{' '}
							{sprintf(/* translators: %s: number of days. */ _n('%s day', '%s days', answer.period.days, 'seoprostats'), formatNumber(answer.period.days, locale, false))}
						</p>
						<PageCell test={test} />
					</div>
				</CardHeader>
				<CardBody className="spst-card__body">
					<Notice status={noticeStatus(answer.verdict.code)} isDismissible={false} className="spst-notice">
						{answer.verdict.text}
					</Notice>
					<h3 className="spst-subtitle">
						{sprintf(
							/* translators: %s: what the variants are compared on, such as a goal's name. */
							__('Compared on: %s', 'seoprostats'),
							primary.name
						)}
					</h3>
					<TableScroll label={__('Variants', 'seoprostats')}>
						<table className={table}>
							<thead>
								<tr>
									<th scope="col">{__('Variant', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Visits', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Conversions', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Conversion rate', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Uplift', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Chance to beat control', 'seoprostats')}</th>
									<th scope="col">{__('Verdict', 'seoprostats')}</th>
								</tr>
							</thead>
							<tbody>
								{variants.map((v) => (
									<tr key={v.slug}>
										<td>{label(v)}</td>
										<td className="num">
											{formatNumber(v.visits, locale)}
											<span className="spst-meta">{formatPercent(v.share, locale)}</span>
										</td>
										<td className="num">{formatNumber(v.primary.conversions, locale)}</td>
										<td className="num">{formatPercent(v.primary.rate, locale)}</td>
										<td className="num">
											<Uplift value={v.primary} control={v.control} />
										</td>
										<td className="num">{v.control ? <span className="spst-muted">—</span> : chance(v.primary.probability)}</td>
										<td>{v.control ? <span className="spst-muted">—</span> : <span className={`spst-change ${verdictTone(v.primary.verdict)}`}>{verdictLabel(v.primary.verdict)}</span>}</td>
									</tr>
								))}
							</tbody>
						</table>
					</TableScroll>
					<p className="spst-note">
						{answer.mixed.visits > 0
							? sprintf(
									/* translators: 1: number of visits, 2: their share of the test's visits. */
									__('%1$s visits (%2$s) saw more than one variant: they are counted apart, in no variant.', 'seoprostats'),
									formatNumber(answer.mixed.visits, locale, false),
									formatPercent(answer.mixed.share, locale)
								)
							: __('No visit saw more than one variant.', 'seoprostats')}{' '}
						{thresholdsText(answer.thresholds)}
					</p>
				</CardBody>
			</Card>

			{answer.goals.length > 0 && (
				<Card className="spst-card is-wide spst-section" size="small">
					<CardHeader className="spst-card__header">
						<h2 className="spst-card__title">{__('Goals', 'seoprostats')}</h2>
					</CardHeader>
					<CardBody className="spst-card__body">
						<TableScroll label={__('Goals by variant', 'seoprostats')}>
							<table className={table}>
								<thead>
									<tr>
										<th scope="col">{__('Goal', 'seoprostats')}</th>
										<th scope="col">{__('Variant', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Conversions', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Conversion rate', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Uplift', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Chance to beat control', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Revenue', 'seoprostats')}</th>
									</tr>
								</thead>
								<tbody>
									{answer.goals.flatMap((goal, g) =>
										variants.map((v, i) => {
											const r = v.goals[g];
											if (!r) {
												return null;
											}
											return (
												<tr key={`${goal.id}:${v.slug}`}>
													{i === 0 && (
														<th scope="rowgroup" rowSpan={variants.length}>
															{goal.name || goal.match}
														</th>
													)}
													<td>{v.label}</td>
													<td className="num" title={sprintf(/* translators: %s: a number of times. */ __('Reached %s times', 'seoprostats'), formatNumber(r.completions, locale, false))}>
														{formatNumber(r.conversions, locale)}
													</td>
													<td className="num">{formatPercent(r.rate, locale)}</td>
													<td className="num">
														<Uplift value={r} control={v.control} />
													</td>
													<td className="num">{v.control ? <span className="spst-muted">—</span> : chance(r.probability)}</td>
													<td className="num">
														<Money revenue={r.revenue} />
													</td>
												</tr>
											);
										})
									)}
								</tbody>
							</table>
						</TableScroll>
						<p className="spst-note">{__('A conversion is a visit that reached the goal after it saw the test. Revenue is shown per currency, never added across currencies.', 'seoprostats')}</p>
					</CardBody>
				</Card>
			)}

			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<h2 className="spst-card__title">{__('Behaviour', 'seoprostats')}</h2>
				</CardHeader>
				<CardBody className="spst-card__body">
					<TableScroll label={__('Behaviour by variant', 'seoprostats')}>
						<table className={table}>
							<thead>
								<tr>
									<th scope="col">{__('Variant', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Visitors', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Page loads', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Bounce rate', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Engaged time', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Clicks', 'seoprostats')}</th>
									<th scope="col" className="num">{__('Visits that clicked', 'seoprostats')}</th>
								</tr>
							</thead>
							<tbody>
								{variants.map((v) => (
									<tr key={v.slug}>
										<td>{v.label}</td>
										<td className="num">{formatNumber(v.visitors, locale)}</td>
										<td className="num">{formatNumber(v.pageviews, locale)}</td>
										<td className="num">{formatPercent(v.bounce_rate, locale)}</td>
										<td className="num">{formatDuration(v.engaged_time)}</td>
										<td className="num">{formatNumber(v.clicks, locale)}</td>
										<td className="num">{formatPercent(v.click_rate, locale)}</td>
									</tr>
								))}
							</tbody>
						</table>
					</TableScroll>
					<p className="spst-note">
						{__('Page loads that showed the variant; clicks inside it; engaged time is the time per visit with the site on screen.', 'seoprostats')}
					</p>
				</CardBody>
			</Card>
		</>
	);
}
