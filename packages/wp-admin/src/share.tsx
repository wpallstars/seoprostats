/**
 * Standalone, read-only report shell using the dashboard's report components.
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */
import { useEffect, useState } from 'react';
import * as element from '@wordpress/element';
import { Button, Notice, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { shareView, type ViewState } from '@seoprostats/core';
import { errorMessage, queryClient, shareAccess, shareFetch, useMarkers } from './api';
import type { SharedReport } from './Shares';
import { groupLabel } from './changelog';
import { Controls } from './components/Controls';
import { FilterBar } from './components/FilterBar';
import { Realtime } from './components/Realtime';
import { longLabel, rangeText } from './dates';
import { filterLabel, sectionLabel } from './labels';
import { Overview } from './Overview';
import { Search } from './Search';
import { Goals } from './Goals';
import { Funnels } from './Funnels';
import { Properties } from './Properties';
import { Clicks } from './Clicks';
import { Changes } from './Changes';
import { mount } from './mount';
import './share.css';

declare global { interface Window { seoprostatsShare?: { token: string; root: string } } }
const config = window.seoprostatsShare;
shareAccess.token = config?.token ?? '';
shareAccess.root = config?.root ?? '';
try { shareAccess.unlock = sessionStorage.getItem(`spst-share-${shareAccess.token}`) ?? ''; } catch { /* Session storage is optional. */ }

/** Draw now (before printing); WordPress before 6.3 has no flushSync, and then its print styles alone apply. */
const flushSync: (draw: () => void) => void = (element as { flushSync?: (draw: () => void) => void }).flushSync ?? ((draw) => draw());

type Opened = SharedReport & { unlock: string; home: string; site_name: string; logo_url: string; agency_logo_url: string };

function contrast(hex: string, background: string): number {
    const luminance = (colour: string) => {
        const rgb = [1, 3, 5].map((offset) => parseInt(colour.slice(offset, offset + 2), 16) / 255).map((n) => n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4);
        return rgb[0]! * 0.2126 + rgb[1]! * 0.7152 + rgb[2]! * 0.0722;
    };
    const a = luminance(hex), b = luminance(background);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/** The section's changes as a dated list: printed under the chart, whose markers paper cannot hover. */
function PrintedChanges({ state }: { state: ViewState }) {
    const markers = useMarkers(state, state.view === 'search' ? (state.page ?? '') : '');
    const list = markers.data?.markers ?? [];
    if (!list.length) {
        return null;
    }
    return (
        <section className="spst-share-changes" aria-labelledby="spst-share-changes-title">
            <h2 id="spst-share-changes-title">
                {markers.data
                    ? sprintf(/* translators: %s: the period's days, such as "1 Sep – 30 Sep 2026". */ __('Changes, %s', 'seoprostats'), rangeText(markers.data.range.from, markers.data.range.to))
                    : __('Changes', 'seoprostats')}
            </h2>
            <ol>
                {list.map((marker) => (
                    <li key={marker.id}>
                        <time dateTime={marker.t}>{longLabel(marker.t, 'day')}</time>
                        <span className="spst-share-changes__group">{groupLabel(marker.group)}</span>
                        <span>
                            {String(marker.meta?.name ?? '') || marker.label}
                            {marker.title && marker.title !== marker.meta?.name ? `: ${marker.title}` : ''}
                            {marker.path ? <code>{marker.path}</code> : null}
                        </span>
                    </li>
                ))}
            </ol>
        </section>
    );
}

function Report({ share }: { share: Opened }) {
    const [index, setIndex] = useState(0);
    const [state, setState] = useState<ViewState>(share.views[0]!);
    const [mode, setMode] = useState(share.branding.mode);
    const [printing, setPrinting] = useState(false);
    const [systemDark, setSystemDark] = useState(window.matchMedia('(prefers-color-scheme: dark)').matches);
    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const changed = () => setSystemDark(media.matches);
        media.addEventListener('change', changed);
        return () => media.removeEventListener('change', changed);
    }, []);
    // Paper is light: the charts are drawn again in light colours before printing (also from the browser's menu).
    useEffect(() => {
        const before = () => flushSync(() => setPrinting(true));
        const after = () => setPrinting(false);
        window.addEventListener('beforeprint', before);
        window.addEventListener('afterprint', after);
        return () => {
            window.removeEventListener('beforeprint', before);
            window.removeEventListener('afterprint', after);
        };
    }, []);
    const dark = !printing && (mode === 'dark' || (mode === 'system' && systemDark));
    const accent = contrast(share.branding.accent, dark ? '#1d2327' : '#ffffff') >= 4.5 ? share.branding.accent : dark ? '#72aee6' : '#2271b1';
    const section = share.views[index]!;
    shareAccess.section = section.view;
    useEffect(() => {
        document.body.classList.toggle('spst-share-dark', dark);
        document.body.style.setProperty('--spst-share-accent', accent);
    }, [dark, accent]);
    const update = (patch: Partial<ViewState>) => {
        // The section and its engine stay; locks are shown apart and added by the server.
        setState(shareView({ ...state, ...patch, view: section.view, engine: section.engine })!);
    };
    const open = (i: number) => {
        queryClient.clear();
        setIndex(i);
        setState(share.views[i]!);
    };
    const print = () => {
        flushSync(() => setPrinting(true));
        window.print();
        setPrinting(false);
    };
    const props = { state, update };
    const title = share.branding.title || share.site_name;
    let body = <Overview {...props} />;
    if (state.view === 'search') {
        body = <Search {...props} shared />;
    } else if (state.view === 'goals') {
        body = <Goals {...props} />;
    } else if (state.view === 'funnels') {
        body = <Funnels {...props} />;
    } else if (state.view === 'properties') {
        body = <Properties {...props} />;
    } else if (state.view === 'clicks') {
        body = <Clicks {...props} />;
    } else if (state.view === 'changes') {
        body = <Changes {...props} />;
    }
    return <div className={`spst-app spst-report ${dark ? 'is-dark' : 'is-light'}`} style={{ '--spst-share-accent': accent } as React.CSSProperties}>
        <header className="spst-share-head">
            <a className="spst-share-head__brand" href={share.home} rel="noopener">
                {share.logo_url && <img src={share.logo_url} alt="" />}
                <span className="spst-share-head__titles">
                    <h1>{title}</h1>
                    <span className="spst-share-head__subtitle">
                        {share.views.length > 1 ? sprintf(/* translators: %s: a report section, such as Overview or Search: Google. */ __('Statistics report: %s', 'seoprostats'), sectionLabel(section)) : __('Statistics report', 'seoprostats')}
                    </span>
                </span>
            </a>
            <div className="spst-share-head__tools">
                <Button variant="tertiary" icon={dark ? 'lightbulb' : 'visibility'} onClick={() => setMode(dark ? 'light' : 'dark')}>
                    {dark ? __('Light', 'seoprostats') : __('Dark', 'seoprostats')}
                </Button>
                <Button variant="secondary" icon="printer" onClick={print}>{__('Print or save as PDF', 'seoprostats')}</Button>
            </div>
        </header>
        {share.note && <p className="spst-share-note">{share.note}</p>}
        {share.views.length > 1 && (
            <nav aria-label={__('Report sections', 'seoprostats')} className="spst-share-nav">
                {share.views.map((view, i) => (
                    <Button key={i} variant={i === index ? 'primary' : 'secondary'} aria-current={i === index ? 'page' : undefined} onClick={() => open(i)}>{sectionLabel(view)}</Button>
                ))}
            </nav>
        )}
        <div className="spst-toolbar"><Controls state={state} update={update} />{!share.hide_realtime && !share.locked_filters.length && state.view === 'overview' && <Realtime />}</div>
        {!!share.locked_filters.length && <p className="spst-share-locks">{__('This report shows only:', 'seoprostats')} {share.locked_filters.map(filterLabel).join('; ')}</p>}
        <FilterBar filters={state.filters} update={update} />
        <div key={dark ? 'dark' : 'light'} className="spst-share-body">{body}</div>
        {(state.view === 'overview' || state.view === 'search') && <PrintedChanges state={state} />}
        <footer className="spst-share-foot">
            {share.agency_logo_url && <img src={share.agency_logo_url} alt="" />}
            {(share.branding.byline || share.branding.agency) && (
                <p>{share.branding.byline} {share.branding.website ? <a href={share.branding.website} rel="noopener">{share.branding.agency || share.branding.website}</a> : share.branding.agency}</p>
            )}
            {share.branding.credit && <p className="spst-share-foot__credit">{__('Statistics logged by SEO Pro Stats for WordPress', 'seoprostats')}</p>}
        </footer>
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
        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Password', 'seoprostats')} type="password" value={password} onChange={setPassword} autoComplete="current-password" />
        <Button type="submit" variant="primary" disabled={busy}>{__('Open report', 'seoprostats')}</Button>
    </form>;
}
mount('spst-share', <ShareApp />);
