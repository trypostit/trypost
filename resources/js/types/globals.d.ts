import { AppPageProps } from '@/types/index';

declare global {
    /** The full commit the bundle was built from, empty when unknown (`vite.config.ts`). */
    const __BUILD_COMMIT__: string;
    /** When the bundle was built, as an ISO instant (`vite.config.ts`). */
    const __BUILD_DATE__: string;

    interface Window {
        dataLayer: Record<string, unknown>[];
        $crisp?: {
            push: (command: unknown[]) => void;
        };
    }
}

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps, AppPageProps {}
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
