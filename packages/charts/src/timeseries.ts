/**
 * Time-series chart on uPlot. Points are placed by index, with labels the
 * caller makes in the site's time zone (uPlot's time axis would use the
 * browser's), so a comparison period lines up point for point.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import uPlot from 'uplot';
import 'uplot/dist/uPlot.min.css';

export interface ChartSeries {
	/** Name in the tooltip, e.g. "Visitors" or "Previous period". */
	label: string;
	values: Array<number | null>;
	color: string;
	dashed?: boolean;
	/** Shade the area under the line. */
	fill?: boolean;
	/** Each point's own label in the tooltip (a comparison's own dates). */
	pointLabels?: string[];
	/**
	 * Index of the first point still being counted (today, this hour): the
	 * line into it and on from it is dotted, so a low number reads as
	 * unfinished rather than a drop.
	 */
	partialFrom?: number;
}

export interface TimeseriesConfig {
	/** Axis label of each point, by index. */
	labels: string[];
	series: ChartSeries[];
	height: number;
	formatValue: (value: number) => string;
	/** Counts use whole-number gridlines; rates and averages keep fractional steps. */
	integer?: boolean;
	/** Colour of axis text and grid lines. */
	axisColor: string;
	gridColor: string;
	/** Called after each draw (data, size), e.g. to place a markers lane. */
	onDraw?: () => void;
}

export interface TimeseriesChart {
	update: (config: TimeseriesConfig) => void;
	resize: (width: number) => void;
	/** A point's x position in client pixels; null outside the points. */
	clientX: (index: number) => number | null;
	/** Show a thin vertical line at a point (null hides it). */
	guide: (index: number | null) => void;
	destroy: () => void;
}

/** Whether a series has a partial tail inside its points. */
function hasPartial(s: ChartSeries, length: number): s is ChartSeries & { partialFrom: number } {
	return s.partialFrom !== undefined && s.partialFrom > 0 && s.partialFrom < length;
}

