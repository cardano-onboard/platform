import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import CampaignShow from '../../resources/js/Pages/Campaign/Show.vue';

/**
 * A balance the backend could not read is shown as unavailable, never as an empty bucket.
 */

function mountShow(balance) {
    return mount(CampaignShow, {
        attachTo: document.body,
        props: {
            flash: {},
            campaign: {
                id: '01HQ1234567890ABCDEFGHIJ',
                name: 'Test Campaign',
                description: '',
                start_date: '2026-04-01',
                end_date: '2026-04-30',
                network: 'preprod',
                one_per_wallet: 0,
                txn_msg: null,
                nmkr_api_key: null,
                wallet: { address: 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer' },
                codes: [],
                claims: [],
                rewards: {},
            },
            claim_url: 'https://beta.onbd.io/api/claim/v1/01HQ1234567890ABCDEFGHIJ',
            encoded_claim_url: 'https%3A%2F%2Fbeta.onbd.io',
            balance,
            wallet_pending: false,
            backend_mismatch: false,
            wallet_backend: 'native',
            max_file_size: 10485760,
        },
        global: {
            stubs: {
                AuthenticatedLayout: { template: '<div class="layout"><slot /></div>' },
                QrcodeVue: { template: '<div class="qr-stub" />' },
            },
        },
    });
}

describe('CampaignShow balance', () => {
    let wrapper;

    beforeEach(() => {
        window.axios = {
            get: vi.fn().mockResolvedValue({ data: [] }),
            post: vi.fn().mockResolvedValue({ data: {} }),
        };
    });

    afterEach(() => {
        wrapper?.unmount();
    });

    it('says the balance is unavailable when it could not be read', () => {
        wrapper = mountShow(null);

        expect(wrapper.text()).toContain('Balance unavailable');
        expect(wrapper.text()).not.toContain('Fund your campaign bucket');
    });

    it('calls a balance that was read and holds nothing empty', () => {
        wrapper = mountShow([]);

        expect(wrapper.text()).toContain('Empty');
        expect(wrapper.text()).not.toContain('Balance unavailable');
    });
});
