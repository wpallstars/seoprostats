<?php
/**
 * Settings → Shared reports: the accent colour picker in the Report colours
 * card. Swatches come from the site's colour palette (the theme's colours,
 * and colours added in the Site Editor or Customizer), with any other colour
 * from the browser's picker. Each choice saves like other settings: the
 * controls carry data-spst-setting="share_accent".
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Share_Settings {

    /** The accent setting, whose panel holds the picker. */
    const KEY = 'share_accent';

    /** The default accent: WordPress's admin blue. */
    const DEFAULT_ACCENT = '#2271b1';

    /** Most swatches shown. */
    const MAX_SWATCHES = 30;

    /**
     * Register hooks (admin requests only).
     */
    public static function init() {
        add_action('seoprostats_setting_panel', array(__CLASS__, 'panel'), 10, 1);
        add_action('seoprostats_admin_enqueue', array(__CLASS__, 'enqueue'));
    }

    /**
     * A colour as #rrggbb in lower case, or '' when it is not a hex colour.
     *
     * @param mixed $colour Colour.
     * @return string
     */
    public static function hex($colour) {
        $hex = is_string($colour) ? (string) sanitize_hex_color(trim($colour)) : '';
        if (strlen($hex) === 4) {
            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }
        return strtolower($hex);
    }

    /**
     * The site's colours: WordPress's admin blue, then the theme's colours
     * (a classic theme's editor palette, set in its Customizer, and the
     * theme.json palette), then colours added in the Site Editor;
     * WordPress's own palette only when the site has none. Only hex
     * colours: themes that give CSS variables in theme.json (such as
     * Kadence) usually give the real colours in the editor palette. No
     * repeats.
     *
     * @return array<string,string> hex => name
     */
    public static function palette() {
        $all     = function_exists('wp_get_global_settings') ? (array) wp_get_global_settings(array('color', 'palette')) : array();
        $support = get_theme_support('editor-color-palette');
        $own     = array_merge(
            is_array($support) && isset($support[0]) && is_array($support[0]) ? $support[0] : array(),
            (array) ($all['theme'] ?? array()),
            (array) ($all['custom'] ?? array())
        );
        $out = self::add_colours(array(), $own);
        if (!$out) {
            $out = self::add_colours(array(), (array) ($all['default'] ?? array()));
        }
        $out = self::add_colours(array(self::DEFAULT_ACCENT => __('WordPress blue', 'seoprostats')), array_map(static function ($hex, $name) {
            return array('color' => $hex, 'name' => $name);
        }, array_keys($out), $out));

        /**
         * Filter the colours offered for shared reports' accent, for a theme
         * that keeps its colours elsewhere.
         *
         * @param array<string,string> $out hex (#rrggbb) => name.
         */
        $filtered = (array) apply_filters('seoprostats_share_palette', $out);
        return array_slice(self::add_colours(array(), array_map(static function ($hex, $name) {
            return array('color' => $hex, 'name' => $name);
        }, array_keys($filtered), $filtered)), 0, self::MAX_SWATCHES, true);
    }

    /**
     * Add palette entries (color, name) that are hex colours not yet in the list.
     *
     * @param array<string,string> $out     hex => name.
     * @param array                $entries Palette entries.
     * @return array<string,string>
     */
    private static function add_colours(array $out, array $entries) {
        foreach ($entries as $entry) {
            $hex = self::hex(is_array($entry) ? ($entry['color'] ?? '') : '');
            if ($hex === '' || isset($out[$hex])) {
                continue;
            }
            $name      = is_array($entry) && is_scalar($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
            $out[$hex] = $name !== '' ? $name : $hex;
        }
        return $out;
    }

    /**
     * Draw the picker at the top of the Report colours panel.
     *
     * @param string $key Setting key whose panel is drawn.
     */
    public static function panel($key) {
        if ($key !== self::KEY) {
            return;
        }
        $value = self::hex(SEOProStats_Settings::get(self::KEY));
        $value = $value !== '' ? $value : self::DEFAULT_ACCENT;
        $id    = 'spst-' . self::KEY;
        ?>
        <div class="spst-field spst-colour">
            <span class="spst-field__label" id="<?php echo esc_attr($id); ?>-label"><?php esc_html_e('Accent colour', 'seoprostats'); ?></span>
            <div class="spst-field__control">
                <fieldset class="spst-swatches" aria-labelledby="<?php echo esc_attr($id); ?>-label" aria-describedby="<?php echo esc_attr($id); ?>-desc">
                    <?php foreach (self::palette() as $hex => $name) : ?>
                        <label class="spst-swatch" title="<?php echo esc_attr($name . ' ' . $hex); ?>">
                            <input type="radio" name="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($hex); ?>" data-spst-setting="<?php echo esc_attr(self::KEY); ?>"<?php checked($value, $hex); ?> />
                            <span class="spst-swatch__colour" style="background-color: <?php echo esc_attr($hex); ?>" aria-hidden="true"></span>
                            <span class="screen-reader-text"><?php echo esc_html($name . ' ' . $hex); ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <label class="spst-colour__other">
                    <input type="color" value="<?php echo esc_attr($value); ?>" data-spst-setting="<?php echo esc_attr(self::KEY); ?>" />
                    <?php esc_html_e('Other colour', 'seoprostats'); ?>
                </label>
                <p class="description" id="<?php echo esc_attr($id); ?>-desc"><?php esc_html_e('The site\'s colours from its theme, Site Editor or Customizer. A colour too pale or too dark to read on the report is swapped for a readable one.', 'seoprostats'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * The picker's styles and the script that keeps its swatches and other
     * colour in step; once, for whichever settings tab is drawn first (the
     * card can also show in search results).
     */
    public static function enqueue() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        wp_add_inline_style('seoprostats-admin', '.spst-swatches{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;border:0}.spst-swatch{position:relative;display:block;width:28px;height:28px;cursor:pointer}.spst-swatch input{position:absolute;inset:0;margin:0;opacity:0;cursor:pointer}.spst-swatch__colour{display:block;width:100%;height:100%;border-radius:50%;box-shadow:inset 0 0 0 1px rgba(0,0,0,.2)}.spst-swatch input:checked+.spst-swatch__colour{box-shadow:inset 0 0 0 1px rgba(0,0,0,.2),0 0 0 2px #fff,0 0 0 4px #1d2327}.spst-swatch input:focus-visible+.spst-swatch__colour{outline:2px solid var(--spst-accent);outline-offset:4px}.spst-colour__other{display:inline-flex;gap:8px;align-items:center;margin-top:12px}.spst-colour__other input{width:40px;height:28px;padding:0 2px;cursor:pointer}');
        wp_add_inline_script('seoprostats-admin', 'jQuery(function($){$(document).on("change",".spst-colour input[type=radio]",function(){$(this).closest(".spst-colour").find("input[type=color]").val(this.value);});$(document).on("change",".spst-colour input[type=color]",function(){var value=String(this.value).toLowerCase();$(this).closest(".spst-colour").find("input[type=radio]").each(function(){this.checked=this.value===value;});});});');
    }
}
