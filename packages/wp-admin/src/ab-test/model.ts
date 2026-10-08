/**
 * A/B test blocks' names, attributes and small rules, shared by the
 * editor's parts. The same rules on the server: SEOProStats_AB_Tests.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useSyncExternalStore } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import type { BlockInstance } from './wp';

export const TEST = 'seoprostats/ab-test';
export const VARIANT = 'seoprostats/ab-variant';

export type Status = 'draft' | 'running' | 'paused' | 'ended';

export interface TestAttributes {
	testId: string;
	name: string;
	status: Status;
	goals: string[];
	winner: string;
}

export interface VariantAttributes {
	slug: string;
	label: string;
	weight: number;
}

/** As SEOProStats_AB_Tests: variants a test may have, and weights. */
export const MAX_VARIANTS = 10;
export const WEIGHT = 50;
export const MAX_WEIGHT = 100;

/** Post types tests can't start in yet: templates, template parts, synced patterns, menus. */
export const UNSUPPORTED_TYPES = ['wp_template', 'wp_template_part', 'wp_block', 'wp_navigation'];

/** Blocks whose insides tests can't start in yet (synced patterns, template parts). */
export const UNSUPPORTED_PARENTS = ['core/block', 'core/template-part'];

/** A test id as the server accepts it: 12 lower-case letters and digits. */
export function newId(): string {
	const bytes = new Uint8Array(12);
	window.crypto.getRandomValues(bytes);
	return Array.from(bytes, (b) => (b % 36).toString(36)).join('');
}

export function validId(id: unknown): id is string {
	return typeof id === 'string' && /^[a-z0-9]{6,32}$/.test(id);
}

/** variant-a … variant-z, then variant-27 …, as the server names them. */
export function slugAt(i: number): string {
	return `variant-${i < 26 ? String.fromCharCode(97 + i) : String(i + 1)}`;
}

/** The first slug not taken, from position `from` on. */
export function freeSlug(taken: string[], from: number): string {
	for (let i = from; ; i++) {
		const slug = slugAt(i);
		if (!taken.includes(slug)) {
			return slug;
		}
	}
}

/** "Variant B" for variant-b, else by position. */
export function defaultLabel(slug: string, i: number): string {
	const m = /^variant-([a-z])$/.exec(slug);
	/* translators: %s: a letter or number, e.g. B. */
	return sprintf(__('Variant %s', 'seoprostats'), m?.[1] ? m[1].toUpperCase() : String(i + 1));
}

export function variantAttributes(block: BlockInstance | null | undefined): VariantAttributes {
	const a = block?.attributes ?? {};
	return {
		slug: typeof a.slug === 'string' ? a.slug : '',
		label: typeof a.label === 'string' ? a.label : '',
		weight: typeof a.weight === 'number' ? a.weight : WEIGHT,
	};
}

/** A variant's label as shown: its own, else its default. */
export function variantLabel(block: BlockInstance | null | undefined, i: number): string {
	const v = variantAttributes(block);
	return v.label.trim() || defaultLabel(v.slug, i);
}

export function statusLabel(status: Status): string {
	switch (status) {
		case 'running':
			return __('Running', 'seoprostats');
		case 'paused':
			return __('Paused', 'seoprostats');
		case 'ended':
			return __('Ended', 'seoprostats');
		default:
			return __('Draft', 'seoprostats');
	}
}

/*
 * The variant each test shows in the editor's canvas: test block client id
 * → variant block client id. Editor state only; never saved.
 */
const shown = new Map<string, string>();
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
	listeners.add(listener);
	return () => listeners.delete(listener);
}

export function showVariant(testClientId: string, variantClientId: string): void {
	if (shown.get(testClientId) !== variantClientId) {
		shown.set(testClientId, variantClientId);
		listeners.forEach((listener) => listener());
	}
}

export function shownVariant(testClientId: string): string | undefined {
	return shown.get(testClientId);
}

/** The variant a test shows in the canvas, kept up to date. */
export function useShownVariant(testClientId: string | null): string | undefined {
	return useSyncExternalStore(subscribe, () => (testClientId ? shown.get(testClientId) : undefined));
}
