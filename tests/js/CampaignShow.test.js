import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import CampaignShow from '../../resources/js/Pages/Campaign/Show.vue';

function makeCampaign(overrides = {}) {
    return {
        id: '01HQ1234567890ABCDEFGHIJ',
        name: 'Test Campaign',
        description: 'A test description',
        start_date: '2026-04-01',
        end_date: '2026-04-30',
        network: 'preprod',
        one_per_wallet: 0,
        txn_msg: null,
        nmkr_api_key: null,
        wallet: {
            address: 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3jcu5d8ps7zex2k2xt3uqxgjqnnj83ws8lhrn648jjxtwq2ytjc7',
        },
        codes: [],
        claims: [],
        needed_tokens: [],
        rewards: {},
        ...overrides,
    };
}

function mountShow(campaignOverrides = {}, propOverrides = {}) {
    return mount(CampaignShow, {
        props: {
            flash: {},
            campaign: makeCampaign(campaignOverrides),
            claim_url: 'https://beta.onbd.io/api/claim/v1/01HQ1234567890ABCDEFGHIJ',
            encoded_claim_url: 'https%3A%2F%2Fbeta.onbd.io%2Fapi%2Fclaim%2Fv1%2F01HQ1234567890ABCDEFGHIJ',
            balance: [],
            wallet_pending: false,
            backend_mismatch: false,
            wallet_backend: 'null',
            max_file_size: 10485760,
            ...propOverrides,
        },
        global: {
            stubs: {
                AuthenticatedLayout: {
                    template: '<div class="layout"><slot /></div>',
                },
                QrcodeVue: { template: '<div class="qr-stub" />' },
            },
        },
    });
}

describe('CampaignShow', () => {
    it('renders the campaign name', () => {
        const wrapper = mountShow({ name: 'Alpha Airdrop' });
        expect(wrapper.text()).toContain('Alpha Airdrop');
    });

    it('displays the campaign network', () => {
        const wrapper = mountShow({ network: 'mainnet' });
        expect(wrapper.text()).toContain('mainnet');
    });

    it('displays the wallet address', () => {
        const wrapper = mountShow({
            wallet: { address: 'addr_test1abc123' },
        });
        expect(wrapper.text()).toContain('addr_test1abc123');
    });

    it('shows the claim URL', () => {
        const wrapper = mountShow();
        expect(wrapper.text()).toContain('beta.onbd.io');
    });

    it('shows wallet pending message when wallet is not ready', () => {
        const wrapper = mountShow({}, { wallet_pending: true });
        expect(wrapper.text()).toContain('WALLET PROVISIONING');
    });

    it('renders code data table headers', () => {
        const wrapper = mountShow();
        const text = wrapper.text();
        expect(text).toContain('Code');
        expect(text).toContain('Uses');
        expect(text).toContain('Lovelace');
    });

    it('displays codes in the data table', () => {
        const codes = [
            {
                id: '01CODE001',
                code: 'TESTCODE1',
                uses: 5,
                perWallet: 1,
                lovelace: 2000000,
                rewards_count: 0,
                claims_count: 2,
                claims: [{ id: 'c1' }, { id: 'c2' }],
            },
        ];
        const wrapper = mountShow({ codes });
        expect(wrapper.text()).toContain('TESTCODE1');
    });

    it('shows empty state when no codes exist', () => {
        const wrapper = mountShow({ codes: [] });
        expect(wrapper.text()).toContain('No data available');
    });

    it('shows backend mismatch warning', () => {
        const wrapper = mountShow({}, { backend_mismatch: true });
        expect(wrapper.text()).toContain('BACKEND MISMATCH');
    });

    it('renders the campaign description', () => {
        const wrapper = mountShow({ description: 'Special event airdrop' });
        expect(wrapper.text()).toContain('Special event airdrop');
    });

    it('shows start and end dates', () => {
        const wrapper = mountShow({
            start_date: '2026-04-01',
            end_date: '2026-04-30',
        });
        expect(wrapper.text()).toContain('2026-04-01');
        expect(wrapper.text()).toContain('2026-04-30');
    });
});
