/**
 * The colour mode button, right of Buy me a coffee in the header of the
 * plugin's screens: Light, Dark or System, saved for the person (user
 * meta, as WordPress saves the admin colour scheme) through admin-ajax
 * (SEOProStats_Admin_Theme::save()).
 *
 * The screen's <head> has already set the mode's classes on <html>
 * (SEOProStats_Admin_Theme::head()); this draws the button and its menu,
 * switches the classes at once, saves, and puts the mode back if saving
 * fails. Charts redraw on the `spst-themechange` event, sent on <document>
 * whenever dark turns on or off, including System following the computer.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 */
(function (wp, data) {
	'use strict';

	if (!wp || !wp.i18n || !data) {
		return;
	}

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var root = document.documentElement;
	var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
	var svgNs = 'http://www.w3.org/2000/svg';

	var modes = {
		light: {
			label: __('Light', 'seoprostats'),
			icon: 'M12 7.75a4.25 4.25 0 1 0 0 8.5 4.25 4.25 0 0 0 0-8.5zM11.25 2h1.5v3h-1.5zm0 17h1.5v3h-1.5zM2 11.25h3v1.5H2zm17 0h3v1.5h-3zM4.4 5.46 5.46 4.4l2.12 2.12-1.06 1.06zm12.02 12.02 1.06-1.06 2.12 2.12-1.06 1.06zM4.4 18.54l2.12-2.12 1.06 1.06-2.12 2.12zM16.42 6.52l2.12-2.12 1.06 1.06-2.12 2.12z'
		},
		dark: {
			label: __('Dark', 'seoprostats'),
			icon: 'M20.5 14.6A8.5 8.5 0 0 1 9.4 3.5a8.5 8.5 0 1 0 11.1 11.1z'
		},
		system: {
			label: __('System', 'seoprostats'),
			icon: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm0 1.5v15a7.5 7.5 0 0 1 0-15z'
		}
	};
	var order = ['light', 'dark', 'system'];
	var mode = modes[data.mode] ? data.mode : 'light';
	var dark = root.classList.contains('spst-dark');
	var toggle;
	var menu;
	var items = [];

	function speak(message, politeness) {
		if (wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(message, politeness || 'polite');
		}
	}

	function icon(path) {
		var svg = document.createElementNS(svgNs, 'svg');
		var shape = document.createElementNS(svgNs, 'path');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		shape.setAttribute('d', path);
		shape.setAttribute('fill-rule', 'evenodd');
		svg.appendChild(shape);
		return svg;
	}

	function isDark(which) {
		return which === 'dark' || (which === 'system' && !!media && media.matches);
	}

	// Classes on <html>, the button's icon and name, the menu's tick; tell the charts.
	function apply(which) {
		var nowDark = isDark(which);
		mode = which;
		order.forEach(function (name) {
			root.classList.toggle('spst-theme-' + name, name === which);
		});
		root.classList.toggle('spst-dark', nowDark);
		if (toggle) {
			/* translators: %s: the colour mode, Light, Dark or System. */
			var name = sprintf(__('Colour mode: %s', 'seoprostats'), modes[which].label);
			toggle.replaceChild(icon(modes[which].icon), toggle.firstChild);
			toggle.lastChild.textContent = name;
			toggle.title = name;
		}
		items.forEach(function (item) {
			item.setAttribute('aria-checked', item.getAttribute('data-spst-mode') === which ? 'true' : 'false');
		});
		if (nowDark !== dark) {
			dark = nowDark;
			document.dispatchEvent(new CustomEvent('spst-themechange', { detail: { mode: which, dark: nowDark } }));
		}
	}

	function save(which, previous) {
		var body = new URLSearchParams();
		body.append('action', data.action);
		body.append('nonce', data.nonce);
		body.append('mode', which);
		window.fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				return response.json();
			})
			.then(function (answer) {
				if (!answer || !answer.success) {
					throw new Error('not saved');
				}
			})
			.catch(function () {
				apply(previous);
				speak(__('The colour mode could not be saved. Please try again.', 'seoprostats'), 'assertive');
			});
	}

	function choose(which) {
		var previous = mode;
		close(true);
		if (which === previous) {
			return;
		}
		apply(which);
		/* translators: %s: the colour mode, Light, Dark or System. */
		speak(sprintf(__('Colour mode: %s', 'seoprostats'), modes[which].label));
		save(which, previous);
		document.dispatchEvent(new CustomEvent('wpallstars-admin-theme', { detail: { mode: which, source: 'seoprostats' } }));
	}

	function open() {
		menu.hidden = false;
		toggle.setAttribute('aria-expanded', 'true');
		var checked = items.filter(function (item) {
			return item.getAttribute('aria-checked') === 'true';
		})[0];
		(checked || items[0]).focus();
	}

	function close(focusToggle) {
		if (!menu || menu.hidden) {
			return;
		}
		menu.hidden = true;
		toggle.setAttribute('aria-expanded', 'false');
		if (focusToggle) {
			toggle.focus();
		}
	}

	function move(step) {
		var at = items.indexOf(document.activeElement);
		items[(at + step + items.length) % items.length].focus();
	}

	function onMenuKey(event) {
		switch (event.key) {
			case 'Tab':
				close(false);
				return;
			case 'ArrowDown':
				move(1);
				break;
			case 'ArrowUp':
				move(-1);
				break;
			case 'Home':
				items[0].focus();
				break;
			case 'End':
				items[items.length - 1].focus();
				break;
			case 'Escape':
				close(true);
				break;
			default:
				return;
		}
		event.preventDefault();
	}

	function build() {
		var actions = document.querySelector('.spst-header__actions');
		var header = document.querySelector('.spst-header');
		if (!actions && header) {
			actions = document.createElement('div');
			actions.className = 'spst-header__actions';
			header.appendChild(actions);
		}
		if (!actions) {
			return;
		}

		var wrap = document.createElement('div');
		wrap.className = 'spst-theme';

		toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'button spst-header__support spst-theme__toggle';
		toggle.setAttribute('aria-haspopup', 'true');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.setAttribute('aria-controls', 'spst-theme-menu');
		toggle.appendChild(icon(modes[mode].icon));
		var name = document.createElement('span');
		name.className = 'screen-reader-text';
		toggle.appendChild(name);

		menu = document.createElement('div');
		menu.id = 'spst-theme-menu';
		menu.className = 'spst-theme__menu';
		menu.setAttribute('role', 'menu');
		menu.setAttribute('aria-label', __('Colour mode', 'seoprostats'));
		menu.hidden = true;

		order.forEach(function (which) {
			var item = document.createElement('button');
			item.type = 'button';
			item.className = 'spst-theme__item';
			item.tabIndex = -1;
			item.setAttribute('role', 'menuitemradio');
			item.setAttribute('data-spst-mode', which);
			item.appendChild(icon(modes[which].icon));
			item.appendChild(document.createTextNode(modes[which].label));
			var check = document.createElement('span');
			check.className = 'dashicons dashicons-yes spst-theme__check';
			check.setAttribute('aria-hidden', 'true');
			item.appendChild(check);
			item.addEventListener('click', function () {
				choose(which);
			});
			items.push(item);
			menu.appendChild(item);
		});

		toggle.addEventListener('click', function () {
			if (menu.hidden) {
				open();
			} else {
				close(true);
			}
		});
		toggle.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowDown' && menu.hidden) {
				event.preventDefault();
				open();
			}
		});
		menu.addEventListener('keydown', onMenuKey);
		document.addEventListener('click', function (event) {
			if (!wrap.contains(event.target)) {
				close(false);
			}
		});

		wrap.appendChild(toggle);
		wrap.appendChild(menu);
		var donate = actions.querySelector('.spst-header__donate');
		actions.insertBefore(wrap, donate ? donate.nextSibling : null);
		apply(mode);
	}

	// System follows the computer while the screen is open.
	if (media) {
		var follow = function () {
			if (mode === 'system') {
				apply('system');
			}
		};
		if (media.addEventListener) {
			media.addEventListener('change', follow);
		} else if (media.addListener) {
			media.addListener(follow);
		}
	}

	// Another control already saves and announces; only update this screen.
	document.addEventListener('wpallstars-admin-theme', function (event) {
		var detail = event.detail;
		if (!detail || detail.source === 'seoprostats' || order.indexOf(detail.mode) === -1) {
			return;
		}
		apply(detail.mode);
	});

	function start() {
		build();
		// Colours ease between modes from now on, not while the page draws.
		window.requestAnimationFrame(function () {
			root.classList.add('spst-theme-ready');
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
}(window.wp, window.seoprostatsTheme));
