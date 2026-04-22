import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// @ts-ignore
window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY as string;
const host = import.meta.env.VITE_REVERB_HOST as string;
const port = parseInt((import.meta.env.VITE_REVERB_PORT as string) ?? '8080', 10);
const scheme = (import.meta.env.VITE_REVERB_SCHEME as string) ?? 'http';

export const echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
});

// Expose globally for quick dev debugging.
// @ts-ignore
window.Echo = echo;
