/**
 * Changes as a table: when, what (with its group and source), the page
 * and who. Used by the Changes section and the chart's changes modal.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Button, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ChangeSource, Marker } from '@seoprostats/core';
import { shareAccess } from '../api';
import { boot } from '../boot';
import { groupLabel, sourceUrl } from '../changelog';
import { momentLabel, timeLabel } from '../dates';
import { TableScroll } from './TableScroll';

function sourceLabel(source: ChangeSource): string {
	const labels: Record<ChangeSource, string> = {
		wordpress: __('WordPress', 'seoprostats'),
		cli: __('WP-CLI', 'seoprostats'),
		api: __('REST API', 'seoprostats'),
		cron: __('Scheduled', 'seoprostats'),
		feed: __('Feed', 'seoprostats'),
		note: __('Note', 'seoprostats'),
	};
	return labels[source] ?? source;
}

interface Props {
	rows: Marker[];
	/** Dim the rows while newer ones load. */
	refreshing?: boolean;
	/** A page chosen; without it, pages are plain text. */
	onPage?: (path: string) => void;
	/** Delete a note (administrators). */
	onDelete?: (change: Marker) => void;
	/** Start an experiment on a page's change (administrators). */
	onExperiment?: (change: Marker) => void;
	/** All on one day (named elsewhere): show the time only. */
	timeOnly?: boolean;
}

/** A change an experiment can measure: one page's own, not a note, update or experiment. */
function measurable(change: Marker): boolean {
	return !!change.path && change.group !== 'note' && change.group !== 'search';
}

export function ChangesTable({ rows, refreshing = false, onPage, onDelete, onExperiment, timeOnly = false }: Readonly<Props>) {
	const actions = boot.canManage && (!!onDelete || !!onExperiment);
	// A shared report does not say who made a change.
	const who = !shareAccess.token;
	return (
		<TableScroll label={__('Changes', 'seoprostats')}>
			<table className={`widefat striped spst-table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('When', 'seoprostats')}</th>
						<th scope="col">{__('Change', 'seoprostats')}</th>
						<th scope="col">{__('Page', 'seoprostats')}</th>
						{who && <th scope="col">{__('By', 'seoprostats')}</th>}
						{actions && (
							<th scope="col">
								<span className="screen-reader-text">{__('Actions', 'seoprostats')}</span>
							</th>
						)}
					</tr>
				</thead>
				<tbody>
					{rows.map((change) => (
						<tr key={change.id}>
							<td className={timeOnly ? 'spst-nowrap' : undefined}>{timeOnly ? timeLabel(change.t) : momentLabel(change.t)}</td>
							<td>
								<span className="spst-changes__group">
									<span className="spst-chart-lane__dot" style={{ background: `var(--spst-mark-${change.group})` }} aria-hidden="true" />
									<span>{change.label}</span>
								</span>
								<span className="spst-meta">
									{groupLabel(change.group)}
									{sourceUrl(change) && (
										<>
											{' · '}
											<ExternalLink href={sourceUrl(change) ?? ''}>
												{__('Source', 'seoprostats')}
												<span className="screen-reader-text"> {change.label}</span>
											</ExternalLink>
										</>
									)}
								</span>
							</td>
							<td>
								{change.path && onPage ? (
									<button
										type="button"
										className="spst-link"
										title={__('Show this page’s statistics', 'seoprostats')}
										onClick={() => onPage(change.path ?? '')}
									>
										{change.path}
									</button>
								) : (
									change.path ?? <span className="spst-muted">{__('Whole site', 'seoprostats')}</span>
								)}
							</td>
							{who && (
								<td>
									{change.user ?? <span className="spst-muted">–</span>}
									<span className="spst-meta">{sourceLabel(change.source)}</span>
								</td>
							)}
							{actions && (
								<td className="spst-actions">
									{onExperiment && measurable(change) && (
										<Button variant="link" onClick={() => onExperiment(change)}>
											{__('Start an experiment', 'seoprostats')}
											<span className="screen-reader-text"> {change.label}</span>
										</Button>
									)}
									{onDelete && change.kind === 'note' && (
										<Button variant="link" isDestructive onClick={() => onDelete(change)}>
											{__('Delete', 'seoprostats')}
											<span className="screen-reader-text"> {change.label}</span>
										</Button>
									)}
								</td>
							)}
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}
