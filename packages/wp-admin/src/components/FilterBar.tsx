/**
 * The filters in force, each removable. Filters are added by choosing a
 * row in a breakdown.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { removeFilter, type Filter, type ViewState } from '@seoprostats/core';
import { dimensionLabel, operatorLabel, valueLabel } from '../labels';

interface Props {
	filters: Filter[];
	update: (patch: Partial<ViewState>) => void;
}

export function filterText(filter: Filter): string {
	const values = filter.values.map((v) => valueLabel(filter.dimension, v)).join(__(' or ', 'seoprostats'));
	return `${dimensionLabel(filter.dimension)} ${operatorLabel(filter.op)} ${values}`;
}

export function FilterBar({ filters, update }: Props) {
	if (!filters.length) {
		return null;
	}
	return (
		<div className="spst-filters" role="group" aria-label={__('Filters', 'seoprostats')}>
			<span className="spst-filters__title">{__('Showing visits where', 'seoprostats')}</span>
			<ul className="spst-filters__list">
				{filters.map((filter, i) => {
					const text = filterText(filter);
					return (
						<li key={`${filter.dimension}:${filter.op}:${i}`} className="spst-chip">
							<span className="spst-chip__text">{text}</span>
							<button
								type="button"
								className="spst-chip__remove"
								aria-label={sprintf(/* translators: %s: a filter, e.g. "Country is France". */ __('Remove filter: %s', 'seoprostats'), text)}
								onClick={() => update({ filters: removeFilter(filters, i) })}
							>
								<span aria-hidden="true">×</span>
							</button>
						</li>
					);
				})}
			</ul>
			{filters.length > 1 && (
				<Button variant="link" onClick={() => update({ filters: [] })}>
					{__('Clear all', 'seoprostats')}
				</Button>
			)}
		</div>
	);
}
