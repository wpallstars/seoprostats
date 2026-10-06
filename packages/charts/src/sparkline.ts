/**
 * Sparkline as an SVG path: no library, for tiles, widgets and popups.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

export interface SparklinePaths {
	/** The line. */
	line: string;
	/** The line closed along the bottom, for a shaded area. */
	area: string;
}

/** Paths for values drawn into a width × height box, zero at the bottom. */
export function sparklinePaths(values: number[], width: number, height: number, pad = 1.5): SparklinePaths {
	if (values.length === 0) {
		return { line: '', area: '' };
	}
	const max = Math.max(...values, 0) || 1;
	const step = values.length > 1 ? (width - pad * 2) / (values.length - 1) : 0;
	const points = values.map((v, i) => {
		const x = pad + i * step;
		const y = height - pad - (Math.max(0, v) / max) * (height - pad * 2);
		return `${x.toFixed(1)},${y.toFixed(1)}`;
	});
	const line = `M${points.join('L')}`;
	const last = pad + (values.length - 1) * step;
	return { line, area: `${line}L${last.toFixed(1)},${height}L${pad},${height}Z` };
}
