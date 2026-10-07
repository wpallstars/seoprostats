/**
 * A change against the comparison period, coloured by whether it is good
 * news for the metric (a falling bounce rate is). Places: a search
 * position's move, shown as places climbed (up is good).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, sprintf } from '@wordpress/i18n';
import { formatChange, formatPlaces, METRICS, type MetricKey } from '@seoprostats/core';
import { locale } from '../boot';

interface Props {
	/** The metric, for whether up is good; without it, up is good (conversions). */
	metric?: MetricKey;
	/** Whether up or down is good, for numbers that are not metrics (dead clicks: down). */
	better?: 'up' | 'down';
	/** Relative change; with places, the position now − then (lower is better). */
	change: number | null | undefined;
	/** The change is a search position's, in places. */
	places?: boolean;
	/** Formatted value of the comparison period, for the tooltip. */
	previous?: string;
}

export function Change({ metric, better, change, places, previous }: Props) {
	// A position falling from 8 to 5 is 3 places climbed.
	const value = places && typeof change === 'number' ? -change : change;
	const text = places ? formatPlaces(value, locale) : formatChange(value, locale);
	let tone = 'is-flat';
	if (typeof value === 'number' && Math.abs(value) >= (places ? 0.05 : 0.005)) {
		const up = value > 0;
		tone = up === ((places ? 'up' : better ?? (metric ? METRICS[metric].better : 'up')) === 'up') ? 'is-good' : 'is-bad';
	}
	const arrow = typeof value === 'number' && value !== 0 ? (value > 0 ? '↑' : '↓') : '';
	const titles = [
		places && typeof value === 'number' ? (value >= 0 ? __('Places climbed', 'seoprostats') : __('Places dropped', 'seoprostats')) : '',
		previous ? sprintf(/* translators: %s: the metric's value in the comparison period. */ __('Before: %s', 'seoprostats'), previous) : '',
	].filter(Boolean);
	return (
		<span className={`spst-change ${tone}`} title={titles.length ? titles.join(' · ') : undefined}>
			{arrow && <span aria-hidden="true">{arrow} </span>}
			{text}
		</span>
	);
}
