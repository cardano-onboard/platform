import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { usePage } from '@inertiajs/vue3';
import Welcome from '../../resources/js/Pages/Welcome.vue';

function mountWelcome(props = {}, pageProps = {}) {
    // Override usePage for this mount
    usePage.mockReturnValue({
        props: {
            auth: { user: null },
            errors: {},
            beta_banner: false,
            transaction_backend: 'phyrhose',
            ...pageProps,
        },
    });

    return mount(Welcome, {
        props: {
            canLogin: true,
            canRegister: true,
            ...props,
        },
        global: {
            stubs: {
                LogoSvg: { template: '<div class="logo-stub">Onboard.Ninja</div>' },
            },
            mocks: {
                $page: {
                    props: {
                        auth: { user: null },
                        errors: {},
                        beta_banner: false,
                        transaction_backend: 'phyrhose',
                        ...pageProps,
                    },
                },
            },
        },
    });
}

describe('Welcome', () => {
    it('renders the landing page with tagline', () => {
        const wrapper = mountWelcome();
        expect(wrapper.text()).toContain('Ninja-fast Cardano airdrops for your event');
    });

    it('shows login button when canLogin is true', () => {
        const wrapper = mountWelcome({ canLogin: true });
        expect(wrapper.text()).toContain('Log In');
    });

    it('shows register button when canRegister is true', () => {
        const wrapper = mountWelcome({ canRegister: true });
        expect(wrapper.text()).toContain('Register');
    });

    it('hides register button when canRegister is false', () => {
        const wrapper = mountWelcome({ canRegister: false });
        expect(wrapper.text()).not.toContain('Register');
    });

    it('shows dashboard link for authenticated users', () => {
        const wrapper = mountWelcome({}, {
            auth: { user: { name: 'Adam' } },
        });
        expect(wrapper.text()).toContain('Welcome back, Adam');
        expect(wrapper.text()).toContain('Go to Dashboard');
    });

    it('hides login/register for authenticated users', () => {
        const wrapper = mountWelcome({}, {
            auth: { user: { name: 'Adam' } },
        });
        expect(wrapper.text()).not.toContain('Log In');
        expect(wrapper.text()).not.toContain('Register');
    });

    it('shows TEST MODE banner when transaction_backend is null', () => {
        const wrapper = mountWelcome({}, {
            transaction_backend: 'null',
        });
        expect(wrapper.text()).toContain('TEST MODE');
    });

    it('hides TEST MODE banner for real backends', () => {
        const wrapper = mountWelcome({}, {
            transaction_backend: 'phyrhose',
        });
        expect(wrapper.text()).not.toContain('TEST MODE');
    });

    it('shows beta banner when enabled', () => {
        const wrapper = mountWelcome({}, {
            beta_banner: true,
        });
        expect(wrapper.text()).toContain('beta');
    });

    it('renders footer links', () => {
        const wrapper = mountWelcome();
        expect(wrapper.text()).toContain('Terms');
        expect(wrapper.text()).toContain('Privacy');
        expect(wrapper.text()).toContain('FAQs');
    });

    it('has a dark mode toggle button', () => {
        const wrapper = mountWelcome();
        const themeBtn = wrapper.findAll('button').find(btn => {
            return btn.find('.mdi-weather-night').exists() || btn.find('.mdi-white-balance-sunny').exists();
        });
        expect(themeBtn).toBeTruthy();
    });
});

describe('Welcome — self-hosted (reduced route table)', () => {
    // The published DIY build patches routes/web.php down to a much smaller
    // set: no register, terms, privacy or faqs. Ziggy throws on an unknown
    // route name, so an unguarded route() call blanks the entire page. This
    // reproduces that route table to catch the regression.
    const DIY_ROUTES = [
        'dashboard', 'login', 'logout',
        'campaigns.index', 'campaigns.create', 'campaigns.store',
        'campaigns.show', 'campaigns.edit', 'campaigns.update',
        'campaigns.destroy', 'campaigns.check-claims', 'campaigns.refund',
        'campaigns.download-qr',
        'codes.index', 'codes.create', 'codes.store', 'codes.show',
        'codes.edit', 'codes.update', 'codes.destroy',
        'known-assets.index', 'known-assets.lookup', 'known-assets.store',
    ];

    afterEach(() => {
        globalThis.setAvailableRoutes(null);
    });

    it('renders without throwing when marketing routes are absent', () => {
        globalThis.setAvailableRoutes(DIY_ROUTES);

        // canRegister is false in the self-hosted build — there is no
        // public registration.
        const wrapper = mountWelcome({ canRegister: false });

        expect(wrapper.html()).toContain('Onboard.Ninja');
        expect(wrapper.text()).not.toContain('Terms');
        expect(wrapper.text()).not.toContain('Privacy');
        expect(wrapper.text()).not.toContain('FAQs');
    });

    it('still shows the login button in the self-hosted build', () => {
        globalThis.setAvailableRoutes(DIY_ROUTES);
        const wrapper = mountWelcome({ canLogin: true, canRegister: false });
        expect(wrapper.text()).toContain('Log In');
    });

    it('shows marketing links when the SaaS routes are present', () => {
        globalThis.setAvailableRoutes(null);
        const wrapper = mountWelcome();
        expect(wrapper.text()).toContain('Terms');
        expect(wrapper.text()).toContain('Privacy');
        expect(wrapper.text()).toContain('FAQs');
    });
});
