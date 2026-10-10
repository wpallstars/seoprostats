/**
 * Translated names of ranges, metrics, dimensions and values.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, sprintf } from '@wordpress/i18n';
import type { CompareKey, Dimension, Filter, MetricKey, Operator, RangeKey, View, ViewState } from '@seoprostats/core';
import { locale } from './boot';

/** API values stay open-ended; only presentation names are known here. */
export function appearanceLabel(value: string): string {
	const names: Record<string, string> = {
		AMP_BLUE_LINK: __('AMP blue link', 'seoprostats'),
		AMP_TOP_STORIES: __('AMP top stories', 'seoprostats'),
		AMP_IMAGE_RESULT: __('AMP image result', 'seoprostats'),
		VIDEO: __('Video', 'seoprostats'),
		PRODUCT_SNIPPETS: __('Product snippets', 'seoprostats'),
		MERCHANT_LISTINGS: __('Merchant listings', 'seoprostats'),
		REVIEW_SNIPPET: __('Review snippets', 'seoprostats'),
		FORUMS: __('Forums', 'seoprostats'),
		AMP_STORY: __('AMP story', 'seoprostats'),
	};
	const sentence = value.replace(/_/g, ' ').toLowerCase();
	return names[value] ?? sentence.charAt(0).toUpperCase() + sentence.slice(1);
}

export function viewLabel(view: View): string {
	const labels: Record<View, string> = {
		overview: __('Overview', 'seoprostats'),
		search: __('Search', 'seoprostats'),
		goals: __('Goals', 'seoprostats'),
		funnels: __('Funnels', 'seoprostats'),
		properties: __('Properties', 'seoprostats'),
		clicks: __('Clicks', 'seoprostats'),
		'ab-tests': __('A/B tests', 'seoprostats'),
		changes: __('Changes', 'seoprostats'),
	};
	return labels[view];
}

/** A shared report's section: its tab, and for Search its engine. */
export function sectionLabel(state: Pick<ViewState, 'view' | 'engine'>): string {
	if (state.view !== 'search') {
		return viewLabel(state.view);
	}
	/* translators: %s: a search engine, such as Google or Bing. */
	return sprintf(__('Search: %s', 'seoprostats'), state.engine === 'bing' ? __('Bing', 'seoprostats') : __('Google', 'seoprostats'));
}

/** A filter as people read it: Country is United Kingdom, Spain. */
export function filterLabel(filter: Filter): string {
	return `${dimensionLabel(filter.dimension)} ${operatorLabel(filter.op)} ${filter.values.map((value) => valueLabel(filter.dimension, value)).join(', ')}`;
}

export function rangeLabel(key: RangeKey): string {
	const labels: Record<RangeKey, string> = {
		realtime: __('Last 30 minutes', 'seoprostats'),
		today: __('Today', 'seoprostats'),
		yesterday: __('Yesterday', 'seoprostats'),
		'24h': __('Last 24 hours', 'seoprostats'),
		'7d': __('Last 7 days', 'seoprostats'),
		'28d': __('Last 4 weeks', 'seoprostats'),
		'30d': __('Last 30 days', 'seoprostats'),
		'90d': __('Last 90 days', 'seoprostats'),
		'91d': __('Last 13 weeks', 'seoprostats'),
		'182d': __('Last 26 weeks', 'seoprostats'),
		'364d': __('Last 52 weeks', 'seoprostats'),
		week: __('This week', 'seoprostats'),
		month: __('This month', 'seoprostats'),
		year: __('This year', 'seoprostats'),
		'12mo': __('Last 12 months', 'seoprostats'),
		lastyear: __('Last year', 'seoprostats'),
		all: __('All time', 'seoprostats'),
		custom: __('Custom dates', 'seoprostats'),
	};
	return labels[key];
}

/** A heading in the period menu and the ranges under it; ranges without a heading follow the groups. */
export interface RangeGroup {
	label?: string;
	keys: RangeKey[];
}

/**
 * The period menu. Weeks are whole weeks, so the previous period meets the
 * same weekdays. 30d, 90d and realtime stay valid in links and the API but
 * are left out; the menu shows one only while it is the chosen period.
 */
export function rangeMenu(): RangeGroup[] {
	return [
		{ label: __('Days', 'seoprostats'), keys: ['today', 'yesterday', '24h', '7d'] },
		{ label: __('Weeks', 'seoprostats'), keys: ['28d', '91d', '182d', '364d'] },
		{ label: __('Calendar', 'seoprostats'), keys: ['week', 'month', 'year', '12mo', 'lastyear'] },
		{ keys: ['all', 'custom'] },
	];
}

export function compareLabel(key: CompareKey): string {
	const labels: Record<CompareKey, string> = {
		none: __('No comparison', 'seoprostats'),
		prev: __('Previous period', 'seoprostats'),
		year: __('Same period last year', 'seoprostats'),
	};
	return labels[key];
}

