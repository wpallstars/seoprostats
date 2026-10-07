/**
 * Query coverage: how far a page's own words cover a search query, the
 * same way as SEOProStats_Coverage (includes/stats/class-seoprostats-coverage.php),
 * so an editor can re-check while people write.
 *
 * A query's terms are its words without common short words, lightly
 * stemmed (plural s). title: every term in the title or SEO title;
 * heading: every term in the headings; text: every term anywhere on the
 * page; partial: some; none: none.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { CoverageMatch, CoverageResult } from './types';

/** Short words a query's terms leave out (English). Keep in step with the PHP. */
const STOPWORDS = new Set([
	'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'did', 'do', 'does', 'for', 'from', 'has', 'have', 'how', 'i', 'if', 'in', 'into', 'is', 'it',
	'its', 'me', 'my', 'of', 'on', 'or', 'our', 'so', 'than', 'that', 'the', 'their', 'then', 'there', 'these', 'this', 'to', 'vs', 'was', 'we', 'were',
	'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'you', 'your',
]);

/** Words that start a question (English). */
const QUESTION_WORDS = new Set([
	'how', 'what', 'why', 'when', 'where', 'who', 'whom', 'whose', 'which', 'can', 'could', 'should', 'would', 'will', 'does', 'do', 'did', 'is', 'are',
	'was', 'were', 'am', 'may', 'might', 'shall',
]);

/** Letters WordPress's remove_accents() spells out rather than strips. */
const SPELLED: Record<string, string> = { ß: 'ss', æ: 'ae', œ: 'oe', ø: 'o', đ: 'd', ð: 'd', ł: 'l', þ: 'th', ı: 'i' };

const ENTITIES: Record<string, string> = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

/** What a page says, as the coverage answer and the editor give it. */
export interface PageText {
	title: string;
	/** HTML (blocks or classic). */
	content: string;
	excerpt?: string;
	seoTitle?: string;
	description?: string;
}

/** A page's words, ready for matching. */
export interface PageIndex {
	title: Set<string>;
	heading: Set<string>;
	all: Set<string>;
	sequence: string;
	words: number;
}

/** Words of a text: lower case, accents removed, split on anything not a letter or digit. */
export function words(text: string): string[] {
	const plain = text
		.toLowerCase()
		.replace(/[ßæœøđðłþı]/g, (c) => SPELLED[c] ?? c)
		.normalize('NFD')
		.replace(/\p{M}+/gu, '');
	return plain.split(/[^\p{L}\p{N}]+/u).filter(Boolean);
}

/** A light stem: plural endings off (studies → study, links → link). */
export function stem(word: string): string {
	const length = [...word].length;
	if (length > 4 && word.endsWith('ies')) {
		return `${word.slice(0, -3)}y`;
	}
	if (length > 3 && word.endsWith('s') && !/(ss|us|is)$/.test(word)) {
		return word.slice(0, -1);
	}
	return word;
}

function stems(text: string): string[] {
	return words(text).map(stem);
}

/** A query's terms: stem → first word, without short common words (all its words when that leaves none). */
function terms(query: string): Map<string, string> {
	const all = words(query);
	const kept = all.filter((w) => !STOPWORDS.has(w) && !/^[a-z]$/.test(w));
	const out = new Map<string, string>();
	for (const w of kept.length ? kept : all) {
		const s = stem(w);
		if (!out.has(s)) {
			out.set(s, w);
		}
	}
	return out;
}

/** Whether a query is a question: it starts with a question word or holds a question mark. */
export function isQuestion(query: string): boolean {
	const first = words(query)[0];
	return query.includes('?') || (first !== undefined && QUESTION_WORDS.has(first));
}

function decode(text: string): string {
	return text.replace(/&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi, (whole, name: string) => {
		if (name[0] === '#') {
			const code = name[1] === 'x' || name[1] === 'X' ? parseInt(name.slice(2), 16) : parseInt(name.slice(1), 10);
			return Number.isFinite(code) && code > 0 && code < 0x110000 ? String.fromCodePoint(code) : ' ';
		}
		return ENTITIES[name.toLowerCase()] ?? whole;
	});
}

/** Text of HTML: tags, scripts and styles out, entities decoded. */
function plain(html: string): string {
	return decode(html.replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ').replace(/<[^>]*>/g, ' '));
}

/** Characters of a page's text read at most. */
const MAX_TEXT = 200000;

/** A page's words: term sets of the title, the headings and the whole page, and its word sequence. */
export function pageIndex(text: PageText): PageIndex {
	const html = text.content.replace(/\[\/?[a-zA-Z][^[\]]*\]/g, ' ').slice(0, MAX_TEXT);
	const headings = [...html.matchAll(/<h[1-6]\b[^>]*>([\s\S]*?)<\/h[1-6]>/gi)].map((m) => m[1] ?? '');
	const alts = [...html.matchAll(/\balt\s*=\s*"([^"]*)"/gi)].map((m) => m[1] ?? '');
	const title = `${text.title} ${text.seoTitle ?? ''}`;
	const body = `${plain(html)} ${plain(alts.join(' '))} ${plain(text.excerpt ?? '')} ${text.description ?? ''}`;
	const all = stems(`${title} ${body}`);
	return {
		title: new Set(stems(title)),
		heading: new Set(stems(plain(headings.join(' ')))),
		all: new Set(all),
		sequence: ` ${all.join(' ')} `,
		words: words(body).length,
	};
}

/** How far a page's words cover a query. */
export function coverage(query: string, index: PageIndex | null): CoverageResult {
	const want = terms(query);
	const question = isQuestion(query);
	if (!index || !want.size) {
		return { match: 'none', phrase: false, missing: [...want.values()], question };
	}
	const missing = [...want].filter(([s]) => !index.all.has(s)).map(([, w]) => w);
	let match: CoverageMatch = 'none';
	if (!missing.length) {
		match = 'text';
		for (const place of ['heading', 'title'] as const) {
			if ([...want.keys()].every((s) => index[place].has(s))) {
				match = place;
			}
		}
	} else if (missing.length < want.size) {
		match = 'partial';
	}
	const phrase = (match === 'title' || match === 'heading' || match === 'text') && index.sequence.includes(` ${stems(query).join(' ')} `);
	return { match, phrase, missing, question };
}

/** Whether a match leaves words out. */
export function isMissing(match: CoverageMatch): boolean {
	return match === 'partial' || match === 'none';
}
