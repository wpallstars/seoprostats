/**
 * Starting an A/B test: a toolbar button on every block (and on a
 * selection of blocks of one type) and an item in the block options menu
 * for any selection. The selection goes into Variant A; Variant B starts
 * as a copy. Blocks that must stay in their parent (a button in Buttons,
 * a column in Columns) take their parent with them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { ComponentType } from 'react';
import { MenuItem, ToolbarButton } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	defaultLabel,
	showVariant,
	TEST,
	UNSUPPORTED_PARENTS,
	UNSUPPORTED_TYPES,
	VARIANT,
	WEIGHT,
	newId,
} from './model';
import { VariantSwitcher } from './switcher';
import { blockActions, blockEditor, editor, notice, postTitle, wp, type BlockEditorSelectors, type BlockInstance, type EditProps } from './wp';

/** The A/B test icon: two panes, A and B. */
export const abIcon = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<path d="M3.75 5.75h7v12.5h-7zM13.25 5.75h7v12.5h-7z" fill="none" stroke="currentColor" strokeWidth="1.5" />
		<path d="M5.6 15.5 7.25 9h.5l1.65 6.5M6.1 13.4h2.8M15.5 9.25h1.9a1.4 1.4 0 0 1 0 2.8h-1.9zm0 2.8h2.2a1.45 1.45 0 0 1 0 2.9h-2.2z" fill="none" stroke="currentColor" strokeWidth="1.1" />
	</svg>
);

/** Whether a block has an A/B test inside it, at any depth. */
function holdsTest(block: BlockInstance | null): boolean {
	return !!block && (block.name === TEST || block.innerBlocks.some(holdsTest));
}

/**
 * The blocks to wrap for a selection: the selection, or the nearest
 * parent that can sit in a test where the selection can't (a button →
 * its Buttons block). Null when nothing can.
 */
function target(be: BlockEditorSelectors, ids: string[]): string[] | null {
	let current = ids;
	for (;;) {
		const first = current[0];
		if (!first) {
			return null;
		}
		const root = be.getBlockRootClientId(first);
		const tied = current.some((id) => {
			const type = wp.blocks.getBlockType(be.getBlockName(id) ?? '');
			return !!(type?.parent?.length || type?.ancestor?.length);
		});
		if (!tied && be.canInsertBlockType(TEST, root)) {
			return current;
		}
		if (!root) {
			return null;
		}
		current = [root];
	}
}

/**
 * Why a selection can't start a test ('' when it can), or null when it
 * is already in a test (no button then).
 */
function blocked(be: BlockEditorSelectors, type: string | null, ids: string[]): string | null {
	const first = ids[0];
	if (!first) {
		return '';
	}
	const names = be.getBlockParents(first).map((id) => be.getBlockName(id) ?? '');
	if (names.includes(TEST) || ids.some((id) => [TEST, VARIANT].includes(be.getBlockName(id) ?? ''))) {
		return null;
	}
	if (!type || UNSUPPORTED_TYPES.includes(type) || names.some((n) => UNSUPPORTED_PARENTS.includes(n))) {
		return __('A/B tests can be started in posts and pages only for now, not in templates, template parts or synced patterns.', 'seoprostats');
	}
	const ids2 = target(be, ids);
	if (!ids2) {
		return __('These blocks can’t be put in an A/B test here.', 'seoprostats');
	}
	if (be.getBlocksByClientId(ids2).some(holdsTest)) {
		return __('An A/B test can’t hold another A/B test.', 'seoprostats');
	}
	return '';
}

/** Wrap the selection in a new test: Variant A the blocks, Variant B a copy. */
/** The test takes the widest alignment of the blocks it wraps. */
function widestAlign(blocks: BlockInstance[]): 'full' | 'wide' | undefined {
	const aligns: ReadonlySet<unknown> = new Set(blocks.map((b) => b.attributes.align));
	if (aligns.has('full')) {
		return 'full';
	}
	return aligns.has('wide') ? 'wide' : undefined;
}

