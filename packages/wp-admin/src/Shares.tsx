/**
 * Owner management for private reports; full links live only in this screen's memory.
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */
import { useEffect, useState } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Modal, Notice, SelectControl, TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { DEFAULT_STATE, type ViewState } from '@seoprostats/core';
import { errorMessage } from './api';

export interface ShareBranding {
    title: string;
    logo: number;
    agency: string;
    agency_logo: number;
    website: string;
    byline: string;
    accent: string;
    mode: 'light' | 'dark' | 'system';
    credit: boolean;
}
export interface SharedReport {
    id: string;
    name: string;
    note: string;
    views: ViewState[];
    locked_filters: ViewState['filters'];
    max_days: number;
    expires: number;
    hide_sensitive: boolean;
    hide_realtime: boolean;
    created: number;
    last_opened: number;
    opens: number;
    revoked: boolean;
    protected: boolean;
    branding: ShareBranding;
    url?: string;
}
const path = '/seoprostats/v1/shares';

export function ShareEditor({ state, share, close, saved }: { state: ViewState; share?: SharedReport; close: () => void; saved: (share: SharedReport) => void }) {
    const [name, setName] = useState(share?.name ?? '');
    const [note, setNote] = useState(share?.note ?? '');
    const [views, setViews] = useState(share?.views ?? [state]);
    const [locked, setLocked] = useState(share?.locked_filters ?? state.filters);
    const [password, setPassword] = useState('');
    const [clearPassword, setClearPassword] = useState(false);
    const [expires, setExpires] = useState(share?.expires ? new Date(share.expires * 1000).toISOString().slice(0, 10) : '');
    const [days, setDays] = useState(String(share?.max_days ?? 0));
    const [sensitive, setSensitive] = useState(share?.hide_sensitive ?? true);
    const [realtime, setRealtime] = useState(share?.hide_realtime ?? true);
    const [branding, setBranding] = useState<ShareBranding>(share?.branding ?? { title: '', logo: 0, agency: '', agency_logo: 0, website: '', byline: '', accent: '#2271b1', mode: 'system', credit: true });
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const save = async () => {
        setBusy(true);
        try {
            const answer = await apiFetch<SharedReport>({ path: share ? `${path}/${share.id}` : path, method: 'POST', data: {
                name, note, views, locked_filters: locked, max_days: Number(days), expires: expires ? Math.floor(new Date(`${expires}T23:59:59`).getTime() / 1000) : 0,
                hide_sensitive: sensitive, hide_realtime: realtime, branding,
                ...(!share || password || clearPassword ? { password } : {}),
            } });
            saved(answer);
            close();
        } catch (e) {
            setError(errorMessage(e, __('The report could not be saved.', 'seoprostats')));
        } finally {
            setBusy(false);
        }
    };
    return <Modal title={share ? __('Edit shared report', 'seoprostats') : __('Share this view', 'seoprostats')} onRequestClose={close}>
        {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
        <TextControl label={__('Name', 'seoprostats')} value={name} onChange={setName} />
        <TextareaControl label={__('Note for the reader', 'seoprostats')} value={note} onChange={setNote} />
        <p>{__('Sections, in this order', 'seoprostats')}</p>
        <ol>{views.map((view, index) => <li key={index}>{view.view} <Button disabled={views.length === 1} onClick={() => setViews(views.filter((_, i) => i !== index))}>{__('Remove', 'seoprostats')}</Button></li>)}</ol>
        <SelectControl label={__('Add a section', 'seoprostats')} value="" options={[{ label: __('Choose a section', 'seoprostats'), value: '' }, ...(['overview', 'goals', 'clicks'] as const).map((view) => ({ label: view, value: view }))]} onChange={(view) => { if (view && views.length < 10) setViews([...views, { ...DEFAULT_STATE, view: view as ViewState['view'] }]); }} />
        <CheckboxControl label={__('Lock the starting filters on every report', 'seoprostats')} checked={locked.length > 0} disabled={!state.filters.length && !share?.locked_filters.length} onChange={(checked) => setLocked(checked ? state.filters : [])} />
        <p>{locked.map((f) => `${f.dimension} ${f.op} ${f.values.join(', ')}`).join('; ')}</p>
        <TextControl label={__('Password (optional)', 'seoprostats')} type="password" value={password} onChange={setPassword} autoComplete="new-password" help={share?.protected ? __('Leave empty to keep the password.', 'seoprostats') : undefined} />
        {share?.protected && <CheckboxControl label={__('Remove the password', 'seoprostats')} checked={clearPassword} onChange={setClearPassword} />}
        <TextControl label={__('Expires (optional)', 'seoprostats')} type="date" value={expires} onChange={setExpires} />
        <TextControl label={__('Allow the last N days (0: any period)', 'seoprostats')} type="number" min={0} max={3650} value={days} onChange={setDays} />
        <CheckboxControl label={__('Hide realtime visitors', 'seoprostats')} checked={realtime} onChange={setRealtime} />
        <CheckboxControl label={__('Hide search terms and referrer addresses', 'seoprostats')} checked={sensitive} onChange={setSensitive} />
        <h3>{__('Branding', 'seoprostats')}</h3>
        {(['title', 'agency', 'website', 'byline'] as const).map((key) => <TextControl key={key} label={key} value={branding[key]} onChange={(value) => setBranding({ ...branding, [key]: value })} />)}
        {(['logo', 'agency_logo'] as const).map((key) => <TextControl key={key} type="number" min={0} label={`${key} (Media Library ID)`} value={String(branding[key])} onChange={(value) => setBranding({ ...branding, [key]: Number(value) })} />)}
        <TextControl label={__('Accent colour (hex)', 'seoprostats')} value={branding.accent} onChange={(accent) => setBranding({ ...branding, accent })} />
        <SelectControl label={__('Appearance', 'seoprostats')} value={branding.mode} options={['system', 'light', 'dark'].map((value) => ({ label: value, value }))} onChange={(mode) => setBranding({ ...branding, mode: mode as ShareBranding['mode'] })} />
        <CheckboxControl label={__('Show Statistics by SEO Pro Stats', 'seoprostats')} checked={branding.credit} onChange={(credit) => setBranding({ ...branding, credit })} />
        <Button variant="primary" disabled={busy || !name.trim()} onClick={() => void save()}>{busy ? __('Saving…', 'seoprostats') : __('Save report', 'seoprostats')}</Button>
    </Modal>;
}

export function Shares({ state }: { state: ViewState }) {
    const [shares, setShares] = useState<SharedReport[]>([]);
    const [editing, setEditing] = useState<SharedReport | 'new' | null>(null);
    const [links, setLinks] = useState<Record<string, string>>({});
    const [error, setError] = useState('');
    const reload = () => apiFetch<{ shares: SharedReport[] }>({ path }).then((answer) => setShares(answer.shares)).catch((e: unknown) => setError(errorMessage(e, __('Could not load reports.', 'seoprostats'))));
    useEffect(() => { void reload(); }, []);
    const act = async (share: SharedReport, renew: boolean) => {
        try {
            const answer = await apiFetch<{ url?: string }>({ path: `${path}/${share.id}${renew ? '/renew' : ''}`, method: renew ? 'POST' : 'DELETE' });
            setLinks((old) => ({ ...old, [share.id]: answer.url ?? '' }));
            void reload();
        } catch (e) { setError(errorMessage(e, __('Could not change this report.', 'seoprostats'))); }
    };
    return <section><h2>{__('Shared reports', 'seoprostats')}</h2>
        {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
        <p>{__('Links are shown once. Keep a copy; make a new link if it is lost. The old link then stops working.', 'seoprostats')}</p>
        <Button variant="primary" onClick={() => setEditing('new')}>{__('New shared report', 'seoprostats')}</Button>
        <div style={{ overflowX: 'auto' }}><table className="widefat"><thead><tr>{['Name', 'Sections', 'Created', 'Expires', 'Last opened', 'Opens', 'Actions'].map((label) => <th key={label}>{label}</th>)}</tr></thead><tbody>
            {shares.map((share) => <tr key={share.id}><td>{share.name}{share.revoked ? ' (revoked)' : ''}</td><td>{share.views.map((view) => view.view).join(', ')}</td>
                {[share.created, share.expires, share.last_opened].map((time, index) => <td key={index}>{time ? new Date(time * 1000).toLocaleString() : '—'}</td>)}<td>{share.opens}</td>
                <td>{links[share.id] && <><input aria-label="Private link" readOnly value={links[share.id]} onFocus={(event) => event.currentTarget.select()} /><Button onClick={() => { void navigator.clipboard.writeText(links[share.id]!).catch(() => setError(__('Select and copy the link above.', 'seoprostats'))); }}>{__('Copy link', 'seoprostats')}</Button></>}
                    <Button onClick={() => setEditing(share)}>{__('Edit', 'seoprostats')}</Button><Button onClick={() => void act(share, false)}>{__('Revoke', 'seoprostats')}</Button><Button onClick={() => void act(share, true)}>{__('Make a new link', 'seoprostats')}</Button></td></tr>)}
        </tbody></table></div>
        {editing && <ShareEditor state={state} share={editing === 'new' ? undefined : editing} close={() => setEditing(null)} saved={(share) => { if (share.url) setLinks((old) => ({ ...old, [share.id]: share.url! })); void reload(); }} />}
    </section>;
}
