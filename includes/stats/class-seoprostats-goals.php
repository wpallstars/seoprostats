<?php
/**
 * Goal and funnel definitions: what counts as a conversion, and the steps
 * of a funnel. SEOProStats_Conversions reports on them.
 *
 * A goal is a page (a path, `*` for any text) or an event (a name, `*`
 * for any text). Event goals add up the revenue their events carry, per
 * currency. A funnel is 2 to 12 such steps, reached in order within one
 * visit.
 *
 * Definitions are small option arrays with autoload off, one pair per data
 * set (SEOProStats_Schema::option()), so the demo data has goals of its
 * own and removing it removes them (docs/architecture.md → Storage).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Goals {

    /** Option holding the goals (live; demo adds _demo). */
    const GOALS_OPTION = 'seoprostats_goals';

    /** Option holding the funnels (live; demo adds _demo). */
    const FUNNELS_OPTION = 'seoprostats_funnels';

    /** What a goal or step matches. */
    const KINDS = array('page', 'event');

    /** Most goals. */
    const MAX_GOALS = 50;

    /** Most funnels. */
    const MAX_FUNNELS = 20;

    /** Fewest and most steps in a funnel. */
    const MIN_STEPS = 2;
    const MAX_STEPS = 12;

    /** Longest name, and longest page or event to match. */
    const MAX_NAME  = 100;
    const MAX_MATCH = 300;

    /**
     * The goals of the current data set.
     *
     * @return array<int,array{id:string,name:string,kind:string,match:string}>
     */
    public static function goals() {
        $out = array();
        foreach (self::read(self::GOALS_OPTION) as $item) {
            $step = self::stored_step($item);
            if ($step) {
                $out[] = array('id' => (string) $item['id']) + $step;
            }
        }
        return $out;
    }

    /**
     * The funnels of the current data set.
     *
     * @return array<int,array{id:string,name:string,steps:array<int,array{name:string,kind:string,match:string}>}>
     */
    public static function funnels() {
        $out = array();
        foreach (self::read(self::FUNNELS_OPTION) as $item) {
            $steps = array();
            foreach (isset($item['steps']) && is_array($item['steps']) ? $item['steps'] : array() as $step) {
                $step = is_array($step) ? self::stored_step($step) : null;
                if ($step) {
                    $steps[] = $step;
                }
            }
            if (count($steps) >= self::MIN_STEPS) {
                $out[] = array(
                    'id'    => (string) $item['id'],
                    'name'  => (string) $item['name'],
                    'steps' => $steps,
                );
            }
        }
        return $out;
    }

    /**
     * A stored goal or step as its fields, or null when it is not one.
     *
     * @param array<string,mixed> $item Stored entry.
     * @return array{name:string,kind:string,match:string}|null
     */
    private static function stored_step(array $item) {
        if (!isset($item['name'], $item['kind'], $item['match']) || !in_array($item['kind'], self::KINDS, true) || !is_scalar($item['match'])) {
            return null;
        }
        return array(
            'name'  => (string) $item['name'],
            'kind'  => (string) $item['kind'],
            'match' => (string) $item['match'],
        );
    }

    /**
     * One goal or funnel by id, or null.
     *
     * @param string $type goals or funnels.
     * @param string $id   Id.
     * @return array<string,mixed>|null
     */
    public static function find($type, $id) {
        foreach ($type === 'funnels' ? self::funnels() : self::goals() as $item) {
            if ($item['id'] === (string) $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Add a goal, or change one when the id is given.
     *
     * @param array<string,mixed> $input name, kind, match.
     * @param string              $id    Goal to change, or '' to add.
     * @return array<string,mixed>|WP_Error The goal saved.
     */
    public static function save_goal(array $input, $id = '') {
        $input = self::with_stored('goals', $input, (string) $id);
        $step  = self::clean_step($input, true);
        if (is_wp_error($step)) {
            return $step;
        }
        return self::store(self::GOALS_OPTION, $step, (string) $id, self::MAX_GOALS);
    }

    /**
     * Add a funnel, or change one when the id is given.
     *
     * @param array<string,mixed> $input name, steps (list of name, kind, match).
     * @param string              $id    Funnel to change, or '' to add.
     * @return array<string,mixed>|WP_Error The funnel saved.
     */
    public static function save_funnel(array $input, $id = '') {
        $input = self::with_stored('funnels', $input, (string) $id);
        $name  = self::clean_name(isset($input['name']) ? $input['name'] : '');
        if ($name === '') {
            return self::error(__('Give the funnel a name.', 'seoprostats'));
        }
        $raw = isset($input['steps']) && is_array($input['steps']) ? array_values($input['steps']) : array();
        if (count($raw) < self::MIN_STEPS || count($raw) > self::MAX_STEPS) {
            /* translators: 1: fewest steps, 2: most steps */
            return self::error(sprintf(__('A funnel has %1$d to %2$d steps.', 'seoprostats'), self::MIN_STEPS, self::MAX_STEPS));
        }
        $steps = array();
        foreach ($raw as $step) {
            $clean = self::clean_step(is_array($step) ? $step : array(), false);
            if (is_wp_error($clean)) {
                return $clean;
            }
            $steps[] = $clean;
        }
        return self::store(self::FUNNELS_OPTION, array('name' => $name, 'steps' => $steps), (string) $id, self::MAX_FUNNELS);
    }

    /**
     * Delete a goal or funnel.
     *
     * @param string $type goals or funnels.
     * @param string $id   Id.
     * @return bool Whether it was there.
     */
    public static function delete($type, $id) {
        $option = $type === 'funnels' ? self::FUNNELS_OPTION : self::GOALS_OPTION;
        $items  = self::read($option);
        $kept   = array_values(array_filter($items, static function ($item) use ($id) {
            return $item['id'] !== (string) $id;
        }));
        if (count($kept) === count($items)) {
            return false;
        }
        update_option(SEOProStats_Schema::option($option), $kept, false);
        return true;
    }

    /**
     * Replace every goal and funnel of the current data set (the demo
     * data's examples). Each is checked as save_*() checks it.
     *
     * @param array<int,array<string,mixed>> $goals   Goals.
     * @param array<int,array<string,mixed>> $funnels Funnels.
     */
    public static function replace(array $goals, array $funnels) {
        self::forget();
        foreach ($goals as $goal) {
            self::save_goal($goal);
        }
        foreach ($funnels as $funnel) {
            self::save_funnel($funnel);
        }
    }

    /**
     * Delete the current data set's goals and funnels (uninstall; removing
     * the demo data).
     */
    public static function forget() {
        delete_option(SEOProStats_Schema::option(self::GOALS_OPTION));
        delete_option(SEOProStats_Schema::option(self::FUNNELS_OPTION));
    }

    /**
     * Input for a change: fields not given keep their stored values, so a
     * change can name only what changes.
     *
     * @param string              $type  goals or funnels.
     * @param array<string,mixed> $input Input.
     * @param string              $id    Entry to change, or '' to add.
     * @return array<string,mixed>
     */
    private static function with_stored($type, array $input, $id) {
        $stored = $id !== '' ? self::find($type, $id) : null;
        if (!$stored) {
            return $input;
        }
        return array_merge($stored, array_filter($input, static function ($value) {
            return $value !== null;
        }));
    }

    /**
     * A stored list, keeping only well-formed entries.
     *
     * @param string $option Live option name.
     * @return array<int,array<string,mixed>>
     */
    private static function read($option) {
        $items = get_option(SEOProStats_Schema::option($option), array());
        if (!is_array($items)) {
            return array();
        }
        return array_values(array_filter($items, static function ($item) {
            return is_array($item) && isset($item['id'], $item['name']) && is_string($item['id']);
        }));
    }

    /**
     * Add or change an entry and save the list.
     *
     * @param string              $option Live option name.
     * @param array<string,mixed> $entry  Checked entry, without id.
     * @param string              $id     Entry to change, or '' to add.
     * @param int                 $max    Most entries.
     * @return array<string,mixed>|WP_Error The entry saved.
     */
    private static function store($option, array $entry, $id, $max) {
        $items = self::read($option);
        if ($id !== '') {
            foreach ($items as $i => $item) {
                if ($item['id'] === $id) {
                    $items[$i] = array('id' => $id) + $entry;
                    update_option(SEOProStats_Schema::option($option), $items, false);
                    return $items[$i];
                }
            }
            return new WP_Error('seoprostats_not_found', __('There is no such goal or funnel.', 'seoprostats'), array('status' => 404));
        }
        if (count($items) >= $max) {
            /* translators: %d: most entries */
            return self::error(sprintf(__('There can be at most %d. Delete one first.', 'seoprostats'), $max));
        }
        $entry   = array('id' => self::new_id($items)) + $entry;
        $items[] = $entry;
        update_option(SEOProStats_Schema::option($option), $items, false);
        return $entry;
    }

    /**
     * A goal or funnel step from input: name, kind (page or event) and
     * match. A page is a path from the site root ("/pricing/"; "*" for any
     * text); a full address is cut to its path.
     *
     * @param array<string,mixed> $input     Input.
     * @param bool                $need_name Whether a name is required (goals); steps fall back to the match.
     * @return array{name:string,kind:string,match:string}|WP_Error
     */
    private static function clean_step(array $input, $need_name) {
        $kind = isset($input['kind']) ? (string) $input['kind'] : '';
        if (!in_array($kind, self::KINDS, true)) {
            return self::error(__('Choose whether a page or an event counts.', 'seoprostats'));
        }
        $match = isset($input['match']) && is_scalar($input['match']) ? trim(wp_strip_all_tags((string) $input['match'])) : '';
        if ($kind === 'page' && $match !== '') {
            if (preg_match('~^https?://~i', $match)) {
                $path  = wp_parse_url($match, PHP_URL_PATH);
                $match = is_string($path) && $path !== '' ? $path : '/';
            }
            if ($match[0] !== '/' && $match[0] !== '*') {
                $match = '/' . $match;
            }
        }
        if ($match === '' || strlen($match) > self::MAX_MATCH) {
            return self::error($kind === 'page'
                ? __('Give the page as a path, such as /pricing/ or /blog/*.', 'seoprostats')
                : __('Give the event name, such as Purchase or Newsletter signup.', 'seoprostats'));
        }
        $name = self::clean_name(isset($input['name']) ? $input['name'] : '');
        if ($name === '') {
            if ($need_name) {
                return self::error(__('Give the goal a name.', 'seoprostats'));
            }
            $name = self::clean_name($match);
        }
        return array('name' => $name, 'kind' => $kind, 'match' => $match);
    }

    /**
     * A name: plain text, trimmed and cut to MAX_NAME characters.
     *
     * @param mixed $name Input.
     * @return string
     */
    private static function clean_name($name) {
        if (!is_scalar($name)) {
            return '';
        }
        $name = trim(sanitize_text_field((string) $name));
        return function_exists('mb_substr') ? mb_substr($name, 0, self::MAX_NAME) : substr($name, 0, self::MAX_NAME);
    }

    /**
     * A short id that no entry has.
     *
     * @param array<int,array<string,mixed>> $items Entries.
     * @return string
     */
    private static function new_id(array $items) {
        $taken = array_column($items, 'id');
        do {
            $id = strtolower(wp_generate_password(8, false, false));
        } while (in_array($id, $taken, true));
        return $id;
    }

    /**
     * A 400 error with a message.
     *
     * @param string $message Message.
     * @return WP_Error
     */
    private static function error($message) {
        return new WP_Error('seoprostats_goal', $message, array('status' => 400));
    }
}
