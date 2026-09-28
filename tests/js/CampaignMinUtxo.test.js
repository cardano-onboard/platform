import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import CampaignShow from '../../resources/js/Pages/Campaign/Show.vue';

// Posts to the minimum UTxO endpoint only. Adding a token also looks its metadata up,
// which is a post too, and is not what these tests count.
function quoteCalls() {
    return window.axios.post.mock.calls.filter(([url]) => url !== '/known-assets/lookup-many');
}
import WalletTokenList from '../../resources/js/Components/WalletTokenList.vue';

/**
 * What the campaign page says about the chain's minimum for an output.
 *
 * The figure itself is the server's, computed by App\Support\MinUtxo and covered by
 * tests/Unit/Support/MinUtxoTest.php. Nothing here recomputes it. What is checked here is
 * that the page asks for it, renders what came back, and tells the two failure states
 * apart: one that cannot be paid at all and one that can be paid and not spent.
 */

const POLICY = 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235';

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
        wallet: { address: 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer' },
        codes: [],
        claims: [],
        rewards: {},
        ...overrides,
    };
}

function mountShow(campaignOverrides = {}, propOverrides = {}) {
    return mount(CampaignShow, {
        attachTo: document.body,
        props: {
            flash: {},
            campaign: makeCampaign(campaignOverrides),
            claim_url: 'https://beta.onbd.io/api/claim/v1/01HQ1234567890ABCDEFGHIJ',
            encoded_claim_url: 'https%3A%2F%2Fbeta.onbd.io',
            balance: [],
            wallet_pending: false,
            backend_mismatch: false,
            wallet_backend: 'null',
            max_file_size: 10485760,
            ...propOverrides,
        },
        global: {
            stubs: {
                AuthenticatedLayout: { template: '<div class="layout"><slot /></div>' },
                QrcodeVue: { template: '<div class="qr-stub" />' },
            },
        },
    });
}

function quoteResponse(overrides = {}) {
    return {
        data: {
            min_lovelace: 1159390,
            headroom_lovelace: 1000000,
            recommended_lovelace: 2159390,
            coins_per_utxo_byte: 4310,
            source: 'koios',
            asset_count: 1,
            policy_count: 1,
            output_bytes: 109,
            state: null,
            warning: null,
            ...overrides,
        },
    };
}

// One macrotask turn is not always enough to carry a mocked request through its promise
// chain and back into the component, and the campaign page now mounts a task poller as
// well, so on a loaded machine a single turn left the assertion reading the frame before
// the answer arrived. Three turns costs nothing and does not depend on how busy the box is.
async function settle(wrapper) {
    for (let turn = 0; turn < 3; turn += 1) {
        await new Promise((resolve) => setTimeout(resolve, 0));
        await wrapper.vm.$nextTick();
    }
}

function fieldsLabelled(wrapper, label) {
    return wrapper
        .findAllComponents({ name: 'VTextField' })
        .filter((candidate) => candidate.props('label') === label);
}

function fieldLabelled(wrapper, label, which = 0) {
    const fields = fieldsLabelled(wrapper, label);

    if (!fields[which]) {
        throw new Error(`No text field labelled "${label}" at position ${which}`);
    }

    return fields[which];
}

/** Drive the real add-token dialog rather than reaching into component state. */
async function addToken(wrapper, policy, asset, quantity) {
    const openers = wrapper
        .findAllComponents({ name: 'VBtn' })
        .filter((button) => button.text().trim() === 'Add Token');

    await openers[0].trigger('click');
    await settle(wrapper);

    await fieldLabelled(wrapper, 'Policy ID').setValue(policy);
    await fieldLabelled(wrapper, 'Token ID').setValue(asset);
    const quantities = fieldsLabelled(wrapper, 'Quantity');
    await quantities[quantities.length - 1].setValue(String(quantity));

    const submit = wrapper
        .findAllComponents({ name: 'VBtn' })
        .filter((button) => button.text().trim() === 'Add Token')
        .pop();

    await submit.trigger('click');
    await settle(wrapper);
}

