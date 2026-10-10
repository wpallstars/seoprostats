/**
 * The Dashboard widget's default place: the top of the right-most column
 * (the left-most in right-to-left languages). WordPress shows one to four
 * columns by screen width and the Layout screen option, and lays them out
 * with floats, so the columns are measured rather than assumed.
 *
 * It runs only while the person has no saved arrangement that includes the
 * widget (PHP says so in boot.placeWidget), follows the column count as the
 * window changes, and stops as soon as they move a box: WordPress then saves
 * their arrangement and keeps it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

/** SEOProStats_Dashboard::WIDGET. */
const WIDGET_ID = 'seoprostats_widget';

type JQueryLike = (target: Document) => { on: (event: string, handler: () => void) => void };

declare global {
	interface Window {
		jQuery?: JQueryLike;
		postboxes?: { _mark_area?: () => void };
	}
}

/** The sortable list at the top of the far column. */
function target(): HTMLElement | null {
	const containers = Array.from(document.querySelectorAll<HTMLElement>('#dashboard-widgets .postbox-container')).filter(
		(el) => el.getClientRects().length > 0
	);
	if (!containers.length) {
		return null;
	}
	const rtl = document.documentElement.dir === 'rtl';
	// How far each container reaches towards the far side.
	const reach = (el: HTMLElement) => {
		const rect = el.getBoundingClientRect();
		return rtl ? -rect.left : rect.right;
	};
	const far = Math.max(...containers.map(reach));
	// Containers stacked in the far column (two columns stack 2, 3 and 4), topmost first.
	const column = containers
		.filter((el) => far - reach(el) < 4)
		.sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top);
	return column[0]?.querySelector<HTMLElement>('.meta-box-sortables') ?? null;
}

function place(): void {
	const widget = document.getElementById(WIDGET_ID);
	const into = target();
	if (!widget || !into || into.firstElementChild === widget) {
		return;
	}
	into.insertBefore(widget, into.firstElementChild);
	// Core marks empty columns as drop areas; tell it the columns changed.
	window.postboxes?._mark_area?.();
}

/** Place the widget now and whenever the column count may change, until the person moves a box. */
export function placeWidget(): void {
	let moved = false;
	let frame = 0;
	const again = () => {
		if (moved || frame) {
			return;
		}
		frame = window.requestAnimationFrame(() => {
			frame = 0;
			if (!moved) {
				place();
			}
		});
	};

	place();
	window.addEventListener('resize', again);
	// The Layout screen option changes the columns without a resize.
	document.querySelectorAll('.columns-prefs input').forEach((input) => input.addEventListener('change', again));
	// Core fires postbox-moved when a box is dragged or moved with its arrows.
	window.jQuery?.(document).on('postbox-moved', () => {
		moved = true;
	});
}
