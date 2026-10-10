/**
 * WordPress's keyboard-accessible research menu; navigation only, no scraping.
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 */
import { Button, DropdownMenu, MenuGroup } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { RESEARCH_ENGINES, researchLinks } from '@seoprostats/core';
import { boot } from '../boot';

export function ResearchMenu({ query }: Readonly<{ query: string }>) {
	const descriptions: Record<string, string> = {
		query: __('Search the query', 'seoprostats'),
		allintitle: __('All words in titles', 'seoprostats'),
		intitle: __('Phrase in titles', 'seoprostats'),
		inurl: __('First word in addresses', 'seoprostats'),
		competitors: __('Pages outside this site', 'seoprostats'),
		site: __('This site’s pages', 'seoprostats'),
		forum: __('Forum questions', 'seoprostats'),
	};
	/* translators: a button that opens a menu of searches; the ellipsis shows it opens a menu. */
	const more = __('Research…', 'seoprostats');
	// A space, not a margin: a line break drops it, so a menu that wraps starts its line flush (spst-research__gap widens it).
	return (
		<>
			<span className="spst-research__gap">{' '}</span>
			<DropdownMenu
				className="spst-research"
				label={sprintf(/* translators: %s: query. */ __('Research “%s”', 'seoprostats'), query)}
				text={more}
				icon={null}
				toggleProps={{ variant: 'link', className: 'spst-research__toggle' }}
				popoverProps={{ flip: true, shift: true, resize: false }}
			>
				{() => (
					<div style={{ width: 'min(320px, calc(100vw - 48px))', maxHeight: 'min(60vh, 480px)', overflowY: 'auto' }}>
						{RESEARCH_ENGINES.map((engine) => (
							<MenuGroup key={engine} label={{ google: 'Google', bing: 'Bing', brave: 'Brave', duckduckgo: 'DuckDuckGo' }[engine]}>
								{researchLinks(engine, query, boot.siteHost).map((link) => (
									<Button role="menuitem" className="components-menu-item__button" key={link.kind} href={link.supported ? link.url : undefined} disabled={!link.supported} target="_blank" rel="noopener noreferrer" title={link.query}
										style={{ width: '100%', height: 'auto', minHeight: 36, whiteSpace: 'normal', overflowWrap: 'anywhere' }}
										aria-label={`${descriptions[link.kind]} — ${link.supported ? __('Opens in a new tab', 'seoprostats') : __('Not supported', 'seoprostats')}`}>
										{descriptions[link.kind]}{!link.supported && ` — ${__('Not supported', 'seoprostats')}`}
									</Button>
								))}
							</MenuGroup>
						))}
					</div>
				)}
			</DropdownMenu>
		</>
	);
}
