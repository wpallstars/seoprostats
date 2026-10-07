/**
 * Where a query's words are on its page: in the title, a heading, the
 * text, partly or not at all; the words missing; and whether it is a
 * question. Opportunities → Missing from the page and the editor panel.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, sprintf } from '@wordpress/i18n';
import type { CoverageMatch, CoverageResult } from '@seoprostats/core';

export function matchName(match: CoverageMatch): string {
	const names: Record<CoverageMatch, string> = {
		title: __('In the title', 'seoprostats'),
		heading: __('In a heading', 'seoprostats'),
		text: __('In the text', 'seoprostats'),
		partial: __('Partly', 'seoprostats'),
		none: __('Not on the page', 'seoprostats'),
	};
	return names[match];
}

export function CoverageBadges({ result }: { result: CoverageResult }) {
	const partial = result.match === 'partial';
	return (
		<span className="spst-coverage">
			<span className={`spst-coverage__match is-${result.match}`}>{matchName(result.match)}</span>
			{partial && result.missing.length > 0 && (
				<span className="spst-coverage__missing">
					{sprintf(/* translators: %s: words, e.g. "ttfb, reduce". */ __('missing: %s', 'seoprostats'), result.missing.join(', '))}
				</span>
			)}
			{result.question && <span className="spst-coverage__question">{__('Question', 'seoprostats')}</span>}
		</span>
	);
}
