import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import CampaignCreate from '../../resources/js/Pages/Campaign/Create.vue';

function mountCreate(props = {}) {
    return mount(CampaignCreate, {
        props: {
            errors: {},
            ...props,
        },
        global: {
            stubs: {
                AuthenticatedLayout: {
                    template: '<div class="layout"><slot /></div>',
                },
            },
        },
    });
}

describe('CampaignCreate', () => {
    it('renders the create campaign form', () => {
        const wrapper = mountCreate();
        expect(wrapper.text()).toContain('Create New Campaign');
    });

    it('renders all required form fields', () => {
        const wrapper = mountCreate();
        expect(wrapper.find('#campaign_name').exists()).toBe(true);
        expect(wrapper.find('#description').exists()).toBe(true);
        expect(wrapper.find('#start_date').exists()).toBe(true);
        expect(wrapper.find('#end_date').exists()).toBe(true);
        expect(wrapper.find('#network').exists()).toBe(true);
        expect(wrapper.find('#one_per_wallet').exists()).toBe(true);
    });

    it('has correct network options', () => {
        const wrapper = mountCreate();
        const options = wrapper.findAll('#network option');
        const values = options.map(o => o.element.value);
        expect(values).toContain('preprod');
        expect(values).toContain('preview');
        expect(values).toContain('mainnet');
    });

    it('defaults network to preprod', () => {
        const wrapper = mountCreate();
        const select = wrapper.find('#network');
        expect(select.element.value).toBe('preprod');
    });

    it('renders submit button', () => {
        const wrapper = mountCreate();
        const submitBtn = wrapper.find('button[type="submit"]');
        expect(submitBtn.exists()).toBe(true);
        expect(submitBtn.text()).toContain('Submit');
    });

    it('displays validation errors when present', () => {
        const wrapper = mountCreate({
            errors: {
                name: 'The name field is required.',
                network: 'The network field is required.',
            },
        });
        expect(wrapper.text()).toContain('The name field is required.');
        expect(wrapper.text()).toContain('The network field is required.');
    });

    it('binds form fields to reactive data', async () => {
        const wrapper = mountCreate();
        const nameInput = wrapper.find('#campaign_name');
        await nameInput.setValue('My Test Campaign');
        expect(nameInput.element.value).toBe('My Test Campaign');
    });

    it('has date inputs for start and end dates', () => {
        const wrapper = mountCreate();
        expect(wrapper.find('#start_date').element.type).toBe('date');
        expect(wrapper.find('#end_date').element.type).toBe('date');
    });
});
