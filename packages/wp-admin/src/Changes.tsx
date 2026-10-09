/**
 * Changes: the change log for the period, newest first. What changed,
 * when, on which page and by whom: posts, SEO fields, products, plugins,
 * themes and settings, search engine updates (when switched on), and
 * notes people and agents add. Optionally one
 * page's (with the site-wide changes) or one group's. Administrators add
 * and delete notes here.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useId, useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber, toggleFilterValue, type ChangeGroup, type Marker } from '@seoprostats/core';
import { addNote, deleteNote, errorMessage, shareAccess, useBreakdown, useChanges } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import type { ViewProps } from './App';
import { PeriodLine } from './Overview';
import { CHANGE_GROUPS, filteredPage, groupLabel } from './changelog';
import { ChangesTable } from './components/ChangesTable';
import { DefinitionModal } from './components/DefinitionModal';
import { startFrom } from './Experiments';

const PER_PAGE = 50;

/** Longest note, as the change log keeps it. */
const MAX_NOTE = 190;

function NoteModal({ page, onClose }: Readonly<{ page: string; onClose: () => void }>) {
	const data = useDataSet();
	const [note, setNote] = useState('');
	const [path, setPath] = useState(page.includes('*') ? '' : page);
	const [when, setWhen] = useState('');
	return (
		<DefinitionModal
			title={__('Add a note', 'seoprostats')}
			onClose={onClose}
			canSave={note.trim() !== ''}
			onSave={() => addNote(data, { note: note.trim(), page: path.trim(), time: when.replace('T', ' ') })}
		>
			<TextControl
				__nextHasNoMarginBottom
				label={__('What happened', 'seoprostats')}
				help={__('Such as "Newsletter sent" or "Price test started".', 'seoprostats')}
				value={note}
				maxLength={MAX_NOTE}
				onChange={setNote}
				required
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={__('Page (optional)', 'seoprostats')}
				help={__('A path such as /pricing/. Leave empty for the whole site.', 'seoprostats')}
				value={path}
				onChange={setPath}
			/>
			<TextControl
				__nextHasNoMarginBottom
				type="datetime-local"
				label={__('When (optional)', 'seoprostats')}
				help={sprintf(/* translators: %s: the site's time zone. */ __('In the site time zone (%s). Leave empty for now.', 'seoprostats'), boot.timezone)}
				value={when}
				onChange={setWhen}
			/>
		</DefinitionModal>
	);
}

