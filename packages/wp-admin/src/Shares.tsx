/**
 * Shared reports for administrators: the Share editor (a modal from the
 * Share button or the list), the list of reports, and the private link,
 * shown once (only its hash is stored) with Copy and Open.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */
import { useEffect, useId, useLayoutEffect, useRef, useState, type FormEvent, type KeyboardEvent, type PointerEvent } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Modal, Notice, SelectControl, TextControl, TextareaControl } from '@wordpress/components';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf } from '@wordpress/i18n';
import { shareSectionKey, shareView, switchView, SHARE_VIEWS, type SearchEngine, type View, type ViewState } from '@seoprostats/core';
import { errorMessage } from './api';
import { boot, locale } from './boot';
import { filterLabel, sectionLabel } from './labels';

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
/** GET /shares. */
interface SharesAnswer {
    shares: SharedReport[];
    defaults: ShareBranding;
    /** Logo attachment id => image address. */
    logos: Record<string, string>;
    /** Search engines with data: a Search section for each. */
    engines: SearchEngine[];
    /** Sections with live data (shareSectionKey() names), in tab order: a new report starts with these. */
    sections?: string[];
}

const path = '/seoprostats/v1/shares';
const MAX_SECTIONS = 10;
const DEFAULT_BRANDING: ShareBranding = { title: '', logo: 0, agency: '', agency_logo: 0, website: '', byline: '', accent: '#2271b1', mode: 'system', credit: true };
/** Sections only page filters can narrow (search data and the change log have no visits). */
const PAGE_ONLY = ['search', 'changes'];

/** WordPress's Media Library frame (wp_enqueue_media() on this screen). */
interface MediaFrame {
    on: (event: 'select', callback: () => void) => void;
    open: () => void;
    state: () => { get: (name: 'selection') => { first: () => { toJSON: () => { id: number; url: string; sizes?: { medium?: { url: string } } } } } };
}
const mediaLibrary = () => (window as unknown as { wp?: { media?: (options: Record<string, unknown>) => MediaFrame } }).wp?.media;

function chooseImage(title: string, done: (id: number, url: string) => void): void {
    const media = mediaLibrary();
    if (!media) {
        return;
    }
    const frame = media({ title, multiple: false, library: { type: ['image/png', 'image/jpeg', 'image/webp', 'image/gif'] }, button: { text: __('Use this image', 'seoprostats') } });
    frame.on('select', () => {
        const picked = frame.state().get('selection').first().toJSON();
        done(picked.id, picked.sizes?.medium?.url ?? picked.url);
    });
    frame.open();
}

/** The private link: shown once, with Copy and Open (a new window). */
export function ShareLink({ url, name, onDismiss }: { url: string; name?: string; onDismiss: () => void }) {
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const [copied, setCopied] = useState(false);
    const copy = () => {
        input.current?.select();
        navigator.clipboard.writeText(url).then(
            () => setCopied(true),
            () => setCopied(false)
        );
    };
    return (
        <Notice status="success" className="spst-notice spst-share-link" onRemove={onDismiss}>
            <p className="spst-share-link__title">
                <strong>{name ? sprintf(/* translators: %s: a shared report's name. */ __('The private link to “%s”', 'seoprostats'), name) : __('The private link', 'seoprostats')}</strong>
            </p>
            <p className="spst-share-link__help">{__('Copy it now: it is shown only once. If it is lost, make a new link; the old one then stops working.', 'seoprostats')}</p>
            <div className="spst-share-link__row">
                <label className="screen-reader-text" htmlFor={id}>{__('Private link', 'seoprostats')}</label>
                <input ref={input} id={id} className="spst-share-link__url code" readOnly value={url} onFocus={(event) => event.currentTarget.select()} />
                <Button variant="primary" icon={copied ? 'yes' : 'admin-page'} onClick={copy}>
                    {copied ? __('Copied', 'seoprostats') : __('Copy link', 'seoprostats')}
                </Button>
                <Button variant="secondary" icon="external" href={url} target="_blank" rel="noopener noreferrer">
                    {__('Open', 'seoprostats')}
                    <span className="screen-reader-text">{__('(opens in a new window)', 'seoprostats')}</span>
                </Button>
            </div>
            <span aria-live="polite" className="screen-reader-text">{copied ? __('Link copied.', 'seoprostats') : ''}</span>
        </Notice>
    );
}

