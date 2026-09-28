import { describe, it, expect, vi, afterEach } from 'vitest';
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

// The page looks reward-token metadata up in batches over post, and quotes a minimum UTxO
// over post too. Every asset a batch asks about is answered with `meta`.
function axiosAnswering(meta) {
    return {
        get: vi.fn().mockResolvedValue({ data: [] }),
        post: vi.fn((url, body) =>
            Promise.resolve(
                url === '/known-assets/lookup-many'
                    ? {
                          data: Object.fromEntries(
                              body.assets.map((a) => [a.policy + a.asset_name, meta]),
                          ),
                      }
                    : { data: { min_lovelace: 1000000, recommended_lovelace: 2000000, asset_count: 1 } },
            ),
        ),
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
        window.axios = axiosAnswering({ name: 'HOSKY Token', ticker: 'HOSKY', decimals: 0, logo: null });

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
        expect(window.axios.post).toHaveBeenCalledWith('/known-assets/lookup-many', expect.anything());
    });

    // A reload of a campaign with hundreds of reward NFTs must not look them all up again.
    it('asks nothing about a reward asset the page arrived with', async () => {
        window.axios = axiosAnswering({ name: 'Wrong', ticker: null, decimals: 0, logo: null });
        const policy = 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235';
        const codes = [
            {
                id: '01CODE001', code: 'TESTCODE1', uses: 1, perWallet: 1, lovelace: 2000000,
                rewards_count: 2, claims_count: 0, claims: [],
                rewards: [
                    { policy_hex: policy, asset_hex: '484f534b59', quantity: 5 },
                    { policy_hex: policy, asset_hex: '6e6f6e65', quantity: 1 },
                ],
            },
        ];

        const wrapper = mountShow({ codes }, {
            asset_meta: {
                [`${policy}484f534b59`]: { name: 'HOSKY Token', ticker: 'HOSKY', decimals: 0, logo: null },
                [`${policy}6e6f6e65`]: null,
            },
        });
        await new Promise((resolve) => setTimeout(resolve, 0));
        await wrapper.vm.$nextTick();

        const lookups = window.axios.post.mock.calls.filter(([url]) => url === '/known-assets/lookup-many');
        expect(lookups).toHaveLength(0);
        expect(wrapper.vm.rewardDisplayName({ policy_hex: policy, asset_hex: '484f534b59' })).toBe('HOSKY');
    });

    // The server keys asset_meta in lowercase; a reward stored in uppercase must still match.
    it('finds arrived metadata for a reward whose hex is stored in uppercase', async () => {
        window.axios = axiosAnswering({ name: 'Wrong', ticker: null, decimals: 0, logo: null });
        const policy = 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235';
        const codes = [
            {
                id: '01CODE001', code: 'TESTCODE1', uses: 1, perWallet: 1, lovelace: 2000000,
                rewards_count: 1, claims_count: 0, claims: [],
                rewards: [{ policy_hex: policy.toUpperCase(), asset_hex: '484F534B59', quantity: 5 }],
            },
        ];

        const wrapper = mountShow({ codes }, {
            asset_meta: { [`${policy}484f534b59`]: { name: 'HOSKY Token', ticker: 'HOSKY', decimals: 0, logo: null } },
        });
        await new Promise((resolve) => setTimeout(resolve, 0));

        const lookups = window.axios.post.mock.calls.filter(([url]) => url === '/known-assets/lookup-many');
        expect(lookups).toHaveLength(0);
        expect(wrapper.vm.rewardDisplayName({ policy_hex: policy.toUpperCase(), asset_hex: '484F534B59' })).toBe('HOSKY');
    });

    it('applies decimals and shows the raw on-chain base-unit count', async () => {
        window.axios = axiosAnswering({ name: 'USDM', ticker: 'USDM', decimals: 6, logo: null });

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
        window.axios = axiosAnswering({ name: 'USDM', ticker: 'USDM', decimals: 6, logo: null });

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

    // The top-up figure has always covered the fees as well as the rewards, so a full
    // bucket serves every code that can still be claimed. It was one number, though, and
    // an operator asked to send ADA could not see how much of it reached claimants.
    it('breaks the ADA the funding card asks for into what it is for', async () => {
        const wrapper = mountShow(
            { rewards: { lovelace: 18000000 } },
            {
                balance: [], // empty bucket, so the shortfall is the whole of the need
                funding: {
                    remaining_claims: 9,
                    reward_lovelace: 18000000,
                    network_fee_lovelace: 1800000,
                    platform_fee_lovelace: 9000000,
                },
            },
        );
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.fundingParts.map((part) => part.label)).toEqual([
            'Rewards still to pay out',
            'Network fees',
            'Platform fees',
        ]);
        expect(wrapper.vm.fundingParts.map((part) => part.value)).toEqual([18000000, 1800000, 9000000]);

        // The three are the whole of the figure the card asks for rather than a sample
        // of it: 18 to claimants, 1.8 to the chain and 9 to us is the 28.8 it wants.
        expect(wrapper.vm.campaign_needs.lovelace).toBe(28800000n);
        expect(wrapper.vm.wallet_missing.lovelace).toBe('28800000');

        expect(wrapper.text()).toContain('Rewards still to pay out');
        expect(wrapper.text()).toContain('Taken from this bucket as each claim is sent.');
    });

    it('leaves the platform fee out of the funding card where the deployment does not charge', async () => {
        const wrapper = mountShow(
            { rewards: { lovelace: 18000000 } },
            {
                balance: [],
                funding: {
                    remaining_claims: 9,
                    reward_lovelace: 18000000,
                    network_fee_lovelace: 1800000,
                    platform_fee_lovelace: null,
                },
            },
        );
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.fundingParts.map((part) => part.label)).toEqual([
            'Rewards still to pay out',
            'Network fees',
        ]);
        // Absent rather than zero, and the figure asked for is short by exactly that line.
        expect(wrapper.vm.campaign_needs.lovelace).toBe(19800000n);
        expect(wrapper.text()).not.toContain('Taken from this bucket as each claim is sent.');
    });
});

