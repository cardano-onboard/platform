import { describe, it, expect, vi, afterEach, beforeAll, afterAll } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Claim from '../../resources/js/Pages/Claim.vue';

// The address conversion is Mesh's own, unmocked, so the vectors below prove the page against it.

// The payment-address test vectors of CIP-19 ("Test Vectors"), mainnet then testnet. The
// hex is what a CIP-30 wallet returns for each (CIP-30 "Address").
const CIP19 = [
    ['type-00', 'addr1qx2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3n0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgse35a3x',
        '019493315cd92eb5d8c4304e67b7e16ae36d61d34502694657811a2c8e337b62cfff6403a06a3acbc34f8c46003c69fe79a3628cefa9c47251'],
    ['type-01', 'addr1z8phkx6acpnf78fuvxn0mkew3l0fd058hzquvz7w36x4gten0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgs9yc0hh',
        '11c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f337b62cfff6403a06a3acbc34f8c46003c69fe79a3628cefa9c47251'],
    ['type-02', 'addr1yx2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzerkr0vd4msrxnuwnccdxlhdjar77j6lg0wypcc9uar5d2shs2z78ve',
        '219493315cd92eb5d8c4304e67b7e16ae36d61d34502694657811a2c8ec37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f'],
    ['type-03', 'addr1x8phkx6acpnf78fuvxn0mkew3l0fd058hzquvz7w36x4gt7r0vd4msrxnuwnccdxlhdjar77j6lg0wypcc9uar5d2shskhj42g',
        '31c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542fc37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f'],
    ['type-04', 'addr1gx2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer5pnz75xxcrzqf96k',
        '419493315cd92eb5d8c4304e67b7e16ae36d61d34502694657811a2c8e8198bd431b03'],
    ['type-05', 'addr128phkx6acpnf78fuvxn0mkew3l0fd058hzquvz7w36x4gtupnz75xxcrtw79hu',
        '51c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f8198bd431b03'],
    ['type-06', 'addr1vx2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzers66hrl8',
        '619493315cd92eb5d8c4304e67b7e16ae36d61d34502694657811a2c8e'],
    ['type-07', 'addr1w8phkx6acpnf78fuvxn0mkew3l0fd058hzquvz7w36x4gtcyjy7wx',
        '71c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f'],
    ['type-00', 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3n0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgs68faae',
        '009493315cd92eb5d8c4304e67b7e16ae36d61d34502694657811a2c8e337b62cfff6403a06a3acbc34f8c46003c69fe79a3628cefa9c47251'],
    ['type-01', 'addr_test1zrphkx6acpnf78fuvxn0mkew3l0fd058hzquvz7w36x4gten0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgsxj90mg',
        '10c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f337b62cfff6403a06a3acbc34f8c46003c69fe79a3628cefa9c47251'],
    ['type-02', 'addr_test1yz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzerkr0vd4msrxnuwnccdxlhdjar77j6lg0wypcc9uar5d2shsf5r8qx',
        '209493315cd92eb5d8c4304e67b7e16ae36d61d34502694657811a2c8ec37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f'],
    ['type-03', 'addr_test1xrphkx6acpnf78fuvxn0mkew3l0fd058hzquvz7w36x4gt7r0vd4msrxnuwnccdxlhdjar77j6lg0wypcc9uar5d2shs4p04xh',
        '30c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542fc37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f'],
    ['type-04', 'addr_test1gz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer5pnz75xxcrdw5vky',
        '409493315cd92eb5d8c4304e67b7e16ae36d61d34502694657811a2c8e8198bd431b03'],
    ['type-05', 'addr_test12rphkx6acpnf78fuvxn0mkew3l0fd058hzquvz7w36x4gtupnz75xxcryqrvmw',
        '50c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f8198bd431b03'],
    ['type-06', 'addr_test1vz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzerspjrlsz',
        '609493315cd92eb5d8c4304e67b7e16ae36d61d34502694657811a2c8e'],
    ['type-07', 'addr_test1wrphkx6acpnf78fuvxn0mkew3l0fd058hzquvz7w36x4gtcl6szpr',
        '70c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f'],
];
// jsdom swaps in its own Uint8Array, so Node's Buffer, which Mesh encodes through, fails an
// instanceof check in its bech32 library. Node's TextEncoder still returns Node's own
// Uint8Array. A browser has no such mismatch.
const jsdomUint8Array = globalThis.Uint8Array;
beforeAll(() => {
    globalThis.Uint8Array = new TextEncoder().encode('').constructor;
});
afterAll(() => {
    globalThis.Uint8Array = jsdomUint8Array;
});

const MAINNET = CIP19[0][1];
const MAINNET_HEX = CIP19[0][2];
const TESTNET = CIP19[8][1];
const TESTNET_HEX = CIP19[8][2];

