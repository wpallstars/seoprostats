/**
 * The block editor's own scripts (wp.blocks, wp.blockEditor, wp.data…),
 * read from the page: SEOProStats_Editor::ab_tests() lists them as
 * dependencies. Only the parts the A/B test editor uses are typed.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { ComponentType, ReactNode } from 'react';

export type Attributes = Record<string, unknown>;

export interface BlockInstance {
	clientId: string;
	name: string;
	attributes: Attributes;
	innerBlocks: BlockInstance[];
}

export interface BlockType {
	name: string;
	title: string;
	parent?: string[];
	ancestor?: string[];
}

export interface EditProps<A = Attributes> {
	clientId: string;
	attributes: A;
	setAttributes(attributes: Partial<A>): void;
	isSelected: boolean;
	name: string;
}

export interface BlockEditorSelectors {
	getBlock(clientId: string): BlockInstance | null;
	getBlocksByClientId(clientIds: string[]): (BlockInstance | null)[];
	getBlockName(clientId: string): string | null;
	getBlockAttributes(clientId: string): Attributes | null;
	getBlockRootClientId(clientId: string): string | null;
	getBlockParents(clientId: string, ascending?: boolean): string[];
	getBlockOrder(rootClientId?: string): string[];
	getBlockIndex(clientId: string): number;
	getSelectedBlockClientId(): string | null;
	getSelectedBlockClientIds(): string[];
	getClientIdsWithDescendants(): string[];
	canInsertBlockType(name: string, rootClientId?: string | null): boolean;
}

export interface BlockEditorActions {
	replaceBlocks(clientIds: string | string[], blocks: BlockInstance | BlockInstance[]): void;
	insertBlock(block: BlockInstance, index?: number, rootClientId?: string, updateSelection?: boolean): void;
	removeBlock(clientId: string, selectPrevious?: boolean): void;
	moveBlocksUp(clientIds: string[], rootClientId?: string): void;
	moveBlocksDown(clientIds: string[], rootClientId?: string): void;
	updateBlockAttributes(clientId: string, attributes: Attributes): void;
	selectBlock(clientId: string): void;
	__unstableMarkNextChangeAsNotPersistent?(): void;
}

export interface EditorSelectors {
	getCurrentPostType(): string | null;
	getEditedPostAttribute(name: string): unknown;
	isSavingPost(): boolean;
	isAutosavingPost(): boolean;
	didPostSaveRequestSucceed(): boolean;
}

type Select = (store: string) => unknown;

interface BlockSettings {
	apiVersion?: number;
	title?: string;
	description?: string;
	icon?: ReactNode;
	category?: string;
	parent?: string[];
	edit: ComponentType<EditProps<never>>;
	save: ComponentType<Record<string, never>>;
	__experimentalLabel?(attributes: Attributes, context: { context: string }): string | undefined;
}

interface MenuControlsProps {
	selectedClientIds: string[];
	onClose(): void;
}

interface Wp {
	blocks: {
		registerBlockType(name: string, settings: BlockSettings): unknown;
		getBlockType(name: string): BlockType | undefined;
		createBlock(name: string, attributes?: Attributes, innerBlocks?: BlockInstance[]): BlockInstance;
		cloneBlock(block: BlockInstance, attributes?: Attributes): BlockInstance;
	};
	blockEditor: {
		useBlockProps(props?: Record<string, unknown>): Record<string, unknown>;
		useInnerBlocksProps(props: Record<string, unknown>, options?: Record<string, unknown>): Record<string, unknown>;
		BlockControls: ComponentType<{ group?: 'default' | 'block' | 'inline' | 'other' | 'parent'; children?: ReactNode }>;
		InspectorControls: ComponentType<{ children?: ReactNode }>;
		InnerBlocks: { Content: ComponentType<Record<string, never>> };
		BlockSettingsMenuControls?: ComponentType<{ children: (props: MenuControlsProps) => ReactNode }>;
	};
	data: {
		select: Select;
		dispatch(store: string): unknown;
		subscribe(listener: () => void): () => void;
		useSelect<T>(map: (select: Select) => T, deps: unknown[]): T;
	};
	hooks: {
		addFilter(hook: string, namespace: string, callback: (value: never) => unknown): void;
	};
	compose: {
		createHigherOrderComponent<P>(fn: (Inner: ComponentType<P>) => ComponentType<P>, name: string): (Inner: ComponentType<P>) => ComponentType<P>;
	};
	plugins: {
		registerPlugin(name: string, settings: { render: ComponentType; icon?: unknown }): void;
	};
}

export const wp = (window as unknown as { wp: Wp }).wp;

/** The block editor's selectors now (outside React). */
export function blockEditor(): BlockEditorSelectors {
	return wp.data.select('core/block-editor') as BlockEditorSelectors;
}

/** The block editor's actions. */
export function blockActions(): BlockEditorActions {
	return wp.data.dispatch('core/block-editor') as BlockEditorActions;
}

/** The post editor's selectors, when there is a post (not in the widgets editor). */
export function editor(select: Select = wp.data.select): EditorSelectors | null {
	const store = select('core/editor') as Partial<EditorSelectors> | undefined;
	return store && typeof store.getCurrentPostType === 'function' ? (store as EditorSelectors) : null;
}

export interface NoticeAction {
	label: string;
	onClick(): void;
}

interface NoticeActions {
	createNotice?(status: string, text: string, options: Record<string, unknown>): void;
	removeNotice?(id: string): void;
}

/** A short note at the foot of the editor (a snackbar), with actions such as Undo; `id` to remove it later. */
export function notice(text: string, actions: NoticeAction[] = [], status: 'info' | 'success' | 'error' = 'info', id?: string): void {
	const notices = wp.data.dispatch('core/notices') as NoticeActions | undefined;
	// With actions, dismiss only by its close button: a snackbar that closes on
	// any key press would close before a key reaches Undo.
	notices?.createNotice?.(status, text, { type: 'snackbar', isDismissible: true, explicitDismiss: actions.length > 0, actions, ...(id ? { id } : {}) });
}

export function removeNotice(id: string): void {
	(wp.data.dispatch('core/notices') as NoticeActions | undefined)?.removeNotice?.(id);
}

/** Undo the editor's last change (the post editor's history). */
export function undo(): void {
	const actions = wp.data.dispatch('core/editor') as { undo?(): void } | undefined;
	actions?.undo?.();
}
