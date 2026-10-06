/**
 * Revenue, one amount per currency (amounts in different currencies are
 * never added together).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import { formatMoney, type Revenue } from '@seoprostats/core';
import { locale } from '../boot';

export function Money({ revenue }: { revenue: Revenue[] }) {
	if (!revenue.length) {
		return <span className="spst-muted">—</span>;
	}
	return (
		<span className="spst-money">
			{revenue.map((r) => (
				<span key={r.currency} className="spst-money__amount">
					{formatMoney(r.amount, r.currency, locale)}
				</span>
			))}
			<span className="screen-reader-text"> {__('revenue', 'seoprostats')}</span>
		</span>
	);
}
