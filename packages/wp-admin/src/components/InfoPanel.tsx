/**
 * Info & ideas: a card at the bottom of each section of the statistics
 * screen saying what the section is for and what it can help with. Search
 * has one for each of its tabs. It is advice for the site's owner, so
 * shared and printed reports do not show it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SEARCH_REPORTS, type SearchReport, type ViewState } from '@seoprostats/core';

interface Info {
	about: string;
	ideas: string[];
}

type InfoKey = Exclude<ViewState['view'], 'search'> | SearchReport;

/** The text for each section, made when shown so it is translated. */
function info(key: InfoKey): Info {
	switch (key) {
		case 'overview':
			return {
				about: __('Where visits come from, what they read and what they do, against the previous period. The markers under the chart show what changed at the time, so a rise or a drop can be traced to its cause.', 'seoprostats'),
				ideas: [
					__('Choose a marker where the line turns to see what changed then, and open it in Changes.', 'seoprostats'),
					__('Check Pages → Not found for broken links worth redirecting, and Site search → No results for content people look for but cannot find.', 'seoprostats'),
					__('Choose a channel, page or country to filter every report by it, and see which visits reach your goals.', 'seoprostats'),
				],
			};
		case 'rankings':
			return {
				about: __('How the site shows in search: clicks, impressions, click-through rate and position for each search, page, country and device, from Google Search Console (and Bing Webmaster Tools when connected).', 'seoprostats'),
				ideas: [
					__('Choose a page to list the searches it shows for, and make sure its title and first paragraph answer the main ones.', 'seoprostats'),
					__('Fewer clicks from as many impressions usually means a lower position or a less inviting result: check the markers for your changes and search engine updates.', 'seoprostats'),
					__('Open Days to find the day a rise or a drop started.', 'seoprostats'),
				],
			};
		case 'opportunities':
			return {
				about: __('Where search work pays most: searches close to the top three, results searchers pass over, pages losing clicks, words a page lacks, and pages competing for one search.', 'seoprostats'),
				ideas: [
					__('Start with Striking distance: a clearer title, a heading and a section that answers the search can lift a page into the top three.', 'seoprostats'),
					__('For Low CTR, rewrite the title and description to say what the searcher wants to find.', 'seoprostats'),
					__('Accept the ones worth doing in Plan, so the work is tracked and its result measured.', 'seoprostats'),
				],
			};
		case 'audit':
			return {
				about: __('What WordPress tells search engines about each page, the fixes that matter most first: titles, descriptions, headings, images, thin content, indexing, internal links and Google\'s own index.', 'seoprostats'),
				ideas: [
					__('Fix noindex and canonical findings first on pages that should rank: they can keep a page out of search.', 'seoprostats'),
					__('Link to orphan pages from related ones, so readers and search engines find them.', 'seoprostats'),
					__('Pages search never shows may be worth improving, merging into a stronger page, or removing.', 'seoprostats'),
				],
			};
		case 'content':
			return {
				about: __('Each page\'s search results beside what its visitors from search did next: whether they left, how long they stayed and whether they reached the goal you pick.', 'seoprostats'),
				ideas: [
					__('A page that ranks but whose visitors leave needs a clearer answer or next step: a link, a button, a form.', 'seoprostats'),
					__('A page that converts but gets few clicks is worth ranking higher: add its main search to Targets.', 'seoprostats'),
					__('Pick another goal to see which content leads to each kind of conversion.', 'seoprostats'),
				],
			};
		case 'backlinks':
			return {
				about: __('Pages of other sites that link to yours, found from the visits they sent, with new and lost links on the timeline.', 'seoprostats'),
				ideas: [
					__('When a link is lost, ask the site to put it back or to point it at the page\'s new address.', 'seoprostats'),
					__('Sites that send visitors already like your content: they may link to other pages too.', 'seoprostats'),
					__('From pages that gain links, link on to the pages you most want to rank.', 'seoprostats'),
				],
			};
		case 'targets':
			return {
				about: __('The searches you chose to win and the page meant for each, with where search shows them now.', 'seoprostats'),
				ideas: [
					__('Import your list of searches with their pages and priorities, so Plan puts your priorities first.', 'seoprostats'),
					__('Where another page ranks, link from it to the page meant, or make one page the clear answer.', 'seoprostats'),
					__('Mark targets won or retired to keep the list on what is still to do.', 'seoprostats'),
				],
			};
		case 'plan':
			return {
				about: __('One list of what to do next for search, best first: each item scored by the clicks it could bring, the page\'s value, how sure the estimate is and the effort.', 'seoprostats'),
				ideas: [
					__('Set the effort of items you know are quick or slow; the order follows.', 'seoprostats'),
					__('Mark an item Done once the change is live: that starts an experiment, so you learn whether it worked.', 'seoprostats'),
					__('Dismiss items that do not apply; they come back after 90 days if still found.', 'seoprostats'),
				],
			};
		case 'experiments':
			return {
				about: __('Whether a change worked: its pages before and after, against the pages that did not change, with search engine updates and other changes beside them.', 'seoprostats'),
				ideas: [
					__('Write down what the change should do before the result is known.', 'seoprostats'),
					__('Change one thing on a page at a time, so the result can be read.', 'seoprostats'),
					__('Decide Keep, Revise or Undo with a note, so the next change builds on what you learned.', 'seoprostats'),
				],
			};
		case 'goals':
			return {
				about: __('The pages and events that count as conversions, with the visits that reached each, the conversion rate and revenue.', 'seoprostats'),
				ideas: [
					__('Add a goal for each thank-you page, sign-up and purchase, so every report can show which visits are worth most.', 'seoprostats'),
					__('Use * to count a group of pages as one goal, such as /checkout/*.', 'seoprostats'),
					__('Filter by a channel or campaign to compare their conversion rates.', 'seoprostats'),
				],
			};
		case 'funnels':
			return {
				about: __('The steps a visit takes towards a goal, in order, and how many visits leave before each next step.', 'seoprostats'),
				ideas: [
					__('Work on the step with the biggest drop first: it has the most to gain.', 'seoprostats'),
					__('Filter by device to see whether phones leave at a step that computers pass.', 'seoprostats'),
					__('After changing a step, compare with the period before the change.', 'seoprostats'),
				],
			};
		case 'properties':
			return {
				about: __('The details sent with events and pages, such as a plan or an author, how often each value comes and the revenue it brings.', 'seoprostats'),
				ideas: [
					__('Send a property with purchases to see which plans, products or authors bring the revenue.', 'seoprostats'),
					__('Choose a value to filter every report by it.', 'seoprostats'),
					__('Compare with the previous period to find the values that grow.', 'seoprostats'),
				],
			};
		case 'clicks':
			return {
				about: __('What people click and the forms they send: dead clicks, links out, files and forms, for the site or one page.', 'seoprostats'),
				ideas: [
					__('Dead clicks show what looks clickable but is not: make it a link, or make it look different.', 'seoprostats'),
					__('A popular link out may deserve a page of your own, or an affiliate link.', 'seoprostats'),
					__('Pages with many visits but few clicks may need a clearer next step.', 'seoprostats'),
				],
			};
		case 'ab-tests':
			return {
				about: __('The A/B tests in your posts and pages, with each variant\'s visits, conversions and chance to beat Variant A.', 'seoprostats'),
				ideas: [
					__('Test one difference at a time, such as a heading or a button\'s text.', 'seoprostats'),
					__('Wait until the test calls a variant better or worse: early leads often fade.', 'seoprostats'),
					__('Pick a winner in the post editor to end the test with that variant.', 'seoprostats'),
				],
			};
		case 'changes':
			return {
				about: __('What changed on the site in the period, with search engine updates and your notes, so a change in traffic can be traced to its cause.', 'seoprostats'),
				ideas: [
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
	const { about, ideas } = info(infoKey(state));
	return (
		<Card className="spst-card is-wide spst-info" size="small">
			<CardHeader className="spst-card__header">
				<h2 className="spst-card__title">{__('Info & ideas', 'seoprostats')}</h2>
			</CardHeader>
			<CardBody className="spst-info__body">
				<div>
					<h3 className="spst-info__title">{__('What it is for', 'seoprostats')}</h3>
					<p>{about}</p>
				</div>
				<div>
					<h3 className="spst-info__title">{__('Ideas', 'seoprostats')}</h3>
					<ul className="spst-info__ideas">
						{ideas.map((idea) => (
							<li key={idea}>{idea}</li>
						))}
					</ul>
				</div>
			</CardBody>
		</Card>
	);
}
