/**
 * @seoprostats/tracker: the browser script. Sends pageviews (with SPA
 * navigation), engagement (visible time, deepest scroll) and events
 * (seoprostats('Name', {props, revenue}), data-sps-event, outbound links,
 * file downloads) to the collector, batched, as text/plain so there is no
 * CORS preflight. Design: docs/architecture.md → Collection → Tracker.
 *
 * It stores nothing in the browser: no cookies, localStorage or
 * sessionStorage. A random ID for each page load, kept in memory, joins a
 * pageview to its engagement and events; visits are made on the server.
 *
 * Configuration is the JSON in the script element's data-cfg attribute
 * (SEOProStats_Tracker::config()), so the same file works printed inline
 * or loaded from a URL. No dependencies; built with esbuild into
 * assets/build/tracker.js.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

/** data-cfg: written by SEOProStats_Tracker::config(). */
interface Config {
	/** Collector address. */
	u: string;
	/** The site's own hosts: links anywhere else are outbound. */
	h?: string[];
	/** Query parameters kept in page addresses, besides UTM tags and the defaults. */
	q?: string[];
	/** Skip visitors whose browser sends Do Not Track or Global Privacy Control. */
	dnt?: boolean;
	/** Paths not tracked; * matches any characters. */
	x?: string[];
}

type Scalar = string | number | boolean;

/** Options of seoprostats('Name', options). */
export interface EventOptions {
	/** Up to 30 properties; values are cut to 300 characters. */
	props?: Record<string, Scalar>;
	/** Revenue in one currency (ISO 4217 code). */
	revenue?: { amount: number; currency: string };
}

/** window.seoprostats: callable, with calls made before the script loaded in q. */
export interface Api {
	(name: string, options?: EventOptions): void;
	q?: ArrayLike<unknown>[];
}

/** One hit, in the collector's format (SEOProStats_Processor lists the fields). */
type Hit = { t: 'pv' | 'eng' | 'e'; p: string } & Record<string, unknown>;

declare global {
	interface Window {
		seoprostats?: Api;
	}
	interface Navigator {
		globalPrivacyControl?: boolean;
	}
	interface Document {
		prerendering?: boolean;
	}
}

/** Query parameters that say which page it is (WordPress without pretty permalinks), kept with UTM tags. */
const PAGE_QUERY = ['ref', 'source', 'p', 'page_id', 'cat', 'tag', 'post_type', 'paged', 'author'];

/** Ad click and tracking IDs: only their names are sent (as =1), for the channel, never their values. */
const CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'dclid', 'msclkid', 'fbclid', 'ttclid', 'twclid', 'li_fat_id', 'yclid', '_ga', '_gl', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'igshid'];

/** Links to files of these kinds count as downloads. */
const DOWNLOAD = /\.(?:pdf|zipx?|rar|7z|gz|tgz|bz2|xz|tar|dmg|pkg|exe|msi|apk|iso|docx?|xlsx?|pptx?|od[tsp]|rtf|csv|txt|epub|mp3|m4a|wav|ogg|flac|mp4|m4v|mov|avi|wmv|webm|mkv)$/i;

/** Most hits, and bytes, per request (the collector takes 50 and 16 KB). */
const BATCH_HITS = 25;
const BATCH_BYTES = 15000;

const win = window;
const doc = document;
const nav = navigator;
const loc = location;

const script = doc.currentScript;

/** JSON in one of the script element's attributes, or null. */
function attr(name: string): unknown {
	try {
		return JSON.parse((script && script.getAttribute(name)) || 'null') as unknown;
	} catch {
		return null;
	}
}

// Unreadable or missing: cfg.u stays empty and nothing is sent.
const cfg: Config = { u: '', ...(attr('data-cfg') as Partial<Config> | null) };
/** data-props: properties of the page as loaded, sent with its first pageview only. */
const pageProps = attr('data-props');

