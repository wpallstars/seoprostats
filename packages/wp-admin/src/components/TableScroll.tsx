/**
 * A table that scrolls sideways inside its card on narrow screens, rather
 * than widening the page. Focusable and labelled, so the keyboard scrolls it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { ReactNode } from 'react';

export function TableScroll({ label, children }: Readonly<{ label: string; children: ReactNode }>) {
	return (
		<div className="spst-table-scroll" role="region" aria-label={label} tabIndex={0}>
			{children}
		</div>
	);
}
