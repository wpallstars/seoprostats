/**
 * Info & ideas: a card at the bottom of each section of the statistics
 * screen answering two questions: what does the data say (how to read the
 * section's figures and what they point to), and how can you improve
 * (three things to do about it). Search has one for each of its tabs. It
 * is advice for the site's owner, so shared and printed reports do not
 * show it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SEARCH_REPORTS, type SearchReport, type ViewState } from '@seoprostats/core';

interface Info {
	/** What does the data say? */
	data: string;
	/** How can you improve? */
	improve: string[];
}

type InfoKey = Exclude<ViewState['view'], 'search'> | SearchReport;

/** The text for each section, made when shown so it is translated. */
function info(key: InfoKey): Info {
	switch (key) {
		case 'overview':
			return {
				data: __('Whether the site is growing: each tile compares the period with the one before, the chart shows when a figure turned, and the markers under it show what changed on the site at that time. The cards below say which channels, pages, places and devices the change came from.', 'seoprostats'),
				improve: [
					__('Where the line turns, choose the marker to see what changed then, and repeat what worked.', 'seoprostats'),
					__('Redirect or fix the addresses under Pages → Not found, and write the content people looked for under Site search → No results.', 'seoprostats'),
					__('Choose your best channel or page to filter every report by it, then give more of those visits a clear path to your goals.', 'seoprostats'),
				],
			};
		case 'rankings':
			return {
				data: __('How search sees the site: impressions say how often it was shown, clicks how often it was chosen, CTR how inviting the result is, and position where it ranks (lower is better). Fewer clicks from as many impressions point to a lower position or a less inviting result; fewer impressions mean fewer searches, or the page shown less.', 'seoprostats'),
				improve: [
					__('Choose a page to list the searches it shows for, and make its title and first paragraph answer the main ones.', 'seoprostats'),
					__('When clicks drop, find the day in Days and check the markers for your changes and search engine updates.', 'seoprostats'),
					__('Compare devices and countries: a weaker position on phones or in one country shows where to start.', 'seoprostats'),
				],
			};
		case 'opportunities':
			return {
				data: __('Where search work pays most: each row is a search or page with the clicks it could gain, worked out from the CTR this site gets at each position. Striking distance and Low CTR are gains within reach, Losing clicks gives each page\'s likely cause, and Overlapping pages may be splitting one search between them.', 'seoprostats'),
				improve: [
					__('Lift striking-distance pages with a clearer title, a heading and a section that answers the search.', 'seoprostats'),
					__('Rewrite the title and description of Low CTR results to say what the searcher wants to find.', 'seoprostats'),
					__('Add the missing words where they belong, and accept the work in Plan so its result is measured.', 'seoprostats'),
				],
			};
		case 'audit':
			return {
				data: __('What WordPress tells search engines about each page, most search impressions first, so the findings at the top cost the most. Noindex, canonical and Google index findings can keep a page out of search; long or shared titles and descriptions, headings, missing alt text and thin content make it weaker; orphan pages and missing links make it hard to find.', 'seoprostats'),
				improve: [
					__('Fix noindex, canonical and indexing findings first on pages that should rank.', 'seoprostats'),
					__('Give each page its own title under 60 characters, a description under 160, and alt text on its images.', 'seoprostats'),
					__('Link to orphan pages from related ones, and improve, merge or remove pages search never shows.', 'seoprostats'),
				],
			};
		case 'content':
			return {
				data: __('Whether search visits pay off: each page\'s search clicks, position and CTR beside what its visitors from search did next. A page that ranks but whose visitors leave is not answering the search; one that converts but gets few clicks deserves to rank higher.', 'seoprostats'),
				improve: [
					__('Give pages that rank but lose their visitors a clearer answer and next step: a link, a button, a form.', 'seoprostats'),
					__('Add pages that convert but get few clicks to Targets, and work on their ranking first.', 'seoprostats'),
					__('Pick another goal to see which content leads to each kind of conversion.', 'seoprostats'),
				],
			};
		case 'backlinks':
			return {
				data: __('Which other sites link to yours and send visitors, which of your pages they point at, and which links were lost. New links usually lift the pages they point to; a lost link from a site that sent visits can explain a drop.', 'seoprostats'),
				improve: [
					__('Ask sites that removed a link to put it back, or to point it at the page\'s new address.', 'seoprostats'),
					__('Offer sites that already send visitors more of what they linked to.', 'seoprostats'),
					__('From pages that gain links, link on to the pages you most want to rank.', 'seoprostats'),
				],
			};
		case 'targets':
			return {
				data: __('Whether the searches you chose are being won: where each ranks now, and whether search shows the page meant for it, another page, or none. When another page ranks, search reads that page as the better answer.', 'seoprostats'),
				improve: [
					__('Import your list of searches with their pages and priorities, so Plan puts them first.', 'seoprostats'),
					__('Where another page ranks, link from it to the page meant, or make one page the clear answer.', 'seoprostats'),
					__('Mark targets won or retired, to keep the list on what is still to do.', 'seoprostats'),
				],
			};
		case 'plan':
			return {
				data: __('What to do next for search, best first: each item\'s score is the clicks it could bring, times the page\'s value and how sure the estimate is, divided by the effort. Choose a score to see why it ranks where it does.', 'seoprostats'),
				improve: [
					__('Work from the top, and set the effort of items you know are quick or slow so the order fits your time.', 'seoprostats'),
					__('Mark an item Done once the change is live: that starts an experiment, so you learn whether it worked.', 'seoprostats'),
					__('Dismiss items that do not apply; they come back after 90 days if still found.', 'seoprostats'),
				],
			};
		case 'experiments':
			return {
				data: __('Whether a change worked: its pages before and after, against the pages that did not change, so a season or a site-wide rise does not count. Each result says how far unchanged pages move by themselves, whether there was enough data, and what else happened meanwhile.', 'seoprostats'),
				improve: [
					__('Write down what a change should do before the result is known.', 'seoprostats'),
					__('Change one thing on a page at a time, so the result can be read.', 'seoprostats'),
					__('Repeat what worked on similar pages, and revise or undo what did not, with a note.', 'seoprostats'),
				],
			};
		case 'goals':
			return {
				data: __('How many visits do what you want, and what they are worth: each goal\'s visits, conversion rate and revenue against the previous period. A falling rate from as many visits points to the pages on the way to the goal; a rising one shows what is working.', 'seoprostats'),
				improve: [
					__('Add a goal for each thank-you page, sign-up and purchase, so every report can show which visits are worth most.', 'seoprostats'),
					__('Filter by a channel or campaign to find the visits that convert best, and get more of them.', 'seoprostats'),
					__('Use * to count a group of pages as one goal, such as /checkout/*.', 'seoprostats'),
				],
			};
		case 'funnels':
			return {
				data: __('Where visits leave on the way to a goal: how many reach each step, in order, within one visit. The step with the biggest drop is where most visits are lost.', 'seoprostats'),
				improve: [
					__('Work on the step with the biggest drop first: simplify it, or make its next step clearer.', 'seoprostats'),
					__('Filter by device to see whether phones leave at a step that computers pass.', 'seoprostats'),
					__('After changing a step, compare with the period before the change.', 'seoprostats'),
				],
			};
		case 'properties':
			return {
				data: __('Which plans, products, authors or other details sent with events and pages come most often and bring the most revenue, against the previous period.', 'seoprostats'),
				improve: [
					__('Send a property with purchases to see which plans, products or authors bring the revenue.', 'seoprostats'),
					__('Choose a value to filter every report by it, and see where its visits come from.', 'seoprostats'),
					__('Promote the values that grow, and look into the ones that fall.', 'seoprostats'),
				],
			};
		case 'clicks':
			return {
				data: __('What people try to do on each page: the links they follow, the files they open, the forms they send, and dead clicks on things that look clickable but are not. Many visits with few clicks mean a page gives no clear next step.', 'seoprostats'),
				improve: [
					__('Make what gets dead clicks a real link, or make it look different.', 'seoprostats'),
					__('Give a popular link out a page of your own, or an affiliate link.', 'seoprostats'),
					__('Add a clear next step to pages with many visits but few clicks.', 'seoprostats'),
				],
			};
		case 'ab-tests':
			return {
				data: __('Which version of a block works better: each variant\'s visits, conversions, uplift over Variant A and chance to beat it. A test is called once each side has enough visits, conversions and days; until then a lead may be chance.', 'seoprostats'),
				improve: [
					__('Test one difference at a time, such as a heading or a button\'s text.', 'seoprostats'),
					__('Wait until the test calls a variant better or worse: early leads often fade.', 'seoprostats'),
					__('Pick the winner in the post editor, then test the next idea on the same page.', 'seoprostats'),
				],
			};
		case 'changes':
			return {
				data: __('What changed on the site and when: content, SEO, products, settings, search engine updates and your notes. Set beside the charts, it explains why traffic, rankings or conversions moved.', 'seoprostats'),
				improve: [
					__('Add notes for what the log cannot see, such as a newsletter, a sale or an advertising campaign.', 'seoprostats'),
					__('Start an experiment from a change to measure whether it worked.', 'seoprostats'),
					__('Switch on search engine updates under Settings → Data to see them beside your own changes.', 'seoprostats'),
				],
			};
	}
}

/** The section shown, or for Search its tab. */
function infoKey(state: ViewState): InfoKey {
	if (state.view !== 'search') {
		return state.view;
	}
	return state.report && SEARCH_REPORTS.includes(state.report) ? state.report : 'rankings';
}

export function InfoPanel({ state }: { state: ViewState }) {
	const { data, improve } = info(infoKey(state));
	return (
		<Card className="spst-card is-wide spst-info" size="small">
			<CardHeader className="spst-card__header">
				<h2 className="spst-card__title">{__('Info & ideas', 'seoprostats')}</h2>
			</CardHeader>
			<CardBody className="spst-info__body">
				<div>
					<h3 className="spst-info__title">{__('What does the data say?', 'seoprostats')}</h3>
					<p>{data}</p>
				</div>
				<div>
					<h3 className="spst-info__title">{__('How can you improve?', 'seoprostats')}</h3>
					<ul className="spst-info__ideas">
						{improve.map((idea) => (
							<li key={idea}>{idea}</li>
						))}
					</ul>
				</div>
			</CardBody>
		</Card>
	);
}
