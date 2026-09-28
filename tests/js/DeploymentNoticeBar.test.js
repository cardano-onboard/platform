import { describe, it, expect, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick } from 'vue';
import { VApp, VSystemBar } from 'vuetify/components';
import { usePage } from '@inertiajs/vue3';
import DeploymentNoticeBar from '../../resources/js/Components/DeploymentNoticeBar.vue';

function mountBar(deploymentNotice) {
    usePage.mockReturnValue({
        props: {
            auth: { user: null },
            errors: {},
            beta_banner: false,
            transaction_backend: 'phyrhose',
            deployment_notice: deploymentNotice,
        },
    });

    // The bar is a Vuetify layout item, which only exists inside v-app, as it is on every
    // page that renders it.
    return mount(VApp, {
        attachTo: document.body,
        slots: {
            default: () => [
                h(DeploymentNoticeBar),
                h(VSystemBar, { height: 32, class: 'following-bar' }, () => 'Beta'),
            ],
        },
    });
}

afterEach(() => {
    vi.restoreAllMocks();
    window.sessionStorage.clear();
    document.body.innerHTML = '';
});

describe('DeploymentNoticeBar', () => {
    it('renders nothing when no notice prop is shared', () => {
        const wrapper = mountBar(undefined);

        expect(wrapper.find('.notice-bar').exists()).toBe(false);
        expect(wrapper.find('.v-layout-item').exists()).toBe(false);
    });

    it('renders the configured message', () => {
        const wrapper = mountBar({
            message: 'This platform is now preprod only.',
            type: 'info',
            link: null,
        });

        expect(wrapper.text()).toContain('This platform is now preprod only.');
    });

    it('renders the link with its text and href when one is configured', () => {
        const wrapper = mountBar({
            message: 'This platform is now preprod only.',
            type: 'warning',
            link: { url: 'https://example.com', text: 'Go to the main app' },
        });

        const link = wrapper.find('a.notice-bar__link');
        expect(link.exists()).toBe(true);
        expect(link.text()).toBe('Go to the main app');
        expect(link.attributes('href')).toBe('https://example.com');
        // Opens in a new tab rather than navigating the claim/operator session away,
        // and without handing the opened tab a reference back to this window.
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toContain('noopener');
    });

    it('renders no link element when none is configured', () => {
        const wrapper = mountBar({
            message: 'This platform is now preprod only.',
            type: 'info',
            link: null,
        });

        expect(wrapper.find('a.notice-bar__link').exists()).toBe(false);
    });

    it('applies the warning color for type warning', () => {
        const wrapper = mountBar({ message: 'Careful', type: 'warning', link: null });

        expect(wrapper.find('.v-alert').classes().join(' ')).toContain('warning');
    });

    it('applies the info color for type info', () => {
        const wrapper = mountBar({ message: 'FYI', type: 'info', link: null });

        expect(wrapper.find('.v-alert').classes().join(' ')).toContain('info');
    });

    it('escapes HTML in the message instead of rendering it', () => {
        const wrapper = mountBar({
            message: '<b>bold</b><img src=x onerror=alert(1)>',
            type: 'info',
            link: null,
        });

        // Vue text interpolation writes the string as text, so the tags show up as
        // literal characters and no <b> or <img> element is ever created.
        expect(wrapper.find('.notice-bar__message b').exists()).toBe(false);
        expect(wrapper.find('.notice-bar__message img').exists()).toBe(false);
        expect(wrapper.text()).toContain('<b>bold</b><img src=x onerror=alert(1)>');
    });

    it('escapes HTML in the link text instead of rendering it', () => {
        const wrapper = mountBar({
            message: 'Notice',
            type: 'info',
            link: { url: 'https://example.com', text: '<script>alert(1)</script>' },
        });

        expect(wrapper.find('.notice-bar__link script').exists()).toBe(false);
        expect(wrapper.text()).toContain('<script>alert(1)</script>');
    });

    it('is dismissible', async () => {
        const wrapper = mountBar({ message: 'Notice', type: 'info', link: null });

        expect(wrapper.find('.notice-bar').exists()).toBe(true);

        const closeButton = wrapper.find('.v-alert__close button');
        expect(closeButton.exists()).toBe(true);

        await closeButton.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('.notice-bar').exists()).toBe(false);
    });

    it('pushes the bars that follow it down by its own measured height', async () => {
        // jsdom lays nothing out, so report the height a wrapped two-line notice has.
        vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(72);

        const wrapper = mountBar({ message: 'Notice', type: 'warning', link: null });
        await nextTick();
        await nextTick();

        // Before the bar joined the layout, the system bar sat at top 0 and covered it.
        expect(wrapper.find('.following-bar').attributes('style')).toContain('top: 72px');
    });

    it('gives its space back when dismissed', async () => {
        vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(72);

        const wrapper = mountBar({ message: 'Notice', type: 'info', link: null });
        await nextTick();
        await nextTick();

        await wrapper.find('.v-alert__close button').trigger('click');
        await nextTick();
        await nextTick();

        expect(wrapper.find('.following-bar').attributes('style')).toContain('top: 0px');
    });

    it('stays dismissed on the next page in the same tab', async () => {
        const notice = { message: 'Moved', type: 'warning', link: null };
        const first = mountBar(notice);
        await first.find('.v-alert__close button').trigger('click');
        first.unmount();

        // Every Inertia visit and every refresh mounts the layout, and this bar, afresh.
        const next = mountBar(notice);

        expect(next.find('.notice-bar').exists()).toBe(false);
    });

    it('shows again when the message changes', async () => {
        const first = mountBar({ message: 'Moved', type: 'warning', link: null });
        await first.find('.v-alert__close button').trigger('click');
        first.unmount();

        const next = mountBar({ message: 'Moved again', type: 'warning', link: null });

        expect(next.text()).toContain('Moved again');
    });

    it('still shows and dismisses when storage is unavailable', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });

        const wrapper = mountBar({ message: 'Moved', type: 'warning', link: null });
        expect(wrapper.find('.notice-bar').exists()).toBe(true);

        await wrapper.find('.v-alert__close button').trigger('click');
        await nextTick();

        expect(wrapper.find('.notice-bar').exists()).toBe(false);
    });
});