function LogoField({ label, help, id, url, choose, clear }: { label: string; help: string; id: number; url: string; choose: () => void; clear: () => void }) {
    const canChoose = !!mediaLibrary();
    return (
        <div className="spst-share-logo">
            <span className="spst-share-logo__label">{label}</span>
            {id > 0 && url && <img src={url} alt="" className="spst-share-logo__image" />}
            <div className="spst-share-logo__actions">
                <Button variant="secondary" disabled={!canChoose} accessibleWhenDisabled onClick={choose}>
                    {id ? __('Change', 'seoprostats') : __('Choose from the Media Library', 'seoprostats')}
                </Button>
                {id > 0 && <Button variant="tertiary" isDestructive onClick={clear}>{__('Remove', 'seoprostats')}</Button>}
            </div>
            <p className="spst-share-logo__help">{canChoose ? help : __('The Media Library did not load. Reload the page to choose a logo.', 'seoprostats')}</p>
        </div>
    );
}

/** Six dots: a handle to drag (WordPress's own drag handle, drawn here). */
function DragHandleIcon() {
    return (
        <svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            {[8, 15].flatMap((x) => [7, 12, 17].map((y) => <circle key={`${x}-${y}`} cx={x + 0.5} cy={y} r="1.5" />))}
        </svg>
    );
}

/**
 * The sections in order. Drag one by its handle (mouse, pen or touch); or
 * Tab to the handle, press Space, move it with the arrow keys (Home, End)
 * and press Space again; Escape puts it back. Each move is announced.
 */
