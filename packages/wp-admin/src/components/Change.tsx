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

/** Which way is good news: places climbed always are; then the prop, then the metric's own. */
function goodWay({ metric, better, places }: Readonly<Pick<Props, 'metric' | 'better' | 'places'>>): 'up' | 'down' {
	if (places) {
		return 'up';
	}
	if (better) {
		return better;
	}
	return metric ? METRICS[metric].better : 'up';
}

function arrowOf(value: number | null | undefined): string {
	if (typeof value !== 'number' || value === 0) {
		return '';
	}
	return value > 0 ? '↑' : '↓';
}

function placesTitle(places: boolean | undefined, value: number | null | undefined): string {
	if (!places || typeof value !== 'number') {
		return '';
	}
	return value >= 0 ? __('Places climbed', 'seoprostats') : __('Places dropped', 'seoprostats');
}

export function Change({ metric, better, change, places, previous }: Readonly<Props>) {
	// A position falling from 8 to 5 is 3 places climbed.
	const value = places && typeof change === 'number' ? -change : change;
	const text = places ? formatPlaces(value, locale) : formatChange(value, locale);
	let tone = 'is-flat';
	if (typeof value === 'number' && Math.abs(value) >= (places ? 0.05 : 0.005)) {
		const up = value > 0;
		tone = up === (goodWay({ metric, better, places }) === 'up') ? 'is-good' : 'is-bad';
	}
	const arrow = arrowOf(value);
	const titles = [
		placesTitle(places, value),
		previous ? sprintf(/* translators: %s: the metric's value in the comparison period. */ __('Before: %s', 'seoprostats'), previous) : '',
	].filter(Boolean);
	return (
		<span className={`spst-change ${tone}`} title={titles.length ? titles.join(' · ') : undefined}>
			{arrow && <span aria-hidden="true">{arrow} </span>}
			{text}
		</span>
	);
}
