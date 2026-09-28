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
 * Changing what an existing code pays, from the campaign page.
 *
 * What an edit does to the database and to earlier claims is the server's business and is
 * covered by tests/Feature/CodeRewardEditTest.php. What is checked here is the dialog: that
 * it opens filled with what the code currently pays, that it says who the change reaches
 * before it is made, that it asks for its own minimum, and that it sends the reward fields
 * and nothing else.
 */

const POLICY = 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235';
const ASSET = '484f534b59';

function makeCode(overrides = {}) {
    return {
        id: '01CODE001',
        code: 'TESTCODE1',
        uses: 10,
        perWallet: 1,
        lovelace: 3000000,
        rewards_count: 1,
        claims_count: 0,
        claims: [],
        nmkr_project_uid: null,
        nmkr_count_nft: 0,
        rewards: [{ policy_hex: POLICY, asset_hex: ASSET, quantity: 5 }],
        min_utxo: {
            min_lovelace: 1159390,
            recommended_lovelace: 2159390,
            state: 'ok',
            warning: null,
        },
        ...overrides,
    };
}

function mountShow(campaignOverrides = {}) {
    return mount(CampaignShow, {
        attachTo: document.body,
        props: {
            flash: {},
            campaign: {
                id: '01HQ1234567890ABCDEFGHIJ',
                name: 'Test Campaign',
                description: 'A test description',
                start_date: '2026-04-01',
                end_date: '2099-04-30',
                network: 'preprod',
                one_per_wallet: 0,
                txn_msg: null,
                nmkr_api_key: null,
                status: 'running',
                wallet: { address: 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer' },
                codes: [makeCode()],
                claims: [],
                rewards: {},
                ...campaignOverrides,
            },
            claim_url: 'https://beta.onbd.io/api/claim/v1/01HQ1234567890ABCDEFGHIJ',
            encoded_claim_url: 'https%3A%2F%2Fbeta.onbd.io',
            balance: [],
            wallet_pending: false,
            backend_mismatch: false,
            wallet_backend: 'null',
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

function quote(overrides = {}) {
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

/**
 * Turns until a condition holds, rather than a fixed number of them.
 *
 * Three turns is still a guess about how busy the machine is, and under the whole suite it is
 * sometimes wrong: two dialogs that mount and unmount around each other can be read mid-change,
 * where one of them has gone and the other has not arrived. Waiting for the state the assertion
 * is about removes the guess. It gives up after enough turns that a real failure still reports
 * itself through the assertion rather than through a timeout.
 */
async function settleUntil(wrapper, ready, turns = 50) {
    for (let turn = 0; turn < turns; turn += 1) {
        if (ready()) {
            return;
        }

        await new Promise((resolve) => setTimeout(resolve, 0));
        await wrapper.vm.$nextTick();
    }
}

function fieldsLabelled(wrapper, label) {
    return wrapper
        .findAllComponents({ name: 'VTextField' })
        .filter((candidate) => candidate.props('label') === label);
}

async function openEdit(wrapper) {
    const button = wrapper.find('[data-test="edit-code"]');
    expect(button.exists()).toBe(true);
    await button.trigger('click');
    await settle(wrapper);
}

/** The edit dialog's own Lovelace field, which renders after the create dialog's. */
function editLovelace(wrapper) {
    const fields = fieldsLabelled(wrapper, 'Lovelace');
    return fields[fields.length - 1];
}

describe('CampaignShow reward editing', () => {
    let wrapper;

    beforeEach(() => {
        window.axios = {
            get: vi.fn().mockResolvedValue({ data: [] }),
            post: vi.fn().mockResolvedValue(quote()),
        };
    });

    afterEach(() => {
        if (wrapper) {
            wrapper.unmount();
            wrapper = null;
        }
        document.body.innerHTML = '';
    });

    it('offers an edit control on each code', () => {
        wrapper = mountShow();

        expect(wrapper.find('[data-test="edit-code"]').exists()).toBe(true);
    });

    /**
     * Hidden rather than disabled. Nothing can be claimed after the window closes, so there
     * is no forward for an edit to apply to, and a disabled button at the end of a row has
     * nowhere to explain itself.
     */
    it('hides the edit control once the campaign has ended', () => {
        wrapper = mountShow({ status: 'ended' });

        expect(wrapper.find('[data-test="edit-code"]').exists()).toBe(false);
    });

    it('opens filled with what the code currently pays', async () => {
        wrapper = mountShow();
        await openEdit(wrapper);

        expect(document.body.textContent).toContain('Change Reward');
        expect(document.body.textContent).toContain('TESTCODE1');
        expect(editLovelace(wrapper).props('modelValue')).toBe(3000000);

        const list = wrapper.findComponent(WalletTokenList);
        expect(list.exists()).toBe(true);
        expect(list.props('tokens')).toHaveLength(1);
    });

    it('asks the server what this code bundle has to be worth', async () => {
        wrapper = mountShow();
        await openEdit(wrapper);

        expect(quoteCalls()).toHaveLength(1);
        const [url, payload] = quoteCalls()[0];
        expect(url).toContain('campaigns/min-utxo');
        expect(payload).toEqual({
            tokens: [{ policy_id: POLICY, token_id: ASSET, quantity: 5 }],
        });
    });

    it('states the minimum for the bundle being edited', async () => {
        wrapper = mountShow();
        await openEdit(wrapper);

        const hint = document.querySelector('[data-test="edit-min-utxo-hint"]');
        expect(hint).not.toBeNull();
        expect(hint.textContent).toContain('1.15939 ADA');
    });

    it('refuses an amount under the chain minimum and warns about one with no room', async () => {
        wrapper = mountShow();
        await openEdit(wrapper);

        await editLovelace(wrapper).setValue(1000000);
        await settle(wrapper);
        expect(document.querySelector('[data-test="edit-min-utxo-below"]')).not.toBeNull();

        await editLovelace(wrapper).setValue(1200000);
        await settle(wrapper);
        expect(document.querySelector('[data-test="edit-min-utxo-below"]')).toBeNull();
        expect(document.querySelector('[data-test="edit-min-utxo-tight"]')).not.toBeNull();

        await editLovelace(wrapper).setValue(5000000);
        await settle(wrapper);
        expect(document.querySelector('[data-test="edit-min-utxo-tight"]')).toBeNull();
    });

    it('says how many claims keep what they were already sent', async () => {
        wrapper = mountShow({
            claims: [
                { id: 1, code_id: '01CODE001', transaction_id: 'sent-1' },
                { id: 2, code_id: '01CODE001', transaction_id: 'sent-2' },
                { id: 3, code_id: 'ANOTHERCODE', transaction_id: 'sent-3' },
            ],
        });
        await openEdit(wrapper);

        const settled = document.querySelector('[data-test="edit-settled"]');
        expect(settled).not.toBeNull();
        // Only this code's claims, and only the ones that actually went out.
        expect(settled.textContent).toContain('2 claims have');
        expect(settled.textContent).toContain('keep what they were sent');
    });

    /**
     * The one case where forward-only is a surprise. A claim that has been accepted has
     * already been shown what it was promised, and it has not been sent yet, so it will be
     * paid whatever is set here instead.
     */
    it('warns that an accepted claim waiting to be sent will get the new reward', async () => {
        wrapper = mountShow({
            claims: [{ id: 1, code_id: '01CODE001', transaction_id: null }],
        });
        await openEdit(wrapper);

        const pending = document.querySelector('[data-test="edit-pending"]');
        expect(pending).not.toBeNull();
        expect(pending.textContent).toContain('1 claim has');
        expect(pending.textContent).toContain('not sent yet');
    });

    it('says nothing about claims on a code nobody has claimed', async () => {
        wrapper = mountShow();
        await openEdit(wrapper);

        expect(document.querySelector('[data-test="edit-settled"]')).toBeNull();
        expect(document.querySelector('[data-test="edit-pending"]')).toBeNull();
    });

    it('sends the reward fields to the code it opened on', async () => {
        wrapper = mountShow();
        await openEdit(wrapper);

        await editLovelace(wrapper).setValue(6000000);
        await settle(wrapper);

        const save = wrapper
            .findAllComponents({ name: 'VBtn' })
            .find((button) => button.text().trim() === 'Save Rewards');
        expect(save).toBeTruthy();
        await save.trigger('click');
        await settle(wrapper);

        // useForm's put is the mocked one from the test setup.
        const { useForm } = await import('@inertiajs/vue3');
        const editForm = useForm.mock.results
            .map((result) => result.value)
            .find((candidate) => candidate.put.mock.calls.length > 0);

        expect(editForm).toBeTruthy();
        expect(editForm.put.mock.calls[0][0]).toContain('01CODE001');
        expect(editForm.lovelace).toBe(6000000);
        expect(editForm.tokens).toEqual([
            { policy_id: POLICY, token_id: ASSET, quantity: 5 },
        ]);
        // Usage limits are not the dialog's business; a printed code keeps them.
        expect(editForm.uses).toBeUndefined();
        expect(editForm.perWallet).toBeUndefined();
    });

    it('removing a token re-asks for the minimum', async () => {
        wrapper = mountShow();
        await openEdit(wrapper);
        expect(quoteCalls()).toHaveLength(1);

        wrapper.findComponent(WalletTokenList).vm.$emit('remove', 0);
        await settle(wrapper);

        expect(quoteCalls()).toHaveLength(2);
        expect(quoteCalls()[1][1]).toEqual({ tokens: [] });
    });

    /**
     * The two dialogs are reachable from the same page and hold different work. Typing an
     * amount into one must not arrive in the other, which a single shared form would do.
     */
    it('the edit dialog and the create dialog hold separate work', async () => {
        wrapper = mountShow();
        await openEdit(wrapper);

        await editLovelace(wrapper).setValue(9000000);
        await settle(wrapper);

        const cancel = wrapper
            .findAllComponents({ name: 'VBtn' })
            .filter((button) => button.text().trim() === 'Cancel')
            .pop();
        await cancel.trigger('click');
        await settle(wrapper);

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);

        // The create dialog's field comes first in the template; the edit dialog's stays
        // mounted behind it once it has been opened. Both have to be present before the
        // values mean anything, and which turn that happens on depends on the machine.
        await settleUntil(wrapper, () => fieldsLabelled(wrapper, 'Lovelace').length === 2);

        const fields = fieldsLabelled(wrapper, 'Lovelace');
        expect(fields).toHaveLength(2);
        expect(fields[0].props('modelValue')).toBe(1000000);
        expect(fields[1].props('modelValue')).toBe(9000000);
    });

    /**
     * And the other direction: a token added while the create dialog is open belongs to the
     * create form, not to whichever code was edited last.
     */
    it('a token added from the create dialog does not land on the edited code', async () => {
        wrapper = mountShow();
        await openEdit(wrapper);

        const cancel = wrapper
            .findAllComponents({ name: 'VBtn' })
            .filter((button) => button.text().trim() === 'Cancel')
            .pop();
        await cancel.trigger('click');
        await settle(wrapper);

        await wrapper.find('[data-test="add-code"]').trigger('click');
        await settle(wrapper);
        window.axios.post.mockClear();

        const addToken = wrapper
            .findAllComponents({ name: 'VBtn' })
            .filter((button) => button.text().trim() === 'Add Token');
        await addToken[0].trigger('click');
        await settle(wrapper);

        await fieldsLabelled(wrapper, 'Policy ID')[0].setValue(POLICY);
        await fieldsLabelled(wrapper, 'Token ID')[0].setValue(ASSET);
        const quantities = fieldsLabelled(wrapper, 'Quantity');
        await quantities[quantities.length - 1].setValue('3');

        const submit = wrapper
            .findAllComponents({ name: 'VBtn' })
            .filter((button) => button.text().trim() === 'Add Token')
            .pop();
        await submit.trigger('click');
        await settle(wrapper);

        // The bundle quoted is the create form's one token, not the edited code's.
        expect(quoteCalls()[0][1]).toEqual({
            tokens: [{ policy_id: POLICY, token_id: ASSET, quantity: 3 }],
        });
    });
});