const queued = (win.seoprostats && win.seoprostats.q) || [];
const allowed = PAGE_QUERY.concat((cfg.q || []).map((key) => String(key).toLowerCase()));
const own = (cfg.h || []).map((host) => String(host).toLowerCase()).concat(loc.hostname.toLowerCase());
const skipPaths = (cfg.x || []).map(
	(glob) => new RegExp('^' + String(glob).split('*').map((part) => part.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*') + '$'),
);

const off =
	!cfg.u ||
	!/^https?:$/.test(loc.protocol) ||
	/^(?:localhost|127(?:\.\d+){3}|\[::1\])$/.test(loc.hostname) ||
	nav.webdriver === true ||
	/headless|phantomjs|lighthouse/i.test(nav.userAgent) ||
	(cfg.dnt === true && (nav.doNotTrack === '1' || nav.globalPrivacyControl === true));

let pageId = '';
let pageKey = '';
let pageHref = '';
let pageSkipped = false;
let visibleMs = 0;
let visibleFrom = 0;
let scroll = 0;
let sentMs = -1;
let sentScroll = -1;
let queue: Hit[] = [];
let timer: ReturnType<typeof setTimeout> | undefined;
let scrollPending = false;

/** A random 16-hex-digit ID for a page load. */
function newId(): string {
	const bytes = new Uint8Array(8);
	crypto.getRandomValues(bytes);
	return Array.from(bytes, (byte) => (byte + 256).toString(16).slice(1)).join('');
}

/** The page's path with the query parameters that may be kept. */
function pagePath(): string {
	const keep: string[] = [];
	new URLSearchParams(loc.search).forEach((value, key) => {
		const name = key.toLowerCase();
		if (CLICK_IDS.indexOf(name) >= 0) {
			keep.push(name + '=1');
		} else if (name.indexOf('utm_') === 0 || allowed.indexOf(name) >= 0) {
			keep.push(encodeURIComponent(name) + '=' + encodeURIComponent(value));
		}
	});
	return loc.pathname + (keep.length ? '?' + keep.join('&') : '');
}

/** An http(s) address without its query or fragment, or ''. */
function bare(href: string): string {
	try {
		const url = new URL(href);
		return /^https?:$/.test(url.protocol) ? url.origin + url.pathname : '';
	} catch {
		return '';
	}
}

function timeZone(): string {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
	} catch {
		return '';
	}
}

function send(hits: Hit[]): void {
	const body = JSON.stringify({ h: loc.hostname.toLowerCase(), e: hits });
	if (nav.sendBeacon && nav.sendBeacon(cfg.u, new Blob([body], { type: 'text/plain' }))) {
		return;
	}
	fetch(cfg.u, { method: 'POST', body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }).catch(() => undefined);
}

/** Send every queued hit now, in batches the collector accepts. */
function flush(): void {
	if (timer !== undefined) {
		clearTimeout(timer);
		timer = undefined;
	}
	let batch: Hit[] = [];
	let bytes = 0;
	for (const hit of queue) {
		const size = JSON.stringify(hit).length + 1;
		if (batch.length && (batch.length >= BATCH_HITS || bytes + size > BATCH_BYTES)) {
			send(batch);
			batch = [];
			bytes = 0;
		}
		batch.push(hit);
		bytes += size;
	}
	queue = [];
	if (batch.length) {
		send(batch);
	}
}

/** Queue a hit; hits of the same moment go in one request. */
function push(hit: Hit): void {
	if (pageSkipped) {
		return;
	}
	queue.push(hit);
	if (timer === undefined) {
		timer = setTimeout(flush, 0);
	}
}

/** Visible milliseconds of the current page so far. */
function visibleTime(): number {
	return visibleMs + (visibleFrom ? Date.now() - visibleFrom : 0);
}

function measureScroll(): void {
	scrollPending = false;
	const root = doc.documentElement;
	const height = Math.max(root.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
	const seen = (win.scrollY || root.scrollTop) + win.innerHeight;
	const percent = height > 0 ? Math.min(100, Math.round((seen / height) * 100)) : 100;
	if (percent > scroll) {
		scroll = percent;
	}
}

/** Engagement of the current page, when it changed since the last one sent. */
function engagement(): void {
	const ms = Math.round(visibleTime());
	if (!pageId || (ms === sentMs && scroll === sentScroll)) {
		return;
	}
	sentMs = ms;
	sentScroll = scroll;
	push({ t: 'eng', p: pageId, s: ms, sc: scroll });
}

/** A new page: its pageview, and fresh engagement counters. */
function startPage(referrer: string, props?: unknown): void {
	pageId = newId();
	pageKey = pagePath();
	pageHref = bare(loc.href);
	pageSkipped = skipPaths.some((path) => path.test(loc.pathname));
	visibleMs = 0;
	visibleFrom = doc.visibilityState === 'visible' ? Date.now() : 0;
	scroll = 0;
	sentMs = -1;
	sentScroll = -1;
	measureScroll();
	const hit: Hit = { t: 'pv', p: pageId, u: pageKey, r: referrer, w: screen.width, tz: timeZone(), l: nav.language || '' };
	const clean = cleanProps(props);
	if (clean) {
		hit.d = clean;
	}
	push(hit);
}

/** SPA navigation: a new pageview when the path or a kept parameter changed. */
function navigated(): void {
	if (pagePath() === pageKey) {
		return;
	}
	engagement();
	startPage(pageHref);
}

function cleanProps(props: unknown): Record<string, Scalar> | null {
	if (!props || typeof props !== 'object') {
		return null;
	}
	const out: Record<string, Scalar> = {};
	let count = 0;
	for (const [key, value] of Object.entries(props as Record<string, unknown>)) {
		if (count >= 30 || !key.trim() || value === '') {
			continue;
		}
		if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
			out[key.slice(0, 100)] = typeof value === 'string' ? value.slice(0, 300) : value;
			count++;
		}
	}
	return count ? out : null;
}

function event(name: unknown, options?: EventOptions): void {
	const label = String(name || '').trim().slice(0, 120);
	if (!label) {
		return;
	}
	const hit: Hit = { t: 'e', p: pageId, n: label };
	const props = cleanProps(options && options.props);
	if (props) {
		hit.d = props;
	}
	const revenue = options && options.revenue;
	if (revenue && isFinite(Number(revenue.amount)) && /^[A-Za-z]{3}$/.test(String(revenue.currency))) {
		hit.rv = { a: Number(revenue.amount), c: String(revenue.currency).toUpperCase() };
	}
	push(hit);
}

/** Clicks: data-sps-event elements, outbound links and file downloads. */
function clicked(e: MouseEvent): void {
	if ((e.type === 'auxclick' && e.button !== 1) || !(e.target instanceof Element)) {
		return;
	}
	let sent = false;
	const tagged = e.target.closest('[data-sps-event]');
	if (tagged instanceof HTMLElement) {
		// data-sps-prop-plan="pro" becomes the property plan.
		const props: Record<string, Scalar> = {};
		for (const [key, value] of Object.entries(tagged.dataset)) {
			if (key.indexOf('spsProp') === 0 && key.length > 7 && value !== undefined) {
				props[key.charAt(7).toLowerCase() + key.slice(8)] = value;
			}
		}
		event(tagged.dataset.spsEvent, { props });
		sent = true;
	}
	const link = e.target.closest('a[href]');
	if (link instanceof HTMLAnchorElement) {
		let url: URL | null = null;
		try {
			url = new URL(link.href, loc.href);
		} catch {
			url = null;
		}
		if (url && /^https?:$/.test(url.protocol)) {
			if (own.indexOf(url.hostname.toLowerCase()) < 0) {
				event('Outbound link', { props: { url: url.origin + url.pathname } });
				sent = true;
			} else if (link.hasAttribute('download') || DOWNLOAD.test(url.pathname)) {
				event('File download', { props: { url: url.pathname } });
				sent = true;
			}
		}
	}
	if (sent) {
		// The page may be about to unload.
		flush();
	}
}

function begin(): void {
	startPage(bare(doc.referrer), pageProps);
	for (const args of queued) {
		api(args[0] as string, args[1] as EventOptions | undefined);
	}

	for (const name of ['pushState', 'replaceState'] as const) {
		const original = history[name];
		history[name] = function (this: History, ...args: Parameters<History['pushState']>) {
			const result = original.apply(this, args);
			navigated();
			return result;
		};
	}
	win.addEventListener('popstate', navigated);

	doc.addEventListener('visibilitychange', () => {
		if (doc.visibilityState === 'visible') {
			visibleFrom = Date.now();
			return;
		}
		visibleMs = visibleTime();
		visibleFrom = 0;
		engagement();
		flush();
	});
	win.addEventListener('pagehide', () => {
		engagement();
		flush();
	});
	// Back or forward from the browser's page cache: a new pageview.
	win.addEventListener('pageshow', (e) => {
		if (e.persisted) {
			startPage(bare(doc.referrer));
		}
	});
	win.addEventListener(
		'scroll',
		() => {
			if (!scrollPending) {
				scrollPending = true;
				requestAnimationFrame(measureScroll);
			}
		},
		{ passive: true },
	);
	doc.addEventListener('click', clicked, true);
	doc.addEventListener('auxclick', clicked, true);
}

const api: Api = (name, options) => {
	if (!off) {
		event(name, options);
	}
};
win.seoprostats = api;

if (!off) {
	if (doc.prerendering) {
		// Prerendered and maybe never shown: count it once it is.
		doc.addEventListener('prerenderingchange', begin, { once: true });
	} else {
		begin();
	}
}
