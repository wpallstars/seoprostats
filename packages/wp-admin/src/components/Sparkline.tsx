/**
 * A small trend line (decorative: the numbers next to it carry the meaning).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { sparklinePaths } from '@seoprostats/charts';

interface Props {
	values: number[];
	width?: number;
	height?: number;
}

export function Sparkline({ values, width = 96, height = 28 }: Props) {
	if (values.length < 2) {
		return null;
	}
	const { line, area } = sparklinePaths(values, width, height);
	return (
		<svg className="spst-spark" width={width} height={height} viewBox={`0 0 ${width} ${height}`} aria-hidden="true" focusable="false">
			<path className="spst-spark__area" d={area} />
			<path className="spst-spark__line" d={line} />
		</svg>
	);
}
