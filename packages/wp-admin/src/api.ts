/**
 * Reading the API through WordPress's apiFetch (logged-in cookie and
 * nonce) and TanStack Query (cache, deduplication, refresh).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { QueryClient, keepPreviousData, useQuery } from '@tanstack/react-query';
import {
	apiArgs,
	type BreakdownAnswer,
	type Dimension,
	type RealtimeAnswer,
	type StatsAnswer,
	type TimeseriesAnswer,
	type ViewState,
} from '@seoprostats/core';
import { boot, type DataSet, type DemoStatus } from './boot';
import { useDataSet } from './data';

const NAMESPACE = '/seoprostats/v1';

type Args = Record<string, string | string[] | number>;

export function get<T>(route: string, args: Args = {}): Promise<T> {
	return apiFetch<T>({ path: addQueryArgs(`${NAMESPACE}/${route}`, args) });
}

function send<T>(route: string, method: 'POST' | 'DELETE', data: Record<string, unknown> = {}): Promise<T> {
	return apiFetch<T>({ path: `${NAMESPACE}/${route}`, method, data });
}

/** Report arguments for a data set: live is the default, so it is left out. */
function withData(args: Args, data: DataSet): Args {
	return data === 'demo' ? { ...args, data } : args;
}

export const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			// The server caches answers for five minutes; a minute here is plenty.
			staleTime: 60 * 1000,
			refetchOnWindowFocus: false,
			retry: 1,
		},
	},
});

type Scope = Pick<ViewState, 'range' | 'from' | 'to' | 'filters' | 'compare'>;

/** Reports wait while the chosen demo data is not made yet (they would fail). */
function useReportData(): { data: DataSet; enabled: boolean } {
	const data = useDataSet();
	const demo = useDemo();
	return { data, enabled: data === 'live' || demo.data?.status === 'ready' };
}

export function useStats(scope: Scope) {
	const { data, enabled } = useReportData();
	const args = withData(apiArgs(scope), data);
	return useQuery({
		queryKey: ['stats', args],
		queryFn: () => get<StatsAnswer>('stats', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

export function useTimeseries(scope: Scope) {
	const { data, enabled } = useReportData();
	const args = withData(apiArgs(scope), data);
	return useQuery({
		queryKey: ['timeseries', args],
		queryFn: () => get<TimeseriesAnswer>('timeseries', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

export function useBreakdown(scope: Omit<Scope, 'compare'>, dimension: Dimension, limit = 10) {
	const { data, enabled } = useReportData();
	const args: Args = withData({ ...apiArgs(scope), dimension, limit }, data);
	return useQuery({
		queryKey: ['breakdown', args],
		queryFn: () => get<BreakdownAnswer>('breakdown', args),
		placeholderData: keepPreviousData,
		enabled,
	});
}

export function useRealtime() {
	const { data, enabled } = useReportData();
	const args = withData({}, data);
	return useQuery({
		queryKey: ['realtime', args],
		queryFn: () => get<RealtimeAnswer>('realtime', args),
		refetchInterval: 30 * 1000,
		staleTime: 0,
		enabled,
	});
}

/** Whether demo data is made: from the page at first, then the API. */
export function useDemo() {
	return useQuery({
		queryKey: ['demo'],
		queryFn: () => get<DemoStatus>('demo'),
		initialData: boot.demo,
		staleTime: Infinity,
	});
}

/** Make demo data, or carry on making it: one request does a slice of the work. */
export function makeDemo(restart = false): Promise<DemoStatus> {
	return send<DemoStatus>('demo', 'POST', restart ? { restart } : {});
}

export function removeDemo(): Promise<DemoStatus> {
	return send<DemoStatus>('demo', 'DELETE');
}

/** Save the data set this person sees. */
export function saveView(data: DataSet): Promise<{ data: DataSet }> {
	return send<{ data: DataSet }>('view', 'POST', { data });
}

/** The message of an API error, or a general one. */
export function errorMessage(error: unknown, fallback: string): string {
	if (error && typeof error === 'object' && 'message' in error && typeof error.message === 'string') {
		return error.message;
	}
	return fallback;
}
