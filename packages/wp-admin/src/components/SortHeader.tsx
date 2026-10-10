/**
 * A column header that sorts its table. The first click sorts the column
 * its natural way (most first; lowest first for position, as a lower
 * position is better; newest first for days); a click on the column
 * already sorted reverses it. The arrow shows the direction: ↓ highest
 * (or newest) at the top, ↑ lowest (or oldest). Without onSort (paper)
 * the header is plain text, with the arrow on the sorted column.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, sprintf } from '@wordpress/i18n';
import { naturalOrder, type SortOrder } from '@seoprostats/core';

/** A table's sort and how to change it. */
export interface TableSortProps<K extends string> {
	sort: K;
	order: SortOrder;
	/** Sort by a column in a direction; left out where the table cannot be sorted (paper). */
	onSort?: (sort: K, order: SortOrder) => void;
}

interface SortHeaderProps<K extends string> extends TableSortProps<K> {
	column: K;
	label: string;
	/** The cell's class: num (the default) for figures. */
	className?: string;
}

/** What a click does, for the button's tooltip. */
function hint(column: string, label: string, next: SortOrder): string {
	if (column === 'day') {
		return next === 'asc'
			? sprintf(/* translators: %s: column name, e.g. "Day". */ __('Sort by %s, oldest first', 'seoprostats'), label)
			: sprintf(/* translators: %s: column name, e.g. "Day". */ __('Sort by %s, newest first', 'seoprostats'), label);
	}
	return next === 'asc'
		? sprintf(/* translators: %s: column name, e.g. "Position". */ __('Sort by %s, lowest first', 'seoprostats'), label)
		: sprintf(/* translators: %s: column name, e.g. "Clicks". */ __('Sort by %s, highest first', 'seoprostats'), label);
}

export function SortHeader<K extends string>({ column, label, sort, order, onSort, className = 'num' }: SortHeaderProps<K>) {
	const active = sort === column;
	const arrow = active && <span aria-hidden="true">{order === 'asc' ? ' ↑' : ' ↓'}</span>;
	const ariaSort = active ? (order === 'asc' ? 'ascending' : 'descending') : undefined;
	if (!onSort) {
		return (
			<th scope="col" className={className || undefined} aria-sort={ariaSort}>
				{label}
				{arrow}
			</th>
		);
	}
	// The column's natural way first; the sorted column turns round.
	const next: SortOrder = active ? (order === 'asc' ? 'desc' : 'asc') : naturalOrder(column);
	return (
		<th scope="col" className={className || undefined} aria-sort={ariaSort}>
			<button type="button" className={`spst-sort${active ? ' is-active' : ''}`} title={hint(column, label, next)} onClick={() => onSort(column, next)}>
				{label}
				{arrow}
			</button>
		</th>
	);
}