// The edit dialog's selector is a courtesy: the controller is what accepts or refuses a
// network. It still has to keep the campaign's own network in the list, because a
// deployment that stops allowing mainnet would otherwise leave the select empty and no
// other edit to a grandfathered campaign could be saved.
describe('CampaignShow network selector', () => {
    function networkOptions(wrapper) {
        return wrapper.vm.networkOptions;
    }

    it('offers only the networks the deployment accepts', () => {
        const wrapper = mountShow({ network: 'preprod' }, { allowed_networks: ['preprod', 'preview'] });

        expect(networkOptions(wrapper)).toEqual(['preprod', 'preview']);
    });

    it('keeps the campaign on an excluded network selectable', () => {
        const wrapper = mountShow({ network: 'mainnet' }, { allowed_networks: ['preprod', 'preview'] });

        expect(networkOptions(wrapper)).toContain('mainnet');
    });

    it('does not offer a second excluded network to a grandfathered campaign', () => {
        const wrapper = mountShow({ network: 'mainnet' }, { allowed_networks: ['preprod'] });

        expect(networkOptions(wrapper)).toEqual(['preprod', 'mainnet']);
        expect(networkOptions(wrapper)).not.toContain('preview');
    });
    /**
     * Who a batch is being handed to is asked where the batch is made, because that is the
     * only place it can honestly be asked: a code attributed to a partner after its claims
     * are in was not necessarily given away by them.
     */
    describe('the partner picker', () => {
        const PARTNERS = [
            { id: '01HAAAAAAAAAAAAAAAAAAAAAAA', name: 'Vendor A', kind: 'partner' },
            { id: '01HBBBBBBBBBBBBBBBBBBBBBBB', name: 'Booth Staff', kind: 'staff' },
        ];

        async function openDialog(wrapper, icon) {
            const button = wrapper.findAll('button').find((candidate) => candidate.find(icon).exists());
            expect(button, `no button carrying ${icon}`).toBeTruthy();

            await button.trigger('click');
            await new Promise((resolve) => setTimeout(resolve, 0));

            return document.body.textContent;
        }

        afterEach(() => {
            document.body.innerHTML = '';
        });

        /**
         * The control says Partner, the same word the codes table and the export use. An
         * operator reading a column called Partner has to be able to find the control that
         * set it, and "Given to" was a second name for one thing.
         */
        it('asks who the codes are for when a batch is created', async () => {
            const wrapper = mountShow({}, {
                partners: PARTNERS,
                default_partner_id: null,
            });

            const dialog = await openDialog(wrapper, '.mdi-plus');

            expect(dialog).toContain('Partner');
            expect(dialog).not.toContain('Given to');
        });

        it('asks the same question of an import', async () => {
            const wrapper = mountShow({}, {
                partners: PARTNERS,
                default_partner_id: null,
            });

            const dialog = await openDialog(wrapper, '.mdi-cloud-upload');

            expect(dialog).toContain('Partner');
            expect(dialog).not.toContain('Given to');
        });

        /**
         * Generating another batch for the same person is the repeated action at a booth.
         * Unassigned is the one answer that cannot be put right afterwards, so it is not
         * where the control should start once there is a better answer to start on.
         */
        it('starts on the partner the last batch was generated for', async () => {
            const wrapper = mountShow({}, {
                partners: PARTNERS,
                default_partner_id: PARTNERS[1].id,
            });

            expect(await openDialog(wrapper, '.mdi-plus')).toContain('Booth Staff');
        });

        it('starts on Unassigned where no batch has been attributed yet', async () => {
            const wrapper = mountShow({}, {
                partners: PARTNERS,
                default_partner_id: null,
            });

            expect(await openDialog(wrapper, '.mdi-plus')).toContain('Unassigned');
        });
    });

    /**
     * Reading the attribution back.
     *
     * The picker writes who a batch was generated for, and until the table showed it there
     * was nowhere in the product to find out what a batch had been stamped with. These
     * cover the column and the filter over it.
     */
    describe('the partner column', () => {
        const PARTNERS = [
            { id: '01HAAAAAAAAAAAAAAAAAAAAAAA', name: 'Vendor A', kind: 'partner' },
            { id: '01HBBBBBBBBBBBBBBBBBBBBBBB', name: 'Booth Staff', kind: 'staff' },
        ];

        function code(overrides = {}) {
            return {
                id: '01CODE001',
                code: 'TESTCODE1',
                uses: 5,
                perWallet: 1,
                lovelace: 2000000,
                rewards_count: 0,
                claims_count: 0,
                claims: [],
                partner_id: null,
                partner: null,
                ...overrides,
            };
        }

        /** The table's own Partner filter, which is a select the page renders beside Search. */
        function partnerFilter(wrapper) {
            const select = wrapper
                .findAll('.v-select')
                .find((candidate) => candidate.text().includes('Partner'));
            expect(select, 'no Partner filter on the page').toBeTruthy();

            return select;
        }

        async function chooseFilter(wrapper, title) {
            const select = partnerFilter(wrapper);

            await select.find('.v-field').trigger('mousedown');
            await select.find('.v-field').trigger('click');
            await new Promise((resolve) => setTimeout(resolve, 0));

            const option = [...document.body.querySelectorAll('.v-list-item')].find(
                (item) => item.textContent.trim() === title,
            );
            expect(option, `no filter option titled ${title}`).toBeTruthy();

            option.click();
            await new Promise((resolve) => setTimeout(resolve, 0));
        }

        afterEach(() => {
            document.body.innerHTML = '';
        });

        it('names the partner a batch was generated for', () => {
            const wrapper = mountShow(
                {
                    codes: [
                        code({
                            partner_id: PARTNERS[0].id,
                            partner: { id: PARTNERS[0].id, name: 'Vendor A' },
                        }),
                    ],
                },
                { partners: PARTNERS },
            );

            expect(wrapper.text()).toContain('Partner');
            expect(wrapper.text()).toContain('Vendor A');
        });

        /**
         * Unassigned is an answer, not a blank. A batch generated with nobody in mind is the
         * ordinary case on every campaign that predates partners, and an empty cell reads as
         * a value that failed to load.
         */
        it('says Unassigned for a batch generated for nobody', () => {
            const wrapper = mountShow(
                { codes: [code()] },
                { partners: PARTNERS },
            );

            expect(wrapper.text()).toContain('Unassigned');
        });

        /**
         * A partner removed from the picker keeps the codes it was given, so the table still
         * names it. Blanking those rows would say the codes were handed out by nobody.
         */
        it('still names a partner that has been removed', () => {
            const wrapper = mountShow(
                {
                    codes: [
                        code({
                            partner_id: '01HZZZZZZZZZZZZZZZZZZZZZZZ',
                            partner: { id: '01HZZZZZZZZZZZZZZZZZZZZZZZ', name: 'Gone Vendor' },
                        }),
                    ],
                },
                { partners: [] },
            );

            expect(wrapper.text()).toContain('Gone Vendor');
        });

        /**
         * A campaign that has never used a partner gets neither the column nor the filter. A
         * column of Unassigned on every row tells the operator nothing, and a filter with
         * one thing to filter by is a control that does nothing.
         */
        it('leaves the column and the filter out where no partner has been used', () => {
            const wrapper = mountShow(
                { codes: [code()] },
                { partners: [] },
            );

            expect(wrapper.text()).not.toContain('Unassigned');
            expect(
                wrapper.findAll('.v-select').find((s) => s.text().includes('Partner')),
            ).toBeUndefined();
        });

        it('narrows the table to one partners codes', async () => {
            const wrapper = mountShow(
                {
                    codes: [
                        code({
                            id: '01CODE001',
                            code: 'VENDORCODE',
                            partner_id: PARTNERS[0].id,
                            partner: { id: PARTNERS[0].id, name: 'Vendor A' },
                        }),
                        code({
                            id: '01CODE002',
                            code: 'STAFFCODE',
                            partner_id: PARTNERS[1].id,
                            partner: { id: PARTNERS[1].id, name: 'Booth Staff' },
                        }),
                    ],
                },
                { partners: PARTNERS },
            );

            expect(wrapper.text()).toContain('VENDORCODE');
            expect(wrapper.text()).toContain('STAFFCODE');

            await chooseFilter(wrapper, 'Vendor A');

            expect(wrapper.text()).toContain('VENDORCODE');
            expect(wrapper.text()).not.toContain('STAFFCODE');
        });

        it('narrows the table to the codes nobody was given', async () => {
            const wrapper = mountShow(
                {
                    codes: [
                        code({
                            id: '01CODE001',
                            code: 'VENDORCODE',
                            partner_id: PARTNERS[0].id,
                            partner: { id: PARTNERS[0].id, name: 'Vendor A' },
                        }),
                        code({ id: '01CODE002', code: 'NOBODYCODE' }),
                    ],
                },
                { partners: PARTNERS },
            );

            await chooseFilter(wrapper, 'Unassigned');

            expect(wrapper.text()).toContain('NOBODYCODE');
            expect(wrapper.text()).not.toContain('VENDORCODE');
        });

        /**
         * A removed partner is off the picker but still on its codes, so it has to be on the
         * filter too. Without it those rows are in the table and no filter reaches them.
         */
        it('offers a removed partner that still has codes', async () => {
            const wrapper = mountShow(
                {
                    codes: [
                        code({
                            id: '01CODE001',
                            code: 'GONECODE',
                            partner_id: '01HZZZZZZZZZZZZZZZZZZZZZZZ',
                            partner: { id: '01HZZZZZZZZZZZZZZZZZZZZZZZ', name: 'Gone Vendor' },
                        }),
                        code({
                            id: '01CODE002',
                            code: 'LIVECODE',
                            partner_id: PARTNERS[0].id,
                            partner: { id: PARTNERS[0].id, name: 'Vendor A' },
                        }),
                    ],
                },
                { partners: PARTNERS },
            );

            await chooseFilter(wrapper, 'Gone Vendor (removed)');

            expect(wrapper.text()).toContain('GONECODE');
            expect(wrapper.text()).not.toContain('LIVECODE');
        });
    });
});