describe('CampaignShow minimum UTxO', () => {
    let wrapper;

    beforeEach(() => {
        window.axios = {
            get: vi.fn().mockResolvedValue({ data: [] }),
            post: vi.fn().mockResolvedValue(quoteResponse()),
        };
    });

    afterEach(() => {
        if (wrapper) {
            wrapper.unmount();
            wrapper = null;
        }
        document.body.innerHTML = '';
    });

    it('says nothing about minimums when every code clears them', () => {
        wrapper = mountShow({}, {
            min_utxo: {
                coins_per_utxo_byte: 4310,
                headroom_lovelace: 1000000,
                below_minimum_codes: 0,
                tight_codes: 0,
            },
        });

        expect(wrapper.find('[data-test="codes-below-minimum"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="codes-tight"]').exists()).toBe(false);
    });

    it('names the codes the chain will refuse', () => {
        wrapper = mountShow({}, {
            min_utxo: {
                coins_per_utxo_byte: 4310,
                headroom_lovelace: 1000000,
                below_minimum_codes: 3,
                tight_codes: 0,
            },
        });

        const alert = wrapper.find('[data-test="codes-below-minimum"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('3 codes cannot be paid');
        // Not a funding shortfall: topping the bucket up would not change the outcome.
        expect(alert.text()).toContain('however much is in the bucket');
    });

    it('uses the singular for a single unpayable code', () => {
        wrapper = mountShow({}, {
            min_utxo: {
                coins_per_utxo_byte: 4310,
                headroom_lovelace: 1000000,
                below_minimum_codes: 1,
                tight_codes: 0,
            },
        });

        expect(wrapper.find('[data-test="codes-below-minimum"]').text()).toContain('1 code cannot be paid');
    });

    it('reports codes that leave a claimant nothing to spend, separately', () => {
        wrapper = mountShow({}, {
            min_utxo: {
                coins_per_utxo_byte: 4310,
                headroom_lovelace: 1000000,
                below_minimum_codes: 0,
                tight_codes: 2,
            },
        });

        const alert = wrapper.find('[data-test="codes-tight"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('2 codes');
        expect(alert.text()).toContain('nothing to spend');
    });

    it('shows the unpayable warning rather than the tight one when both apply', () => {
        wrapper = mountShow({}, {
            min_utxo: {
                coins_per_utxo_byte: 4310,
                headroom_lovelace: 1000000,
                below_minimum_codes: 1,
                tight_codes: 4,
            },
        });

        expect(wrapper.find('[data-test="codes-below-minimum"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="codes-tight"]').exists()).toBe(false);
    });

    it('asks the server for a minimum when the create dialog opens', async () => {
        wrapper = mountShow();

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);

        expect(quoteCalls()).toHaveLength(1);
        const [url, payload] = quoteCalls()[0];
        expect(url).toContain('campaigns/min-utxo');
        expect(payload).toEqual({ tokens: [] });
    });

    it('states the minimum and a suggestion in the create dialog', async () => {
        window.axios.post.mockResolvedValue(quoteResponse({ asset_count: 2, policy_count: 2 }));
        wrapper = mountShow();

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);

        const hint = document.querySelector('[data-test="min-utxo-hint"]');
        expect(hint).not.toBeNull();
        expect(hint.textContent).toContain('2 assets');
        expect(hint.textContent).toContain('2 policies');
        expect(hint.textContent).toContain('1.15939 ADA');
        // Rounded up to a whole ADA, which is the number an operator will actually type.
        expect(hint.textContent).toContain('3 ADA');
    });

    it('calls a default 1 ADA reward unpayable once a token is in the bundle', async () => {
        wrapper = mountShow();

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);

        expect(document.querySelector('[data-test="min-utxo-below"]')).not.toBeNull();
        expect(document.querySelector('[data-test="min-utxo-tight"]')).toBeNull();
    });

    it('warns without blocking when the amount clears the minimum but not the headroom', async () => {
        window.axios.post.mockResolvedValue(quoteResponse({
            min_lovelace: 1159390,
            recommended_lovelace: 2159390,
        }));
        wrapper = mountShow();

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);

        await fieldLabelled(wrapper, 'Lovelace').setValue('1200000');
        await settle(wrapper);

        expect(document.querySelector('[data-test="min-utxo-below"]')).toBeNull();
        const tight = document.querySelector('[data-test="min-utxo-tight"]');
        expect(tight).not.toBeNull();
        expect(tight.textContent).toContain('You can create it anyway');
    });

    it('says nothing once the amount clears the headroom', async () => {
        wrapper = mountShow();

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);

        await fieldLabelled(wrapper, 'Lovelace').setValue('3000000');
        await settle(wrapper);

        expect(document.querySelector('[data-test="min-utxo-below"]')).toBeNull();
        expect(document.querySelector('[data-test="min-utxo-tight"]')).toBeNull();
    });

    it('leaves the last known figure alone when the lookup fails', async () => {
        wrapper = mountShow();

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);

        window.axios.post.mockRejectedValue(new Error('network down'));
        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);

        // A failed lookup must never read as a bundle that costs nothing.
        const hint = document.querySelector('[data-test="min-utxo-hint"]');
        expect(hint).not.toBeNull();
        expect(hint.textContent).toContain('1.15939 ADA');
    });

    it('does not reach for a server that is not there', async () => {
        delete window.axios;
        wrapper = mountShow();

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);

        expect(document.querySelector('[data-test="min-utxo-hint"]')).toBeNull();
        expect(document.querySelector('[data-test="min-utxo-below"]')).toBeNull();
    });

    it('asks again when a token joins the bundle', async () => {
        wrapper = mountShow();

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);
        expect(quoteCalls()).toHaveLength(1);

        await addToken(wrapper, POLICY, '484f534b59', 5);

        expect(quoteCalls()).toHaveLength(2);
        const [, payload] = quoteCalls()[1];
        expect(payload).toEqual({
            tokens: [{ policy_id: POLICY, token_id: '484f534b59', quantity: 5 }],
        });
    });

    it('asks again when a token leaves the bundle', async () => {
        wrapper = mountShow();

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);
        await addToken(wrapper, POLICY, '484f534b59', 5);
        expect(quoteCalls()).toHaveLength(2);

        const remove = wrapper.findComponent(WalletTokenList);
        expect(remove.exists()).toBe(true);
        remove.vm.$emit('remove', 0);
        await settle(wrapper);

        expect(quoteCalls()).toHaveLength(3);
        const [, payload] = quoteCalls()[2];
        expect(payload).toEqual({ tokens: [] });
    });
});
