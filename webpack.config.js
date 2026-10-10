/**
 * Build of the wp-admin app (DEVELOPMENT.md → JavaScript builds): WordPress's
 * own configuration with our entries, written to assets/build/.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 */

const path = require('node:path');
const wpConfig = require('@wordpress/scripts/config/webpack.config');

const base = Array.isArray(wpConfig) ? wpConfig[0] : wpConfig;

module.exports = {
	...base,
	entry: {
		dashboard: './packages/wp-admin/src/dashboard.tsx',
		widget: './packages/wp-admin/src/widget.tsx',
		share: './packages/wp-admin/src/share.tsx',
		editor: './packages/wp-admin/src/editor.tsx',
		'ab-test': './packages/wp-admin/src/ab-test/index.tsx',
	},
	output: {
		...base.output,
		path: path.resolve(__dirname, 'assets/build'),
	},
};
