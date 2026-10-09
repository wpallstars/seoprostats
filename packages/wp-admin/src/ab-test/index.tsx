/**
 * A/B tests in the block editor (SEOProStats_AB_Tests): the test and
 * variant blocks, the toolbar button that starts a test, and the variant
 * dropdown. Attributes and supports come from the server's registration;
 * this adds the editor parts. Saved markup is the inner blocks only: the
 * server renders the site's markup.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import { TEST, useShownVariant, VARIANT, variantAttributes, defaultLabel, type TestAttributes, type VariantAttributes } from './model';
import { abIcon, addMenuItem, addToolbar } from './start';
import { TestEdit } from './test-block';
import { wp, type BlockEditorSelectors, type EditProps } from './wp';
import './ab-test.css';

function VariantEdit({ clientId, attributes }: Readonly<EditProps<VariantAttributes>>) {
	const { useBlockProps, useInnerBlocksProps } = wp.blockEditor;
	const { root, index } = wp.data.useSelect(
		(select) => {
			const be = select('core/block-editor') as BlockEditorSelectors;
			return { root: be.getBlockRootClientId(clientId), index: be.getBlockIndex(clientId) };
		},
		[clientId]
	);
	const shown = useShownVariant(root);
	const visible = shown ? shown === clientId : index === 0;
	const blockProps = useBlockProps({
		className: 'spst-ab-variant',
		'data-spst-variant': attributes.slug,
		hidden: !visible,
		style: visible ? undefined : { display: 'none' },
	});
	const innerBlocksProps = useInnerBlocksProps(blockProps, { template: [['core/paragraph']], templateLock: false });
	return <div {...innerBlocksProps} />;
}

function Content() {
	const { InnerBlocks } = wp.blockEditor;
	return <InnerBlocks.Content />;
}

function start(): void {
	if (!wp?.blocks || !wp.blockEditor || wp.blocks.getBlockType(TEST)) {
		return;
	}
	wp.blocks.registerBlockType(TEST, {
		apiVersion: 3,
		title: __('A/B test', 'seoprostats'),
		description: __('Shows one of its variants to each visitor, by weight, to learn which does better.', 'seoprostats'),
		icon: abIcon,
		category: 'design',
		edit: TestEdit as never,
		save: Content,
		__experimentalLabel: (attributes) => {
			const a = attributes as Partial<TestAttributes>;
			return a.name ? `${__('A/B test', 'seoprostats')}: ${a.name}` : __('A/B test', 'seoprostats');
		},
	});
	wp.blocks.registerBlockType(VARIANT, {
		apiVersion: 3,
		title: __('A/B test variant', 'seoprostats'),
		icon: abIcon,
		category: 'design',
		parent: [TEST],
		edit: VariantEdit as never,
		save: Content,
		__experimentalLabel: (attributes) => {
			const v = variantAttributes({ clientId: '', name: VARIANT, attributes, innerBlocks: [] });
			return v.label || (v.slug ? defaultLabel(v.slug, 0) : undefined);
		},
	});
	addToolbar();
	addMenuItem();
}

start();
