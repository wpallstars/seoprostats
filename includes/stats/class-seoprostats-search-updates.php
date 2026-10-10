<?php
/**
 * Search engine updates on the timeline: Google's ranking updates and
 * search incidents from its Search Status Dashboard, and announcements
 * from other feeds the owner adds, so a change in traffic can be weighed
 * against them.
 *
 * Opt-in (Settings → Data). Fetched by the daily cron job only, never on
 * a visitor's page: one request to each source, with a short timeout and
 * no cookies or site address. A failed source is noted for doctor and
 * asked again the next day. Each update is one change log row (kind
 * search_update, site-wide), added once and brought up to date when its
 * rollout ends. Design: docs/architecture.md → Search engine updates.
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

final class SEOProStats_Search_Updates {

    /** Google Search Status Dashboard's incidents, as JSON (newest first). */
    const GOOGLE_FEED = 'https://status.search.google.com/incidents.json';

    /** Where the incidents' relative addresses start. */
    const GOOGLE_BASE = 'https://status.search.google.com/';

    /** The dashboard's products: id => type of their incidents (Ranking: from the title). */
    const GOOGLE_PRODUCTS = array(
        'rGHU1u87FJnkP6W2GwMi' => 'ranking',
    );

    /** The other products' names => type. */
    const GOOGLE_NAMED = array(
        'ranking'  => 'ranking',
        'crawling' => 'crawling',
        'indexing' => 'indexing',
        'serving'  => 'serving',
    );

    /** Types of update; announcement: a feed's post that names no known type. */
    const TYPES = array('core', 'spam', 'link_spam', 'helpful_content', 'reviews', 'product_reviews', 'site_reputation', 'discover', 'ranking', 'crawling', 'indexing', 'serving', 'announcement');

    /** Progress and each source's last answer (not autoloaded). */
    const STATE_OPTION = 'seoprostats_search_updates';

    /** Oldest update imported, in seconds before now. */
    const SINCE = 2 * YEAR_IN_SECONDS;

    /** Most other feeds. */
    const MAX_FEEDS = 10;

    /** Most entries kept from one answer. */
    const MAX_ENTRIES = 200;

    /** Largest answer read. */
    const MAX_BYTES = 2097152;

    /** Seconds a source may take to answer. */
    const TIMEOUT = 10;

    /**
     * Words in a feed post's title that make it an update. Other posts
     * (news, tips) are left out.
     */
    const UPDATE_WORDS = '/\b(update|updates|updated|algorithm|rollout|rolling out|roll-out|rolled out)\b/i';

    /**
     * Daily cron: fetch every source and store what is new. Does nothing
     * when the setting is off.
     *
     * @param bool $force Fetch even when the setting is off (WP-CLI).
     * @return array<string,mixed> The state saved.
     */
    public static function run($force = false) {
        if (!$force && !SEOProStats_Statistics::search_updates()) {
            return self::state();
        }
        $sources = array();
        foreach (self::sources() as $source) {
            $sources[] = self::fetch_source($source);
        }
        $state = array(
            'last'    => time(),
            'sources' => $sources,
        );
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    /**
     * Sources to fetch: Google's dashboard, then the other feeds.
     *
     * @return array<int,array{url:string,engine:string,name:string,format:string}>
     */
    public static function sources() {
        $out = array(
            array(
                'url'    => self::GOOGLE_FEED,
                'engine' => 'google',
                'name'   => 'Google',
                'format' => 'google',
            ),
        );
        foreach (SEOProStats_Statistics::search_feeds() as $feed) {
            $out[] = $feed + array('format' => 'feed');
        }
        return $out;
    }

    /**
     * A line of the other feeds setting: an address, then the search
     * engine's name (without it, the address's domain).
     *
     * @param string $line Line.
     * @return array{url:string,engine:string,name:string}|null Null when not an http(s) address.
     */
    public static function parse_feed_line($line) {
        $parts = preg_split('/\s+/', trim((string) $line), 2);
        // Only a full address: esc_url_raw() would make "not a url" http://not.
        $url   = $parts && preg_match('#^https?://#i', (string) $parts[0]) ? esc_url_raw((string) $parts[0], array('http', 'https')) : '';
        $host  = $url !== '' ? (string) wp_parse_url($url, PHP_URL_HOST) : '';
        if ($host === '') {
            return null;
        }
        $name = isset($parts[1]) ? trim(sanitize_text_field($parts[1])) : '';
        $name = $name !== '' ? $name : (string) preg_replace('/^www\./', '', strtolower($host));
        // PHP 7's substr() answers false for an empty string.
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 40) : (string) substr($name, 0, 40);
        $key  = (string) substr(sanitize_key(str_replace(array('.', ' '), '-', $name)), 0, 20);
        return array(
            'url'    => $url,
            'engine' => $key !== '' && $key !== 'google' ? $key : 'feed',
            'name'   => $name !== '' ? $name : $host,
        );
    }

    /**
     * Fetch one source and store its updates.
     *
     * @param array{url:string,engine:string,name:string,format:string} $source Source.
     * @return array<string,mixed> Its result: url, name, ok, error, entries, added, updated, at.
     */
    private static function fetch_source(array $source) {
        $out  = array(
            'url'     => $source['url'],
            'name'    => $source['name'],
            'ok'      => false,
            'error'   => '',
            'entries' => 0,
            'added'   => 0,
            'updated' => 0,
            'at'      => time(),
        );
        $body = self::get($source['url']);
        if (is_wp_error($body)) {
            $out['error'] = $body->get_error_message();
            return $out;
        }
        $entries = $source['format'] === 'google' ? self::parse_google($body) : self::parse_feed($body);
        if (is_wp_error($entries)) {
            $out['error'] = $entries->get_error_message();
            return $out;
        }
        $since   = time() - self::SINCE;
        $entries = array_slice(array_values(array_filter($entries, static function ($entry) use ($since) {
            return $entry['started'] >= $since && $entry['started'] <= time() + DAY_IN_SECONDS;
        })), 0, self::MAX_ENTRIES);
        $stored  = self::store($source, $entries);
        if (is_wp_error($stored)) {
            $out['error'] = $stored->get_error_message();
            return $out;
        }
        $out['ok']      = true;
        $out['entries'] = count($entries);
        $out['added']   = $stored['added'];
        $out['updated'] = $stored['updated'];
        return $out;
    }

    /**
     * GET an address: the answer's body, or why not. Only public
     * addresses (wp_safe_remote_get), no cookies, and a user agent that
     * names the plugin, not the site.
     *
     * @param string $url Address.
     * @return string|WP_Error
     */
    private static function get($url) {
        $response = wp_safe_remote_get($url, array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_BYTES,
            'user-agent'          => 'SEO Pro Stats/' . SEOPROSTATS_VERSION . ' (WordPress plugin)',
            'headers'             => array('Accept' => 'application/json, application/feed+json, application/atom+xml, application/rss+xml, application/xml;q=0.9, */*;q=0.5'),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            /* translators: %d: HTTP status code */
            return new WP_Error('seoprostats_search_updates_http', sprintf(__('The source answered with HTTP status %d.', 'seoprostats'), $code));
        }
        $body = (string) wp_remote_retrieve_body($response);
        if (trim($body) === '') {
            return new WP_Error('seoprostats_search_updates_empty', __('The source answered with nothing.', 'seoprostats'));
        }
        return $body;
    }

    /**
     * Google's incidents: ranking updates, and crawling, indexing and
     * serving incidents. Ongoing ones have no end yet.
     *
     * @param string $body JSON.
     * @return array<int,array<string,mixed>>|WP_Error Entries: id, title, type, started, ended (0 while rolling out), url, span.
     */
    public static function parse_google($body) {
        $data = json_decode($body, true);
        if (!is_array($data) || ($data && !isset($data[0]))) {
            return new WP_Error('seoprostats_search_updates_format', __('Google\'s Search Status Dashboard answered in a format SEO Pro Stats does not know.', 'seoprostats'));
        }
        $out = array();
        foreach ($data as $incident) {
            $entry = is_array($incident) ? self::google_entry($incident) : null;
            if ($entry !== null) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * One Google incident as an entry, or null without a usable id and
     * start.
     *
     * @param array<string,mixed> $incident Incident.
     * @return array<string,mixed>|null
     */
    private static function google_entry(array $incident) {
        $started = self::google_start($incident);
        if (!$started) {
            return null;
        }
        $ended = !empty($incident['end']) ? (int) strtotime((string) $incident['end']) : 0;
        $title = isset($incident['external_desc']) ? self::text($incident['external_desc']) : '';
        $uri   = isset($incident['uri']) ? (string) $incident['uri'] : '';
        return array(
            'id'      => (string) $incident['id'],
            'title'   => $title !== '' ? $title : __('Google Search incident', 'seoprostats'),
            'type'    => self::type($title, self::google_product($incident)),
            'started' => $started,
            'ended'   => $ended > $started ? $ended : 0,
            'url'     => preg_match('#^incidents/[A-Za-z0-9_-]+$#', $uri) ? self::GOOGLE_BASE . $uri : self::GOOGLE_BASE,
            'span'    => true,
        );
    }

    /**
     * A Google incident's start (Unix time), or 0 without a usable id
     * and start.
     *
     * @param array<string,mixed> $incident Incident.
     * @return int
     */
    private static function google_start(array $incident) {
        if (empty($incident['id']) || empty($incident['begin'])) {
            return 0;
        }
        $started = strtotime((string) $incident['begin']);
        return preg_match('/^[A-Za-z0-9_-]{1,100}$/', (string) $incident['id']) && $started ? (int) $started : 0;
    }

    /**
     * The type an incident's product gives: ranking (the title then says
     * which update), crawling, indexing, serving or ''.
     *
     * @param array<string,mixed> $incident Incident.
     * @return string
     */
    private static function google_product(array $incident) {
        $products = isset($incident['affected_products']) && is_array($incident['affected_products']) ? $incident['affected_products'] : array();
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $id   = isset($product['id']) ? (string) $product['id'] : '';
            $name = isset($product['title']) ? strtolower(trim((string) $product['title'])) : '';
            if (isset(self::GOOGLE_PRODUCTS[$id])) {
                return self::GOOGLE_PRODUCTS[$id];
            }
            if (isset(self::GOOGLE_NAMED[$name])) {
                return self::GOOGLE_NAMED[$name];
            }
        }
        return '';
    }

    /**
     * A feed's update posts: RSS 2.0, Atom or JSON Feed. Posts whose
     * title does not name an update are left out.
     *
     * @param string $body Feed.
     * @return array<int,array<string,mixed>>|WP_Error Entries: id, title, type, started, ended (0), url, span (false).
     */
    public static function parse_feed($body) {
        $items = self::feed_items($body);
        if (is_wp_error($items)) {
            return $items;
        }
        $out = array();
        foreach ($items as $item) {
            $title   = self::text($item['title']);
            $started = $item['date'] !== '' ? strtotime($item['date']) : false;
            $type    = self::type($title, '');
            if ($title === '' || !$started || ($type === 'announcement' && !preg_match(self::UPDATE_WORDS, $title))) {
                continue;
            }
            $url   = esc_url_raw($item['url'], array('http', 'https'));
            $out[] = array(
                // The post's id or address; hashed to fit the column.
                'id'      => md5($item['id'] !== '' ? $item['id'] : $url . "\0" . $title), // NOSONAR nosemgrep: an ID that fits the column, not security.
                'title'   => $title,
                'type'    => $type,
                'started' => (int) $started,
                'ended'   => 0,
                'url'     => $url,
                'span'    => false,
            );
        }
        return $out;
    }

    /**
     * A feed's items as id, title, url and date.
     *
     * @param string $body RSS, Atom or JSON Feed.
     * @return array<int,array{id:string,title:string,url:string,date:string}>|WP_Error
     */
    private static function feed_items($body) {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if ($body !== '' && $body[0] === '{') {
            return self::json_feed_items($body);
        }
        if (!function_exists('simplexml_load_string')) {
            return new WP_Error('seoprostats_search_updates_xml', __('PHP\'s SimpleXML extension is needed to read RSS and Atom feeds.', 'seoprostats'));
        }
        $errors = libxml_use_internal_errors(true);
        // No network access and no entity expansion for an outside document.
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        if (!$xml) {
            return new WP_Error('seoprostats_search_updates_format', __('The feed is not RSS, Atom or JSON Feed.', 'seoprostats'));
        }
        if (isset($xml->channel->item)) {
            $out = array();
            foreach ($xml->channel->item as $item) {
                $out[] = array(
                    'id'    => (string) $item->guid,
                    'title' => (string) $item->title,
                    'url'   => (string) $item->link,
                    'date'  => (string) $item->pubDate,
                );
            }
            return $out;
        }
        $out = self::atom_items($xml);
        if (!$out && $xml->getName() !== 'feed') {
            return new WP_Error('seoprostats_search_updates_format', __('The feed is not RSS, Atom or JSON Feed.', 'seoprostats'));
        }
        return $out;
    }

    /**
     * A JSON Feed's items as id, title, url and date.
     *
     * @param string $body JSON.
     * @return array<int,array{id:string,title:string,url:string,date:string}>|WP_Error
     */
    private static function json_feed_items($body) {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return new WP_Error('seoprostats_search_updates_format', __('The feed is JSON but not a JSON Feed (it has no items).', 'seoprostats'));
        }
        $out = array();
        foreach ($data['items'] as $item) {
            if (is_array($item)) {
                $out[] = array(
                    'id'    => isset($item['id']) ? (string) $item['id'] : '',
                    'title' => isset($item['title']) ? (string) $item['title'] : '',
                    'url'   => isset($item['url']) ? (string) $item['url'] : '',
                    'date'  => (string) ($item['date_published'] ?? ($item['date_modified'] ?? '')),
                );
            }
        }
        return $out;
    }

    /**
     * An Atom feed's entries as id, title, url and date (entries in the
     * Atom namespace, or without one).
     *
     * @param SimpleXMLElement $xml Feed.
     * @return array<int,array{id:string,title:string,url:string,date:string}>
     */
    private static function atom_items(SimpleXMLElement $xml) {
        $atom = $xml->children('http://www.w3.org/2005/Atom');
        $list = array();
        if (isset($atom->entry)) {
            $list = $atom->entry;
        } elseif (isset($xml->entry)) {
            $list = $xml->entry;
        }
        $out = array();
        foreach ($list as $entry) {
            $out[] = array(
                'id'    => (string) $entry->id,
                'title' => (string) $entry->title,
                'url'   => self::atom_link($entry),
                'date'  => (string) $entry->published !== '' ? (string) $entry->published : (string) $entry->updated,
            );
        }
        return $out;
    }

    /**
     * An Atom entry's address: its first link without a rel, or with rel
     * alternate.
     *
     * @param SimpleXMLElement $entry Entry.
     * @return string
     */
    private static function atom_link(SimpleXMLElement $entry) {
        foreach ($entry->link as $link) {
            $rel = (string) $link['rel'];
            if ($rel === '' || $rel === 'alternate') {
                return (string) $link['href'];
            }
        }
        return '';
    }

    /**
     * An update's type from its title, or its product's when the title
     * names none.
     *
     * @param string $title   Title.
     * @param string $product Type from the product (Google), or ''.
     * @return string One of TYPES.
     */
    public static function type($title, $product) {
        $t     = strtolower((string) $title);
        $rules = array(
            'link_spam'       => '/\blink spam\b/',
            'spam'            => '/\bspam\b/',
            'helpful_content' => '/\bhelpful content\b/',
            'product_reviews' => '/\bproduct reviews?\b/',
            'reviews'         => '/\breviews? (system )?update\b/',
            'site_reputation' => '/\bsite reputation\b/',
            'core'            => '/\bcore (algorithm )?updates?\b/',
            'discover'        => '/\bdiscover\b/',
        );
        foreach ($rules as $type => $pattern) {
            if (preg_match($pattern, $t)) {
                return $type;
            }
        }
        if (in_array($product, array('crawling', 'indexing', 'serving'), true)) {
            return $product;
        }
        if (preg_match('/\bcrawl/', $t)) {
            return 'crawling';
        }
        if (preg_match('/\bindex(ing)?\b/', $t)) {
            return 'indexing';
        }
        return $product === 'ranking' || preg_match('/\branking\b/', $t) ? 'ranking' : 'announcement';
    }

    /**
     * Add the source's new updates to the change log and bring stored ones
     * up to date (an end, a new title). Each update is found by its
     * engine and id (object_type and new).
     *
     * @param array{url:string,engine:string,name:string,format:string} $source  Source.
     * @param array<int,array<string,mixed>>                            $entries Entries.
     * @return array{added:int,updated:int}|WP_Error
     */
    private static function store(array $source, array $entries) {
        global $wpdb;
        $before = SEOProStats_Schema::use_set('live');
        try {
            if (!SEOProStats_Schema::maybe_upgrade()) {
                return new WP_Error('seoprostats_search_updates_tables', __('The statistics tables are not ready.', 'seoprostats'));
            }
            $table = SEOProStats_Schema::table('changes');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by key kind_ts.
            $rows  = $wpdb->get_results($wpdb->prepare('SELECT id, ts, new, meta FROM %i WHERE kind = %d AND ts >= %d AND object_type = %s', $table, SEOProStats_Changes::SEARCH_UPDATE, time() - self::SINCE - DAY_IN_SECONDS, $source['engine']), ARRAY_A);
            $have  = array();
            foreach (is_array($rows) ? $rows : array() as $row) {
                $have[(string) $row['new']] = $row;
            }
            $out = array('added' => 0, 'updated' => 0);
            foreach ($entries as $entry) {
                $meta = self::entry_meta($source, $entry);
                $id   = (string) $entry['id'];
                if (isset($have[$id])) {
                    if (self::update_entry($table, $have[$id], $entry, $meta)) {
                        $out['updated']++;
                    }
                    continue;
                }
                $added = SEOProStats_Changes::record(SEOProStats_Changes::SEARCH_UPDATE, array(
                    'ts'          => (int) $entry['started'],
                    'object_type' => $source['engine'],
                    'old'         => (string) $entry['type'],
                    'new'         => $id,
                    'meta'        => $meta,
                    'source'      => 5,
                    'user_id'     => 0,
                ));
                if ($added) {
                    $have[$id] = array('id' => 0, 'ts' => $entry['started'], 'new' => $id, 'meta' => '');
                    $out['added']++;
                }
            }
            return $out;
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * The meta a change keeps for an update: name, engine, address, the
     * end for incidents with a span, and the feed it came from.
     *
     * @param array{url:string,engine:string,name:string,format:string} $source Source.
     * @param array<string,mixed>                                       $entry  Entry.
     * @return array<string,mixed>
     */
    private static function entry_meta(array $source, array $entry) {
        $meta = array(
            'name'   => $entry['title'],
            'engine' => $source['name'],
            'url'    => $entry['url'],
        );
        if ($entry['span']) {
            $meta['ended'] = $entry['ended'] ? gmdate('c', $entry['ended']) : '';
        }
        if ($source['format'] === 'feed') {
            $meta['feed'] = $source['url'];
        }
        return $meta;
    }

    /**
     * Bring a stored update up to date, if its start or meta changed.
     *
     * @param string              $table The changes table.
     * @param array<string,mixed> $have  The stored row: id, ts, new, meta.
     * @param array<string,mixed> $entry Entry.
     * @param array<string,mixed> $meta  entry_meta().
     * @return bool Whether it was updated.
     */
    private static function update_entry($table, array $have, array $entry, array $meta) {
        global $wpdb;
        $old = json_decode((string) $have['meta'], true);
        if ($old === $meta && (int) $have['ts'] === (int) $entry['started']) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $wpdb->update(
            $table,
            array(
                'ts'   => (int) $entry['started'],
                'old'  => (string) $entry['type'],
                'meta' => (string) wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ),
            array('id' => (int) $have['id']),
            array('%d', '%s', '%s'),
            array('%d')
        );
        return true;
    }

    /**
     * The last run: last (Unix time; 0 never) and each source's answer.
     *
     * @return array{last:int,sources:array<int,array<string,mixed>>}
     */
    public static function state() {
        $state = get_option(self::STATE_OPTION, array());
        $state = is_array($state) ? $state : array();
        return array(
            'last'    => isset($state['last']) ? (int) $state['last'] : 0,
            'sources' => isset($state['sources']) && is_array($state['sources']) ? $state['sources'] : array(),
        );
    }

    /**
     * One line of plain text from a feed: no tags, entities or extra
     * spaces, cut to the column's size.
     *
     * @param mixed $value Text.
     * @return string
     */
    private static function text($value) {
        $text = html_entity_decode(wp_strip_all_tags(is_scalar($value) ? (string) $value : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (strlen($text) > SEOProStats_Changes::MAX_VALUE) {
            $text = function_exists('mb_strcut') ? mb_strcut($text, 0, SEOProStats_Changes::MAX_VALUE, 'UTF-8') : substr($text, 0, SEOProStats_Changes::MAX_VALUE);
        }
        return $text;
    }
}
