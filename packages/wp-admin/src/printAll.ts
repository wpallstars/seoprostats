/**
 * Printing a shared report: each panel shows all of its tabs, one after
 * another, instead of the one chosen on screen (paper cannot switch tabs).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { createContext, useContext } from 'react';

export const PrintAll = createContext(false);

export function usePrintAll(): boolean {
	return useContext(PrintAll);
}
