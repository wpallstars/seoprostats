/**
 * Goals: the pages and events that count as conversions, with the visits
 * that reached each, the conversion rate and revenue. Administrators add,
 * change and delete goals here; choosing a goal filters every report by it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	formatNumber,
	formatPercent,
	hasFilterValue,
	toggleFilterValue,
	type GoalRow,
	type GoalStep,
	type Operator,
	type ViewState,
} from '@seoprostats/core';
import { deleteDefinition, errorMessage, saveGoal, shareAccess, useGoals } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import type { ViewProps } from './App';
import { PeriodLine } from './Overview';
import { Change } from './components/Change';
import { DefinitionModal } from './components/DefinitionModal';
import { Money } from './components/Money';
import { StepFields, kindLabel } from './components/StepFields';
import { TableScroll } from './components/TableScroll';

/** The filter a goal or step stands for: its page or event. */
export function stepFilter(step: GoalStep): { dimension: 'page' | 'event'; value: string; op: Operator } {
	return { dimension: step.kind === 'page' ? 'page' : 'event', value: step.match, op: step.match.includes('*') ? 'matches' : 'is' };
}

export function toggleStep(filters: ViewState['filters'], step: GoalStep) {
	const f = stepFilter(step);
	return toggleFilterValue(filters, f.dimension, f.value, f.op);
}

export function hasStep(filters: ViewState['filters'], step: GoalStep): boolean {
	const f = stepFilter(step);
	return hasFilterValue(filters, f.dimension, f.value, f.op);
}

const EMPTY: GoalStep = { name: '', kind: 'event', match: '' };

function GoalEditor({ goal, onClose }: Readonly<{ goal: GoalRow | null; onClose: () => void }>) {
	const data = useDataSet();
	const [step, setStep] = useState<GoalStep>(goal ? { name: goal.name, kind: goal.kind, match: goal.match } : EMPTY);
	return (
		<DefinitionModal
			title={goal ? __('Edit goal', 'seoprostats') : __('Add a goal', 'seoprostats')}
			onClose={onClose}
			canSave={step.match.trim() !== ''}
			onSave={() => saveGoal(data, { ...step, match: step.match.trim(), name: step.name.trim() }, goal?.id)}
		>
			<StepFields
				step={step}
				onChange={setStep}
				nameLabel={__('Goal name', 'seoprostats')}
				nameHelp={__('As the reports show it, such as Signed up. Left empty, the page or event is the name.', 'seoprostats')}
			/>
		</DefinitionModal>
	);
}

/** Confirm, then delete a goal or funnel; returns an error message or ''. */
export async function confirmDelete(data: ReturnType<typeof useDataSet>, type: 'goals' | 'funnels', id: string, name: string): Promise<string> {
	const question =
		type === 'goals'
			? /* translators: %s: a goal's name. */ __('Delete the goal “%s”? Its statistics stay; only the goal goes.', 'seoprostats')
			: /* translators: %s: a funnel's name. */ __('Delete the funnel “%s”? Its statistics stay; only the funnel goes.', 'seoprostats');
	// eslint-disable-next-line no-alert -- a plain confirmation, as WordPress uses for deleting.
	if (!window.confirm(sprintf(question, name))) {
		return '';
	}
	try {
		await deleteDefinition(data, type, id);
		return '';
	} catch (e) {
		return errorMessage(e, __('It could not be deleted. Try again.', 'seoprostats'));
	}
}

/** With no goals: who can add one. */
function emptyHint(): string {
	if (shareAccess.token) {
		return __('No goals are set up on this site yet.', 'seoprostats');
	}
	return boot.canManage ? __('Add one to see how many visits reach it, and what they are worth.', 'seoprostats') : __('An administrator can add goals here.', 'seoprostats');
}

