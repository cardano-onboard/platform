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

    it('renders the performance charts when codes exist', () => {
        const codes = [
            { id: '01CODE001', code: 'TESTCODE1', uses: 5, perWallet: 1, lovelace: 2000000, rewards_count: 0, claims_count: 2, claims: [] },
        ];
        const stats = {
            claims_over_time: [{ date: '2026-04-02', count: 2, cumulative: 2 }],
            claimed_vs_unclaimed: { claimed: 2, unclaimed: 3 },
            code_utilization: { total: 1, claimed: 1, unclaimed: 0, available: 1, exhausted: 0 },
        };
        const wrapper = mountShow({ codes }, { stats });
        const text = wrapper.text();
        expect(text).toContain('Claims Over Time');
        expect(text).toContain('Reward Slots');
        expect(text).toContain('Code Utilization');
    });

    it('hides the performance charts when there are no codes', () => {
        const wrapper = mountShow({ codes: [] });
        expect(wrapper.text()).not.toContain('Claims Over Time');
    });

    it('shows reward details with enriched token info when a code row is expanded', async () => {
        window.axios = {
            get: vi.fn().mockResolvedValue({
                data: { name: 'HOSKY Token', ticker: 'HOSKY', decimals: 0, logo: null },
            }),
        };

        const codes = [
            {
                id: '01CODE001',
                code: 'TESTCODE1',
                uses: 1,
                perWallet: 1,
                lovelace: 2000000,
                rewards_count: 1,
                claims_count: 0,
                claims: [],
                rewards: [
                    {
                        policy_hex: 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
                        asset_hex: '484f534b59',
                        quantity: 5,
                    },
                ],
            },
        ];

        const wrapper = mountShow({ codes });

        // Click the row-expand toggle rendered by v-data-table's show-expand.
        const expandIcon = wrapper.find('.v-data-table .mdi-chevron-down');
        expect(expandIcon.exists()).toBe(true);
        await expandIcon.trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));
        await wrapper.vm.$nextTick();

        const text = wrapper.text();
        expect(text).toContain('Reward Details');
        // Falls back to hex-decoded name immediately, then the mocked ticker resolves.
        expect(text).toContain('HOSKY');
        expect(window.axios.get).toHaveBeenCalled();
    });

    it('applies decimals and shows the raw on-chain base-unit count', async () => {
        window.axios = {
            get: vi.fn().mockResolvedValue({
                data: { name: 'USDM', ticker: 'USDM', decimals: 6, logo: null },
            }),
        };

        const codes = [
            {
                id: '01CODE001', code: 'TESTCODE1', uses: 1, perWallet: 1, lovelace: 2000000,
                rewards_count: 1, claims_count: 0, claims: [],
                rewards: [
                    {
                        policy_hex: 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad',
                        asset_hex: '0014df105553444d',
                        quantity: 10000000,
                    },
                ],
            },
        ];

        const wrapper = mountShow({ codes });
        const expandIcon = wrapper.find('.v-data-table .mdi-chevron-down');
        await expandIcon.trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));
        await wrapper.vm.$nextTick();

        const text = wrapper.text();
        // Clean amount (10) with a "6 decimals" indicator chip; the exact on-chain
        // base-unit count lives in the chip's tooltip (title attribute).
        expect(text).toContain('6 decimals');
        expect(wrapper.html()).toContain('10,000,000 base units');
    });

    it('renders a branded funding card with human token names instead of raw hex', async () => {
        window.axios = {
            get: vi.fn().mockResolvedValue({
                data: { name: 'USDM', ticker: 'USDM', decimals: 6, logo: null },
            }),
        };

        const wrapper = mountShow(
            {
                codes: [
                    { id: 'c1', code: 'X', uses: 1, perWallet: 1, lovelace: 5000000, rewards_count: 1, claims_count: 0, claims: [], rewards: [] },
                ],
                rewards: {
                    lovelace: 5000000,
                    'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad.0014df105553444d': 10000000,
                },
            },
            { balance: [] }, // empty wallet → still-needs-funding state
        );
        await new Promise((resolve) => setTimeout(resolve, 0));
        await wrapper.vm.$nextTick();

        const text = wrapper.text();
        expect(text).toContain('Fund your campaign bucket'); // branded card title (empty bucket)
        expect(text).toContain('USDM'); // human name, not hex
        expect(text).toContain('6 decimals'); // decimals indicator in the token row
        expect(text).not.toContain('0014df105553444d'); // raw asset hex is not shown
    });
});
