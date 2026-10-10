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
		<section className="spst-table-scroll" aria-label={label} tabIndex={0}>{/* NOSONAR: a scrolling region must take focus so the keyboard can scroll it (WCAG 2.1.1). */}
			{children}
		</section>
	);
}