function start(ids: string[]): void {
	const be = blockEditor();
	const why = blocked(be, editor()?.getCurrentPostType() ?? null, ids);
	if (why) {
		notice(why);
		return;
	}
	const wrap = why === '' ? target(be, ids) : null;
	if (!wrap) {
		// Already in a test: nothing to start.
		return;
	}
	const blocks = be.getBlocksByClientId(wrap).filter((b): b is BlockInstance => !!b);
	const copy = () => blocks.map((b) => wp.blocks.cloneBlock(b));
	const align = widestAlign(blocks);
	const title = postTitle().trim();
	const variant = (i: number) => {
		const slug = i === 0 ? 'variant-a' : 'variant-b';
		return wp.blocks.createBlock(VARIANT, { slug, label: defaultLabel(slug, i), weight: WEIGHT }, copy());
	};
	const test = wp.blocks.createBlock(
		TEST,
		{ testId: newId(), name: title, status: 'draft', goals: [], ...(align ? { align } : {}) },
		[variant(0), variant(1)]
	);
	blockActions().replaceBlocks(wrap, test);
	const b = test.innerBlocks[1];
	if (b) {
		showVariant(test.clientId, b.clientId);
	}
	blockActions().selectBlock(test.clientId);
	const parent = wrap !== ids && wrap[0] ? wp.blocks.getBlockType(be.getBlockName(wrap[0]) ?? '')?.title : '';
	notice(
		parent
			? sprintf(
					/* translators: %s: a block's name, e.g. Buttons. */
					__('A/B test started around the %s block. Variant B, a copy, is shown: change it to try something new.', 'seoprostats'),
					parent
				)
			: __('A/B test started. Variant B, a copy, is shown: change it to try something new.', 'seoprostats')
	);
}

/** Why the current selection can't start a test, as start() would say. */
function useBlocked(ids: string[] | null): string | null {
	return wp.data.useSelect(
		(select) => {
			const be = select('core/block-editor') as BlockEditorSelectors;
			return blocked(be, editor(select)?.getCurrentPostType() ?? null, ids ?? be.getSelectedBlockClientIds());
		},
		[ids ? ids.join() : '']
	);
}

function StartButton() {
	const why = useBlocked(null);
	if (why === null) {
		return null;
	}
	return (
		<ToolbarButton
			icon={abIcon}
			label={why || __('Start an A/B test', 'seoprostats')}
			onClick={() => start(blockEditor().getSelectedBlockClientIds())}
		/>
	);
}

/** Inside a test: its variant switcher, on the selected block's toolbar. */
function ParentSwitcher({ clientId }: Readonly<{ clientId: string }>) {
	const test = wp.data.useSelect(
		(select) => {
			const be = select('core/block-editor') as BlockEditorSelectors;
			const parents = be.getBlockParents(clientId, true);
			return parents.find((id) => be.getBlockName(id) === TEST) ?? null;
		},
		[clientId]
	);
	return test ? <VariantSwitcher testClientId={test} /> : null;
}

/** The toolbar button and, inside a test, its variant switcher, on every block. */
export function addToolbar(): void {
	const { BlockControls } = wp.blockEditor;
	const withAbTest = wp.compose.createHigherOrderComponent<EditProps>(
		(BlockEdit: ComponentType<EditProps>) =>
			function AbTestControls(props: Readonly<EditProps>) {
				if (props.name === TEST) {
					return <BlockEdit {...props} />;
				}
				return (
					<>
						<BlockEdit {...props} />
						{props.name !== VARIANT && (
							<BlockControls group="other">
								<StartButton />
							</BlockControls>
						)}
						<BlockControls group="parent">
							<ParentSwitcher clientId={props.clientId} />
						</BlockControls>
					</>
				);
			},
		'withSeoProStatsAbTest'
	);
	wp.hooks.addFilter('editor.BlockEdit', 'seoprostats/ab-test', withAbTest as (value: never) => unknown);
}

function StartMenuItem({ ids, onClose }: Readonly<{ ids: string[]; onClose(): void }>) {
	const why = useBlocked(ids);
	if (why === null) {
		return null;
	}
	return (
		<MenuItem
			icon={abIcon}
			info={why || undefined}
			onClick={() => {
				start(ids);
				onClose();
			}}
		>
			{__('Start an A/B test', 'seoprostats')}
		</MenuItem>
	);
}

/** The options menu item, for any selection (the toolbar shows ours only for one block type). */
export function addMenuItem(): void {
	const Controls = wp.blockEditor.BlockSettingsMenuControls;
	if (!Controls) {
		return;
	}
	wp.plugins.registerPlugin('seoprostats-ab-test', {
		icon: null,
		render: () => <Controls>{({ selectedClientIds, onClose }) => <StartMenuItem ids={selectedClientIds} onClose={onClose} />}</Controls>,
	});
}
