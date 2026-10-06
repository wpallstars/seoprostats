/**
 * Funnels: ordered steps (2 to 12 pages or events) within one visit, with
 * the visits that reached each step and where they dropped out.
 * Administrators add, change and delete funnels here.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { FUNNEL_STEPS, formatNumber, formatPercent, type FunnelRow, type GoalStep } from '@seoprostats/core';
import { errorMessage, saveFunnel, useFunnels } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import type { ViewProps } from './App';
import { PeriodLine } from './Overview';
import { confirmDelete, hasStep, toggleStep } from './Goals';
import { Change } from './components/Change';
import { DefinitionModal } from './components/DefinitionModal';
import { StepFields } from './components/StepFields';

const NEW_STEP: GoalStep = { name: '', kind: 'page', match: '' };

function stepName(step: GoalStep): string {
	return step.name || step.match;
}

function FunnelEditor({ funnel, onClose }: { funnel: FunnelRow | null; onClose: () => void }) {
	const data = useDataSet();
	const [name, setName] = useState(funnel?.name ?? '');
	const [steps, setSteps] = useState<GoalStep[]>(
		funnel ? funnel.steps.map((s) => ({ name: s.name, kind: s.kind, match: s.match })) : [NEW_STEP, { ...NEW_STEP }]
	);
	const set = (i: number, step: GoalStep) => setSteps(steps.map((s, j) => (j === i ? step : s)));
	const move = (i: number, by: number) => {
		const next = [...steps];
		const [step] = next.splice(i, 1);
		if (step) {
			next.splice(i + by, 0, step);
			setSteps(next);
		}
	};
	const complete = name.trim() !== '' && steps.length >= FUNNEL_STEPS.min && steps.every((s) => s.match.trim() !== '');

	return (
		<DefinitionModal
			wide
			title={funnel ? __('Edit funnel', 'seoprostats') : __('Add a funnel', 'seoprostats')}
			onClose={onClose}
			canSave={complete}
			onSave={() =>
				saveFunnel(
					data,
					{ name: name.trim(), steps: steps.map((s) => ({ ...s, name: s.name.trim(), match: s.match.trim() })) },
					funnel?.id
				)
			}
		>
			<TextControl __nextHasNoMarginBottom label={__('Funnel name', 'seoprostats')} value={name} onChange={setName} required />
			<p className="spst-note">
				{__('Steps count in this order within one visit; other pages and events may come between them.', 'seoprostats')}
			</p>
			<ol className="spst-steps-editor">
				{steps.map((step, i) => (
					<li key={i} className="spst-steps-editor__step">
						<div className="spst-steps-editor__head">
							<strong>{sprintf(/* translators: %d: step number. */ __('Step %d', 'seoprostats'), i + 1)}</strong>
							<span className="spst-steps-editor__tools">
								<Button size="small" variant="tertiary" disabled={i === 0} onClick={() => move(i, -1)}>
									{__('Move up', 'seoprostats')}
								</Button>
								<Button size="small" variant="tertiary" disabled={i === steps.length - 1} onClick={() => move(i, 1)}>
									{__('Move down', 'seoprostats')}
								</Button>
								<Button
									size="small"
									variant="tertiary"
									isDestructive
									disabled={steps.length <= FUNNEL_STEPS.min}
									onClick={() => setSteps(steps.filter((_, j) => j !== i))}
								>
									{__('Remove', 'seoprostats')}
								</Button>
							</span>
						</div>
						<StepFields step={step} onChange={(s) => set(i, s)} nameLabel={__('Step name (optional)', 'seoprostats')} />
					</li>
				))}
			</ol>
			{steps.length < FUNNEL_STEPS.max && (
				<Button variant="secondary" onClick={() => setSteps([...steps, { ...NEW_STEP }])}>
					{__('Add a step', 'seoprostats')}
				</Button>
			)}
		</DefinitionModal>
	);
}

