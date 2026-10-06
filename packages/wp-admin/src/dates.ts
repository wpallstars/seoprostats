/**
 * Point labels in the site's time zone. The API's times carry the site's
 * offset (2026-09-16T00:00:00+01:00), so the wall-clock parts are read
 * straight from the text and formatted as UTC: the browser's own time
 * zone never shifts them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { Grain } from '@seoprostats/core';
import { locale } from './boot';

function wallClock(iso: string): Date {
	const m = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/.exec(iso);
	if (!m) {
		return new Date(NaN);
	}
	return new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4] ?? 0), Number(m[5] ?? 0)));
}

const formats = new Map<string, Intl.DateTimeFormat>();

function df(options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
	const key = JSON.stringify(options);
	let format = formats.get(key);
	if (!format) {
		format = new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' });
		formats.set(key, format);
	}
	return format;
}

/** Short axis label: 16 Sep, 14:00, Sep 2026. */
export function axisLabel(iso: string, grain: Grain): string {
	const d = wallClock(iso);
	if (grain === 'hour') {
		return df({ hour: 'numeric', minute: '2-digit' }).format(d);
	}
	if (grain === 'month') {
		return df({ month: 'short', year: 'numeric' }).format(d);
	}
	return df({ day: 'numeric', month: 'short' }).format(d);
}

/** Fuller label for tooltips and tables: Tue 16 Sep 2026. */
export function longLabel(iso: string, grain: Grain): string {
	const d = wallClock(iso);
	if (grain === 'hour') {
		return df({ weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }).format(d);
	}
	if (grain === 'month') {
		return df({ month: 'long', year: 'numeric' }).format(d);
	}
	return df({ weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(d);
}

/** A range's days, for "compared with …": 1 Sep – 30 Sep 2026. */
export function rangeText(fromIso: string, toIso: string): string {
	const from = wallClock(fromIso);
	// `to` is exclusive: show the last day included.
	const to = new Date(wallClock(toIso).getTime() - 1);
	const sameYear = from.getUTCFullYear() === to.getUTCFullYear();
	const start = df(sameYear ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' }).format(from);
	const end = df({ day: 'numeric', month: 'short', year: 'numeric' }).format(to);
	return start === end ? end : `${start} – ${end}`;
}
