/**
 * What PHP passes to the app (SEOProStats_Dashboard::boot()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

export type DataSet = 'live' | 'demo';

/** GET /demo (SEOProStats_Demo::status()). */
export interface DemoStatus {
	status: 'none' | 'making' | 'ready';
	days: number;
	/** 0 to 1 while making. */
	progress: number;
	from: string | null;
	made: string | null;
}

export interface Boot {
	/** The user's language, e.g. en_GB. */
	locale: string;
	/** The site's time zone, e.g. Europe/London or +01:00. */
	timezone: string;
	dashboardUrl: string;
	settingsUrl: string;
	siteHost: string;
	canManage: boolean;
	/** Dashboard only: no saved arrangement includes the widget, so it goes to its default place. */
	placeWidget: boolean;
	/** The data set this person chose to see. */
	data: DataSet;
	demo: DemoStatus;
	/** Dashboard, for those who share: the site's colours offered as a shared report's accent. */
	sharePalette: { color: string; name: string }[];
}

declare global {
	interface Window {
		seoprostatsBoot?: Partial<Boot>;
	}
}

const raw = window.seoprostatsBoot ?? {};

export const boot: Boot = {
	locale: raw.locale ?? 'en_US',
	timezone: raw.timezone ?? 'UTC',
	dashboardUrl: raw.dashboardUrl ?? '',
	settingsUrl: raw.settingsUrl ?? '',
	siteHost: raw.siteHost ?? '',
	canManage: raw.canManage ?? false,
	placeWidget: raw.placeWidget ?? false,
	data: raw.data === 'demo' ? 'demo' : 'live',
	demo: raw.demo ?? { status: 'none', days: 0, progress: 0, from: null, made: null },
	sharePalette: raw.sharePalette ?? [],
};

/** BCP 47 form for Intl (en_GB → en-GB); falls back to the browser's. */
export const locale: string = (() => {
	const tag = boot.locale.replace('_', '-');
	try {
		return Intl.NumberFormat.supportedLocalesOf([tag]).length ? tag : navigator.language;
	} catch {
		return navigator.language;
	}
})();
