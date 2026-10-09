/**
 * Sign in with Google for SEO Pro Stats' Search Console connection: a
 * stateless relay (Cloudflare Worker) that holds the OAuth client's secret,
 * so the plugin never ships it.
 *
 * GET  /start     The plugin sends the admin to it with the site's return
 *                 address and a one-time nonce. A page names the site, and
 *                 Continue goes to Google's consent screen.
 * GET  /callback  Google's redirect: checks the signed state, swaps the
 *                 code for tokens and posts them to the site in a form
 *                 (never in an address).
 * POST /refresh   The site sends its refresh token; the answer is a new
 *                 access token. Google rejects tokens it did not issue to
 *                 this client, so no list of sites is kept.
 *
 * Nothing is stored and nothing is logged. Design: docs/architecture.md →
 * Search Console → Sign in with Google.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 */

const GOOGLE_AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
// Seconds from /start to /callback (time to sign in and consent).
const STATE_TTL = 900;
const NONCE = /^[A-Za-z0-9_-]{32,128}$/;
// Return addresses on plain http are allowed only for local test sites.
const LOCAL_HOST = /(^localhost$|^127\.0\.0\.1$|\.local$|\.test$|\.localhost$)/;

const encoder = new TextEncoder();

export default {
	async fetch(request, env) {
		const url = new URL(request.url);
		try {
			if (request.method === 'GET' && url.pathname === '/') {
				return home(env);
			}
			if (request.method === 'GET' && url.pathname === '/start') {
				return await start(url, env);
			}
			if (request.method === 'GET' && url.pathname === '/callback') {
				return await callback(url, env);
			}
			if (request.method === 'POST' && url.pathname === '/refresh') {
				return await refresh(request, env);
			}
			return page('Not found', html`<p>There is nothing here.</p>`, 404);
		} catch {
			// No details: they could hold a token.
			return page('Something went wrong', html`<p>Please go back to your site and try again.</p>`, 500);
		}
	},
};

/** The relay's own page. */
function home(env) {
	return page(
		'SEO Pro Stats: Sign in with Google',
		html`<p>This service lets the SEO Pro Stats WordPress plugin connect a site to Google Search Console with one sign-in. It passes Google's answer straight to the site that asked and keeps nothing.</p>
<p>It asks Google only for read access to Search Console. Your site stores its access, encrypted, and can disconnect at any time.</p>
<p><a href="${env.PRIVACY_URL}">Privacy policy</a> · <a href="${env.PLUGIN_URL}">SEO Pro Stats</a></p>`
	);
}

/** GET /start?site=…&nonce=…: name the site, then on to Google. */
async function start(url, env) {
	const site = returnAddress(url.searchParams.get('site'));
	const nonce = url.searchParams.get('nonce') || '';
	if (!site || !NONCE.test(nonce)) {
		return page('This link is not valid', html`<p>Start again from Settings → Connections on your site.</p>`, 400);
	}
	const state = await sign(env, { s: site.href, n: nonce, t: Math.floor(Date.now() / 1000) });
	const auth = new URL(GOOGLE_AUTH);
	auth.search = new URLSearchParams({
		client_id: env.GOOGLE_CLIENT_ID,
		redirect_uri: callbackAddress(url),
		response_type: 'code',
		scope: SCOPE,
		access_type: 'offline',
		prompt: 'consent',
		include_granted_scopes: 'false',
		state,
	}).toString();
	return page(
		'Connect Google Search Console',
		html`<p>SEO Pro Stats on <strong>${site.host}</strong> asks to read your Search Console data (search clicks, impressions, position, sitemaps and URL inspections). It cannot change anything.</p>
<p>Continue only if you started this from that site's wp-admin.</p>
<p><a class="button" href="${auth.href}">Continue with Google</a> <a href="${site.origin}">Cancel</a></p>`
	);
}

