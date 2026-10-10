/**
 * A page's Edit link. It opens the editor in a new tab, so the report
 * stays where it was.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';

export function EditLink({ href, path, children }: Readonly<{ href: string; path: string; children?: ReactNode }>) {
	return (
		<a
			href={href}
			target="_blank"
			rel="noopener noreferrer"
			aria-label={sprintf(/* translators: %s: page path or title. */ __('Edit %s (opens in a new tab)', 'seoprostats'), path)}
		>
			{children ?? (
				<>
					<span className="dashicons dashicons-edit" aria-hidden="true" /> {__('Edit', 'seoprostats')}
				</>
			)}
		</a>
	);
}
