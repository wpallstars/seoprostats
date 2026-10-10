/**
 * @seoprostats/tracker: the browser script. Sends pageviews (with SPA
 * navigation), engagement (visible time, deepest scroll), events
 * (seoprostats('Name', {props, revenue}), data-sps-event, outbound,
 * affiliate and file links) and, with autocapture, clicks (dead when the
 * page does not react within a second) and form submits to the collector,
 * batched, as text/plain so there is no CORS preflight. Clicks in form
 * fields and what is typed or picked in a form are never sent; labels
 * mask emails and long numbers, and data-sps-mask hides them.
 * Design: docs/architecture.md → Collection → Tracker.
 *
 * The page as loaded also sends what WordPress knew about it (data-ctx:
 * not found, site search, the item shown, logged in). With ThriveCart
 * connected, a clicked ThriveCart link gets the page load's ID as
 * passthrough[spst], for the order webhook (SEOProStats_Purchases).
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
	/** Autocapture: clicks on things that can be clicked, and form submits. */
	c?: boolean;
	/** The site's affiliate link paths; * matches any characters. */
	a?: string[];
	/** ThriveCart is connected: its checkout links carry the page load's ID, so its order webhook joins the visit. */
	tc?: boolean;
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
type Hit = { t: 'pv' | 'eng' | 'e' | 'c' | 'f'; p: string } & Record<string, unknown>;

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
const FILE_TYPES = /\.(?:pdf|zipx?|rar|7z|gz|tgz|bz2|xz|tar|dmg|pkg|exe|msi|apk|iso|docx?|xlsx?|pptx?|od[tsp]|rtf|csv|txt|epub|mp3|m4a|wav|ogg|flac|mp4|m4v|mov|avi|wmv|webm|mkv)$/i; // NOSONAR: a flat list of extensions anchored at the end (no nesting or backtracking); one regex is the smallest form in the tracker.

/** Most hits, and bytes, per request (the collector takes 50 and 16 KB). */
const BATCH_HITS = 25;
const BATCH_BYTES = 15000;

/** Click flags (SEOProStats_Clicks): no reaction, another site, affiliate link, file. */
const DEAD = 1;
const OUTBOUND = 2;
const AFFILIATE = 4;
const DOWNLOAD = 8;

/** Things made to be clicked. */
const CLICKABLE =
	'a[href],button,summary,[role=button],[role=link],[role=tab],[role=menuitem],[onclick],input[type=button],input[type=submit],input[type=reset],input[type=image]';

/** Form fields: clicks in them are never sent (what someone picks is a field value). */
const FIELDS = 'input,select,textarea,label,option,[contenteditable],[role=checkbox],[role=radio],[role=switch],[role=option]';

/** Milliseconds a click waits for the page to react before it counts as dead. */
const DEAD_MS = 1000;

/** A running A/B test's wrapper, marked "test:variant" by its swap script (SEOProStats_AB_Tests). */
const AB = '[data-spst-ab]';

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
/** data-ctx: what WordPress knew about the page as loaded (SEOProStats_Tracker::context()), sent with its pageview only. */
const pageContext = attr('data-ctx');