// Three operator settings existed end to end in the backend with nothing in the
// interface that could reach them. What is under test here is the reachable half: what
// the controls are seeded with, what they send, and where they are offered at all.
describe('CampaignShow alert settings', () => {
    it('seeds the fields from what is stored, not from what is in force', () => {
        const wrapper = mountShow(
            { alerts_enabled: 0, alert_threshold_claims: 42 },
            { alerts: { enabled: false, threshold: 42, claims_remaining: 3, held: 0, alerting: true, reason: 'low' } },
        );

        expect(wrapper.vm.editForm.alerts_enabled).toBe(0);
        expect(wrapper.vm.editForm.alert_threshold_claims).toBe(42);
    });

    // The default in force is ten. Seeding the box with it would write ten in as the
    // operator's own choice the first time they saved anything else in the dialog.
    it('leaves a threshold nobody has set empty rather than filling in the default', () => {
        const wrapper = mountShow(
            { alerts_enabled: 1, alert_threshold_claims: null },
            { alerts: { enabled: true, threshold: 10, claims_remaining: 40, held: 0, alerting: false, reason: null } },
        );

        expect(wrapper.vm.editForm.alert_threshold_claims).toBeNull();
        expect(wrapper.vm.editForm.alerts_enabled).toBe(1);
    });

    it('sends an emptied threshold as none at all rather than as a threshold of zero', () => {
        const wrapper = mountShow({ alert_threshold_claims: 42 });

        wrapper.vm.editForm.alert_threshold_claims = '';
        wrapper.vm.submitEdit();

        expect(wrapper.vm.editForm.alert_threshold_claims).toBeNull();
        expect(wrapper.vm.editForm.patch).toHaveBeenCalledWith(
            expect.stringContaining('campaigns/update'),
            expect.any(Object),
        );
    });

    it('sends a typed threshold as a number', () => {
        const wrapper = mountShow({ alert_threshold_claims: null });

        wrapper.vm.editForm.alert_threshold_claims = '25';
        wrapper.vm.submitEdit();

        expect(wrapper.vm.editForm.alert_threshold_claims).toBe(25);
    });

    // The threshold is in claims on purpose, because that means the same thing however
    // the campaign is billed. Showing what it will be compared against is what stops the
    // number being a guess.
    it('says how many more claims the campaign can serve beside the threshold', () => {
        const wrapper = mountShow(
            {},
            { alerts: { enabled: true, threshold: 10, claims_remaining: 8, held: 0, alerting: true, reason: 'low' } },
        );

        expect(wrapper.vm.alertThresholdHint).toContain('In claims, not ADA');
        expect(wrapper.vm.alertThresholdHint).toContain('8 more claim(s)');
    });

    it('claims nothing about what is left where the figure cannot be worked out', () => {
        const wrapper = mountShow(
            {},
            { alerts: { enabled: true, threshold: 10, claims_remaining: null, held: 0, alerting: false, reason: null } },
        );

        expect(wrapper.vm.alertThresholdHint).not.toContain('more claim(s)');
    });

    it('puts both controls in the edit dialog', async () => {
        const wrapper = mountShow({}, {});

        wrapper.vm.openEditDialog();
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));

        const text = document.body.textContent;
        expect(text).toContain('Warn me when this campaign is running short');
        expect(text).toContain('Warn me with this many claims left');
        // The control must not imply a campaign warns outside its own claim window.
        expect(text).toContain('Nothing is warned about before it starts or after it ends.');

        wrapper.unmount();
    });
});

