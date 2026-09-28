import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Dashboard from '../../resources/js/Pages/Dashboard.vue';

// Helper to mount Dashboard with campaigns prop
function mountDashboard(campaigns = [], props = {}) {
    return mount(Dashboard, {
        props: { campaigns, ...props },
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

// The list sits directly on the page, as the codes list on a campaign page does.
it('does not wrap the campaign list in a card', () => {
    const wrapper = mountDashboard([makeCampaign()]);

    expect(wrapper.find('.v-data-table').element.closest('.v-card')).toBeNull();
    expect(wrapper.find('.v-toolbar').element.closest('.v-card')).toBeNull();
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

    // The creation dialog on this page had a hardcoded network list that ignored the
    // deployment's allowlist, and it also omitted preview, so it disagreed with the
    // create page as well as with the server.
    describe('network selector', () => {
        function networkItems(wrapper) {
            return wrapper.vm.networks;
        }

        it('offers only the networks the deployment accepts', () => {
            const wrapper = mountDashboard([], { allowed_networks: ['preprod'] });

            expect(networkItems(wrapper)).toEqual(['preprod']);
        });

        it('keeps the create page ordering', () => {
            const wrapper = mountDashboard([], {
                allowed_networks: ['mainnet', 'preprod', 'preview'],
            });

            expect(networkItems(wrapper)).toEqual(['preprod', 'preview', 'mainnet']);
        });

        it('offers preview, which the hardcoded list left out', () => {
            const wrapper = mountDashboard([], {
                allowed_networks: ['preprod', 'preview', 'mainnet'],
            });

            expect(networkItems(wrapper)).toContain('preview');
        });
    });

    // A deployment restricted to preprod/preview still has to tell an operator where a
    // mainnet campaign actually gets created, or the field just goes quiet on them.
    describe('mainnet hint', () => {
        async function openDialog(wrapper) {
            const createBtn = wrapper.findAll('button').find(btn => btn.text().includes('Create Campaign'));
            await createBtn.trigger('click');
            await wrapper.vm.$nextTick();
            return document.body.textContent;
        }

        it('says nothing when mainnet_app_url is unset', async () => {
            const wrapper = mountDashboard([], { allowed_networks: ['preprod', 'preview'] });

            expect(await openDialog(wrapper)).not.toContain('Mainnet campaigns are created at');
        });

        it('says nothing when mainnet is already allowed, even if mainnet_app_url is set', async () => {
            const wrapper = mountDashboard([], {
                allowed_networks: ['preprod', 'preview', 'mainnet'],
                mainnet_app_url: 'https://example.com',
            });

            expect(await openDialog(wrapper)).not.toContain('Mainnet campaigns are created at');
        });

        it('shows the hint with the configured URL when mainnet is excluded and the URL is set', async () => {
            const wrapper = mountDashboard([], {
                allowed_networks: ['preprod', 'preview'],
                mainnet_app_url: 'https://example.com',
            });

            const body = await openDialog(wrapper);
            expect(body).toContain('Mainnet campaigns are created at https://example.com');
        });
    });
});

// Below the md breakpoint the table's own stacked layout gave every column a
// full-height labelled row, so one campaign filled most of a phone screen. Each
// campaign is one compact row there instead, and it still has to carry everything.
describe('Dashboard on a phone', () => {
    const desktopWidth = window.innerWidth;

    // Vuetify recomputes its breakpoints in a watcher, so the new width only
    // applies after a tick.
    async function setWidth(width) {
        window.innerWidth = width;
        window.dispatchEvent(new Event('resize'));
        await nextTick();
    }

    beforeEach(() => setWidth(360));
    afterEach(() => setWidth(desktopWidth));

    it('renders each campaign as one compact row', () => {
        const wrapper = mountDashboard([
            makeCampaign({ name: 'Alpha Airdrop' }),
            makeCampaign({ id: '01HQ9999999999ZYXWVUTSRQ', name: 'Beta Drop' }),
        ]);

        expect(wrapper.findAll('tr.campaign-card')).toHaveLength(2);
        // The stacked layout labelled every value with its column title per row.
        expect(wrapper.find('.v-data-table__td-title').exists()).toBe(false);
    });

    it('keeps every column of the campaign in the compact row', () => {
        const wrapper = mountDashboard([
            makeCampaign({
                name: 'Rare Evo 2026',
                description: 'Las Vegas booth',
                status: 'upcoming',
                network: 'mainnet',
                start_date: '2026-08-01',
                end_date: '2026-08-03',
                codes_count: 1250,
                claims_count: 1,
            }),
        ]);
        const card = wrapper.find('tr.campaign-card').text();

        expect(card).toContain('Rare Evo 2026');
        expect(card).toContain('Las Vegas booth');
        expect(card).toContain('upcoming');
        expect(card).toContain('mainnet');
        expect(card).toContain('2026-08-01 to 2026-08-03');
        expect(card).toContain('1250 codes');
        expect(card).toContain('1 claim');
        expect(card).not.toContain('1 claims');
    });

    it('keeps the view and edit links, and remove only while nothing is claimed', () => {
        const wrapper = mountDashboard([
            makeCampaign({ id: 'claimed', name: 'Has Claims', claims_count: 5 }),
            makeCampaign({ id: 'unclaimed', name: 'No Claims', claims_count: 0 }),
        ]);
        const [claimed, unclaimed] = wrapper.findAll('tr.campaign-card');

        for (const card of [claimed, unclaimed]) {
            expect(card.find('.mdi-magnify').exists()).toBe(true);
            expect(card.find('.mdi-pencil').exists()).toBe(true);
        }
        expect(claimed.find('.mdi-trash-can').exists()).toBe(false);
        expect(unclaimed.find('.mdi-trash-can').exists()).toBe(true);
    });

    it('opens the remove dialog from the compact row', async () => {
        const wrapper = mountDashboard([makeCampaign({ name: 'Doomed Campaign', claims_count: 0 })]);

        await wrapper.find('tr.campaign-card .mdi-trash-can').element.closest('button').click();
        await wrapper.vm.$nextTick();

        expect(document.body.textContent).toContain('You have chosen to remove your');
        expect(document.body.textContent).toContain('Doomed Campaign');
    });

    // The full button label left the title room for "Yo..." at 360px, and a spacer
    // beside the title took half of what was left.
    it('keeps the whole title and a 44px create button in the header', async () => {
        const wrapper = mountDashboard([makeCampaign()]);
        const toolbar = wrapper.find('.v-toolbar');
        const button = toolbar.find('button');

        expect(toolbar.find('.v-toolbar-title').text()).toBe('Your Campaigns');
        expect(toolbar.find('.v-spacer').exists()).toBe(false);
        expect(button.text()).toBe('Create');
        expect(button.attributes('aria-label')).toBe('Create Campaign');
        expect(button.attributes('style')).toContain('height: 44px');

        await button.trigger('click');
        await wrapper.vm.$nextTick();
        expect(document.body.textContent).toContain('Create New Campaign');
    });

    it('keeps the full table row layout on a wide screen', async () => {
        await setWidth(desktopWidth);
        const wrapper = mountDashboard([makeCampaign()]);

        expect(wrapper.find('tr.campaign-card').exists()).toBe(false);
        expect(wrapper.text()).toContain('Claims');
        expect(wrapper.find('.v-toolbar button').text()).toBe('Create Campaign');
    });
});
