/**
 * Search queries in the editor: the Search Console queries this post's
 * page shows for, which of them its words do not cover yet, its
 * questions, and its SEO plugin's focus keywords. A panel in the block
 * editor's document sidebar, or a meta box in the classic editor
 * (SEOProStats_Editor). The queries come from GET /coverage; the words
 * are re-checked in the browser as people write (core coverage.ts), so
 * a query turns covered as soon as its words are added.
 *
 * WordPress's editor scripts (wp.plugins, wp.editor, wp.data) are read
 * from the page, as PHP lists them as dependencies.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useMemo, useState, type ComponentType, type ReactNode } from 'react';
import { createRoot } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { QueryClientProvider, useQuery } from '@tanstack/react-query';
import {
	buildHash,
	coverage,
	DEFAULT_STATE,
	formatDecimal,
	formatNumber,
	formatPercent,
	isMissing,
	pageIndex,
	type CoverageAnswer,
	type CoverageResult,
	type CoverageRow,
	type PageIndex,
	type PageText,
} from '@seoprostats/core';
import { errorMessage, get, queryClient } from './api';
import { boot, locale } from './boot';
import { CoverageBadges } from './components/CoverageBadges';
import { ResearchMenu } from './components/ResearchMenu';
import './editor.css';

interface EditorBoot {
	postId: number;
	mode: 'block' | 'classic';
}

interface EditorStore {
	getEditedPostAttribute(name: string): unknown;
	getEditedPostContent(): string;
}

interface TinyMCEEditor {
	isHidden(): boolean;
	getContent(): string;
}

declare global {
	interface Window {
		seoprostatsEditor?: Partial<EditorBoot>;
		wp?: {
			plugins?: { registerPlugin(name: string, settings: { render: ComponentType; icon?: unknown }): void };
			editor?: { PluginDocumentSettingPanel?: ComponentType<PanelProps> };
			editPost?: { PluginDocumentSettingPanel?: ComponentType<PanelProps> };
			data?: { select(store: string): unknown; subscribe(listener: () => void): () => void };
		};
		tinymce?: { get(id: string): TinyMCEEditor | null };
	}
}

interface PanelProps {
	name: string;
	title: string;
	className?: string;
	children?: ReactNode;
}

const editorBoot: EditorBoot = {
	postId: Number(window.seoprostatsEditor?.postId ?? 0),
	mode: window.seoprostatsEditor?.mode === 'classic' ? 'classic' : 'block',
};

/** Queries listed in each part of the panel. */
const SHOWN = 8;

/** Wait after typing before the words are read again (ms). */
const SETTLE = 800;

const number = (value: number) => formatNumber(value, locale, false);
const place = (value: number | null) => (value === null || value === 0 ? '–' : formatDecimal(value, locale));

/** The post's words now, from the block editor's store. */
function readBlock(): PageText | null {
	const store = window.wp?.data?.select('core/editor') as EditorStore | undefined;
	if (!store) {
		return null;
	}
	const text = (name: string) => {
		const value = store.getEditedPostAttribute(name);
		return typeof value === 'string' ? value : '';
	};
	return {
		title: text('title'),
		content: store.getEditedPostContent(),
		excerpt: text('excerpt'),
	};
}

/** The post's words now, from the classic editor's fields. */
function readClassic(): PageText {
	const value = (id: string) => (document.getElementById(id) as HTMLInputElement | HTMLTextAreaElement | null)?.value ?? '';
	const visual = window.tinymce?.get('content');
	return {
		title: value('title'),
		content: visual && !visual.isHidden() ? visual.getContent() : value('content'),
		excerpt: value('excerpt'),
	};
}

/** The post's words, read again a moment after each change. */
function usePageText(): PageText | null {
	const [text, setText] = useState<PageText | null>(null);
	useEffect(() => {
		let timer = 0;
		let last = '';
		const read = () => {
			const now = editorBoot.mode === 'classic' ? readClassic() : readBlock();
			const key = now ? `${now.title}\u0000${now.excerpt ?? ''}\u0000${now.content}` : '';
			if (now && key !== last) {
				last = key;
				setText(now);
			}
		};
		const later = () => {
			window.clearTimeout(timer);
			timer = window.setTimeout(read, SETTLE);
		};
		read();
		if (editorBoot.mode === 'classic') {
			// The visual editor writes to its own frame; reading on an interval catches both.
			const every = window.setInterval(read, SETTLE * 2);
			document.addEventListener('input', later);
			return () => {
				window.clearInterval(every);
				window.clearTimeout(timer);
				document.removeEventListener('input', later);
			};
		}
		const stop = window.wp?.data?.subscribe(later);
		return () => {
			window.clearTimeout(timer);
			stop?.();
		};
	}, []);
	return text;
}

function useCoverage() {
	return useQuery({
		queryKey: ['coverage', editorBoot.postId],
		queryFn: () => get<CoverageAnswer>('coverage', { post: editorBoot.postId, range: '90d' }),
		enabled: editorBoot.postId > 0,
	});
}

/** The Search section for this page, in the last 90 days. */
function searchUrl(page: string): string {
	if (!boot.dashboardUrl) {
		return '';
	}
	return (boot.dashboardUrl.split('#')[0] ?? '') + buildHash({ ...DEFAULT_STATE, view: 'search', range: '90d', compare: 'none', page });
}

/** A row checked against the words in the editor now (as the server checked the saved ones). */
function recheck<T extends CoverageResult>(row: T, words: string, index: PageIndex | null): T {
	return index ? { ...row, ...coverage(words, index) } : row;
}

