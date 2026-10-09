/**
 * Answers of the SEO Pro Stats API, as docs/api/openapi.yaml describes
 * them. Keep the two in step.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

export type VitalMetric = 'lcp' | 'inp' | 'cls' | 'fcp' | 'ttfb';
export type VitalSample = {
	day: string;
	form_factor: 'PHONE' | 'DESKTOP';
	metric: VitalMetric;
	p75: number;
	good: number;
	ni: number;
	poor: number;
	status: 'good' | 'needs_improvement' | 'poor';
	stale: boolean;
};
export type VitalsAnswer = {
	connected: boolean;
	source: 'crux';
	window_days: number;
	traffic_days: number;
	origin: VitalSample[];
	series: VitalSample[];
	rows: { path_id: number; path: string; clicks: number; visits: number; samples: VitalSample[]; available: boolean; failing: boolean }[];
};
export type LighthouseAnswer = { source: 'lighthouse'; url: string; score: number | null; opportunities: { id: string; title: string; display: string; saved_ms: number }[] };

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
	/** A/B test variants seen: value "test-id:variant-slug" ("test-id:*" filters every variant of a test). */
	'variant',
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
	/** Variants: clicks inside the variant. */
	clicks?: number;
	/** Pages: the page has a running A/B test. */
	ab_test?: boolean;
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
	/** Site-wide daily counters; unavailable for visit-filtered, demo or sub-day requests. */
	renewals?: {
		scope: 'site' | 'unavailable';
		days: (Revenue & { day: string })[];
		totals: Revenue[];
	};
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
export const SEARCH_KINDS = ['queries', 'pages', 'countries', 'devices', 'appearance', 'days'] as const;
export type SearchKind = (typeof SEARCH_KINDS)[number];

/** The kinds every engine, page and query has; the others are Google's whole site only. */
export const SEARCH_ANY_KINDS: readonly SearchKind[] = ['queries', 'pages', 'days'];

/** The Search section's reports; the first is the default. */
export const SEARCH_REPORTS = ['rankings', 'opportunities', 'audit', 'content', 'backlinks', 'targets', 'plan', 'experiments'] as const;
export type SearchReport = (typeof SEARCH_REPORTS)[number];

/**
 * Search engines with imported data; the first is the default. Bing gives
 * pages and queries by week (stored on each week's last day) and no
 * countries or devices.
 */
export const SEARCH_ENGINES = ['google', 'bing'] as const;
export type SearchEngine = (typeof SEARCH_ENGINES)[number];

/**
 * The engine choices: an engine, or `all` (Combined), every engine with
 * data added up. Combined is for Rankings, Opportunities and Content
 * (COMBINED_REPORTS); it has no countries or devices, and with an engine
 * by week its period is whole weeks.
 */
export const SEARCH_ENGINE_CHOICES = [...SEARCH_ENGINES, 'all'] as const;
export type SearchEngineChoice = (typeof SEARCH_ENGINE_CHOICES)[number];

/** The Search reports that can show Combined; the others show one engine (Google for Combined). */
export const COMBINED_REPORTS = ['rankings', 'opportunities', 'content'] as const;

/** The one engine a report of one engine reads: Google for Combined. */
export function singleEngine(engine: SearchEngineChoice | undefined): SearchEngine {
	return engine && engine !== 'all' ? engine : 'google';
}

/** What every search answer says about its engine. */
export interface SearchEngineAnswer<E extends SearchEngineChoice = SearchEngine> {
	engine: E;
	/** Engines with search data or connected; Google always. */
	engines: SearchEngine[];
	/** Rankings, Opportunities and Content: whether Combined adds anything up (two or more engines with data). */
	combined?: boolean;
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
	/** Query or page dictionary ID, country code (alpha-3), device code, appearance or a days row's first day. */
	id: string;
	/** The query, page path, country (alpha-2; '' unknown), device, appearance or a days row's first day. */
	value: string;
	label: string;
	/** clicks ÷ all clicks. */
	share: number;
	/** Present only for days rows: the days covered (YYYY-MM-DD, both included), as the chart's grain. */
	from?: string;
	to?: string;
	/** Present only for pages rows. */
	path?: string;
	url?: string;
	post_id?: number;
	edit_url?: string | null;
	compare?: SearchMetrics & { change: SearchChange };
}

