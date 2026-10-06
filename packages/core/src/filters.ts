/**
 * Filters as the API reads them: `dimension:operator:value,value`, where a
 * comma means "any of" and `\,` is a comma inside a value.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { DIMENSIONS, OPERATORS, type Dimension, type Operator } from './types';

export interface Filter {
	dimension: Dimension;
	op: Operator;
	values: string[];
}

export function isDimension(value: string): value is Dimension {
	return (DIMENSIONS as readonly string[]).includes(value);
}

export function isOperator(value: string): value is Operator {
	return (OPERATORS as readonly string[]).includes(value);
}

/** The API's text form; always three parts, so values may hold colons. */
export function serializeFilter(filter: Filter): string {
	const values = filter.values.map((v) => v.replace(/,/g, '\\,')).join(',');
	return `${filter.dimension}:${filter.op}:${values}`;
}

/** Read the text form; null when it is not a filter. */
export function parseFilter(text: string): Filter | null {
	const first = text.indexOf(':');
	if (first < 1) {
		return null;
	}
	const dimension = text.slice(0, first);
	if (!isDimension(dimension)) {
		return null;
	}
	let rest = text.slice(first + 1);
	let op: Operator = 'is';
	const second = rest.indexOf(':');
	if (second > 0) {
		const maybe = rest.slice(0, second);
		if (isOperator(maybe)) {
			op = maybe;
			rest = rest.slice(second + 1);
		}
	}
	return { dimension, op, values: splitValues(rest) };
}

/** Split on commas not escaped as `\,` (no lookbehind: older Safari lacks it). */
function splitValues(text: string): string[] {
	const values: string[] = [];
	let current = '';
	for (let i = 0; i < text.length; i++) {
		const c = text[i];
		if (c === '\\' && text[i + 1] === ',') {
			current += ',';
			i++;
		} else if (c === ',') {
			values.push(current);
			current = '';
		} else {
			current += c;
		}
	}
	values.push(current);
	return values;
}

/** Same dimension and operator: a click on a second value adds to it. */
export function sameKind(a: Filter, b: Filter): boolean {
	return a.dimension === b.dimension && a.op === b.op;
}

/** Add a filter, merging values into one of the same kind. */
export function addFilter(filters: Filter[], add: Filter): Filter[] {
	const at = filters.findIndex((f) => sameKind(f, add));
	if (at < 0) {
		return [...filters, add];
	}
	const merged = filters[at] as Filter;
	const values = Array.from(new Set([...merged.values, ...add.values]));
	return filters.map((f, i) => (i === at ? { ...merged, values } : f));
}

export function removeFilter(filters: Filter[], index: number): Filter[] {
	return filters.filter((_, i) => i !== index);
}
