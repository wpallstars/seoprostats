/**
 * The data set on screen: live statistics or the demo data
 * (SEOProStats_Demo). Each person's choice is saved (POST /view), so the
 * Overview and the Dashboard widget agree.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useSyncExternalStore } from 'react';
import { boot, type DataSet } from './boot';

let current: DataSet = boot.data;
const listeners = new Set<() => void>();

function subscribe(onChange: () => void): () => void {
	listeners.add(onChange);
	return () => {
		listeners.delete(onChange);
	};
}

export function getDataSet(): DataSet {
	return current;
}

/** Show another data set (the caller saves the choice). */
export function setDataSet(next: DataSet): void {
	if (next !== current) {
		current = next;
		listeners.forEach((listener) => listener());
	}
}

export function useDataSet(): DataSet {
	return useSyncExternalStore(subscribe, getDataSet);
}
