export type QueryValue = string | number | boolean | null | undefined;

export function buildPath(path: string, params: Record<string, QueryValue>): string {
	const searchParams = new URLSearchParams();

	for (const [key, value] of Object.entries(params)) {
		if (value === undefined || value === null || value === '') {
			continue;
		}

		searchParams.set(key, String(value));
	}

	const query = searchParams.toString();

	return query === '' ? path : `${path}?${query}`;
}

export function parseOptionalPositiveInt(value: string | null | undefined): number | undefined {
	if (value === null || value === undefined || value.trim() === '') {
		return undefined;
	}

	const parsed = Number.parseInt(value, 10);

	return Number.isInteger(parsed) && parsed > 0 ? parsed : undefined;
}

export function parsePageParam(value: string | null | undefined): number {
	return parseOptionalPositiveInt(value) ?? 1;
}

export function normalizeSearchParam(value: string | null | undefined): string | undefined {
	if (value === null || value === undefined) {
		return undefined;
	}

	const normalized = value.trim();

	return normalized === '' ? undefined : normalized;
}
