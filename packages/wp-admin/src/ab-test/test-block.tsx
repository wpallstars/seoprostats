/**
 * The A/B test block in the editor: its variants in the canvas (one shown
 * at a time), the variant dropdown in its toolbar, and its settings in the
 * sidebar: name, status, goals and variants (label, weight, order, add,
 * duplicate, remove).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect } from 'react';
import { Button, CheckboxControl, PanelBody, RangeControl, SelectControl, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	defaultLabel,
	freeSlug,
	MAX_VARIANTS,
	MAX_WEIGHT,
	newId,
	showVariant,
	statusLabel,
	TEST,
	useShownVariant,
	validId,
	VARIANT,
	variantAttributes,
	variantLabel,
	WEIGHT,
	type Status,
	type TestAttributes,
} from './model';
import { useVariants, VariantSwitcher } from './switcher';
import { blockActions, editor, wp, type BlockEditorSelectors, type BlockInstance, type EditProps } from './wp';

interface AbBoot {
	goals: { id: string; name: string }[];
	goalsUrl: string;
}

const raw = (window as unknown as { seoprostatsAbTests?: Partial<AbBoot> }).seoprostatsAbTests ?? {};
const abBoot: AbBoot = {
	goals: Array.isArray(raw.goals) ? raw.goals : [],
	goalsUrl: typeof raw.goalsUrl === 'string' ? raw.goalsUrl : '',
};

const STATUSES: Status[] = ['draft', 'running', 'paused', 'ended'];

function statusHelp(status: Status): string {
	switch (status) {
		case 'running':
			return __('Each visitor sees one variant, picked by weight on each page load.', 'seoprostats');
		case 'paused':
			return __('Everyone sees the first variant until the test runs again.', 'seoprostats');
		case 'ended':
			return __('Everyone sees the first variant (or the winner, once chosen).', 'seoprostats');
		default:
			return __('Not running yet: everyone sees the first variant.', 'seoprostats');
	}
}

/** Changes the editor makes itself (ids, slugs) stay out of undo. */
function quietly(change: () => void): void {
	blockActions().__unstableMarkNextChangeAsNotPersistent?.();
	change();
}

/** Keep each test's id valid and unique in the post, and each variant's slug set and unique. */
function useIds(clientId: string, attributes: TestAttributes, setAttributes: (a: Partial<TestAttributes>) => void, variants: BlockInstance[]) {
	const duplicate = wp.data.useSelect(
		(select) => {
			const be = select('core/block-editor') as BlockEditorSelectors;
			for (const id of be.getClientIdsWithDescendants()) {
				if (id === clientId) {
					return false;
				}
				if (be.getBlockName(id) === TEST && be.getBlockAttributes(id)?.testId === attributes.testId) {
					return true;
				}
			}
			return false;
		},
		[clientId, attributes.testId]
	);
	useEffect(() => {
		if (!validId(attributes.testId) || duplicate) {
			quietly(() => setAttributes({ testId: newId() }));
		}
	}, [attributes.testId, duplicate, setAttributes]);

	useEffect(() => {
		const taken: string[] = [];
		variants.forEach((b, i) => {
			const v = variantAttributes(b);
			if (v.slug && !taken.includes(v.slug)) {
				taken.push(v.slug);
				return;
			}
			const slug = freeSlug(taken, i);
			taken.push(slug);
			quietly(() => blockActions().updateBlockAttributes(b.clientId, { slug, label: v.label || defaultLabel(slug, i) }));
		});
	}, [variants]);
}

/** The variant shown in the canvas: the one chosen, else the winner or the first; and any variant a selected block is in. */
function useShown(clientId: string, winner: string, variants: BlockInstance[]): string | undefined {
	const active = useShownVariant(clientId);
	const within = wp.data.useSelect(
		(select) => {
			const be = select('core/block-editor') as BlockEditorSelectors;
			const selected = be.getSelectedBlockClientId();
			if (!selected || selected === clientId) {
				return null;
			}
			const chain = [...be.getBlockParents(selected), selected];
			const at = chain.indexOf(clientId);
			return at >= 0 ? (chain[at + 1] ?? null) : null;
		},
		[clientId]
	);
	const fallback = variants.find((b) => winner && variantAttributes(b).slug === winner) ?? variants[0];
	const resolved = variants.some((b) => b.clientId === active) ? active : fallback?.clientId;
	useEffect(() => {
		if (resolved && resolved !== active) {
			showVariant(clientId, resolved);
		}
	}, [clientId, resolved, active]);
	useEffect(() => {
		if (within && variants.some((b) => b.clientId === within)) {
			showVariant(clientId, within);
		}
	}, [clientId, within, variants]);
	return resolved;
}

