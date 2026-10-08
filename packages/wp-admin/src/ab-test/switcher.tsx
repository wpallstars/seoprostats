/**
 * The variant dropdown ("Variant A ▾"): which of a test's variants shows,
 * and is edited, in the canvas. The others are hidden, not removed.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { ToolbarDropdownMenu } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { showVariant, useShownVariant, VARIANT, variantLabel } from './model';
import { wp, type BlockEditorSelectors, type BlockInstance } from './wp';

const NONE: BlockInstance[] = [];

const chevron = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<path d="M17.5 11.6 12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z" fill="currentColor" />
	</svg>
);

/** A test's variant blocks, in order. */
export function useVariants(testClientId: string): BlockInstance[] {
	const inner = wp.data.useSelect(
		(select) => (select('core/block-editor') as BlockEditorSelectors).getBlock(testClientId)?.innerBlocks ?? NONE,
		[testClientId]
	);
	return inner.filter((b) => b.name === VARIANT);
}

export function VariantSwitcher({ testClientId }: { testClientId: string }) {
	const variants = useVariants(testClientId);
	const active = useShownVariant(testClientId);
	const index = Math.max(0, variants.findIndex((b) => b.clientId === active));
	const current = variants[index];
	if (!current) {
		return null;
	}
	const label = variantLabel(current, index);
	return (
		<ToolbarDropdownMenu
			icon={chevron}
			text={label}
			label={sprintf(
				/* translators: %s: the variant shown, e.g. Variant A. */
				__('A/B test variant shown in the editor: %s', 'seoprostats'),
				label
			)}
			toggleProps={{ iconPosition: 'right', className: 'spst-ab-switcher' }}
			controls={variants.map((b, i) => ({
				title: variantLabel(b, i),
				isActive: b.clientId === current.clientId,
				role: 'menuitemradio',
				onClick: () => showVariant(testClientId, b.clientId),
			}))}
		/>
	);
}
