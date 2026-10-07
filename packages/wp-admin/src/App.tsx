/**
 * The SEO Pro Stats screen: the period and comparison, the Live/Demo
 * switch and filters (shared by every section), then the section the URL
 * hash names. The section tabs above it are the server's (useNavCurrent).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useState } from 'react';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { buildHash, switchView, type View, type ViewState } from '@seoprostats/core';
import { useDemo } from './api';
import { useDataSet } from './data';
import { useViewState } from './hash';
import { Controls } from './components/Controls';
import { DemoNotice, DemoSwitch } from './components/Demo';
import { FilterBar } from './components/FilterBar';
import { Realtime } from './components/Realtime';
import { Overview } from './Overview';
import { Search } from './Search';
import { Goals } from './Goals';
import { Funnels } from './Funnels';
import { Properties } from './Properties';
import { Clicks } from './Clicks';
import { Changes } from './Changes';
import { boot } from './boot';
import { ShareEditor, Shares } from './Shares';

export interface ViewProps {
	state: ViewState;
	update: (patch: Partial<ViewState>) => void;
}

function markCurrent(link: HTMLAnchorElement, current: boolean, className: string, item: Element | null): void {
	link.classList.toggle(className, current);
	item?.classList.toggle(className, current);
	if (current) {
		link.setAttribute('aria-current', 'page');
	} else {
		link.removeAttribute('aria-current');
	}
}

/**
 * Mark the section shown (none for shared reports) in the admin submenu,
 * whose items differ only by hash, and in the screen's tabs, which the
 * server draws (SEOProStats_Dashboard::render()) like the settings
 * screen's. The tabs' links keep the period, comparison and filters.
 */
function useNavCurrent(state: ViewState, shown: View | null): void {
	useEffect(() => {
		document.querySelectorAll<HTMLAnchorElement>('#toplevel_page_seoprostats-dashboard .wp-submenu a').forEach((link) => {
			if (new URLSearchParams(link.search).get('page') !== 'seoprostats-dashboard') {
				return;
			}
			const hash = link.hash.replace(/^#\/?/, '').split('?')[0] ?? '';
			markCurrent(link, (hash || 'overview') === shown, 'current', link.parentElement);
		});
		document.querySelectorAll<HTMLAnchorElement>('#spst-dashboard-nav [data-spst-view]').forEach((link) => {
			const view = link.dataset.spstView as View;
			link.href = buildHash(switchView(state, view));
			markCurrent(link, view === shown, 'is-active', null);
		});
	}, [state, shown]);
}

export function App() {
	const [state, update] = useViewState();
	const [sharing, setSharing] = useState(false);
	const [link, setLink] = useState('');
	const [shares, setShares] = useState(window.location.hash.startsWith('#/shares'));
	useEffect(() => {
		const change = () => setShares(window.location.hash.startsWith('#/shares'));
		window.addEventListener('hashchange', change);
		return () => window.removeEventListener('hashchange', change);
	}, []);
	const data = useDataSet();
	const demo = useDemo();
	const waiting = data === 'demo' && demo.data.status !== 'ready';
	const sharesShown = shares && boot.canManage;
	useNavCurrent(state, sharesShown ? null : state.view);

	const props: ViewProps = { state, update };
	if (sharesShown) {
		return <div className="spst-app"><a href="#/overview">{__('Back to reports', 'seoprostats')}</a><Shares state={state} /></div>;
	}
	let section = <Overview {...props} />;
	if (state.view === 'search') {
		section = <Search {...props} />;
	} else if (state.view === 'goals') {
		section = <Goals {...props} />;
	} else if (state.view === 'funnels') {
		section = <Funnels {...props} />;
	} else if (state.view === 'properties') {
		section = <Properties {...props} />;
	} else if (state.view === 'clicks') {
		section = <Clicks {...props} />;
	} else if (state.view === 'changes') {
		section = <Changes {...props} />;
	}

	return (
		<div className="spst-app">
			{sharing && <ShareEditor state={state} close={() => setSharing(false)} saved={(share) => setLink(share.url ?? '')} />}
			{link && <Notice status="success" onRemove={() => setLink('')}><p>{__('Copy this private link now; it is shown only once.', 'seoprostats')}</p><input aria-label="Private link" readOnly value={link} onFocus={(event) => event.currentTarget.select()} /></Notice>}
			<div className="spst-toolbar">
				{!waiting && <Controls state={state} update={update} />}
				<div className="spst-toolbar__end">
					{boot.canManage && data === 'live' && ['overview', 'goals', 'clicks'].includes(state.view) && <Button variant="secondary" onClick={() => setSharing(true)}>{__('Share', 'seoprostats')}</Button>}
					{!waiting && <Realtime />}
					<DemoSwitch />
				</div>
			</div>

			<DemoNotice />

			{!waiting && (
				<>
					<FilterBar filters={state.filters} update={update} />
					{section}
				</>
			)}
		</div>
	);
}
