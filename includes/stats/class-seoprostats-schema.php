<?php
/**
 * The statistics tables: names, definitions, upgrades and removal.
 *
 * Tables are made and changed with dbDelta() when VERSION is newer than
 * the stored one, on admin requests and before processing, never on
 * visitor pages. Layout and reasons: docs/architecture.md → Storage.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Schema {

    /**
     * Table layout version. Bump it with every change to definitions() and
     * add a line.
     *
     * v1: dict, sessions, pageviews, events, props, daily.
     * v2: daily.scroll (sum of the deepest scroll % of a page's views).
     * v3: props keys owner_ts and key_ts replace ts and key_value.
     * v4: clicks (clicks and form submits).
     * v5: pageviews.search_id and key search_ts, sessions.login, pages
     *     (what each address shows); daily rows for logged-in visits.
     * v6: changes (the change log: markers on the timeline).
     * v7: gsc_pages, gsc_queries, gsc_pairs, gsc_totals (search data from
     *     Search Console and later Bing), imports (each import run).
     * v8: experiments (a change's hypothesis, measured before and after).
     * v9: queue (decision queue items someone accepted, did or dismissed).
     * v10: page_facts (the content audit's facts about each published page).
     * v11: page_links (links between the site's own pages) and
     *      page_facts.links_in (pages linking to each).
     */
    const VERSION = 11;

    /** Keys a later version replaced: table => key names (dbDelta() only adds). */
    const OLD_KEYS = array('props' => array('ts', 'key_value'));

    /** Option holding the version the tables were last made with. */
    const OPTION = 'seoprostats_schema_version';

    /** Kinds of text in the dictionary table. */
    const DICT_PATH     = 1;
    const DICT_HOST     = 2;
    const DICT_UTM      = 3;
    const DICT_EVENT    = 4;
    const DICT_PROP_KEY = 5;
    const DICT_PROP_VAL = 6;
    const DICT_BROWSER  = 7;
    const DICT_OS       = 8;
    const DICT_REGION   = 9;
    const DICT_CITY     = 10;
    const DICT_LANGUAGE = 11;
    const DICT_SELECTOR = 12;
    const DICT_LABEL    = 13;
    const DICT_TARGET   = 14;
    const DICT_SEARCH   = 15;
    const DICT_QUERY    = 16;

    /** Search engines of the gsc_* rows. */
    const ENGINE_GOOGLE = 1;
    const ENGINE_BING   = 2;

    /** Device of gsc_totals rows (as SEOProStats_UA's device codes; 0 other). */
    const GSC_DEVICES = array(
        'DESKTOP' => 1,
        'MOBILE'  => 2,
        'TABLET'  => 3,
    );

    /** Flags of a pageview: the page was not found; a site search; the search found nothing. */
    const PAGE_NOT_FOUND  = 1;
    const PAGE_SEARCH     = 2;
    const PAGE_NO_RESULTS = 4;

    /** Kinds of rows in the clicks table. */
    const CLICK = 1;
    const FORM  = 2;

    /** Owners of rows in the props table. */
    const OWNER_PAGEVIEW = 1;
    const OWNER_EVENT    = 2;

    /**
     * Data sets: live (the site's statistics) and demo (made-up visits in
     * tables of their own, SEOProStats_Demo). Same layout, never mixed.
     */
    const SETS = array('live', 'demo');

    /** @var string The data set this request reads and writes. */
    private static $set = 'live';

    /**
     * Table names without the prefix, in the order they are made.
     *
     * @return string[]
     */
    public static function names() {
        return array('dict', 'sessions', 'pageviews', 'events', 'props', 'daily', 'clicks', 'pages', 'changes', 'gsc_pages', 'gsc_queries', 'gsc_pairs', 'gsc_totals', 'imports', 'experiments', 'queue', 'page_facts', 'page_links');
    }

    /**
     * Full name of one of the plugin's tables on the current site, in the
     * current data set (demo tables: seoprostats_demo_*).
     *
     * @param string $name Name from names().
     * @return string
     */
    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'seoprostats_' . (self::$set === 'demo' ? 'demo_' : '') . $name;
    }

    /**
     * Name of an option that belongs to a data set (table version,
     * processing and summary progress): as given for live, with _demo
     * after it for demo.
     *
     * @param string $name Live option name.
     * @return string
     */
    public static function option($name) {
        return self::$set === 'demo' ? $name . '_demo' : $name;
    }

    /**
     * Switch the data set for what follows; give the one returned back
     * with use_set() when done. Cron and visitor requests stay on live.
     *
     * @param string $set live or demo.
     * @return string The data set before.
     */
    public static function use_set($set) {
        $before    = self::$set;
        self::$set = $set === 'demo' ? 'demo' : 'live';
        return $before;
    }

    /**
     * The current data set.
     *
     * @return string live or demo.
     */
    public static function set() {
        return self::$set;
    }

    /**
     * Make or change the tables when they are older than VERSION.
     *
     * @return bool Whether the tables are at VERSION.
     */
    public static function maybe_upgrade() {
        if (self::is_current()) {
            return true;
        }
        return self::install();
    }

    /**
     * Whether the tables are at VERSION (no query: the option is autoloaded).
     * Cron and the API check this, as only admin requests upgrade.
     *
     * @return bool
     */
    public static function is_current() {
        return (int) get_option(self::option(self::OPTION), 0) >= self::VERSION;
    }

    /**
     * Make or change every table with dbDelta(), then record VERSION.
     *
     * @return bool Whether every table exists afterwards.
     */
    public static function install() {
        global $wpdb;

        $before = (int) get_option(self::option(self::OPTION), 0);
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(array_values(self::definitions()));

        foreach (self::names() as $name) {
            $table = self::table($name);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- checking our own table after dbDelta(); not cached on purpose.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                return false;
            }
        }
        self::drop_old_keys();
        if ($before > 0 && $before < 5) {
            self::add_login_days();
        }
        // Live: autoloaded like the settings version, as maybe_upgrade()
        // reads it on every admin request. Demo: read only when shown.
        update_option(self::option(self::OPTION), self::VERSION, self::$set === 'live');
        return true;
    }

    /**
     * Drop keys a later version replaced, where they are still there.
     */
    private static function drop_old_keys() {
        global $wpdb;
        foreach (self::OLD_KEYS as $name => $keys) {
            $table = self::table($name);
            foreach ($keys as $key) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading our own table's keys while upgrading; not cached on purpose.
                if ($wpdb->get_var($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $table, $key))) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- upgrading our own table.
                    $wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX %i', $table, $key));
                }
            }
        }
    }

    /**
     * v5: days summarised before visits recorded logging in get their
     * logged-in row: every visit of theirs counts as not logged in, so the
     * row is the site's row.
     */
    private static function add_login_days() {
        global $wpdb;
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-rollup.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- upgrading our own table, once.
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO %i (day, dim, val, visitors, visits, pageviews, bounces, engaged_ms, events) SELECT day, %d, 0, visitors, visits, pageviews, bounces, engaged_ms, events FROM %i WHERE dim = 0', self::table('daily'), SEOProStats_Rollup::DIMS['login'], self::table('daily')));
    }

    /**
     * Delete the current data set's tables and version option (uninstall;
     * removing demo data).
     */
    public static function drop() {
        global $wpdb;
        foreach (array_reverse(self::names()) as $name) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing our own tables on uninstall or with the demo data.
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::table($name)));
        }
        delete_option(self::option(self::OPTION));
    }

    /**
     * CREATE TABLE statements in dbDelta()'s format: one column or key per
     * line, two spaces after PRIMARY KEY, keys named.
     *
     * Times are Unix seconds (UTC); days are site-local dates. Text is a
     * dict id. Revenue is in the currency's minor unit (cents).
     *
     * @return array<string,string> Name => statement.
     */
    public static function definitions() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $t       = array();
        foreach (self::names() as $name) {
            $t[$name] = self::table($name);
        }

        return array(
            // Each distinct text of a kind, once.
            'dict' => "CREATE TABLE {$t['dict']} (
  id int unsigned NOT NULL AUTO_INCREMENT,
  kind tinyint unsigned NOT NULL,
  hash binary(8) NOT NULL,
  value varchar(2048) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY kind_hash (kind,hash)
) $charset;",

            // One row per visit.
            'sessions' => "CREATE TABLE {$t['sessions']} (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  skey binary(8) NOT NULL,
  visitor binary(8) NOT NULL,
  day date NOT NULL,
  started int unsigned NOT NULL,
  ended int unsigned NOT NULL,
  pageviews smallint unsigned NOT NULL DEFAULT 0,
  events smallint unsigned NOT NULL DEFAULT 0,
  engaged_ms int unsigned NOT NULL DEFAULT 0,
  entry_id int unsigned NOT NULL DEFAULT 0,
  exit_id int unsigned NOT NULL DEFAULT 0,
  ref_host_id int unsigned NOT NULL DEFAULT 0,
  ref_path_id int unsigned NOT NULL DEFAULT 0,
  channel tinyint unsigned NOT NULL DEFAULT 0,
  utm_source_id int unsigned NOT NULL DEFAULT 0,
  utm_medium_id int unsigned NOT NULL DEFAULT 0,
  utm_campaign_id int unsigned NOT NULL DEFAULT 0,
  utm_term_id int unsigned NOT NULL DEFAULT 0,
  utm_content_id int unsigned NOT NULL DEFAULT 0,
  country char(2) NOT NULL DEFAULT '',
  region_id int unsigned NOT NULL DEFAULT 0,
  city_id int unsigned NOT NULL DEFAULT 0,
  lang_id int unsigned NOT NULL DEFAULT 0,
  browser_id int unsigned NOT NULL DEFAULT 0,
  browser_ver smallint unsigned NOT NULL DEFAULT 0,
  os_id int unsigned NOT NULL DEFAULT 0,
  os_ver smallint unsigned NOT NULL DEFAULT 0,
  device tinyint unsigned NOT NULL DEFAULT 0,
  screen smallint unsigned NOT NULL DEFAULT 0,
  source tinyint unsigned NOT NULL DEFAULT 0,
  import_id int unsigned NOT NULL DEFAULT 0,
  login tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY skey (skey),
  KEY started (started),
  KEY day_visitor (day,visitor)
) $charset;",

            // One row per page load or SPA navigation. flags: PAGE_*
            // constants. search_id: the site search's words (0: none, or
            // not recorded).
            'pageviews' => "CREATE TABLE {$t['pageviews']} (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  pkey binary(8) NOT NULL,
  session_id bigint unsigned NOT NULL,
  ts int unsigned NOT NULL,
  seq smallint unsigned NOT NULL,
  path_id int unsigned NOT NULL,
  engaged_ms int unsigned NOT NULL DEFAULT 0,
  scroll tinyint unsigned NOT NULL DEFAULT 0,
  flags tinyint unsigned NOT NULL DEFAULT 0,
  search_id int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY pkey (pkey),
  UNIQUE KEY session_seq (session_id,seq),
  KEY path_ts (path_id,ts),
  KEY search_ts (search_id,ts),
  KEY ts (ts)
) $charset;",

            // One row per custom or automatic event.
            'events' => "CREATE TABLE {$t['events']} (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  ts int unsigned NOT NULL,
  seq smallint unsigned NOT NULL,
  path_id int unsigned NOT NULL DEFAULT 0,
  name_id int unsigned NOT NULL,
  revenue bigint NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY session_seq (session_id,seq),
  KEY name_ts (name_id,ts),
  KEY ts (ts)
) $charset;",

            // Properties of pageviews and events. Reads are by period:
            // owner_ts lists keys and prunes; key_ts lists a key's values.
            // Both hold the primary key too, so neither reads the rows.
            'props' => "CREATE TABLE {$t['props']} (
  owner tinyint unsigned NOT NULL,
  owner_id bigint unsigned NOT NULL,
  key_id int unsigned NOT NULL,
  value_id int unsigned NOT NULL,
  ts int unsigned NOT NULL,
  PRIMARY KEY  (owner,owner_id,key_id),
  KEY owner_ts (owner,ts),
  KEY key_ts (key_id,ts,value_id)
) $charset;",

            // Finished days, per dimension and value (dim 0, val 0: the
            // site; codes in SEOProStats_Rollup::DIMS). For pages,
            // engaged_ms and scroll are sums over the page's views.
            'daily' => "CREATE TABLE {$t['daily']} (
  day date NOT NULL,
  dim tinyint unsigned NOT NULL,
  val int unsigned NOT NULL,
  visitors int unsigned NOT NULL DEFAULT 0,
  visits int unsigned NOT NULL DEFAULT 0,
  pageviews int unsigned NOT NULL DEFAULT 0,
  bounces int unsigned NOT NULL DEFAULT 0,
  engaged_ms bigint unsigned NOT NULL DEFAULT 0,
  events int unsigned NOT NULL DEFAULT 0,
  scroll bigint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,dim,val),
  KEY dim_val_day (dim,val,day)
) $charset;",

            // One row per click or form submit (autocapture), on its page
            // load: session_id, seq and path_id are the pageview's, so
            // clicks never start or extend a visit. flags: 1 dead, 2
            // outbound, 4 affiliate, 8 download. fields: a form's fields.
            'clicks' => "CREATE TABLE {$t['clicks']} (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  ts int unsigned NOT NULL,
  seq smallint unsigned NOT NULL,
  path_id int unsigned NOT NULL,
  kind tinyint unsigned NOT NULL,
  selector_id int unsigned NOT NULL DEFAULT 0,
  label_id int unsigned NOT NULL DEFAULT 0,
  target_id int unsigned NOT NULL DEFAULT 0,
  flags tinyint unsigned NOT NULL DEFAULT 0,
  fields tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY path_ts (path_id,ts),
  KEY ts (ts)
) $charset;",

            // What an address shows, when it is one post, page or other
            // single item: written by the processor from WordPress (the
            // latest view wins), read by reports for authors, categories
            // and post types. Names are looked up when a report is made.
            'pages' => "CREATE TABLE {$t['pages']} (
  path_id int unsigned NOT NULL,
  post_id bigint unsigned NOT NULL DEFAULT 0,
  post_type varchar(20) NOT NULL DEFAULT '',
  author_id bigint unsigned NOT NULL DEFAULT 0,
  term_id bigint unsigned NOT NULL DEFAULT 0,
  seen int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (path_id),
  KEY post_type (post_type),
  KEY author_id (author_id),
  KEY term_id (term_id)
) $charset;",

            // The change log (SEOProStats_Changes): one row per change to
            // the site, kept for good. kind and source: codes in
            // SEOProStats_Changes::KINDS and ::SOURCES. path_id: the page
            // it affects (0: the whole site). object_type: the post type,
            // or coupon, plugin, theme, core, option. meta: JSON details.
            'changes' => "CREATE TABLE {$t['changes']} (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  ts int unsigned NOT NULL,
  kind tinyint unsigned NOT NULL,
  path_id int unsigned NOT NULL DEFAULT 0,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint unsigned NOT NULL DEFAULT 0,
  old varchar(190) NOT NULL DEFAULT '',
  new varchar(190) NOT NULL DEFAULT '',
  meta text NOT NULL,
  source tinyint unsigned NOT NULL DEFAULT 1,
  user_id bigint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY ts (ts),
  KEY path_ts (path_id,ts),
  KEY kind_ts (kind,ts)
) $charset;",

            // Search data (SEOProStats_Search_Import): one row per search
            // engine (ENGINE_*), day (the engine's own, Pacific time for
            // Google) and page, query, page and query, or device and
            // country. pos_impr: average position × impressions × 100, so
            // SUM(pos_impr) / SUM(impressions) / 100 is the weighted
            // average. import_id: the imports row that wrote it.
            'gsc_pages' => "CREATE TABLE {$t['gsc_pages']} (
  engine tinyint unsigned NOT NULL,
  day date NOT NULL,
  path_id int unsigned NOT NULL,
  clicks int unsigned NOT NULL DEFAULT 0,
  impressions int unsigned NOT NULL DEFAULT 0,
  pos_impr bigint unsigned NOT NULL DEFAULT 0,
  import_id int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (engine,day,path_id),
  KEY path_day (path_id,day)
) $charset;",

            'gsc_queries' => "CREATE TABLE {$t['gsc_queries']} (
  engine tinyint unsigned NOT NULL,
  day date NOT NULL,
  query_id int unsigned NOT NULL,
  clicks int unsigned NOT NULL DEFAULT 0,
  impressions int unsigned NOT NULL DEFAULT 0,
  pos_impr bigint unsigned NOT NULL DEFAULT 0,
  import_id int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (engine,day,query_id),
  KEY query_day (query_id,day)
) $charset;",

            'gsc_pairs' => "CREATE TABLE {$t['gsc_pairs']} (
  engine tinyint unsigned NOT NULL,
  day date NOT NULL,
  path_id int unsigned NOT NULL,
  query_id int unsigned NOT NULL,
  clicks int unsigned NOT NULL DEFAULT 0,
  impressions int unsigned NOT NULL DEFAULT 0,
  pos_impr bigint unsigned NOT NULL DEFAULT 0,
  import_id int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (engine,day,path_id,query_id),
  KEY path_day (path_id,day,query_id),
  KEY query_day (query_id,day,path_id)
) $charset;",

            // country: the engine's code as given (Google: ISO 3166-1
            // alpha-3, lower case; '' unknown). device: GSC_DEVICES.
            'gsc_totals' => "CREATE TABLE {$t['gsc_totals']} (
  engine tinyint unsigned NOT NULL,
  day date NOT NULL,
  device tinyint unsigned NOT NULL,
  country char(3) NOT NULL DEFAULT '',
  clicks int unsigned NOT NULL DEFAULT 0,
  impressions int unsigned NOT NULL DEFAULT 0,
  pos_impr bigint unsigned NOT NULL DEFAULT 0,
  import_id int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (engine,day,device,country)
) $charset;",

            // One row per import run of an outside source, so it can be
            // undone. status: 1 running, 2 done, 3 failed, 4 undone. days:
            // the days it covers (day_from to day_to, both included).
            'imports' => "CREATE TABLE {$t['imports']} (
  id int unsigned NOT NULL AUTO_INCREMENT,
  source varchar(20) NOT NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  started int unsigned NOT NULL,
  finished int unsigned NOT NULL DEFAULT 0,
  day_from date NOT NULL,
  day_to date NOT NULL,
  rows_added int unsigned NOT NULL DEFAULT 0,
  meta text NOT NULL,
  PRIMARY KEY  (id),
  KEY source_status (source,status)
) $charset;",

            // Experiments (SEOProStats_Experiments): a hypothesis about a
            // change, measured before and after against unchanged pages;
            // kept for good (small). Codes in SEOProStats_Experiments.
            // start: the change's time; days: each window's length;
            // review: the after window's last day. path_id 0: several
            // pages, in meta.pages. meta: JSON (pages, goal, note,
            // hypothesis, and the measurement a decision was made on).
            'experiments' => "CREATE TABLE {$t['experiments']} (
  id int unsigned NOT NULL AUTO_INCREMENT,
  created int unsigned NOT NULL,
  user_id bigint unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  start int unsigned NOT NULL,
  days tinyint unsigned NOT NULL DEFAULT 28,
  review date NOT NULL,
  engine tinyint unsigned NOT NULL DEFAULT 1,
  metric tinyint unsigned NOT NULL DEFAULT 1,
  direction tinyint unsigned NOT NULL DEFAULT 1,
  threshold smallint unsigned NOT NULL DEFAULT 10,
  change_id bigint unsigned NOT NULL DEFAULT 0,
  path_id int unsigned NOT NULL DEFAULT 0,
  status tinyint unsigned NOT NULL DEFAULT 1,
  result tinyint unsigned NOT NULL DEFAULT 0,
  decided int unsigned NOT NULL DEFAULT 0,
  meta text NOT NULL,
  PRIMARY KEY  (id),
  KEY status_review (status,review),
  KEY path_id (path_id),
  KEY start (start)
) $charset;",

            // Decision queue items someone acted on (SEOProStats_Queue);
            // items nobody touched are worked out when the list is read.
            // ikey: 8-byte hash of kind, engine, page and query. Codes in
            // SEOProStats_Queue. effort 0: the kind's. meta: JSON (the
            // numbers and reason when it was acted on).
            'queue' => "CREATE TABLE {$t['queue']} (
  id int unsigned NOT NULL AUTO_INCREMENT,
  ikey binary(8) NOT NULL,
  kind tinyint unsigned NOT NULL,
  engine tinyint unsigned NOT NULL DEFAULT 1,
  path_id int unsigned NOT NULL DEFAULT 0,
  query_id int unsigned NOT NULL DEFAULT 0,
  status tinyint unsigned NOT NULL DEFAULT 1,
  effort tinyint unsigned NOT NULL DEFAULT 0,
  experiment_id int unsigned NOT NULL DEFAULT 0,
  created int unsigned NOT NULL,
  updated int unsigned NOT NULL,
  user_id bigint unsigned NOT NULL DEFAULT 0,
  note varchar(190) NOT NULL DEFAULT '',
  meta text NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ikey (ikey),
  KEY status_updated (status,updated)
) $charset;",

            // Content audit facts (SEOProStats_Audit): one row per
            // published page, read from WordPress when a post is saved and
            // by the daily cron. Times are Unix seconds; lengths are
            // characters. title_hash, desc_hash: 8-byte keys of the shown
            // title and description (zeros for none). flags: the page's
            // own findings, SEOProStats_Audit::FLAGS. links_in: other pages
            // whose content links to it (SEOProStats_Links), at most 65535.
            'page_facts' => "CREATE TABLE {$t['page_facts']} (
  path_id int unsigned NOT NULL,
  post_id bigint unsigned NOT NULL DEFAULT 0,
  checked int unsigned NOT NULL,
  modified int unsigned NOT NULL DEFAULT 0,
  title_len smallint unsigned NOT NULL DEFAULT 0,
  seo_title_len smallint unsigned NOT NULL DEFAULT 0,
  desc_len smallint unsigned NOT NULL DEFAULT 0,
  title_hash binary(8) NOT NULL,
  desc_hash binary(8) NOT NULL,
  h1 tinyint unsigned NOT NULL DEFAULT 0,
  words int unsigned NOT NULL DEFAULT 0,
  images smallint unsigned NOT NULL DEFAULT 0,
  images_no_alt smallint unsigned NOT NULL DEFAULT 0,
  noindex tinyint unsigned NOT NULL DEFAULT 0,
  canonical_away tinyint unsigned NOT NULL DEFAULT 0,
  flags smallint unsigned NOT NULL DEFAULT 0,
  links_in smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (path_id),
  KEY post_id (post_id),
  KEY checked (checked),
  KEY flags (flags),
  KEY title_hash (title_hash),
  KEY desc_hash (desc_hash),
  KEY links_in (links_in)
) $charset;",

            // Links in the content of published pages to the site's own
            // pages (SEOProStats_Links), read with their page_facts row:
            // one row per page and page it links to, with the first link's
            // text (a dictionary id, DICT_LABEL) and how many links there are.
            'page_links' => "CREATE TABLE {$t['page_links']} (
  from_path int unsigned NOT NULL,
  to_path int unsigned NOT NULL,
  text_id int unsigned NOT NULL DEFAULT 0,
  links smallint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (from_path,to_path),
  KEY to_path (to_path)
) $charset;",
        );
    }
}
