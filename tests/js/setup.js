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

// jsdom does not implement SVG geometry methods used by Vuetify's v-sparkline auto-draw.
if (typeof window.SVGElement !== 'undefined' && typeof window.SVGElement.prototype.getTotalLength === 'undefined') {
    window.SVGElement.prototype.getTotalLength = () => 0;
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
// Ziggy's real signature: route(name, params) returns a URL, while route()
// with no arguments returns the Router instance, whose .has(name) reports
// whether a named route exists. Components rely on that second form to stay
// safe against the reduced route table in the self-hosted build, so the mock
// has to support it too.
//
// KNOWN_ROUTES is the SaaS route table. Tests that need to simulate the
// self-hosted build can narrow it with setAvailableRoutes().
let availableRoutes = null; // null = every route exists

const routeMock = vi.fn((name, params) => {
    if (name === undefined) {
        return {
            has: (n) => (availableRoutes === null ? true : availableRoutes.includes(n)),
        };
    }
    // Real Ziggy throws on an unknown route name — that is precisely what
    // blanks a page in the self-hosted build, so the mock must throw too or
    // tests would pass against routes that do not exist.
    if (availableRoutes !== null && !availableRoutes.includes(name)) {
        throw new Error(`Ziggy error: route '${name}' is not in the route list.`);
    }
    if (params) {
        const id = typeof params === 'object' ? Object.values(params)[0] : params;
        return `/${name.replace(/\./g, '/')}/${id}`;
    }
    return `/${name.replace(/\./g, '/')}`;
});

// Retained for any test that calls route.has(...) directly.
routeMock.has = vi.fn((n) => (availableRoutes === null ? true : availableRoutes.includes(n)));

// Limit which named routes "exist" — pass null to restore the full table.
globalThis.setAvailableRoutes = (names) => {
    availableRoutes = names;
};

// Ziggy exposes route() as a global in the real app; mirror that for component
// <script setup> code (template scope is covered by config.global.mocks below).
globalThis.route = routeMock;

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
