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
}

export interface TimeseriesConfig {
	/** Axis label of each point, by index. */
	labels: string[];
	series: ChartSeries[];
	height: number;
	formatValue: (value: number) => string;
	/** Colour of axis text and grid lines. */
	axisColor: string;
	gridColor: string;
}

export interface TimeseriesChart {
	update: (config: TimeseriesConfig) => void;
	resize: (width: number) => void;
	destroy: () => void;
}

function alpha(color: string, opacity: number): string {
	const hex = color.trim().replace('#', '');
	if (/^[0-9a-f]{6}$/i.test(hex)) {
		const n = parseInt(hex, 16);
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
				values: (_u, splits) => splits.map((v) => getConfig().formatValue(v)),
			},
		],
		series: [
			{},
			...config.series.map((s) => ({
				label: s.label,
				stroke: s.color,
				width: s.dashed ? 1.5 : 2,
				dash: s.dashed ? [5, 4] : undefined,
				fill: s.fill ? alpha(s.color, 0.12) : undefined,
				points: { show: false },
				spanGaps: false,
			})),
		],
		plugins: [tooltipPlugin(getConfig)],
	};
}

function data(config: TimeseriesConfig): uPlot.AlignedData {
	const xs = config.labels.map((_, i) => i);
	return [xs, ...config.series.map((s) => xs.map((i) => s.values[i] ?? null))] as uPlot.AlignedData;
}

/** Draw a chart into an element; update it with new data, resize with the element. */
export function createTimeseries(el: HTMLElement, initial: TimeseriesConfig): TimeseriesChart {
	let config = initial;
	const getConfig = () => config;
	let plot = new uPlot(options(config, el.clientWidth || 600, getConfig), data(config), el);

	return {
		update(next) {
			const shape = next.series.length !== config.series.length || next.series.some((s, i) => {
				const was = config.series[i];
				return !was || was.color !== s.color || was.dashed !== s.dashed || was.fill !== s.fill;
			});
			config = next;
			if (shape || next.height !== plot.height) {
				// Series changed: make the chart again (cheap; uPlot is small).
				const width = plot.width;
				plot.destroy();
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
		destroy() {
			plot.destroy();
		},
	};
}
