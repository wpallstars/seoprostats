/**
 * The view state, kept in the URL hash (@seoprostats/core state.ts).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useCallback, useMemo, useSyncExternalStore } from 'react';
import { buildHash, parseHash, type ViewState } from '@seoprostats/core';

function subscribe(onChange: () => void): () => void {
	window.addEventListener('hashchange', onChange);
	return () => window.removeEventListener('hashchange', onChange);
}

function snapshot(): string {
	return window.location.hash;
}

export function useViewState(): [ViewState, (patch: Partial<ViewState>) => void] {
	const hash = useSyncExternalStore(subscribe, snapshot);
	const state = useMemo(() => parseHash(hash), [hash]);
	const update = useCallback((patch: Partial<ViewState>) => {
		const next = buildHash({ ...parseHash(window.location.hash), ...patch });
		if (next !== window.location.hash) {
			// A new history entry, so the back button undoes it.
			window.location.hash = next;
		}
	}, []);
	return [state, update];
}
