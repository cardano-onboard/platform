import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Now from '../../resources/js/Pages/Ada/Now.vue';

const IPHONE_UA =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const VESPR_UA = `${IPHONE_UA} VESPR`;
const DESKTOP_UA =
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

const PAGE_URL = 'https://app.onbd.io/ada/now';

/**
 * Point navigator.userAgent and window.location at a scenario, then mount.
 * Awaits a tick because isMobile / inWalletBrowser are only resolved in
 * onMounted — the hint bar is absent from the DOM until after that flush.
 */
async function mountNow({ ua = DESKTOP_UA, href = PAGE_URL, props = {} } = {}) {
    Object.defineProperty(window.navigator, 'userAgent', { value: ua, configurable: true });
    // location.href is both read (to build the deep link) and written (to fire it),
    // so it has to be a plain writable property rather than jsdom's real Location.
    delete window.location;
    window.location = { href };
    const wrapper = mount(Now, {
        props,
        global: { stubs: { LogoSvg: { template: '<span class="logo-stub" />' } } },
    });
    await wrapper.vm.$nextTick();
    return wrapper;
}

/** The delegate button only exists when a pool is configured. */
const delegateButton = (wrapper) =>
    wrapper.findAll('button').find((b) => b.text().includes('Delegate to'));

describe('Ada/Now — CIP-158 open-in-wallet', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('offers the wallet hint on a phone that is not already in a wallet browser', async () => {
        expect((await mountNow({ ua: IPHONE_UA })).text()).toContain('Open in wallet');
    });

    it('hides the hint inside a wallet browser — the page is already where it needs to be', async () => {
        expect((await mountNow({ ua: VESPR_UA })).text()).not.toContain('Open in wallet');
    });

    it('hides the hint on desktop, where no wallet has an embedded browser', async () => {
        expect((await mountNow({ ua: DESKTOP_UA })).text()).not.toContain('Open in wallet');
    });

    it('fires a CIP-158 browse URI carrying the percent-encoded page URL', async () => {
        const wrapper = await mountNow({ ua: IPHONE_UA });
        await wrapper.find('.hint__go').trigger('click');

        // web+cardano://browse/v1?uri=<percent-encoded https URL>
        expect(window.location.href).toBe(
            `web+cardano://browse/v1?uri=${encodeURIComponent(PAGE_URL)}`
        );
        // The encoded target must round-trip back to the page, unescaped.
        const uri = new URL(window.location.href.replace('web+cardano:', 'https:')).searchParams.get('uri');
        expect(uri).toBe(PAGE_URL);
    });

    it('falls back to copy/paste when nothing handles the scheme', async () => {
        const wrapper = await mountNow({ ua: IPHONE_UA });
        await wrapper.find('.hint__go').trigger('click');
        expect(wrapper.text()).not.toContain('Copy link');

        // Focus never left, so after the probe window the fallback takes over.
        vi.advanceTimersByTime(1500);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('No wallet caught that');
        expect(wrapper.find('.hint__go').text()).toContain('Copy link');
    });

    it('keeps the one-tap path when a wallet does handle the scheme', async () => {
        const wrapper = await mountNow({ ua: IPHONE_UA });
        await wrapper.find('.hint__go').trigger('click');

        // A handler backgrounds the page; that is the only signal available.
        window.dispatchEvent(new Event('blur'));
        vi.advanceTimersByTime(1500);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('No wallet caught that');
    });
});

describe('Ada/Now — CIP-13 stake delegation', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('shows no delegate button when no pool is configured, rather than a dead link', async () => {
        const wrapper = await mountNow({ ua: IPHONE_UA });
        expect(wrapper.text()).toContain('How to stake');
        expect(delegateButton(wrapper)).toBeUndefined();
    });

    it('fires a CIP-13 stake URI for the configured pool', async () => {
        const wrapper = await mountNow({ ua: IPHONE_UA, props: { stakePool: 'POOO' } });
        const btn = delegateButton(wrapper);
        expect(btn).toBeTruthy();
        expect(btn.text()).toContain('POOO');

        await btn.trigger('click');
        expect(window.location.href).toBe('web+cardano://stake?POOO');
    });

    it('reveals in-wallet steps when no wallet handles the stake URI', async () => {
        const wrapper = await mountNow({ ua: IPHONE_UA, props: { stakePool: 'POOO' } });
        await delegateButton(wrapper).trigger('click');

        vi.advanceTimersByTime(1500);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('No wallet caught that link');
    });
});

describe('Ada/Now — app directory', () => {
    it('links every app over https and renders a mark or a monogram for each', async () => {
        const wrapper = await mountNow();
        const links = wrapper.findAll('.app');
        expect(links.length).toBeGreaterThanOrEqual(24);

        for (const link of links) {
            expect(link.attributes('href')).toMatch(/^https:\/\//);
            // Untrusted outbound links must not leak window.opener.
            expect(link.attributes('rel')).toContain('noopener');
            expect(link.attributes('target')).toBe('_blank');
            const hasMark = link.find('img.app__mark').exists();
            const hasMono = link.find('.app__mono').exists();
            expect(hasMark || hasMono).toBe(true);
        }
    });

    it('states an app count that matches the rendered links', async () => {
        const wrapper = await mountNow();
        const count = wrapper.findAll('.app').length;
        expect(wrapper.find('.tally').text()).toContain(`${count}+`);
    });

    it('never advertises the bare onbd.io host for /ada/now, which 404s', async () => {
        const html = (await mountNow()).html();
        expect(html).not.toContain('onbd.io/ada/now');
    });
});
