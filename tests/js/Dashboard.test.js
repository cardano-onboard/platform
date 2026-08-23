import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Dashboard from '../../resources/js/Pages/Dashboard.vue';

// Helper to mount Dashboard with campaigns prop
function mountDashboard(campaigns = []) {
    return mount(Dashboard, {
        props: { campaigns },
        global: {
            stubs: {
                AuthenticatedLayout: {
                    template: '<div class="layout"><slot /></div>',
                },
            },
        },
    });
}

function makeCampaign(overrides = {}) {
    return {
        id: '01HQ1234567890ABCDEFGHIJ',
        name: 'Test Campaign',
        description: 'A test campaign',
        start_date: '2026-04-01',
        end_date: '2026-04-30',
        network: 'preprod',
        status: 'active',
        codes_count: 10,
        claims_count: 3,
        ...overrides,
    };
}

describe('Dashboard', () => {
    it('renders the page title and toolbar', () => {
        const wrapper = mountDashboard();
        expect(wrapper.text()).toContain('Your Campaigns');
        expect(wrapper.text()).toContain('Create Campaign');
    });

    it('shows empty state when no campaigns exist', () => {
        const wrapper = mountDashboard([]);
        expect(wrapper.text()).toContain("You don't have any campaigns yet!");
    });

    it('renders campaign rows with correct data', () => {
        const campaigns = [
            makeCampaign({ name: 'Alpha Airdrop', codes_count: 50, claims_count: 12, network: 'mainnet' }),
            makeCampaign({ id: '01HQ9999999999ZYXWVUTSRQ', name: 'Beta Drop', codes_count: 5, claims_count: 0, network: 'preprod' }),
        ];
        const wrapper = mountDashboard(campaigns);
        expect(wrapper.text()).toContain('Alpha Airdrop');
        expect(wrapper.text()).toContain('Beta Drop');
        expect(wrapper.text()).toContain('mainnet');
        expect(wrapper.text()).toContain('preprod');
    });

    it('shows delete button only for campaigns with zero claims', () => {
        const campaigns = [
            makeCampaign({ name: 'Has Claims', claims_count: 5 }),
            makeCampaign({ id: '01HQ0000000000000000000A', name: 'No Claims', claims_count: 0 }),
        ];
        const wrapper = mountDashboard(campaigns);
        // Find all trash icon buttons
        const deleteButtons = wrapper.findAll('.mdi-trash-can').map(i => i.element.closest('button'));
        // Only one delete button should exist (for the campaign with 0 claims)
        expect(deleteButtons.length).toBe(1);
    });

    it('opens create campaign dialog when button is clicked', async () => {
        const wrapper = mountDashboard();

        // Click "Create Campaign" button
        const createBtn = wrapper.findAll('button').find(btn => btn.text().includes('Create Campaign'));
        await createBtn.trigger('click');
        await wrapper.vm.$nextTick();

        // Vuetify dialogs teleport to document.body
        const body = document.body.textContent;
        expect(body).toContain('Create New Campaign');
    });

    it('renders all form fields in the create dialog', async () => {
        const wrapper = mountDashboard();
        const createBtn = wrapper.findAll('button').find(btn => btn.text().includes('Create Campaign'));
        await createBtn.trigger('click');
        await wrapper.vm.$nextTick();

        const body = document.body.textContent;
        expect(body).toContain('Name');
        expect(body).toContain('Description');
        expect(body).toContain('Start');
        expect(body).toContain('End');
        expect(body).toContain('Network');
        expect(body).toContain('Transaction Message');
        expect(body).toContain('NMKR API Key');
    });

    it('shows remove confirmation dialog with campaign name', async () => {
        const campaigns = [makeCampaign({ name: 'Doomed Campaign', claims_count: 0 })];
        const wrapper = mountDashboard(campaigns);

        // Click delete button
        const deleteBtn = wrapper.findAll('button').find(btn => {
            const icon = btn.find('.mdi-trash-can');
            return icon.exists();
        });
        await deleteBtn.trigger('click');
        await wrapper.vm.$nextTick();

        // Vuetify dialogs teleport to document.body
        const body = document.body.textContent;
        expect(body).toContain('Doomed Campaign');
        expect(body).toContain('Are you sure you want to remove this campaign?');
    });

    it('shows codes and claims counts for each campaign', () => {
        const campaigns = [makeCampaign({ codes_count: 42, claims_count: 17 })];
        const wrapper = mountDashboard(campaigns);
        expect(wrapper.text()).toContain('42');
        expect(wrapper.text()).toContain('17');
    });

    it('displays status badges with correct text', () => {
        const campaigns = [
            makeCampaign({ name: 'Active One', status: 'active' }),
            makeCampaign({ id: '01HQ0000000000000000000B', name: 'Ended One', status: 'ended' }),
            makeCampaign({ id: '01HQ0000000000000000000C', name: 'Upcoming One', status: 'upcoming' }),
        ];
        const wrapper = mountDashboard(campaigns);
        expect(wrapper.text()).toContain('active');
        expect(wrapper.text()).toContain('ended');
        expect(wrapper.text()).toContain('upcoming');
    });

    it('renders column headers for the data table', () => {
        const wrapper = mountDashboard([makeCampaign()]);
        const text = wrapper.text();
        expect(text).toContain('Name');
        expect(text).toContain('Status');
        expect(text).toContain('Network');
        expect(text).toContain('Codes');
        expect(text).toContain('Claims');
    });
});