function alpha(color: string, opacity: number): string {
	const hex = color.trim().replace('#', '');
	if (/^[0-9a-f]{6}$/i.test(hex)) {
		const n = Number.parseInt(hex, 16);
		return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${opacity})`;
	}
	return color;
}

/** A tooltip that follows the cursor, listing each series at that point. */
function tooltipPlugin(getConfig: () => TimeseriesConfig): uPlot.Plugin {
	let tip: HTMLDivElement;
	return {
		hooks: {
			init: (u: uPlot) => {
				tip = document.createElement('div');
				tip.className = 'spst-chart-tip';
				tip.setAttribute('aria-hidden', 'true');
				tip.style.display = 'none';
				u.over.appendChild(tip);
				u.over.addEventListener('mouseleave', () => {
					tip.style.display = 'none';
				});
			},
			setCursor: (u: uPlot) => {
				const idx = u.cursor.idx;
				if (idx === null || idx === undefined) {
					tip.style.display = 'none';
					return;
				}
				const config = getConfig();
				tip.textContent = '';
				config.series.forEach((s) => {
					const value = s.values[idx];
					const row = document.createElement('div');
					row.className = 'spst-chart-tip__row';
					const swatch = document.createElement('span');
					swatch.className = 'spst-chart-tip__swatch' + (s.dashed ? ' is-dashed' : '');
					swatch.style.borderColor = s.color;
					const when = document.createElement('span');
					when.className = 'spst-chart-tip__when';
					when.textContent = s.pointLabels?.[idx] ?? config.labels[idx] ?? '';
					const what = document.createElement('strong');
					what.textContent = value === null || value === undefined ? '—' : config.formatValue(value);
					row.append(swatch, when, what);
					tip.appendChild(row);
				});
				tip.style.display = 'block';
				const left = u.cursor.left ?? 0;
				const width = u.over.clientWidth;
				// Keep the tip inside the plot: right of the cursor, or left near the edge.
				const flip = left > width - tip.offsetWidth - 16;
				tip.style.left = `${flip ? left - tip.offsetWidth - 12 : left + 12}px`;
				tip.style.top = '8px';
			},
		},
	};
}

function options(config: TimeseriesConfig, width: number, getConfig: () => TimeseriesConfig): uPlot.Options {
	const font = '12px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
	return {
		width,
		height: config.height,
		legend: { show: false },
		cursor: {
			y: false,
			points: { size: 7 },
			drag: { x: false, y: false, setScale: false },
		},
		scales: {
			x: { time: false },
			y: {
				range: (_u, _min, max) => [0, max > 0 ? max * 1.1 : 1],
			},
		},
		axes: [
			{
				stroke: config.axisColor,
				font,
				grid: { show: false },
				ticks: { show: false },
				space: 64,
				incrs: [1, 2, 3, 4, 5, 6, 7, 10, 12, 14, 15, 24, 28, 30, 48, 60, 90, 120, 180, 365],
				values: (_u, splits) => splits.map((i) => (Number.isInteger(i) ? getConfig().labels[i] ?? '' : '')),
			},
			{
				stroke: config.axisColor,
				font,
				size: 56,
				grid: { stroke: config.gridColor, width: 1 },
				ticks: { show: false },
				incrs: config.integer ? Array.from({ length: 16 }, (_, i) => [1, 2, 5].map((n) => n * 10 ** i)).flat() : undefined,
				values: (_u, splits) => splits.map((v) => getConfig().formatValue(v)),
			},
		],
		series: [
			{},
			...config.series.flatMap((s) => {
				const line: uPlot.Series = {
					label: s.label,
					stroke: s.color,
					width: s.dashed ? 1.5 : 2,
					dash: s.dashed ? [5, 4] : undefined,
					fill: s.fill ? alpha(s.color, 0.12) : undefined,
					points: { show: false },
					spanGaps: false,
				};
				if (!hasPartial(s, config.labels.length)) {
					return [line];
				}
				// The unfinished tail: a second, dotted line with a lighter fill.
				return [line, { ...line, dash: [2, 4], fill: s.fill ? alpha(s.color, 0.05) : undefined }];
			}),
		],
		plugins: [tooltipPlugin(getConfig), { hooks: { draw: () => getConfig().onDraw?.() } }],
	};
}

function data(config: TimeseriesConfig): uPlot.AlignedData {
	const xs = config.labels.map((_, i) => i);
	const columns = config.series.flatMap((s) => {
		const values = xs.map((i) => s.values[i] ?? null);
		if (!hasPartial(s, xs.length)) {
			return [values];
		}
		// Finished points solid; the tail dotted from the last finished point on.
		const from = s.partialFrom;
		return [values.map((v, i) => (i < from ? v : null)), values.map((v, i) => (i >= from - 1 ? v : null))];
	});
	return [xs, ...columns] as uPlot.AlignedData;
}

/** Draw a chart into an element; update it with new data, resize with the element. */
export function createTimeseries(el: HTMLElement, initial: TimeseriesConfig): TimeseriesChart {
	let config = initial;
	const getConfig = () => config;
	let plot = new uPlot(options(config, el.clientWidth || 600, getConfig), data(config), el);
	let guide: HTMLDivElement | null = null;

	return {
		update(next) {
			const shape = next.integer !== config.integer || next.series.length !== config.series.length || next.series.some((s, i) => {
				const was = config.series[i];
				const length = next.labels.length;
				return was?.color !== s.color || was.dashed !== s.dashed || was.fill !== s.fill || hasPartial(was, config.labels.length) !== hasPartial(s, length);
			});
			config = next;
			if (shape || next.height !== plot.height) {
				// Series changed: make the chart again (cheap; uPlot is small).
				const width = plot.width;
				plot.destroy();
				guide = null;
				plot = new uPlot(options(config, width, getConfig), data(config), el);
			} else {
				plot.setData(data(config));
			}
		},
		resize(width) {
			if (width > 0 && width !== plot.width) {
				plot.setSize({ width, height: config.height });
			}
		},
		clientX(index) {
			if (index < 0 || index >= config.labels.length) {
				return null;
			}
			const x = plot.valToPos(index, 'x');
			return Number.isFinite(x) ? plot.over.getBoundingClientRect().left + x : null;
		},
		guide(index) {
			if (index === null || index < 0 || index >= config.labels.length) {
				if (guide) {
					guide.style.display = 'none';
				}
				return;
			}
			if (!guide) {
				guide = document.createElement('div');
				guide.className = 'spst-chart-guide';
				plot.over.appendChild(guide);
			}
			guide.style.left = `${plot.valToPos(index, 'x')}px`;
			guide.style.display = 'block';
		},
		destroy() {
			plot.destroy();
			guide = null;
		},
	};
}
