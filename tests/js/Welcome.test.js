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

    it('keeps the theme toggle below both notices', async () => {
        // jsdom lays nothing out, so give each notice the height of a wrapped message.
        vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(50);

        const wrapper = mountWelcome({}, {
            beta_banner: true,
            deployment_notice: { message: 'Moved', type: 'warning', link: null },
        });
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        const toggle = wrapper.find('.mdi-weather-night').element.closest('div[style]');
        expect(toggle.getAttribute('style')).toContain('top: 108px');

        vi.restoreAllMocks();
    });

    it('renders footer links', () => {
        const wrapper = mountWelcome();
        expect(wrapper.text()).toContain('Terms');
        expect(wrapper.text()).toContain('Privacy');
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
    // set: no register, terms or privacy. Ziggy throws on an unknown
    // route name, so an unguarded route() call blanks the entire page. This
    // reproduces that route table to catch the regression.
    const DIY_ROUTES = [
        'dashboard', 'login', 'logout',
        'campaigns.store', 'campaigns.show', 'campaigns.update',
        'campaigns.destroy', 'campaigns.check-claims', 'campaigns.refund',
        'campaigns.download-qr',
        'codes.store', 'codes.destroy',
        'known-assets.index', 'known-assets.lookup', 'known-assets.lookup-many',
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
    });

    it('leaves the register button out when no register route exists, whatever the prop says', () => {
        globalThis.setAvailableRoutes(DIY_ROUTES);

        // The prop and the route table are two different answers to the same question, and
        // only the route table decides whether route('register') throws. A build that says
        // registration is on while the route is gone has to render the page anyway.
        const wrapper = mountWelcome({ canRegister: true });

        expect(wrapper.text()).not.toContain('Register');
        expect(wrapper.text()).toContain('Onboard.Ninja');
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
    });
});
