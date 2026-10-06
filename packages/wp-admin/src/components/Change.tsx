/**
 * A change against the comparison period, coloured by whether it is good
 * news for the metric (a falling bounce rate is).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, sprintf } from '@wordpress/i18n';
import { formatChange, METRICS, type MetricKey } from '@seoprostats/core';
import { locale } from '../boot';

interface Props {
	metric: MetricKey;
	change: number | null | undefined;
	/** Formatted value of the comparison period, for the tooltip. */
	previous?: string;
}

export function Change({ metric, change, previous }: Props) {
	const text = formatChange(change, locale);
	let tone = 'is-flat';
	if (typeof change === 'number' && Math.abs(change) >= 0.005) {
		const up = change > 0;
		tone = up === (METRICS[metric].better === 'up') ? 'is-good' : 'is-bad';
	}
	const arrow = typeof change === 'number' && change !== 0 ? (change > 0 ? '↑' : '↓') : '';
	return (
		<span
			className={`spst-change ${tone}`}
			title={previous ? sprintf(/* translators: %s: the metric's value in the comparison period. */ __('Before: %s', 'seoprostats'), previous) : undefined}
		>
			{arrow && <span aria-hidden="true">{arrow} </span>}
			{text}
		</span>
	);
}
