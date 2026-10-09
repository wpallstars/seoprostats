/**
 * CrUX field samples and origin history; lab tests are explicit owner actions.
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { LighthouseAnswer, VitalMetric, VitalsAnswer } from '@seoprostats/core';
import { boot } from './boot';
import { useDataSet } from './data';
import { errorMessage, shareAccess } from './api';
import { MainChart } from './components/MainChart';
import { TableScroll } from './components/TableScroll';

export function Vitals({ chartOnly = false }: Readonly<{ chartOnly?: boolean }>) {
	const data = useDataSet();
	const [metric, setMetric] = useState<VitalMetric>('lcp');
	const [form, setForm] = useState<'PHONE' | 'DESKTOP'>('PHONE');
	const [show, setShow] = useState(false);
	const [lab, setLab] = useState<LighthouseAnswer | null>(null);
	const [running, setRunning] = useState('');
	const [error, setError] = useState('');
	const query = useQuery({ queryKey: ['vitals', data], queryFn: () => apiFetch<VitalsAnswer>({ path: `/seoprostats/v1/vitals?data=${data}` }), enabled: !shareAccess.token && (!chartOnly || show), staleTime: 60000 });
	if (shareAccess.token) return null;
	const answer = query.data;
	const points = (answer?.series ?? []).filter((p) => p.metric === metric && p.form_factor === form).map((p) => ({ t: `${p.day}T12:00:00Z`, p75: p.p75 })).sort((a, b) => a.t.localeCompare(b.t));
	const run = async (page: string) => {
		setRunning(page);
		setError('');
		setLab(null);
		try {
			setLab(await apiFetch<LighthouseAnswer>({ path: '/seoprostats/v1/vitals/lighthouse', method: 'POST', data: { page } }));
		} catch (e) {
			setError(errorMessage(e, __('Lighthouse could not be run.', 'seoprostats')));
		} finally {
			setRunning('');
		}
	};
	return <Card className="spst-card is-wide" size="small">
		<CardHeader><h2>{__('Page experience', 'seoprostats')}</h2>{chartOnly && <Button variant="secondary" onClick={() => setShow(!show)}>{show ? __('Hide origin metrics', 'seoprostats') : __('Show origin metrics', 'seoprostats')}</Button>}</CardHeader>
		{(!chartOnly || show) && <CardBody>
			<p>{__('Chrome UX Report: p75 from real Chrome users over overlapping 28-day windows, not daily measurements or Lighthouse lab scores. Traffic counts cover the last 30 days. Samples older than 14 days are stale and do not create findings.', 'seoprostats')}</p>
			{query.isError && <Notice status="error" isDismissible={false}>{errorMessage(query.error, __('Field data could not be read.', 'seoprostats'))}</Notice>}
			{answer && !answer.connected && <p>{__('Connect Chrome UX Report in Settings → Connections to collect field data.', 'seoprostats')}</p>}
			<SelectControl label={__('Origin metric (p75)', 'seoprostats')} value={metric} options={(['lcp', 'inp', 'cls', 'fcp', 'ttfb'] as const).map((m) => ({ value: m, label: m.toUpperCase() }))} onChange={(m: string) => setMetric(m as VitalMetric)} __nextHasNoMarginBottom />
			<SelectControl label={__('Form factor', 'seoprostats')} value={form} options={[{ value: 'PHONE', label: __('Phone', 'seoprostats') }, { value: 'DESKTOP', label: __('Desktop', 'seoprostats') }]} onChange={(f: string) => setForm(f === 'DESKTOP' ? 'DESKTOP' : 'PHONE')} __nextHasNoMarginBottom />
			{points.length > 0 ? <MainChart metric="p75" label={`${metric.toUpperCase()} p75${metric === 'cls' ? '' : ' (ms)'}`} format="decimal" series={{ grain: 'day', range: { key: 'custom', from: points[0]!.t, to: points[points.length - 1]!.t, timezone: 'UTC' }, points }} /> : <p>{__('No origin field data for this metric and form factor.', 'seoprostats')}</p>}
			{!chartOnly && answer && <TableScroll label={__('Page experience', 'seoprostats')}><table className="widefat striped"><thead><tr><th>{__('Page', 'seoprostats')}</th><th>{__('Search clicks', 'seoprostats')}</th><th>{__('Visits', 'seoprostats')}</th><th>{__('Field metrics (p75)', 'seoprostats')}</th><th>{__('Lab test', 'seoprostats')}</th></tr></thead><tbody>{answer.rows.map((row) => <tr key={row.path_id}><th scope="row">{row.path}{row.failing && <strong> — {__('Poor Core Web Vitals', 'seoprostats')}</strong>}</th><td>{row.clicks}</td><td>{row.visits}</td><td>{row.available ? row.samples.map((s) => <div key={`${s.form_factor}-${s.metric}`}>{s.form_factor} {s.metric.toUpperCase()}: {s.p75}{s.metric === 'cls' ? '' : ' ms'} · {s.status.replace('_', ' ')} · {s.day}{s.stale ? ` (${__('stale', 'seoprostats')})` : ''}</div>) : __('No record: insufficient eligible Chrome traffic or not checked yet.', 'seoprostats')}</td><td>{boot.canManage && data === 'live' && answer.connected && <Button variant="secondary" disabled={!!running} isBusy={running === row.path} onClick={() => void run(row.path)}>{__('Run Lighthouse', 'seoprostats')}</Button>}</td></tr>)}</tbody></table></TableScroll>}
			{error && <Notice status="error" isDismissible={false}>{error}</Notice>}
			{lab && data === 'live' && <section aria-live="polite"><h3>{__('Lighthouse lab opportunities', 'seoprostats')} — {lab.url}</h3><p>{__('One simulated mobile run, not CrUX field data.', 'seoprostats')} {lab.score === null ? '' : `${Math.round(lab.score * 100)}/100`}</p><ul>{lab.opportunities.map((o) => <li key={o.id}>{o.title} — {o.display}</li>)}</ul>{!lab.opportunities.length && <p>{__('No legacy opportunity audits were returned by this Lighthouse version.', 'seoprostats')}</p>}</section>}
		</CardBody>}
	</Card>;
}
