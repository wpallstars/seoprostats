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

const NAMESPACE = '/seoprostats/v1';

type Args = Record<string, string | string[] | number>;

export function get<T>(route: string, args: Args = {}): Promise<T> {
	return apiFetch<T>({ path: addQueryArgs(`${NAMESPACE}/${route}`, args) });
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

export function useStats(scope: Scope) {
	const args = apiArgs(scope);
	return useQuery({
		queryKey: ['stats', args],
		queryFn: () => get<StatsAnswer>('stats', args),
		placeholderData: keepPreviousData,
	});
}

export function useTimeseries(scope: Scope) {
	const args = apiArgs(scope);
	return useQuery({
		queryKey: ['timeseries', args],
		queryFn: () => get<TimeseriesAnswer>('timeseries', args),
		placeholderData: keepPreviousData,
	});
}

export function useBreakdown(scope: Omit<Scope, 'compare'>, dimension: Dimension, limit = 10) {
	const args: Args = { ...apiArgs(scope), dimension, limit };
	return useQuery({
		queryKey: ['breakdown', args],
		queryFn: () => get<BreakdownAnswer>('breakdown', args),
		placeholderData: keepPreviousData,
	});
}

export function useRealtime() {
	return useQuery({
		queryKey: ['realtime'],
		queryFn: () => get<RealtimeAnswer>('realtime'),
		refetchInterval: 30 * 1000,
		staleTime: 0,
	});
}

/** The message of an API error, or a general one. */
export function errorMessage(error: unknown, fallback: string): string {
	if (error && typeof error === 'object' && 'message' in error && typeof error.message === 'string') {
		return error.message;
	}
	return fallback;
}