const ENDPOINT = '/api/claim/v1/01TESTCAMPAIGN';

const mountClaim = () => mount(Claim);

function fakeWallet(name, returned) {
    return {
        name,
        icon: 'data:image/png;base64,AAAA',
        enable: vi.fn(async () => ({ getChangeAddress: async () => returned })),
    };
}

function replyWith(body, status = 200) {
    const fetchMock = vi.fn(async () => ({ status, json: async () => body }));
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

const inputs = (wrapper) => wrapper.findAll('input');
const button = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text);

async function connect(wrapper, hex = TESTNET_HEX, name = 'Lace') {
    window.cardano = { ...(window.cardano ?? {}), [name]: fakeWallet(name, hex) };
    await button(wrapper, 'Connect a wallet').trigger('click');
    await button(wrapper, name).trigger('click');
    await flushPromises();
}

async function submit(wrapper, { code = 'ABC123', address = TESTNET_HEX } = {}) {
    await inputs(wrapper)[0].setValue(code);
    await connect(wrapper, address);
    await wrapper.find('form').trigger('submit');
    await flushPromises();
}

afterEach(() => {
    delete window.cardano;
    window.history.replaceState(null, '', '/');
    vi.unstubAllGlobals();
});

describe('Claim page, claiming', () => {
    it('posts the connected wallet address and code to its own address and shows the success', async () => {
        window.history.replaceState(null, '', ENDPOINT);
        const fetchMock = replyWith({ code: 200, status: 'accepted', lovelaces: '2000000' });
        const wrapper = mountClaim();

        await submit(wrapper, { code: ' ABC123 ' });

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe(ENDPOINT);
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ address: TESTNET, code: 'ABC123' });
        expect(wrapper.text()).toContain('Your claim was accepted');
    });

    it('has no input for an address, only the code', () => {
        const wrapper = mountClaim();

        expect(inputs(wrapper)).toHaveLength(1);
        expect(wrapper.text()).not.toMatch(/paste/i);
        expect(wrapper.text()).not.toContain('Wallet address');
    });

    it('does not post until a wallet is connected', async () => {
        const fetchMock = replyWith({ code: 200, status: 'accepted' });
        const wrapper = mountClaim();
        await inputs(wrapper)[0].setValue('ABC123');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Connect a wallet to receive your reward.');
    });

    it('shows the transaction when the reward has already been sent', async () => {
        replyWith({ code: 202, status: 'claimed', tx_hash: 'TX1234' });
        const wrapper = mountClaim();
        await submit(wrapper);

        expect(wrapper.text()).toContain('Transaction: TX1234');
    });

    it.each([
        [{ code: 400, status: 'invalidaddress' }, 200, 'That is not a valid Cardano address.'],
        [{ code: 400, status: 'invalidaddress', message: 'Claims need a base address.' }, 200, 'Claims need a base address.'],
        [{ code: 400, status: 'missingcode' }, 200, 'Enter your claim code.'],
        [{ code: 400, status: 'invalidnetwork' }, 200, 'wrong network'],
        [{ code: 404, status: 'notfound' }, 200, 'That code was not found.'],
        [{ code: 409, status: 'alreadyclaimed' }, 200, 'This code or this wallet has already claimed.'],
        [{ code: 410, status: 'expired' }, 200, 'This claim has ended.'],
        [{ code: 425, status: 'tooearly' }, 200, 'This claim has not started yet.'],
        [{ message: 'Too Many Attempts.' }, 429, 'Too many attempts. Wait a minute and try again.'],
        [{ code: 429, status: 'ratelimited' }, 200, 'Too many attempts. Wait a minute and try again.'],
        [{ message: 'Server Error' }, 500, 'The claim could not be completed. Try again.'],
    ])('says in the page own words why a claim was refused: %j', async (body, httpStatus, words) => {
        replyWith(body, httpStatus);
        const wrapper = mountClaim();
        await submit(wrapper);

        expect(wrapper.text()).toContain(words);
        expect(wrapper.text()).not.toContain('Your claim was accepted');
        expect(wrapper.text()).not.toContain('Too Many Attempts.');
    });

    it('says so when the server cannot be reached', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('network'); }));
        const wrapper = mountClaim();
        await submit(wrapper);

        expect(wrapper.text()).toContain('Could not reach the server');
    });

    it('sends one request and disables the button while it is in flight', async () => {
        let finish;
        const fetchMock = vi.fn(() => new Promise((resolve) => { finish = resolve; }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountClaim();
        await inputs(wrapper)[0].setValue('ABC123');
        await connect(wrapper);

        await wrapper.find('form').trigger('submit');
        await wrapper.find('form').trigger('submit');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(button(wrapper, 'Claim').element.disabled).toBe(true);

        finish({ status: 200, json: async () => ({ status: 'accepted' }) });
        await flushPromises();
        expect(button(wrapper, 'Claim').element.disabled).toBe(false);
    });

    it('never takes the code from the address bar', () => {
        window.history.replaceState(null, '', ENDPOINT + '?code=SECRET-42#code=SECRET-42');

        expect(inputs(mountClaim())[0].element.value).toBe('');
    });

    it('shows no campaign data', () => {
        const text = mountClaim().text();

        expect(text).toContain('Claim your reward');
        expect(text).not.toMatch(/beta|Onboard\.Ninja/i);
    });
});

