/**
 * An A/B test's results and its winner, in the test block's sidebar.
 *
 * Results: per variant visits, the conversion rate on the test's first
 * goal and the chance to beat the control, with a short verdict and a
 * link to the A/B tests section. Read from GET /ab-tests/{id} (live data)
 * when the panel opens, never on the site; for people who may read the
 * statistics.
 *
 * Pick a winner: replaces the test block with the chosen variant's blocks
 * (one step to undo). Once the post is saved without the test, the editor
 * sends the winner to POST /ab-tests/{id}/winner, which ends the test with
 * it; its results stay. Undone before saving, nothing is sent.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useState, useSyncExternalStore } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { Button, PanelBody, SelectControl, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber, formatPercent, type AbTestAnswer } from '@seoprostats/core';
import { locale } from '../boot';
import { TEST, variantAttributes, variantLabel, type Status } from './model';
import { blockActions, blockEditor, editor, notice, removeNotice, undo, wp, type BlockInstance } from './wp';

const NAMESPACE = '/seoprostats/v1';

interface ResultsBoot {
	canRead: boolean;
	testsUrl: string;
}

const raw = (window as unknown as { seoprostatsAbTests?: Partial<ResultsBoot> }).seoprostatsAbTests ?? {};
const resultsBoot: ResultsBoot = {
	canRead: raw.canRead === true,
	testsUrl: typeof raw.testsUrl === 'string' ? raw.testsUrl : '',
};

/** A probability as a whole percentage, never 0% or 100% (nothing is certain). */
function chance(p: number): string {
	if (p > 0.995) {
		return `>${formatPercent(0.99, locale)}`;
	}
	if (p < 0.005) {
		return `<${formatPercent(0.01, locale)}`;
	}
	return formatPercent(Math.round(p * 100) / 100, locale);
}

/** One short line: "Variant B is ahead: 94% likely better", "Too early to call". */
export function shortVerdict(answer: AbTestAnswer): string {
	const leader = answer.variants.find((v) => v.slug === answer.leader?.slug);
	const control = answer.variants.find((v) => v.control);
	const p = leader?.primary.probability;
	switch (answer.verdict.code) {
		case 'no_data':
			return __('No visits have seen this test yet.', 'seoprostats');
		case 'too_early':
			// The server's sentence says what is still needed (visits, conversions, days).
			return answer.verdict.text || __('Too early to call.', 'seoprostats');
		case 'winner':
			return leader && typeof p === 'number'
				? sprintf(/* translators: 1: variant label, 2: chance, e.g. 97%. */ __('%1$s is ahead: %2$s likely better.', 'seoprostats'), leader.label, chance(p))
				: __('A variant is ahead.', 'seoprostats');
		case 'control':
			return sprintf(/* translators: %s: the control's label. */ __('%s, the control, is still best.', 'seoprostats'), control?.label ?? __('The control', 'seoprostats'));
		default:
			return leader && !leader.control && typeof p === 'number'
				? sprintf(
						/* translators: 1: variant label, 2: chance, e.g. 94%. */
						__('%1$s is ahead, %2$s likely better: not clear yet.', 'seoprostats'),
						leader.label,
						chance(p)
					)
				: __('No clear difference yet.', 'seoprostats');
	}
}

/*
 * Answers already read this session (test id → answer), so reopening the
 * panel is instant and the winner panel can start on the leader.
 */
const answers = new Map<string, AbTestAnswer | 'none'>();
const answerListeners = new Set<() => void>();

function remember(testId: string, answer: AbTestAnswer | 'none' | undefined): void {
	if (answer === undefined) {
		answers.delete(testId);
	} else {
		answers.set(testId, answer);
	}
	answerListeners.forEach((listener) => listener());
}

function subscribeAnswers(listener: () => void): () => void {
	answerListeners.add(listener);
	return () => answerListeners.delete(listener);
}

/** A test's answer as read so far, kept up to date. */
function useAnswer(testId: string): AbTestAnswer | 'none' | undefined {
	return useSyncExternalStore(subscribeAnswers, () => answers.get(testId));
}