export interface SearchAnswer extends Answer, SearchEngineAnswer<SearchEngineChoice> {
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
export const OPPORTUNITY_KINDS = ['striking', 'ctr', 'decay', 'missing', 'overlap'] as const;
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

/** Another page of the site that overtook a losing page for a query: its figures now, its position before (null: not shown then) and its share of the query's impressions. */
export interface DecayRival extends OpportunityPage {
	clicks: number;
	impressions: number;
	position: number;
	then_position: number | null;
	share: number;
}

/** A page losing clicks. */
export interface OpportunityDecay extends OpportunityPage, SearchMetrics {
	compare: SearchMetrics & { change: SearchChange };
	lost: number;
	cause: DecayCause;
	/** One sentence saying why, in the site's language. */
	why: string;
	/** The queries that lost most, each with the other page that overtook this one there (ranks better now, did not before), if one did. */
	queries: {
		query: string;
		lost: number;
		clicks: number;
		then_clicks: number;
		position: number | null;
		then_position: number | null;
		rival: DecayRival | null;
	}[];
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

/** A page sharing a query: its figures and share of the query's impressions. */
export interface OverlapPage extends OpportunityPage, SearchMetrics {
	share: number;
}

/**
 * A query shared by pages (a candidate to review): the leading page's
 * fields, with the query's figures over all its pages.
 */
export interface OpportunityOverlap extends OpportunityPage, SearchMetrics {
	query: string;
	/** Most impressions first; at most rules.pages. */
	pages: OverlapPage[];
	/** Pages with the share or more, including any not listed. */
	page_count: number;
	/** The page with most impressions in the first and second half of the period. */
	leaders: [string | null, string | null];
	/** Whether the page with most impressions changed between the halves. */
	switched: boolean;
	/** Clicks it would have if all its pages' impressions had the best of their CTRs. */
	potential: number;
}

export interface OpportunitiesAnswer extends Answer, SearchEngineAnswer<SearchEngineChoice> {
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
	rows: (OpportunityPair | OpportunityDecay | OpportunityMissing | OpportunityOverlap)[];
	total: number;
	more: boolean;
	/** overlap: the two halves of the period; null when too short to halve. */
	halves?: [Range, Range] | null;
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

export interface ContentAnswer extends Answer, SearchEngineAnswer<SearchEngineChoice> {
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

/** An A/B test's state (set in the block editor). */
export const AB_TEST_STATUSES = ['draft', 'running', 'paused', 'ended'] as const;
export type AbTestStatus = (typeof AB_TEST_STATUSES)[number];

/**
 * A comparison's verdict: for a variant (control, too_early, better,
 * worse, unclear) and for a test (no_data, too_early, winner, control,
 * unclear).
 */
export type AbVariantVerdict = 'control' | 'too_early' | 'better' | 'worse' | 'unclear';
export type AbTestVerdictCode = 'no_data' | 'too_early' | 'winner' | 'control' | 'unclear';

/** The minimum sample before a call, and the probability that calls it. */
export interface AbThresholds {
	/** Visits each side of a comparison needs. */
	visits: number;
	/** Conversions the two sides need together. */
	conversions: number;
	/** Days the test has run. */
	days: number;
	/** Probability to beat the control that calls a variant better (1 − this: worse). */
	confidence: number;
}

/** The post a test is in. */
export interface AbTestPost {
	id: number;
	title: string;
	path: string | null;
	/** For people who may edit it. */
	edit_url: string | null;
}

/** What the variants are compared on: the test's first goal, or clicks inside the variant. */
export interface AbPrimary {
	kind: 'goal' | 'clicks';
	/** The goal's id ('' for clicks). */
	id: string;
	name: string;
}

/** A variant against the control: uplift (relative change in rate) with its 95% interval, and the probability to beat the control. All null for the control. */
export interface AbComparison {
	uplift: number | null;
	interval: [number, number] | null;
	probability: number | null;
}

export interface AbTestVerdict {
	code: AbTestVerdictCode;
	/** One sentence, in the site's language. */
	text: string;
}

/** Visits that saw two or more of a test's variants: counted apart, in no variant. */
export interface AbMixed {
	visits: number;
	/** Of every visit that saw the test. */
	share: number;
}

/** A test in the list. */
export interface AbTestRow {
	id: string;
	name: string;
	status: AbTestStatus;
	post: AbTestPost;
	/** ISO, site time zone; null before it first ran. */
	started: string | null;
	ended: string | null;
	/** When its block was taken out of the post. */
	removed: string | null;
	/** The winner chosen in the editor (a variant slug), or ''. */
	winner: string;
	primary: AbPrimary;
	visits: number;
	mixed: AbMixed;
	variants: {
		slug: string;
		label: string;
		weight: number;
		control: boolean;
		visits: number;
		conversions: number;
		rate: number;
		probability: number | null;
		verdict: AbVariantVerdict;
	}[];
	leader: { slug: string; label: string } | null;
	verdict: AbTestVerdict;
}

export interface AbTestsAnswer extends Answer {
	thresholds: AbThresholds;
	tests: AbTestRow[];
}

/** A goal's numbers for one variant. */
export interface AbGoalResult extends AbComparison {
	id: string;
	name: string;
	/** Visits that reached the goal after seeing the test. */
	conversions: number;
	completions: number;
	/** conversions ÷ the variant's visits. */
	rate: number;
	/** Per currency, never added across currencies. */
	revenue: Revenue[];
}

/** One variant of a test, in full. */
export interface AbVariantReport {
	slug: string;
	label: string;
	weight: number;
	control: boolean;
	/** The winner chosen in the editor. */
	winner: boolean;
	/** Visits that saw this variant only. */
	visits: number;
	/** Of every visit that saw the test. */
	share: number;
	visitors: number;
	/** Page loads that showed it. */
	pageviews: number;
	/** Clicks inside the variant. */
	clicks: number;
	/** Visits that clicked inside it. */
	clicked: number;
	click_rate: number;
	bounce_rate: number;
	/** Seconds per visit. */
	engaged_time: number;
	goals: AbGoalResult[];
	primary: AbComparison & { conversions: number; rate: number; verdict: AbVariantVerdict };
}

/** A test as the registry holds it. */
export interface AbTest {
	id: string;
	name: string;
	status: AbTestStatus;
	post: AbTestPost;
	variants: { slug: string; label: string; weight: number }[];
	/** Goal ids, in order; the first is the primary metric. */
	goals: string[];
	winner: string;
	created: string | null;
	/** Unix time of the last save. */
	updated: number;
	started: string | null;
	ended: string | null;
	removed: string | null;
}

export interface AbTestAnswer extends Answer {
	test: AbTest;
	thresholds: AbThresholds;
	/** The span measured: from its start (or creation) to its end, or now. */
	period: { from: string; to: string; days: number };
	primary: AbPrimary;
	goals: { id: string; name: string; kind: GoalKind; match: string }[];
	visits: number;
	mixed: AbMixed;
	variants: AbVariantReport[];
	leader: { slug: string; label: string } | null;
	verdict: AbTestVerdict;
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

/** Content audit findings, most serious first. */
export const AUDIT_FINDINGS = [
	'noindex',
	'canonical',
	'robots_blocked',
	'not_indexed',
	'google_canonical',
	'thin',
	'title_missing',
	'title_duplicate',
	'title_long',
	'description_missing',
	'description_duplicate',
	'description_long',
	'h1_none',
	'h1_several',
	'rich_errors',
	'images_alt',
] as const;
export type AuditFinding = (typeof AUDIT_FINDINGS)[number];

/** Findings of Google's URL Inspection (also content audit findings). */
export const INSPECTION_FINDINGS = ['robots_blocked', 'not_indexed', 'google_canonical', 'rich_errors'] as const;
export type InspectionFinding = (typeof INSPECTION_FINDINGS)[number];

/** Google's verdicts of a page in its index. */
export const INSPECTION_VERDICTS = ['PASS', 'PARTIAL', 'FAIL', 'NEUTRAL'] as const;
export type InspectionVerdict = (typeof INSPECTION_VERDICTS)[number];

/** A rich result type Google detected on a page, with its issues. */
export interface InspectionRichType {
	type: string;
	items: number;
	errors: number;
	warnings: number;
	issues: { message: string; severity: 'error' | 'warning' }[];
}

/** Google's view of a page from URL Inspection (the version in its index); Google's values as it gives them, null when not given. */
export interface InspectionGoogle {
	/** When it was inspected (ISO 8601). */
	checked: string;
	verdict: InspectionVerdict | null;
	/** Google's reason, in its words, e.g. "Crawled - currently not indexed". */
	coverage: string | null;
	indexing: string | null;
	robots: string | null;
	page_fetch: string | null;
	crawled_as: string | null;
	last_crawl: string | null;
	google_canonical: string | null;
	user_canonical: string | null;
	rich_verdict: InspectionVerdict | null;
	rich: InspectionRichType[];
	/** Sitemaps and pages Google knows it from (up to five each). */
	sitemaps: string[];
	referring: string[];
	/** The inspection in Search Console. */
	link: string | null;
	/** Google's message when it would not inspect the address. */
	error: string | null;
	findings: InspectionFinding[];
}

/** A sitemap problem: errors, not downloaded lately, warnings. */
export type SitemapProblem = 'errors' | 'stale' | 'warnings';

/** A sitemap submitted in Search Console, as Google read it. */
export interface SearchSitemap {
	path: string;
	type: string;
	index: boolean;
	pending: boolean;
	submitted: string | null;
	downloaded: string | null;
	errors: number;
	warnings: number;
	/** Addresses submitted, by type (Google's indexed count is deprecated and left out). */
	contents: { type: string; submitted: number }[];
	problems: SitemapProblem[];
}

/** The property's sitemaps, read daily while Search Console is connected. */
export interface SearchSitemaps {
	read: string | null;
	error: string | null;
	/** The site's own sitemap index (its SEO plugin's, or WordPress's); null without one. */
	own: string | null;
	/** False when the site's own sitemap index is not submitted. */
	submitted: boolean;
	stale_days: number;
	rows: SearchSitemap[];
}

/** URL Inspection's daily cap, today's use, the last run and its error. */
export interface InspectionProgress {
	daily: number;
	used: number;
	/** Google's day (Pacific time), YYYY-MM-DD. */
	day: string;
	last: string | null;
	error: string | null;
	error_at: string | null;
}

export interface InspectionRow extends OpportunityPage, InspectionGoogle {}

export interface InspectionsAnswer {
	connected: boolean;
	ignored: string[];
	verdict: InspectionVerdict | '';
	coverage: string;
	finding: InspectionFinding | '';
	progress: InspectionProgress;
	rules: { daily: number; max_daily: number; recheck_days: number; budget: number; stale_days: number };
	sitemaps: SearchSitemaps;
	/** Pages inspected, and per verdict. */
	inspected: number;
	counts: Record<InspectionVerdict, number>;
	/** Newest first. */
	rows: InspectionRow[];
	total: number;
	more: boolean;
}

/** What the content audit read from a page's post and SEO plugin fields. */
export interface AuditFacts {
	/** Characters of the title shown: the SEO title, else the post title. */
	title_length: number;
	/** Characters of the SEO title as written (0 when it is the plugin's variables). */
	seo_title_length: number;
	/** Characters of the description: the SEO plugin's, else the excerpt. */
	description_length: number;
	/** H1s in the text (themes show the title as one more). */
	h1: number;
	words: number;
	images: number;
	images_no_alt: number;
	noindex: boolean;
	canonical_away: boolean;
	/** When the post was last changed and its facts read (ISO 8601). */
	modified: string | null;
	checked: string;
}

/** A page with audit findings and its search figures in the period. */
export interface AuditRow extends OpportunityPage, SearchMetrics {
	findings: AuditFinding[];
	facts: AuditFacts;
	/** Other pages with the same title or description (up to five). */
	same_title: string[];
	same_description: string[];
	/** Google's URL Inspection of the page; null until inspected. */
	google: InspectionGoogle | null;
}

export interface AuditAnswer extends Answer, SearchEngineAnswer {
	/** The period of the search figures: cut at the newest search day and to its newest 91 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	/** The finding asked for; '' for all. */
	finding: AuditFinding | '';
	rules: {
		title_max: number;
		description_max: number;
		thin_words: number;
		thin_impressions: number;
		batch: number;
		stale_days: number;
	};
	/** Pages with facts, and when the oldest and newest were read. */
	checked: { pages: number; oldest: string | null; newest: string | null };
	/** The SEO plugin read: rank-math, yoast, seopress, aioseo, demo or ''. */
	plugin: string;
	/** Pages per finding (of every page, whatever finding is asked for). */
	counts: Record<AuditFinding, number>;
	/** Pages with any finding. */
	pages: number;
	/** Most impressions first. */
	rows: AuditRow[];
	total: number;
	more: boolean;
}

/** Internal links lists. */
export const LINKS_KINDS = ['orphans', 'converting', 'missing'] as const;
export type LinksKind = (typeof LINKS_KINDS)[number];

/** An orphan page, or a converting page with few links in. */
export interface LinksPageRow extends OpportunityPage, SearchMetrics {
	/** Visits from search that started on the page, and their conversions of the goal (null without one). */
	visits: number;
	conversions: number | null;
	/** Other pages whose text links to it, and up to five of them. */
	links_in: number;
	from: string[];
}

/** A search a page shows for whose page with most clicks it does not link to. */
export interface LinksMissingQuery {
	query: string;
	clicks: number;
	impressions: number;
	position: number;
	/** The page it should link to, for the search. */
	to_clicks: number;
	to_position: number;
}

/** A missing link: the page (its figures for the searches) and the page it should link to (its figures for them). */
export interface LinksMissingRow extends OpportunityPage, SearchMetrics {
	to: OpportunityPage & SearchMetrics;
	/** Up to five searches, most impressions first, of query_count. */
	queries: LinksMissingQuery[];
	query_count: number;
}

export type LinksRow = LinksPageRow | LinksMissingRow;

export interface LinksAnswer extends Answer, SearchEngineAnswer {
	/** The period of the search figures: cut at the newest search day and to its newest 91 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	kind: LinksKind;
	rules: {
		/** Pages linking in at most for "few". */
		few_links: number;
		min_conversions: number;
		/** Impressions a page needs on a search for a missing link. */
		min_impressions: number;
		queries: number;
		/** Pages a page's links are kept to, at most. */
		max_links: number;
	};
	/** Published pages read by the audit, and how many of them had their links read. */
	read: { pages: number; read: number; complete: boolean };
	/** The goal counted; null without goals. */
	goal: { id: string; name: string } | null;
	goals: { id: string; name: string }[];
	/** Rows per list (of every list, whichever is asked for). */
	counts: Record<LinksKind, number>;
	rows: LinksRow[];
	total: number;
	more: boolean;
}

/** Indexation lists: published pages, and other addresses in the site's sitemaps. */
export const INDEXATION_KINDS = ['pages', 'sitemap'] as const;
export type IndexationKind = (typeof INDEXATION_KINDS)[number];

/** Where a sitemap address comes from: category and tag archives, author archives, another plugin's. */
export type SitemapSource = 'taxonomies' | 'users' | 'other';

/** A page or sitemap address search has not shown in the window. */
export interface IndexationRow extends OpportunityPage {
	/** never: search never showed it; lost: it did until last_impression. */
	state: 'never' | 'lost';
	/** YYYY-MM-DD, or null when never shown. */
	last_impression: string | null;
	/** Days from publishing (pages) or first listing (sitemap) to the window's end. */
	age: number;
	/** pages: when it was published, its words and the pages linking to it. */
	published?: string;
	words?: number;
	links_in?: number;
	/** sitemap: when it was first listed, and from where. */
	first_seen?: string;
	source?: SitemapSource;
	/** Google's URL Inspection of the page (its reason and last crawl); null until inspected or for Bing. */
	google: InspectionGoogle | null;
}

export interface IndexationAnswer extends Answer, SearchEngineAnswer {
	/** The window: the engine's newest `days` days, through `through`. */
	range: Range;
	days: number;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	kind: IndexationKind;
	rules: { days: number; max_rows: number; max_urls: number };
	/** Published pages read by the audit, how many have their published time, and the sitemaps' last read. */
	read: {
		pages: number;
		published: number;
		complete: boolean;
		sitemap: { read: string | null; enabled: boolean; complete: boolean; addresses: number };
	};
	/** A page's clicks per 28 days here, on average, when search shows it. */
	typical: number;
	/** Pages left out: they ask not to be indexed, or name another page as canonical. */
	skipped: { noindex: number; canonical: number };
	/** Rows per list (of every list, whichever is asked for). */
	counts: Record<IndexationKind, number>;
	/** Search Console's sitemaps and the URL Inspection run (Google; null for Bing). */
	sitemaps: SearchSitemaps | null;
	inspections: (InspectionProgress & { inspected: number }) | null;
	/** Never shown first, then the newest. */
	rows: IndexationRow[];
	total: number;
	more: boolean;
}

/** Backlinks lists: live links, the sites linking, the site's pages linked to, links lost in the period. */
export const BACKLINK_KINDS = ['links', 'domains', 'pages', 'lost'] as const;
export type BacklinkKind = (typeof BACKLINK_KINDS)[number];

/** A link's rel words kept. */
export type BacklinkRel = 'nofollow' | 'sponsored' | 'ugc';

/** A link from a page of another site to one of the site's pages. */
export interface BacklinkRow {
	/** The page linking (https://host/path). */
	source: string;
	host: string;
	/** The site's page it links to. */
	page: string;
	/** Its text ([alt] for an image, [image] without one). */
	anchor: string;
	rel: BacklinkRel[];
	/** How it was found: referrer (a page that sent visits), or a provider. */
	found: string[];
	/** ISO times: first and last check that found it. */
	first_seen: string | null;
	last_seen: string | null;
	/** First found in the period. */
	new: boolean;
	authority: number;
	/** lost list: when it was lost. */
	lost?: string | null;
}

/** A site linking to the site. */
export interface BacklinkDomainRow {
	host: string;
	links: number;
	/** Links without nofollow, sponsored or ugc. */
	followed: number;
	pages: number;
	new: number;
	lost: number;
	/** Its visits in the period. */
	visits: number;
	first_seen: string | null;
	last_seen: string | null;
}

/** One of the site's pages that other sites link to. */
export interface BacklinkPageRow {
	page: string;
	links: number;
	domains: number;
	new: number;
	first_seen: string | null;
}

export interface BacklinksAnswer extends Answer {
	range: Range;
	kind: BacklinkKind;
	totals: { domains: number; links: number; pages: number; new: number; new_domains: number; lost: number };
	/** Referring pages known and checked, the last run, failed requests then, and whether the check is on. */
	read: { enabled: boolean; pages: number; checked: number; last: string | null; errors: number };
	rules: { recheck_days: number; misses: number; budget: number; max_rows: number };
	rows: (BacklinkRow | BacklinkDomainRow | BacklinkPageRow)[];
	total: number;
	more: boolean;
}

/** A search target's status, as the site set it. */
export const TARGET_STATUSES = ['candidate', 'targeted', 'live', 'won', 'retired'] as const;
export type TargetStatus = (typeof TARGET_STATUSES)[number];

/** Targets a list can ask for: every target, the open ones (candidate, targeted, live), or one status. */
export const TARGET_FILTERS = ['all', 'open', ...TARGET_STATUSES] as const;
export type TargetFilter = (typeof TARGET_FILTERS)[number];

/** How search treats a target: the page meant for it ranks, another page does, no page is chosen yet, or it is not shown. */
export const TARGET_STATES = ['ranking', 'wrong_page', 'no_page', 'not_shown'] as const;
export type TargetState = (typeof TARGET_STATES)[number];

/** Decision queue findings of a search target: shown with another page, or high priority in striking distance. */
export type TargetFinding = 'wrong_page' | 'striking';

/** A page of a target with its figures for the target's query. */
export interface TargetPage extends OpportunityPage {
	clicks: number;
	impressions: number;
	position: number | null;
	/** Its share of the query's impressions; null when search did not show it. */
	share: number | null;
}

export interface TargetRow {
	query: string;
	/** 0–100. */
	priority: number;
	status: TargetStatus;
	/** list, aidevops or demo. */
	source: string;
	state: TargetState;
	/** Positions 1–3, 4–20 or beyond; null when not shown. */
	band: 'top' | 'striking' | 'beyond' | null;
	clicks: number;
	impressions: number;
	ctr: number;
	position: number | null;
	/** With a comparison period: the query's position and clicks then. */
	then_position: number | null;
	then_clicks: number | null;
	/** The page meant for it; null when none is chosen yet. */
	page: TargetPage | null;
	/** The page search shows most for it; null when not shown or search gave no page. */
	shown: TargetPage | null;
	/** Pages search showed for it. */
	pages: number;
	updated: string;
}

export interface TargetsAnswer extends Answer, SearchEngineAnswer {
	/** The period read: cut at the newest search day and to its newest 91 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	compare: { range: Range } | null;
	connected: boolean;
	rules: { priority: number; high_priority: number; striking_from: number; striking_to: number; max_targets: number };
	/** Targets per status (every target). */
	statuses: Record<TargetStatus, number>;
	status: TargetFilter;
	/** Targets of the list asked for, per state. */
	counts: Record<TargetState, number>;
	/** Highest priority first, then most impressions. */
	rows: TargetRow[];
	total: number;
	more: boolean;
}

/** A row an import skipped, and why. */
export interface TargetSkipped {
	/** 1 for the first data row. */
	row: number;
	query: string;
	reason: 'query' | 'address' | 'priority' | 'status' | 'duplicate' | 'limit';
	message: string;
}

export interface TargetsImportAnswer {
	/** list (objects), csv, json or toon. */
	format: string;
	added: number;
	updated: number;
	removed: number;
	skipped: TargetSkipped[];
	total: number;
}

/** Refresh planner proposals for a page losing clicks, in the order they are checked. */
export const REFRESH_PROPOSALS = ['leave', 'protect', 'merge', 'update'] as const;
export type RefreshProposal = (typeof REFRESH_PROPOSALS)[number];

/** Kinds of decision queue item: each an opportunity kind, audit findings, internal links, indexation, refresh proposals, search targets and Search Console sitemap problems. */
export type QueueKind = OpportunityKind | 'audit' | 'links' | 'index' | 'refresh' | 'target' | 'sitemap';

/** A sitemap item's problem: a sitemap's own, or the site's sitemap index not submitted (missing). */
export const QUEUE_SITEMAP_PROBLEMS = ['missing', 'errors', 'stale', 'warnings'] as const;
export type QueueSitemapProblem = (typeof QUEUE_SITEMAP_PROBLEMS)[number];

/** An item's state: new (worked out now) or as someone left it. */
export const QUEUE_STATUSES = ['new', 'accepted', 'done', 'dismissed'] as const;
export type QueueStatus = (typeof QUEUE_STATUSES)[number];

/** States a list can ask for; open (the default) is new and accepted. */
export const QUEUE_FILTERS = ['open', 'new', 'accepted', 'done', 'dismissed', 'all'] as const;
export type QueueFilter = (typeof QUEUE_FILTERS)[number];

export type QueueAction = 'accept' | 'done' | 'dismiss' | 'restore' | 'effort' | 'note';

/** score = clicks × value × confidence ÷ effort. */
export interface QueueParts {
	/** Potential clicks per 28 days. */
	clicks: number;
	/** How well the page's visits from search convert against the site; 1 without goal data. */
	value: number;
	/** 0–1: the kind's own, weighed by impressions. */
	confidence: number;
	/** 1 (least) to 5. */
	effort: number;
}

/** The numbers behind an item; which are there depends on its kind. */
export interface QueueFigures {
	clicks: number;
	impressions: number;
	ctr?: number;
	position: number | null;
	expected_ctr?: number;
	potential?: number;
	then_clicks?: number;
	then_position?: number;
	lost?: number;
	cause?: DecayCause;
	match?: CoverageMatch;
	missing?: string[];
	question?: boolean;
	/** overlap: whether the leading page changed between the halves. */
	switched?: boolean;
	/** overlap: the pages sharing the query; refresh, merge: this page and the one that overtook it (with its share of the query). */
	pages?: { path_id: number; path: string; clicks: number; impressions: number; position: number | null; share: number | null }[];
	/** audit: the finding, its share of the page's expected clicks, the page's facts and the other pages with the same title or description. */
	finding?: AuditFinding;
	share?: number;
	facts?: AuditFacts;
	same?: string[];
	/** links: the list; orphans and converting: links in, the pages linking (up to five), visits and conversions. */
	list?: LinksKind;
	links_in?: number;
	from?: string[];
	visits?: number | null;
	conversions?: number | null;
	/** links, missing: the page that should link, its figures for the searches, and the searches. */
	link_from?: { path_id: number; path: string; url: string };
	from_impressions?: number;
	from_position?: number;
	queries?: LinksMissingQuery[];
	query_count?: number;
	/** index: never or lost, the last day shown, the age in days, what a shown page earns here per 28 days, and the row's own facts. */
	state?: 'never' | 'lost';
	last_impression?: string | null;
	/** index: days since publishing or listing; refresh: days since the content changed (null: not known). */
	age?: number | null;
	typical?: number;
	published?: string | null;
	words?: number;
	first_seen?: string;
	source?: SitemapSource;
	/** refresh: the proposal, the impressions before, when the content changed, whether that is old or within the periods compared, the queries lost most (each with the page that overtook it) and, for merge, that page with the query. */
	proposal?: RefreshProposal;
	then_impressions?: number;
	modified?: string | null;
	old?: boolean;
	changed?: boolean;
	lost_queries?: { query: string; lost: number; position: number | null; then_position: number | null; rival: { path: string; clicks: number; position: number; then_position: number | null; share: number } | null }[];
	rival?: DecayRival & { query: string; position_here: number | null; then_position_here: number | null };
	/** target: the target's priority and status (wrong_page: pages are the page meant for it, then the page shown). */
	priority?: number;
	target_status?: TargetStatus;
	/** sitemap: the sitemap as Search Console read it (typical and share give the potential clicks). */
	sitemap?: { url: string; errors: number; warnings: number; downloaded: string | null; submitted: string | null };
}

export interface QueueItem extends OpportunityPage {
	/** 16 hex characters: kind, engine, page and query. */
	key: string;
	kind: QueueKind;
	engine: SearchEngine;
	status: QueueStatus;
	/** Whether the opportunity is still found in this period (else as it was when acted on). */
	found: boolean;
	query: string | null;
	/** audit: the finding (the item is one per page and finding); links and index: the list; refresh: the proposal; target: wrong_page or striking; sitemap: the problem; else null. */
	finding: AuditFinding | LinksKind | IndexationKind | RefreshProposal | TargetFinding | QueueSitemapProblem | null;
	/** Why it is listed, in the site's language. */
	why: string;
	/** What to do, in the site's language. */
	todo: string;
	figures: QueueFigures;
	/** The measure of the experiment done opens. */
	metric: ExperimentMetric;
	parts: QueueParts;
	score: number;
	note: string;
	updated: string | null;
	user: string | null;
	effort_set: boolean;
	experiment_id: number | null;
	/** Done: the experiment it opened and its result. */
	experiment: {
		id: number;
		name: string;
		status: ExperimentStatus;
		result: ExperimentResult | null;
		due: boolean;
		review: string;
		metric: ExperimentMetric;
		state: 'running' | 'ready' | null;
		effect: number | null;
		unit: 'ratio' | 'places' | null;
		suggested: ExperimentResult | null;
		summary: string | null;
	} | null;
}

export interface QueueAnswer extends Answer, SearchEngineAnswer {
	/** The period read: cut at the newest search day and to its newest 91 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	connected: boolean;
	ignored: string[];
	/** The goal giving value; null without goals. */
	goal: { id: string; name: string } | null;
	goals: { id: string; name: string }[];
	/** The site's conversion rate of visits from search; null without a goal or visits. */
	site_rate: number | null;
	rules: {
		scale_days: number;
		effort: Record<QueueKind, number>;
		/** Audit findings whose effort is not the audit kind's. */
		audit_effort: Partial<Record<AuditFinding, number>>;
		/** Internal links lists whose effort is not the links kind's, and each list's share of the expected clicks. */
		links_effort: Partial<Record<LinksKind, number>>;
		links_share: Record<LinksKind, number>;
		/** Indexation lists whose effort is not the index kind's, and each list's share of a typical page's clicks. */
		index_effort: Partial<Record<IndexationKind, number>>;
		index_share: Record<IndexationKind, number>;
		/** Sitemap problems whose effort is not the sitemap kind's, and each problem's share of a typical page's clicks. */
		sitemap_effort: Partial<Record<QueueSitemapProblem, number>>;
		sitemap_share: Record<QueueSitemapProblem, number>;
		/** Refresh proposals' effort and share of the clicks lost, and the planner's thresholds. */
		refresh_effort: Record<RefreshProposal, number>;
		refresh_share: Record<RefreshProposal, number>;
		refresh: { old_days: number; protect_value: number; protect_conversions: number };
		/** Search targets: the share of a search's expected clicks at stake on the wrong page, the default and least striking priority, and the statuses listed. */
		target: { share: number; priority: number; high_priority: number; statuses: TargetStatus[] };
		confidence: Record<QueueKind, number>;
		full_impressions: number;
		missing_share: number;
		smooth_visits: number;
		max_value: number;
		hide_days: number;
		per_kind: number;
	};
	/** New items left out because their page has a running experiment. */
	left_out: number;
	status: QueueFilter;
	/** The kind asked for; null for every kind. */
	kind: QueueKind | null;
	counts: Record<QueueStatus, number>;
	items: QueueItem[];
	total: number;
	more: boolean;
}

export interface ApiError {
	code: string;
	message: string;
	data?: { status?: number };
}
