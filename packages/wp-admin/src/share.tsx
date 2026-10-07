/**
 * Standalone, read-only report shell using the dashboard's report components.
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */
import { useEffect, useState } from 'react';
import { Button, Notice, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { shareView, type ViewState } from '@seoprostats/core';
import { errorMessage, queryClient, shareAccess, shareFetch } from './api';
import type { SharedReport } from './Shares';
import { Controls } from './components/Controls';
import { FilterBar } from './components/FilterBar';
import { Realtime } from './components/Realtime';
import { Overview } from './Overview';
import { Goals } from './Goals';
import { Clicks } from './Clicks';
import { mount } from './mount';
import './share.css';

declare global { interface Window { seoprostatsShare?: { token: string; root: string } } }
const config = window.seoprostatsShare;
shareAccess.token = config?.token ?? '';
shareAccess.root = config?.root ?? '';
try { shareAccess.unlock = sessionStorage.getItem(`spst-share-${shareAccess.token}`) ?? ''; } catch { /* Session storage is optional. */ }

type Opened = SharedReport & { unlock: string; home: string; site_name: string; logo_url: string; agency_logo_url: string };

function contrast(hex: string, background: string): number {
    const luminance = (colour: string) => {
        const rgb = [1, 3, 5].map((offset) => parseInt(colour.slice(offset, offset + 2), 16) / 255).map((n) => n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4);
        return rgb[0]! * 0.2126 + rgb[1]! * 0.7152 + rgb[2]! * 0.0722;
    };
    const a = luminance(hex), b = luminance(background);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function Report({ share }: { share: Opened }) {
    const [index, setIndex] = useState(0);
    const [state, setState] = useState<ViewState>(share.views[0]!);
    const [mode, setMode] = useState(share.branding.mode);
    const [systemDark, setSystemDark] = useState(window.matchMedia('(prefers-color-scheme: dark)').matches);
    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const changed = () => setSystemDark(media.matches);
        media.addEventListener('change', changed);
        return () => media.removeEventListener('change', changed);
    }, []);
    const dark = mode === 'dark' || (mode === 'system' && systemDark);
    const accent = contrast(share.branding.accent, dark ? '#1d2327' : '#ffffff') >= 4.5 ? share.branding.accent : dark ? '#72aee6' : '#2271b1';
    shareAccess.section = state.view;
    useEffect(() => {
        document.body.classList.toggle('spst-share-dark', dark);
        document.body.style.setProperty('--spst-share-accent', accent);
    }, [dark, accent]);
    const update = (patch: Partial<ViewState>) => {
        // Canonicalize mutable choices; locks are displayed separately and added by the server.
        setState(shareView({ ...state, ...patch, view: share.views[index]!.view })!);
    };
    const props = { state, update };
    return <div className={`spst-app spst-report ${dark ? 'is-dark' : 'is-light'}`} style={{ '--spst-share-accent': accent } as React.CSSProperties}>
        <header className="spst-share-brand"><a href={share.home} rel="noopener">{share.logo_url && <img src={share.logo_url} alt="" />}<h1>{share.branding.title || share.site_name}</h1></a>
            <Button onClick={() => setMode(dark ? 'light' : 'dark')}>{dark ? __('Light', 'seoprostats') : __('Dark', 'seoprostats')}</Button>
            <Button onClick={() => window.print()}>{__('Print / Save as PDF', 'seoprostats')}</Button></header>
        {share.note && <p className="spst-share-note">{share.note}</p>}
        <nav aria-label={__('Report sections', 'seoprostats')} className="spst-share-nav">{share.views.map((view, i) => <Button key={i} variant={i === index ? 'primary' : 'secondary'} aria-current={i === index ? 'page' : undefined} onClick={() => { queryClient.clear(); setIndex(i); setState(view); }}>{view.view}</Button>)}</nav>
        <div className="spst-toolbar"><Controls state={state} update={update} />{!share.hide_realtime && !share.locked_filters.length && state.view === 'overview' && <Realtime />}</div>
        {!!share.locked_filters.length && <p>{__('Locked filters:', 'seoprostats')} {share.locked_filters.map((f) => `${f.dimension} ${f.op} ${f.values.join(', ')}`).join('; ')}</p>}
        <FilterBar filters={state.filters} update={update} />
        <div key={dark ? 'dark' : 'light'}>{state.view === 'goals' ? <Goals {...props} /> : state.view === 'clicks' ? <Clicks {...props} /> : <Overview {...props} />}</div>
        <footer className="spst-share-brand">{share.agency_logo_url && <img src={share.agency_logo_url} alt="" />}<p>{share.branding.byline} {share.branding.website ? <a href={share.branding.website} rel="noopener">{share.branding.agency}</a> : share.branding.agency}</p>{share.branding.credit && <p>{__('Statistics by SEO Pro Stats', 'seoprostats')}</p>}</footer>
    </div>;
}

function ShareApp() {
    const [share, setShare] = useState<Opened | null>(null);
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const open = async (value: string) => {
        setBusy(true);
        try {
            const answer = await shareFetch<Opened>('', 'POST', { password: value });
            shareAccess.unlock = answer.unlock;
            try { sessionStorage.setItem(`spst-share-${shareAccess.token}`, answer.unlock); } catch { /* No cookie fallback. */ }
            setShare(answer);
            setPassword('');
            setError('');
        } catch (e) { setError(errorMessage(e, __('This report is unavailable.', 'seoprostats'))); }
        finally { setBusy(false); }
    };
    useEffect(() => { void open(''); }, []);
    if (share) return <Report share={share} />;
    return <form className="spst-share-unlock" onSubmit={(event) => { event.preventDefault(); void open(password); }}><h1>{__('Shared report', 'seoprostats')}</h1>
        {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
        <TextControl label={__('Password', 'seoprostats')} type="password" value={password} onChange={setPassword} autoComplete="current-password" />
        <Button type="submit" variant="primary" disabled={busy}>{__('Open report', 'seoprostats')}</Button>
    </form>;
}
mount('spst-share', <ShareApp />);
