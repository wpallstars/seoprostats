/**
 * The view state, kept in the URL hash (@seoprostats/core state.ts).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useCallback, useMemo, useSyncExternalStore } from 'react';
import { buildHash, parseHash, switchView, type ViewState } from '@seoprostats/core';
import { boot, savePeriod } from './boot';

function subscribe(onChange: () => void): () => void {
	window.addEventListener('hashchange', onChange);
	return () => window.removeEventListener('hashchange', onChange);
}

function snapshot(): string {
	return window.location.hash;
}

export function useViewState(): [ViewState, (patch: Partial<ViewState>) => void] {
	const hash = useSyncExternalStore(subscribe, snapshot);
	const state = useMemo(() => parseHash(hash, boot.period), [hash]);
	const update = useCallback((patch: Partial<ViewState>) => {
		const current = parseHash(window.location.hash, boot.period);
		// Another section starts from the shared values, without this one's choices.
		const base = patch.view ? switchView(current, patch.view) : current;
		const updated = { ...base, ...patch };
		const next = buildHash(updated);
		if (next !== window.location.hash) {
			// A new history entry, so the back button undoes it.
			window.location.hash = next;
		}
		if (patch.range !== undefined || patch.compare !== undefined) {
			// Use the current address, not picker props from before its hashchange.
			savePeriod(updated);
		}
	}, []);
	return [state, update];
}
