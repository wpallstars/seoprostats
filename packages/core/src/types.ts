/**
 * Answers of the SEO Pro Stats API, as docs/api/openapi.yaml describes
 * them. Keep the two in step.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

export const RANGE_KEYS = ['realtime', 'today', 'yesterday', '24h', '7d', '30d', '90d', 'week', 'month', 'year', '12mo', 'lastyear', 'all', 'custom'] as const;
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
	'login',
	'entry',
	'exit',
	'page',
	'not_found',
	'search',
	'no_results',
	'author',
	'category',
	'post_type',
	'event',
] as const;
export type Dimension = (typeof DIMENSIONS)[number];

export const OPERATORS = ['is', 'is_not', 'contains', 'matches'] as const;
export type Operator = (typeof OPERATORS)[number];

/** A chart point's span; week only for search data that comes by week (Bing). */
export type Grain = 'hour' | 'day' | 'week' | 'month';

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

/**
 * Groups of changes in the change log; search: search engine updates
 * (from feeds); note: added by a person or an agent.
 */
export const CHANGE_GROUPS = ['content', 'seo', 'product', 'site', 'search', 'note'] as const;
export type ChangeGroup = (typeof CHANGE_GROUPS)[number];

/** Where a change was made. */
export type ChangeSource = 'wordpress' | 'cli' | 'api' | 'cron' | 'feed' | 'note';

/** One change to the site (an entry of the change log), as a chart marker. */
export interface Marker {
	id: number;
	/** When it changed (ISO, site time zone). */
	t: string;
	/** What changed: published, price_down, plugin_updated, search_update… */
	kind: string;
	group: ChangeGroup;
	/** One line saying what changed, in the site's language. */
	label: string;
	/** The post, product, plugin, theme or setting, by name. */
	title: string;
	/** The page it affects; null for site-wide changes. */
	path: string | null;
	/** Before; for search_update, its type (core, spam, reviews…). */
	old: string;
	/** After; for search_update, its id at the source. */
	new: string;
	/** For search_update, type is the engine (google, or a feed's name). */
	object: { type: string; id: number };
	/**
	 * Details; for search_update: name, engine, url, and ended (ISO; ''
	 * while rolling out) when it rolls out over a span.
	 */
	meta: Record<string, unknown>;
	source: ChangeSource;
	/** Who made it, for people who may list users. */
	user: string | null;
}

export interface MarkersAnswer {
	range: Range;
	markers: Marker[];
	total: number;
}

