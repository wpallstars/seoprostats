/**
 * Standalone, read-only report shell using the dashboard's report components.
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */
import { useEffect, useId, useState } from 'react';
import * as element from '@wordpress/element';
import { Button, CheckboxControl, Modal, Notice, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useIsFetching } from '@tanstack/react-query';
import { shareView, type ViewState } from '@seoprostats/core';
import { errorMessage, shareAccess, ShareSection, shareFetch, useMarkers } from './api';
import { PrintAll } from './printAll';
import type { SharedReport } from './Shares';
import { groupLabel } from './changelog';
import { Controls } from './components/Controls';
import { FilterBar } from './components/FilterBar';
import { RealtimeShown } from './components/Realtime';
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

type Opened = SharedReport & { unlock: string; home: string; site_name: string; logo_url: string; agency_logo_url: string; hidden_dimensions?: string[] };

/** #rgb or #rrggbb as red, green and blue (0–255); null for anything else. */
function channels(hex: string): number[] | null {
    const long = /^#[0-9a-f]{3}$/i.test(hex) ? `#${[...hex.slice(1)].map((c) => c + c).join('')}` : hex;
    return /^#[0-9a-f]{6}$/i.test(long) ? [1, 3, 5].map((offset) => parseInt(long.slice(offset, offset + 2), 16)) : null;
}