const queued = (win.seoprostats && win.seoprostats.q) || []; // NOSONAR: the tracker builds for ES2018, where ?. compiles to longer code.
const allowed = PAGE_QUERY.concat((cfg.q || []).map((key) => String(key).toLowerCase()));
const own = (cfg.h || []).map((host) => String(host).toLowerCase()).concat(loc.hostname.toLowerCase());
/** Paths matching any of a list of globs (* matches any characters). */
const globs = (list?: string[]): RegExp[] =>
	(list || []).map((glob) => new RegExp('^' + String(glob).split('*').map((part) => part.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*') + '$')); // NOSONAR nosemgrep: a plain string is shorter than String.raw in the built tracker; the site owner's path globs, with every special character escaped, not visitor input.
const skipPaths = globs(cfg.x);
const affiliatePaths = globs(cfg.a);

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
/** Reactions seen (DOM change, scroll, focus, navigation): a click with none is dead. */
let reactions = 0;
/** Clicks waiting to see whether the page reacts. */
let waiting: { hit: Hit; seen: number; at: number }[] = [];
let observer: MutationObserver | undefined;

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
		if (CLICK_IDS.includes(name)) {
			keep.push(name + '=1');
		} else if (name.startsWith('utm_') || allowed.includes(name)) {
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
	if (nav.sendBeacon && nav.sendBeacon(cfg.u, new Blob([body], { type: 'text/plain' }))) { // NOSONAR: the tracker builds for ES2018, where ?. compiles to longer code.
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
	if (timer === undefined) { // NOSONAR: the tracker builds for ES2018, where ??= compiles to longer code.
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

/** A new page: its pageview, and fresh engagement counters. The page as loaded also sends its properties and context. */
function startPage(referrer: string, props?: unknown, context?: unknown): void {
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
	if (context && typeof context === 'object') {
		hit.x = context;
	}
	// The A/B test variants this page shows (up to 20).
	const ab = Array.from(doc.querySelectorAll<HTMLElement>(AB), (el) => el.dataset.spstAb).slice(0, 20);
	if (ab.length) {
		hit.ab = ab;
	}
	push(hit);
}

/** SPA navigation: a new pageview when the path or a kept parameter changed. */
function navigated(): void {
	reacted();
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
	const label = String(name || '').trim().slice(0, 120); // NOSONAR: page code may pass any value; the label is its text, as before.
	if (!label) {
		return;
	}
	const hit: Hit = { t: 'e', p: pageId, n: label };
	const props = cleanProps(options && options.props); // NOSONAR: the tracker builds for ES2018, where ?. compiles to longer code.
	if (props) {
		hit.d = props;
	}
	const revenue = options && options.revenue; // NOSONAR: the tracker builds for ES2018, where ?. compiles to longer code.
	if (revenue && isFinite(Number(revenue.amount)) && /^[A-Za-z]{3}$/.test(String(revenue.currency))) { // NOSONAR: the argument is already a number, so the global is the same test and shorter in the built tracker.
		hit.rv = { a: Number(revenue.amount), c: String(revenue.currency).toUpperCase() };
	}
	push(hit);
}

/** An address as clicks send it: a path on this site, origin and path elsewhere, the scheme alone for mailto: and tel:. */
function target(href: string): { to: string; flags: number } {
	let url: URL;
	try {
		url = new URL(href, loc.href);
	} catch {
		return { to: '', flags: 0 };
	}
	if (/^(?:mailto|tel|sms):$/.test(url.protocol)) {
		return { to: url.protocol, flags: 0 };
	}
	if (!/^https?:$/.test(url.protocol)) {
		return { to: '', flags: 0 };
	}
	const away = !own.includes(url.hostname.toLowerCase());
	let flags = FILE_TYPES.test(url.pathname) ? DOWNLOAD : 0;
	if (away) {
		flags |= OUTBOUND;
	} else if (affiliatePaths.some((path) => path.test(url.pathname))) {
		flags |= AFFILIATE;
	}
	return { to: away ? url.origin + url.pathname : url.pathname, flags };
}

/** The thing someone meant to click: made to be clicked, an image, or shown with a pointer. Never a form field. */
function clickable(el: Element): Element | null {
	const field = el.closest(FIELDS);
	if (field && !field.matches(CLICKABLE)) {
		return null;
	}
	const made = el.closest(CLICKABLE);
	if (made) {
		return made;
	}
	for (let i = 0, at: Element | null = el; at && at !== doc.body && i < 5; i++, at = at.parentElement) {
		if (at.tagName === 'IMG' || getComputedStyle(at).cursor === 'pointer') {
			return at;
		}
	}
	return null;
}

/** tag#id.class, leaving out generated names (with three digits in a row). */
function selector(el: Element): string {
	const name = (part: string): boolean => /^[A-Za-z_-][\w-]{0,39}$/.test(part) && !/\d{3}/.test(part);
	const id = el.getAttribute('id') || '';
	const classes = (el.getAttribute('class') || '').split(/\s+/).filter(name).slice(0, 3);
	return (el.tagName.toLowerCase() + (name(id) ? '#' + id : '') + (classes.length ? '.' + classes.join('.') : '')).slice(0, 120);
}

/** What an element says: its label, value or text, alt or title, or its image's alt. */
function says(el: Element): string {
	const image = el.querySelector('img[alt]');
	return el.getAttribute('aria-label') || (el instanceof HTMLInputElement ? el.value : (el as HTMLElement).innerText) || el.getAttribute('alt') || el.getAttribute('title') || (image && image.getAttribute('alt')) || ''; // NOSONAR: the tracker builds for ES2018, where ?. compiles to longer code.
}

/** Visible text (or the name given) of up to 60 characters, emails and long numbers masked; '' under data-sps-mask. */
function text(el: Element, name?: string): string {
	if (el.closest('[data-sps-mask]')) {
		return '';
	}
	const label = name !== undefined ? name : says(el); // NOSONAR: the tracker builds for ES2018, where ?? compiles to longer code.
	return String(label)
		.replace(/\s+/g, ' ')
		.replace(/[^\s@]+@[^\s@]+/g, '…@…') // NOSONAR: backtracking stays inside one run of text without spaces, once per click, in the visitor's own browser.
		.replace(/\+?\d(?:[\s().-]?\d){5,}/g, '#')
		.trim()
		.slice(0, 60);
}

/** Something happened on the page: clicks waiting now are not dead. */
function reacted(): void {
	reactions++;
}

/** Send the clicks waiting; dead when the page has not reacted since (all when the page is left). */
function settle(leaving: boolean): void {
	const now = Date.now();
	waiting = waiting.filter((item) => {
		if (!leaving && item.at > now) {
			return true;
		}
		if (!leaving && item.seen === reactions) {
			item.hit.f = (item.hit.f as number) | DEAD;
		}
		push(item.hit);
		return false;
	});
	if (!waiting.length && observer) {
		observer.disconnect();
		observer = undefined;
	}
}

/** Clicks: data-sps-event elements, outbound, affiliate and file links, and (autocapture) what was clicked. */
function clicked(e: MouseEvent): void { // NOSONAR: one function on purpose: the tracker loads on every page, and splitting it adds bytes.
	if ((e.type === 'auxclick' && e.button !== 1) || !(e.target instanceof Element)) {
		return;
	}
	let sent = false;
	const tagged = e.target.closest('[data-sps-event]');
	if (tagged instanceof HTMLElement) {
		// data-sps-prop-plan="pro" becomes the property plan.
		const props: Record<string, Scalar> = {};
		for (const [key, value] of Object.entries(tagged.dataset)) {
			if (key.startsWith('spsProp') && key.length > 7 && value !== undefined) {
				props[key.charAt(7).toLowerCase() + key.slice(8)] = value;
			}
		}
		event(tagged.dataset.spsEvent, { props });
		sent = true;
	}
	const link = e.target.closest('a[href]');
	let to = { to: '', flags: 0 };
	if (link instanceof HTMLAnchorElement) {
		if (cfg.tc && pageId && /(?:^|\.)thrivecart\.com$/i.test(link.hostname)) {
			// Before the browser follows it: ThriveCart sends passthrough fields back with the order.
			const url = new URL(link.href);
			url.searchParams.set('passthrough[spst]', pageId);
			link.href = url.href;
		}
		to = target(link.href);
		if (/(?:^|\s)sponsored(?:\s|$)/i.test(link.rel) && !(to.flags & AFFILIATE)) {
			to.flags |= AFFILIATE;
		}
		const url = { props: { url: to.to } };
		if (to.flags & AFFILIATE) {
			event('Affiliate link', url);
		} else if (to.flags & OUTBOUND) {
			event('Outbound link', url);
		} else if (to.flags & DOWNLOAD || (to.to[0] === '/' && link.hasAttribute('download'))) { // NOSONAR: one character compared; shorter than startsWith in the built tracker.
			to.flags |= DOWNLOAD;
			event('File download', url);
		}
		sent = sent || to.flags > 0;
	}

	const el = cfg.c ? clickable(e.target) : null;
	if (el && pageId) {
		const hit: Hit = { t: 'c', p: pageId, s: selector(el), l: text(el), h: to.to, f: to.flags };
		// Inside an A/B test variant: which one.
		const test = el.closest<HTMLElement>(AB);
		if (test) {
			hit.ab = test.dataset.spstAb;
		}
		// A link that leaves the page reacts by itself; anything else may do nothing.
		const leaves = link && to.to && !/^#|^javascript:/i.test(link.getAttribute('href') || '');
		if (leaves || sent) {
			push(hit);
			sent = true;
		} else {
			waiting.push({ hit, seen: reactions, at: Date.now() + DEAD_MS });
			if (!observer && typeof MutationObserver === 'function') {
				observer = new MutationObserver(reacted);
				observer.observe(doc.documentElement, { subtree: true, childList: true, attributes: true, characterData: true });
			}
			setTimeout(() => settle(false), DEAD_MS + 50);
		}
	}
	if (sent) {
		// The page may be about to unload.
		flush();
	}
}

/** Form submits (autocapture): the form's name, address and number of fields; never what is in them. */
function submitted(e: Event): void {
	const form = e.target;
	if (!cfg.c || !pageId || !(form instanceof HTMLFormElement)) {
		return;
	}
	let fields = 0;
	for (const field of Array.from(form.elements)) {
		if (field instanceof HTMLInputElement ? !/^(?:hidden|submit|button|reset|image)$/.test(field.type) : field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
			fields++;
		}
	}
	// getAttribute: form.action and form.name can be fields of those names.
	push({ t: 'f', p: pageId, s: selector(form), l: text(form, form.getAttribute('name') || form.getAttribute('id') || form.getAttribute('aria-label') || ''), h: target(form.getAttribute('action') || loc.href).to, n: fields });
	reacted();
	flush();
}

function begin(): void {
	startPage(bare(doc.referrer), pageProps, pageContext);
	const loadedKey = pageKey;
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

	// Hidden or left: stop the clock first, so pagehide and visibilitychange
	// (both fire on leaving, in either order) send one engagement, not two.
	const away = (): void => {
		visibleMs = visibleTime();
		visibleFrom = 0;
		engagement();
		settle(true);
		flush();
	};
	doc.addEventListener('visibilitychange', () => {
		if (doc.visibilityState === 'visible') {
			visibleFrom = Date.now();
		} else {
			away();
		}
	});
	win.addEventListener('pagehide', away);
	// Back or forward from the browser's page cache: a new pageview of the same document.
	win.addEventListener('pageshow', (e) => {
		if (e.persisted) {
			startPage(bare(doc.referrer), undefined, pagePath() === loadedKey ? pageContext : undefined);
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
	if (cfg.c) {
		doc.addEventListener('submit', submitted, true);
		// What counts as the page reacting to a click, besides changes to it.
		doc.addEventListener('scroll', reacted, { capture: true, passive: true });
		doc.addEventListener('focusin', reacted);
		win.addEventListener('hashchange', reacted);
		win.addEventListener('blur', reacted);
	}
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
