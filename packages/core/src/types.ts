/**
 * Answers of the SEO Pro Stats API, as docs/api/openapi.yaml describes
 * them. Keep the two in step.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

export const RANGE_KEYS = [
	'realtime',
	'today',
	'yesterday',
	'24h',
	'7d',
	'30d',
	'90d',
	'week',
	'month',
	'year',
	'12mo',
	'lastyear',
	'all',
	'custom',
] as const;
export type RangeKey = (typeof RANGE_KEYS)[number];

export const COMPARE_KEYS = ['none', 'prev', 'year'] as const;
export type CompareKey = (typeof COMPARE_KEYS)[number];

export const DIMENSIONS = [
	'channel',
	'source',
	'utm_source',
	'utm_medium',
	'utm_campaign',
	'utm_term',
	'utm_content',
	'country',
	'device',
	'browser',
	'os',
	'language',
	'entry',
	'exit',
	'page',
	'event',
] as const;
export type Dimension = (typeof DIMENSIONS)[number];

export const OPERATORS = ['is', 'is_not', 'contains', 'matches'] as const;
export type Operator = (typeof OPERATORS)[number];

export type Grain = 'hour' | 'day' | 'month';

export interface Range {
	key: string;
	from: string;
	to: string;
	timezone: string;
}

export interface Metrics {
	visitors: number;
	visits: number;
	pageviews: number;
	views_per_visit: number;
	bounce_rate: number;
	visit_duration: number;
	events: number;
}

export type MetricKey = keyof Metrics;

/** (now − then) ÷ then per metric; null when then is 0. */
export type Change = Partial<Record<MetricKey, number | null>>;

export interface Answer {
	generated?: string;
	cached?: boolean;
}

export interface StatsAnswer extends Answer {
	range: Range;
	metrics: Metrics;
	compare?: { range: Range; metrics: Metrics; change: Change };
}

export interface Point extends Metrics {
	t: string;
}

export interface TimeseriesAnswer extends Answer {
	range: Range;
	grain: Grain;
	points: Point[];
	compare?: { range: Range; points: Point[] };
}

export interface BreakdownRow extends Partial<Metrics> {
	value: string;
	label: string;
	visitors: number;
	visits: number;
	share: number;
	time_on_page?: number;
	scroll?: number;
	conversion_rate?: number;
}

export interface BreakdownAnswer extends Answer {
	range: Range;
	dimension: Dimension;
	total: Metrics;
	rows: BreakdownRow[];
}

export interface Count {
	value: string;
	label: string;
	count: number;
}

export interface RealtimeAnswer {
	visitors: number;
	pageviews: number;
	per_minute: number[];
	pages: Count[];
	sources: Count[];
	processed: string | null;
	generated: string;
}

export interface Marker {
	t: string;
	kind: string;
	label: string;
	path?: string;
}

export interface MarkersAnswer {
	range: Range;
	markers: Marker[];
}

/** What a goal or funnel step matches: a page viewed or an event sent. */
export type GoalKind = 'page' | 'event';

/** A goal or funnel step: a path (`*` any text) or an event name. */
export interface GoalStep {
	name: string;
	kind: GoalKind;
	match: string;
}

export interface Goal extends GoalStep {
	id: string;
}

export interface Funnel {
	id: string;
	name: string;
	steps: GoalStep[];
}

/** Funnels have 2 to 12 steps. */
export const FUNNEL_STEPS = { min: 2, max: 12 } as const;

/** Revenue in one currency (never added across currencies). */
export interface Revenue {
	currency: string;
	/** In the currency's main unit (dollars, not cents). */
	amount: number;
	/** Events that carried it. */
	count: number;
}

export interface GoalRow extends Goal {
	visitors: number;
	/** Visits that reached the goal. */
	visits: number;
	/** Times it was reached (pageviews or events). */
	completions: number;
	/** visits ÷ all visits. */
	conversion_rate: number;
	revenue: Revenue[];
	change?: { visits: number | null; completions: number | null; conversion_rate: number | null };
}

export interface GoalsAnswer extends Answer {
	range: Range;
	visits: number;
	goals: GoalRow[];
	compare?: { range: Range; visits: number; goals: GoalRow[] };
}

export interface FunnelStepRow extends GoalStep {
	visits: number;
	/** Of the visits that started the funnel. */
	rate: number;
	/** Of the visits at the step before. */
	step_rate: number;
	/** Visits at the step before that did not reach this one. */
	dropped: number;
}

export interface FunnelRow {
	id: string;
	name: string;
	entered: number;
	completed: number;
	/** completed ÷ entered. */
	completion_rate: number;
	/** completed ÷ all visits. */
	conversion_rate: number;
	steps: FunnelStepRow[];
	change?: { entered: number | null; completed: number | null; conversion_rate: number | null };
}

export interface FunnelsAnswer extends Answer {
	range: Range;
	visits: number;
	funnels: FunnelRow[];
	compare?: { range: Range; visits: number; funnels: FunnelRow[] };
}

export interface PropertyRow {
	value: string;
	label: string;
	/** Events and pageviews that carried it. */
	count: number;
	visits: number;
	share: number;
	revenue: Revenue[];
}

export interface PropertiesAnswer extends Answer {
	range: Range;
	/** The key whose values these are; '' when the rows are keys. */
	key: string;
	event: string;
	visits: number;
	rows: PropertyRow[];
}

/** Rows of the clicks report. */
export const CLICK_KINDS = ['elements', 'dead', 'links', 'downloads', 'forms'] as const;
export type ClickKind = (typeof CLICK_KINDS)[number];

export interface ClickTotals {
	/** Clicks (form submits are counted apart). */
	clicks: number;
	/** Clicks the page did not react to within a second. */
	dead: number;
	/** dead ÷ clicks. */
	dead_rate: number;
	/** Clicks on links and buttons with a destination (here or elsewhere). */
	links: number;
	outbound: number;
	affiliate: number;
	downloads: number;
	/** Forms sent. */
	forms: number;
	/** Visits with a click or form submit. */
	visits: number;
}

export interface ClickRow {
	/** tag#id.class of the element or form. */
	selector: string;
	/** Its text (a form's name); '' when hidden or empty. */
	label: string;
	/** Link or form destination: a path here, origin and path elsewhere, mailto: or tel:. */
	target: string;
	count: number;
	visits: number;
	/** count ÷ all clicks (forms: all forms sent). */
	share: number;
	dead: number;
	dead_rate: number;
	outbound: boolean;
	affiliate: boolean;
	download: boolean;
	/** A form's fields (never their values). */
	fields: number;
}

export interface ClicksAnswer extends Answer {
	range: Range;
	kind: ClickKind;
	page: string;
	totals: ClickTotals;
	rows: ClickRow[];
	compare?: { range: Range; totals: ClickTotals; change: Record<keyof ClickTotals, number | null> };
}

export interface ApiError {
	code: string;
	message: string;
	data?: { status?: number };
}