export function Goals({ state, update }: Readonly<ViewProps>) {
	const data = useDataSet();
	const query = useGoals(state);
	const [editing, setEditing] = useState<GoalRow | null | 'new'>(null);
	const [error, setError] = useState('');
	const answer = query.data;
	const comparing = !!answer?.compare;

	return (
		<>
			{(query.isError || error) && (
				<Notice status="error" isDismissible={!!error} onRemove={() => setError('')}>
					{error || errorMessage(query.error, __('The goals could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}

			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<div>
						<h2 className="spst-card__title">{__('Goals', 'seoprostats')}</h2>
						{answer && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
					</div>
					{boot.canManage && (
						<Button variant="secondary" onClick={() => setEditing('new')}>
							{__('Add a goal', 'seoprostats')}
						</Button>
					)}
				</CardHeader>
				<CardBody className="spst-card__body">
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && !answer.goals.length && (
						<div className="spst-empty">
							<p>{__('A goal is a page or event that counts as a conversion: a thank-you page, a sign-up, a purchase.', 'seoprostats')}</p>
							<p>{emptyHint()}</p>
						</div>
					)}
					{answer && answer.goals.length > 0 && (
						<TableScroll label={__('Goals', 'seoprostats')}>
							<table className={`widefat striped spst-table${query.isFetching ? ' is-refreshing' : ''}`}>
								<thead>
									<tr>
										<th scope="col">{__('Goal', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Visitors', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Conversions', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Conversion rate', 'seoprostats')}</th>
										<th scope="col" className="num">{__('Revenue', 'seoprostats')}</th>
										{boot.canManage && (
											<th scope="col">
												<span className="screen-reader-text">{__('Actions', 'seoprostats')}</span>
											</th>
										)}
									</tr>
								</thead>
								<tbody>
									{answer.goals.map((goal) => {
										const name = goal.name || goal.match;
										const active = hasStep(state.filters, goal);
										return (
											<tr key={goal.id}>
												<td>
													<button
														type="button"
														className={`spst-link${active ? ' is-active' : ''}`}
														aria-pressed={active}
														title={
															active
																? __('Remove this filter', 'seoprostats')
																: __('Show only visits that reached this goal', 'seoprostats')
														}
														onClick={() => update({ filters: toggleStep(state.filters, goal) })}
													>
														{name}
													</button>
													<span className="spst-meta">
														{kindLabel(goal.kind)}: <code>{goal.match}</code>
													</span>
												</td>
												<td className="num">{formatNumber(goal.visitors, locale)}</td>
												<td className="num" title={sprintf(/* translators: %s: a number of times. */ __('Reached %s times', 'seoprostats'), formatNumber(goal.completions, locale, false))}>
													{formatNumber(goal.visits, locale)}
													{comparing && <Change change={goal.change?.visits} />}
												</td>
												<td className="num">
													{formatPercent(goal.conversion_rate, locale)}
													{comparing && <Change change={goal.change?.conversion_rate} />}
												</td>
												<td className="num">
													<Money revenue={goal.revenue} />
												</td>
												{boot.canManage && (
													<td className="spst-actions">
														<Button variant="link" onClick={() => setEditing(goal)}>
															{__('Edit', 'seoprostats')}
															<span className="screen-reader-text"> {name}</span>
														</Button>
														<Button
															variant="link"
															isDestructive
															onClick={async () => setError(await confirmDelete(data, 'goals', goal.id, name))}
														>
															{__('Delete', 'seoprostats')}
															<span className="screen-reader-text"> {name}</span>
														</Button>
													</td>
												)}
											</tr>
										);
									})}
								</tbody>
							</table>
						</TableScroll>
					)}
					{answer && answer.goals.length > 0 && (
						<p className="spst-note">
							{sprintf(
								/* translators: %s: number of visits. */
								__('Conversions are visits that reached the goal; the rate is of all %s visits in the period.', 'seoprostats'),
								formatNumber(answer.visits, locale, false)
							)}
						</p>
					)}
				</CardBody>
			</Card>

			{editing && <GoalEditor goal={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
		</>
	);
}
