import '../css/app.css';

import { echo } from '@/echo';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import * as Sentry from '@sentry/vue';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        readonly VITE_SENTRY_DSN?: string;
        readonly VITE_SENTRY_ENVIRONMENT?: string;
        readonly VITE_SENTRY_TRACES_SAMPLE_RATE?: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        const sentryDsn = import.meta.env.VITE_SENTRY_DSN;
        if (sentryDsn) {
            Sentry.init({
                app,
                dsn: sentryDsn,
                environment: import.meta.env.VITE_SENTRY_ENVIRONMENT,
                integrations: [Sentry.browserTracingIntegration()],
                tracesSampleRate: Number(import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? '0.1'),
                tracePropagationTargets: ['localhost', /^\//],
            });
        }

        app.use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// Ensures Echo is initialized at boot.
void echo;
