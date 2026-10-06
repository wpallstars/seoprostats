/**
 * The frame of the goal and funnel editors: a modal with a form, its
 * error and Save and Cancel.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState, type FormEvent, type ReactNode } from 'react';
import { Button, Modal, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { errorMessage } from '../api';

interface Props {
	title: string;
	onClose: () => void;
	/** Saves; a thrown error is shown and the modal stays open. */
	onSave: () => Promise<unknown>;
	canSave: boolean;
	children: ReactNode;
	/** Room for a list of steps. */
	wide?: boolean;
}

// Components come from WordPress itself (6.2 and later): only props it has.
export function DefinitionModal({ title, onClose, onSave, canSave, children, wide = false }: Props) {
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');

	const submit = async (event: FormEvent) => {
		event.preventDefault();
		if (busy || !canSave) {
			return;
		}
		setBusy(true);
		setError('');
		try {
			await onSave();
			onClose();
		} catch (e) {
			setError(errorMessage(e, __('It could not be saved. Try again.', 'seoprostats')));
			setBusy(false);
		}
	};

	return (
		<Modal title={title} onRequestClose={onClose} className={`spst-modal${wide ? ' is-wide' : ''}`}>
			<form onSubmit={submit} className="spst-form">
				{error && (
					<Notice status="error" isDismissible={false}>
						{error}
					</Notice>
				)}
				{children}
				<div className="spst-form__actions">
					<Button variant="tertiary" onClick={onClose} disabled={busy}>
						{__('Cancel', 'seoprostats')}
					</Button>
					<Button variant="primary" type="submit" isBusy={busy} disabled={busy || !canSave}>
						{__('Save', 'seoprostats')}
					</Button>
				</div>
			</form>
		</Modal>
	);
}