function ResultsBody({ testId }: Readonly<{ testId: string }>) {
	const answer = useAnswer(testId);
	const [error, setError] = useState('');
	const [round, setRound] = useState(0);

	useEffect(() => {
		if (round === 0 && answers.has(testId)) {
			return;
		}
		let live = true;
		setError('');
		apiFetch<AbTestAnswer>({ path: `${NAMESPACE}/ab-tests/${testId}?data=live` })
			.then((a) => {
				if (live) {
					remember(testId, a);
				}
			})
			.catch((e: { code?: string; message?: string }) => {
				if (!live) {
					return;
				}
				if (e?.code === 'seoprostats_not_found') {
					remember(testId, 'none');
				} else {
					setError(e?.message || __('The results could not be read.', 'seoprostats'));
				}
			});
		return () => {
			live = false;
		};
	}, [testId, round]);

	const link = resultsBoot.testsUrl ? `${resultsBoot.testsUrl}?test=${encodeURIComponent(testId)}` : '';
	const refresh = (
		<Button variant="link" onClick={() => setRound((r) => r + 1)}>
			{__('Refresh', 'seoprostats')}
		</Button>
	);

	if (error) {
		return (
			<p className="spst-ab-note" role="alert">
				{error} {refresh}
			</p>
		);
	}
	if (answer === undefined) {
		return (
			<p className="spst-ab-note">
				<Spinner /> {__('Reading results…', 'seoprostats')}
			</p>
		);
	}
	if (answer === 'none') {
		return (
			<p className="spst-ab-note">
				{__('Too early to call: results start once the post is saved with the test running and visitors see it.', 'seoprostats')}
			</p>
		);
	}
	if (answer.visits === 0 && answer.mixed.visits === 0) {
		return (
			<div className="spst-ab-results">
				<p className="spst-ab-results__verdict is-no_data">{shortVerdict(answer)}</p>
				<p className="spst-ab-results__links">
					{link && <a href={link}>{__('Open in A/B tests', 'seoprostats')}</a>} {refresh}
				</p>
			</div>
		);
	}
	return (
		<div className="spst-ab-results">
			<p className={`spst-ab-results__verdict is-${answer.verdict.code}`}>{shortVerdict(answer)}</p>
			<table className="spst-ab-results__table">
				<caption>
					{answer.period.days < 1
						? sprintf(/* translators: %s: what the variants are compared on (a goal's name). */ __('%s, since it started today', 'seoprostats'), answer.primary.name)
						: sprintf(
								/* translators: 1: what the variants are compared on (a goal's name), 2: number of days. */
								_n('%1$s, over %2$d day', '%1$s, over %2$d days', answer.period.days, 'seoprostats'),
								answer.primary.name,
								answer.period.days
							)}
				</caption>
				<thead>
					<tr>
						<th scope="col">{__('Variant', 'seoprostats')}</th>
						<th scope="col">{__('Visits', 'seoprostats')}</th>
						<th scope="col">{__('Rate', 'seoprostats')}</th>
						<th scope="col">{__('Chance', 'seoprostats')}</th>
					</tr>
				</thead>
				<tbody>
					{answer.variants.map((v) => (
						<tr key={v.slug}>
							<th scope="row">{v.label}</th>
							<td>{formatNumber(v.visits, locale)}</td>
							<td>{formatPercent(v.primary.rate, locale)}</td>
							<td>{v.control ? __('Control', 'seoprostats') : v.primary.probability === null ? '—' : chance(v.primary.probability)}</td>
						</tr>
					))}
				</tbody>
			</table>
			<p className="spst-ab-note">
				{__('Chance: that its rate beats the control’s.', 'seoprostats')}
				{answer.mixed.visits > 0 &&
					' ' +
						sprintf(
							/* translators: %s: number of visits. */
							__('%s visits saw more than one variant and are left out.', 'seoprostats'),
							formatNumber(answer.mixed.visits, locale)
						)}
			</p>
			<p className="spst-ab-results__links">
				{link && <a href={link}>{__('Open in A/B tests', 'seoprostats')}</a>} {refresh}
			</p>
		</div>
	);
}

/** The results panel; reads when opened. Only for people who may read the statistics. */
export function ResultsPanel({ testId }: Readonly<{ testId: string }>) {
	if (!resultsBoot.canRead) {
		return null;
	}
	return (
		<PanelBody title={__('Results', 'seoprostats')}>
			<ResultsBody testId={testId} />
		</PanelBody>
	);
}

/*
 * Winners picked and waiting for the post to be saved: test id → the
 * variant's slug and the client ids of the blocks that replaced the test.
 * Sent once a save succeeds with the test gone and those blocks in its
 * place; kept while the test is back (undone, maybe redone later).
 */
const pending = new Map<string, { slug: string; blocks: string[] }>();
let watching = false;

/** Every test id in the editor's blocks now. */
function testIdsInPost(): Set<string> {
	const be = blockEditor();
	const ids = new Set<string>();
	for (const id of be.getClientIdsWithDescendants()) {
		if (be.getBlockName(id) === TEST) {
			const testId = be.getBlockAttributes(id)?.testId;
			if (typeof testId === 'string') {
				ids.add(testId);
			}
		}
	}
	return ids;
}

async function sendWinners(): Promise<void> {
	const be = blockEditor();
	const inPost = testIdsInPost();
	for (const [testId, { slug, blocks }] of [...pending]) {
		// Undone before saving: the test is back, and its block counts.
		if (inPost.has(testId)) {
			continue;
		}
		pending.delete(testId);
		// The winner's blocks are gone too (undone, then the test deleted): no winner to record.
		if (!blocks.some((id) => be.getBlock(id))) {
			continue;
		}
		try {
			await apiFetch({ path: `${NAMESPACE}/ab-tests/${testId}/winner`, method: 'POST', data: { variant: slug } });
			remember(testId, undefined);
			notice(__('The A/B test ended with its winner. Its results stay in A/B tests.', 'seoprostats'), [], 'success');
		} catch (e) {
			const code = (e as { code?: string })?.code;
			// A test never saved or never run has nothing to record.
			if (code !== 'seoprostats_not_found' && code !== 'seoprostats_never_ran') {
				notice((e as { message?: string })?.message || __('The winner could not be recorded.', 'seoprostats'), [], 'error');
			}
		}
	}
}

