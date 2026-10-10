/**
 * Markers lane: the changes in a chart's range, in a strip under its plot,
 * one marker per point (day, hour or month) with changes. Markers closer
 * than a finger's width, or whose pills would touch, merge into one with a
 * count, so a year of daily changes (or a phone's width) stays readable. Each marker is a button: hover or focus lists its
 * changes, choosing it calls back. Arrow keys move between markers (one tab
 * stop for the lane). Spans (a search engine update's rollout) are thin
 * bars under the markers, from their first point to their last.
 *
 * The lane knows nothing of dates or the plot: the caller places it with
 * a function giving each point's x position (TimeseriesChart.clientX) and
 * passes translated text.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

export interface ChartMarkerItem {
	/** One line saying what changed. */
	label: string;
	/** Its group's colour. */
	color: string;
}

export interface ChartMarker {
	/** The point (by index) the changes fall in. */
	index: number;
	items: ChartMarkerItem[];
}

/** Something that lasts (a search engine update's rollout), drawn as a bar under the markers. */
export interface ChartSpan {
	/** First point (by index) it covers. */
	from: number;
	/** Last point (by index) it covers. */
	to: number;
	color: string;
}

export interface MarkersLaneConfig {
	markers: ChartMarker[];
	/** Spans, for sight only: the markers' labels carry their dates. */
	spans?: ChartSpan[];
	/** Name of the lane for screen readers, e.g. "Changes". */
	label: string;
	/** A point's long label (its day, hour or month). */
	pointLabel: (index: number) => string;
	/** Text for the changes not listed in a tooltip, e.g. "and 4 more". */
	moreText: (count: number) => string;
	/** A marker chosen: the point indexes it covers. */
	onSelect?: (indexes: number[]) => void;
	/** A marker hovered or focused (its first point), or none. */
	onHover?: (index: number | null) => void;
}

export interface MarkersLane {
	update: (config: MarkersLaneConfig) => void;
	/** Place the markers: x of a point in client pixels, or null when not shown. */
	layout: (clientX: (index: number) => number | null) => void;
	destroy: () => void;
}

/** Closest two markers may be, in pixels, before they merge. */
const MIN_GAP = 18;

/** Space kept between two markers' pills, in pixels. */
const PILL_GAP = 2;

/** Most changes listed in a tooltip. */
const MAX_LISTED = 8;

/**
 * A marker's width as the stylesheet draws it (DESIGN.md → Markers): 4px
 * padding and a 1px border each side, up to three 8px dots 2px apart, and
 * the count; at least 20px.
 */
function markerWidth(items: ChartMarkerItem[]): number {
	const dots = Math.min(3, new Set(items.map((item) => item.color)).size);
	const count = items.length > 1 ? 4 + 7 * String(items.length).length : 0;
	return Math.max(20, 10 + 8 * dots + 2 * Math.max(0, dots - 1) + count);
}

interface Group {
	x: number;
	indexes: number[];
	items: ChartMarkerItem[];
}

