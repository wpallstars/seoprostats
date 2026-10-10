<?php
/**
 * Backlinks (Search → Backlinks): pages of other sites that link to the
 * site's pages, from the site's own data, with no outside service.
 *
 * The daily cron (and WP-CLI) finds the pages that sent visits: visits
 * of the Referral channel (sessions by `started`, from where the last run
 * stopped), each referring page once; browsers often send only the other
 * site's address, so its home page is checked then. It opens each page
 * with wp_safe_remote_get() (public addresses only, TIMEOUT seconds, at
 * most MAX_BYTES, a user agent naming the plugin and the site), reads its
 * `<a href>` links to the site's hosts with their text and rel, and keeps
 * them in the links table: one row per referring page (path_id 0, its
 * checks) and one per page of the site it links to. Within BUDGET seconds
 * a run, never on a visitor page, only while the setting is on.
 *
 * Pages are checked again weekly (RECHECK), oldest first; a page that
 * showed no link is opened again only after it sends another visit. A link
 * missing on MISSES checks in a row, or on a page gone (HTTP 404 or 410),
 * is lost; failed requests count for nothing. New and lost links become
 * changes (backlink_new, backlink_lost; one per referring site and day),
 * so they line up with traffic on the timeline.
 *
 * Reads: live links by status_first (newest first), lost ones by lost,
 * the referring pages by path_checked, and each referring site's visits
 * from the daily source summaries by their dim_val_day key. Layout:
 * docs/architecture.md → Backlinks.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Backlinks {

    /** Lists of the report: reported is the referring pages link exports named, checked or not. */
    const KINDS = array('links', 'domains', 'pages', 'lost', 'reported');

    /** A link's status: on its page at the last check, or lost. */
    const LINK_LIVE = 1;
    const LINK_LOST = 2;

    /** A referring page's status: no link to the site at the last check (or not checked yet), links, or gone (404, 410). */
    const PAGE_NONE  = 0;
    const PAGE_LINKS = 1;
    const PAGE_GONE  = 2;

    /** rel values kept: name => bit. */
    const REL = array(
        'nofollow'  => 1,
        'sponsored' => 2,
        'ugc'       => 4,
    );

    /** How a link was found: name => bit. Bits never change meaning. */
    const FOUND = array(
        'referrer'   => 1,
        'dataforseo' => 2,
        'gsc'       => 4,
        'ahrefs'    => 8,
        'semrush'   => 16,
        'majestic'  => 32,
        'moz'       => 64,
        'bing'      => 128,
        'generic'   => 256,
        'verified'  => 512,
    );

    /** The FOUND bits of outside lists (DataForSEO and every export): dataforseo to generic. */
    const EXPORTS = 510;

    /** Cron hook: the catch-up check, every CATCH_UP seconds while pages an export named wait for their first check. */
    const CHECK_HOOK = 'seoprostats_backlinks_check';
    const CATCH_UP   = 60;

    /** Progress, per data set (autoload off): upto, last, pages, checked, errors, version. */
    const OPTION = 'seoprostats_backlinks';

    /** Seconds a run may take, a request's timeout, and the most bytes read of a page. */
    const BUDGET    = 20;
    const TIMEOUT   = 5;
    const MAX_BYTES = 1048576;

    /** A page is checked again after this long; links missing on this many checks in a row are lost. */
    const RECHECK = WEEK_IN_SECONDS;
    const MISSES  = 2;

    /** How far back the first run looks for referrers, and the referrers read per day of visits at most. */
    const LOOKBACK = 30 * DAY_IN_SECONDS;
    const MAX_REFERRERS = 2000;

    /** Links to the site's pages kept per referring page, and the longest anchor text kept (characters). */
    const MAX_LINKS  = 50;
    const MAX_ANCHOR = 100;

    /** Pages read per query of due pages; rows read per list at most; ids per query. */
    const BATCH    = 20;
    const MAX_ROWS = 5000;
    const CHUNK    = 500;

    /** Rows listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 500;

    // ------------------------------------------------------------------
    // The check.

    /**
     * Daily cron (and WP-CLI): find new referring pages, then check the
     * pages due within the time budget. Live data only, while the
     * setting is on (or $force).
     *
     * @param int  $budget Seconds.
     * @param bool $force  Run though the setting is off (WP-CLI).
     * @param bool $all    Open every referring page now, not only those due (WP-CLI --all).
     * @return array<string,mixed> state() after the run, with this run's counts (new_pages, checked, links_new, links_lost, errors, skipped, more).
     */
    public static function run($budget = self::BUDGET, $force = false, $all = false) {
        $out = array('new_pages' => 0, 'checked' => 0, 'links_new' => 0, 'links_lost' => 0, 'errors' => 0, 'skipped' => 0, 'more' => false);
        if (!self::may_run($force)) {
            return self::state() + $out;
        }
        self::load();
        $start = microtime(true);
        $out['new_pages'] = self::collect($start, $budget);
        $events = array('new' => array(), 'lost' => array());
        self::check_due($all, $start, $budget, $out, $events);
        $out['links_new']  = self::count_events($events['new']);
        $out['links_lost'] = self::count_events($events['lost']);
        self::changes($events);
        self::save_run($out);
        return self::state() + $out;
    }

    /**
     * run() under a five-minute lease, so the daily run, the catch-up and
     * Check now never open the same pages at once; then the catch-up is
     * scheduled while pages an export named wait for their first check.
     *
     * @param int  $budget Seconds.
     * @param bool $force  Run though the setting is off (Check now, WP-CLI).
     * @param bool $all    Open every referring page now (WP-CLI --all).
     * @return array<string,mixed>|null run()'s answer, or null while another run holds the lease.
     */
    public static function run_locked($budget = self::BUDGET, $force = false, $all = false) {
        $lock = SEOProStats_Schema::option(self::OPTION . '_lock');
        $held = (int) get_option($lock, 0);
        if ($held && $held < time() - 300) {
            delete_option($lock);
        }
        if (!add_option($lock, time(), '', false)) {
            return null;
        }
        try {
            $done = self::run($budget, $force, $all);
        } finally {
            delete_option($lock);
        }
        self::schedule_catch_up();
        return $done;
    }

    /**
     * Cron (CHECK_HOOK): one more run while pages an export named wait for
     * their first check; it schedules the next itself.
     */
    public static function catch_up() {
        if (self::run_locked() === null) {
            // Another run holds the lease: try again after it.
            self::schedule_catch_up();
        }
    }

    /**
     * Schedule the catch-up a minute from now while the check is on and
     * pages an export named wait for their first check (live data only).
     *
     * @return bool Whether it is scheduled.
     */
    public static function schedule_catch_up() {
        if (wp_next_scheduled(self::CHECK_HOOK)) {
            return true;
        }
        if (!self::may_run(false) || !self::waiting()) {
            return false;
        }
        return (bool) wp_schedule_single_event(time() + self::CATCH_UP, self::CHECK_HOOK);
    }

    /**
     * Pages an export named that were never opened, by the path_checked key.
     *
     * @return int
     */
    public static function waiting() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, the path_id = 0, checked = 0 range of its path_checked key.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE path_id = 0 AND checked = 0 AND found & %d', SEOProStats_Schema::table('links'), self::EXPORTS));
    }

    /**
     * Whether the check may run: live data in the current schema, with the
     * setting on (or $force).
     *
     * @param bool $force Run though the setting is off (WP-CLI).
     * @return bool
     */
    private static function may_run($force) {
        return SEOProStats_Schema::set() === 'live' && SEOProStats_Schema::is_current() && ($force || SEOProStats_Statistics::backlinks());
    }

    /**
     * Check the due referring pages, batch by batch, until none is left or
     * the time is up (checked before each page).
     *
     * @param bool                                                    $all    Every referring page now, not only those due.
     * @param float                                                   $start  microtime(true) when the run began.
     * @param int                                                     $budget Seconds.
     * @param array<string,mixed>                                     $out    The run's counts; added to.
     * @param array{new:array<string,array>,lost:array<string,array>} $events New and lost links by referring host; added to.
     */
    private static function check_due($all, $start, $budget, array &$out, array &$events) {
        $due   = $all ? time() : time() - self::RECHECK;
        $pages = self::due($due);
        while ($pages) {
            foreach ($pages as $page) {
                if (!SEOProStats_Feature::more_time($start, $budget)) {
                    $out['more'] = true;
                    return;
                }
                self::check_page($page, $all, $out, $events);
            }
            $pages = self::due($due);
        }
    }

    /**
     * Check one due referring page, or skip it when it is not worth
     * opening yet.
     *
     * @param array<string,string>                                    $page   Its row.
     * @param bool                                                    $all    Every referring page now (no skipping).
     * @param array<string,mixed>                                     $out    The run's counts; added to.
     * @param array{new:array<string,array>,lost:array<string,array>} $events New and lost links by referring host; added to.
     */
    private static function check_page(array $page, $all, array &$out, array &$events) {
        if (!$all && self::quiet($page)) {
            self::touch((int) $page['id']);
            ++$out['skipped'];
            return;
        }
        $done = self::check($page, $events);
        ++$out['checked'];
        if ($done === 'error') {
            ++$out['errors'];
        }
    }

    /**
     * No link at the last check and no visit since: not worth opening yet.
     *
     * @param array<string,string> $page Its row.
     * @return bool
     */
    private static function quiet(array $page) {
        return ((int) $page['found'] & ~self::FOUND['referrer']) === 0 && (int) $page['checked'] > 0 && (int) $page['status'] === self::PAGE_NONE && (int) $page['last_seen'] <= (int) $page['checked'];
    }

    /**
     * Keep the run's progress and counts.
     *
     * @param array<string,mixed> $out The run's counts.
     */
    private static function save_run(array $out) {
        $state = self::state();
        update_option(SEOProStats_Schema::option(self::OPTION), array(
            'upto'    => $state['upto'],
            'last'    => time(),
            'checked' => $out['checked'],
            'errors'  => $out['errors'],
            'version' => time(),
        ), false);
    }

    /**
     * Add the pages that sent visits since the last run (day by day,
     * within the budget) to the links table as referring pages.
     *
     * @param float $start  microtime(true) when the run began.
     * @param int   $budget Seconds.
     * @return int Referring pages read (new and known).
     */
    private static function collect($start, $budget) {
        global $wpdb;
        $state = self::state();
        $now   = time();
        $from  = $state['upto'] ? $state['upto'] : $now - self::LOOKBACK;
        $read  = 0;
        while ($from < $now && SEOProStats_Feature::more_time($start, $budget)) {
            $to = min($now, $from + DAY_IN_SECONDS);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, a range of its started key; no sort (ORDER BY NULL).
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT ref_host_id, ref_path_id, MIN(started) AS first, MAX(started) AS last FROM %i WHERE started > %d AND started <= %d AND channel = %d AND ref_host_id > 0 GROUP BY ref_host_id, ref_path_id ORDER BY NULL LIMIT %d', SEOProStats_Schema::table('sessions'), $from, $to, SEOProStats_Channels::REFERRAL, self::MAX_REFERRERS), ARRAY_A);
            $read += self::add_referrers($rows);
            $from  = $to;
            self::save_upto($from);
        }
        return $read;
    }

    /**
     * Keep referring pages: new ones are added, known ones get their
     * newest visit.
     *
     * @param array<int,array<string,string>> $rows ref_host_id, ref_path_id, first, last.
     * @return int Pages kept.
     */
    private static function add_referrers(array $rows) {
        if (!$rows) {
            return 0;
        }
        $pages = self::referrer_pages($rows);
        if (!$pages) {
            return 0;
        }
        $urls  = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_URL, array_keys($pages));
        $hosts = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_HOST, array_column($pages, 'host'));
        $args  = array();
        foreach ($pages as $url => $page) {
            $url_id  = self::dict_id($urls, $url);
            $host_id = isset($hosts[$page['host']]) ? (int) $hosts[$page['host']] : 0;
            if (!$url_id) {
                continue;
            }
            $args[] = array(self::key($url, 0), $host_id, $url_id, self::FOUND['referrer'], self::PAGE_NONE, $page['first'], $page['last']);
        }
        self::insert_referrers($args);
        return count($args);
    }

    /**
     * Visits' referrers as referring pages: address => host and the first
     * and last visit. Hosts that are not domains are left out.
     *
     * @param array<int,array<string,string>> $rows ref_host_id, ref_path_id, first, last.
     * @return array<string,array{host:string,first:int,last:int}>
     */
    private static function referrer_pages(array $rows) {
        // $wpdb gives the ids as strings.
        $texts = SEOProStats_Dict::values(array_map('intval', array_merge(array_column($rows, 'ref_host_id'), array_column($rows, 'ref_path_id'))));
        $pages = array();
        foreach ($rows as $row) {
            $host = isset($texts[(int) $row['ref_host_id']]) ? strtolower($texts[(int) $row['ref_host_id']]) : '';
            $path = (int) $row['ref_path_id'] && isset($texts[(int) $row['ref_path_id']]) ? $texts[(int) $row['ref_path_id']] : '/';
            $url  = self::page_url($host, $path);
            if ($url === '') {
                continue;
            }
            $first = isset($pages[$url]) ? min($pages[$url]['first'], (int) $row['first']) : (int) $row['first'];
            $last  = isset($pages[$url]) ? max($pages[$url]['last'], (int) $row['last']) : (int) $row['last'];
            $pages[$url] = array('host' => $host, 'first' => $first, 'last' => $last);
        }
        return $pages;
    }

    /**
     * Insert referring pages' rows, or add the newest visit to known ones.
     *
     * @param array<int,array<int,int|string>> $args Per page: lkey (hex), source_host_id, source_url_id, found, status, first_seen, last_seen.
     */
    private static function insert_referrers(array $args) {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its unique key; $groups holds only placeholder groups.
        foreach (array_chunk($args, self::CHUNK) as $chunk) {
            $groups = implode(', ', array_fill(0, count($chunk), '(UNHEX(%s), %d, %d, 0, %d, %d, %d, %d)'));
            $wpdb->query($wpdb->prepare("INSERT INTO %i (lkey, source_host_id, source_url_id, path_id, found, status, first_seen, last_seen) VALUES $groups ON DUPLICATE KEY UPDATE last_seen = GREATEST(last_seen, VALUES(last_seen)), found = found | VALUES(found)", array_merge(array(SEOProStats_Schema::table('links')), array_merge(...$chunk))));
        }
        // phpcs:enable
    }

    /**
     * Referring pages checked before a time, oldest first, by the
     * path_checked key.
     *
     * @param int $before Unix seconds.
     * @return array<int,array<string,string>>
     */
    private static function due($before) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its path_checked key in its order.
        return (array) $wpdb->get_results($wpdb->prepare('SELECT id, source_host_id, source_url_id, status, checked, first_seen, last_seen, found, providers FROM %i WHERE path_id = 0 AND checked < %d ORDER BY checked LIMIT %d', SEOProStats_Schema::table('links'), (int) $before, self::BATCH), ARRAY_A);
    }

    /**
     * Mark a referring page checked now without opening it.
     *
     * @param int $id Row.
     */
    private static function touch($id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key.
        $wpdb->update(SEOProStats_Schema::table('links'), array('checked' => time()), array('id' => (int) $id), array('%d'), array('%d'));
    }

    /**
     * Open one referring page and keep its links to the site.
     *
     * @param array<string,string>                                  $page   Its row (id, source_host_id, source_url_id, status).
     * @param array{new:array<string,array>,lost:array<string,array>} $events New and lost links by referring host; added to.
     * @return string ok, gone or error.
     */
    private static function check(array $page, array &$events) {
        global $wpdb;
        $table = SEOProStats_Schema::table('links');
        $texts = SEOProStats_Dict::values(array((int) $page['source_url_id'], (int) $page['source_host_id']));
        $url   = isset($texts[(int) $page['source_url_id']]) ? $texts[(int) $page['source_url_id']] : '';
        $host  = isset($texts[(int) $page['source_host_id']]) ? $texts[(int) $page['source_host_id']] : (string) wp_parse_url($url, PHP_URL_HOST);
        $now   = time();
        $redirects = array();
        $got   = $url !== '' ? self::fetch($url, $redirects) : new WP_Error('seoprostats_backlinks_url', 'No address.');
        if (is_wp_error($got)) {
            // A failed request says nothing about the links; a referring
            // page's misses count its failed opens in a row.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key.
            $wpdb->query($wpdb->prepare('UPDATE %i SET checked = %d, misses = LEAST(misses + 1, 255) WHERE id = %d', $table, $now, (int) $page['id']));
            return 'error';
        }
        $by   = self::known_links($table, (int) $page['source_url_id']);
        $gone = $got === 'gone';
        $seen = array();
        if (!$gone) {
            $seen = self::keep_page($page, $url, $host, (string) $got, $by, $now, $events);
            self::save_facts((int) $page['source_url_id'], (string) $got, $redirects);
        }
        // Links not found now: a miss, lost after MISSES in a row or when the page is gone.
        $gone_paths = self::miss_links($table, $by, $seen, $gone, $now);
        if ($gone_paths) {
            self::lost_events($by, $gone_paths, $url, $host, $events);
        }
        $status = $seen ? self::PAGE_LINKS : self::PAGE_NONE;
        if ($gone) {
            $status = self::PAGE_GONE;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key.
        $wpdb->update($table, array('status' => $status, 'checked' => $now, 'misses' => 0), array('id' => (int) $page['id']), array('%d', '%d', '%d'), array('%d'));
        return $gone ? 'gone' : 'ok';
    }

    /**
     * The links of a referring page known before this check, by linked
     * page.
     *
     * @param string $table  The links table.
     * @param int    $url_id The referring page.
     * @return array<int,array<string,string>> path_id => id, path_id, anchor_id, status, misses, first_seen.
     */
    private static function known_links($table, $url_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its source_url key.
        $known = (array) $wpdb->get_results($wpdb->prepare('SELECT id, path_id, anchor_id, status, misses, first_seen FROM %i WHERE source_url_id = %d AND path_id > 0', $table, $url_id), ARRAY_A);
        $by    = array();
        foreach ($known as $row) {
            $by[(int) $row['path_id']] = $row;
        }
        return $by;
    }

    /**
     * Keep the links an opened referring page has to the site.
     *
     * @param array<string,string>                                    $page   Its row.
     * @param string                                                  $url    Its address.
     * @param string                                                  $host   Its host.
     * @param string                                                  $html   What it answered.
     * @param array<int,array<string,string>>                         $by     Its links known before, by linked page.
     * @param int                                                     $now    Unix seconds.
     * @param array{new:array<string,array>,lost:array<string,array>} $events New and lost links by referring host; added to.
     * @return array<int,bool> Linked pages found now.
     */
    private static function keep_page(array $page, $url, $host, $html, array $by, $now, array &$events) {
        $links = self::parse($html);
        return $links ? self::keep_links($page, $url, $host, $links, $by, $now, $events) : array();
    }

    /**
     * Save the page facts of an opened referring page on each of its links.
     *
     * @param int               $url_id    The referring page.
     * @param string            $html      What it answered.
     * @param array<string,int> $redirects Observed redirect count.
     * @return void
     */
    private static function save_facts($url_id, $html, array $redirects) {
        global $wpdb;
        require_once __DIR__ . '/class-seoprostats-backlink-review.php';
        $facts = wp_json_encode(SEOProStats_Backlink_Review::page_facts($html) + $redirects);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, indexed source_url read/write; no new requests.
        $wpdb->query($wpdb->prepare('UPDATE %i SET facts = %s WHERE source_url_id = %d', SEOProStats_Schema::table('links'), (string) $facts, $url_id));
    }

    /**
     * Write the links found on a referring page, live as of now.
     *
     * @param array<string,string>                                    $page   Its row.
     * @param string                                                  $url    Its address.
     * @param string                                                  $host   Its host.
     * @param array<string,array{anchor:string,rel:int}>              $links  From parse().
     * @param array<int,array<string,string>>                         $by     Its links known before, by linked page.
     * @param int                                                     $now    Unix seconds.
     * @param array{new:array<string,array>,lost:array<string,array>} $events New and lost links by referring host; added to.
     * @return array<int,bool> Linked pages found now.
     */
    private static function keep_links(array $page, $url, $host, array $links, array $by, $now, array &$events) {
        $ids     = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array_keys($links));
        $anchors = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_LABEL, array_column($links, 'anchor'));
        $found   = self::page_found($page);
        $since   = self::discovered($page, $now);
        $seen    = array();
        foreach ($links as $path => $link) {
            $path_id = self::dict_id($ids, $path);
            if (!$path_id) {
                continue;
            }
            $seen[$path_id] = true;
            $was            = isset($by[$path_id]) ? $by[$path_id] : null;
            self::keep_link($page, $url, $path_id, self::dict_id($anchors, $link['anchor']), (int) $link['rel'], $found, $now, $since ? $since : $now);
            // A link an export reported was already there: found, not new.
            if (!$since && (!$was || (int) $was['status'] !== self::LINK_LIVE)) {
                $events['new'][$host][] = array('from' => $url, 'to' => $path, 'anchor' => $link['anchor']);
            }
        }
        return $seen;
    }

    /**
     * When the links found on the first check of a page an export named
     * were there already: the export's dates (first seen, else last seen),
     * else now. 0 for a page checked before or known only from visits,
     * whose links found now are new.
     *
     * @param array<string,string> $page Its row (checked, found, first_seen, last_seen).
     * @param int                  $now  Unix seconds.
     * @return int Unix seconds, or 0.
     */
    private static function discovered(array $page, $now) {
        if ((int) $page['checked'] > 0 || !((int) $page['found'] & self::EXPORTS)) {
            return 0;
        }
        $since = (int) $page['first_seen'] > 0 ? (int) $page['first_seen'] : (int) $page['last_seen'];
        return $since > 0 && $since <= $now ? $since : $now;
    }

    /**
     * The found bits of the links of a checked referring page: verified,
     * the visits' bit, and the bits of source-only exports that led to it.
     *
     * @param array<string,string> $page Its row.
     * @return int
     */
    private static function page_found(array $page) {
        $found     = self::FOUND['verified'] | ((int) $page['found'] & self::FOUND['referrer']);
        $providers = json_decode((string) $page['providers'], true);
        if (!is_array($providers)) {
            return $found;
        }
        // A source-only export led us to this page; target-specific exports
        // retain their bits on the exact link, never on every link of its page.
        foreach ($providers as $source => $provider) {
            if (!empty($provider['candidate']) && isset(self::FOUND[$source])) {
                $found |= self::FOUND[$source];
            }
        }
        return $found;
    }

    /**
     * Write one link found now: new, back again, or still there.
     *
     * @param array<string,string> $page      The referring page's row.
     * @param string               $url       Its address.
     * @param int                  $path_id   Linked page.
     * @param int                  $anchor_id Link text.
     * @param int                  $rel       REL bits.
     * @param int                  $found     FOUND bits.
     * @param int                  $now       Unix seconds.
     * @param int                  $first     First seen, when new (now, or an export's date).
     */
    private static function keep_link(array $page, $url, $path_id, $anchor_id, $rel, $found, $now, $first) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its unique key.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (lkey, source_host_id, source_url_id, path_id, anchor_id, rel, found, status, first_seen, last_seen, lost, checked, misses) VALUES (UNHEX(%s), %d, %d, %d, %d, %d, %d, %d, %d, %d, 0, %d, 0) ON DUPLICATE KEY UPDATE anchor_id = VALUES(anchor_id), rel = VALUES(rel), found = found | VALUES(found), first_seen = IF(status = %d, first_seen, VALUES(first_seen)), status = VALUES(status), last_seen = VALUES(last_seen), lost = 0, checked = VALUES(checked), misses = 0',
            SEOProStats_Schema::table('links'),
            self::key($url, $path_id),
            (int) $page['source_host_id'],
            (int) $page['source_url_id'],
            $path_id,
            $anchor_id,
            $rel,
            $found,
            self::LINK_LIVE,
            $first,
            $now,
            $now,
            self::LINK_LIVE
        ));
    }

    /**
     * Count a miss for each live link not found now; lost after MISSES in
     * a row, or at once when the page is gone.
     *
     * @param string                          $table The links table.
     * @param array<int,array<string,string>> $by    The page's links known before, by linked page.
     * @param array<int,bool>                 $seen  Linked pages found now.
     * @param bool                            $gone  The page is gone.
     * @param int                             $now   Unix seconds.
     * @return int[] Linked pages lost now.
     */
    private static function miss_links($table, array $by, array $seen, $gone, $now) {
        global $wpdb;
        $gone_paths = array();
        foreach ($by as $path_id => $row) {
            if (isset($seen[$path_id]) || (int) $row['status'] !== self::LINK_LIVE) {
                continue;
            }
            $misses = (int) $row['misses'] + 1;
            $lost   = $gone || $misses >= self::MISSES;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key.
            $wpdb->update($table, array('misses' => min(255, $misses), 'checked' => $now, 'status' => $lost ? self::LINK_LOST : self::LINK_LIVE, 'lost' => $lost ? $now : 0), array('id' => (int) $row['id']), array('%d', '%d', '%d', '%d'), array('%d'));
            if ($lost) {
                $gone_paths[] = $path_id;
            }
        }
        return $gone_paths;
    }

    /**
     * Add the links lost now to the run's events, with the text they had,
     * for the timeline.
     *
     * @param array<int,array<string,string>>                         $by         The page's links known before, by linked page.
     * @param int[]                                                   $gone_paths Linked pages lost now.
     * @param string                                                  $url        The referring page.
     * @param string                                                  $host       Its host.
     * @param array{new:array<string,array>,lost:array<string,array>} $events     New and lost links by referring host; added to.
     */
    private static function lost_events(array $by, array $gone_paths, $url, $host, array &$events) {
        $texts = SEOProStats_Dict::values(array_merge($gone_paths, array_map(static function ($path_id) use ($by) {
            return (int) $by[$path_id]['anchor_id'];
        }, $gone_paths)));
        foreach ($gone_paths as $path_id) {
            $anchor_id = (int) $by[$path_id]['anchor_id'];
            $events['lost'][$host][] = array(
                'from'   => $url,
                'to'     => isset($texts[$path_id]) ? $texts[$path_id] : '',
                'anchor' => isset($texts[$anchor_id]) ? $texts[$anchor_id] : '',
            );
        }
    }

    /**
     * GET a referring page: its HTML ('' when it is not HTML), 'gone' for
     * HTTP 404 or 410, or an error. Public addresses only, no cookies.
     *
     * @param string $url Address.
     * @param array<string,int> $facts Observed redirect count, when the HTTP transport provides it.
     * @return string|WP_Error
     */
    private static function fetch($url, array &$facts) {
        $response = wp_safe_remote_get($url, array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_BYTES,
            'user-agent'          => self::user_agent(),
            'headers'             => array('Accept' => 'text/html, application/xhtml+xml;q=0.9, */*;q=0.1'),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        if (isset($response['http_response']) && $response['http_response'] instanceof WP_HTTP_Requests_Response) {
            $facts['redirects'] = count($response['http_response']->get_response_object()->history);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404 || $code === 410) {
            return 'gone';
        }
        if ($code < 200 || $code >= 300) {
            /* translators: %d: HTTP status code */
            return new WP_Error('seoprostats_backlinks_http', sprintf(__('The page answered with HTTP status %d.', 'seoprostats'), $code));
        }
        // A header sent more than once comes as a list: the last one counts.
        $type = wp_remote_retrieve_header($response, 'content-type');
        $type = strtolower(is_array($type) ? (string) end($type) : $type);
        if ($type !== '' && strpos($type, 'html') === false) {
            return '';
        }
        return (string) wp_remote_retrieve_body($response);
    }

    /**
     * The user agent: the plugin and the site, so the other site's owner
     * knows who looked.
     *
     * @return string
     */
    public static function user_agent() {
        return 'SEO Pro Stats/' . SEOPROSTATS_VERSION . ' (WordPress plugin; backlink check for ' . home_url('/') . ')';
    }

    /**
     * The links of a page of another site to the site's pages: path =>
     * the first link's text and rel bits, at most MAX_LINKS pages. Only
     * absolute links (http, https, or //host) to one of the site's hosts.
     *
     * @param string $html Page.
     * @return array<string,array{anchor:string,rel:int}>
     */
    public static function parse($html) {
        $out = array();
        if (!preg_match_all('/<a\s[^>]*>(.*?)<\/a>/is', (string) $html, $matches, PREG_SET_ORDER)) {
            return $out;
        }
        $site = SEOProStats_Collection::hosts();
        foreach ($matches as $match) {
            $tags = new WP_HTML_Tag_Processor($match[0]);
            if (!$tags->next_tag(array('tag_name' => 'a'))) {
                continue;
            }
            $path = self::site_path($tags->get_attribute('href'), $site);
            if ($path === '' || isset($out[$path])) {
                continue;
            }
            if (count($out) >= self::MAX_LINKS) {
                break;
            }
            $bits       = self::rel_bits($tags->get_attribute('rel'));
            $out[$path] = array('anchor' => self::anchor($match[1]), 'rel' => $bits);
        }
        return $out;
    }

    /**
     * The site's page a link goes to: '' unless the link is absolute
     * (http, https, or //host) to one of the site's hosts and is a page.
     *
     * @param string|bool|null $href The href attribute.
     * @param string[]         $site The site's hosts.
     * @return string
     */
    private static function site_path($href, array $site) {
        $href = is_string($href) ? trim(html_entity_decode($href, ENT_QUOTES)) : '';
        if (strpos($href, '//') === 0) {
            $href = 'https:' . $href;
        }
        $scheme = strtolower((string) wp_parse_url($href, PHP_URL_SCHEME));
        $host   = strtolower((string) wp_parse_url($href, PHP_URL_HOST));
        if (!in_array($scheme, array('http', 'https'), true) || $host === '' || !in_array($host, $site, true)) {
            return '';
        }
        return SEOProStats_Links::target(SEOProStats_Changes::path(explode('#', $href, 2)[0]));
    }

    /**
     * REL bits of a rel attribute's words.
     *
     * @param string|bool|null $rel The rel attribute.
     * @return int
     */
    private static function rel_bits($rel) {
        $bits  = 0;
        $words = preg_split('/\s+/', strtolower(is_string($rel) ? $rel : ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words ? $words : array() as $word) {
            $bits |= isset(self::REL[$word]) ? self::REL[$word] : 0;
        }
        return $bits;
    }

    /**
     * A link's text: its words, else an image's alt text in brackets.
     *
     * @param string $inner The link's inner HTML.
     * @return string
     */
    private static function anchor($inner) {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) $inner), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '' && preg_match('/<img\s[^>]*>/i', (string) $inner, $img)) {
            $tags = new WP_HTML_Tag_Processor($img[0]);
            $alt  = $tags->next_tag() ? $tags->get_attribute('alt') : null;
            $text = is_string($alt) && trim($alt) !== '' ? '[' . trim($alt) . ']' : '[image]';
        }
        return function_exists('mb_substr') ? (string) mb_substr($text, 0, self::MAX_ANCHOR, 'UTF-8') : substr($text, 0, self::MAX_ANCHOR);
    }

    /**
     * Write the run's new and lost links as changes: one per referring
     * site, kind and day (added to when the day already has one).
     *
     * @param array{new:array<string,array>,lost:array<string,array>} $events Links by referring host.
     */
    private static function changes(array $events) {
        $today = (new DateTimeImmutable('today', wp_timezone()))->getTimestamp();
        $table = SEOProStats_Schema::table('changes');
        foreach (array('new' => SEOProStats_Changes::BACKLINK_NEW, 'lost' => SEOProStats_Changes::BACKLINK_LOST) as $which => $kind) {
            if (!empty($events[$which])) {
                self::kind_changes($events[$which], $kind, $today, $table);
            }
        }
    }

    /**
     * Write one kind of the run's links as changes, one per referring site.
     *
     * @param array<string,array> $by_host Links by referring host.
     * @param int                 $kind    SEOProStats_Changes::BACKLINK_NEW or BACKLINK_LOST.
     * @param int                 $today   Unix seconds at the start of today.
     * @param string              $table   The changes table.
     */
    private static function kind_changes(array $by_host, $kind, $today, $table) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its kind_ts key.
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT id, new, meta FROM %i WHERE kind = %d AND ts >= %d', $table, $kind, $today), ARRAY_A);
        $known = array();
        foreach ($rows as $row) {
            $known[(string) $row['new']] = $row;
        }
        foreach ($by_host as $host => $links) {
            $host = (string) $host;
            self::host_change($host, $links, isset($known[$host]) ? $known[$host] : null, $kind, $table);
        }
    }

    /**
     * Write a referring site's links as a change, or add them to today's.
     *
     * @param string                    $host  Referring host.
     * @param array<int,array>          $links Its links (from, to, anchor).
     * @param array<string,string>|null $known Today's change of this kind for the host (id, new, meta), if any.
     * @param int                       $kind  SEOProStats_Changes::BACKLINK_NEW or BACKLINK_LOST.
     * @param string                    $table The changes table.
     */
    private static function host_change($host, array $links, $known, $kind, $table) {
        global $wpdb;
        $count = count($links);
        if ($known !== null) {
            list($count, $links) = self::merge_change($known, $links);
        }
        $paths = array_values(array_unique(array_column($links, 'to')));
        $meta  = array('host' => $host, 'count' => $count, 'links' => array_slice($links, 0, SEOProStats_Changes::MAX_LIST));
        if ($known !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key.
            $wpdb->update($table, array('meta' => (string) wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), array('id' => (int) $known['id']), array('%s'), array('%d'));
            return;
        }
        SEOProStats_Changes::write(array(
            'ts'          => time(),
            'kind'        => $kind,
            'path'        => count($paths) === 1 ? (string) $paths[0] : '',
            'object_type' => 'backlink',
            'object_id'   => 0,
            'old'         => '',
            'new'         => $host,
            'meta'        => $meta,
            'source'      => SEOProStats_Changes::source(),
            'user_id'     => 0,
        ));
    }

    /**
     * Add links to today's change: the same link again the same day (lost,
     * back, lost) replaces its entry and is not counted twice.
     *
     * @param array<string,string> $known Today's change (id, new, meta).
     * @param array<int,array>     $links Links (from, to, anchor).
     * @return array{0:int,1:array<int,array>} The count and the links.
     */
    private static function merge_change(array $known, array $links) {
        $meta   = json_decode((string) $known['meta'], true);
        $meta   = is_array($meta) ? $meta : array();
        $merged = array();
        foreach (isset($meta['links']) && is_array($meta['links']) ? $meta['links'] : array() as $link) {
            if (is_array($link) && isset($link['from'], $link['to'])) {
                $merged[$link['from'] . ' ' . $link['to']] = $link;
            }
        }
        $count = isset($meta['count']) ? (int) $meta['count'] : count($merged);
        foreach ($links as $link) {
            $count += isset($merged[$link['from'] . ' ' . $link['to']]) ? 0 : 1;
            $merged[$link['from'] . ' ' . $link['to']] = $link;
        }
        return array($count, array_values($merged));
    }

    /**
     * Links in a run's events.
     *
     * @param array<string,array> $by_host Links by referring host.
     * @return int
     */
    private static function count_events(array $by_host) {
        return (int) array_sum(array_map('count', $by_host));
    }

    // ------------------------------------------------------------------
    // The report.

    /**
     * The backlinks report: one of KINDS, with the totals.
     *
     * @param array<string,mixed> $req  From SEOProStats_Query::request() (range, limit, offset).
     * @param string              $kind One of KINDS.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $kind = 'links') {
        self::load();
        $kind = $kind === '' ? 'links' : (string) $kind;
        if (!in_array($kind, self::KINDS, true)) {
            /* translators: %s: list of kinds */
            return new WP_Error('seoprostats_backlinks_kind', sprintf(__('The kind is one of: %s.', 'seoprostats'), implode(', ', self::KINDS)), array('status' => 400));
        }
        $source = isset($req['source']) ? (string) $req['source'] : '';
        if ($source !== '' && !isset(self::FOUND[$source])) {
            return new WP_Error('seoprostats_backlinks_source', __('Unknown backlink source.', 'seoprostats'), array('status' => 400));
        }
        $range = SEOProStats_Query::range($req);
        $key   = array(
            'from'    => $range['from'],
            'to'      => $range['to'],
            'version' => self::state()['version'],
            'source'  => $source,
            // The answer's shape: a cached answer from before reported pages' targets is not reused.
            'shape'   => 2,
        );
        $all    = SEOProStats_Query::cached('backlinks', $key, static function () use ($range, $source) {
            return self::build($range, $source);
        });
        $limit  = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $list   = (array) $all['lists'][$kind];
        unset($all['lists']);
        return $all + array(
            'kind'  => $kind,
            'rows'  => array_slice($list, $offset, $limit),
            'total' => count($list),
            'more'  => $offset + $limit < count($list),
        );
    }

    /**
     * The shared part of the answer, with every list (cached).
     *
     * @param array<string,mixed> $range From SEOProStats_Query::range().
     * @param string $source Optional source, applied within the bounded report window.
     * @return array<string,mixed>
     */
    private static function build(array $range, $source = '') {
        global $wpdb;
        $table = SEOProStats_Schema::table('links');
        $from  = (int) $range['from'];
        $to    = (int) $range['to'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its status_first key in its order.
        $live = (array) $wpdb->get_results($wpdb->prepare('SELECT source_host_id, source_url_id, path_id, anchor_id, rel, found, first_seen, last_seen, authority, providers, facts FROM %i FORCE INDEX (`status_first`) WHERE status = %d ORDER BY first_seen DESC LIMIT %d', $table, self::LINK_LIVE, self::MAX_ROWS), ARRAY_A);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, a range of its lost key in its order.
        $lost  = (array) $wpdb->get_results($wpdb->prepare('SELECT source_host_id, source_url_id, path_id, anchor_id, rel, found, first_seen, last_seen, lost, providers, facts FROM %i FORCE INDEX (`lost`) WHERE lost >= %d AND lost < %d ORDER BY lost DESC LIMIT %d', $table, $from, $to, self::MAX_ROWS), ARRAY_A);
        $live  = self::of_source($live, $source);
        $lost  = self::of_source($lost, $source);
        $texts = self::row_texts(array_merge($live, $lost));
        $links = array();
        foreach ($live as $row) {
            $links[] = self::link_out($row, $texts, $from, $to);
        }
        $lost_rows = array();
        foreach ($lost as $row) {
            $lost_rows[] = self::link_out($row, $texts, $from, $to) + array('lost' => self::date_out($row['lost']));
        }
        // Referring sites and linked pages, from the live links.
        $sites     = self::sites($live, $links, $texts);
        $pages     = self::linked_pages($live, $links, $texts);
        $domains   = self::domains($sites, $lost, $range);
        $targets   = self::targets($pages);
        require_once __DIR__ . '/class-seoprostats-backlinks-reported.php';
        $reported  = SEOProStats_Backlinks_Reported::read($live, $links, $source);
        $new_sites = 0;
        foreach ($sites as $site) {
            $new_sites += $site['first'] >= $from && $site['first'] < $to ? 1 : 0;
        }
        return array(
            'range'  => SEOProStats_Query::range_out($range),
            'totals' => array(
                'domains'     => count($sites),
                'links'       => count($live),
                'pages'       => count($pages),
                'new'         => count(array_filter($links, static function ($l) {
                    return $l['new'];
                })),
                'new_domains' => $new_sites,
                'lost'        => count($lost),
                // Referring pages link exports named, their sites, and how many were opened.
                'reported'         => count($reported['rows']),
                'reported_domains' => $reported['domains'],
                'reported_checked' => $reported['checked'],
            ),
            'read'   => self::coverage(),
            'rules'  => array(
                'recheck_days' => (int) (self::RECHECK / DAY_IN_SECONDS),
                'misses'       => self::MISSES,
                'budget'       => self::BUDGET,
                'max_rows'     => self::MAX_ROWS,
            ),
            'lists'  => array(
                'links'   => $links,
                'domains' => $domains,
                'pages'    => $targets,
                'lost'     => $lost_rows,
                'reported' => $reported['rows'],
            ),
        );
    }

    /**
     * Link rows (not referring pages' own rows), of one source when given.
     *
     * @param array<int,array<string,string>> $rows   Rows of the links table.
     * @param string                          $source One of FOUND, or '' for all.
     * @return array<int,array<string,string>>
     */
    private static function of_source(array $rows, $source) {
        return array_values(array_filter($rows, static function ($row) use ($source) {
            return (int) $row['path_id'] > 0 && ($source === '' || ((int) $row['found'] & self::FOUND[$source]));
        }));
    }

    /**
     * The texts of link rows' hosts, addresses, pages and link texts.
     *
     * @param array<int,array<string,string>> $rows Rows of the links table.
     * @return array<int,string> Id => text.
     */
    private static function row_texts(array $rows) {
        $ids = array();
        foreach ($rows as $row) {
            array_push($ids, (int) $row['source_host_id'], (int) $row['source_url_id'], (int) $row['path_id'], (int) $row['anchor_id']);
        }
        return SEOProStats_Dict::values($ids);
    }

    /**
     * A text by its id, '' when unknown.
     *
     * @param array<int,string> $texts Id => text.
     * @param int|string        $id    Id.
     * @return string
     */
    private static function text_of(array $texts, $id) {
        return isset($texts[(int) $id]) ? (string) $texts[(int) $id] : '';
    }

    /**
     * A time for the answer (ISO 8601 in the site's time zone), null for 0.
     *
     * @param int|string $ts Unix seconds.
     * @return string|null
     */
    private static function date_out($ts) {
        return (int) $ts ? (string) wp_date('c', (int) $ts) : null;
    }

    /**
     * A link row as the report gives it.
     *
     * @param array<string,string> $row   Row of the links table.
     * @param array<int,string>    $texts Id => text.
     * @param int                  $from  Range start, Unix seconds.
     * @param int                  $to    Range end, Unix seconds.
     * @return array<string,mixed>
     */
    private static function link_out(array $row, array $texts, $from, $to) {
        $providers = json_decode(isset($row['providers']) ? (string) $row['providers'] : '', true);
        $providers = is_array($providers) ? $providers : array();
        foreach ($providers as &$provider) {
            $provider['last_seen'] = self::date_out($provider['last_seen']);
        }
        unset($provider);
        return array(
            'source'     => self::text_of($texts, $row['source_url_id']),
            'host'       => self::text_of($texts, $row['source_host_id']),
            'page'       => self::text_of($texts, $row['path_id']),
            'anchor'     => self::text_of($texts, $row['anchor_id']),
            'rel'        => self::names(self::REL, (int) $row['rel']),
            'found'      => self::names(self::FOUND, (int) $row['found']),
            'first_seen' => self::date_out($row['first_seen']),
            'last_seen'  => self::date_out($row['last_seen']),
            'new'        => (int) $row['first_seen'] >= $from && (int) $row['first_seen'] < $to,
            'authority'  => isset($row['authority']) ? (int) $row['authority'] : 0,
            'providers'  => (object) $providers,
            'facts'      => (object) (json_decode(isset($row['facts']) ? (string) $row['facts'] : '', true) ?: array()),
        );
    }

    /**
     * Referring sites of the live links, by host id.
     *
     * @param array<int,array<string,string>> $live  Live link rows.
     * @param array<int,array<string,mixed>>  $links The same links from link_out().
     * @param array<int,string>               $texts Id => text.
     * @return array<int,array<string,mixed>>
     */
    private static function sites(array $live, array $links, array $texts) {
        $sites = array();
        foreach ($live as $i => $row) {
            $host = (int) $row['source_host_id'];
            if (!isset($sites[$host])) {
                $sites[$host] = array('host' => self::text_of($texts, $host), 'host_id' => $host, 'links' => 0, 'pages' => array(), 'new' => 0, 'first' => PHP_INT_MAX, 'last' => 0, 'follow' => 0);
            }
            $sites[$host]['links']++;
            $sites[$host]['pages'][(int) $row['path_id']] = true;
            $sites[$host]['new']   += $links[$i]['new'] ? 1 : 0;
            $sites[$host]['first']  = min($sites[$host]['first'], (int) $row['first_seen']);
            $sites[$host]['last']   = max($sites[$host]['last'], (int) $row['last_seen']);
            $sites[$host]['follow'] += ((int) $row['rel'] & (self::REL['nofollow'] | self::REL['sponsored'] | self::REL['ugc'])) ? 0 : 1;
        }
        return $sites;
    }

    /**
     * The site's pages the live links go to, by path id.
     *
     * @param array<int,array<string,string>> $live  Live link rows.
     * @param array<int,array<string,mixed>>  $links The same links from link_out().
     * @param array<int,string>               $texts Id => text.
     * @return array<int,array<string,mixed>>
     */
    private static function linked_pages(array $live, array $links, array $texts) {
        $pages = array();
        foreach ($live as $i => $row) {
            $path = (int) $row['path_id'];
            if (!isset($pages[$path])) {
                $pages[$path] = array('page' => self::text_of($texts, $path), 'links' => 0, 'hosts' => array(), 'new' => 0, 'first' => PHP_INT_MAX);
            }
            $pages[$path]['links']++;
            $pages[$path]['hosts'][(int) $row['source_host_id']] = true;
            $pages[$path]['new']   += $links[$i]['new'] ? 1 : 0;
            $pages[$path]['first']  = min($pages[$path]['first'], (int) $row['first_seen']);
        }
        return $pages;
    }

    /**
     * The domains list: referring sites with their lost links and visits,
     * most visits first.
     *
     * @param array<int,array<string,mixed>>  $sites From sites().
     * @param array<int,array<string,string>> $lost  Lost link rows in the range.
     * @param array<string,mixed>             $range From SEOProStats_Query::range().
     * @return array<int,array<string,mixed>>
     */
    private static function domains(array $sites, array $lost, array $range) {
        $lost_by_host = array_count_values(array_map('intval', array_column($lost, 'source_host_id')));
        $visits       = self::visits(array_keys($sites), $range);
        $domains      = array();
        foreach ($sites as $host => $site) {
            $domains[] = array(
                'host'       => $site['host'],
                'links'      => $site['links'],
                'followed'   => $site['follow'],
                'pages'      => count($site['pages']),
                'new'        => $site['new'],
                'lost'       => isset($lost_by_host[$host]) ? (int) $lost_by_host[$host] : 0,
                'visits'     => isset($visits[$host]) ? (int) $visits[$host] : 0,
                'first_seen' => self::date_out($site['first']),
                'last_seen'  => self::date_out($site['last']),
            );
        }
        usort($domains, static function ($a, $b) {
            return array($b['visits'], $b['links'], $a['host']) <=> array($a['visits'], $a['links'], $b['host']);
        });
        return $domains;
    }

    /**
     * The linked pages list, most referring sites first.
     *
     * @param array<int,array<string,mixed>> $pages From linked_pages().
     * @return array<int,array<string,mixed>>
     */
    private static function targets(array $pages) {
        $targets = array();
        foreach ($pages as $page) {
            $targets[] = array(
                'page'       => $page['page'],
                'links'      => $page['links'],
                'domains'    => count($page['hosts']),
                'new'        => $page['new'],
                'first_seen' => self::date_out($page['first']),
            );
        }
        usort($targets, static function ($a, $b) {
            return array($b['domains'], $b['links'], $a['page']) <=> array($a['domains'], $a['links'], $b['page']);
        });
        return $targets;
    }

    /**
     * Visits from each referring site in a range, from the daily source
     * summaries (finished days), by their dim_val_day key.
     *
     * @param int[]               $hosts Host ids.
     * @param array<string,mixed> $range From SEOProStats_Query::range().
     * @return array<int,int> Host id => visits.
     */
    private static function visits(array $hosts, array $range) {
        global $wpdb;
        $out = array();
        if (!$hosts) {
            return $out;
        }
        /** @var DateTimeImmutable $start */
        $start = $range['start'];
        $first = $start->format('Y-m-d');
        $last  = $start->setTimestamp(max((int) $range['from'], (int) $range['to'] - 1))->format('Y-m-d');
        require_once __DIR__ . '/class-seoprostats-rollup.php';
        foreach (array_chunk(array_map('intval', $hosts), self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its dim_val_day key; $holders holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT val, SUM(visits) AS visits FROM %i WHERE dim = %d AND val IN ($holders) AND day >= %s AND day <= %s GROUP BY val", array_merge(array(SEOProStats_Schema::table('daily'), SEOProStats_Rollup::DIMS['source']), $chunk, array($first, $last))), ARRAY_A);
            foreach ($rows as $row) {
                $out[(int) $row['val']] = (int) $row['visits'];
            }
        }
        return $out;
    }

    /**
     * What has been checked: referring pages known and checked, with and
     * without links, gone, and the last run.
     *
     * @return array<string,mixed>
     */
    private static function coverage() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its path_checked key (covering).
        $row   = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS pages, SUM(checked > 0) AS checked FROM %i WHERE path_id = 0', SEOProStats_Schema::table('links')), ARRAY_A);
        $state = self::state();
        $live  = SEOProStats_Schema::set() === 'live';
        $next  = $live ? wp_next_scheduled(self::CHECK_HOOK) : false;
        return array(
            'enabled' => !$live || SEOProStats_Statistics::backlinks(),
            'pages'   => is_array($row) ? (int) $row['pages'] : 0,
            'checked' => is_array($row) ? (int) $row['checked'] : 0,
            'last'    => $state['last'] ? (string) wp_date('c', $state['last']) : null,
            'errors'  => $state['errors'],
            // The catch-up's next run while pages an export named wait for their first check.
            'next'    => $next ? (string) wp_date('c', (int) $next) : null,
        );
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * Names of the bits set.
     *
     * @param array<string,int> $map  Name => bit.
     * @param int               $bits Bits.
     * @return string[]
     */
    private static function names(array $map, $bits) {
        $out = array();
        foreach ($map as $name => $bit) {
            if ($bits & $bit) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * The address of a referring page: https, the host as the visit gave
     * it (without www.), and its path; '' when the host is not a domain.
     *
     * @param string $host Host.
     * @param string $path Path ('/' for the home page).
     * @return string
     */
    public static function page_url($host, $path) {
        $host = strtolower(trim((string) $host, '. '));
        if ($host === '' || strpos($host, '.') === false || !preg_match('/^[a-z0-9.-]+$/', $host) || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }
        $path = (string) $path;
        $path = $path === '' || $path[0] !== '/' ? '/' . $path : $path;
        return 'https://' . $host . $path;
    }

    /**
     * The 8-byte key of a referring page and a page it links to (0: the
     * referring page's own row), as hex.
     *
     * @param string $url     Referring page.
     * @param int    $path_id Linked page.
     * @return string
     */
    private static function key($url, $path_id) {
        return SEOProStats_Dict::hash($url . "\t" . (int) $path_id);
    }

    /**
     * Keep how far the visits were read.
     *
     * @param int $upto Unix seconds.
     */
    private static function save_upto($upto) {
        $state         = get_option(SEOProStats_Schema::option(self::OPTION), array());
        $state         = is_array($state) ? $state : array();
        $state['upto'] = (int) $upto;
        update_option(SEOProStats_Schema::option(self::OPTION), $state, false);
    }

    /**
     * Load the classes the check and report use.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-query.php';
        require_once __DIR__ . '/class-seoprostats-channels.php';
        require_once __DIR__ . '/class-seoprostats-changes.php';
        require_once __DIR__ . '/class-seoprostats-links.php';
    }

    /**
     * Progress of the current data set.
     *
     * @return array{upto:int,last:int,checked:int,errors:int,version:int}
     */
    public static function state() {
        $state = get_option(SEOProStats_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        return array(
            'upto'    => isset($state['upto']) ? (int) $state['upto'] : 0,
            'last'    => isset($state['last']) ? (int) $state['last'] : 0,
            'checked' => isset($state['checked']) ? (int) $state['checked'] : 0,
            'errors'  => isset($state['errors']) ? (int) $state['errors'] : 0,
            'version' => isset($state['version']) ? (int) $state['version'] : 0,
        );
    }

    /**
     * Delete the current data set's progress (demo removal, uninstall).
     */
    public static function reset() {
        require_once __DIR__ . '/class-seoprostats-backlinks-import.php';
        require_once __DIR__ . '/class-seoprostats-backlinks-history.php';
        SEOProStats_Backlinks_Import::reset();
        SEOProStats_Backlinks_History::reset();
        delete_option(SEOProStats_Schema::option(self::OPTION));
        delete_option(SEOProStats_Schema::option(self::OPTION . '_lock'));
        if (SEOProStats_Schema::set() === 'live') {
            wp_clear_scheduled_hook(self::CHECK_HOOK);
        }
    }

    /**
     * Write links straight to the current data set (the demo data):
     * referring pages and their links as a check would have found them.
     *
     * @param array<int,array{url:string,path:string,anchor:string,rel:int,first_seen:int,last_seen:int,lost:int,facts?:array<string,mixed>}> $links Links; lost 0 for live ones.
     */
    public static function write_links(array $links) {
        self::load();
        $urls     = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_URL, array_column($links, 'url'));
        $paths    = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array_column($links, 'path'));
        $anchors  = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_LABEL, array_column($links, 'anchor'));
        $host_ids = self::link_host_ids($links);
        $table    = SEOProStats_Schema::table('links');
        $pages    = array();
        foreach ($links as $link) {
            $url     = (string) $link['url'];
            $host    = (string) wp_parse_url($url, PHP_URL_HOST);
            $url_id  = self::dict_id($urls, $url);
            $path_id = self::dict_id($paths, $link['path']);
            $host_id = isset($host_ids[$host]) ? (int) $host_ids[$host] : 0;
            if (!$url_id || !$path_id) {
                continue;
            }
            self::put_link($table, $link, array($host_id, $url_id, $path_id, self::dict_id($anchors, $link['anchor'])));
            $pages[$url] = self::demo_page(isset($pages[$url]) ? $pages[$url] : null, $link, $host_id, $url_id);
        }
        foreach ($pages as $url => $page) {
            self::put($table, self::key((string) $url, 0), array($page['host_id'], $page['url_id'], 0, 0, 0, self::FOUND['referrer'], $page['live'] ? self::PAGE_LINKS : self::PAGE_NONE, $page['first'], $page['checked'], 0, $page['checked'], 0));
        }
        $state            = get_option(SEOProStats_Schema::option(self::OPTION), array());
        $state            = is_array($state) ? $state : array();
        $state['last']    = time();
        $state['version'] = time();
        update_option(SEOProStats_Schema::option(self::OPTION), $state, false);
    }

    /**
     * Host ids of the referring pages of links to write.
     *
     * @param array<int,array<string,mixed>> $links Links (url).
     * @return array<string,int> Host => id.
     */
    private static function link_host_ids(array $links) {
        $hosts = array();
        foreach ($links as $link) {
            $host = (string) wp_parse_url($link['url'], PHP_URL_HOST);
            $hosts[$host] = true;
        }
        return SEOProStats_Dict::ids(SEOProStats_Schema::DICT_HOST, array_keys($hosts));
    }

    /**
     * Write one demo link whole, and its referring page's facts when given.
     *
     * @param string              $table The links table.
     * @param array<string,mixed> $link  The link (url, rel, first_seen, last_seen, lost, facts?).
     * @param int[]               $ids   source_host_id, source_url_id, path_id, anchor_id.
     */
    private static function put_link($table, array $link, array $ids) {
        global $wpdb;
        list($host_id, $url_id, $path_id, $anchor_id) = $ids;
        $lost = (int) $link['lost'];
        self::put($table, self::key((string) $link['url'], $path_id), array(
            $host_id,
            $url_id,
            $path_id,
            $anchor_id,
            (int) $link['rel'],
            self::FOUND['referrer'],
            $lost ? self::LINK_LOST : self::LINK_LIVE,
            (int) $link['first_seen'],
            (int) $link['last_seen'],
            $lost,
            (int) $link['last_seen'],
            $lost ? self::MISSES : 0,
        ));
        if (isset($link['facts'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- demo data only, indexed source_url read/write.
            $wpdb->query($wpdb->prepare('UPDATE %i SET facts = %s WHERE source_url_id = %d', $table, (string) wp_json_encode($link['facts']), $url_id));
        }
    }

    /**
     * A demo referring page with one more of its links added.
     *
     * @param array{host_id:int,url_id:int,first:int,checked:int,live:bool}|null $page The page so far, or null.
     * @param array<string,mixed>      $link    The link (first_seen, last_seen, lost).
     * @param int                      $host_id Its host.
     * @param int                      $url_id  Its address.
     * @return array{host_id:int,url_id:int,first:int,checked:int,live:bool}
     */
    private static function demo_page($page, array $link, $host_id, $url_id) {
        $page            = $page !== null ? $page : array('host_id' => $host_id, 'url_id' => $url_id, 'first' => (int) $link['first_seen'], 'checked' => 0, 'live' => false);
        $page['first']   = min($page['first'], (int) $link['first_seen']);
        $page['checked'] = max($page['checked'], (int) $link['last_seen']);
        $page['live']    = $page['live'] || !(int) $link['lost'];
        return $page;
    }

    /**
     * The id SEOProStats_Dict::ids() gave a text, 0 when none.
     *
     * @param array<string,int|string> $ids   Clean text => id.
     * @param string                   $value The text.
     * @return int
     */
    private static function dict_id(array $ids, $value) {
        $key = SEOProStats_Dict::clean($value);
        return isset($ids[$key]) ? (int) $ids[$key] : 0;
    }

    /**
     * Write one row whole, by its key (the demo data).
     *
     * @param string $table Table.
     * @param string $key   lkey as hex.
     * @param int[]  $v     source_host_id, source_url_id, path_id, anchor_id, rel, found, status, first_seen, last_seen, lost, checked, misses.
     */
    private static function put($table, $key, array $v) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the demo's own table by its unique key; the values are passed as one array.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (lkey, source_host_id, source_url_id, path_id, anchor_id, rel, found, status, first_seen, last_seen, lost, checked, misses) VALUES (UNHEX(%s), %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE source_host_id = VALUES(source_host_id), source_url_id = VALUES(source_url_id), path_id = VALUES(path_id), anchor_id = VALUES(anchor_id), rel = VALUES(rel), found = VALUES(found), status = VALUES(status), first_seen = VALUES(first_seen), last_seen = VALUES(last_seen), lost = VALUES(lost), checked = VALUES(checked), misses = VALUES(misses)',
            array_merge(array($table, $key), array_map('intval', $v))
        ));
    }
}