export function metricLabel(key: MetricKey): string {
	const labels: Record<MetricKey, string> = {
		visitors: __('Visitors', 'seoprostats'),
		visits: __('Visits', 'seoprostats'),
		pageviews: __('Pageviews', 'seoprostats'),
		views_per_visit: __('Views per visit', 'seoprostats'),
		bounce_rate: __('Bounce rate', 'seoprostats'),
		visit_duration: __('Visit duration', 'seoprostats'),
		events: __('Events', 'seoprostats'),
	};
	return labels[key];
}

export function metricHelp(key: MetricKey): string {
	const help: Partial<Record<MetricKey, string>> = {
		visitors: __('Different people each day, added up over the days. No one is followed from one day to the next.', 'seoprostats'),
		visits: __('Visits to the site. A visit ends after 30 minutes without a page or event.', 'seoprostats'),
		bounce_rate: __('Visits with one page and nothing else done, as a share of all visits.', 'seoprostats'),
		visit_duration: __('Average time a visit had the site on screen.', 'seoprostats'),
	};
	return help[key] ?? '';
}

export function dimensionLabel(key: Dimension): string {
	const labels: Record<Dimension, string> = {
		channel: __('Channel', 'seoprostats'),
		source: __('Source', 'seoprostats'),
		utm_source: __('UTM source', 'seoprostats'),
		utm_medium: __('UTM medium', 'seoprostats'),
		utm_campaign: __('Campaign', 'seoprostats'),
		utm_term: __('UTM term', 'seoprostats'),
		utm_content: __('UTM content', 'seoprostats'),
		country: __('Country', 'seoprostats'),
		device: __('Device', 'seoprostats'),
		browser: __('Browser', 'seoprostats'),
		os: __('Operating system', 'seoprostats'),
		language: __('Language', 'seoprostats'),
		login: __('Logged in', 'seoprostats'),
		entry: __('Entry page', 'seoprostats'),
		exit: __('Exit page', 'seoprostats'),
		page: __('Page', 'seoprostats'),
		not_found: __('Page not found', 'seoprostats'),
		search: __('Site search', 'seoprostats'),
		no_results: __('Search with no results', 'seoprostats'),
		author: __('Author', 'seoprostats'),
		category: __('Category', 'seoprostats'),
		post_type: __('Post type', 'seoprostats'),
		event: __('Event', 'seoprostats'),
		variant: __('A/B variant', 'seoprostats'),
	};
	return labels[key];
}

export function operatorLabel(op: Operator): string {
	const labels: Record<Operator, string> = {
		is: __('is', 'seoprostats'),
		is_not: __('is not', 'seoprostats'),
		contains: __('contains', 'seoprostats'),
		matches: __('matches', 'seoprostats'),
	};
	return labels[op];
}

const regionNames = (() => {
	try {
		return new Intl.DisplayNames([locale], { type: 'region' });
	} catch {
		return null;
	}
})();

const languageNames = (() => {
	try {
		return new Intl.DisplayNames([locale], { type: 'language' });
	} catch {
		return null;
	}
})();

/** A dimension value as people read it; the API's label when it has one. */
export function valueLabel(dimension: Dimension, value: string, apiLabel?: string): string {
	if (value === '') {
		// The API names empty values for each dimension (Direct, Unknown, (none)).
		return apiLabel || (dimension === 'source' ? __('Direct', 'seoprostats') : __('(none)', 'seoprostats'));
	}
	if (dimension === 'country' && /^[A-Z]{2}$/.test(value)) {
		return regionNames?.of(value) ?? value;
	}
	if (dimension === 'language' && /^[a-z]{2,3}(-[A-Za-z]{2,4})?$/.test(value)) {
		try {
			return languageNames?.of(value) ?? value;
		} catch {
			return value;
		}
	}
	if (dimension === 'channel') {
		const channels: Record<string, string> = {
			direct: __('Direct', 'seoprostats'),
			organic_search: __('Organic search', 'seoprostats'),
			paid_search: __('Paid search', 'seoprostats'),
			ai: __('AI assistants', 'seoprostats'),
			organic_social: __('Organic social', 'seoprostats'),
			paid_social: __('Paid social', 'seoprostats'),
			email: __('Email', 'seoprostats'),
			referral: __('Referral', 'seoprostats'),
			paid_other: __('Other paid', 'seoprostats'),
		};
		return channels[value] ?? apiLabel ?? value;
	}
	if (dimension === 'device') {
		const devices: Record<string, string> = {
			desktop: __('Desktop', 'seoprostats'),
			mobile: __('Mobile', 'seoprostats'),
			tablet: __('Tablet', 'seoprostats'),
			unknown: __('Unknown', 'seoprostats'),
		};
		return devices[value] ?? apiLabel ?? value;
	}
	if (dimension === 'login') {
		const logins: Record<string, string> = {
			logged_in: __('Logged in', 'seoprostats'),
			logged_out: __('Not logged in', 'seoprostats'),
		};
		return logins[value] ?? apiLabel ?? value;
	}
	return apiLabel || value;
}
