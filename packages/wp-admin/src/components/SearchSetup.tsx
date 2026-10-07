/**
 * Search without search days on live data: how to connect the engine's
 * source (Search Console, Bing Webmaster Tools), or that its days are on
 * their way. Shared by Rankings, Opportunities and Content, with the
 * engine switch and the engine's names.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect } from 'react';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import type { SearchEngine } from '@seoprostats/core';
import type { ViewProps } from '../App';
import { boot } from '../boot';
import { useDataSet } from '../data';

/** A page and query to open Rankings with. */
export interface SearchPick {
	page: string;
	query: string;
}

/** A search report's props: the view's, and where to say which engines have data. */
export type SearchReportProps = ViewProps & { onEngines?: (engines: SearchEngine[]) => void };

/** Pass on the engines an answer lists, for the engine switch. */
export function useReportEngines(answer: { engines?: SearchEngine[] } | undefined, onEngines?: (engines: SearchEngine[]) => void): void {
	const list = answer?.engines;
	useEffect(() => {
		if (list && onEngines) {
			onEngines(list);
		}
	}, [list, onEngines]);
}

/** An engine's name: Google, Bing. */
export function engineName(engine: SearchEngine): string {
	return engine === 'bing' ? __('Bing', 'seoprostats') : __('Google', 'seoprostats');
}

/** The source of an engine's figures: Google Search Console, Bing Webmaster Tools. */
export function sourceName(engine: SearchEngine): string {
	return engine === 'bing' ? __('Bing Webmaster Tools', 'seoprostats') : __('Google Search Console', 'seoprostats');
}

export function SearchSetup({ answer }: { answer: { through: string; connected: boolean; engine?: SearchEngine } }) {
	const demo = useDataSet() === 'demo';
	if (demo || answer.through) {
		return null;
	}
	const connections = boot.canManage && boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'connections' }) : '';
	const bing = answer.engine === 'bing';
	let text: string;
	if (bing) {
		text = answer.connected
			? __('Bing Webmaster Tools is connected. Its days are imported in the background, each week once Bing has it, about a week later; they show here as they arrive.', 'seoprostats')
			: __('Connect Bing Webmaster Tools to see Bing’s searches next to Google’s: clicks and impressions by day, and the top pages and queries by week with their average position.', 'seoprostats');
	} else {
		text = answer.connected
			? __('Search Console is connected. Its days are imported in the background, the newest about three days old; they show here as they arrive.', 'seoprostats')
			: __('Connect Google Search Console to see the searches that show your pages: clicks, impressions, CTR and average position, next to your visits and changes.', 'seoprostats');
	}
	return (
		<Notice status="info" isDismissible={false} className="spst-notice">
			{text}{' '}
			{!answer.connected && connections && <a href={connections}>{__('Settings → Connections', 'seoprostats')}</a>}
		</Notice>
	);
}

/** Google or Bing, when there is more than one engine (or Bing is chosen). */
export function EngineSwitch({ engines, engine, choose }: { engines: SearchEngine[]; engine: SearchEngine; choose: (engine: SearchEngine) => void }) {
	const shown: SearchEngine[] = engines.includes(engine) ? engines : [...engines, engine];
	if (shown.length < 2) {
		return null;
	}
	return (
		<div className="spst-engines" role="group" aria-label={__('Search engine', 'seoprostats')}>
			{shown.map((e) => (
				<Button key={e} size="small" variant={e === engine ? 'primary' : 'secondary'} aria-pressed={e === engine} onClick={() => choose(e)}>
					{engineName(e)}
				</Button>
			))}
		</div>
	);
}