/*
 * Undo notices shown: notice id → the blocks that replaced the test. Each
 * goes once those blocks are gone (undone) or the post is saved, so its
 * Undo never undoes a later change.
 */
const undoNotices = new Map<string, string[]>();

/** Watch the post's saves (not autosaves) and send waiting winners after one succeeds. */
function watchSaves(): void {
	if (watching) {
		return;
	}
	watching = true;
	let saving = false;
	wp.data.subscribe(() => {
		const ed = editor();
		if (!ed || (!pending.size && !undoNotices.size)) {
			saving = false;
			return;
		}
		const be = blockEditor();
		for (const [id, blocks] of undoNotices) {
			if (!blocks.some((clientId) => be.getBlock(clientId))) {
				undoNotices.delete(id);
				removeNotice(id);
			}
		}
		if (ed.isSavingPost()) {
			saving = saving || !ed.isAutosavingPost();
			return;
		}
		if (saving) {
			saving = false;
			if (ed.didPostSaveRequestSucceed()) {
				undoNotices.forEach((_blocks, id) => removeNotice(id));
				undoNotices.clear();
				void sendWinners();
			}
		}
	});
}

/** Replace the test with a variant's blocks, and send the winner once the post is saved. */
function pickWinner(testClientId: string, testId: string, status: Status, variant: BlockInstance, label: string): void {
	const be = blockEditor();
	const block = be.getBlock(variant.clientId);
	const inner = (block?.innerBlocks ?? []).map((b) => wp.blocks.cloneBlock(b));
	const replacement = inner.length ? inner : [wp.blocks.createBlock('core/paragraph')];
	const blocks = replacement.map((b) => b.clientId);
	blockActions().replaceBlocks(testClientId, replacement);
	if (status !== 'draft') {
		pending.set(testId, { slug: variantAttributes(variant).slug, blocks });
	}
	const id = `spst-ab-winner-${testId}`;
	undoNotices.set(id, blocks);
	watchSaves();
	notice(
		status === 'draft'
			? sprintf(/* translators: %s: variant label. */ __('The test is now %s.', 'seoprostats'), label)
			: sprintf(
					/* translators: %s: variant label. */
					__('The test is now %s. Save the post to end the test with it as the winner.', 'seoprostats'),
					label
				),
		[{ label: __('Undo', 'seoprostats'), onClick: undo }],
		'info',
		id
	);
}

/**
 * Pick a winner: choose a variant (the leader when one is called, else the
 * one shown), then confirm. A draft test never ran: it just keeps one.
 */
export function WinnerPanel({
	testClientId,
	testId,
	status,
	variants,
	shown,
}: Readonly<{
	testClientId: string;
	testId: string;
	status: Status;
	variants: BlockInstance[];
	shown: string | undefined;
}>) {
	const [choice, setChoice] = useState('');
	const [confirming, setConfirming] = useState(false);
	const leader = useAnswer(testId);
	const leaderSlug = leader && leader !== 'none' && leader.verdict.code === 'winner' ? (leader.leader?.slug ?? '') : '';
	const fallback = variants.find((b) => variantAttributes(b).slug === leaderSlug) ?? variants.find((b) => b.clientId === shown) ?? variants[0];
	const chosen = variants.find((b) => b.clientId === choice) ?? fallback;
	if (!chosen) {
		return null;
	}
	const index = variants.indexOf(chosen);
	const label = variantLabel(chosen, index);
	return (
		<PanelBody title={__('Pick a winner', 'seoprostats')} initialOpen={false}>
			<p className="spst-ab-note spst-ab-winner__note">
				{status === 'draft'
					? __('This test has not run. Keeping one variant replaces the test with its blocks.', 'seoprostats')
					: __('The test is replaced with the winner’s blocks; after saving, everyone sees them, the test ends with that winner and its results stay.', 'seoprostats')}
			</p>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={status === 'draft' ? __('Variant to keep', 'seoprostats') : __('Winner', 'seoprostats')}
				value={chosen.clientId}
				options={variants.map((b, i) => ({ value: b.clientId, label: variantLabel(b, i) }))}
				onChange={(value: string) => {
					setChoice(value);
					setConfirming(false);
				}}
			/>
			<div className="spst-ab-variant-row__actions spst-ab-winner__actions">
				{!confirming ? (
					<Button variant="secondary" onClick={() => setConfirming(true)}>
						{sprintf(/* translators: %s: variant label. */ __('Keep %s…', 'seoprostats'), label)}
					</Button>
				) : (
					<>
						<Button variant="primary" onClick={() => pickWinner(testClientId, testId, status, chosen, label)}>
							{sprintf(/* translators: %s: variant label. */ __('Replace the test with %s', 'seoprostats'), label)}
						</Button>
						<Button variant="tertiary" onClick={() => setConfirming(false)}>
							{__('Cancel', 'seoprostats')}
						</Button>
					</>
				)}
			</div>
		</PanelBody>
	);
}