function contrast(a: number[], b: number[]): number {
    const luminance = (rgb: number[]) => {
        const [r, g, bl] = rgb.map((n) => n / 255).map((n) => (n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4));
        return r! * 0.2126 + g! * 0.7152 + bl! * 0.0722;
    };
    const x = luminance(a), y = luminance(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

/**
 * The report's accent as text and buttons can use it (4.5:1 on the page):
 * a colour too light for a white page is darkened, one too dark for the
 * dark page lightened, in small steps, so it stays the brand's hue.
 */
function readableAccent(hex: string, dark: boolean): string {
    const rgb = channels(hex);
    if (!rgb) {
        return dark ? '#72aee6' : '#2271b1';
    }
    const page = dark ? [29, 35, 39] : [255, 255, 255];
    const toward = dark ? 255 : 0;
    for (let step = 0; step <= 20; step++) {
        const mixed = rgb.map((n) => Math.round(n + ((toward - n) * step) / 20));
        if (contrast(mixed, page) >= 4.5) {
            return `#${mixed.map((n) => n.toString(16).padStart(2, '0')).join('')}`;
        }
    }
    return dark ? '#ffffff' : '#000000';
}

/** The section's changes as a dated list: printed under the chart, whose markers paper cannot hover. */
function PrintedChanges({ state }: { state: ViewState }) {
    const markers = useMarkers(state, state.view === 'search' ? (state.page ?? '') : '');
    const title = useId();
    const list = markers.data?.markers ?? [];
    if (!list.length) {
        return null;
    }
    return (
        <section className="spst-share-changes" aria-labelledby={title}>
            <h2 id={title}>
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

/** Paper has nothing to change. */
const ignore = () => undefined;

/** A section's report: the dashboard tab it was made from (Search without the owner's Plan and Experiments). */
function SectionBody(props: { state: ViewState; update: (patch: Partial<ViewState>) => void }) {
    switch (props.state.view) {
        case 'search':
            return <Search {...props} shared />;
        case 'goals':
            return <Goals {...props} />;
        case 'funnels':
            return <Funnels {...props} />;
        case 'properties':
            return <Properties {...props} />;
        case 'clicks':
            return <Clicks {...props} />;
        case 'changes':
            return <Changes {...props} />;
        default:
            return <Overview {...props} />;
    }
}

function Report({ share }: { share: Opened }) {
    const [index, setIndex] = useState(0);
    const [state, setState] = useState<ViewState>(share.views[0]!);
    const [mode, setMode] = useState(share.branding.mode);
    const [printing, setPrinting] = useState(false);
    // The print dialog's ticked sections (indexes, in report order); then the sections laid out for paper.
    const [choosing, setChoosing] = useState(false);
    const [ticked, setTicked] = useState<number[]>(() => share.views.map((_, i) => i));
    const [paper, setPaper] = useState<number[] | null>(null);
    const fetching = useIsFetching();
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
        const after = () => {
            setPrinting(false);
            setPaper(null);
        };
        window.addEventListener('beforeprint', before);
        window.addEventListener('afterprint', after);
        return () => {
            window.removeEventListener('beforeprint', before);
            window.removeEventListener('afterprint', after);
        };
    }, []);
    const dark = !printing && !paper && (mode === 'dark' || (mode === 'system' && systemDark));
    const accent = readableAccent(share.branding.accent, dark);
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
        setIndex(i);
        setState(share.views[i]!);
    };
    // One section prints at once; several are chosen first.
    const print = () => {
        if (share.views.length > 1) {
            setChoosing(true);
        } else {
            setPaper([0]);
        }
    };
    // The browser's own shortcut opens the same choice.
    useEffect(() => {
        const key = (event: globalThis.KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'p' && !paper) {
                event.preventDefault();
                print();
            }
        };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    });
    // Print once every printed section has its data, and its charts a moment to draw; at most half a minute's wait.
    useEffect(() => {
        if (!paper) {
            return;
        }
        const ready = window.setTimeout(() => window.print(), fetching ? 30000 : 600);
        return () => window.clearTimeout(ready);
    }, [paper, fetching]);
    const title = share.branding.title || share.site_name;
    // The printed sections: the one on screen as it is; the others as saved, for the period on screen.
    const printed = (paper ?? []).map((i) => (i === index ? state : { ...share.views[i]!, range: state.range, from: state.from, to: state.to, compare: state.compare }));
    // The head names the section shown, when the report has several (on paper, when one is printed).
    const named = share.views.length < 2 ? null : paper ? (printed.length === 1 ? printed[0]! : null) : section;
    return <div className={`spst-app spst-report ${dark ? 'is-dark' : 'is-light'}`} style={{ '--spst-share-accent': accent } as React.CSSProperties}>
        <header className="spst-share-head">
            <a className="spst-share-head__brand" href={share.home} rel="noopener">
                {share.logo_url && <img src={share.logo_url} alt="" />}
                <span className="spst-share-head__titles">
                    <h1>{title}</h1>
                    <span className="spst-share-head__subtitle">
                        {named ? sprintf(/* translators: %s: a report section, such as Overview or Search: Google. */ __('Statistics report: %s', 'seoprostats'), sectionLabel(named)) : __('Statistics report', 'seoprostats')}
                    </span>
                </span>
            </a>
            <div className="spst-share-head__tools">
                <Button variant="tertiary" icon={dark ? 'lightbulb' : 'visibility'} onClick={() => setMode(dark ? 'light' : 'dark')}>
                    {dark ? __('Light', 'seoprostats') : __('Dark', 'seoprostats')}
                </Button>
                <Button variant="secondary" icon="printer" onClick={print} disabled={!!paper} accessibleWhenDisabled>{__('Print or save as PDF', 'seoprostats')}</Button>
            </div>
        </header>
        {share.note && <p className="spst-share-note">{share.note}</p>}
        {paper ? (
            <>
                <div className="spst-share-preparing" role="status">
                    <Notice status="info" isDismissible={false}>
                        <span>{fetching ? __('Getting every tab of the chosen sections ready to print…', 'seoprostats') : __('Opening the print dialog…', 'seoprostats')}</span>{' '}
                        <Button variant="link" onClick={() => setPaper(null)}>{__('Cancel', 'seoprostats')}</Button>
                    </Notice>
                </div>
                {!!share.locked_filters.length && <p className="spst-share-locks">{__('This report shows only:', 'seoprostats')} {share.locked_filters.map(filterLabel).join('; ')}</p>}
                <PrintAll.Provider value>
                    {printed.map((view, i) => (
                        <ShareSection.Provider key={paper[i]} value={view.view}>
                            <section className="spst-share-print">
                                {printed.length > 1 && <h2 className="spst-share-print__title">{sectionLabel(view)}</h2>}
                                <FilterBar filters={view.filters} update={ignore} />
                                <div className="spst-share-body"><SectionBody state={view} update={ignore} /></div>
                                {(view.view === 'overview' || view.view === 'search') && <PrintedChanges state={view} />}
                            </section>
                        </ShareSection.Provider>
                    ))}
                </PrintAll.Provider>
            </>
        ) : (
            <>
                {share.views.length > 1 && (
                    <nav aria-label={__('Report sections', 'seoprostats')} className="spst-share-nav">
                        {share.views.map((view, i) => (
                            <Button key={i} variant={i === index ? 'primary' : 'secondary'} aria-current={i === index ? 'page' : undefined} onClick={() => open(i)}>{sectionLabel(view)}</Button>
                        ))}
                    </nav>
                )}
                <ShareSection.Provider value={section.view}>
                    <div className="spst-toolbar"><Controls state={state} update={update} /></div>
                    {!!share.locked_filters.length && <p className="spst-share-locks">{__('This report shows only:', 'seoprostats')} {share.locked_filters.map(filterLabel).join('; ')}</p>}
                    <FilterBar filters={state.filters} update={update} />
                    {/* The live count is site-wide: never beside locked filters. */}
                    <RealtimeShown.Provider value={!share.hide_realtime && !share.locked_filters.length}>
                        <div key={dark ? 'dark' : 'light'} className="spst-share-body"><SectionBody state={state} update={update} /></div>
                    </RealtimeShown.Provider>
                    {(state.view === 'overview' || state.view === 'search') && <PrintedChanges state={state} />}
                </ShareSection.Provider>
            </>
        )}
        {choosing && (
            <Modal title={__('Print or save as PDF', 'seoprostats')} onRequestClose={() => setChoosing(false)} className="spst-modal spst-print-dialog">
                <form
                    className="spst-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (ticked.length) {
                            setChoosing(false);
                            setPaper(ticked);
                        }
                    }}
                >
                    <fieldset className="spst-fieldset">
                        <legend>{__('Sections to print', 'seoprostats')}</legend>
                        {share.views.map((view, i) => (
                            <CheckboxControl
                                key={i}
                                __nextHasNoMarginBottom
                                label={sectionLabel(view)}
                                checked={ticked.includes(i)}
                                onChange={(on) => setTicked(on ? [...ticked, i].sort((a, b) => a - b) : ticked.filter((t) => t !== i))}
                            />
                        ))}
                    </fieldset>
                    <p className="spst-note">{__('Each section prints with all of its tabs, for the period on screen.', 'seoprostats')}</p>
                    <div className="spst-form__actions">
                        <Button variant="tertiary" onClick={() => setChoosing(false)}>{__('Cancel', 'seoprostats')}</Button>
                        <Button variant="primary" type="submit" icon="printer" disabled={!ticked.length} accessibleWhenDisabled>{__('Print', 'seoprostats')}</Button>
                    </div>
                </form>
            </Modal>
        )}
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
            shareAccess.hidden = answer.hidden_dimensions ?? [];
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