/** GET /callback: Google's answer, swapped for tokens and posted to the site. */
async function callback(url, env) {
	const state = await verify(env, url.searchParams.get('state') || '');
	const site = state ? returnAddress(state.s) : null;
	if (!site || !NONCE.test(state.n || '')) {
		return page('This sign-in has expired', html`<p>Start again from Settings → Connections on your site.</p>`, 400);
	}
	const fail = (error) => postBack(site, { nonce: state.n, error });
	if (url.searchParams.get('error')) {
		return fail(url.searchParams.get('error') === 'access_denied' ? 'access_denied' : 'google_error');
	}
	const code = url.searchParams.get('code') || '';
	if (!code) {
		return fail('google_error');
	}
	const answer = await google(env, {
		grant_type: 'authorization_code',
		code,
		redirect_uri: callbackAddress(url),
	});
	if (!answer.ok) {
		return fail('exchange_failed');
	}
	const tokens = answer.body;
	if (!String(tokens.scope || '').split(' ').includes(SCOPE)) {
		// The person unticked Search Console on Google's screen.
		return fail('scope_denied');
	}
	if (!tokens.refresh_token) {
		return fail('no_refresh_token');
	}
	return postBack(site, {
		nonce: state.n,
		refresh_token: tokens.refresh_token,
		access_token: tokens.access_token || '',
		expires_in: String(tokens.expires_in || ''),
	});
}

/** POST /refresh {refresh_token}: a new access token. */
async function refresh(request, env) {
	let input = {};
	try {
		input = await request.json();
	} catch {
		input = {};
	}
	const token = typeof input.refresh_token === 'string' ? input.refresh_token : '';
	if (token.length < 10 || token.length > 512) {
		return json({ error: 'invalid_request' }, 400);
	}
	const answer = await google(env, { grant_type: 'refresh_token', refresh_token: token });
	if (!answer.ok) {
		const error = typeof answer.body.error === 'string' ? answer.body.error : 'refresh_failed';
		return json({ error }, answer.status >= 400 && answer.status < 500 ? answer.status : 502);
	}
	return json({
		access_token: answer.body.access_token,
		expires_in: answer.body.expires_in,
		scope: answer.body.scope,
	});
}

/** A request to Google's token endpoint with the client's credentials. */
async function google(env, params) {
	const response = await fetch(GOOGLE_TOKEN, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({
			client_id: env.GOOGLE_CLIENT_ID,
			client_secret: env.GOOGLE_CLIENT_SECRET,
			...params,
		}).toString(),
	});
	let body = {};
	try {
		body = await response.json();
	} catch {
		body = {};
	}
	return { ok: response.ok && typeof body === 'object' && body !== null, status: response.status, body: body || {} };
}

/** The site's return address, or null: https, or http for a local test site. */
function returnAddress(value) {
	if (typeof value !== 'string' || value.length > 2048) {
		return null;
	}
	let site;
	try {
		site = new URL(value);
	} catch {
		return null;
	}
	if (site.username || site.password || site.hash) {
		return null;
	}
	if (site.protocol === 'https:' || (site.protocol === 'http:' && LOCAL_HOST.test(site.hostname))) {
		return site;
	}
	return null;
}

/** This relay's callback address, as registered with Google. */
function callbackAddress(url) {
	return `${url.origin}/callback`;
}

/** The post-back page's only script: fixed text, allowed by its hash in the CSP. */
const SUBMIT_TAG = "<script>document.getElementById('f').submit();</script>";
const SUBMIT_JS = SUBMIT_TAG.slice('<script>'.length, -'</script>'.length);

