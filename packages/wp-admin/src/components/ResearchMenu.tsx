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

export function ResearchMenu({ query }: { query: string }) {
	return <DropdownMenu label={sprintf(/* translators: %s: query. */ __('Research “%s”', 'seoprostats'), query)} text={__('Research', 'seoprostats')} icon={null}>
		{() => <>{RESEARCH_ENGINES.map((engine) => <MenuGroup key={engine} label={{ google: 'Google', bing: 'Bing', brave: 'Brave', duckduckgo: 'DuckDuckGo' }[engine]}>
			{researchLinks(engine, query, boot.siteHost).map((link) => <Button role="menuitem" className="components-menu-item__button" key={link.kind} href={link.supported ? link.url : undefined} disabled={!link.supported} target="_blank" rel="noopener noreferrer" title={link.description}>
				{link.query}{!link.supported ? ` — ${__('Not supported', 'seoprostats')}` : ` — ${__('Opens in a new tab', 'seoprostats')}`}
			</Button>)}
		</MenuGroup>)}</>}
	</DropdownMenu>;
}
