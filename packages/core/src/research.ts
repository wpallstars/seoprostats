/**
 * Portable, browser-only research links. No results are fetched or counted.
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 */
export const RESEARCH_ENGINES = ['google', 'bing', 'brave', 'duckduckgo'] as const;
export type ResearchEngine = (typeof RESEARCH_ENGINES)[number];
export interface ResearchLink { kind: string; description: string; query: string; url: string; supported: boolean }

const SEARCH_URLS: Record<ResearchEngine, string> = {
	google: 'https://www.google.com/search',
	bing: 'https://www.bing.com/search',
	brave: 'https://search.brave.com/search',
	duckduckgo: 'https://duckduckgo.com/',
};

export function researchUrl(engine: ResearchEngine, query: string): string {
	return `${SEARCH_URLS[engine]}?q=${encodeURIComponent(query)}`;
}

/** Control characters become spaces and quotes are dropped, so the text cannot break out of a phrase. */
function cleanQuery(text: string): string {
	return Array.from(text, (character) => {
		const code = character.codePointAt(0) ?? 0;
		return code < 32 || code === 127 ? ' ' : character;
	}).join('').replace(/["“”]/g, ' ').replace(/\s+/g, ' ').trim();
}

/**
 * Google's allintitle search for every word of the query, in any order, as the
 * Keyword Golden Ratio counts it. Unquoted: quotes would make it an exact-phrase
 * title search and undercount the competition. No space after the colon.
 */
export function allintitleQuery(text: string): string {
	return `allintitle:${cleanQuery(text)}`;
}

/** The longest lexical word (the first on a tie) is a predictable URL-word seed that skips short filler words such as "how" or "the". */
export function researchLinks(engine: ResearchEngine, text: string, site: string): ResearchLink[] {
	const query = cleanQuery(text);
	if (!query) return [];
	const phrase = `"${query}"`;
	const word = (query.match(/[\p{L}\p{N}]+/gu) ?? []).reduce((longest, next) => (next.length > longest.length ? next : longest), '');
	const host = /^[a-z0-9.-]+$/i.test(site) ? site : '';
	const templates: [string, string, string, boolean][] = [
		['query', 'Search the query', query, true],
		['allintitle', 'All words in page titles, in any order (Google only)', allintitleQuery(query), engine === 'google'],
		['intitle', 'Find the phrase in page titles', `intitle:${phrase}`, true],
		['inurl', 'Find the longest word in page addresses', `inurl:${word}`, !!word && (engine === 'google' || engine === 'duckduckgo')],
	];
	if (host) templates.push(
		['competitors', 'Find pages outside this site', `${phrase} -site:${host}`, true],
		['site', 'Find this site’s pages and internal link sources', `site:${host} ${phrase}`, true],
	);
	// Google's OR binds only its neighbours: group it, or every result needs "forum" in its address.
	if (engine === 'google') templates.push(['forum', 'Find forum questions and discussions', `${phrase} (intitle:forum OR inurl:forum)`, true]);
	return templates.map(([kind, description, search, supported]) => ({ kind, description, query: search, url: researchUrl(engine, search), supported }));
}