/** A page that posts the fields to the site at once (a button without JavaScript). */
async function postBack(site, fields) {
	const digest = await crypto.subtle.digest('SHA-256', encoder.encode(SUBMIT_JS));
	const hash = btoa(String.fromCharCode(...new Uint8Array(digest)));
	const inputs = Object.entries(fields).map(([name, value]) => html`<input type="hidden" name="${name}" value="${value}">`);
	const form = html`<form id="f" method="post" action="${site.href}">${inputs}<p>Returning you to ${site.host}…</p><noscript><button type="submit">Continue</button></noscript></form>`;
	const csp = ["default-src 'none'", "style-src 'unsafe-inline'", "script-src 'sha256-" + hash + "'", 'form-action ' + site.origin, "base-uri 'none'", "frame-ancestors 'none'"].join('; ');
	return page('Returning to your site', form, 200, { 'Content-Security-Policy': csp }, true);
}

/** Signed state: base64url(JSON).base64url(HMAC-SHA256). */
async function sign(env, data) {
	const payload = b64url(encoder.encode(JSON.stringify(data)));
	return `${payload}.${b64url(await hmac(env, payload))}`;
}

/** The state's data when its signature is right and it is recent, else null. */
async function verify(env, state) {
	const [payload, signature] = state.split('.');
	if (!payload || !signature) {
		return null;
	}
	let given;
	try {
		given = unb64url(signature);
	} catch {
		return null;
	}
	const key = await crypto.subtle.importKey('raw', encoder.encode(env.STATE_KEY), { name: 'HMAC', hash: 'SHA-256' }, false, ['verify']);
	if (!(await crypto.subtle.verify('HMAC', key, given, encoder.encode(payload)))) {
		return null;
	}
	let data;
	try {
		data = JSON.parse(new TextDecoder().decode(unb64url(payload)));
	} catch {
		return null;
	}
	const age = Math.floor(Date.now() / 1000) - Number(data.t);
	return age >= 0 && age <= STATE_TTL ? data : null;
}

async function hmac(env, payload) {
	const key = await crypto.subtle.importKey('raw', encoder.encode(env.STATE_KEY), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
	return new Uint8Array(await crypto.subtle.sign('HMAC', key, encoder.encode(payload)));
}

function b64url(bytes) {
	let text = '';
	for (const byte of bytes) {
		text += String.fromCharCode(byte);
	}
	return btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function unb64url(text) {
	const plain = atob(text.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((text.length + 3) % 4));
	return Uint8Array.from(plain, (c) => c.charCodeAt(0));
}

function esc(value) {
	return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** Markup made by html``: inserted into other html`` as it is. */
class Safe {
	constructor(text) {
		this.text = text;
	}
	toString() {
		return this.text;
	}
}

/** Tagged template for HTML: every value is escaped, unless it is html`` already (or a list of them). */
function html(strings, ...values) {
	let out = strings[0];
	values.forEach((value, i) => {
		const parts = Array.isArray(value) ? value : [value];
		out += parts.map((part) => (part instanceof Safe ? part.text : esc(part))).join('') + strings[i + 1];
	});
	return new Safe(out);
}

const STYLE = 'body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:36em;margin:4em auto;padding:0 1em;color:#1e1e1e}h1{font-size:1.4em}.button{display:inline-block;background:#2271b1;color:#fff;padding:.5em 1em;border-radius:3px;text-decoration:none;margin-right:1em}@media (prefers-color-scheme:dark){body{background:#1e1e1e;color:#f0f0f0}a{color:#72aee6}}';

/** An HTML page; submit adds the fixed SUBMIT_TAG before </body> (the post-back page). */
function page(title, body, status = 200, headers = {}, submit = false) {
	const document = html`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>${title}</title><style>${new Safe(STYLE)}</style></head><body><h1>${title}</h1>${body}</body></html>`;
	const markup = submit ? document.text.replace(/<\/body><\/html>$/, SUBMIT_TAG + '</body></html>') : document.text;
	return new Response(markup, {
		status,
		headers: {
			'Content-Type': 'text/html; charset=utf-8',
			'Cache-Control': 'no-store',
			'Referrer-Policy': 'no-referrer',
			'X-Content-Type-Options': 'nosniff',
			'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
			...headers,
		},
	});
}

function json(data, status = 200) {
	return new Response(JSON.stringify(data), {
		status,
		headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' },
	});
}
