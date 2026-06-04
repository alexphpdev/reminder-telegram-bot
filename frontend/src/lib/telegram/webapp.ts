import { browser } from '$app/environment';

export type TelegramBootstrapState = {
	available: boolean;
	source: 'server' | 'browser' | 'telegram';
	version: string | null;
	platform: string | null;
	colorScheme: string | null;
	error: string | null;
};

let telegramCleanup: VoidFunction | null = null;

export async function bootstrapTelegramMiniApp(): Promise<TelegramBootstrapState> {
	if (!browser) {
		return {
			available: false,
			source: 'server',
			version: null,
			platform: null,
			colorScheme: null,
			error: null
		};
	}

	const webApp = window.Telegram?.WebApp;

	if (!webApp) {
		return {
			available: false,
			source: 'browser',
			version: null,
			platform: null,
			colorScheme: null,
			error: null
		};
	}

	try {
		const { backButton, init } = await import('@tma.js/sdk');

		if (telegramCleanup === null) {
			telegramCleanup = init();
		}

		if (!backButton.isMounted()) {
			backButton.mount();
		}

		webApp.ready?.();
		webApp.expand?.();

		return {
			available: true,
			source: 'telegram',
			version: webApp.version ?? null,
			platform: webApp.platform ?? null,
			colorScheme: webApp.colorScheme ?? null,
			error: null
		};
	} catch (error) {
		return {
			available: false,
			source: 'telegram',
			version: webApp.version ?? null,
			platform: webApp.platform ?? null,
			colorScheme: webApp.colorScheme ?? null,
			error: error instanceof Error ? error.message : 'Unknown Telegram SDK error.'
		};
	}
}
