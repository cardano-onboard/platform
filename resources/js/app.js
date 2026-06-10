import './bootstrap';
import '../css/app.css';

import {createApp, h} from 'vue';
import {createInertiaApp} from '@inertiajs/vue3';
import {resolvePageComponent} from 'laravel-vite-plugin/inertia-helpers';
import {ZiggyVue} from '../../vendor/tightenco/ziggy';

import Vapor from 'laravel-vapor'
import '@mdi/font/css/materialdesignicons.css'
import 'vuetify/styles'
import {createVuetify} from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'
import vueCardano from "@/plugins/vue-cardano.js";


Vapor.withBaseAssetUrl(import.meta.env.VITE_VAPOR_ASSET_URL)
window.Vapor = Vapor

const vuetify = createVuetify({
    components,
    directives,
    theme: {
        defaultTheme: localStorage.getItem('theme') || 'onboard_dark',
        themes: {
            onboard: {
                dark: false,
                colors: {
                    primary: '#FE5B24',
                    secondary: '#1A1A1A',
                    accent: '#FF7A3D',
                    error: '#D32F2F',
                    info: '#1976D2',
                    success: '#388E3C',
                    warning: '#F9A825',
                    background: '#FFFFFF',
                    surface: '#FFFFFF',
                    'on-primary': '#FFFFFF',
                    'on-secondary': '#FFFFFF',
                },
            },
            onboard_dark: {
                dark: true,
                colors: {
                    primary: '#FE5B24',
                    secondary: '#B0B0B0',
                    accent: '#FF7A3D',
                    error: '#EF5350',
                    info: '#42A5F5',
                    success: '#66BB6A',
                    warning: '#FFA726',
                    background: '#121212',
                    surface: '#1E1E1E',
                    'on-primary': '#FFFFFF',
                    'on-secondary': '#000000',
                },
            },
        },
    },
});

const appName = import.meta.env.VITE_APP_NAME || 'Onboard.Ninja';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({el, App, props, plugin}) {
        return createApp({render: () => h(App, props)})
            .use(plugin)
            .use(vuetify)
            .use(vueCardano)
            .use(ZiggyVue, Ziggy)
            .mixin({
                methods: {asset: window.Vapor.asset}
            })
            .mount(el);
    },
    progress: {
        color: '#FE5B24',
    },
});