function SectionOrder({ views, setViews }: { views: ViewState[]; setViews: (views: ViewState[]) => void }) {
    const help = useId();
    const list = useRef<HTMLOListElement>(null);
    const handles = useRef(new Map<string, HTMLButtonElement>());
    // Picked up from the keyboard: the section and where it was.
    const [held, setHeld] = useState<{ key: string; from: number } | null>(null);
    // Dragged with a pointer.
    const [dragging, setDragging] = useState<string | null>(null);
    const [said, setSaid] = useState('');
    const keys = views.map(shareSectionKey);
    const count = views.length;
    const moveTo = (key: string, to: number) => {
        const from = keys.indexOf(key);
        if (from < 0 || to < 0 || to >= count || from === to) {
            return;
        }
        const next = [...views];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item!);
        setViews(next);
    };
    /* translators: 1: a report section, such as Overview, 2: its place in the list, 3: the number of sections. */
    const at = (name: string, place: number) => sprintf(__('%1$s, position %2$d of %3$d.', 'seoprostats'), name, place + 1, count);
    // React may move the focused handle in the page: keep the focus on the one held.
    useLayoutEffect(() => {
        if (held) {
            handles.current.get(held.key)?.focus();
        }
    }, [views, held]);

    const onKey = (event: KeyboardEvent<HTMLButtonElement>, key: string) => {
        const place = keys.indexOf(key);
        const name = sectionLabel(views[place]!);
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();
            if (held?.key === key) {
                setHeld(null);
                setSaid(`${sprintf(/* translators: %s: a report section. */ __('%s dropped.', 'seoprostats'), name)} ${at(name, place)}`);
            } else {
                setHeld({ key, from: place });
                setSaid(`${sprintf(/* translators: %s: a report section. */ __('%s picked up.', 'seoprostats'), name)} ${at(name, place)} ${__('Move it with the up and down arrow keys, then press Space to drop it, or Escape to cancel.', 'seoprostats')}`);
            }
            return;
        }
        if (held?.key !== key) {
            return;
        }
        if (event.key === 'Escape') {
            // Not the dialog's Escape: only the move is cancelled.
            event.preventDefault();
            event.stopPropagation();
            moveTo(key, held.from);
            setHeld(null);
            setSaid(`${sprintf(/* translators: %s: a report section. */ __('Moving %s cancelled.', 'seoprostats'), name)} ${at(name, held.from)}`);
            return;
        }
        const to = ({ ArrowUp: place - 1, ArrowDown: place + 1, Home: 0, End: count - 1 } as Record<string, number>)[event.key];
        if (to === undefined) {
            return;
        }
        event.preventDefault();
        if (to >= 0 && to < count && to !== place) {
            moveTo(key, to);
            setSaid(at(name, to));
        }
    };

    // While dragging, the page follows the pointer (the list's items move under it, so not the handle's own events).
    useEffect(() => {
        if (!dragging) {
            return;
        }
        // Its place: how many other sections' middles are above the pointer.
        const follow = (event: globalThis.PointerEvent) => {
            const from = keys.indexOf(dragging);
            let to = 0;
            Array.from(list.current?.children ?? []).forEach((item, i) => {
                const box = item.getBoundingClientRect();
                if (i !== from && event.clientY > box.top + box.height / 2) {
                    to++;
                }
            });
            moveTo(dragging, to);
        };
        const drop = () => {
            setDragging(null);
            const place = keys.indexOf(dragging);
            const name = sectionLabel(views[place]!);
            setSaid(`${sprintf(/* translators: %s: a report section. */ __('%s dropped.', 'seoprostats'), name)} ${at(name, place)}`);
        };
        window.addEventListener('pointermove', follow);
        window.addEventListener('pointerup', drop);
        window.addEventListener('pointercancel', drop);
        return () => {
            window.removeEventListener('pointermove', follow);
            window.removeEventListener('pointerup', drop);
            window.removeEventListener('pointercancel', drop);
        };
    });

    return (
        <>
            <p id={help} className="spst-note">
                {__('Drag a section by its handle to change the order. With the keyboard: Tab to the handle, press Space, move it with the arrow keys, and press Space again.', 'seoprostats')}
            </p>
            <ol ref={list} className={`spst-share-sections${dragging ? ' is-dragging' : ''}`}>
                {views.map((view, index) => {
                    const key = keys[index]!;
                    const name = sectionLabel(view);
                    return (
                        <li key={key} className={`spst-share-sections__item${held?.key === key || dragging === key ? ' is-moving' : ''}`}>
                            <button
                                type="button"
                                ref={(el) => {
                                    if (el) {
                                        handles.current.set(key, el);
                                    } else {
                                        handles.current.delete(key);
                                    }
                                }}
                                className="spst-share-sections__handle"
                                aria-label={sprintf(/* translators: %s: a report section, such as Overview. */ __('Move %s', 'seoprostats'), name)}
                                aria-describedby={help}
                                aria-pressed={held?.key === key}
                                disabled={count < 2}
                                onKeyDown={(event) => onKey(event, key)}
                                onBlur={() => window.requestAnimationFrame(() => setHeld((was) => (was?.key === key && document.activeElement !== handles.current.get(key) ? null : was)))}
                                onPointerDown={(event: PointerEvent<HTMLButtonElement>) => {
                                    if (event.button !== 0 || count < 2) {
                                        return;
                                    }
                                    // No text selection or scrolling while dragging.
                                    event.preventDefault();
                                    setHeld(null);
                                    setDragging(key);
                                }}
                            >
                                <DragHandleIcon />
                            </button>
                            <span className="spst-share-sections__name">{name}</span>
                            <Button size="small" variant="tertiary" isDestructive disabled={count === 1} accessibleWhenDisabled onClick={() => setViews(views.filter((_, i) => i !== index))}>
                                {__('Remove', 'seoprostats')}
                                <span className="screen-reader-text"> {name}</span>
                            </Button>
                        </li>
                    );
                })}
            </ol>
            <p className="screen-reader-text" aria-live="assertive" aria-atomic="true">{said}</p>
        </>
    );
}