export interface ChangesAnswer {
	range: Range;
	changes: Marker[];
	total: number;
	limit: number;
	offset: number;
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
	change?: {
		visits: number | null;
		completions: number | null;
		conversion_rate: number | null;
	};
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
	change?: {
		entered: number | null;
		completed: number | null;
		conversion_rate: number | null;
	};
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
export const CLICK_KINDS = ['elements', 'dead', 'links', 'downloads', 'forms', 'pages'] as const;
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

export interface ClickPageInfo {
	path: string;
	url: string;
	/** Zero when the path is not a post on this site. */
	post_id: number;
	/** Omitted by shared read-only interfaces; null without edit permission. */
	edit_url?: string | null;
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
	/** Present only for pages rows. */
	path?: string;
	url?: string;
	post_id?: number;
	edit_url?: string | null;
	links?: number;
	forms?: number;
}

export interface ClicksAnswer extends Answer {
	range: Range;
	kind: ClickKind;
	page: string;
	page_info: ClickPageInfo | null;
	totals: ClickTotals;
	rows: ClickRow[];
	compare?: {
		range: Range;
		totals: ClickTotals;
		change: Record<keyof ClickTotals, number | null>;
	};
}

/** What a chart draws: the range, grain and points (each with its start, t). */
export interface ChartData<P extends { t: string } = { t: string }> {
	range: Range;
	grain: Grain;
	points: P[];
	compare?: { range: Range; points: P[] };
	generated?: string;
}

/** Rows of the search report. */
export const SEARCH_KINDS = ['queries', 'pages', 'countries', 'devices'] as const;
export type SearchKind = (typeof SEARCH_KINDS)[number];

/** The Search section's reports; the first is the default. */
export const SEARCH_REPORTS = ['rankings', 'opportunities', 'content', 'experiments'] as const;
export type SearchReport = (typeof SEARCH_REPORTS)[number];

/**
 * Search engines with imported data; the first is the default. Bing gives
 * pages and queries by week (stored on each week's last day) and no
 * countries or devices.
 */
export const SEARCH_ENGINES = ['google', 'bing'] as const;
export type SearchEngine = (typeof SEARCH_ENGINES)[number];

/** What every search answer says about its engine. */
export interface SearchEngineAnswer {
	engine: SearchEngine;
	/** Engines with search data or connected; Google always. */
	engines: SearchEngine[];
}

export interface SearchMetrics {
	clicks: number;
	impressions: number;
	/** clicks ÷ impressions. */
	ctr: number;
	/** Average position, weighted by impressions; 0 without impressions. Lower is better. */
	position: number;
}

export type SearchMetricKey = keyof SearchMetrics;

/** Relative change for clicks, impressions and CTR; position: now − then in places (lower is better). */
export type SearchChange = Record<SearchMetricKey, number | null>;

export interface SearchPoint extends SearchMetrics {
	t: string;
}

export interface SearchRow extends SearchMetrics {
	/** Query or page dictionary ID, country code (alpha-3) or device code. */
	id: string;
	/** The query, page path, country (alpha-2; '' unknown) or device. */
	value: string;
	label: string;
	/** clicks ÷ all clicks. */
	share: number;
	/** Present only for pages rows. */
	path?: string;
	url?: string;
	post_id?: number;
	edit_url?: string | null;
	compare?: SearchMetrics & { change: SearchChange };
}

export interface SearchAnswer extends Answer, SearchEngineAnswer {
	/** The range cut at the newest day with search data. */
	range: Range;
	/** Newest and first day with search data (YYYY-MM-DD); '' with none. */
	through: string;
	first: string;
	/** Whether the engine's source is connected (demo data: always). */
	connected: boolean;
	kind: SearchKind;
	page: string;
	query: string;
	page_info: ClickPageInfo | null;
	/** Filter dimensions left out: only page filters apply to search data. */
	ignored: string[];
	totals: SearchMetrics;
	/** week: a page or query of an engine that gives them by week. */
	grain: 'day' | 'week' | 'month';
	points: SearchPoint[];
	rows: SearchRow[];
	more: boolean;
	compare?: {
		range: Range;
		totals: SearchMetrics;
		change: SearchChange;
		points: SearchPoint[];
	};
}

/** Kinds of search opportunity. */
export const OPPORTUNITY_KINDS = ['striking', 'ctr', 'decay', 'missing'] as const;
export type OpportunityKind = (typeof OPPORTUNITY_KINDS)[number];

/** Likely cause of a page losing clicks. */
export type DecayCause = 'position' | 'demand' | 'ctr' | 'gone';

/** A page of an opportunity, with links. */
export interface OpportunityPage {
	path_id: number;
	path: string;
	url: string;
	post_id: number;
	edit_url: string | null;
}

/** A page's query: striking distance or low CTR. */
export interface OpportunityPair extends OpportunityPage, SearchMetrics {
	query: string;
	/** CTR aimed for: the site's at position 3 (striking) or at its position (ctr). */
	expected_ctr: number;
	/** Clicks it could gain (striking) or misses (ctr) in the period. */
	potential: number;
}

/** A page losing clicks. */
export interface OpportunityDecay extends OpportunityPage, SearchMetrics {
	compare: SearchMetrics & { change: SearchChange };
	lost: number;
	cause: DecayCause;
	/** One sentence saying why, in the site's language. */
	why: string;
	/** The queries that lost most. */
	queries: { query: string; lost: number; clicks: number; then_clicks: number; position: number | null; then_position: number | null }[];
	/** What changed on the page in the two periods, newest first. */
	changes: Marker[];
}

/** How far a page's words cover a query, best first (coverage.ts). */
export const COVERAGE_MATCHES = ['title', 'heading', 'text', 'partial', 'none'] as const;
export type CoverageMatch = (typeof COVERAGE_MATCHES)[number];

export interface CoverageResult {
	match: CoverageMatch;
	/** The query's words, in order, are on the page. */
	phrase: boolean;
	/** The query's words the page does not have. */
	missing: string[];
	question: boolean;
}

/** A page's query its words do not cover, or only partly. */
export interface OpportunityMissing extends OpportunityPage, SearchMetrics, CoverageResult {
	query: string;
}

export interface OpportunitiesAnswer extends Answer, SearchEngineAnswer {
	kind: OpportunityKind;
	/** The period read: cut at the newest search day and to its newest 91 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	rules: Record<string, number>;
	rows: (OpportunityPair | OpportunityDecay | OpportunityMissing)[];
	total: number;
	more: boolean;
	/** striking and ctr: the site's CTR by position (1–20). */
	curve?: { source: 'site' | 'mixed' | 'default'; ctr: Record<string, number> } | null;
	/** decay: the earlier period, and search engine updates in either. */
	compare?: { range: Range } | null;
	updates?: Marker[];
}

/** A query of the coverage report. */
export interface CoverageRow extends SearchMetrics, CoverageResult {
	query: string;
}

/** A focus keyword set in an SEO plugin, with its search figures when it is a query of the page. */
export interface CoverageFocus extends CoverageResult {
	keyword: string;
	/** rank-math, yoast, seopress, aioseo, demo, or another plugin's name. */
	source: string;
	searched: boolean;
	clicks: number;
	impressions: number;
	position: number | null;
}

export interface CoverageAnswer extends Answer {
	page: string;
	post_id: number;
	page_info: ClickPageInfo | null;
	/** The period read: cut at the newest search day and to its newest 91 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	connected: boolean;
	/** What the page's words were read from; null when it is not one post. */
	text: {
		source: 'post' | 'demo';
		words: number;
		/** The SEO plugin read; '' for none. */
		plugin: string;
		seo_title: string;
		description: string;
	} | null;
	focus: CoverageFocus[];
	totals: {
		queries: number;
		impressions: number;
		clicks: number;
		/** Share of impressions on queries the page covers. */
		covered: number;
		/** Queries covered partly or not at all. */
		missing: number;
		questions: number;
	};
	/** Most impressions first, up to 200. */
	rows: CoverageRow[];
	more: boolean;
}

/** Orders of the content report, most first. */
export const CONTENT_SORTS = ['clicks', 'visits', 'conversions'] as const;
export type ContentSort = (typeof CONTENT_SORTS)[number];

/** A page's search figures with its visits from search and their conversions of the goal. */
export interface ContentMetrics extends SearchMetrics {
	/** Visits from organic search (any engine) that started on the page. */
	visits: number;
	bounce_rate: number;
	views_per_visit: number;
	/** Seconds. */
	visit_duration: number;
	/** Visits that reached the goal; null without goals. */
	conversions: number | null;
	conversion_rate: number | null;
}

export type ContentChange = Partial<Record<keyof ContentMetrics, number | null>>;

export interface ContentRow extends ContentMetrics, OpportunityPage {
	id: string;
	value: string;
	label: string;
	compare?: ContentMetrics & { change: ContentChange };
}

export interface ContentAnswer extends Answer, SearchEngineAnswer {
	/** The range cut at the newest day with search data. */
	range: Range;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	sort: ContentSort;
	/** The goal counted (the first unless one is chosen); null without goals. */
	goal: { id: string; name: string; kind: string; match: string } | null;
	goals: { id: string; name: string }[];
	/** Oldest day whose visits from search are summarised; '' before the first. */
	landings_from: string;
	/** Whether some days of the period (or comparison) are not summarised yet. */
	partial: boolean;
	totals: ContentMetrics;
	rows: ContentRow[];
	total: number;
	more: boolean;
	compare?: { range: Range; totals: ContentMetrics; change: ContentChange };
}

/** What an experiment should change. */
export const EXPERIMENT_METRICS = ['clicks', 'impressions', 'ctr', 'position', 'visits', 'conversions'] as const;
export type ExperimentMetric = (typeof EXPERIMENT_METRICS)[number];

/** Days in each window, before and after: whole weeks. */
export const EXPERIMENT_WINDOWS = [7, 14, 28, 56, 84] as const;

/** A person's or agent's decision; the plugin suggests one too. */
export const EXPERIMENT_RESULTS = ['keep', 'revise', 'undo', 'inconclusive'] as const;
export type ExperimentResult = (typeof EXPERIMENT_RESULTS)[number];

export type ExperimentStatus = 'running' | 'decided' | 'cancelled';

/** Days, both included (site dates). */
export interface DayWindow {
	from: string;
	to: string;
}

/** Some pages' figures in one window: search figures, or visits (and conversions of a goal). */
export interface ExperimentFigures {
	clicks?: number;
	impressions?: number;
	ctr?: number | null;
	position?: number | null;
	visits?: number;
	conversions?: number;
	rate?: number | null;
}

/** Why the plugin suggests its result. */
export type ExperimentReason =
	| 'no_group'
	| 'search_update'
	| 'too_little_data'
	| 'no_measure'
	| 'within_noise'
	| 'at_threshold'
	| 'under_threshold'
	| 'opposite'
	| 'no_change';

export interface ExperimentRunning {
	state: 'running';
	through: string | null;
	review: string;
	days: number;
	/** Days of data after the change so far. */
	so_far: number;
	windows: { before: DayWindow; after: DayWindow };
}

export interface ExperimentMeasured {
	state: 'ready';
	through: string;
	review: string;
	days: number;
	windows: { before: DayWindow; after: DayWindow };
	/** Days with search data in each window (search measures). */
	coverage: { before: number; after: number; days: number } | null;
	pages: { count: number; before: ExperimentFigures; after: ExperimentFigures };
	group: { count: number; before: ExperimentFigures; after: ExperimentFigures; change: number | null };
	/** Whether there were enough unchanged pages to compare with. */
	compared: boolean;
	value: { before: number | null; after: number | null };
	/** Raw change: a ratio (0.12 is +12%), or places for position. */
	change: number | null;
	/** Against the group (raw without one). */
	effect: number | null;
	/** The effect in the expected direction: positive when it went the way expected. */
	improvement: number | null;
	unit: 'ratio' | 'places';
	/** The group's usual spread (10th to 90th percentile of its pages' effects). */
	noise: { low: number; high: number; pages: number } | null;
	beyond_noise: boolean | null;
	enough: { ok: boolean; unit: string; needed: number; before: number; after: number };
	confounders: { updates: Marker[]; site: Marker[]; pages: Marker[]; total: number };
	suggested: ExperimentResult;
	reasons: ExperimentReason[];
	/** One sentence saying what was found. */
	summary: string;
}

export interface Experiment {
	id: number;
	name: string;
	hypothesis: string;
	note: string;
	created: string;
	user: string | null;
	/** The change's time (ISO, site time zone). */
	start: string;
	days: number;
	windows: { before: DayWindow; after: DayWindow };
	/** The day the after window's data is complete. */
	review: string;
	engine: SearchEngine;
	metric: ExperimentMetric;
	direction: 'up' | 'down';
	/** Percent, or places for position. */
	threshold: number;
	change_id: number | null;
	pages: string[];
	goal: { id: string; name: string | null } | null;
	status: ExperimentStatus;
	result: ExperimentResult | null;
	decided: string | null;
	/** Running, with data through the review day. */
	due: boolean;
	through: string | null;
	/** While running, now; once decided, as it was at the decision; null when cancelled. */
	measurement: ExperimentRunning | ExperimentMeasured | null;
}

export interface ExperimentsAnswer {
	experiments: Experiment[];
	total: number;
}

/** A new experiment: from a change (its time and page), or a start and pages. */
export interface ExperimentInput {
	name: string;
	change?: number;
	start?: string;
	page?: string;
	days: number;
	engine: SearchEngine;
	metric: ExperimentMetric;
	direction: 'up' | 'down';
	threshold?: number;
	goal?: string;
	hypothesis?: string;
}

export interface ApiError {
	code: string;
	message: string;
	data?: { status?: number };
}
