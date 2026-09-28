import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CampaignWalletClients from '../../resources/js/Components/CampaignWalletClients.vue';

function mountPanel(walletClients, endDate = '2026-04-30') {
    return mount(CampaignWalletClients, {
        props: { walletClients, endDate },
    });
}

const READY = {
    state: 'ready',
    floor: 10,
    clients: [
        { client: 'okhttp', wallet: 'Lace', share: 50 },
        { client: 'axios', wallet: 'VESPR', share: 30 },
        { client: 'unknown', wallet: null, share: 20 },
    ],
};

describe('CampaignWalletClients', () => {
    it('withholds the breakdown while the campaign is still running', () => {
        const wrapper = mountPanel({ state: 'running', floor: 10, clients: null });

        expect(wrapper.text()).toContain('once this campaign closes');
        expect(wrapper.text()).toContain('2026-04-30');
        expect(wrapper.text()).not.toContain('Lace');
    });

    it('withholds the breakdown when too few claims are recorded', () => {
        const wrapper = mountPanel({ state: 'too_few', floor: 10, clients: null });

        expect(wrapper.text()).toContain('at least 10 claims');
    });

    it('names each wallet and its share once the campaign has closed', () => {
        const wrapper = mountPanel(READY);

        expect(wrapper.text()).toContain('Lace');
        expect(wrapper.text()).toContain('50%');
        expect(wrapper.text()).toContain('VESPR');
        expect(wrapper.text()).toContain('30%');
    });

    it('labels a client that maps to no wallet rather than leaving it blank', () => {
        const wrapper = mountPanel(READY);

        expect(wrapper.text()).toContain('Not recognised');
    });

    // The width of each segment is the share, so a mis-scaled bar is a visible bug
    // rather than a silent one.
    it('sizes each bar segment to its share', () => {
        const wrapper = mountPanel(READY);
        const widths = wrapper
            .findAll('[role="img"] > div')
            .map((segment) => segment.attributes('style'));

        expect(widths).toEqual([
            'width: 50%;',
            'width: 30%;',
            'width: 20%;',
        ]);
    });

    it('describes the whole bar for a screen reader', () => {
        const wrapper = mountPanel(READY);

        expect(wrapper.find('[role="img"]').attributes('aria-label')).toBe(
            'Share of claims by wallet: Lace 50 percent, VESPR 30 percent, Not recognised 20 percent',
        );
    });

    it('tells an administrator when they are seeing it before the operator can', () => {
        const wrapper = mountPanel({ ...READY, state: 'early' });

        expect(wrapper.text()).toContain('as an administrator');
        expect(wrapper.text()).toContain('Lace');
    });

    it('does not claim an early view when the campaign has closed', () => {
        const wrapper = mountPanel(READY);

        expect(wrapper.text()).not.toContain('as an administrator');
    });
});
