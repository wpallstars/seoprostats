/**
 * Settings → Import: the dry run, import (with its progress), undo and
 * removing leftover data call the REST routes (/seoprostats/v1/migrate and
 * /imports), then reload the tab, which PHP draws from
 * SEOProStats_Migrate::status() (SEOProStats_Import_Tab).
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
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var base = '/seoprostats/v1/';
	var root = document.querySelector('[data-spst-import-root]');

	if (!root) {
		return;
	}

	var linksForm = root.querySelector('[data-spst-links-form]');
	var linksStatus = root.querySelector('[data-spst-links-status]');
	var linksProgress = root.querySelector('[data-spst-links-progress]');
	function showLinks(job) {
		linksStatus.textContent = job.status + ': ' + number(job.done) + '/' + number(job.total) + ' · ' + number(job.accepted) + ' ' + __('accepted', 'seoprostats') + ' · ' + number(job.skipped) + ' ' + __('skipped', 'seoprostats');
		linksProgress.max = Math.max(1, job.total);
		linksProgress.value = job.done;
		linksForm.querySelector('button').disabled = job.status === 'running';
		if (job.status === 'running') {
			window.setTimeout(pollLinks, 5000);
		}
	}
	function pollLinks() {
		wp.apiFetch({ path: base + 'backlinks/import' }).then(showLinks).catch(function (error) {
			linksStatus.textContent = error.message || __('The links import status could not be read.', 'seoprostats');
			linksForm.querySelector('button').disabled = false;
		});
	}
	if (linksForm) {
		linksForm.addEventListener('submit', function (event) {
			event.preventDefault();
			var file = linksForm.querySelector('input').files[0];
			if (!file) { return; }
			var body = new FormData();
			body.append('file', file);
			linksForm.querySelector('button').disabled = true;
			wp.apiFetch({ path: base + 'backlinks/import', method: 'POST', body: body }).then(showLinks).catch(function (error) {
				linksStatus.textContent = error.message || __('The links could not be imported.', 'seoprostats');
				linksForm.querySelector('button').disabled = false;
			});
		});
		if (linksStatus.getAttribute('data-status') === 'running') { pollLinks(); }
	}

	function speak(message, politeness) {
		if (wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(message, politeness || 'polite');
		}
	}

	function number(value) {
		return Number(value || 0).toLocaleString();
	}

	function day(ymd) {
		if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd || '')) {
			return ymd || '';
		}
		return new Date(ymd + 'T12:00:00Z').toLocaleDateString(undefined, { dateStyle: 'medium', timeZone: 'UTC' });
	}

	function range(from, to) {
		return from === to ? day(from) : day(from) + ' – ' + day(to);
	}

	// An element with text (never HTML) and optional class.
	function el(tag, text, className) {
		var node = document.createElement(tag);
		if (text !== undefined && text !== null && text !== '') {
			node.textContent = text;
		}
		if (className) {
			node.className = className;
		}
		return node;
	}

	function show(box, text, kind) {
		box = box || root.querySelector('[data-spst-message]');
		box.textContent = '';
		if (!text) {
			return;
		}
		var notice = el('div', '', 'notice inline notice-' + (kind || 'error'));
		notice.appendChild(el('p', text));
		box.appendChild(notice);
		speak(text, kind === 'error' ? 'assertive' : 'polite');
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
		show(null, '', '');
		speak(__('Working…', 'seoprostats'));
		wp.apiFetch(options)
			.then(function (answer) {
				busy(card, button, false);
				done(answer);
			})
			.catch(function (error) {
				busy(card, button, false);
				show(null, (error && error.message) || __('That did not work. Please try again.', 'seoprostats'), 'error');
			});
	}

	function reload(message) {
		show(null, message, 'success');
		window.setTimeout(function () {
			window.location.reload();
		}, 800);
	}

	// A two-column table: rows of [label, value] (or [label, value, value]).
	function table(head, rows) {
		var wrap = el('div', '', 'spst-import__scroll');
		var t = el('table', '', 'widefat striped');
		var thead = el('thead');
		var tr = el('tr');
		head.forEach(function (label, i) {
			var th = el('th', label, i ? 'num' : '');
			th.scope = 'col';
			tr.appendChild(th);
		});
		thead.appendChild(tr);
		t.appendChild(thead);
		var tbody = el('tbody');
		rows.forEach(function (row) {
			var r = el('tr');
			row.forEach(function (cell, i) {
				var c = el(i ? 'td' : 'th', cell, i ? 'num' : '');
				if (!i) {
					c.scope = 'row';
				}
				r.appendChild(c);
			});
			tbody.appendChild(r);
		});
		t.appendChild(tbody);
		wrap.appendChild(t);
		return wrap;
	}

	var dimensions = {
		site: __('Site totals', 'seoprostats'),
		page: __('Pages', 'seoprostats'),
		entry: __('Entry pages', 'seoprostats'),
		exit: __('Exit pages', 'seoprostats'),
		landing: __('Search landing pages', 'seoprostats'),
		source: __('Referrers', 'seoprostats'),
		channel: __('Channels', 'seoprostats'),
		utm_source: __('Campaign sources', 'seoprostats'),
		utm_medium: __('Campaign media', 'seoprostats'),
		utm_campaign: __('Campaigns', 'seoprostats'),
		utm_term: __('Campaign terms', 'seoprostats'),
		utm_content: __('Campaign content', 'seoprostats'),
		country: __('Countries', 'seoprostats'),
		device: __('Devices', 'seoprostats'),
		browser: __('Browsers', 'seoprostats'),
		os: __('Operating systems', 'seoprostats')
	};

	// What a setting would be after the import: its new value when it is
	// carried over, else why it stays.
	function settingAfter(s, ticked) {
		if (!s.change) {
			return s.reason === 'same' ? __('Already the same', 'seoprostats') : __('Stays: you changed it', 'seoprostats');
		}
		/* translators: %s: the setting's value now */
		return ticked ? s.to : sprintf(__('Stays: %s', 'seoprostats'), s.now);
	}

	// The settings the import would carry over, a row each: a checkbox
	// (when there is an import to carry them), our setting with the
	// plugin's own name for it, its value, ours now and ours after.
	function settingsTable(plan, choose) {
		var wrap = el('div', '', 'spst-import__scroll');
		var t = el('table', '', 'widefat striped spst-import__settings');
		var thead = el('thead');
		var head = el('tr');
		if (choose) {
			var corner = el('td', '', 'check-column');
			corner.appendChild(el('span', __('Carry over', 'seoprostats'), 'screen-reader-text'));
			head.appendChild(corner);
		}
		[__('Setting', 'seoprostats'), plan.name, __('SEO Pro Stats now', 'seoprostats'), __('After the import', 'seoprostats')].forEach(function (label) {
			var th = el('th', label);
			th.scope = 'col';
			head.appendChild(th);
		});
		thead.appendChild(head);
		t.appendChild(thead);

		var tbody = el('tbody');
		plan.settings.forEach(function (s, i) {
			var r = el('tr');
			var id = 'spst-setting-' + plan.source + '-' + i;
			var after = el('td', settingAfter(s, s.change));
			var name = el('td', '', 'spst-import__setting');
			if (choose) {
				var check = el('th', '', 'check-column');
				check.scope = 'row';
				var box = el('input');
				box.type = 'checkbox';
				box.id = id;
				box.value = s.key;
				box.checked = !!s.change;
				box.disabled = !s.change;
				box.setAttribute('data-spst-field', 'setting');
				box.addEventListener('change', function () {
					after.textContent = settingAfter(s, box.checked);
					r.classList.toggle('is-kept', !box.checked);
				});
				check.appendChild(box);
				r.appendChild(check);
				var label = el('label', s.label);
				label.htmlFor = id;
				name.appendChild(label);
			} else {
				name.appendChild(el('strong', s.label));
			}
			/* translators: 1: plugin name, 2: that plugin's name for the setting */
			name.appendChild(el('span', sprintf(__('%1$s: %2$s', 'seoprostats'), plan.name, s.theirs), 'description'));
			r.appendChild(name);
			r.appendChild(el('td', s.from));
			r.appendChild(el('td', s.now));
			r.appendChild(after);
			if (!s.change) {
				r.classList.add('is-kept');
			}
			tbody.appendChild(r);
		});
		t.appendChild(tbody);
		wrap.appendChild(t);
		return wrap;
	}

	// The settings ticked in the dry run (null: no settings table, so the
	// import carries over all it would change, which is none).
	function chosenSettings(card) {
		var box = card.querySelector('[data-spst-plan]');
		var boxes = box.querySelectorAll('[data-spst-field="setting"]');
		if (!boxes.length) {
			return null;
		}
		return Array.prototype.filter.call(boxes, function (b) {
			return b.checked && !b.disabled;
		}).map(function (b) {
			return b.value;
		});
	}

	// The dry run's answer, with the choice of plugin for shared days and
	// the Import button.
	function drawPlan(card, plan) {
		var box = card.querySelector('[data-spst-plan]');
		box.textContent = '';
		var days = plan.import.days;

		box.appendChild(el('h4', __('What the import would do', 'seoprostats')));
		var summary = el('p');
		if (days) {
			/* translators: 1: number of days, 2: the days, such as "1 Mar 2026 – 30 Sep 2026" */
			summary.textContent = sprintf(_n('Add %1$s day: %2$s.', 'Add %1$s days: %2$s.', days, 'seoprostats'), number(days), range(plan.import.from, plan.import.to));
		} else {
			summary.textContent = __('Nothing to add: every day with its statistics is already filled.', 'seoprostats');
		}
		box.appendChild(summary);

		var skipped = [];
		if (plan.skipped.own) {
			/* translators: 1: number of days, 2: a day */
			skipped.push(sprintf(_n('%1$s day skipped: SEO Pro Stats has its own statistics from %2$s.', '%1$s days skipped: SEO Pro Stats has its own statistics from %2$s.', plan.skipped.own, 'seoprostats'), number(plan.skipped.own), day(plan.own_from)));
		}
		Object.keys(plan.skipped.imported || {}).forEach(function (by) {
			var count = plan.skipped.imported[by];
			/* translators: 1: number of days, 2: plugin key */
			skipped.push(sprintf(_n('%1$s day skipped: already imported (%2$s).', '%1$s days skipped: already imported (%2$s).', count, 'seoprostats'), number(count), by));
		});
		if (skipped.length) {
			var list = el('ul', '', 'spst-import__list');
			skipped.forEach(function (line) {
				list.appendChild(el('li', line));
			});
			box.appendChild(list);
		}

		if (days) {
			box.appendChild(el('h4', plan.estimated ? __('Counts (estimated from a few days: its tables are large)', 'seoprostats') : __('Counts', 'seoprostats')));
			box.appendChild(table(
				['', plan.name],
				[
					[__('Pageviews', 'seoprostats'), number(plan.totals.pageviews)],
					[__('Visits', 'seoprostats'), number(plan.totals.visits)],
					[__('Visitors', 'seoprostats'), number(plan.totals.visitors)]
				]
			));
			var rows = Object.keys(plan.rows || {}).map(function (name) {
				return [dimensions[name] || name, number(plan.rows[name])];
			});
			if (rows.length) {
				box.appendChild(el('h4', __('Rows it would add (estimated)', 'seoprostats')));
				box.appendChild(table([__('Report', 'seoprostats'), __('Rows', 'seoprostats')], rows));
			}
		}

		// Other plugins not imported yet, with statistics on the same days.
		var choice = plan.prefer;
		(plan.overlap || []).forEach(function (other) {
			var set = el('fieldset');
			/* translators: 1: plugin name, 2: number of days, 3: the days */
			set.appendChild(el('legend', sprintf(_n('%1$s also has statistics for %2$s of these days (%3$s). Which should fill them?', '%1$s also has statistics for %2$s of these days (%3$s). Which should fill them?', other.days, 'seoprostats'), other.name, number(other.days), range(other.from, other.to))));
			[plan.source, other.source].forEach(function (key) {
				var label = el('label');
				var radio = el('input');
				radio.type = 'radio';
				radio.name = 'spst-prefer-' + plan.source + '-' + other.source;
				radio.value = key;
				radio.setAttribute('data-spst-field', 'prefer');
				radio.checked = key === (plan.prefer !== plan.source ? plan.prefer : other.suggested);
				if (radio.checked) {
					choice = key;
				}
				label.appendChild(radio);
				/* translators: 1: plugin name, 2: number of pageviews */
				label.appendChild(document.createTextNode(' ' + sprintf(__('%1$s (%2$s pageviews on those days)', 'seoprostats'), key === plan.source ? plan.name : other.name, number(other.pageviews[key]))));
				set.appendChild(label);
			});
			set.appendChild(el('p', __('The one chosen is imported first; the other then fills only the days left. To change it later, undo the import and run it again.', 'seoprostats'), 'description'));
			box.appendChild(set);
		});
		box.setAttribute('data-spst-prefer', choice);

		// Settings: only ours still at their default are filled in, and only
		// those ticked.
		if (plan.settings && plan.settings.length) {
			box.appendChild(el('h4', __('Settings', 'seoprostats')));
			box.appendChild(settingsTable(plan, !!days));
			box.appendChild(el('p', days
				/* translators: %s: plugin name */
				? sprintf(__('Tick the settings to carry over with the import. Only SEO Pro Stats settings still at their default can be filled in; %s\'s own settings are never changed.', 'seoprostats'), plan.name)
				/* translators: %s: plugin name */
				: sprintf(__('Settings are carried over with an import; there are no days to import now. %s\'s own settings are never changed.', 'seoprostats'), plan.name), 'description'));
		}

		if (days) {
			var actions = el('div', '', 'spst-import__actions');
			var go = el('button', __('Import', 'seoprostats'), 'button button-primary');
			go.type = 'button';
			go.setAttribute('data-spst-action', 'import');
			actions.appendChild(go);
			actions.appendChild(el('span', __('Runs in the background; each day can be undone with the import.', 'seoprostats'), 'description'));
			box.appendChild(actions);
		}
		box.querySelector('h4').setAttribute('tabindex', '-1');
		box.querySelector('h4').focus();
	}

	function chosen(card) {
		var box = card.querySelector('[data-spst-plan]');
		var picked = box.querySelector('[data-spst-field="prefer"]:checked');
		return picked ? picked.value : (box.getAttribute('data-spst-prefer') || '');
	}

	var kinds = [
		['tables', __('Database tables', 'seoprostats')],
		['options', __('Options', 'seoprostats')],
		['transients', __('Transients', 'seoprostats')],
		['cron', __('Scheduled tasks', 'seoprostats')],
		['user_meta', __('User settings', 'seoprostats')],
		['post_meta', __('Post data', 'seoprostats')],
		['roles', __('User roles', 'seoprostats')],
		['files', __('Files and folders (in wp-content)', 'seoprostats')]
	];

	function drawLeftovers(card, answer) {
		var box = card.querySelector('[data-spst-leftovers]');
		box.textContent = '';
		var list = answer.leftovers || {};
		var any = false;
		kinds.forEach(function (kind) {
			var items = list[kind[0]] || [];
			if (!items.length) {
				return;
			}
			any = true;
			box.appendChild(el('h4', sprintf('%1$s (%2$s)', kind[1], number(items.length))));
			var ul = el('ul', '', 'spst-import__list');
			items.forEach(function (item) {
				var li = el('li');
				li.appendChild(el('code', item));
				ul.appendChild(li);
			});
			box.appendChild(ul);
		});
		var network = list.network || {};
		if (Object.keys(network).length) {
			box.appendChild(el('p', __('Shared by the whole network and left in place (remove them from the network, once no site uses the plugin):', 'seoprostats'), 'description'));
			var nl = el('ul', '', 'spst-import__list');
			Object.keys(network).forEach(function (kind) {
				network[kind].forEach(function (item) {
					var li = el('li');
					li.appendChild(el('code', item));
					nl.appendChild(li);
				});
			});
			box.appendChild(nl);
		}
		if (!any) {
			box.appendChild(el('p', __('Nothing is left on this site.', 'seoprostats')));
			return;
		}
		box.appendChild(el('p', __('This cannot be undone. Make a database backup first. The days already imported into SEO Pro Stats stay.', 'seoprostats'), 'description'));
		var actions = el('div', '', 'spst-import__actions');
		var go = el('button', __('Remove leftover data', 'seoprostats'), 'button button-link-delete');
		go.type = 'button';
		go.setAttribute('data-spst-action', 'cleanup');
		actions.appendChild(go);
		box.appendChild(actions);
		box.querySelector('h4').setAttribute('tabindex', '-1');
		box.querySelector('h4').focus();
	}

	// While an import runs: read the status (each read moves it on) until it
	// ends, then reload.
	function poll() {
		var bar = root.querySelector('[data-spst-progress] progress');
		var text = root.querySelector('[data-spst-progress-text]');
		wp.apiFetch({ path: base + 'migrate' })
			.then(function (status) {
				var job = status.job || {};
				var done = 0;
				var total = 0;
				var waiting = '';
				(job.queue || []).forEach(function (item) {
					done += item.done;
					total += item.total;
					// A remote source (Jetpack Stats on WordPress.com) asked to wait.
					waiting = item.notice || waiting;
				});
				if (bar) {
					bar.max = Math.max(1, total);
					bar.value = done;
				}
				if (text) {
					/* translators: 1: days done, 2: days in all */
					text.textContent = sprintf(__('%1$d of %2$d days. It carries on in the background; you can leave this page.', 'seoprostats'), done, total) + (waiting ? ' ' + waiting : '');
				}
				if (job.status === 'running') {
					window.setTimeout(poll, 1500);
				} else {
					reload(__('Import finished.', 'seoprostats'));
				}
			})
			.catch(function () {
				window.setTimeout(poll, 5000);
			});
	}

	var actions = {
		refresh: function (card, button) {
			request(card, button, { path: base + 'migrate?fresh=1' }, function () {
				reload(__('Looked again.', 'seoprostats'));
			});
		},

		plan: function (card, button, source) {
			request(card, button, {
				path: base + 'migrate/' + source,
				method: 'POST',
				data: { dry_run: true }
			}, function (plan) {
				drawPlan(card, plan);
			});
		},

		import: function (card, button, source) {
			var data = { prefer: chosen(card) };
			var settings = chosenSettings(card);
			if (settings !== null) {
				data.settings = settings;
			}
			request(card, button, {
				path: base + 'migrate/' + source,
				method: 'POST',
				data: data
			}, function () {
				reload(__('The import has started.', 'seoprostats'));
			});
		},

		undo: function (card, button) {
			var id = button.getAttribute('data-spst-import');
			/* translators: %s: import number */
			if (!window.confirm(sprintf(__('Delete the days import #%s added?', 'seoprostats'), id))) {
				return;
			}
			request(card, button, {
				path: base + 'imports/' + id,
				method: 'DELETE'
			}, function (answer) {
				/* translators: %s: number of rows */
				reload(sprintf(__('Undone: %s rows deleted.', 'seoprostats'), number(answer.deleted)));
			});
		},

		leftovers: function (card, button, source) {
			request(card, button, {
				path: base + 'migrate/' + source + '/cleanup',
				method: 'POST',
				data: { dry_run: true }
			}, function (answer) {
				drawLeftovers(card, answer);
			});
		},

		cleanup: function (card, button, source) {
			if (!window.confirm(__('Remove everything listed? This cannot be undone.', 'seoprostats'))) {
				return;
			}
			request(card, button, {
				path: base + 'migrate/' + source + '/cleanup',
				method: 'POST',
				data: { dry_run: false }
			}, function (answer) {
				var removed = 0;
				Object.keys(answer.removed || {}).forEach(function (kind) {
					removed += answer.removed[kind];
				});
				/* translators: %s: number of items */
				reload(sprintf(__('Removed %s items.', 'seoprostats'), number(removed)));
			});
		}
	};

	root.addEventListener('click', function (event) {
		var button = event.target.closest('[data-spst-action]');
		var card = button ? button.closest('[data-spst-source]') : null;
		var action = button ? actions[button.getAttribute('data-spst-action')] : null;
		if (!card || !action) {
			return;
		}
		event.preventDefault();
		action(card, button, card.getAttribute('data-spst-source'));
	});

	if (root.getAttribute('data-spst-job') === 'running') {
		poll();
	}
})(window.wp);
