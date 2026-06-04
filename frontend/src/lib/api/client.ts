import type { ZodType } from 'zod';

export const API_BASE_PATH = '/api';

export type ApiValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
	constructor(
		public readonly status: number,
		message: string,
		public readonly validationErrors: ApiValidationErrors = {}
	) {
		super(message);
		this.name = 'ApiError';
	}
}

export function apiPath(path: string): string {
	const normalizedPath = path.startsWith('/') ? path : `/${path}`;

	return normalizedPath.startsWith(`${API_BASE_PATH}/`) || normalizedPath === API_BASE_PATH
		? normalizedPath
		: `${API_BASE_PATH}${normalizedPath}`;
}

export async function apiJson<T>(
	path: string,
	schema: ZodType<T>,
	init?: RequestInit
): Promise<T> {
	const response = await fetch(apiPath(path), {
		...init,
		headers: {
			accept: 'application/json',
			...(init?.headers ?? {})
		}
	});

	if (!response.ok) {
		throw await createApiError(response);
	}

	return schema.parse(await response.json());
}

export async function apiVoid(path: string, init?: RequestInit): Promise<void> {
	const response = await fetch(apiPath(path), {
		...init,
		headers: {
			accept: 'application/json',
			...(init?.headers ?? {})
		}
	});

	if (!response.ok) {
		throw await createApiError(response);
	}
}

async function createApiError(response: Response): Promise<ApiError> {
	let message = `API request failed with status ${response.status}.`;
	let validationErrors: ApiValidationErrors = {};

	if ((response.headers.get('content-type') ?? '').includes('application/json')) {
		const payload = await response.json().catch(() => null);
		const payloadMessage = typeof payload?.message === 'string' ? payload.message : null;

		if (payloadMessage !== null) {
			message = payloadMessage;
		}

		if (payload?.errors && typeof payload.errors === 'object') {
			validationErrors = Object.fromEntries(
				Object.entries(payload.errors).flatMap(([field, errors]) => {
					if (!Array.isArray(errors)) {
						return [];
					}

					const normalizedErrors = errors.filter(
						(error): error is string => typeof error === 'string'
					);

					return normalizedErrors.length > 0 ? [[field, normalizedErrors]] : [];
				})
			);
		}
	}

	return new ApiError(response.status, message, validationErrors);
}
