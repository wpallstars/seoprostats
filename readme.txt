=== SEO Pro Stats ===
Contributors: wpallstars
Donate link: https://buymeacoffee.com/marcusquinn
Tags: starter, boilerplate, settings, developer, template
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Privacy-friendly site statistics in WordPress: visits, pages, sources and goals, kept in your own database without cookies or outside services.

== Description ==

SEO Pro Stats is what wpallstars plugins are made from. It has no features of its own: it holds the parts every plugin needs, so a new plugin starts with them working.

* **A settings screen** (SEO Pro Stats → Settings) that features fill by declaring their settings, saved instantly, searchable, in tabs: Tracking (what is collected), Privacy (Do Not Track, excluded addresses and pages) and Data (how long visits are kept, who can see the statistics).
* **A Read Me tab** that shows the plugin's README.md, banner included.
* **Features as classes**, off by default, with settings, hooks, one-off imports from the plugins they replace and clean uninstall.
* **Release and check scripts**: lint, smoke test, release build, preflight and Plugin Check.

Start a plugin from it on GitHub (wpallstars/seoprostats): the Read Me tab explains how.

= Built with AI =

SEO Pro Stats is built and maintained with aidevops (https://aidevops.sh), the same developer's open-source AI harness for creating and managing anything online with AI.

Questions about using or changing SEO Pro Stats: ask aidevops. It reads the plugin's docs and code to answer, and can report a problem for you.

Made from WP Plugin Starter (https://github.com/wpallstars/wp-plugin-starter-template-for-ai-coding), the wpallstars starter plugin.

== Installation ==

1. Upload the `seoprostats` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate it, then open SEO Pro Stats in the admin menu, under Dashboard. Its last item is Settings.

== Frequently Asked Questions ==

= Where are the statistics? =

SEO Pro Stats in the admin menu, under Dashboard, and a widget on the Dashboard. They need visits: open the site logged out, or in a private window, and they show within a minute or two.

= Can I see what it shows before my site has visits? =

Yes. Switch on Demo data on the Overview: an administrator can make a little over a year of made-up visits there. They are kept in tables of their own, apart from your live statistics, and can be removed at any time.

= Where do I get help? =

Ask aidevops (https://aidevops.sh): open the plugin's repository, or your site, with it and ask. To report a problem, use the Support link on the settings screen.

= Does it contact other services? =

No. The WordPress.org build contacts nothing outside WordPress.

= When do versions reach WordPress.org? =

GitHub releases are the stable beta channel: each version comes out there first. WordPress.org gets it 30 days later, except security releases, which come out on both at once.

== Screenshots ==

1. The settings screen (SEO Pro Stats → Settings) on the Tracking tab.
2. The Read Me tab, showing the plugin's README.md inside WordPress.

== Changelog ==

= 0.1.0 =
* First version, made from WP Plugin Starter 1.0.24.

Every change: changelog.txt.
