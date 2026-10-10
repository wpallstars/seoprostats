/**
 * Range and comparison pickers, with day fields for a custom range.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { COMPARE_KEYS, RANGE_KEYS, type CompareKey, type RangeKey, type ViewState } from '@seoprostats/core';
import { compareLabel, rangeLabel } from '../labels';
import { savePeriod } from '../boot';

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

	const ranges: Array<{ value: string; label: string }> = RANGE_KEYS.filter((k) => k !== 'realtime').map((k) => ({
		value: k,
		label: rangeLabel(k),
	}));
	const picked: string = custom ? 'custom' : state.range;

	return (
		<div className="spst-controls">
			<SelectControl
				__nextHasNoMarginBottom
				label={__('Period', 'seoprostats')}
				hideLabelFromVision
				value={picked}
				options={ranges}
				onChange={(value: string) => {
					if (value === 'custom') {
						setEditing(true);
						return;
					}
					setEditing(false);
					update({ range: value as RangeKey, from: undefined, to: undefined });
					savePeriod({ range: value as RangeKey, compare: state.compare });
				}}
			/>
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
				onChange={(value: string) => {
					update({ compare: value as CompareKey });
					savePeriod({ range: state.range, compare: value as CompareKey });
				}}
			/>
		</div>
	);
}
