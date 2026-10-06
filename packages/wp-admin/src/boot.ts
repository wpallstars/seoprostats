/**
 * What PHP passes to the app (SEOProStats_Dashboard::boot()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

export interface Boot {
	/** The user's language, e.g. en_GB. */
	locale: string;
	/** The site's time zone, e.g. Europe/London or +01:00. */
	timezone: string;
	dashboardUrl: string;
	settingsUrl: string;
	canManage: boolean;
	/** Dashboard only: no saved arrangement includes the widget, so it goes to its default place. */
	placeWidget: boolean;
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
	canManage: raw.canManage ?? false,
	placeWidget: raw.placeWidget ?? false,
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
