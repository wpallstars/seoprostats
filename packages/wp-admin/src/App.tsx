/**
 * The SEO Pro Stats screen: section tabs, the period and comparison, the
 * Live/Demo switch and filters (shared by every section), then the
 * section the URL hash names.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect } from 'react';
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

export interface ViewProps {
	state: ViewState;
	update: (patch: Partial<ViewState>) => void;
}

function viewLabel(view: View): string {
	const labels: Record<View, string> = {
		overview: __('Overview', 'seoprostats'),
		search: __('Search', 'seoprostats'),
		goals: __('Goals', 'seoprostats'),
		funnels: __('Funnels', 'seoprostats'),
		properties: __('Properties', 'seoprostats'),
		clicks: __('Clicks', 'seoprostats'),
		changes: __('Changes', 'seoprostats'),
	};
	return labels[view];
}

const NAV: View[] = ['overview', 'search', 'goals', 'funnels', 'properties', 'clicks', 'changes'];

/** Mark the admin submenu item of the section shown (they differ only by hash). */
function useMenuCurrent(view: View): void {
	useEffect(() => {
		const links = document.querySelectorAll<HTMLAnchorElement>('#toplevel_page_seoprostats-dashboard .wp-submenu a');
		links.forEach((link) => {
			const hash = link.hash.replace(/^#\/?/, '').split('?')[0] ?? '';
			const page = new URLSearchParams(link.search).get('page');
			if (page !== 'seoprostats-dashboard') {
				return;
			}
			const current = (hash || 'overview') === view;
			link.classList.toggle('current', current);
			link.parentElement?.classList.toggle('current', current);
			if (current) {
				link.setAttribute('aria-current', 'page');
			} else {
				link.removeAttribute('aria-current');
			}
		});
	}, [view]);
}

function ViewNav({ state }: { state: ViewState }) {
	return (
		<nav className="nav-tab-wrapper spst-nav" aria-label={__('Sections', 'seoprostats')}>
			{NAV.map((view) => (
				<a
					key={view}
					href={buildHash(switchView(state, view))}
					className={`nav-tab${state.view === view ? ' nav-tab-active' : ''}`}
					aria-current={state.view === view ? 'page' : undefined}
				>
					{viewLabel(view)}
				</a>
			))}
		</nav>
	);
}

export function App() {
	const [state, update] = useViewState();
	const data = useDataSet();
	const demo = useDemo();
	const waiting = data === 'demo' && demo.data.status !== 'ready';
	useMenuCurrent(state.view);

	const props: ViewProps = { state, update };
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
			<ViewNav state={state} />
			<div className="spst-toolbar">
				{!waiting && <Controls state={state} update={update} />}
				<div className="spst-toolbar__end">
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
