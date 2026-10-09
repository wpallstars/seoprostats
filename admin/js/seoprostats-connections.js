/**
 * Settings → Connections: the buttons of each source's card call the
 * REST routes (/seoprostats/v1/connections), then reload the tab, which
 * PHP draws from the source's status (SEOProStats_Connections_Tab).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.apiFetch) {
		return;
	}

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var base = '/seoprostats/v1/';

	function speak(message, politeness) {
		if (wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(message, politeness || 'polite');
		}
	}

	function field(card, name) {
		return card.querySelector('[data-spst-field="' + name + '"]');
	}

	function show(card, text, kind) {
		var box = card.querySelector('[data-spst-message]');
		box.textContent = '';
		if (!text) {
			return;
		}
		var notice = document.createElement('div');
		notice.className = 'notice inline notice-' + (kind || 'error');
		var p = document.createElement('p');
		p.textContent = text;
		notice.appendChild(p);
		box.appendChild(notice);
		speak(text, kind === 'error' ? 'assertive' : 'polite');
	}

	// A property the service account can read, to choose from when the
	// site's own was not found.
	function offerProperties(card, properties) {
		var input = field(card, 'property');
		if (!input || !properties || !properties.length) {
			return;
		}
		var select = document.createElement('select');
		select.id = input.id;
		select.setAttribute('data-spst-field', 'property');
		var none = document.createElement('option');
		none.value = '';
		none.textContent = card.getAttribute('data-spst-connection') === 'bing'
			? __('Choose a site', 'seoprostats')
			: __('Choose a property', 'seoprostats');
		select.appendChild(none);
		properties.forEach(function (property) {
			var option = document.createElement('option');
			option.value = property;
			option.textContent = property;
			select.appendChild(option);
		});
		input.parentNode.replaceChild(select, input);
		select.focus();
	}

	function busy(card, button, on) {
		card.querySelectorAll('button').forEach(function (b) {
			b.disabled = on;
		});
		if (on) {
			button.setAttribute('aria-busy', 'true');
		} else {
			button.removeAttribute('aria-busy');
		}
	}

	function request(card, button, options, done) {
		busy(card, button, true);
		show(card, '', '');
		speak(__('Working…', 'seoprostats'));
		wp.apiFetch(options)
			.then(function (answer) {
				done(answer);
			})
			.catch(function (error) {
				busy(card, button, false);
				show(card, (error && error.message) || __('That did not work. Please try again.', 'seoprostats'), 'error');
				if (error && error.data && error.data.properties) {
					offerProperties(card, error.data.properties);
				}
			});
	}

	function reload(card, message) {
		show(card, message, 'success');
		window.setTimeout(function () {
			window.location.reload();
		}, 800);
	}

	var actions = {
		connect: function (card, button, source) {
			// After Sign in with Google, the fields are in its own block.
			var scope = button.closest('[data-spst-google-ready]') || card;
			var google = button.getAttribute('data-spst-google') === '1';
			var key = google ? null : field(scope, 'key');
			var property = field(scope, 'property');
			request(card, button, {
				path: base + 'connections/' + source,
				method: 'POST',
				data: {
					google: google,
					key: key ? key.value : '',
					property: property ? property.value : ''
				}
			}, function () {
				if (key) {
					key.value = '';
				}
				reload(card, __('Connected. The import starts in the background.', 'seoprostats'));
			});
		},

		import: function (card, button, source) {
			request(card, button, {
				path: base + 'connections/' + source + '/import',
				method: 'POST'
			}, function (answer) {
				var run = answer && answer.run ? answer.run : { days: 0, rows: 0 };
				var message = __('Nothing new to import: every final day is in.', 'seoprostats');
				if (run.days) {
					/* translators: 1: number of days, 2: number of rows */
					message = sprintf(__('%1$d days imported (%2$d rows).', 'seoprostats'), run.days, run.rows);
				} else if (run.pages) {
					/* translators: 1: number of pages, 2: number of rows */
					message = sprintf(__('The search queries of %1$d pages imported (%2$d rows).', 'seoprostats'), run.pages, run.rows);
				}
				reload(card, message);
			});
		},

		undo: function (card, button) {
			var id = button.getAttribute('data-spst-import');
			/* translators: %s: import number */
			if (!window.confirm(sprintf(__('Delete the search data import #%s added?', 'seoprostats'), id))) {
				return;
			}
			request(card, button, {
				path: base + 'imports/' + id,
				method: 'DELETE'
			}, function (answer) {
				/* translators: %d: number of rows */
				reload(card, sprintf(__('Undone: %d rows deleted.', 'seoprostats'), answer.deleted));
			});
		},

		disconnect: function (card, button, source) {
			var remove = field(card, 'delete_data');
			var deleting = !!(remove && remove.checked);
			if (deleting && !window.confirm(__('Disconnect and delete the imported search data?', 'seoprostats'))) {
				return;
			}
			request(card, button, {
				path: base + 'connections/' + source + (deleting ? '?delete_data=1' : ''),
				method: 'DELETE'
			}, function () {
				reload(card, __('Disconnected.', 'seoprostats'));
			});
		}
	};

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-spst-action]');
		var card = button ? button.closest('[data-spst-connection]') : null;
		var action = button ? actions[button.getAttribute('data-spst-action')] : null;
		if (!card || !action) {
			return;
		}
		event.preventDefault();
		action(card, button, card.getAttribute('data-spst-connection'));
	});

	// Back from Sign in with Google: finish connecting at once. When the
	// site's property is not found, the error offers the account's ones.
	function finishSignIn() {
		var ready = document.querySelector('[data-spst-google-ready] [data-spst-action="connect"]');
		if (ready) {
			actions.connect(ready.closest('[data-spst-connection]'), ready, 'search-console');
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', finishSignIn);
	} else {
		finishSignIn();
	}
})(window.wp);
