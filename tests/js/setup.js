import { config } from '@vue/test-utils';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import { vi } from 'vitest';

// jsdom polyfills for Vuetify overlay/dialog support
if (typeof window.visualViewport === 'undefined') {
    window.visualViewport = {
        width: 1024,
        height: 768,
        offsetLeft: 0,
        offsetTop: 0,
        pageLeft: 0,
        pageTop: 0,
        scale: 1,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    };
}

if (typeof window.CSS === 'undefined') {
    window.CSS = { supports: vi.fn(() => false) };
}

if (typeof Element.prototype.scrollTo === 'undefined') {
    Element.prototype.scrollTo = vi.fn();
}

if (typeof window.ResizeObserver === 'undefined') {
    window.ResizeObserver = class ResizeObserver {
        constructor(cb) { this._cb = cb; }
        observe() {}
        unobserve() {}
        disconnect() {}
    };
}

if (typeof window.IntersectionObserver === 'undefined') {
    window.IntersectionObserver = class IntersectionObserver {
        constructor() {}
        observe() {}
        unobserve() {}
        disconnect() {}
    };
}

// Create a real Vuetify instance for tests (no mocking — tests render real Vuetify components)
const vuetify = createVuetify({
    components,
    directives,
    theme: {
        defaultTheme: 'onboard',
        themes: {
            onboard: {
                dark: false,
                colors: {
                    primary: '#FE5B24',
                    secondary: '#1A1A1A',
                },
            },
            onboard_dark: {
                dark: true,
                colors: {
                    primary: '#FE5B24',
                    secondary: '#B0B0B0',
                },
            },
        },
    },
});

// Mock vue-cardano plugin (provides cardano data + checkForCardano method via mixin)
const vueCardanoMock = {
    install(Vue) {
        Vue.mixin({
            data() {
                return {
                    cardano: {
                        status: 'init',
                        retries: 0,
                        pollingFrequency: 200,
                        found: false,
                        SupportedWallets: [],
                        Wallets: [],
                        Wallet: null,
                        ActiveWallet: false,
                        stake_key: null,
                        change_address: null,
                        protocol_parameters: null,
                        lovelace_format: { minimumIntegerDigits: 1, maximumFractionDigits: 6, minimumFractionDigits: 0 },
                    },
                };
            },
            methods: {
                formatAda: vi.fn((v) => `${v} ADA`),
                toAda: vi.fn((l) => l / 1000000),
                toLovelace: vi.fn((a) => a * 1000000),
                hexToString: vi.fn((hex) => {
                    let str = '';
                    for (let i = 0; i < hex.length; i += 2) {
                        str += String.fromCharCode(parseInt(hex.substr(i, 2), 16));
                    }
                    return str;
                }),
                checkForCardano: vi.fn(),
                checkWallets: vi.fn(),
                connect: vi.fn(),
                getUtxos: vi.fn(async () => []),
            },
        });
    },
};

config.global.plugins = [vuetify, vueCardanoMock];

// Mock Ziggy's route() helper
const routeMock = vi.fn((name, params) => {
    if (params) {
        const id = typeof params === 'object' ? Object.values(params)[0] : params;
        return `/${name.replace(/\./g, '/')}/${id}`;
    }
    return `/${name.replace(/\./g, '/')}`;
});
routeMock.has = vi.fn(() => true);

config.global.mocks = {
    route: routeMock,
    asset: vi.fn((path) => path),
    $page: {
        props: {
            auth: { user: null },
            errors: {},
            flash: { message: null },
            beta_banner: false,
            transaction_backend: 'null',
        },
    },
};

// Mock Inertia's Head component (renders nothing, avoids errors)
config.global.stubs = {
    Head: { template: '<div />' },
};

// Mock @inertiajs/vue3 module
vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3');
    return {
        ...actual,
        Head: { template: '<div />' },
        usePage: vi.fn(() => ({
            props: {
                auth: { user: null },
                errors: {},
                beta_banner: false,
                transaction_backend: 'null',
            },
        })),
        useForm: vi.fn((initialData) => {
            const form = { ...initialData, processing: false, errors: {} };
            form.post = vi.fn();
            form.put = vi.fn();
            form.delete = vi.fn();
            form.reset = vi.fn();
            return form;
        }),
        router: {
            post: vi.fn(),
            get: vi.fn(),
            delete: vi.fn(),
            visit: vi.fn(),
        },
    };
});

// Suppress Vuetify warnings about missing icons in test environment
const originalWarn = console.warn;
console.warn = (...args) => {
    if (typeof args[0] === 'string' && args[0].includes('[Vuetify]')) return;
    originalWarn(...args);
};
