/**
 * Number, percentage, duration and change formatting in the reader's
 * language (Intl). Pass the locale the app runs in.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { MetricFormat } from './metrics';

const cache = new Map<string, Intl.NumberFormat>();

function nf(locale: string, options: Intl.NumberFormatOptions): Intl.NumberFormat {
	const key = locale + JSON.stringify(options);
	let format = cache.get(key);
	if (!format) {
		format = new Intl.NumberFormat(locale, options);
		cache.set(key, format);
	}
	return format;
}

/** Whole numbers in full up to 99,999, then short (123K, 4.5M). */
export function formatNumber(value: number, locale: string, compact = true): string {
	if (compact && Math.abs(value) >= 100000) {
		return nf(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
	}
	return nf(locale, { maximumFractionDigits: 0 }).format(value);
}

export function formatDecimal(value: number, locale: string): string {
	return nf(locale, { minimumFractionDigits: 1, maximumFractionDigits: 2 }).format(value);
}

/** A fraction as a percentage: 0.452 → 45.2%. */
export function formatPercent(fraction: number, locale: string): string {
	return nf(locale, { style: 'percent', maximumFractionDigits: fraction < 0.1 && fraction > 0 ? 1 : 0 }).format(fraction);
}

/** Seconds as 45s, 3m 05s or 1h 02m. */
export function formatDuration(seconds: number): string {
	const s = Math.max(0, Math.round(seconds));
	if (s < 60) {
		return `${s}s`;
	}
	if (s < 3600) {
		return `${Math.floor(s / 60)}m ${String(s % 60).padStart(2, '0')}s`;
	}
	return `${Math.floor(s / 3600)}h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}m`;
}

export function formatMetric(value: number, format: MetricFormat, locale: string): string {
	switch (format) {
		case 'decimal':
			return formatDecimal(value, locale);
		case 'percent':
			return formatPercent(value, locale);
		case 'duration':
			return formatDuration(value);
		default:
			return formatNumber(value, locale);
	}
}

/** A change as +12% or −3%; null (nothing to compare with) as an em dash. */
export function formatChange(change: number | null | undefined, locale: string): string {
	if (change === null || change === undefined || !Number.isFinite(change)) {
		return '—';
	}
	const text = nf(locale, {
		style: 'percent',
		maximumFractionDigits: Math.abs(change) < 0.1 ? 1 : 0,
		signDisplay: 'exceptZero',
	}).format(change);
	// A true minus sign reads better than a hyphen.
	return text.replace('-', '\u2212');
}