function FunnelCard({ funnel, comparing, onEdit, onDelete, props }: { funnel: FunnelRow; comparing: boolean; onEdit: () => void; onDelete: () => void; props: ViewProps }) {
	const { state, update } = props;
	const id = `spst-funnel-${funnel.id}`;
	return (
		<Card className="spst-card is-wide spst-funnel" size="small">
			<CardHeader className="spst-card__header">
				<h3 className="spst-card__title" id={id}>
					{funnel.name}
				</h3>
				<div className="spst-funnel__summary">
					<span>
						{sprintf(
							/* translators: 1: visits that completed the funnel, 2: visits that started it. */
							__('%1$s of %2$s visits completed', 'seoprostats'),
							formatNumber(funnel.completed, locale),
							formatNumber(funnel.entered, locale)
						)}
					</span>
					<strong className="spst-funnel__rate">{formatPercent(funnel.completion_rate, locale)}</strong>
					{comparing && <Change change={funnel.change?.completed} />}
					{boot.canManage && (
						<span className="spst-actions">
							<Button variant="link" onClick={onEdit}>
								{__('Edit', 'seoprostats')}
								<span className="screen-reader-text"> {funnel.name}</span>
							</Button>
							<Button variant="link" isDestructive onClick={onDelete}>
								{__('Delete', 'seoprostats')}
								<span className="screen-reader-text"> {funnel.name}</span>
							</Button>
						</span>
					)}
				</div>
			</CardHeader>
			<CardBody className="spst-card__body">
				<ol className="spst-funnel__steps" aria-labelledby={id}>
					{funnel.steps.map((step, i) => {
						const active = hasStep(state.filters, step);
						return (
							<li key={i} className="spst-funnel__step">
								<div className="spst-funnel__label">
									<span className="spst-funnel__number" aria-hidden="true">
										{i + 1}
									</span>
									<button
										type="button"
										className={`spst-link${active ? ' is-active' : ''}`}
										aria-pressed={active}
										title={active ? __('Remove this filter', 'seoprostats') : __('Show only visits that reached this step', 'seoprostats')}
										onClick={() => update({ filters: toggleStep(state.filters, step) })}
									>
										{stepName(step)}
									</button>
									{step.name && (
										<code className="spst-meta">{step.match}</code>
									)}
								</div>
								<div className="spst-funnel__bar" aria-hidden="true">
									<span style={{ width: `${step.rate * 100}%` }} />
								</div>
								<div className="spst-funnel__numbers">
									<span>
										{formatNumber(step.visits, locale)} <span className="spst-muted">{__('visits', 'seoprostats')}</span>
									</span>
									<span>{formatPercent(step.rate, locale)}</span>
									{i > 0 && (
										<span className="spst-funnel__drop" title={__('Visits at the step before that did not reach this one', 'seoprostats')}>
											{sprintf(
												/* translators: 1: number of visits, 2: share of the step before. */
												__('%1$s dropped (%2$s)', 'seoprostats'),
												formatNumber(step.dropped, locale),
												formatPercent(1 - step.step_rate, locale)
											)}
										</span>
									)}
								</div>
							</li>
						);
					})}
				</ol>
			</CardBody>
		</Card>
	);
}

export function Funnels(props: ViewProps) {
	const { state } = props;
	const data = useDataSet();
	const query = useFunnels(state);
	const [editing, setEditing] = useState<FunnelRow | null | 'new'>(null);
	const [error, setError] = useState('');
	const answer = query.data;

	return (
		<>
			{(query.isError || error) && (
				<Notice status="error" isDismissible={!!error} onRemove={() => setError('')}>
					{error || errorMessage(query.error, __('The funnels could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}

			<div className="spst-section__head">
				<div>
					<h2 className="spst-section__title">{__('Funnels', 'seoprostats')}</h2>
					{answer && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
				</div>
				{boot.canManage && (
					<Button variant="secondary" onClick={() => setEditing('new')}>
						{__('Add a funnel', 'seoprostats')}
					</Button>
				)}
			</div>

			{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
			{answer && !answer.funnels.length && (
				<Card className="spst-card is-wide" size="small">
					<CardBody className="spst-empty">
						<p>{__('A funnel follows visits through steps in order, such as product, cart, checkout and thank-you, and shows where they leave.', 'seoprostats')}</p>
						<p>
							{boot.canManage
								? __('Add one with 2 to 12 steps.', 'seoprostats')
								: __('An administrator can add funnels here.', 'seoprostats')}
						</p>
					</CardBody>
				</Card>
			)}
			<div className={`spst-funnels${query.isFetching && answer ? ' is-refreshing' : ''}`}>
				{answer?.funnels.map((funnel) => (
					<FunnelCard
						key={funnel.id}
						funnel={funnel}
						comparing={!!answer.compare}
						props={props}
						onEdit={() => setEditing(funnel)}
						onDelete={async () => setError(await confirmDelete(data, 'funnels', funnel.id, funnel.name))}
					/>
				))}
			</div>

			{editing && <FunnelEditor funnel={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
		</>
	);
}