function VariantRow({ testClientId, block, index, count, total, shown }: { testClientId: string; block: BlockInstance; index: number; count: number; total: number; shown: boolean }) {
	const v = variantAttributes(block);
	const label = variantLabel(block, index);
	const share = total > 0 ? Math.round((v.weight / total) * 100) : 0;
	const actions = blockActions();
	return (
		<li className={`spst-ab-variant-row${shown ? ' is-shown' : ''}`}>
			<div className="spst-ab-variant-row__head">
				<strong>{label}</strong>
				<code>{v.slug}</code>
				<span className="spst-ab-variant-row__share">
					{index === 0
						? sprintf(/* translators: %s: share of visitors, e.g. 50%. */ __('%s · control', 'seoprostats'), `${share}%`)
						: `${share}%`}
				</span>
			</div>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={__('Label', 'seoprostats')}
				value={v.label}
				placeholder={defaultLabel(v.slug, index)}
				onChange={(label: string) => actions.updateBlockAttributes(block.clientId, { label })}
			/>
			<RangeControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={__('Weight', 'seoprostats')}
				help={__('Relative to the other variants; 0 never shows it.', 'seoprostats')}
				value={v.weight}
				min={0}
				max={MAX_WEIGHT}
				onChange={(weight?: number) => actions.updateBlockAttributes(block.clientId, { weight: typeof weight === 'number' ? weight : WEIGHT })}
			/>
			<div className="spst-ab-variant-row__actions">
				<Button variant="secondary" size="small" disabled={shown} accessibleWhenDisabled onClick={() => showVariant(testClientId, block.clientId)}>
					{shown ? __('Shown', 'seoprostats') : __('Show', 'seoprostats')}
				</Button>
				<Button
					size="small"
					disabled={index === 0}
					accessibleWhenDisabled
					label={sprintf(/* translators: %s: variant label. */ __('Move %s up', 'seoprostats'), label)}
					onClick={() => actions.moveBlocksUp([block.clientId], testClientId)}
				>
					{__('Up', 'seoprostats')}
				</Button>
				<Button
					size="small"
					disabled={index === count - 1}
					accessibleWhenDisabled
					label={sprintf(/* translators: %s: variant label. */ __('Move %s down', 'seoprostats'), label)}
					onClick={() => actions.moveBlocksDown([block.clientId], testClientId)}
				>
					{__('Down', 'seoprostats')}
				</Button>
				<Button
					size="small"
					isDestructive
					disabled={count <= 2}
					accessibleWhenDisabled
					label={
						count <= 2
							? __('A test needs at least two variants.', 'seoprostats')
							: sprintf(/* translators: %s: variant label. */ __('Remove %s', 'seoprostats'), label)
					}
					showTooltip
					onClick={() => actions.removeBlock(block.clientId, false)}
				>
					{__('Remove', 'seoprostats')}
				</Button>
			</div>
		</li>
	);
}

