<?php
/**
 * Settings → Import: bring another statistics plugin's history across
 * (SEOProStats_Migrate). Lists each plugin whose data is on the site, its
 * days, the imports so far with their check against the plugin's own
 * counts, the next steps, and removing what the plugin left behind. The
 * screen is drawn here from SEOProStats_Migrate::status(); the dry run,
 * import (with its progress), undo and cleanup call the REST routes
 * (/migrate, /imports) through admin/js/seoprostats-import.js.
 *
 * Not drawn with the other settings tabs: it reads other plugins' tables
 * (detection, cached for ten minutes), so it loads only when opened.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Import_Tab {

    /** Tab slug. */
    const TAB = 'import';

    /** The tab's script. */
    const JS_FILE = 'admin/js/seoprostats-import.js';

    /**
     * Register the tab and its script (admin requests only).
     */
    public static function init() {
        add_filter('seoprostats_admin_tabs', array(__CLASS__, 'tabs'));
        add_action('seoprostats_admin_enqueue', array(__CLASS__, 'enqueue'));
    }

    /**
     * Add the tab after Connections (or Data).
     *
     * @param array<string,array<string,mixed>> $tabs Tabs.
     * @return array<string,array<string,mixed>>
     */
    public static function tabs($tabs) {
        $tab   = array(
            'label'      => __('Import', 'seoprostats'),
            'group'      => 'settings',
            'capability' => 'manage_options',
            'render'     => array(__CLASS__, 'render'),
        );
        $after = isset($tabs['connections']) ? 'connections' : 'data';
        $out   = array();
        foreach ((array) $tabs as $slug => $value) {
            $out[$slug] = $value;
            if ($slug === $after) {
                $out[self::TAB] = $tab;
            }
        }
        if (!isset($out[self::TAB])) {
            $out[self::TAB] = $tab;
        }
        return $out;
    }

    /**
     * Enqueue the tab's script when the tab is drawn.
     *
     * @param string $tab A tab drawn on the page.
     */
    public static function enqueue($tab) {
        if ($tab !== self::TAB) {
            return;
        }
        $version = file_exists(SEOPROSTATS_DIR . self::JS_FILE) ? (string) filemtime(SEOPROSTATS_DIR . self::JS_FILE) : SEOPROSTATS_VERSION;
        wp_enqueue_script('seoprostats-import', SEOPROSTATS_URL . self::JS_FILE, array('seoprostats-admin', 'wp-api-fetch', 'wp-i18n', 'wp-a11y'), $version, true);
        wp_set_script_translations('seoprostats-import', 'seoprostats');
        wp_add_inline_style('seoprostats-admin', '.spst-import{padding:16px 20px;min-width:0}.spst-import h3{margin:0 0 4px;font-size:14px}.spst-import h4{margin:16px 0 4px;font-size:13px}.spst-import .form-table th{width:180px;padding-block:8px}.spst-import .form-table td{padding-block:8px}.spst-import__actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}.spst-import__message:empty,.spst-import__plan:empty{display:none}.spst-import__plan{margin-top:12px;border-top:1px solid #dcdcde;padding-top:4px}.spst-import__scroll{position:relative;margin-top:8px;overflow-x:auto}.spst-import__scroll table{max-width:760px}.spst-import__steps{margin:8px 0 0 1.5em}.spst-import__steps li{margin-bottom:8px;max-width:72em}.spst-import progress{width:100%;max-width:480px;height:16px}.spst-import fieldset{margin-top:16px}.spst-import fieldset legend{font-weight:600}.spst-import fieldset label{display:block;margin:4px 0}.spst-import code{overflow-wrap:anywhere}.spst-import ul.spst-import__list{list-style:disc;margin:4px 0 0 1.5em}.spst-import td.num,.spst-import th.num{text-align:end;white-space:nowrap}.spst-import__scroll table.spst-import__settings{max-width:960px}.spst-import__settings th,.spst-import__settings td{vertical-align:top}.spst-import__settings thead th{white-space:nowrap}.spst-import__settings td.spst-import__setting{min-width:14em}.spst-import__setting label,.spst-import__setting strong{display:block;font-weight:600}.spst-import__setting .description{display:block;margin-top:2px}.spst-import__settings tr.is-kept td{color:var(--spst-muted,#646970)}.spst-import__scroll table.spst-import__history{max-width:none}.spst-import__history td{vertical-align:top}.spst-import__history .button{margin-top:4px}.spst-import__file{overflow-wrap:anywhere;font-weight:600}');
    }

    /**
     * Draw the tab.
     */
    public static function render() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-migrate.php';
        $status  = SEOProStats_Migrate::status();
        $sources = (array) $status['sources'];
        $job     = (array) $status['job'];
        $names   = array();
        foreach (SEOProStats_Migrate::sources() as $class) {
            $names[] = $class::NAME;
        }
        ?>
        <div class="spst-section" data-spst-import-root data-spst-job="<?php echo esc_attr((string) $job['status']); ?>">
            <div class="spst-section__intro">
                <h2 class="spst-section__title"><?php esc_html_e('Import', 'seoprostats'); ?></h2>
                <p class="spst-section__desc"><?php esc_html_e('Bring the history of another statistics plugin across, so the charts start before SEO Pro Stats was installed. Each day comes from one place only: SEO Pro Stats\'s own days are never replaced, and a day another import filled is skipped. Every import can be undone. The other plugin\'s data is only read; once it is switched off, you can remove what it left behind.', 'seoprostats'); ?></p>
            </div>
            <div class="spst-import__message" data-spst-message role="alert"></div>
            <?php if ($job['status'] === 'running') : ?>
                <?php self::render_job($job); ?>
            <?php endif; ?>
            <div class="spst-cards">
                <?php self::render_links(); ?>
                <?php
                foreach ($sources as $source) {
                    self::render_source($source, $status);
                }
                if (!$sources) {
                    self::render_none($names, $status['own_from']);
                }
                if ($status['imports']) {
                    self::render_imports($status['imports'], !empty($status['can']['import']));
                }
                ?>
            </div>
        </div>
        <?php
    }

    /** Upload an export without creating a public media-library attachment. */
    private static function render_links() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-backlinks-import.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-backlinks-history.php';
        $job = SEOProStats_Backlinks_Import::status();
        ?>
        <section class="spst-card spst-import" data-spst-links>
            <h3><?php esc_html_e('Links', 'seoprostats'); ?></h3>
            <p><?php esc_html_e('Import a CSV from Search Console, Ahrefs, Semrush, Majestic, Moz or Bing, or a generic source URL / target URL export. Up to 100,000 rows and 50 MB. It carries on in the background; exports never mark missing links lost.', 'seoprostats'); ?></p>
            <p class="description">
                <?php
                echo esc_html(
                    SEOProStats_Statistics::backlinks()
                        ? __('Search Console names the page linking, not the page it links to: each page an export names is opened to read its links to this site, a page every few seconds, starting a minute after the import. Until then it is listed under Search → Backlinks → Reported.', 'seoprostats')
                        : __('Checking pages for links is off (Settings → Data → Check pages that send visitors for links), so the pages an export names stay unchecked under Search → Backlinks → Reported, and exports without the page linked to add no links.', 'seoprostats')
                );
                ?>
            </p>
            <form data-spst-links-form>
                <label for="spst-links-file"><?php esc_html_e('Links CSV', 'seoprostats'); ?></label>
                <input id="spst-links-file" type="file" accept=".csv,text/csv" required>
                <label for="spst-links-source"><?php esc_html_e('Export source', 'seoprostats'); ?></label>
                <select id="spst-links-source">
                    <option value=""><?php esc_html_e('Detect from headers', 'seoprostats'); ?></option>
                    <?php foreach (SEOProStats_Backlinks_Import::SOURCES as $source) : ?>
                        <option value="<?php echo esc_attr($source); ?>"><?php echo esc_html($source === 'gsc' ? 'Search Console' : ucfirst($source)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button button-primary" type="submit"><?php esc_html_e('Import links', 'seoprostats'); ?></button>
            </form>
            <p data-spst-links-status role="status" data-status="<?php echo esc_attr((string) $job['status']); ?>"<?php echo $job['status'] === 'idle' ? ' hidden' : ''; ?>><?php echo esc_html(self::links_status($job)); ?></p>
            <progress data-spst-links-progress max="<?php echo esc_attr((string) max(1, $job['total'])); ?>" value="<?php echo esc_attr((string) $job['done']); ?>"<?php echo $job['status'] === 'running' ? '' : ' hidden'; ?>></progress>
            <?php self::render_links_history(SEOProStats_Backlinks_History::out()); ?>
        </section>
        <?php
    }

    /**
     * The links import's progress in words.
     *
     * @param array<string,mixed> $job SEOProStats_Backlinks_Import::status().
     * @return string
     */
    private static function links_status(array $job) {
        if ($job['status'] === 'running') {
            /* translators: 1: rows done, 2: rows in all */
            return sprintf(__('Importing: %1$s of %2$s rows.', 'seoprostats'), number_format_i18n((int) $job['done']), number_format_i18n((int) $job['total']));
        }
        if ($job['status'] === 'error') {
            return __('The last import stopped: a row could not be written. Import the file again; rows already written are kept once.', 'seoprostats');
        }
        /* translators: 1: rows kept, 2: rows skipped */
        return $job['status'] === 'done' ? sprintf(__('Last import done: %1$s rows kept, %2$s skipped.', 'seoprostats'), number_format_i18n((int) $job['accepted']), number_format_i18n((int) $job['skipped'])) : '';
    }

    /**
     * The link exports imported: when, who, the file (to download again),
     * the rows and where to see them.
     *
     * @param array<int,array<string,mixed>> $imports SEOProStats_Backlinks_History::out().
     */
    private static function render_links_history(array $imports) {
        if (!$imports) {
            return;
        }
        ?>
        <h4><?php esc_html_e('Imported', 'seoprostats'); ?></h4>
        <div class="spst-import__scroll">
            <table class="widefat striped spst-import__history">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('When', 'seoprostats'); ?></th>
                        <th scope="col"><?php esc_html_e('File', 'seoprostats'); ?></th>
                        <th scope="col"><?php esc_html_e('Source', 'seoprostats'); ?></th>
                        <th scope="col" class="num"><?php esc_html_e('Rows', 'seoprostats'); ?></th>
                        <th scope="col" class="num"><?php esc_html_e('Kept', 'seoprostats'); ?></th>
                        <th scope="col" class="num"><?php esc_html_e('Skipped', 'seoprostats'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'seoprostats'); ?></th>
                        <th scope="col"><?php esc_html_e('Results', 'seoprostats'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imports as $import) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html((string) wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), (int) strtotime((string) $import['started']))); ?>
                                <br><span class="description"><?php echo esc_html(trim((string) $import['user'] . ' · ' . self::via_label((string) $import['via']), ' ·')); ?></span>
                            </td>
                            <td>
                                <span class="spst-import__file"><?php echo esc_html((string) $import['name']); ?></span>
                                <br><span class="description"><?php echo esc_html($import['file'] ? (string) size_format((int) $import['bytes']) : __('File not kept', 'seoprostats')); ?></span>
                                <?php if ($import['file']) : ?>
                                    <br><a class="button button-small" href="<?php echo esc_url(self::links_file_url((int) $import['id'])); ?>" download="<?php echo esc_attr((string) $import['name']); ?>">
                                        <?php esc_html_e('Download', 'seoprostats'); ?>
                                        <span class="screen-reader-text"><?php echo esc_html((string) $import['name']); ?></span>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(self::links_source_label((string) $import['source'])); ?></td>
                            <td class="num"><?php echo esc_html(number_format_i18n((int) $import['total'])); ?></td>
                            <td class="num"><?php echo esc_html(number_format_i18n((int) $import['accepted'])); ?></td>
                            <td class="num"><?php echo esc_html(number_format_i18n((int) $import['skipped'])); ?></td>
                            <td><?php echo esc_html(self::links_status_label($import)); ?></td>
                            <td>
                                <?php if ((int) $import['accepted'] > 0) : ?>
                                    <a href="<?php echo esc_url(self::backlinks_url((string) $import['source'])); ?>"><?php esc_html_e('See in Search → Backlinks', 'seoprostats'); ?></a>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="description"><?php esc_html_e('The last 20 imports are listed; their files are kept for download (up to 200 MB in all), readable only by administrators here. Rows that name the same link again are merged, so results are by source, not by file.', 'seoprostats'); ?></p>
        <?php
    }

    /**
     * An import's status, in words.
     *
     * @param array<string,mixed> $import SEOProStats_Backlinks_History::out() entry.
     * @return string
     */
    private static function links_status_label(array $import) {
        if ($import['status'] === 'running') {
            /* translators: 1: rows done, 2: rows in all */
            return sprintf(__('Importing: %1$s of %2$s', 'seoprostats'), number_format_i18n((int) $import['done']), number_format_i18n((int) $import['total']));
        }
        return $import['status'] === 'done' ? __('Done', 'seoprostats') : __('Stopped', 'seoprostats');
    }

    /**
     * How an import came in, in words.
     *
     * @param string $via upload, cli or rest.
     * @return string
     */
    private static function via_label($via) {
        $labels = array(
            'upload' => __('uploaded here', 'seoprostats'),
            'cli'    => 'WP-CLI',
            'rest'   => __('REST API rows', 'seoprostats'),
        );
        return isset($labels[$via]) ? $labels[$via] : $via;
    }

    /**
     * An export source's name.
     *
     * @param string $source SEOProStats_Backlinks_Import::SOURCES.
     * @return string
     */
    private static function links_source_label($source) {
        $labels = array(
            'gsc'     => 'Search Console',
            'ahrefs'  => 'Ahrefs',
            'semrush' => 'Semrush',
            'majestic' => 'Majestic',
            'moz'     => 'Moz',
            'bing'    => 'Bing',
            'generic' => __('Source URL / target URL', 'seoprostats'),
        );
        return isset($labels[$source]) ? $labels[$source] : $source;
    }

    /**
     * Download an import's file again (the owner's REST route, with the
     * REST nonce for the signed-in person).
     *
     * @param int $id Entry id.
     * @return string Unescaped URL.
     */
    private static function links_file_url($id) {
        return add_query_arg('_wpnonce', wp_create_nonce('wp_rest'), rest_url(SEOProStats_Collection::REST_NAMESPACE . '/backlinks/imports/' . $id . '/file'));
    }

    /**
     * Search → Backlinks → Reported, of one source.
     *
     * @param string $source Export source.
     * @return string Unescaped URL.
     */
    private static function backlinks_url($source) {
        require_once SEOPROSTATS_DIR . 'includes/admin/class-seoprostats-dashboard.php';
        return admin_url('admin.php?page=' . SEOProStats_Dashboard::SLUG) . '#/search?report=backlinks&backlinks=reported' . ($source !== '' ? '&found=' . rawurlencode($source) : '');
    }

    /**
     * The running import, with its progress (the script polls it).
     *
     * @param array<string,mixed> $job SEOProStats_Migrate::job().
     */
    private static function render_job(array $job) {
        list($done, $total) = self::progress($job);
        ?>
        <section class="spst-card spst-import" data-spst-progress>
            <h3><?php esc_html_e('Importing…', 'seoprostats'); ?></h3>
            <progress max="<?php echo esc_attr((string) max(1, $total)); ?>" value="<?php echo esc_attr((string) $done); ?>"></progress>
            <p class="description" data-spst-progress-text aria-live="polite">
                <?php
                /* translators: 1: days done, 2: days in all */
                echo esc_html(sprintf(__('%1$d of %2$d days. It carries on in the background; you can leave this page.', 'seoprostats'), $done, $total));
                ?>
            </p>
        </section>
        <?php
    }

    /**
     * Days done and in all, across the job's queue.
     *
     * @param array<string,mixed> $job SEOProStats_Migrate::job().
     * @return array{0:int,1:int}
     */
    public static function progress(array $job) {
        $done  = 0;
        $total = 0;
        foreach (isset($job['queue']) ? (array) $job['queue'] : array() as $item) {
            $done  += (int) $item['done'];
            $total += (int) $item['total'];
        }
        return array($done, $total);
    }

    /**
     * No plugin's data was found.
     *
     * @param string[] $names    Plugins SEO Pro Stats can import from.
     * @param string   $own_from SEO Pro Stats's own first day.
     */
    private static function render_none(array $names, $own_from) {
        ?>
        <section class="spst-card spst-import" data-spst-source="">
            <h3><?php esc_html_e('No other statistics found', 'seoprostats'); ?></h3>
            <p>
                <?php
                /* translators: %s: list of plugin names */
                echo esc_html(sprintf(__('SEO Pro Stats looked for the data of: %s. None is on this site, active or not.', 'seoprostats'), implode(', ', $names)));
                ?>
            </p>
            <?php if ($own_from !== '') : ?>
                <p class="description">
                    <?php
                    /* translators: %s: a day */
                    echo esc_html(sprintf(__('SEO Pro Stats\'s own statistics start on %s.', 'seoprostats'), self::day($own_from)));
                    ?>
                </p>
            <?php endif; ?>
            <div class="spst-import__actions">
                <button type="button" class="button" data-spst-action="refresh"><?php esc_html_e('Look again', 'seoprostats'); ?></button>
            </div>
        </section>
        <?php
    }

    /**
     * One plugin's card: its data, the dry run and import, the next steps
     * after an import, and removing its leftovers.
     *
     * @param array<string,mixed> $source SEOProStats_Migrate::found() entry.
     * @param array<string,mixed> $status SEOProStats_Migrate::status().
     */
    private static function render_source(array $source, array $status) {
        $key      = (string) $source['key'];
        $state    = (string) $source['plugin']['state'];
        $done     = array_filter((array) $status['imports'], static function ($import) use ($key) {
            return $import['source'] === $key && $import['status'] === 'done' && $import['rows'] > 0;
        });
        $running  = $status['job']['status'] === 'running';
        $can      = !empty($status['can']['import']);
        $inactive = $state === 'inactive' || $state === 'missing';
        // Why its history cannot be imported now (Jetpack: connect it first).
        $note     = isset($source['unavailable']) ? (string) $source['unavailable'] : '';
        ?>
        <section class="spst-card spst-import" id="<?php echo esc_attr('spst-import-' . $key); ?>" data-spst-source="<?php echo esc_attr($key); ?>">
            <h3>
                <?php echo esc_html((string) $source['name']); ?>
                <span class="spst-badge"><?php echo esc_html(self::state_label($state)); ?></span>
            </h3>
            <?php // A table of facts with row headers, so not role="presentation" (it would hide the headers from screen readers). ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Statistics', 'seoprostats'); ?></th>
                        <td>
                            <?php if ((string) $source['from'] !== '') : ?>
                                <?php
                                /* translators: 1: first day, 2: last day, 3: number of days */
                                echo esc_html(sprintf(_n('%1$s to %2$s: %3$s day with statistics', '%1$s to %2$s: %3$s days with statistics', (int) $source['days'], 'seoprostats'), self::day((string) $source['from']), self::day((string) $source['to']), number_format_i18n((int) $source['days'])));
                                ?>
                                <?php if ((string) $source['version'] !== '') : ?>
                                    <br><span class="description">
                                        <?php
                                        /* translators: %s: version number */
                                        echo esc_html(sprintf(__('Recorded by version %s.', 'seoprostats'), (string) $source['version']));
                                        ?>
                                    </span>
                                <?php endif; ?>
                            <?php elseif ($note === '') : ?>
                                <?php esc_html_e('None left. Only the data it left behind is still on the site.', 'seoprostats'); ?>
                            <?php endif; ?>
                            <?php if ($note !== '') : ?>
                                <div class="notice notice-warning inline"><p><?php echo esc_html($note); ?></p></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ((string) $status['own_from'] !== '') : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('SEO Pro Stats', 'seoprostats'); ?></th>
                            <td>
                                <?php
                                /* translators: %s: a day */
                                echo esc_html(sprintf(__('Own statistics from %s. Only the days before it are imported.', 'seoprostats'), self::day((string) $status['own_from'])));
                                ?>
                                <br><span class="description"><?php esc_html_e('That first day counts only from when SEO Pro Stats started, so it may look lower than the days around it.', 'seoprostats'); ?></span>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ((string) $source['from'] !== '' && $note === '' && $can) : ?>
                <div class="spst-import__actions">
                    <button type="button" class="button button-primary" data-spst-action="plan" <?php disabled($running); ?>><?php esc_html_e('Check what would be imported', 'seoprostats'); ?></button>
                    <span class="description"><?php esc_html_e('Changes nothing.', 'seoprostats'); ?></span>
                </div>
                <div class="spst-import__plan" data-spst-plan></div>
            <?php endif; ?>

            <?php if ($done) : ?>
                <?php self::render_next_steps($source, reset($done), $status); ?>
            <?php elseif ($inactive && !empty($source['leftovers']) && !empty($status['can']['cleanup'])) : ?>
                <h4><?php esc_html_e('Remove leftover data', 'seoprostats'); ?></h4>
                <?php if ((string) $source['from'] !== '') : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e('Its statistics have not been imported yet. Removing its data deletes them for good.', 'seoprostats'); ?></p></div>
                <?php endif; ?>
                <?php self::render_cleanup(); ?>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * The steps after an import: check the days, switch the plugin off and
     * delete it, then remove what it left behind.
     *
     * @param array<string,mixed> $source SEOProStats_Migrate::found() entry.
     * @param array<string,mixed> $import Its newest finished import.
     * @param array<string,mixed> $status SEOProStats_Migrate::status().
     */
    private static function render_next_steps(array $source, array $import, array $status) {
        $state     = (string) $source['plugin']['state'];
        $file      = (string) $source['plugin']['file'];
        $uninstall = $source['uninstall_setting'];
        ?>
        <h4><?php esc_html_e('Next steps', 'seoprostats'); ?></h4>
        <ol class="spst-import__steps">
            <li>
                <strong><?php esc_html_e('Check the imported days.', 'seoprostats'); ?></strong>
                <?php
                /* translators: 1: first day, 2: last day */
                echo esc_html(sprintf(__('%1$s to %2$s are in the reports, with a note on the timeline. The check against its own counts is under Imports below.', 'seoprostats'), self::day((string) $import['from']), self::day((string) $import['to'])));
                ?>
                <a href="<?php echo esc_url(self::overview_url((string) $import['from'], (string) $import['to'])); ?>"><?php esc_html_e('Open the Overview for those days', 'seoprostats'); ?></a>
            </li>
            <li>
                <strong>
                    <?php
                    /* translators: %s: plugin name */
                    echo esc_html(sprintf(__('Switch %s off and delete it.', 'seoprostats'), (string) $source['name']));
                    ?>
                </strong>
                <?php if (is_array($uninstall)) : ?>
                    <?php
                    echo esc_html(
                        $uninstall['on']
                            /* translators: 1: setting name, 2: where it is */
                            ? sprintf(__('Its "%1$s" setting (%2$s) is on, so deleting it removes its data too.', 'seoprostats'), (string) $uninstall['label'], (string) $uninstall['where'])
                            /* translators: 1: setting name, 2: where it is */
                            : sprintf(__('To have it remove its own data when deleted, first turn on "%1$s" (%2$s).', 'seoprostats'), (string) $uninstall['label'], (string) $uninstall['where'])
                    );
                    ?>
                <?php else : ?>
                    <?php esc_html_e('It has no setting to remove its data when it is deleted, so its tables stay until step 3.', 'seoprostats'); ?>
                <?php endif; ?>
                <?php self::render_plugin_action($state, $file); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Remove leftover data.', 'seoprostats'); ?></strong>
                <?php if ($state === 'active' || $state === 'network') : ?>
                    <?php esc_html_e('Once it is switched off, this removes the tables, options and files it left behind.', 'seoprostats'); ?>
                <?php elseif (empty($source['leftovers'])) : ?>
                    <?php esc_html_e('Nothing is left.', 'seoprostats'); ?>
                <?php elseif (empty($status['can']['cleanup'])) : ?>
                    <?php esc_html_e('Removing another plugin\'s data needs the right to delete plugins and to change settings.', 'seoprostats'); ?>
                <?php else : ?>
                    <?php self::render_cleanup(); ?>
                <?php endif; ?>
            </li>
        </ol>
        <?php
    }

    /**
     * Deactivate or delete links like core's, for people who can.
     *
     * @param string $state Plugin state.
     * @param string $file  Plugin file.
     */
    private static function render_plugin_action($state, $file) {
        if ($file === '') {
            return;
        }
        if ($state === 'active' && current_user_can('deactivate_plugin', $file)) {
            $url = wp_nonce_url(add_query_arg(array('action' => 'deactivate', 'plugin' => rawurlencode($file)), self_admin_url('plugins.php')), 'deactivate-plugin_' . $file);
            printf('<br><a class="button button-small" href="%1$s">%2$s</a>', esc_url($url), esc_html__('Deactivate', 'seoprostats'));
        } elseif ($state === 'network' && current_user_can('manage_network_plugins')) {
            printf('<br><a href="%1$s">%2$s</a>', esc_url(network_admin_url('plugins.php')), esc_html__('It is active for the whole network: deactivate it in Network Admin → Plugins.', 'seoprostats'));
        } elseif ($state === 'inactive' && !is_multisite() && current_user_can('delete_plugins')) {
            $url = wp_nonce_url(add_query_arg(array('action' => 'delete-selected', 'checked[]' => rawurlencode($file)), self_admin_url('plugins.php')), 'bulk-plugins');
            printf('<br><a class="button button-small" href="%1$s">%2$s</a>', esc_url($url), esc_html__('Delete', 'seoprostats'));
        }
    }

    /**
     * The button that lists, then removes, what a plugin left behind.
     */
    private static function render_cleanup() {
        ?>
        <p><?php esc_html_e('Lists exactly what it left on this site; nothing is removed until you confirm. Imported days stay.', 'seoprostats'); ?></p>
        <div class="spst-import__actions">
            <button type="button" class="button" data-spst-action="leftovers"><?php esc_html_e('List leftover data', 'seoprostats'); ?></button>
        </div>
        <div class="spst-import__plan" data-spst-leftovers></div>
        <?php
    }

    /**
     * Imports from other statistics plugins, with their check.
     *
     * @param array<int,array<string,mixed>> $imports SEOProStats_Migrate::imports().
     * @param bool                           $can     Whether the user may undo.
     */
    private static function render_imports(array $imports, $can) {
        $schema = SEOProStats_Settings::schema();
        ?>
        <section class="spst-card spst-import" data-spst-source="">
            <h3><?php esc_html_e('Imports', 'seoprostats'); ?></h3>
            <p class="description"><?php esc_html_e('Undo deletes the days an import added (settings it filled in stay). To fill the same days from another plugin, undo, then import again.', 'seoprostats'); ?></p>
            <div class="spst-import__scroll">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Import', 'seoprostats'); ?></th>
                            <th scope="col"><?php esc_html_e('Days', 'seoprostats'); ?></th>
                            <th scope="col"><?php esc_html_e('Check', 'seoprostats'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'seoprostats'); ?></th>
                            <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'seoprostats'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($imports as $import) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html((string) $import['name']); ?></strong>
                                    <?php echo esc_html((string) $import['version'] !== '' ? ' ' . $import['version'] : ''); ?>
                                    <br><span class="description">#<?php echo esc_html((string) $import['id']); ?> · <?php echo esc_html(self::ago((int) $import['started'])); ?></span>
                                </td>
                                <td>
                                    <?php
                                    if ($import['from'] !== '') {
                                        echo esc_html($import['from'] === $import['to'] ? self::day($import['from']) : self::day($import['from']) . ' – ' . self::day($import['to']));
                                    }
                                    ?>
                                    <br><span class="description">
                                        <?php
                                        /* translators: 1: number of days, 2: number of rows */
                                        echo esc_html(sprintf(__('%1$s days, %2$s rows', 'seoprostats'), number_format_i18n((int) $import['days']), number_format_i18n((int) $import['rows'])));
                                        ?>
                                    </span>
                                    <?php self::render_skipped((array) $import['skipped']); ?>
                                    <?php if ($import['status'] === 'done' && $import['rows'] > 0 && $import['from'] !== '') : ?>
                                        <br><a href="<?php echo esc_url(self::overview_url((string) $import['from'], (string) $import['to'])); ?>"><?php esc_html_e('Overview of these days', 'seoprostats'); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?php self::render_check((array) $import['check'], (string) $import['name']); ?></td>
                                <td>
                                    <?php echo esc_html(self::status_label((string) $import['status'])); ?>
                                    <?php if ((string) $import['error'] !== '') : ?>
                                        <br><?php echo esc_html((string) $import['error']); ?>
                                    <?php endif; ?>
                                    <?php if ($import['settings']) : ?>
                                        <br><span class="description"><?php esc_html_e('Settings filled in:', 'seoprostats'); ?></span>
                                        <ul class="spst-import__list">
                                            <?php foreach ((array) $import['settings'] as $key => $change) : ?>
                                                <li>
                                                    <?php
                                                    $label = isset($schema[$key]['label']) ? (string) $schema[$key]['label'] : (string) $key;
                                                    /* translators: 1: setting, 2: before, 3: after */
                                                    echo esc_html(sprintf(__('%1$s: %2$s → %3$s', 'seoprostats'), $label, (string) $change[0], (string) $change[1]));
                                                    ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($can && $import['status'] === 'done' && $import['rows'] > 0) : ?>
                                        <button type="button" class="button button-small" data-spst-action="undo" data-spst-import="<?php echo esc_attr((string) $import['id']); ?>"><?php esc_html_e('Undo', 'seoprostats'); ?></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php
    }

    /**
     * Days an import skipped, and why.
     *
     * @param array<string,mixed> $skipped Import's skipped.
     */
    private static function render_skipped(array $skipped) {
        $names = array();
        foreach (SEOProStats_Migrate::sources() as $key => $class) {
            $names[$key] = $class::NAME;
        }
        $lines = array();
        if (!empty($skipped['own'])) {
            /* translators: %s: number of days */
            $lines[] = sprintf(_n('%s day skipped: SEO Pro Stats\'s own', '%s days skipped: SEO Pro Stats\'s own', (int) $skipped['own'], 'seoprostats'), number_format_i18n((int) $skipped['own']));
        }
        foreach (isset($skipped['imported']) ? (array) $skipped['imported'] : array() as $by => $count) {
            /* translators: 1: number of days, 2: plugin name */
            $lines[] = sprintf(_n('%1$s day skipped: imported from %2$s', '%1$s days skipped: imported from %2$s', (int) $count, 'seoprostats'), number_format_i18n((int) $count), isset($names[$by]) ? $names[$by] : (string) $by);
        }
        if (!empty($skipped['empty'])) {
            /* translators: %s: number of days */
            $lines[] = sprintf(_n('%s day without visits', '%s days without visits', (int) $skipped['empty'], 'seoprostats'), number_format_i18n((int) $skipped['empty']));
        }
        if (!empty($skipped['failed'])) {
            /* translators: %s: number of days */
            $lines[] = sprintf(_n('%s day could not be written', '%s days could not be written', (int) $skipped['failed'], 'seoprostats'), number_format_i18n((int) $skipped['failed']));
        }
        foreach ($lines as $line) {
            echo '<br><span class="description">' . esc_html($line) . '</span>';
        }
    }

    /**
     * The plugin's own counts beside the imported ones.
     *
     * @param array<string,mixed> $check  Import's check: source, imported.
     * @param string              $name   Plugin name.
     */
    private static function render_check(array $check, $name) {
        if (empty($check['source']) || empty($check['imported'])) {
            echo '—';
            return;
        }
        $metrics = array(
            'pageviews' => __('Pageviews', 'seoprostats'),
            'visits'    => __('Visits', 'seoprostats'),
        );
        ?>
        <table class="spst-import__check">
            <thead>
                <tr>
                    <td></td>
                    <th scope="col" class="num"><?php echo esc_html($name); ?></th>
                    <th scope="col" class="num"><?php esc_html_e('Imported', 'seoprostats'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($metrics as $metric => $label) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($label); ?></th>
                        <td class="num"><?php echo esc_html(number_format_i18n((int) (isset($check['source'][$metric]) ? $check['source'][$metric] : 0))); ?></td>
                        <td class="num"><?php echo esc_html(number_format_i18n((int) (isset($check['imported'][$metric]) ? $check['imported'][$metric] : 0))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The Overview for some days (each section's view is in the address).
     *
     * @param string $from First day.
     * @param string $to   Last day.
     * @return string Unescaped URL.
     */
    private static function overview_url($from, $to) {
        require_once SEOPROSTATS_DIR . 'includes/admin/class-seoprostats-dashboard.php';
        return admin_url('admin.php?page=' . SEOProStats_Dashboard::SLUG) . '#/overview?range=custom&from=' . rawurlencode($from) . '&to=' . rawurlencode($to);
    }

    /**
     * A day in the site's date format.
     *
     * @param string $ymd Y-m-d.
     * @return string
     */
    private static function day($ymd) {
        $time = strtotime($ymd . ' 12:00:00 UTC');
        $text = $ymd !== '' && $time ? wp_date((string) get_option('date_format'), $time, new DateTimeZone('UTC')) : '';
        return is_string($text) && $text !== '' ? $text : $ymd;
    }

    /**
     * Time since, in words.
     *
     * @param int $time Unix time.
     * @return string
     */
    private static function ago($time) {
        /* translators: %s: time span such as "5 mins" */
        return $time ? sprintf(__('%s ago', 'seoprostats'), human_time_diff($time)) : __('never', 'seoprostats');
    }

    /**
     * A plugin's state, in words.
     *
     * @param string $state active, network, inactive or missing.
     * @return string
     */
    private static function state_label($state) {
        $labels = array(
            'active'   => __('Active', 'seoprostats'),
            'network'  => __('Active on the network', 'seoprostats'),
            'inactive' => __('Inactive', 'seoprostats'),
            'missing'  => __('Deleted, data left', 'seoprostats'),
        );
        return isset($labels[$state]) ? $labels[$state] : $state;
    }

    /**
     * An import status, in words.
     *
     * @param string $status running, done, failed or undone.
     * @return string
     */
    private static function status_label($status) {
        $labels = array(
            'running' => __('Running', 'seoprostats'),
            'done'    => __('Done', 'seoprostats'),
            'failed'  => __('Failed', 'seoprostats'),
            'undone'  => __('Undone', 'seoprostats'),
        );
        return isset($labels[$status]) ? $labels[$status] : $status;
    }
}