describe('Dashboard search', () => {
    // Six campaigns: the field only appears once the list is long enough to hunt through.
    function sixCampaigns() {
        return [
            makeCampaign({ id: 'c1', name: 'Rare Evo 2026', description: 'Las Vegas booth', network: 'mainnet' }),
            makeCampaign({ id: 'c2', name: 'Summit Giveaway', description: 'Open source summit', network: 'mainnet' }),
            makeCampaign({ id: 'c3', name: 'Watch Party', description: 'World cup', network: 'preprod', status: 'ended' }),
            makeCampaign({ id: 'c4', name: 'Meetup Drop', description: 'Local meetup' }),
            makeCampaign({ id: 'c5', name: 'Hackathon', description: 'Student event' }),
            makeCampaign({ id: 'c6', name: 'Conference Swag', description: 'Booth handout' }),
        ];
    }

    it('hides the search field until the list is long enough to need it', () => {
        const wrapper = mountDashboard([makeCampaign()]);
        expect(wrapper.text()).not.toContain('Search campaigns');
    });

    it('shows the search field once there are more than five campaigns', () => {
        const wrapper = mountDashboard(sixCampaigns());
        expect(wrapper.text()).toContain('Search campaigns');
    });

    it('matches on campaign name', async () => {
        const wrapper = mountDashboard(sixCampaigns());
        wrapper.vm.search = 'rare';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Rare Evo 2026');
        expect(wrapper.text()).not.toContain('Hackathon');
    });

    it('matches on description, so a campaign is findable by what it was for', async () => {
        const wrapper = mountDashboard(sixCampaigns());
        wrapper.vm.search = 'world cup';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Watch Party');
        expect(wrapper.text()).not.toContain('Rare Evo');
    });

    it('is case insensitive and ignores surrounding whitespace', async () => {
        const wrapper = mountDashboard(sixCampaigns());
        wrapper.vm.search = '  HACKATHON  ';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Hackathon');
        expect(wrapper.text()).not.toContain('Rare Evo');
    });

    it('tells the reader nothing matched rather than showing the empty-account message', async () => {
        const wrapper = mountDashboard(sixCampaigns());
        wrapper.vm.search = 'zzzznotacampaign';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('No campaigns match that search.');
        expect(wrapper.text()).not.toContain("You don't have any campaigns yet!");
    });

    it('restores the full list when the search is cleared', async () => {
        const wrapper = mountDashboard(sixCampaigns());
        wrapper.vm.search = 'rare';
        await wrapper.vm.$nextTick();
        wrapper.vm.search = '';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Hackathon');
        expect(wrapper.text()).toContain('Rare Evo 2026');
    });
});
