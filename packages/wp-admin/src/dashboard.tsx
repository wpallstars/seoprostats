/**
 * The SEO Pro Stats screen (SEOProStats_Dashboard::render()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Overview } from './Overview';
import { mount } from './mount';
import './dashboard.css';

mount('spst-dashboard', <Overview />);