describe('Claim page, browser extension', () => {
    it('offers no wallet until asked, then reads the wallets installed at that moment', async () => {
        const wrapper = mountClaim();
        expect(wrapper.findAll('img')).toHaveLength(0);

        // Injected after the page mounted.
        window.cardano = { lace: fakeWallet('Lace', TESTNET_HEX) };
        await button(wrapper, 'Connect a wallet').trigger('click');

        expect(button(wrapper, 'Lace')).toBeTruthy();
    });

    it('says so when no wallet is installed', async () => {
        const wrapper = mountClaim();
        await button(wrapper, 'Connect a wallet').trigger('click');

        expect(wrapper.text()).toContain('No wallet extension found');
        expect(wrapper.text()).not.toMatch(/paste/i);
    });

    it('offers every installed wallet and takes the address from the one chosen', async () => {
        const eternl = fakeWallet('Eternl', MAINNET_HEX);
        const lace = fakeWallet('Lace', TESTNET_HEX);
        window.cardano = { eternl, lace, notAWallet: { name: 'x' } };
        const wrapper = mountClaim();
        await button(wrapper, 'Connect a wallet').trigger('click');

        await button(wrapper, 'Lace').trigger('click');
        await flushPromises();
        expect(lace.enable).toHaveBeenCalledOnce();
        expect(eternl.enable).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain(`Connected to Lace: ${TESTNET}`);

        await button(wrapper, 'Eternl').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain(`Connected to Eternl: ${MAINNET}`);
    });

    it.each(CIP19)('turns the CIP-30 hex of CIP-19 %s into its bech32 address', async (_type, bech32, hex) => {
        window.cardano = { w: fakeWallet('W', hex) };
        const wrapper = mountClaim();
        await button(wrapper, 'Connect a wallet').trigger('click');
        await button(wrapper, 'W').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain(`Connected to W: ${bech32}`);
    });

    it('accepts a wallet that already returns bech32', async () => {
        window.cardano = { w: fakeWallet('W', MAINNET) };
        const wrapper = mountClaim();
        await button(wrapper, 'Connect a wallet').trigger('click');
        await button(wrapper, 'W').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain(`Connected to W: ${MAINNET}`);
    });

    it('reports a wallet that refuses and stays unconnected', async () => {
        const wallet = fakeWallet('Eternl', MAINNET_HEX);
        wallet.enable = vi.fn(async () => { throw { code: -3, info: 'refused' }; });
        window.cardano = { eternl: wallet };
        const wrapper = mountClaim();
        await button(wrapper, 'Connect a wallet').trigger('click');
        await button(wrapper, 'Eternl').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Could not get an address from Eternl');
        expect(wrapper.text()).not.toContain('Connected to');
        expect(wrapper.text()).not.toMatch(/paste/i);
    });

    it('forgets the earlier address when a reconnect fails, so nothing is claimed to it', async () => {
        const bad = fakeWallet('Eternl', MAINNET_HEX);
        bad.enable = vi.fn(async () => { throw { code: -3 }; });
        window.cardano = { lace: fakeWallet('Lace', TESTNET_HEX), eternl: bad };
        const fetchMock = replyWith({ code: 200, status: 'accepted' });
        const wrapper = mountClaim();
        await button(wrapper, 'Connect a wallet').trigger('click');
        await button(wrapper, 'Lace').trigger('click');
        await flushPromises();
        await button(wrapper, 'Eternl').trigger('click');
        await flushPromises();
        await inputs(wrapper)[0].setValue('ABC123');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).not.toContain('Connected to');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('announces the connection and the missing extension as status lines, under a heading', async () => {
        const wrapper = mountClaim();
        expect(wrapper.find('h1').text()).toBe('Claim your reward');
        await button(wrapper, 'Connect a wallet').trigger('click');
        expect(wrapper.find('[role="status"]').text()).toContain('No wallet extension found');
    });

    it('reports a wallet that returns nonsense instead of connecting it', async () => {
        window.cardano = { w: fakeWallet('W', 'not an address') };
        const wrapper = mountClaim();
        await button(wrapper, 'Connect a wallet').trigger('click');
        await button(wrapper, 'W').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Could not get an address from W');
        expect(wrapper.text()).not.toContain('Connected to');
    });
});
