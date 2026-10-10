/**
 * After a search in a search report (Rankings, Striking distance,
 * Overlap): "Target" when it is a search target already, otherwise, for
 * administrators, Add as target, which adds it as a candidate with the
 * page given (the page search shows for it), leaving a target already
 * listed as it is. Nothing in shared reports.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 */
import { useState } from 'react';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { addSearchTargets, errorMessage, shareAccess, useTargetQueries } from '../api';
import { boot } from '../boot';
import { useDataSet } from '../data';

export function AddTarget({ query, page = '' }: Readonly<{ query: string; page?: string }>) {
	const data = useDataSet();
	const listed = useTargetQueries();
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	if (shareAccess.token || !listed.data) {
		return null;
	}
	const key = query.toLocaleLowerCase();
	const target = listed.data.targets.find((t) => t.query === key);
	if (target) {
		return (
			<span
				className="spst-badge spst-target-mark"
				title={target.page ? sprintf(/* translators: %s: page path. */ __('A search target, meant for %s', 'seoprostats'), target.page) : __('A search target, no page chosen yet', 'seoprostats')}
			>
				{__('Target', 'seoprostats')}
			</span>
		);
	}
	if (!boot.canManage) {
		return null;
	}
	const add = async () => {
		setBusy(true);
		setError('');
		try {
			const done = await addSearchTargets(data, [{ query, page }]);
			const [skipped] = done.skipped;
			if (skipped && skipped.reason !== 'exists') {
				setError(skipped.message);
			}
		} catch (e) {
			setError(errorMessage(e, __('It could not be added. Try again.', 'seoprostats')));
		}
		setBusy(false);
	};
	return (
		<>
			<span className="spst-research__gap">{' '}</span>
			<Button
				variant="link"
				className="spst-research__toggle spst-target-add"
				disabled={busy}
				aria-label={sprintf(/* translators: %s: a search query. */ __('Add “%s” as a search target', 'seoprostats'), query)}
				title={page ? sprintf(/* translators: %s: page path. */ __('Adds it as a candidate target, meant for %s', 'seoprostats'), page) : __('Adds it as a candidate target, no page chosen yet', 'seoprostats')}
				onClick={() => void add()}
			>
				{busy ? __('Adding…', 'seoprostats') : __('Add as target', 'seoprostats')}
			</Button>
			{error && (
				<span className="spst-meta" role="alert">
					{error}
				</span>
			)}
		</>
	);
}