describe('CampaignShow spend limit', () => {
    afterEach(() => {
        setAvailableRoutes(null);
    });

    const spending = (overrides = {}) => ({
        spending: { applies: true, limit_credits: null, spent_credits: '0', ...overrides },
    });

    it('is offered where a limit can bite', () => {
        const wrapper = mountShow({}, spending());

        expect(wrapper.vm.canSetSpendLimit).toBe(true);
        expect(wrapper.text()).toContain('Spend limit');
    });

    // A path already paid from the campaign's own bucket, or a deployment that is not
    // charging, cannot hold anything against a limit. The control goes rather than sitting
    // there unable to do anything, the way the funding card drops its platform fee line.
    it('is left out where a limit could not do anything', () => {
        const wrapper = mountShow({}, spending({ applies: false }));

        expect(wrapper.vm.canSetSpendLimit).toBe(false);
        expect(wrapper.text()).not.toContain('Spend limit');
    });

    // The published edition ships without the billing routes, and Ziggy throws on a name
    // that is not in its table, so a page that asked for one would blank instead.
    it('is left out where the deployment has no endpoint to set it with', () => {
        setAvailableRoutes(['campaigns.update', 'campaigns.refund', 'campaigns.check-claims']);

        const wrapper = mountShow({}, spending());

        expect(wrapper.vm.canSetSpendLimit).toBe(false);
        expect(wrapper.text()).not.toContain('Spend limit');
    });

    it('says what the limit is and what has been spent against it', () => {
        const wrapper = mountShow({}, spending({ limit_credits: '150', spent_credits: '12.5' }));

        expect(wrapper.vm.spendLimitSummary).toContain('150 credit(s)');
        expect(wrapper.vm.spendLimitSummary).toContain('12.5 spent');
        expect(wrapper.vm.spendLimitSummary).toContain('wait rather than fail');
    });

    it('says there is no limit rather than showing one of zero', () => {
        const wrapper = mountShow({}, spending({ limit_credits: null }));

        expect(wrapper.vm.spendLimitSummary).toContain('No limit');
        expect(wrapper.vm.spendLimitSummary).not.toContain('0 credit(s)');
    });

    it('opens with the limit already set', () => {
        const wrapper = mountShow({}, spending({ limit_credits: '150' }));

        wrapper.vm.openSpendLimitDialog();

        expect(wrapper.vm.spendLimitForm.spend_limit_credits).toBe('150');
        expect(wrapper.vm.dialog.spend_limit).toBe(true);
    });

    it('sends the figure as it was typed', () => {
        const wrapper = mountShow({}, spending());

        wrapper.vm.spendLimitForm.spend_limit_credits = ' 8.7 ';
        wrapper.vm.submitSpendLimit();

        expect(wrapper.vm.spendLimitForm.spend_limit_credits).toBe('8.7');
        expect(wrapper.vm.spendLimitForm.patch).toHaveBeenCalledWith(
            expect.stringContaining('campaigns/spend-limit'),
            expect.any(Object),
        );
    });

    it('sends an empty box as no limit rather than as a limit of nothing', () => {
        const wrapper = mountShow({}, spending({ limit_credits: '150' }));

        wrapper.vm.spendLimitForm.spend_limit_credits = '';
        wrapper.vm.submitSpendLimit();

        expect(wrapper.vm.spendLimitForm.spend_limit_credits).toBeNull();
    });

    // A credit is stored in millionths, so a seventh decimal place is precision that
    // cannot survive. Refused at the box rather than truncated on the way in.
    it('refuses more precision than a credit has before anything is sent', () => {
        const wrapper = mountShow({}, spending());
        const [rule] = wrapper.vm.spendLimitRules;
        const message = 'Enter a number of credits, to at most six decimal places.';

        expect(rule('150')).toBe(true);
        expect(rule('8.7')).toBe(true);
        expect(rule('0.000001')).toBe(true);
        expect(rule('')).toBe(true);
        expect(rule(null)).toBe(true);
        expect(rule('1.9999999')).toBe(message);
        expect(rule('-5')).toBe(message);
        expect(rule('soon')).toBe(message);
    });
});

