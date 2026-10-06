/**
 * Mount a component with the shared query client.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { ReactNode } from 'react';
import { createRoot } from '@wordpress/element';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from './api';

export function mount(id: string, node: ReactNode): void {
	const start = () => {
		const el = document.getElementById(id);
		if (!el) {
			return;
		}
		createRoot(el).render(<QueryClientProvider client={queryClient}>{node}</QueryClientProvider>);
	};
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
}