function Coverage() {
	const query = useCoverage();
	const text = usePageText();
	const answer = query.data;

	// The saved SEO title and description stay as the server read them.
	const index = useMemo(
		() => (text && answer?.text ? pageIndex({ ...text, seoTitle: answer.text.seo_title, description: answer.text.description }) : null),
		[text, answer]
	);
	const rows = useMemo(() => (answer ? answer.rows.map((r) => recheck(r, r.query, index)) : []), [answer, index]);
	const focus = useMemo(() => (answer ? answer.focus.map((f) => recheck(f, f.keyword, index)) : []), [answer, index]);

	if (query.isError) {
		return (
			<Notice status="error" isDismissible={false}>
				{errorMessage(query.error, __('The search queries could not be loaded.', 'seoprostats'))}
			</Notice>
		);
	}
	if (!answer) {
		return <div className="spst-skeleton spst-editor__loading" aria-busy="true" />;
	}

	const missing = rows.filter((r) => isMissing(r.match));
	const questions = rows.filter((r) => r.question);
	const impressions = rows.reduce((sum, r) => sum + r.impressions, 0);
	const covered = rows.reduce((sum, r) => sum + (isMissing(r.match) ? 0 : r.impressions), 0);
	const link = searchUrl(answer.page);

	return (
		<div className="spst-editor">
			{!answer.connected && !answer.through && (
				<p className="spst-editor__note">
					{__('Connect Google Search Console in SEO Pro Stats to see the searches this page shows for.', 'seoprostats')}{' '}
					{boot.canManage && boot.settingsUrl && <a href={boot.settingsUrl}>{__('Settings', 'seoprostats')}</a>}
				</p>
			)}
			{answer.through !== '' && !rows.length && (
				<p className="spst-editor__note">{__('No searches showed this page in the last 90 days of search data.', 'seoprostats')}</p>
			)}
			{rows.length > 0 && (
				<p className="spst-editor__summary">
					{sprintf(
						/* translators: 1: number of search queries, 2: share of impressions, e.g. 80%. */
						_n(
							'%1$s search query in the last 90 days; %2$s of impressions are on queries the page’s words cover.',
							'%1$s search queries in the last 90 days; %2$s of impressions are on queries the page’s words cover.',
							rows.length,
							'seoprostats'
						),
						number(rows.length),
						formatPercent(impressions ? covered / impressions : 0, locale)
					)}
				</p>
			)}

			{focus.length > 0 && (
				<Part title={__('Focus keywords', 'seoprostats')}>
					{focus.map((f) => (
						<li key={f.keyword}>
							<strong className="spst-editor__query">{f.keyword}</strong>
							<CoverageBadges result={f} />
							<span className="spst-editor__meta">
								{f.searched
									? sprintf(
											/* translators: 1: impressions, 2: average position. */
											__('%1$s impressions · position %2$s', 'seoprostats'),
											number(f.impressions),
											place(f.position)
										)
									: __('Not a search this page showed for yet', 'seoprostats')}
							</span>
						</li>
					))}
				</Part>
			)}

			{missing.length > 0 && (
				<Part title={__('Not covered by the page’s words', 'seoprostats')} more={missing.length - SHOWN}>
					{missing.slice(0, SHOWN).map((r) => (
						<QueryItem key={r.query} row={r} />
					))}
				</Part>
			)}

			{questions.length > 0 && (
				<Part title={__('Questions people search', 'seoprostats')} more={questions.length - SHOWN}>
					{questions.slice(0, SHOWN).map((r) => (
						<QueryItem key={r.query} row={r} />
					))}
				</Part>
			)}

			{link && rows.length > 0 && (
				<p className="spst-editor__more">
					<a href={link}>{__('All queries in SEO Pro Stats', 'seoprostats')}</a>
				</p>
			)}
		</div>
	);
}

function Part({ title, more = 0, children }: Readonly<{ title: string; more?: number; children: ReactNode }>) {
	return (
		<div className="spst-editor__part">
			<h3 className="spst-editor__heading">{title}</h3>
			<ul className="spst-editor__list">{children}</ul>
			{more > 0 && (
				<p className="spst-editor__meta">
					{sprintf(/* translators: %s: number of queries. */ _n('and %s more', 'and %s more', more, 'seoprostats'), number(more))}
				</p>
			)}
		</div>
	);
}

function QueryItem({ row }: Readonly<{ row: CoverageRow }>) {
	return (
		<li>
			<strong className="spst-editor__query">{row.query}</strong>
			<ResearchMenu query={row.query} />
			<CoverageBadges result={row} />
			<span className="spst-editor__meta">
				{sprintf(
					/* translators: 1: impressions, 2: clicks, 3: average position. */
					__('%1$s impressions · %2$s clicks · position %3$s', 'seoprostats'),
					number(row.impressions),
					number(row.clicks),
					place(row.position)
				)}
			</span>
		</li>
	);
}

function App() {
	return (
		<QueryClientProvider client={queryClient}>
			<Coverage />
		</QueryClientProvider>
	);
}

function start(): void {
	if (editorBoot.mode === 'classic') {
		const el = document.getElementById('spst-coverage');
		if (el) {
			createRoot(el).render(<App />);
		}
		return;
	}
	const wp = window.wp;
	const Panel = wp?.editor?.PluginDocumentSettingPanel ?? wp?.editPost?.PluginDocumentSettingPanel;
	if (!wp?.plugins || !Panel) {
		return;
	}
	wp.plugins.registerPlugin('seoprostats-coverage', {
		icon: null,
		render: () => (
			<Panel name="seoprostats-coverage" title={__('Search queries', 'seoprostats')} className="spst-editor-panel">
				<App />
			</Panel>
		),
	});
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', start);
} else {
	start();
}
