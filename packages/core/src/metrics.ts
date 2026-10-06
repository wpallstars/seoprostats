/**
 * How each metric is shown and which way is better. Labels belong to each
 * app (translated there); definitions: docs/architecture.md → Reports.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { MetricKey } from './types';

export type MetricFormat = 'number' | 'decimal' | 'percent' | 'duration';

export interface MetricSpec {
	key: MetricKey;
	format: MetricFormat;
	/** up: a rise is good news; down: a fall is. */
	better: 'up' | 'down';
}

export const METRICS: Record<MetricKey, MetricSpec> = {
	visitors: { key: 'visitors', format: 'number', better: 'up' },
	visits: { key: 'visits', format: 'number', better: 'up' },
	pageviews: { key: 'pageviews', format: 'number', better: 'up' },
	views_per_visit: { key: 'views_per_visit', format: 'decimal', better: 'up' },
	bounce_rate: { key: 'bounce_rate', format: 'percent', better: 'down' },
	visit_duration: { key: 'visit_duration', format: 'duration', better: 'up' },
	events: { key: 'events', format: 'number', better: 'up' },
};

/** Metrics the Overview shows as tiles and can chart, in order. */
export const CHART_METRICS: readonly MetricKey[] = [
	'visitors',
	'visits',
	'pageviews',
	'views_per_visit',
	'bounce_rate',
	'visit_duration',
];
