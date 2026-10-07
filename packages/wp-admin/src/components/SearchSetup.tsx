/**
 * Search without search days on live data: how to connect Search Console,
 * or that its days are on their way. Shared by Rankings and Opportunities.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { boot } from '../boot';
import { useDataSet } from '../data';

/** A page and query to open Rankings with. */
export interface SearchPick {
	page: string;
	query: string;
}

export function SearchSetup({ answer }: { answer: { through: string; connected: boolean } }) {
	const demo = useDataSet() === 'demo';
	if (demo || answer.through) {
		return null;
	}
	const connections = boot.canManage && boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'connections' }) : '';
	return (
		<Notice status="info" isDismissible={false} className="spst-notice">
			{answer.connected
				? __('Search Console is connected. Its days are imported in the background, the newest about three days old; they show here as they arrive.', 'seoprostats')
				: __('Connect Google Search Console to see the searches that show your pages: clicks, impressions, CTR and average position, next to your visits and changes.', 'seoprostats')}{' '}
			{!answer.connected && connections && <a href={connections}>{__('Settings → Connections', 'seoprostats')}</a>}
		</Notice>
	);
}
