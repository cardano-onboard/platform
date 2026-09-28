import { describe, it, expect, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { usePage } from '@inertiajs/vue3';
import { h, nextTick } from 'vue';
import { VApp } from 'vuetify/components';
import BetaNoticeBar from '../../resources/js/Components/BetaNoticeBar.vue';
import DeploymentNoticeBar from '../../resources/js/Components/DeploymentNoticeBar.vue';

function mountBars(pageProps) {
    usePage.mockReturnValue({
        props: {
            auth: { user: null },
            errors: {},
            beta_banner: false,
            transaction_backend: 'phyrhose',
            ...pageProps,
        },
    });

    // In the order every page renders them.
    return mount(VApp, {
        attachTo: document.body,
        slots: { default: () => [h(DeploymentNoticeBar), h(BetaNoticeBar)] },
    });
}

async function settle() {
    await nextTick();
    await nextTick();
}

afterEach(() => {
    vi.restoreAllMocks();
    window.sessionStorage.clear();
    document.body.innerHTML = '';
});

describe('BetaNoticeBar', () => {
    it('renders the beta message as a warning when the beta banner is on', () => {
        const wrapper = mountBars({ beta_banner: true });

        const alert = wrapper.find('.notice-bar');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('This system is currently in beta.');
        expect(alert.classes().join(' ')).toContain('warning');
    });

    it('renders nothing when the beta banner is off', () => {
        const wrapper = mountBars({ beta_banner: false });

        expect(wrapper.find('.notice-bar').exists()).toBe(false);
    });

    it('stacks below the deployment notice instead of covering it', async () => {
        vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(72);

        const wrapper = mountBars({
            beta_banner: true,
            deployment_notice: { message: 'Moved', type: 'warning', link: null },
        });
        await settle();

        const items = wrapper.findAll('.v-layout-item');
        expect(items).toHaveLength(2);
        expect(items[0].text()).toContain('Moved');
        expect(items[1].text()).toContain('beta');
        expect(items[1].attributes('style')).toContain('top: 72px');
    });

    it('is dismissible, independently of the deployment notice', async () => {
        const pageProps = {
            beta_banner: true,
            deployment_notice: { message: 'Moved', type: 'warning', link: null },
        };
        const first = mountBars(pageProps);
        await first.findAll('.v-alert__close button')[1].trigger('click');
        first.unmount();

        // Dismissing the beta bar must not take the deployment notice with it.
        const next = mountBars(pageProps);
        const bars = next.findAll('.notice-bar');
        expect(bars).toHaveLength(1);
        expect(bars[0].text()).toContain('Moved');
    });
});
