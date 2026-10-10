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

/** The first lexical word is an explicit, predictable URL-word seed. */
export function researchLinks(engine: ResearchEngine, text: string, site: string): ResearchLink[] {
	const query = Array.from(text, (character) => {
		const code = character.codePointAt(0) ?? 0;
		return code < 32 || code === 127 ? ' ' : character;
	}).join('').replace(/["“”]/g, ' ').trim();
	if (!query) return [];
	const phrase = `"${query}"`;
	const word = /[\p{L}\p{N}]+/u.exec(query)?.[0] ?? '';
	const host = /^[a-z0-9.-]+$/i.test(site) ? site : '';
	const templates: [string, string, string, boolean][] = [
		['query', 'Search the query', query, true],
		['allintitle', 'All words in page titles (Google only)', `allintitle:${phrase}`, engine === 'google'],
		['intitle', 'Find the phrase in page titles', `intitle:${phrase}`, true],
		['inurl', 'Find the first word in page addresses', `inurl:${word}`, !!word && (engine === 'google' || engine === 'duckduckgo')],
	];
	if (host) templates.push(
		['competitors', 'Find pages outside this site', `${phrase} -site:${host}`, true],
		['site', 'Find this site’s pages and internal link sources', `site:${host} ${phrase}`, true],
	);
	if (engine === 'google') templates.push(['forum', 'Find forum questions and discussions', `${phrase} intitle:forum OR ${phrase} inurl:forum`, true]);
	return templates.map(([kind, description, search, supported]) => ({ kind, description, query: search, url: researchUrl(engine, search), supported }));
}
