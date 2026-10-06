/**
 * Build of the wp-admin app (DEVELOPMENT.md → JavaScript builds): WordPress's
 * own configuration with our entries, written to assets/build/.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

const path = require('path');
const wpConfig = require('@wordpress/scripts/config/webpack.config');

const base = Array.isArray(wpConfig) ? wpConfig[0] : wpConfig;

module.exports = {
	...base,
	entry: {
		dashboard: './packages/wp-admin/src/dashboard.tsx',
		widget: './packages/wp-admin/src/widget.tsx',
	},
	output: {
		...base.output,
		path: path.resolve(__dirname, 'assets/build'),
	},
};