// The action most likely to be needed before the onboarding analysis, which cannot see a
// claim until its status has been checked. It was an icon with a tooltip, sitting above
// the codes table, and it was the hardest control on the page to find.
describe('CampaignShow check claims control', () => {
    function checkClaimsButton(wrapper) {
        return wrapper
            .findAllComponents({ name: 'VBtn' })
            .find((candidate) => candidate.text().includes('Check claims'));
    }

    it('carries a label rather than an icon alone', () => {
        const wrapper = mountShow();

        expect(checkClaimsButton(wrapper)).toBeTruthy();
    });

    // The check reports itself through the page's poller like every other job, so the page
    // shows it running and reloads the claims when it finishes. Nobody is told to refresh.
    const checking = {
        id: 'task-check',
        type: 'check-claims',
        status: 'running',
        stage: null,
        progress_done: 3,
        progress_total: 10,
        error: null,
        result: null,
        started_at: '2026-09-15T10:00:00+00:00',
        completed_at: null,
        reloads: ['campaign', 'onboarding'],
    };

    function withCheck(task) {
        return { tasks: { now: '2026-09-15T10:00:00+00:00', items: [task] } };
    }

    it('shows a running check with the other background work', () => {
        const wrapper = mountShow({}, withCheck(checking));

        expect(wrapper.text()).toContain('Checking claims');
        expect(wrapper.text()).toContain('3 of 10');
    });

    it('holds the button while a check is running', () => {
        const wrapper = mountShow({}, withCheck(checking));

        expect(checkClaimsButton(wrapper).props('loading')).toBe(true);
    });

    it('frees the button once the check has finished', () => {
        const wrapper = mountShow(
            {},
            withCheck({ ...checking, status: 'complete', completed_at: '2026-09-15T10:00:05+00:00' }),
        );

        expect(checkClaimsButton(wrapper).props('loading')).toBe(false);
    });

    it('says why a check failed', () => {
        const wrapper = mountShow(
            {},
            withCheck({
                ...checking,
                status: 'failed',
                error: 'Another status check for this campaign was still running.',
                completed_at: '2026-09-15T10:00:05+00:00',
            }),
        );

        expect(wrapper.text()).toContain('Checking claims failed');
        expect(wrapper.text()).toContain('still running');
    });

    it('still posts the check when it is used', async () => {
        const { router } = await import('@inertiajs/vue3');
        const wrapper = mountShow();

        await checkClaimsButton(wrapper).trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/campaigns/check-claims/01HQ1234567890ABCDEFGHIJ',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});

// Background work. Six jobs run behind this page and none of them used to say anything,
// so the operator's only way to find out whether an import had finished was to reload.
describe('CampaignShow background work', () => {
    function withTask(task) {
        return { tasks: { now: '2026-09-15T10:00:00+00:00', items: [task] } };
    }

    const importing = {
        id: 'task-1',
        type: 'codes-import',
        status: 'running',
        stage: 'Creating codes',
        progress_done: 312,
        progress_total: 1000,
        error: null,
        result: null,
        started_at: '2026-09-15T10:00:00+00:00',
        completed_at: null,
        reloads: ['campaign', 'stats'],
    };

    it('shows what is running and how far along it is', () => {
        const wrapper = mountShow({}, withTask(importing));

        expect(wrapper.text()).toContain('Importing codes');
        expect(wrapper.text()).toContain('Creating codes');
        expect(wrapper.text()).toContain('312 of 1000');
    });

    it('shows a share of the bar that matches the figures', () => {
        const wrapper = mountShow({}, withTask(importing));

        expect(wrapper.vm.taskPercent(importing)).toBe(31);
    });

    it('leaves the bar indeterminate where the job could not count its work', () => {
        const wrapper = mountShow(
            {},
            withTask({ ...importing, progress_done: 0, progress_total: null }),
        );

        expect(wrapper.vm.taskPercent({ ...importing, progress_total: null })).toBe(0);
        // The count the panel would have shown, absent rather than invented.
        expect(wrapper.text()).not.toContain('312 of');
    });

    it('says why a job failed instead of leaving the operator to guess', () => {
        const wrapper = mountShow(
            {},
            withTask({
                ...importing,
                status: 'failed',
                error: 'That file holds 20,000 codes and the limit is 10,000.',
                completed_at: '2026-09-15T10:00:30+00:00',
            }),
        );

        expect(wrapper.text()).toContain('Importing codes failed');
        expect(wrapper.text()).toContain('the limit is 10,000');
    });

    it('says nothing at all when nothing is running', () => {
        const wrapper = mountShow();

        expect(wrapper.text()).not.toContain('Importing codes');
    });

    // A QR export can be closed and left running, so the page has to be the way back to it.
    // Without that, closing the dialog is indistinguishable from losing the export.
    describe('QR exports', () => {
        const rendering = {
            id: 'task-9',
            type: 'qr-export',
            status: 'running',
            stage: null,
            progress_done: 400,
            progress_total: 1000,
            error: null,
            result: null,
            started_at: '2026-09-15T10:00:00+00:00',
            completed_at: null,
            reloads: ['qr_exports'],
        };

        it('offers a way back into a render that is still going', () => {
            const wrapper = mountShow({}, withTask(rendering));

            expect(wrapper.text()).toContain('Preparing QR export');
            expect(wrapper.find('[data-test="qr-export-reopen"]').exists()).toBe(true);
        });

        it('does not offer that on work of another kind', () => {
            const wrapper = mountShow({}, withTask(importing));

            expect(wrapper.find('[data-test="qr-export-reopen"]').exists()).toBe(false);
        });

        it('offers the archive once a render has finished', () => {
            // The prop a finished run names in its reloads, so this appears without the page
            // being reloaded and without anything polling for it.
            const wrapper = mountShow(
                {},
                {
                    qr_exports: [
                        {
                            id: 'exp-1',
                            bytes: 40960,
                            codes_total: 40,
                            settings: { format: 'pdf', size: 1 },
                            scope_label: 'Vendor A',
                            layout: 'partner',
                            expires_at: '2026-09-22T10:00:00+00:00',
                            created_at: '2026-09-15T10:00:00+00:00',
                            download_url: '/campaigns/c1/qr-exports/exp-1/download',
                        },
                    ],
                },
            );

            const download = wrapper.find('[data-test="qr-export-panel-download"]');

            expect(wrapper.find('[data-test="qr-export-ready-panel"]').exists()).toBe(true);
            expect(wrapper.text()).toContain('40 stickers');
            // Which stack it is, because a campaign can have several archives stored at once
            // and they are otherwise described identically.
            expect(wrapper.text()).toContain('Vendor A');
            expect(wrapper.text()).toContain('one folder per partner');
            expect(download.attributes('href')).toBe('/campaigns/c1/qr-exports/exp-1/download');
        });

        it('offers nothing where no archive is stored', () => {
            const wrapper = mountShow();

            expect(wrapper.find('[data-test="qr-export-ready-panel"]').exists()).toBe(false);
        });
    });
});

// The onboarding analysis reports itself through the same poller as everything else, but
// it has a panel of its own to report into. Letting it into the strip as well would put
// two progress bars on screen for one run, which reads as two runs.
describe('CampaignShow onboarding analysis', () => {
    const analyzing = {
        id: 'task-analysis',
        type: 'onboarding-analysis',
        status: 'running',
        stage: 'Reading wallet history',
        progress_done: 12,
        progress_total: 40,
        error: null,
        result: null,
        started_at: '2026-09-15T10:00:00+00:00',
        completed_at: null,
        reloads: ['onboarding'],
    };

    const importing = {
        id: 'task-import',
        type: 'codes-import',
        status: 'running',
        stage: 'Creating codes',
        progress_done: 312,
        progress_total: 1000,
        error: null,
        result: null,
        started_at: '2026-09-15T10:00:00+00:00',
        completed_at: null,
        reloads: ['campaign', 'stats'],
    };

    function withTasks(items) {
        return { tasks: { now: '2026-09-15T10:00:00+00:00', items } };
    }

    it('hands a running analysis to the onboarding panel rather than the strip', () => {
        const wrapper = mountShow({}, withTasks([analyzing]));

        expect(wrapper.vm.onboardingTask.id).toBe('task-analysis');
        expect(wrapper.vm.stripTasks).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Analyzing onboarding');
    });

    it('still reports the work that has nowhere else to appear', () => {
        const wrapper = mountShow({}, withTasks([analyzing, importing]));

        expect(wrapper.vm.stripTasks.map((task) => task.type)).toEqual([
            'codes-import',
        ]);
        expect(wrapper.text()).toContain('Importing codes');
    });

    // The panel says what went wrong next to the button that would try again. Repeating it
    // at the top of the page would separate the reason from the remedy.
    it('leaves a failed analysis to the panel that can explain it', () => {
        const wrapper = mountShow(
            {},
            withTasks([
                {
                    ...analyzing,
                    status: 'failed',
                    stage: null,
                    error: 'Koios is unreachable.',
                    completed_at: '2026-09-15T10:04:00+00:00',
                },
            ]),
        );

        expect(wrapper.vm.stripFailures).toHaveLength(0);
        expect(wrapper.vm.onboardingTask.error).toBe('Koios is unreachable.');
        expect(wrapper.text()).not.toContain('Analyzing onboarding failed');
    });

    it('has no analysis to hand on when none is running', () => {
        const wrapper = mountShow({}, withTasks([importing]));

        expect(wrapper.vm.onboardingTask).toBeNull();
    });
});
