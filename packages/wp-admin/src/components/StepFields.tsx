/**
 * The fields of a goal or funnel step: a page or an event, which one, and
 * its name. Pages and events the site has seen are offered as you type.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useId } from 'react';
import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { GoalKind, GoalStep } from '@seoprostats/core';
import { useBreakdown } from '../api';

interface Props {
	step: GoalStep;
	onChange: (step: GoalStep) => void;
	/** Label for the name field; steps may leave it empty. */
	nameLabel: string;
	nameHelp?: string;
}

export function kindLabel(kind: GoalKind): string {
	return kind === 'page' ? __('Page viewed', 'seoprostats') : __('Event sent', 'seoprostats');
}

/** The most seen pages or events of the last 90 days, unfiltered. */
function Suggestions({ id, kind }: Readonly<{ id: string; kind: GoalKind }>) {
	const query = useBreakdown({ range: '90d', filters: [] }, kind === 'page' ? 'page' : 'event', 100);
	return (
		<datalist id={id}>
			{(query.data?.rows ?? []).filter((row) => row.value !== '').map((row) => (
				<option key={row.value} value={row.value} />
			))}
		</datalist>
	);
}

export function StepFields({ step, onChange, nameLabel, nameHelp }: Readonly<Props>) {
	const list = useId();
	return (
		<div className="spst-step-fields">
			<SelectControl
				__nextHasNoMarginBottom
				label={__('Counts when', 'seoprostats')}
				value={step.kind}
				options={[
					{ value: 'event', label: kindLabel('event') },
					{ value: 'page', label: kindLabel('page') },
				]}
				onChange={(kind: string) => onChange({ ...step, kind: kind === 'page' ? 'page' : 'event' })}
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={step.kind === 'page' ? __('Page', 'seoprostats') : __('Event name', 'seoprostats')}
				help={
					step.kind === 'page'
						? __('A path such as /pricing/. Use * for any text: /blog/* is every blog page.', 'seoprostats')
						: __('As your site sends it, such as Purchase. Use * for any text.', 'seoprostats')
				}
				value={step.match}
				list={list}
				autoComplete="off"
				onChange={(match: string) => onChange({ ...step, match })}
			/>
			<Suggestions id={list} kind={step.kind} />
			<TextControl
				__nextHasNoMarginBottom
				label={nameLabel}
				help={nameHelp}
				value={step.name}
				onChange={(name: string) => onChange({ ...step, name })}
			/>
		</div>
	);
}