export function Changes({ state, update }: Readonly<ViewProps>) {
	const data = useDataSet();
	// The address's page (a chart's changes, opened here), else the one the reports are filtered to.
	const page = state.page ?? filteredPage(state.filters);
	const group = state.group ?? '';
	const [typed, setTyped] = useState(page);
	const setPage = (next: string) => {
		update({
			page: next || undefined,
			// With no address page, the report filter is the fallback. Clear it too for Any page.
			...(!next ? { filters: state.filters.filter((f) => f.dimension !== 'page') } : {}),
		});
	};
	// Back and forward bring the applied page back, including the empty default.
	useEffect(() => {
		setTyped(page);
	}, [page]);
	const [offset, setOffset] = useState(0);
	const [adding, setAdding] = useState(false);
	const [error, setError] = useState('');
	const query = useChanges(state, page, group, PER_PAGE, offset);
	const pages = useBreakdown(state, 'page', 100);
	const list = useId();
	const answer = query.data;
	const rows = answer?.changes ?? [];

	// A new period, page or group starts at the newest change.
	useEffect(() => setOffset(0), [state.range, state.from, state.to, page, group]);

	const remove = async (change: Marker) => {
		/* translators: %s: a note. */
		const question = sprintf(__('Delete the note “%s”?', 'seoprostats'), change.label);
		// eslint-disable-next-line no-alert -- a plain confirmation, as WordPress uses for deleting.
		if (!window.confirm(question)) {
			return;
		}
		try {
			await deleteNote(data, change.id);
			setError('');
		} catch (e) {
			setError(errorMessage(e, __('It could not be deleted. Try again.', 'seoprostats')));
		}
	};

	const showPage = (path: string) =>
		update({ view: 'overview', filters: toggleFilterValue(state.filters.filter((f) => f.dimension !== 'page'), 'page', path) });

	return (
		<>
			{(query.isError || error) && (
				<Notice status="error" isDismissible={!!error} onRemove={() => setError('')}>
					{error || errorMessage(query.error, __('The changes could not be loaded. Reload the page to try again.', 'seoprostats'))}
				</Notice>
			)}

			<Card className="spst-card is-wide spst-section" size="small">
				<CardHeader className="spst-card__header">
					<div>
						<h2 className="spst-card__title">
							{page ? sprintf(/* translators: %s: a page path. */ __('Changes to %s', 'seoprostats'), page) : __('Changes', 'seoprostats')}
						</h2>
						{answer && <PeriodLine range={answer.range} />}
					</div>
					{boot.canManage && (
						<Button variant="secondary" onClick={() => setAdding(true)}>
							{__('Add a note', 'seoprostats')}
						</Button>
					)}
				</CardHeader>

				<div className="spst-changes__filters">
					<SelectControl
						__nextHasNoMarginBottom
						label={__('Show', 'seoprostats')}
						value={group}
						options={[
							{ value: '', label: __('All changes', 'seoprostats') },
							...CHANGE_GROUPS.map((g) => ({ value: g, label: groupLabel(g) })),
						]}
						onChange={(value: string) => update({ group: (value || undefined) as ChangeGroup | undefined })}
					/>
					<form
						className="spst-properties__event"
						onSubmit={(e) => {
							e.preventDefault();
							setPage(typed.trim());
						}}
					>
						<TextControl
							__nextHasNoMarginBottom
							label={__('Only on page', 'seoprostats')}
							value={typed}
							list={list}
							autoComplete="off"
							placeholder={__('Any page; * for any text', 'seoprostats')}
							onChange={setTyped}
						/>
						<datalist id={list}>
							{(pages.data?.rows ?? []).filter((r) => r.value !== '').map((r) => (
								<option key={r.value} value={r.value} />
							))}
						</datalist>
						<Button variant="secondary" type="submit">
							{__('Apply', 'seoprostats')}
						</Button>
						{page && (
							<Button
								variant="link"
								onClick={() => {
									setTyped('');
									setPage('');
								}}
							>
								{__('Any page', 'seoprostats')}
							</Button>
						)}
					</form>
				</div>

				<CardBody className="spst-card__body">
					{!answer && !query.isError && <div className="spst-skeleton spst-skeleton--table" aria-busy="true" />}
					{answer && !rows.length && (
						<div className="spst-empty">
							<p>{__('No changes in this period.', 'seoprostats')}</p>
							{!shareAccess.token && (
								<p>
									{__('Posts and pages published or edited, SEO titles and descriptions, product prices and stock, plugins, themes and settings are logged as they change. Google’s search updates show here too once switched on under Settings → Data.', 'seoprostats')}
								</p>
							)}
						</div>
					)}
					{rows.length > 0 && (
						<ChangesTable
							rows={rows}
							refreshing={query.isFetching}
							onPage={showPage}
							onDelete={(change) => void remove(change)}
							onExperiment={(change) => {
								startFrom(change);
								update({ view: 'search', report: 'experiments', change: String(change.id), page: undefined });
							}}
						/>
					)}
					{answer && answer.total > PER_PAGE && (
						<nav className="spst-changes__pager" aria-label={__('Pages of changes', 'seoprostats')}>
							<span className="spst-muted">
								{sprintf(
									/* translators: 1: first change shown, 2: last change shown, 3: number of changes. */
									_n('%1$s–%2$s of %3$s change', '%1$s–%2$s of %3$s changes', answer.total, 'seoprostats'),
									formatNumber(offset + 1, locale, false),
									formatNumber(Math.min(offset + PER_PAGE, answer.total), locale, false),
									formatNumber(answer.total, locale, false)
								)}
							</span>
							<Button variant="secondary" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PER_PAGE))}>
								{__('Newer', 'seoprostats')}
							</Button>
							<Button variant="secondary" disabled={offset + PER_PAGE >= answer.total} onClick={() => setOffset(offset + PER_PAGE)}>
								{__('Older', 'seoprostats')}
							</Button>
						</nav>
					)}
				</CardBody>
			</Card>

			{adding && <NoteModal page={page} onClose={() => setAdding(false)} />}
		</>
	);
}
