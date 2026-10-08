/**
 * Visits by country on a world map: each country shaded by its visits in
 * the accent colour (the 100 countries with most visits). Hover shows a
 * country's visits; choosing one filters the view by it, as the Locations
 * card's rows do, and choosing it again takes the filter out. The map is
 * drawn for sight: the Locations card lists the same countries for screen
 * readers and keyboards.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useMemo, useState, type MouseEvent } from 'react';
import { Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { mapShades, WORLD_HEIGHT, WORLD_SHAPES, WORLD_WIDTH } from '@seoprostats/charts';
import { formatNumber, formatPercent, hasFilterValue, toggleFilterValue, type ViewState } from '@seoprostats/core';
import { errorMessage, shareAccess, useBreakdown } from '../api';
import { locale } from '../boot';
import { valueLabel } from '../labels';

/** Most countries a breakdown answers with. */
const COUNTRIES = 100;

interface Props {
	state: ViewState;
	update: (patch: Partial<ViewState>) => void;
}

interface Tip {
	code: string;
	x: number;
	y: number;
}

export function WorldMap({ state, update }: Props) {
	const hidden = shareAccess.hidden.includes('country');
	const query = useBreakdown(state, 'country', COUNTRIES);
	const [tip, setTip] = useState<Tip | null>(null);
	const rows = query.data?.rows;
	const byCode = useMemo(() => new Map((rows ?? []).map((row) => [row.value, row])), [rows]);
	const shades = useMemo(() => mapShades(Object.fromEntries((rows ?? []).map((row) => [row.value, row.visits]))), [rows]);
	const max = Math.max(0, ...(rows ?? []).map((row) => row.visits));

	if (hidden) {
		return null;
	}

	const name = (code: string) => valueLabel('country', code, byCode.get(code)?.label ?? code);
	const move = (code: string) => (event: MouseEvent<SVGPathElement>) => {
		const box = event.currentTarget.ownerSVGElement?.parentElement?.getBoundingClientRect();
		if (box) {
			setTip({ code, x: event.clientX - box.left, y: event.clientY - box.top });
		}
	};
	const tipRow = tip ? byCode.get(tip.code) : undefined;

	return (
		<Card className="spst-card spst-map" size="small">
			<CardHeader className="spst-card__header">
				<h2 className="spst-card__title">{__('Map', 'seoprostats')}</h2>
			</CardHeader>
			<CardBody className="spst-card__body">
				{query.isError ? (
					<Notice status="error" isDismissible={false}>
						{errorMessage(query.error, __('The map could not be loaded.', 'seoprostats'))}
					</Notice>
				) : (
					<>
						<div className={`spst-map__plot${query.isFetching && rows ? ' is-refreshing' : ''}`} onMouseLeave={() => setTip(null)}>
							<svg
								viewBox={`0 0 ${WORLD_WIDTH} ${WORLD_HEIGHT}`}
								role="img"
								aria-label={__('World map of visits by country. The Locations list gives the same figures.', 'seoprostats')}
							>
								{Object.entries(WORLD_SHAPES).map(([code, d]) => {
									const shade = shades[code];
									const active = hasFilterValue(state.filters, 'country', code);
									return (
										<path
											key={code}
											d={d}
											className={`spst-map__country${shade ? ' has-visits' : ''}${active ? ' is-active' : ''}`}
											style={shade ? { fillOpacity: shade } : undefined}
											onMouseMove={move(code)}
											onClick={shade || active ? () => update({ filters: toggleFilterValue(state.filters, 'country', code) }) : undefined}
										/>
									);
								})}
							</svg>
							{tip && (
								<div className="spst-chart-tip spst-map__tip" style={{ left: tip.x, top: tip.y }} aria-hidden="true">
									<strong>{name(tip.code)}</strong>
									<br />
									{tipRow
										? sprintf(
												/* translators: 1: number of visits, 2: their share of all visits, e.g. "12%". */
												__('%1$s visits · %2$s', 'seoprostats'),
												formatNumber(tipRow.visits, locale),
												formatPercent(tipRow.share, locale)
											)
										: __('No visits', 'seoprostats')}
								</div>
							)}
						</div>
						{max > 0 && (
							<div className="spst-map__legend" aria-hidden="true">
								<span>{formatNumber(1, locale)}</span>
								<span className="spst-map__scale" />
								<span>{formatNumber(max, locale)}</span>
							</div>
						)}
						{rows && !rows.length && <p className="spst-empty">{__('Nothing in this period.', 'seoprostats')}</p>}
					</>
				)}
			</CardBody>
		</Card>
	);
}
