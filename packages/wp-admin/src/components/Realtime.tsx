/**
 * Visitors in the last 30 minutes, refreshed every 30 seconds. It shows in
 * the Overview's summary card, beside the period: only the Overview needs
 * it, and the tab bar keeps its room for the tabs and controls.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { createContext, useContext } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber } from '@seoprostats/core';
import { useRealtime } from '../api';
import { locale } from '../boot';
import { usePrintAll } from '../printAll';

/**
 * Whether the Overview shows the live count: a shared report hides it when
 * its owner chose to, or when it locks filters (the count is site-wide).
 * Printed reports never show it.
 */
export const RealtimeShown = createContext(true);

export function useRealtimeShown(): boolean {
	const shown = useContext(RealtimeShown);
	const printing = usePrintAll();
	return shown && !printing;
}

export function Realtime() {
	const query = useRealtime();
	if (!query.data) {
		return null;
	}
	const n = query.data.visitors;
	const text = sprintf(
		/* translators: %s: number of visitors. */
		_n('%s visitor in the last 30 minutes', '%s visitors in the last 30 minutes', n, 'seoprostats'),
		formatNumber(n, locale)
	);
	return (
		<span className={`spst-live${n > 0 ? ' is-active' : ''}`} title={__('Hits are counted within a minute of arriving.', 'seoprostats')}>
			<span className="spst-live__dot" aria-hidden="true" />
			<span aria-live="polite">{text}</span>
		</span>
	);
}