/** Draw a markers lane into an element; place it with layout(). */
export function createMarkersLane(el: HTMLElement, initial: MarkersLaneConfig): MarkersLane {
	let config = initial;
	let clientX: ((index: number) => number | null) | null = null;
	let buttons: HTMLButtonElement[] = [];
	let bars: HTMLElement[] = [];
	let focusIndex = 0;

	el.classList.add('spst-chart-lane');
	el.setAttribute('role', 'group');
	const tip = document.createElement('div');
	tip.className = 'spst-chart-tip spst-chart-lane__tip';
	tip.setAttribute('aria-hidden', 'true');
	tip.style.display = 'none';
	el.appendChild(tip);

	function heading(group: Group): string {
		const first = config.pointLabel(group.indexes[0] ?? 0);
		const last = config.pointLabel(group.indexes[group.indexes.length - 1] ?? 0);
		return first === last ? first : `${first} – ${last}`;
	}

	function hide(): void {
		tip.style.display = 'none';
		config.onHover?.(null);
	}

	function show(group: Group): void {
		tip.textContent = '';
		const head = document.createElement('div');
		head.className = 'spst-chart-lane__when';
		head.textContent = heading(group);
		tip.appendChild(head);
		group.items.slice(0, MAX_LISTED).forEach((item) => {
			const row = document.createElement('div');
			row.className = 'spst-chart-tip__row';
			const dot = document.createElement('span');
			dot.className = 'spst-chart-lane__dot';
			dot.style.background = item.color;
			const text = document.createElement('span');
			text.textContent = item.label;
			row.append(dot, text);
			tip.appendChild(row);
		});
		if (group.items.length > MAX_LISTED) {
			const more = document.createElement('div');
			more.className = 'spst-chart-lane__more';
			more.textContent = config.moreText(group.items.length - MAX_LISTED);
			tip.appendChild(more);
		}
		tip.style.display = 'block';
		const width = el.clientWidth;
		const left = Math.max(0, Math.min(group.x - tip.offsetWidth / 2, width - tip.offsetWidth));
		tip.style.left = `${left}px`;
		config.onHover?.(group.indexes[0] ?? null);
	}

	function groups(): Group[] {
		if (!clientX) {
			return [];
		}
		const base = el.getBoundingClientRect().left;
		const placed: Array<{ x: number; marker: ChartMarker }> = [];
		for (const marker of config.markers) {
			const x = marker.items.length ? clientX(marker.index) : null;
			if (x !== null && Number.isFinite(x)) {
				placed.push({ x: x - base, marker });
			}
		}
		placed.sort((a, b) => a.x - b.x || a.marker.index - b.marker.index);
		const out: Array<Group & { xs: number[] }> = [];
		const centre = (g: { xs: number[] }) => g.xs.reduce((sum, x) => sum + x, 0) / g.xs.length;
		for (const p of placed) {
			const last = out[out.length - 1];
			// Merge when closer than a finger's width, or when the two pills would touch.
			const touch = last ? (markerWidth(last.items) + markerWidth(p.marker.items)) / 2 + PILL_GAP : 0;
			if (last && (p.x - (last.xs[0] ?? p.x) < MIN_GAP || p.x - centre(last) < touch)) {
				last.xs.push(p.x);
				last.indexes.push(p.marker.index);
				last.items.push(...p.marker.items);
			} else {
				out.push({ x: p.x, xs: [p.x], indexes: [p.marker.index], items: [...p.marker.items] });
			}
		}
		return out.map((g) => ({ x: centre(g), indexes: g.indexes, items: g.items }));
	}

	function renderSpans(): void {
		bars.forEach((b) => b.remove());
		bars = [];
		if (!clientX) {
			return;
		}
		const base = el.getBoundingClientRect().left;
		for (const span of config.spans ?? []) {
			const x1 = clientX(Math.min(span.from, span.to));
			const x2 = clientX(Math.max(span.from, span.to));
			if (x1 === null || x2 === null || !Number.isFinite(x1) || !Number.isFinite(x2)) {
				continue;
			}
			const bar = document.createElement('span');
			bar.className = 'spst-chart-lane__span';
			bar.setAttribute('aria-hidden', 'true');
			bar.style.left = `${x1 - base}px`;
			bar.style.width = `${Math.max(2, x2 - x1)}px`;
			bar.style.background = span.color;
			el.insertBefore(bar, el.firstChild);
			bars.push(bar);
		}
	}

	function move(to: number): void {
		const target = buttons[Math.max(0, Math.min(to, buttons.length - 1))];
		if (target) {
			buttons.forEach((b) => (b.tabIndex = -1));
			target.tabIndex = 0;
			target.focus();
		}
	}

	function render(): void {
		const hadFocus = buttons.includes(document.activeElement as HTMLButtonElement);
		buttons.forEach((b) => b.remove());
		buttons = [];
		el.setAttribute('aria-label', config.label);
		renderSpans();
		const list = groups();
		focusIndex = Math.min(focusIndex, Math.max(0, list.length - 1));
		list.forEach((group, i) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'spst-chart-lane__marker';
			// Whole pixels, so the ring and its dots stay sharp.
			button.style.left = `${Math.round(group.x)}px`;
			button.tabIndex = i === focusIndex ? 0 : -1;
			const listed = group.items.slice(0, MAX_LISTED).map((item) => item.label);
			const rest = group.items.length - listed.length;
			const more = rest > 0 ? '; ' + config.moreText(rest) : '';
			button.setAttribute('aria-label', `${heading(group)}: ${listed.join('; ')}${more}`);
			const colors = [...new Set(group.items.map((item) => item.color))].slice(0, 3);
			colors.forEach((color) => {
				const dot = document.createElement('span');
				dot.className = 'spst-chart-lane__dot';
				dot.style.background = color;
				dot.setAttribute('aria-hidden', 'true');
				button.appendChild(dot);
			});
			button.style.borderColor = colors[0] ?? '';
			if (group.items.length > 1) {
				const count = document.createElement('span');
				count.className = 'spst-chart-lane__count';
				count.setAttribute('aria-hidden', 'true');
				count.textContent = String(group.items.length);
				button.appendChild(count);
			}
			button.addEventListener('mouseenter', () => show(group));
			button.addEventListener('mouseleave', () => {
				if (document.activeElement !== button) {
					hide();
				}
			});
			button.addEventListener('focus', () => {
				focusIndex = i;
				show(group);
			});
			button.addEventListener('blur', hide);
			button.addEventListener('click', () => config.onSelect?.(group.indexes));
			button.addEventListener('keydown', (event) => {
				const keys: Record<string, number> = { ArrowLeft: i - 1, ArrowRight: i + 1, Home: 0, End: buttons.length - 1 };
				if (event.key === 'Escape') {
					hide();
				} else if (event.key in keys) {
					event.preventDefault();
					move(keys[event.key] ?? i);
				}
			});
			tip.before(button);
			buttons.push(button);
		});
		el.classList.toggle('is-empty', list.length === 0);
		if (hadFocus) {
			move(focusIndex);
		}
	}

	return {
		update(next) {
			config = next;
			hide();
			render();
		},
		layout(next) {
			clientX = next;
			render();
		},
		destroy() {
			buttons.forEach((b) => b.remove());
			bars.forEach((b) => b.remove());
			tip.remove();
			el.classList.remove('spst-chart-lane', 'is-empty');
			el.removeAttribute('role');
			el.removeAttribute('aria-label');
		},
	};
}