export function TestEdit({ clientId, attributes, setAttributes }: EditProps<TestAttributes>) {
	const variants = useVariants(clientId);
	useIds(clientId, attributes, setAttributes, variants);
	const shown = useShown(clientId, attributes.winner, variants);
	const title = wp.data.useSelect((select) => String(editor(select)?.getEditedPostAttribute('title') ?? ''), []);
	const { useBlockProps, useInnerBlocksProps, BlockControls, InspectorControls } = wp.blockEditor;

	const shownIndex = Math.max(0, variants.findIndex((b) => b.clientId === shown));
	const name = attributes.name.trim() || title.trim() || __('A/B test', 'seoprostats');
	const blockProps = useBlockProps({
		className: 'spst-ab-test',
		'data-spst-label': sprintf(
			/* translators: 1: test name, 2: variant shown, e.g. Variant B, 3: number of variants, 4: status, e.g. Draft. */
			__('A/B test: %1$s · %2$s of %3$d · %4$s', 'seoprostats'),
			name,
			variantLabel(variants[shownIndex], shownIndex),
			variants.length,
			statusLabel(attributes.status)
		),
	});
	const innerBlocksProps = useInnerBlocksProps(blockProps, {
		allowedBlocks: [VARIANT],
		renderAppender: false,
		template: [
			[VARIANT, { slug: 'variant-a', label: defaultLabel('variant-a', 0), weight: WEIGHT }, [['core/paragraph']]],
			[VARIANT, { slug: 'variant-b', label: defaultLabel('variant-b', 1), weight: WEIGHT }, [['core/paragraph']]],
		],
	});

	const total = variants.reduce((sum, b) => sum + variantAttributes(b).weight, 0);
	const slugs = variants.map((b) => variantAttributes(b).slug);
	const add = (from: BlockInstance | null) => {
		const slug = freeSlug(slugs, variants.length);
		const attrs = { slug, label: defaultLabel(slug, variants.length), weight: WEIGHT };
		const block = from
			? wp.blocks.cloneBlock(from, attrs)
			: wp.blocks.createBlock(VARIANT, attrs, [wp.blocks.createBlock('core/paragraph')]);
		blockActions().insertBlock(block, variants.length, clientId, false);
		showVariant(clientId, block.clientId);
	};
	const shownBlock = variants[shownIndex] ?? null;
	const goals = new Set(attributes.goals);
	const missing = attributes.goals.filter((id) => !abBoot.goals.some((g) => g.id === id));

	return (
		<>
			<BlockControls group="block">
				<VariantSwitcher testClientId={clientId} />
			</BlockControls>
			<InspectorControls>
				<PanelBody title={__('A/B test', 'seoprostats')}>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={__('Name', 'seoprostats')}
						value={attributes.name}
						placeholder={title}
						help={__('Shown in reports. Without one, the post title.', 'seoprostats')}
						onChange={(value: string) => setAttributes({ name: value })}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={__('Status', 'seoprostats')}
						value={attributes.status}
						options={STATUSES.map((s) => ({ value: s, label: statusLabel(s) }))}
						help={statusHelp(attributes.status)}
						onChange={(value: string) => setAttributes({ status: (STATUSES as string[]).includes(value) ? (value as Status) : 'draft' })}
					/>
					<fieldset className="spst-ab-goals">
						<legend>{__('Goals it is judged by', 'seoprostats')}</legend>
						{abBoot.goals.map((g) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={g.id}
								label={g.name}
								checked={goals.has(g.id)}
								onChange={(on: boolean) =>
									setAttributes({ goals: on ? [...attributes.goals, g.id] : attributes.goals.filter((id) => id !== g.id) })
								}
							/>
						))}
						{missing.length > 0 && (
							<p className="spst-ab-note">
								{sprintf(
									/* translators: %d: number of goals. */
									_n('%d chosen goal no longer exists.', '%d chosen goals no longer exist.', missing.length, 'seoprostats'),
									missing.length
								)}{' '}
								<Button variant="link" onClick={() => setAttributes({ goals: attributes.goals.filter((id) => !missing.includes(id)) })}>
									{__('Clear', 'seoprostats')}
								</Button>
							</p>
						)}
						{!abBoot.goals.length && (
							<p className="spst-ab-note">
								{__('No goals yet. Goals are pages or events that count as a success.', 'seoprostats')}{' '}
								{abBoot.goalsUrl && <a href={abBoot.goalsUrl}>{__('Add goals', 'seoprostats')}</a>}
							</p>
						)}
					</fieldset>
					<p className="spst-ab-note">
						{sprintf(/* translators: %s: the test's id. */ __('Test ID: %s', 'seoprostats'), attributes.testId)}
					</p>
				</PanelBody>
				<PanelBody title={__('Variants', 'seoprostats')}>
					<p className="spst-ab-note">{__('The first variant is the control: crawlers, feeds and visitors without JavaScript see it.', 'seoprostats')}</p>
					<ol className="spst-ab-variant-list">
						{variants.map((b, i) => (
							<VariantRow
								key={b.clientId}
								testClientId={clientId}
								block={b}
								index={i}
								count={variants.length}
								total={total}
								shown={b.clientId === shown}
							/>
						))}
					</ol>
					<div className="spst-ab-variant-row__actions">
						<Button variant="secondary" disabled={variants.length >= MAX_VARIANTS} accessibleWhenDisabled onClick={() => add(null)}>
							{__('Add blank variant', 'seoprostats')}
						</Button>
						<Button variant="secondary" disabled={variants.length >= MAX_VARIANTS || !shownBlock} accessibleWhenDisabled onClick={() => add(shownBlock)}>
							{shownBlock
								? sprintf(/* translators: %s: variant label. */ __('Duplicate %s', 'seoprostats'), variantLabel(shownBlock, shownIndex))
								: __('Duplicate', 'seoprostats')}
						</Button>
					</div>
				</PanelBody>
			</InspectorControls>
			<div {...innerBlocksProps} />
		</>
	);
}
