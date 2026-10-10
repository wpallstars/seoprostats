/**
 * A chart marker's changes in a modal, newest first, so they can be read
 * and closed without leaving the report; a link opens the Changes section
 * for the same days (and page).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState, type ReactNode } from 'react';
import { Button, Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import type { Marker, ViewState } from '@seoprostats/core';
import { ChangesTable } from './ChangesTable';

/** The changes a marker covers, and the days and label of its points. */
export interface MarkerPick {
	markers: Marker[];
	/** The points' first and last day (YYYY-MM-DD, site time zone). */
	from: string;
	to: string;
	/** The points' long label, e.g. "Sun, Sep 13, 2026". */
	when: string;
}

interface Props {
	pick: MarkerPick;
	onClose: () => void;
	/** Open the Changes section for these days. */
	onOpen: () => void;
}

export function ChangesModal({ pick, onClose, onOpen }: Readonly<Props>) {
	const rows = [...pick.markers].sort((a, b) => Date.parse(b.t) - Date.parse(a.t) || b.id - a.id);
	return (
		<Modal
			/* translators: %s: a day, or a span of days. */
			title={sprintf(__('Changes: %s', 'seoprostats'), pick.when)}
			onRequestClose={onClose}
			className="spst-modal is-wide spst-changes-modal"
		>
			{/* One day, named in the title: times only (a rollout begun earlier keeps its day). */}
			<ChangesTable rows={rows} timeOnly={rows.every((m) => m.t.slice(0, 10) === pick.from && pick.from === pick.to)} />
			<div className="spst-form__actions">
				<Button variant="tertiary" onClick={onClose}>
					{__('Close', 'seoprostats')}
				</Button>
				<Button variant="secondary" onClick={onOpen}>
					{__('Open in Changes', 'seoprostats')}
				</Button>
			</div>
		</Modal>
	);
}

/**
 * A chart's marker handler and its modal: the Changes link shows the same
 * days as a custom period, and the page the chart is for ('' for all).
 */
export function useChangesModal(update: (patch: Partial<ViewState>) => void, page: string): { onMarker: (pick: MarkerPick) => void; modal: ReactNode } {
	const [pick, setPick] = useState<MarkerPick | null>(null);
	const modal = pick ? (
		<ChangesModal
			pick={pick}
			onClose={() => setPick(null)}
			onOpen={() => {
				setPick(null);
				update({ view: 'changes', range: 'custom', from: pick.from, to: pick.to, page: page || undefined });
			}}
		/>
	) : null;
	return { onMarker: setPick, modal };
}
