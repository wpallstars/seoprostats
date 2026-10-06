/**
 * Visitors in the last 30 minutes, refreshed every 30 seconds.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber } from '@seoprostats/core';
import { useRealtime } from '../api';
import { locale } from '../boot';

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
