/**
 * Range and comparison pickers, with day fields for a custom range.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { COMPARE_KEYS, type CompareKey, type RangeKey, type ViewState } from '@seoprostats/core';
import { compareLabel, rangeLabel, rangeMenu } from '../labels';

interface Props {
	state: ViewState;
	update: (patch: Partial<ViewState>) => void;
}

function today(): string {
	const d = new Date();
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function daysAgo(days: number): string {
	const d = new Date();
	d.setDate(d.getDate() - days);
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function Controls({ state, update }: Readonly<Props>) {
	const [editing, setEditing] = useState(false);
	const [from, setFrom] = useState(state.from ?? daysAgo(29));
	const [to, setTo] = useState(state.to ?? today());
	const custom = editing || state.range === 'custom';

	const picked: RangeKey = custom ? 'custom' : state.range;
	const groups = rangeMenu();
	// A range left out of the menu (30d, 90d from an older link) is shown while chosen.
	const listed = groups.some((group) => group.keys.includes(picked));
	const option = (key: RangeKey) => (
		<option key={key} value={key}>
			{rangeLabel(key)}
		</option>
	);

	return (
		<div className="spst-controls">
			<SelectControl
				__nextHasNoMarginBottom
				label={__('Period', 'seoprostats')}
				hideLabelFromVision
				value={picked}
				onChange={(value: string) => {
					if (value === 'custom') {
						setEditing(true);
						return;
					}
					setEditing(false);
					update({ range: value as RangeKey, from: undefined, to: undefined });
				}}
			>
				{!listed && option(picked)}
				{groups.map((group) =>
					group.label ? (
						<optgroup key={group.label} label={group.label}>
							{group.keys.map(option)}
						</optgroup>
					) : (
						group.keys.map(option)
					),
				)}
			</SelectControl>
			{custom && (
				<form
					className="spst-custom-range"
					onSubmit={(event) => {
						event.preventDefault();
						if (from && to && from <= to) {
							setEditing(false);
							update({ range: 'custom', from, to });
						}
					}}
				>
					<label>
						<span className="screen-reader-text">{__('First day', 'seoprostats')}</span>
						<input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} required />
					</label>
					<span aria-hidden="true">–</span>
					<label>
						<span className="screen-reader-text">{__('Last day', 'seoprostats')}</span>
						<input type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} required />
					</label>
					<Button variant="secondary" type="submit" disabled={!from || !to || from > to}>
						{__('Apply', 'seoprostats')}
					</Button>
				</form>
			)}
			<SelectControl
				__nextHasNoMarginBottom
				label={__('Compare with', 'seoprostats')}
				hideLabelFromVision
				value={state.compare}
				options={COMPARE_KEYS.map((k) => ({ value: k, label: compareLabel(k) }))}
				disabled={state.range === 'all'}
				onChange={(value: string) => update({ compare: value as CompareKey })}
			/>
		</div>
	);
}
