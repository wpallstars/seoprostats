/**
 * World map: country shapes (./world-shapes) and how strongly each country
 * is shaded for a count, so any app can draw the same map as SVG.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

export { WORLD_HEIGHT, WORLD_SHAPES, WORLD_WIDTH } from './world-shapes';

/**
 * Lightest shade a country with any count gets, so one visit still shows
 * apart from a country with none, on light and dark surfaces.
 */
export const MAP_MIN_SHADE = 0.3;

/**
 * Each country's shade from 0 (none) to 1 (the most): a square-root scale,
 * so a few big countries do not wash out the rest; any count is at least
 * MAP_MIN_SHADE.
 */
export function mapShades(counts: Readonly<Record<string, number>>): Record<string, number> {
	const max = Math.max(0, ...Object.values(counts));
	const out: Record<string, number> = {};
	if (max <= 0) {
		return out;
	}
	for (const [code, count] of Object.entries(counts)) {
		if (count > 0) {
			out[code] = MAP_MIN_SHADE + (1 - MAP_MIN_SHADE) * Math.sqrt(count / max);
		}
	}
	return out;
}
