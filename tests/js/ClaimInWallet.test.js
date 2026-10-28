import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ClaimInWallet from '../../resources/js/Pages/ClaimInWallet.vue';

describe('ClaimInWallet page', () => {
    it('says the link opens in a wallet app and offers no form', () => {
        const wrapper = mount(ClaimInWallet);

        expect(wrapper.text()).toContain('This link opens in a Cardano wallet app.');
        expect(wrapper.find('h1').text()).toBe('Open this link in a Cardano wallet');
        expect(wrapper.text()).toContain('supports claim links');
        expect(wrapper.findAll('input')).toHaveLength(0);
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});