export function ShareEditor({ state, share, close, saved }: { state: ViewState; share?: SharedReport; close: () => void; saved: (share: SharedReport) => void }) {
    const first = shareView(state) ?? { ...state, view: 'overview' as const };
    const [name, setName] = useState(share?.name ?? '');
    const [note, setNote] = useState(share?.note ?? '');
    const [views, setViews] = useState<ViewState[]>(share?.views ?? [first]);
    const [locked, setLocked] = useState(share?.locked_filters ?? []);
    const [password, setPassword] = useState('');
    const [clearPassword, setClearPassword] = useState(false);
    const [expires, setExpires] = useState(share?.expires ? new Date(share.expires * 1000).toISOString().slice(0, 10) : '');
    const [days, setDays] = useState(String(share?.max_days ?? 0));
    const [sensitive, setSensitive] = useState(share?.hide_sensitive ?? true);
    const [realtime, setRealtime] = useState(share?.hide_realtime ?? true);
    const [branding, setBranding] = useState<ShareBranding>(share?.branding ?? DEFAULT_BRANDING);
    const [logos, setLogos] = useState<Record<string, string>>({});
    const [engines, setEngines] = useState<SearchEngine[]>(['google']);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    // A section's view: the one on screen when it is that section; else its defaults, with the period and filters on screen.
    const sectionView = (key: string): ViewState | null => {
        if (shareSectionKey(first) === key) {
            return first;
        }
        const [view, engine] = key.split(':') as [View, SearchEngine | undefined];
        if (!SHARE_VIEWS.includes(view)) {
            return null;
        }
        const next = { ...switchView(first, view), ...(engine && engine !== 'google' ? { engine } : {}) };
        return shareView(next) ?? next;
    };
    useEffect(() => {
        void apiFetch<SharesAnswer>({ path })
            .then((answer) => {
                // A new report starts from the settings' branding, and with every section that has data (the one on screen first).
                if (!share) {
                    setBranding(answer.defaults);
                    const keys = [shareSectionKey(first), ...(answer.sections ?? []).filter((key) => key !== shareSectionKey(first))].slice(0, MAX_SECTIONS);
                    const start = keys.map(sectionView).filter((view): view is ViewState => !!view);
                    // Only while the list is as it opened: never undo a choice made meanwhile.
                    setViews((was) => (was.length === 1 && shareSectionKey(was[0]!) === shareSectionKey(first) ? start : was));
                }
                setLogos(answer.logos ?? {});
                setEngines(answer.engines?.length ? answer.engines : ['google']);
            })
            .catch((e: unknown) => setError(errorMessage(e, __('The branding from the settings could not be loaded.', 'seoprostats'))));
        // Once per editor: first and sectionView follow the view it opened with.
    }, [share]);

    const brand = <K extends keyof ShareBranding>(key: K, value: ShareBranding[K]) => setBranding((was) => ({ ...was, [key]: value }));
    // Every tab, once; Search once for each engine with data.
    const chosen = new Set(views.map(shareSectionKey));
    type Section = { view: View; engine?: SearchEngine };
    const offered = SHARE_VIEWS.flatMap((view): Section[] => (view === 'search' ? engines.map((engine) => ({ view, engine })) : [{ view }]))
        .filter((section) => !chosen.has(shareSectionKey(section)));
    const add = (key: string) => {
        const next = offered.some((s) => shareSectionKey(s) === key) && views.length < MAX_SECTIONS ? sectionView(key) : null;
        if (next) {
            setViews([...views, next]);
        }
    };
    const lockable = share?.locked_filters.length ? share.locked_filters : first.filters;
    const pageOnlyClash = locked.some((f) => f.dimension !== 'page') && views.some((v) => PAGE_ONLY.includes(v.view));
    const settingsLink = boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'shared-reports' }) : '';

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (busy) {
            return;
        }
        if (!name.trim()) {
            setError(__('Give the report a name, so you can find it in the list of shared reports.', 'seoprostats'));
            return;
        }
        setBusy(true);
        setError('');
        try {
            const answer = await apiFetch<SharedReport>({ path: share ? `${path}/${share.id}` : path, method: 'POST', data: {
                name, note, views, locked_filters: locked, max_days: Number(days), expires: expires ? Math.floor(new Date(`${expires}T23:59:59`).getTime() / 1000) : 0,
                hide_sensitive: sensitive, hide_realtime: realtime, branding,
                ...(!share || password || clearPassword ? { password } : {}),
            } });
            saved(answer);
            close();
        } catch (e) {
            setError(errorMessage(e, __('The report could not be saved. Try again.', 'seoprostats')));
            setBusy(false);
        }
    };

    return (
        <Modal
            title={share ? __('Edit shared report', 'seoprostats') : __('Share this view', 'seoprostats')}
            onRequestClose={close}
            // The Media Library opens over it: a click there is not a click away.
            shouldCloseOnClickOutside={false}
            className="spst-modal is-wide spst-share-editor"
        >
            <form onSubmit={submit} className="spst-form" noValidate>
                {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
                <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Name', 'seoprostats')} help={__('For you, in the list of shared reports. Readers see the report title.', 'seoprostats')} value={name} onChange={setName} required />
                <TextareaControl __nextHasNoMarginBottom label={__('Note for the reader (optional)', 'seoprostats')} value={note} onChange={setNote} rows={3} />

                <fieldset className="spst-fieldset">
                    <legend>{__('Sections, in this order', 'seoprostats')}</legend>
                    <SectionOrder views={views} setViews={setViews} />
                    {offered.length > 0 && views.length < MAX_SECTIONS && (
                        <SelectControl
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                            label={__('Add a section', 'seoprostats')}
                            value=""
                            options={[{ label: __('Choose a section…', 'seoprostats'), value: '' }, ...offered.map((s) => ({ label: sectionLabel(s), value: shareSectionKey(s) }))]}
                            onChange={add}
                        />
                    )}
                </fieldset>

                <fieldset className="spst-fieldset">
                    <legend>{__('What readers can see', 'seoprostats')}</legend>
                    <CheckboxControl
                        __nextHasNoMarginBottom
                        label={__('Lock the filters on every section', 'seoprostats')}
                        help={lockable.length
                            ? sprintf(/* translators: %s: filters, such as "Country is Spain". */ __('Readers cannot remove: %s.', 'seoprostats'), lockable.map(filterLabel).join('; '))
                            : __('To lock filters, close this, filter the view (choose a row, such as a country), then share it.', 'seoprostats')}
                        checked={locked.length > 0}
                        disabled={!lockable.length}
                        onChange={(checked) => setLocked(checked ? lockable : [])}
                    />
                    {pageOnlyClash && (
                        <Notice status="warning" isDismissible={false} className="spst-notice">
                            {__('Search and Changes can only be locked to pages. Remove the other filters or those sections.', 'seoprostats')}
                        </Notice>
                    )}
                    <CheckboxControl __nextHasNoMarginBottom label={__('Hide realtime visitors', 'seoprostats')} checked={realtime} onChange={setRealtime} />
                    <CheckboxControl __nextHasNoMarginBottom label={__('Hide site search terms and referrer addresses', 'seoprostats')} checked={sensitive} onChange={setSensitive} />
                    <div className="spst-form__row">
                        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Days readers can look back', 'seoprostats')} help={__('0 for no limit.', 'seoprostats')} type="number" min={0} max={3650} value={days} onChange={setDays} />
                        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Expires (optional)', 'seoprostats')} type="date" value={expires} onChange={setExpires} />
                    </div>
                    <TextControl
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                        label={__('Password (optional)', 'seoprostats')}
                        type="password"
                        value={password}
                        onChange={setPassword}
                        autoComplete="new-password"
                        help={share?.protected ? __('Leave empty to keep the password.', 'seoprostats') : undefined}
                    />
                    {share?.protected && <CheckboxControl __nextHasNoMarginBottom label={__('Remove the password', 'seoprostats')} checked={clearPassword} onChange={setClearPassword} />}
                </fieldset>

                <fieldset className="spst-fieldset">
                    <legend>{__('Branding', 'seoprostats')}</legend>
                    <p className="spst-note">
                        {__('Starts from the shared reports settings; changes here are for this report only.', 'seoprostats')}{' '}
                        {settingsLink && <a href={settingsLink}>{__('Change the defaults', 'seoprostats')}</a>}
                    </p>
                    <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Report title', 'seoprostats')} help={__('The site name when left empty.', 'seoprostats')} value={branding.title} onChange={(value) => brand('title', value)} />
                    <LogoField
                        label={__('Report logo', 'seoprostats')}
                        help={__('The site logo when left empty.', 'seoprostats')}
                        id={branding.logo}
                        url={logos[branding.logo] ?? ''}
                        choose={() => chooseImage(__('Report logo', 'seoprostats'), (id, url) => { setLogos((was) => ({ ...was, [id]: url })); brand('logo', id); })}
                        clear={() => brand('logo', 0)}
                    />
                    <div className="spst-form__row">
                        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Agency name', 'seoprostats')} value={branding.agency} onChange={(value) => brand('agency', value)} />
                        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Agency website', 'seoprostats')} type="url" value={branding.website} onChange={(value) => brand('website', value)} />
                    </div>
                    <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Byline', 'seoprostats')} help={__('Before the agency name at the foot, such as “Prepared by”.', 'seoprostats')} value={branding.byline} onChange={(value) => brand('byline', value)} />
                    <LogoField
                        label={__('Agency logo', 'seoprostats')}
                        help={__('At the foot of the report.', 'seoprostats')}
                        id={branding.agency_logo}
                        url={logos[branding.agency_logo] ?? ''}
                        choose={() => chooseImage(__('Agency logo', 'seoprostats'), (id, url) => { setLogos((was) => ({ ...was, [id]: url })); brand('agency_logo', id); })}
                        clear={() => brand('agency_logo', 0)}
                    />
                    <div className="spst-share-accent" role="group" aria-label={__('Accent colour', 'seoprostats')}>
                        <span className="spst-share-accent__label">{__('Accent colour', 'seoprostats')}</span>
                        <div className="spst-share-accent__swatches">
                            {boot.sharePalette.map((swatch) => (
                                <button
                                    key={swatch.color}
                                    type="button"
                                    className="spst-share-accent__swatch"
                                    style={{ background: swatch.color }}
                                    aria-pressed={branding.accent.toLowerCase() === swatch.color.toLowerCase()}
                                    title={`${swatch.name} (${swatch.color})`}
                                    onClick={() => brand('accent', swatch.color)}
                                >
                                    <span className="screen-reader-text">{swatch.name}</span>
                                </button>
                            ))}
                            <label className="spst-share-accent__other">
                                <input type="color" value={branding.accent} onChange={(event) => brand('accent', event.currentTarget.value)} />
                                {__('Other colour', 'seoprostats')}
                            </label>
                        </div>
                    </div>
                    <SelectControl
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                        label={__('Light or dark', 'seoprostats')}
                        value={branding.mode}
                        options={[{ label: __('Follow the reader’s device', 'seoprostats'), value: 'system' }, { label: __('Light', 'seoprostats'), value: 'light' }, { label: __('Dark', 'seoprostats'), value: 'dark' }]}
                        onChange={(mode) => brand('mode', mode as ShareBranding['mode'])}
                    />
                    <CheckboxControl __nextHasNoMarginBottom label={__('Show “Statistics logged by SEO Pro Stats for WordPress”', 'seoprostats')} checked={branding.credit} onChange={(credit) => brand('credit', credit)} />
                </fieldset>

                <div className="spst-form__actions">
                    <Button variant="tertiary" onClick={close} disabled={busy}>{__('Cancel', 'seoprostats')}</Button>
                    <Button variant="primary" type="submit" isBusy={busy} disabled={busy || pageOnlyClash} accessibleWhenDisabled>
                        {share ? __('Save', 'seoprostats') : __('Save and make the link', 'seoprostats')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

const when = (time: number) => (time ? new Date(time * 1000).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

export function Shares({ state }: { state: ViewState }) {
    const [shares, setShares] = useState<SharedReport[]>([]);
    const [loaded, setLoaded] = useState(false);
    const [editing, setEditing] = useState<SharedReport | 'new' | null>(null);
    const [fresh, setFresh] = useState<{ url: string; name: string } | null>(null);
    const [error, setError] = useState('');
    const reload = () =>
        apiFetch<SharesAnswer>({ path })
            .then((answer) => setShares(answer.shares))
            .catch((e: unknown) => setError(errorMessage(e, __('The shared reports could not be loaded.', 'seoprostats'))))
            .finally(() => setLoaded(true));
    useEffect(() => { void reload(); }, []);
    const act = async (share: SharedReport, renew: boolean) => {
        try {
            const answer = await apiFetch<{ url?: string }>({ path: `${path}/${share.id}${renew ? '/renew' : ''}`, method: renew ? 'POST' : 'DELETE' });
            setFresh(answer.url ? { url: answer.url, name: share.name } : null);
            void reload();
        } catch (e) { setError(errorMessage(e, __('This report could not be changed. Try again.', 'seoprostats'))); }
    };
    return (
        <section className="spst-shares">
            <div className="spst-section__head">
                <h2 className="spst-section__title">{__('Shared reports', 'seoprostats')}</h2>
                <Button variant="primary" onClick={() => setEditing('new')}>{__('New shared report', 'seoprostats')}</Button>
            </div>
            <p className="spst-note">{__('Read-only reports for clients and colleagues, at a private link. A link is shown once: keep a copy, or make a new one (the old one then stops working).', 'seoprostats')}</p>
            {error && <Notice status="error" className="spst-notice" onRemove={() => setError('')}>{error}</Notice>}
            {fresh && <ShareLink url={fresh.url} name={fresh.name} onDismiss={() => setFresh(null)} />}
            {loaded && !shares.length && <p>{__('No shared reports yet. Use Share on any tab, or New shared report.', 'seoprostats')}</p>}
            {shares.length > 0 && (
                <div className="spst-table-scroll">
                    <table className="widefat striped spst-shares__table">
                        <thead>
                            <tr>
                                <th scope="col">{__('Report', 'seoprostats')}</th>
                                <th scope="col">{__('Created', 'seoprostats')}</th>
                                <th scope="col">{__('Expires', 'seoprostats')}</th>
                                <th scope="col">{__('Last opened', 'seoprostats')}</th>
                                <th scope="col">{__('Opens', 'seoprostats')}</th>
                                <th scope="col"><span className="screen-reader-text">{__('Actions', 'seoprostats')}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {shares.map((share) => (
                                <tr key={share.id}>
                                    <td>
                                        <strong>{share.name}</strong>
                                        {share.revoked && <span className="spst-badge">{__('Link stopped', 'seoprostats')}</span>}
                                        {share.protected && <span className="spst-badge">{__('Password', 'seoprostats')}</span>}
                                        <div className="spst-muted">{share.views.map(sectionLabel).join(', ')}</div>
                                    </td>
                                    <td>{when(share.created)}</td>
                                    <td>{share.expires ? when(share.expires) : __('Never', 'seoprostats')}</td>
                                    <td>{when(share.last_opened)}</td>
                                    <td>{share.opens}</td>
                                    <td>
                                        <span className="spst-actions">
                                            <Button variant="secondary" size="small" onClick={() => setEditing(share)}>
                                                {__('Edit', 'seoprostats')}<span className="screen-reader-text"> {share.name}</span>
                                            </Button>
                                            <Button variant="secondary" size="small" onClick={() => void act(share, true)}>
                                                {__('Make a new link', 'seoprostats')}<span className="screen-reader-text"> {share.name}</span>
                                            </Button>
                                            {!share.revoked && (
                                                <Button variant="tertiary" size="small" isDestructive onClick={() => void act(share, false)}>
                                                    {__('Stop the link', 'seoprostats')}<span className="screen-reader-text"> {share.name}</span>
                                                </Button>
                                            )}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {editing && (
                <ShareEditor
                    state={state}
                    share={editing === 'new' ? undefined : editing}
                    close={() => setEditing(null)}
                    saved={(share) => {
                        if (share.url) {
                            setFresh({ url: share.url, name: share.name });
                        }
                        void reload();
                    }}
                />
            )}
        </section>
    );
}
