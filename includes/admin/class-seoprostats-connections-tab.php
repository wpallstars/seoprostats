<?php
/**
 * Settings → Connections: connect outside data sources (Search Console
 * first), see how far each import is, import now, undo an import, and
 * disconnect. The screen is drawn here from each source's status; its
 * buttons call the REST routes (SEOProStats_API, /connections) through
 * admin/js/seoprostats-connections.js. Credentials are never printed.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Connections_Tab {

    /** Tab slug. */
    const TAB = 'connections';

    /** The tab's script. */
    const JS_FILE = 'admin/js/seoprostats-connections.js';

    /**
     * Google's pages for the setup steps, as Google's own documentation
     * links them (the Search Console API's page is its API library page).
     */
    const LINKS = array(
        'project'   => 'https://console.cloud.google.com/projectcreate',
        'api'       => 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com',
        'accounts'  => 'https://console.cloud.google.com/iam-admin/serviceaccounts',
        'keys_help' => 'https://docs.cloud.google.com/iam/docs/creating-managing-service-account-keys',
        'users'     => 'https://search.google.com/search-console/users',
        'user_help' => 'https://support.google.com/webmasters/answer/7687615',
    );

    /**
     * Register the tab and its script (admin requests only).
     */
    public static function init() {
        add_filter('seoprostats_admin_tabs', array(__CLASS__, 'tabs'));
        add_action('seoprostats_admin_enqueue', array(__CLASS__, 'enqueue'));
    }

    /**
     * Add the tab after Data.
     *
     * @param array<string,array<string,mixed>> $tabs Tabs.
     * @return array<string,array<string,mixed>>
     */
    public static function tabs($tabs) {
        $tab = array(
            'label'      => __('Connections', 'seoprostats'),
            'group'      => 'settings',
            'capability' => 'manage_options',
            'render'     => array(__CLASS__, 'render'),
        );
        $out = array();
        foreach ((array) $tabs as $slug => $value) {
            $out[$slug] = $value;
            if ($slug === 'data') {
                $out[self::TAB] = $tab;
            }
        }
        if (!isset($out[self::TAB])) {
            $out[self::TAB] = $tab;
        }
        return $out;
    }

    /**
     * Enqueue the tab's script on the tab only.
     *
     * @param string $tab Active tab.
     */
    public static function enqueue($tab) {
        if ($tab !== self::TAB) {
            return;
        }
        $version = file_exists(SEOPROSTATS_DIR . self::JS_FILE) ? (string) filemtime(SEOPROSTATS_DIR . self::JS_FILE) : SEOPROSTATS_VERSION;
        wp_enqueue_script('seoprostats-connections', SEOPROSTATS_URL . self::JS_FILE, array('seoprostats-admin', 'wp-api-fetch', 'wp-i18n', 'wp-a11y'), $version, true);
        wp_set_script_translations('seoprostats-connections', 'seoprostats');
        wp_add_inline_style('seoprostats-admin', '.spst-connection{padding:16px 20px;min-width:0}.spst-connection h3{margin:0 0 4px;font-size:14px}.spst-connection__steps{margin:8px 0 12px 1.5em}.spst-connection__steps li{margin-bottom:8px;max-width:72em}.spst-connection textarea{width:100%;font-family:monospace;font-size:12px}.spst-connection .form-table th{width:180px;padding-block:8px}.spst-connection .form-table td{padding-block:8px}.spst-connection__actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}.spst-connection__message:empty{display:none}.spst-connection code{overflow-wrap:anywhere}.spst-connection__scroll{margin-top:12px;overflow-x:auto}.spst-connection details{margin-top:12px}');
    }

    /**
     * Draw the tab.
     */
    public static function render() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-connections.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
        // Puts back the hourly job if it went missing (a cron reset).
        SEOProStats_Search_Import::schedule();
        ?>
        <div class="spst-section">
            <div class="spst-section__intro">
                <h2 class="spst-section__title"><?php esc_html_e('Connections', 'seoprostats'); ?></h2>
                <p class="spst-section__desc"><?php esc_html_e('Add data from services you use to the statistics. Each stays off until you connect it. The site then asks the service from WP-Cron, never while a visitor loads a page, and stores what it gets in its own tables. Keys are stored encrypted and never shown again.', 'seoprostats'); ?></p>
            </div>
            <div class="spst-cards">
                <?php
                foreach (SEOProStats_Connections::statuses() as $status) {
                    self::render_source($status);
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Draw one source's card.
     *
     * @param array<string,mixed> $status SEOProStats_Connections::status().
     */
    private static function render_source(array $status) {
        $source = (string) $status['source'];
        $id     = 'spst-connection-' . $source;
        ?>
        <section class="spst-card spst-connection" id="<?php echo esc_attr($id); ?>" data-spst-connection="<?php echo esc_attr($source); ?>">
            <h3>
                <?php echo esc_html((string) $status['name']); ?>
                <?php if (!empty($status['connected'])) : ?>
                    <span class="spst-badge spst-badge--success"><?php esc_html_e('Connected', 'seoprostats'); ?></span>
                <?php endif; ?>
            </h3>
            <div class="spst-connection__message" data-spst-message role="alert"></div>
            <?php
            if (empty($status['connected'])) {
                self::render_connect($source, $id);
            } else {
                self::render_connected($status, $id);
            }
            ?>
        </section>
        <?php
    }

    /**
     * The form to connect Search Console.
     *
     * @param string $source Source key.
     * @param string $id     Card id.
     */
    private static function render_connect($source, $id) {
        unset($source);
        ?>
        <p class="spst-setting__desc"><?php esc_html_e('Clicks, impressions and average position for each page and search query, by day, so search and visits sit on one timeline. On connecting, the 16 months Search Console keeps are imported; after that each day is added once Search Console marks it final, about three days later.', 'seoprostats'); ?></p>
        <p><?php esc_html_e('Set it up once, signed in to Google with the account that owns this site in Search Console. Each link opens in a new tab.', 'seoprostats'); ?></p>
        <ol class="spst-connection__steps">
            <?php
            foreach (self::steps() as $step) {
                printf(
                    '<li><strong>%1$s</strong> %2$s</li>',
                    esc_html($step[0]),
                    wp_kses($step[1], array(
                        'a'    => array('href' => true, 'target' => true, 'rel' => true),
                        'span' => array('class' => true),
                    ))
                );
            }
            ?>
        </ol>
        <p class="description"><?php esc_html_e('If Google says key creation is turned off by an organisation policy, make the project outside your organisation (Location: No organization), or ask its administrator to allow keys for that project.', 'seoprostats'); ?></p>
        <p>
            <label for="<?php echo esc_attr($id . '-key'); ?>"><strong><?php esc_html_e('Service account key (JSON)', 'seoprostats'); ?></strong></label>
            <textarea id="<?php echo esc_attr($id . '-key'); ?>" rows="6" data-spst-field="key" autocomplete="off" spellcheck="false" placeholder="{&quot;type&quot;: &quot;service_account&quot;, …}"></textarea>
        </p>
        <p>
            <label for="<?php echo esc_attr($id . '-property'); ?>"><strong><?php esc_html_e('Property', 'seoprostats'); ?></strong></label><br>
            <input type="text" class="regular-text" id="<?php echo esc_attr($id . '-property'); ?>" data-spst-field="property" autocomplete="off" placeholder="<?php esc_attr_e('Found from the site\'s address', 'seoprostats'); ?>">
            <span class="description"><?php esc_html_e('Leave empty to use the property for this site; a domain property (sc-domain:) is chosen before an address one.', 'seoprostats'); ?></span>
        </p>
        <div class="spst-connection__actions">
            <button type="button" class="button button-primary" data-spst-action="connect"><?php esc_html_e('Connect', 'seoprostats'); ?></button>
        </div>
        <?php
    }

    /**
     * The setup steps: a title and the step, with its links (HTML made
     * here; translations hold only plain text and %s for the links).
     *
     * @return array<int,array{0:string,1:string}>
     */
    private static function steps() {
        $link = static function ($key, $text) {
            return sprintf(
                '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
                esc_url(self::LINKS[$key]),
                esc_html($text),
                esc_html__('(opens in a new tab)', 'seoprostats')
            );
        };
        $text = static function ($format, ...$links) {
            return vsprintf(esc_html($format), $links);
        };
        return array(
            array(
                __('Make a Google Cloud project.', 'seoprostats'),
                /* translators: %s: link "Create a project" */
                $text(__('%s, give it any name (such as Site statistics) and choose Create. A project you already have works too.', 'seoprostats'), $link('project', __('Create a project', 'seoprostats'))),
            ),
            array(
                __('Turn on the Search Console API.', 'seoprostats'),
                /* translators: %s: link "Open the Google Search Console API" */
                $text(__('%s, check that your project is chosen at the top of the page, and choose Enable.', 'seoprostats'), $link('api', __('Open the Google Search Console API', 'seoprostats'))),
            ),
            array(
                __('Make a service account.', 'seoprostats'),
                /* translators: %s: link "Open Service accounts" */
                $text(__('%s → Create service account. Give it a name (such as seo-pro-stats), choose Create and continue, then Done: it needs no roles or access, so skip those two steps. Copy its email address, which ends in iam.gserviceaccount.com.', 'seoprostats'), $link('accounts', __('Open Service accounts', 'seoprostats'))),
            ),
            array(
                __('Download its key.', 'seoprostats'),
                /* translators: %s: link "About keys" */
                $text(__('In the list of service accounts, choose its email address → Keys → Add key → Create new key → JSON → Create. Your browser downloads a .json file; keep it private (%s).', 'seoprostats'), $link('keys_help', __('About keys', 'seoprostats'))),
            ),
            array(
                __('Let it read this site in Search Console.', 'seoprostats'),
                /* translators: 1: link "Open Users and permissions", 2: link "Help" */
                $text(__('%1$s, choose this site\'s property at the top left, then Add user. Paste the service account\'s email address, choose Restricted permission (it only reads) and Add (%2$s).', 'seoprostats'), $link('users', __('Open Users and permissions', 'seoprostats')), $link('user_help', __('Help', 'seoprostats'))),
            ),
            array(
                __('Connect.', 'seoprostats'),
                $text(__('Open the downloaded key file in a text editor, copy all of it, paste it below and choose Connect. The key is stored encrypted, so you can delete the file; if you ever need it again, make a new key.', 'seoprostats')),
            ),
        );
    }

    /**
     * A connected source: its state, imports and actions.
     *
     * @param array<string,mixed> $status SEOProStats_Connections::status().
     * @param string              $id     Card id.
     */
    private static function render_connected(array $status, $id) {
        $imported = $status['imported'];
        $format   = get_option('date_format');
        $day      = static function ($ymd) use ($format) {
            $time = strtotime($ymd . ' 12:00:00 UTC');
            $text = $ymd !== '' && $time ? wp_date($format, $time, new DateTimeZone('UTC')) : '';
            return is_string($text) ? $text : $ymd;
        };
        $ago      = static function ($time) {
            /* translators: %s: time span such as "5 mins" */
            return $time ? sprintf(__('%s ago', 'seoprostats'), human_time_diff((int) $time)) : __('never', 'seoprostats');
        };
        if ($imported['from'] !== '') {
            /* translators: 1: first day, 2: last day */
            $range = sprintf(__('%1$s to %2$s', 'seoprostats'), $day($imported['from']), $day($imported['to']));
        } else {
            $range = __('Nothing yet: the first import starts within a minute or two.', 'seoprostats');
        }
        if ($imported['complete']) {
            $history = __('All of Search Console\'s history is in.', 'seoprostats');
        } else {
            /* translators: 1: days imported, 2: days in all */
            $history = sprintf(__('%1$d of %2$d days, newest first; it carries on in the background a minute apart.', 'seoprostats'), $imported['days'], $imported['of']);
        }
        ?>
        <?php if ((string) $status['error'] !== '') : ?>
            <div class="notice notice-error inline">
                <p>
                    <?php
                    /* translators: 1: time since, 2: error message */
                    echo esc_html(sprintf(__('The last import failed (%1$s): %2$s', 'seoprostats'), $ago($status['error_at']), (string) $status['error']));
                    ?>
                </p>
            </div>
        <?php endif; ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Service account', 'seoprostats'); ?></th>
                    <td><code><?php echo esc_html((string) $status['account']); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Property', 'seoprostats'); ?></th>
                    <td><code><?php echo esc_html((string) $status['property']); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Imported', 'seoprostats'); ?></th>
                    <td><?php echo esc_html($range); ?><br><span class="description"><?php echo esc_html($history); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Last import', 'seoprostats'); ?></th>
                    <td>
                        <?php echo esc_html($ago($status['last_run'])); ?>
                        <?php if ($status['next_run']) : ?>
                            <br><span class="description">
                                <?php
                                /* translators: %s: time span such as "5 mins" */
                                echo esc_html(sprintf(__('Next check in %s.', 'seoprostats'), human_time_diff((int) $status['next_run'])));
                                ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="spst-connection__actions">
            <button type="button" class="button button-primary" data-spst-action="import"><?php esc_html_e('Import now', 'seoprostats'); ?></button>
        </div>

        <?php if ($status['imports']) : ?>
            <div class="spst-connection__scroll">
            <table class="widefat striped spst-connection__imports">
                <caption class="screen-reader-text"><?php esc_html_e('Recent imports', 'seoprostats'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Import', 'seoprostats'); ?></th>
                        <th scope="col"><?php esc_html_e('Days', 'seoprostats'); ?></th>
                        <th scope="col"><?php esc_html_e('Rows', 'seoprostats'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'seoprostats'); ?></th>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'seoprostats'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($status['imports'] as $import) : ?>
                        <tr>
                            <td>#<?php echo esc_html((string) $import['id']); ?> · <?php echo esc_html($ago($import['started'])); ?></td>
                            <td>
                                <?php
                                echo esc_html($import['from'] === $import['to'] ? $day($import['from']) : $day($import['from']) . ' – ' . $day($import['to']));
                                ?>
                            </td>
                            <td><?php echo esc_html(number_format_i18n((int) $import['rows'])); ?></td>
                            <td><?php echo esc_html(self::status_label($import['status'])); ?><?php echo $import['error'] !== '' ? ': ' . esc_html($import['error']) : ''; ?></td>
                            <td>
                                <?php if ($import['status'] === 'done' && $import['rows'] > 0) : ?>
                                    <button type="button" class="button button-small" data-spst-action="undo" data-spst-import="<?php echo esc_attr((string) $import['id']); ?>"><?php esc_html_e('Undo', 'seoprostats'); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>

        <details>
            <summary><?php esc_html_e('Change the property or key', 'seoprostats'); ?></summary>
            <p>
                <label for="<?php echo esc_attr($id . '-property'); ?>"><strong><?php esc_html_e('Property', 'seoprostats'); ?></strong></label><br>
                <input type="text" class="regular-text" id="<?php echo esc_attr($id . '-property'); ?>" data-spst-field="property" autocomplete="off" value="<?php echo esc_attr((string) $status['property']); ?>">
                <span class="description"><?php esc_html_e('Another property starts the import again from the beginning.', 'seoprostats'); ?></span>
            </p>
            <p>
                <label for="<?php echo esc_attr($id . '-key'); ?>"><strong><?php esc_html_e('New service account key (JSON)', 'seoprostats'); ?></strong></label>
                <textarea id="<?php echo esc_attr($id . '-key'); ?>" rows="4" data-spst-field="key" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Leave empty to keep the saved key.', 'seoprostats'); ?>"></textarea>
            </p>
            <div class="spst-connection__actions">
                <button type="button" class="button" data-spst-action="connect"><?php esc_html_e('Save', 'seoprostats'); ?></button>
            </div>
        </details>

        <details>
            <summary><?php esc_html_e('Disconnect', 'seoprostats'); ?></summary>
            <p><?php esc_html_e('Forgets the key: nothing more is imported. The search data already imported stays, unless you delete it too.', 'seoprostats'); ?></p>
            <p>
                <label>
                    <input type="checkbox" data-spst-field="delete_data">
                    <?php esc_html_e('Also delete the imported search data', 'seoprostats'); ?>
                </label>
            </p>
            <div class="spst-connection__actions">
                <button type="button" class="button button-link-delete" data-spst-action="disconnect"><?php esc_html_e('Disconnect', 'seoprostats'); ?></button>
            </div>
        </details>
        <?php
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
