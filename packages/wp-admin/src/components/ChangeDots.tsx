/**
 * A table row's changes as one marker, drawn as the chart's markers lane
 * draws them (DESIGN.md → Markers): a ring in the first group's colour with
 * up to three group dots and, for more than one change, their count. It is
 * a button named by its changes; choosing it opens them in the changes
 * modal, as the chart's markers do.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import type { Marker } from '@seoprostats/core';
import { locale } from '../boot';
import type { MarkerPick } from './ChangesModal';

/** Most changes named in the button's label, as in the chart's tooltips. */
const MAX_LISTED = 8;

interface Props {
	markers: Marker[];
	/** The row's days (YYYY-MM-DD, both included) and long label. */
	from: string;
	to: string;
	when: string;
	onPick?: (pick: MarkerPick) => void;
}

export function ChangeDots({ markers, from, to, when, onPick }: Readonly<Props>) {
	if (!markers.length) {
		return null;
	}
	const groups = [...new Set(markers.map((m) => m.group))].slice(0, 3);
	const listed = markers.slice(0, MAX_LISTED).map((m) => m.label);
	const rest = markers.length - listed.length;
	/* translators: %s: number of changes not listed. */
	const more = rest > 0 ? `; ${sprintf(_n('and %s more', 'and %s more', rest, 'seoprostats'), rest.toLocaleString(locale))}` : '';
	return (
		<button
			type="button"
			className="spst-change-dots"
			style={{ borderColor: `var(--spst-mark-${groups[0]})` }}
			aria-label={sprintf(/* translators: 1: a day, week or month, 2: the changes in it. */ __('Changes, %1$s: %2$s', 'seoprostats'), when, listed.join('; ') + more)}
			title={listed.join('\n') + (rest > 0 ? `\n${more.slice(2)}` : '')}
			onClick={() => onPick?.({ markers, from, to, when })}
		>
			{groups.map((group) => (
				<span key={group} className="spst-chart-lane__dot" style={{ background: `var(--spst-mark-${group})` }} aria-hidden="true" />
			))}
			{markers.length > 1 && (
				<span className="spst-chart-lane__count" aria-hidden="true">
					{markers.length.toLocaleString(locale)}
				</span>
			)}
		</button>
	);
}
