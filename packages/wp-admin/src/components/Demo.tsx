/**
 * The Live/Demo switch, and what the demo data is (or making it, for
 * administrators). Demo data lives in tables of its own (SEOProStats_Demo),
 * so live statistics are never changed by it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useRef, useState } from 'react';
import { Notice, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage, makeDemo, queryClient, removeDemo, saveView, useDemo } from '../api';
import { boot, type DataSet, type DemoStatus } from '../boot';
import { setDataSet, useDataSet } from '../data';

function show(data: DataSet): void {
	setDataSet(data);
	// The choice is kept for the next visit; showing it does not wait.
	void saveView(data).catch(() => undefined);
}

function store(status: DemoStatus): void {
	queryClient.setQueryData(['demo'], status);
}

/** Reports may hold answers from older demo data: ask again. */
function refreshReports(): void {
	void queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'demo' });
}

export function DemoSwitch() {
	const data = useDataSet();
	return (
		<ToggleControl
			__nextHasNoMarginBottom
			className="spst-demo-switch"
			label={__('Demo data', 'seoprostats')}
			checked={data === 'demo'}
			onChange={(on: boolean) => show(on ? 'demo' : 'live')}
		/>
	);
}

/** Shown above the reports while the demo data is on. */
export function DemoNotice() {
	const data = useDataSet();
	const demo = useDemo();
	const [error, setError] = useState<string | null>(null);
	const busy = useRef(false);
	const restart = useRef(false);
	const status = demo.data;

	// While making: one slice of work per request, each answer asking for
	// the next, until ready.
	useEffect(() => {
		if (data !== 'demo' || status.status !== 'making' || !boot.canManage || busy.current || error) {
			return;
		}
		busy.current = true;
		const again = restart.current;
		restart.current = false;
		makeDemo(again)
			.then((next) => {
				busy.current = false;
				store(next);
				if (next.status === 'ready') {
					refreshReports();
				}
			})
			.catch((e: unknown) => {
				busy.current = false;
				setError(errorMessage(e, __('The demo data could not be made.', 'seoprostats')));
			});
	}, [data, status, error]);

	if (data !== 'demo') {
		return null;
	}

	const start = (again: boolean) => {
		restart.current = again;
		setError(null);
		store({ ...status, status: 'making', progress: 0 });
	};

	const remove = () => {
		// eslint-disable-next-line no-alert -- a plain confirmation, as WordPress uses for deleting.
		if (!window.confirm(__('Remove the demo data? Your live statistics are not changed.', 'seoprostats'))) {
			return;
		}
		removeDemo()
			.then((next) => {
				store(next);
				show('live');
			})
			.catch((e: unknown) => setError(errorMessage(e, __('The demo data could not be removed.', 'seoprostats'))));
	};

	if (error) {
		return (
			<Notice status="error" isDismissible={false} className="spst-notice" actions={[{ label: __('Try again', 'seoprostats'), onClick: () => setError(null), variant: 'secondary' }]}>
				{error}
			</Notice>
		);
	}

	if (status.status === 'ready') {
		return (
			<Notice
				status="warning"
				isDismissible={false}
				className="spst-notice spst-demo-notice"
				actions={[
					{ label: __('Show live data', 'seoprostats'), onClick: () => show('live'), variant: 'secondary' },
					...(boot.canManage
						? [
								{ label: __('Make again', 'seoprostats'), onClick: () => start(true), variant: 'link' as const },
								{ label: __('Remove demo data', 'seoprostats'), onClick: remove, variant: 'link' as const },
							]
						: []),
				]}
			>
				{__('You are looking at demo data: made-up visits for training, screenshots and testing. Your live statistics are kept apart and not changed.', 'seoprostats')}
			</Notice>
		);
	}

	if (status.status === 'making') {
		return (
			<Notice status="info" isDismissible={false} className="spst-notice">
				{boot.canManage
					? sprintf(
							/* translators: %d: percentage done */
							__('Making demo data: %d%% done. Keep this page open; it takes a minute or two.', 'seoprostats'),
							Math.round(status.progress * 100)
						)
					: __('Demo data is being made. Reload the page in a minute or two.', 'seoprostats')}
			</Notice>
		);
	}

	return (
		<Notice
			status="info"
			isDismissible={false}
			className="spst-notice"
			actions={[
				...(boot.canManage ? [{ label: __('Make demo data', 'seoprostats'), onClick: () => start(false), variant: 'primary' as const }] : []),
				{ label: __('Show live data', 'seoprostats'), onClick: () => show('live'), variant: 'secondary' as const },
			]}
		>
			{boot.canManage
				? __('There is no demo data yet. Making it adds a little over a year of made-up visits, in tables of their own, apart from your live statistics. It takes a minute or two.', 'seoprostats')
				: __('There is no demo data yet. An administrator can make it here.', 'seoprostats')}
		</Notice>
	);
}
