import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router, useForm } from '@inertiajs/vue3';
import CampaignOnboarding from '../../resources/js/Components/CampaignOnboarding.vue';

const CAMPAIGN = { id: '01HQ1234567890ABCDEFGHIJ', name: 'Conference drop' };

// One follow-up window, as the server writes them: a list, in order, with the wallets too
// recently claimed to have been watched this long counted apart from the ones that were.
function windowRow(days, overrides = {}) {
    return {
        days,
        observable: 10,
        not_yet: 0,
        active: 3,
        active_pct: 30,
        untimed: 0,
        new_observable: 6,
        new_not_yet: 0,
        new_untimed: 0,
        new_active: 3,
        new_active_pct: 50,
        // Delegation carries its own denominator. It is not the activity one: a wallet is
        // held out of a window by the event that window measures and by nothing else.
        new_delegation_observable: 6,
        new_delegation_untimed: 0,
        new_delegated: 2,
        new_delegated_pct: 33.3,
        ...overrides,
    };
}

// A campaign of twenty claims, twelve of them confirmed on chain.
const WHOLE_CAMPAIGN = {
    network: 'mainnet',
    genuine_wallets: 10,
    operator_wallets: 0,
    classified_wallets: 10,
    unclassified_wallets: 0,
    new_wallets: 6,
    new_pct: 60,
    established_wallets: 4,
    established_pct: 40,
    windows_available: true,
    windows_observed: 10,
    // Six of the ten wallets read are new, and the activity rate is a share of those six.
    windows_observed_new: 6,
    windows_observed_established: 4,
    windows_unknown: 0,
    windows_observed_at: '2026-09-15T10:00:00+00:00',
    new_active: 3,
    new_active_pct: 50,
    established_active: 2,
    established_active_pct: 50,
    delegation_only: 1,
    delegated: 4,
    delegated_pct: 40,
    delegation_known: 10,
    new_delegated: 2,
    new_delegated_pct: 33.3,
    new_delegation_known: 6,
    new_delegated_after_claim: 2,
    new_delegated_after_claim_pct: 33.3,
    script_interactors: 1,
    script_interactors_pct: 10,
    windows: [windowRow(30), windowRow(60), windowRow(90)],
    observation_days_avg: 21,
    observation_wallets: 10,
    claims_total: 20,
    claims_confirmed: 12,
    claims_analyzable: 12,
    claims_unconfirmed: 8,
    coverage_pct: 60,
};

// The event day on its own: every claimant that day was new, and two thirds went on to
// transact for themselves.
const EVENT_DAY = {
    ...WHOLE_CAMPAIGN,
    genuine_wallets: 6,
    classified_wallets: 6,
    new_wallets: 6,
    new_pct: 100,
    windows_observed: 6,
    windows_observed_new: 6,
    windows_observed_established: 0,
    delegation_known: 6,
    new_delegation_known: 6,
    new_active: 4,
    new_active_pct: 66.7,
    claims_total: 8,
    claims_confirmed: 6,
    claims_analyzable: 6,
    claims_unconfirmed: 2,
    coverage_pct: 75,
};

// A run in flight as the page's shared poller reports it. The panel reads the phase and
// the count from here; it keeps no clock of its own.
function analysisTask(overrides = {}) {
    return {
        id: 'task-onboarding',
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
        ...overrides,
    };
}

// A campaign that has already been analysed, for the coverage and date range cases.
function mountPanel(onboarding = {}, task = null) {
    return mount(CampaignOnboarding, {
        props: {
            campaign: CAMPAIGN,
            onboarding: {
                status: 'complete',
                completed_at: '2026-09-15T10:00:00+00:00',
                windowed: true,
                error: null,
                eligible_claims: 12,
                total_claims: 20,
                pending_claims: 0,
                summary: WHOLE_CAMPAIGN,
                wallets: [],
                scope: {
                    from: null,
                    to: null,
                    applied: false,
                    error: null,
                    summary: null,
                    wallets_total: 10,
                    wallets_undated: 0,
                    bounds: { first: '2026-09-01', last: '2026-09-22' },
                },
                ...onboarding,
            },
            task,
            embedded: true,
        },
    });
}

beforeEach(() => {
    vi.clearAllMocks();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Onboarding panel: claims the analysis cannot see', () => {
    it('says how many claims are unconfirmed before anyone runs the analysis', () => {
        const wrapper = mountPanel({ pending_claims: 8, status: null, summary: null });

        expect(wrapper.text()).toContain('8 claim(s) have no confirmed transaction yet');
        expect(wrapper.text()).toContain('Running it checks them first');
    });

    it('offers the status check in the panel rather than leaving it above the codes table', async () => {
        const wrapper = mountPanel({ pending_claims: 8, status: null, summary: null });

        const button = wrapper
            .findAllComponents({ name: 'VBtn' })
            .find((candidate) => candidate.text().includes('Check claims'));

        expect(button).toBeTruthy();

        await button.trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/campaigns/check-claims/01HQ1234567890ABCDEFGHIJ',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    // A check started from the codes table or from here is still running, so the button
    // waits for it rather than offering a second press the server would refuse.
    it('holds the check button while a claim check is running', () => {
        const wrapper = mount(CampaignOnboarding, {
            props: {
                campaign: CAMPAIGN,
                onboarding: { pending_claims: 8, status: null, summary: null },
                claimCheckRunning: true,
            },
        });

        const button = wrapper
            .findAllComponents({ name: 'VBtn' })
            .find((candidate) => candidate.text().includes('Check claims'));

        expect(button.props('loading')).toBe(true);
    });

    it('leaves the check button free when no claim check is running', () => {
        const wrapper = mountPanel({ pending_claims: 8, status: null, summary: null });

        const button = wrapper
            .findAllComponents({ name: 'VBtn' })
            .find((candidate) => candidate.text().includes('Check claims'));

        expect(button.props('loading')).toBe(false);
    });

    it('says nothing about unconfirmed claims when there are none', () => {
        const wrapper = mountPanel({ pending_claims: 0 });

        expect(wrapper.text()).not.toContain('no confirmed transaction yet');
    });

    // The campaign this matters most for: an event that finished yesterday, where no
    // claim has been checked since the venue. Refusing to analyse it would leave the
    // operator with a control that does nothing.
    it('offers to analyse a campaign whose claims are all still unconfirmed', () => {
        const wrapper = mountPanel({
            status: null,
            summary: null,
            eligible_claims: 0,
            pending_claims: 8,
        });

        const analyze = wrapper
            .findAllComponents({ name: 'VBtn' })
            .find((candidate) => candidate.text().includes('Analyze'));

        expect(analyze).toBeTruthy();
    });

    it('does not offer to analyse a campaign with no claims at all', () => {
        const wrapper = mountPanel({
            status: null,
            summary: null,
            eligible_claims: 0,
            pending_claims: 0,
        });

        const analyze = wrapper
            .findAllComponents({ name: 'VBtn' })
            .find((candidate) => candidate.text().includes('Analyze'));

        expect(analyze).toBeFalsy();
        expect(wrapper.text()).toContain('becomes available once this campaign has taken its first claim');
    });

    // The phase comes off the task row the page's poller reads. The confirmation pass is
    // the one phase the standing description gets wrong, because nothing has been read off
    // the chain yet, so it is the one the panel describes for itself.
    it('separates confirming claims from reading the chain while a run is in flight', () => {
        const confirming = mountPanel(
            { status: 'running', pending_claims: 8 },
            analysisTask({
                stage: 'Confirming outstanding claims',
                progress_done: 0,
                progress_total: 8,
            }),
        );
        expect(confirming.text()).toContain('Confirming outstanding claims');
        expect(confirming.text()).toContain('Confirming 8 claim(s)');

        const analyzing = mountPanel({ status: 'running', pending_claims: 0 }, analysisTask());
        expect(analyzing.text()).toContain('Reading chain history for 12 claim(s)');
    });
});

describe('Onboarding panel: coverage', () => {
    it('states how much of the campaign the result covered', () => {
        const wrapper = mountPanel();

        expect(wrapper.text()).toContain('Covers 12 of 20 claim(s)');
        expect(wrapper.text()).toContain('60%');
        expect(wrapper.text()).toContain('8 claim(s) had no confirmed transaction when this ran');
    });

    // A confirmed claim from an enterprise address has no stake key, so there is no wallet
    // history to read. It is not something a status check can fix, and saying so keeps it
    // apart from the claims that are merely unchecked.
    it('separates claims with no stake key from claims that are merely unconfirmed', () => {
        const wrapper = mountPanel({
            summary: { ...WHOLE_CAMPAIGN, claims_confirmed: 12, claims_analyzable: 9 },
        });

        expect(wrapper.text()).toContain('3 confirmed claim(s) came from an address with no stake key');
    });

    // A range can hold wallets whose claim the chain timestamped inside it and no claim
    // the platform recorded inside it, because the two clocks are different. Covering none
    // of no claims is not zero percent covered.
    it('says nothing about coverage where the range holds no claims to cover', () => {
        const wrapper = mountPanel({
            summary: {
                ...WHOLE_CAMPAIGN,
                claims_total: 0,
                claims_confirmed: 0,
                claims_analyzable: 0,
                claims_unconfirmed: 0,
                coverage_pct: 0,
            },
        });

        expect(wrapper.text()).not.toContain('Covers');
        expect(wrapper.text()).not.toContain('0 of 0 claim(s)');
    });

    // Results stored before coverage was recorded carry none of these fields.
    it('says nothing about coverage where the result did not record it', () => {
        const summary = { ...WHOLE_CAMPAIGN };
        delete summary.claims_total;
        delete summary.claims_confirmed;
        delete summary.claims_analyzable;
        delete summary.claims_unconfirmed;
        delete summary.coverage_pct;

        const wrapper = mountPanel({ summary });

        expect(wrapper.text()).not.toContain('Covers');
    });
});

describe('Onboarding panel: unknown is not zero', () => {
    // A result stored before certificates were read cannot say who transacted and who
    // merely delegated, because delegating puts the wallet into its own transaction as an
    // input. Showing 0% would state something the run never established.
    it('says the activity figure is unknown when no windowed run has read the campaign', () => {
        const wrapper = mountPanel({
            windowed: false,
            summary: {
                ...WHOLE_CAMPAIGN,
                windows_available: false,
                windows_observed: 0,
                windows_unknown: 10,
                windows: [],
            },
        });

        const text = wrapper.text();
        expect(text).toContain('Unknown');
        expect(text).toContain('Re-run the analysis');
        // The corrected percentage is not shown, and neither is a zero standing in for it.
        expect(text).not.toContain('50%');
    });

    it('shows a zero it actually measured rather than hiding it', () => {
        const wrapper = mountPanel({
            summary: {
                ...WHOLE_CAMPAIGN,
                new_active: 0,
                new_active_pct: 0,
                delegation_only: 0,
                windows: [
                    windowRow(30, { new_active: 0, new_active_pct: 0, new_delegated: 0, new_delegated_pct: 0 }),
                    windowRow(60, { new_active: 0, new_active_pct: 0, new_delegated: 0, new_delegated_pct: 0 }),
                    windowRow(90, { new_active: 0, new_active_pct: 0, new_delegated: 0, new_delegated_pct: 0 }),
                ],
            },
        });

        const text = wrapper.text();
        expect(text).toContain('0%');
        expect(text).not.toContain('Unknown');
        expect(text).toContain('No wallet delegated after claiming without also transacting');
    });

    it('counts wallets no windowed run has read instead of absorbing them', () => {
        const wrapper = mountPanel({
            summary: { ...WHOLE_CAMPAIGN, windows_observed: 7, windows_unknown: 3 },
        });

        expect(wrapper.text()).toContain('3 wallet(s) have not been read');
    });

    // The percentage and the denominator printed under it have to be the same population.
    // Three of ten read is thirty percent, and the tile said fifty, because the rate was
    // over the new wallets and the sentence beneath it was over every wallet read.
    it('prints the activity rate against the population it was taken over', () => {
        const wrapper = mountPanel();
        const text = wrapper.text();

        expect(text).toContain(
            '3 of 6 new wallet(s) read sent a transaction of their own, not counting delegation',
        );
        expect(text).not.toContain('3 of 10 read sent a transaction');
    });

    it('prints the new wallet rate against the wallets whose history was read', () => {
        const wrapper = mountPanel({
            summary: {
                ...WHOLE_CAMPAIGN,
                genuine_wallets: 12,
                classified_wallets: 10,
                unclassified_wallets: 2,
            },
        });

        expect(wrapper.text()).toContain('6 of 10 wallet(s) read had no prior on-chain history');
    });

    // A provider failure is not a measurement. The wallets behind it are unknown, and a
    // run with holes in it must not present itself as a reading of the campaign.
    it('says a run could not read part of the chain instead of reporting its gaps as zeros', () => {
        const wrapper = mountPanel({
            status: 'partial',
            unread_wallets: 4,
            summary: {
                ...WHOLE_CAMPAIGN,
                genuine_wallets: 10,
                classified_wallets: 6,
                unclassified_wallets: 4,
                windows_observed: 6,
                windows_observed_new: 4,
                windows_unknown: 4,
            },
        });

        const text = wrapper.text();

        // The result is still shown, because six wallets were read.
        expect(text).toContain('Newly onboarded');
        expect(text).toContain('could not read chain data for');
        expect(text).toContain('4 wallet(s)');
        expect(text).toContain('4 wallet(s) could not be read at all');
    });

    // Three states, not two: nothing measured, measured and empty, measured with results.
    // An empty range used to render as a result predating certificate reading, sending the
    // operator to re-run an analysis that would change nothing.
    it('reports a range nobody claimed in as a result rather than a missing measurement', () => {
        const wrapper = mountPanel({
            scope: {
                from: '2026-09-10',
                to: '2026-09-12',
                applied: true,
                error: null,
                wallets_total: 10,
                wallets_undated: 0,
                bounds: { first: '2026-09-01', last: '2026-09-22' },
                summary: {
                    ...WHOLE_CAMPAIGN,
                    genuine_wallets: 0,
                    classified_wallets: 0,
                    unclassified_wallets: 0,
                    new_wallets: 0,
                    new_pct: 0,
                    windows_available: true,
                    windows_observed: 0,
                    windows_observed_new: 0,
                    windows_unknown: 0,
                    windows: [],
                    claims_total: 0,
                    claims_confirmed: 0,
                    claims_analyzable: 0,
                    claims_unconfirmed: 0,
                    coverage_pct: 0,
                },
            },
        });

        const text = wrapper.text();

        expect(text).toContain('No claimant wallets fall in this range');
        expect(text).not.toContain('measured before delegation could be separated');
        expect(text).not.toContain('Re-run the analysis to');
    });
});

// A run that finished now and read nothing is not a run from before the measurement
// existed, and a rate over nobody is not zero percent.
describe('Onboarding panel: a run that read nothing', () => {
    // Exactly what the server writes when the provider answers 500 for every wallet: the
    // rows are unknown, the run is partial, and every denominator in the summary is zero.
    const READ_NOTHING = {
        ...WHOLE_CAMPAIGN,
        genuine_wallets: 2,
        classified_wallets: 0,
        unclassified_wallets: 2,
        new_wallets: 0,
        new_pct: 0,
        established_wallets: 0,
        established_pct: 0,
        windows_available: false,
        windows_observed: 0,
        windows_observed_new: 0,
        windows_observed_established: 0,
        windows_unknown: 2,
        new_active: 0,
        new_active_pct: 0,
        delegated: 0,
        delegated_pct: 0,
        delegation_known: 0,
        new_delegated: 0,
        new_delegated_pct: 0,
        new_delegation_known: 0,
        delegation_only: 0,
        script_interactors: 0,
        script_interactors_pct: 0,
        windows: [],
        observation_days_avg: null,
        observation_wallets: 0,
    };

    function panel() {
        return mountPanel({
            status: 'partial',
            unread_wallets: 2,
            windowed: true,
            summary: READ_NOTHING,
        });
    }

    // Every tile asks about its own population. Two of the three used to inherit the
    // windows tile's answer and print "0% Newly onboarded, 0 of 0 wallet(s) read" in large
    // type off a run that read nothing at all.
    it('marks every tile whose population was not read as unknown', () => {
        const text = panel().text();

        expect(text).not.toContain('0 of 0');
        expect(text).toContain('No wallet history was read');
        expect(text).toContain('No claimant account was read');
    });

    it('shows no percentage on a tile whose population is empty', () => {
        const tiles = panel()
            .findAll('.v-card--variant-tonal')
            .map((card) => card.text())
            .filter((t) => t.includes('Newly onboarded') || t.includes('Delegated to a pool'));

        expect(tiles.length).toBe(2);
        tiles.forEach((tile) => {
            expect(tile).toContain('Unknown');
            expect(tile).not.toContain('0%');
        });
    });

    // The run could separate delegation from activity and did read certificates. It was
    // the provider that was down, and saying otherwise sends the operator to re-run
    // something that will fail the same way.
    it('says the provider could not be reached rather than blaming an older run', () => {
        const text = panel().text();

        expect(text).toContain('The chain data provider did not answer for any of these wallets');
        expect(text).not.toContain('measured before delegation could be separated');
    });

    // A stored result from a run that predates certificate reading still says so.
    it('still names an older run when that is what the result came from', () => {
        const text = mountPanel({
            windowed: false,
            summary: { ...WHOLE_CAMPAIGN, windows_available: false, windows_observed: 0, windows: [] },
        }).text();

        expect(text).toContain('measured before delegation could be separated');
        expect(text).not.toContain('The chain data provider did not answer');
    });

    // The window used to fall back to the time elapsed since the claim, so a result nobody
    // had re-read grew a longer observation every day and described the reader.
    it('prints no observation window for wallets that were not observed', () => {
        const text = panel().text();

        expect(text).not.toContain('Measured over roughly');
        expect(text).toContain('No wallet here has a recorded length of observation');
    });

    it('prints the observation window with the wallets it was taken over', () => {
        expect(mountPanel().text()).toContain(
            'Measured over roughly 21 day(s) since claim on average, across 10 wallet(s) a run watched',
        );
    });
});

describe('Onboarding panel: follow-up windows', () => {
    it('reports thirty, sixty and ninety days in that order', () => {
        const wrapper = mountPanel();
        const rows = wrapper.findAll('tbody tr').map((row) => row.text());

        expect(rows[0]).toContain('30 days');
        expect(rows[1]).toContain('60 days');
        expect(rows[2]).toContain('90 days');
    });

    // A wallet that claimed nine days ago has no ninety-day answer. Counting it as one
    // that did nothing would report the campaign as having failed at something it has not
    // been given time to do.
    it('keeps wallets too recent for a window out of that window', () => {
        const wrapper = mountPanel({
            summary: {
                ...WHOLE_CAMPAIGN,
                windows: [
                    windowRow(30),
                    windowRow(60, { new_observable: 2, new_not_yet: 4, new_active: 1, new_active_pct: 50 }),
                    windowRow(90, { new_observable: 0, new_not_yet: 6, new_active: 0, new_active_pct: 0 }),
                ],
            },
        });

        const rows = wrapper.findAll('tbody tr').map((row) => row.text());

        expect(rows[1]).toContain('4');
        expect(rows[2]).toContain('6');
    });

    // A wallet whose transaction carries no block time cannot be placed inside or outside
    // thirty days. Left in the denominator it is a wallet the headline reports as having
    // transacted and every window reports as not having, on one screen.
    it('holds a wallet whose activity has no timestamp out of the window', () => {
        const wrapper = mountPanel({
            summary: {
                ...WHOLE_CAMPAIGN,
                windows: [
                    windowRow(30, { new_observable: 5, new_untimed: 1, new_active: 3, new_active_pct: 60 }),
                    windowRow(60, { new_observable: 5, new_untimed: 1, new_active: 3, new_active_pct: 60 }),
                    windowRow(90, { new_observable: 5, new_untimed: 1, new_active: 3, new_active_pct: 60 }),
                ],
            },
        });

        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers).toContain('No timestamp');

        const cells = wrapper.findAll('tbody tr')[0].findAll('td').map((td) => td.text());
        // Window, observable, too recent, no timestamp.
        expect(cells[1]).toBe('5');
        expect(cells[3]).toBe('1');

        expect(wrapper.text()).toContain(
            '1 wallet(s) transacted at a time the chain query did not report',
        );
    });

    // The delegation rate is over the wallets whose delegation the chain timed, which is
    // not the activity denominator two columns to its left.
    it('prints the delegation rate against its own population', () => {
        const wrapper = mountPanel({
            summary: {
                ...WHOLE_CAMPAIGN,
                windows: [
                    windowRow(30, {
                        new_observable: 4,
                        new_untimed: 2,
                        new_active: 2,
                        new_active_pct: 50,
                        new_delegation_observable: 6,
                        new_delegation_untimed: 0,
                        new_delegated: 3,
                        new_delegated_pct: 50,
                    }),
                    windowRow(60),
                    windowRow(90),
                ],
            },
        });

        const cells = wrapper.findAll('tbody tr')[0].findAll('td').map((td) => td.text());

        // Window, observable, too recent, no timestamp, transacted, delegated.
        expect(cells[1]).toBe('4');
        expect(cells[5]).toContain('3 of 6');
        expect(cells[5]).not.toContain('3 of 4');
    });

    // A window no wallet has been watched long enough for has no rate at all.
    it('says unknown rather than zero percent for a window nobody is observable in', () => {
        const wrapper = mountPanel({
            summary: {
                ...WHOLE_CAMPAIGN,
                windows: [
                    windowRow(30),
                    windowRow(60),
                    windowRow(90, {
                        new_observable: 0,
                        new_not_yet: 6,
                        new_active: 0,
                        new_active_pct: 0,
                        new_delegation_observable: 0,
                        new_delegated: 0,
                        new_delegated_pct: 0,
                    }),
                ],
            },
        });

        const cells = wrapper.findAll('tbody tr')[2].findAll('td').map((td) => td.text());

        expect(cells[4]).toBe('Unknown');
        expect(cells[5]).toBe('Unknown');
    });

    it('counts wallets whose delegation carries no time apart from wallets whose transaction does', () => {
        const wrapper = mountPanel({
            summary: {
                ...WHOLE_CAMPAIGN,
                windows: [
                    windowRow(30, { new_untimed: 1, new_delegation_untimed: 2 }),
                    windowRow(60, { new_untimed: 1, new_delegation_untimed: 2 }),
                    windowRow(90, { new_untimed: 1, new_delegation_untimed: 2 }),
                ],
            },
        });

        const text = wrapper.text();

        expect(text).toContain('1 wallet(s) transacted at a time the chain');
        expect(text).toContain('2 wallet(s) delegated at a time the chain');
    });

    it('names the wallets that only delegated', () => {
        const wrapper = mountPanel({
            summary: { ...WHOLE_CAMPAIGN, delegation_only: 3 },
        });

        expect(wrapper.text()).toContain('3 wallet(s) delegated after claiming and did');
    });

    // The per-wallet table has to make the same distinction the tiles do: a dash where
    // nothing was measured, a zero where something was and it was nothing.
    it('marks a wallet no windowed run has read as unknown in the table', async () => {
        const wrapper = mountPanel({
            wallets: [
                {
                    stake_key: 'stake1unread',
                    stake_key_short: 'stake1un…unread',
                    is_new: true,
                    windowed: false,
                    activity_count: null,
                    delegation_events: null,
                    first_activity_days: null,
                    first_delegation_days: null,
                    delegated: false,
                    is_operator: false,
                    prior_tx_count: 0,
                    self_initiated_count: 0,
                    claimed_at: '2026-09-01T10:00:00+00:00',
                },
                {
                    stake_key: 'stake1idle',
                    stake_key_short: 'stake1id…le0000',
                    is_new: true,
                    windowed: true,
                    activity_count: 0,
                    delegation_events: 1,
                    first_activity_days: null,
                    first_delegation_days: 4,
                    delegated: true,
                    is_operator: false,
                    prior_tx_count: 0,
                    self_initiated_count: 1,
                    claimed_at: '2026-09-01T10:00:00+00:00',
                },
            ],
        });

        // The per-wallet table is detail the operator asks for, so it has to be opened
        // before anything in it can be read.
        await wrapper.find('.mdi-chevron-down').trigger('click');

        // Cell by cell, not by searching the row for a word. Two columns can say
        // "unknown" and the assertion has to be about the right one, or a cell printing a
        // zero where it means nothing-was-measured would pass on its neighbour.
        const cells = (match) =>
            wrapper
                .findAll('tbody tr')
                .filter((row) => row.text().includes(match))
                .map((row) => row.findAll('td').map((cell) => cell.text()))[0];

        const [, , unreadActivity, unreadDays, unreadDelegation] = cells('unread');
        const [, , idleActivity, idleDays, idleDelegation] = cells('le0000');

        // Never read: both answers are unknown, and neither is a zero.
        expect(unreadActivity).toBe('unknown');
        expect(unreadActivity).not.toBe('0');
        expect(unreadDelegation).toBe('unknown');
        expect(unreadDays).toBe('—');

        // Read, and it did nothing of its own but did delegate on day four.
        expect(idleActivity).toBe('0');
        expect(idleDays).toBe('—');
        expect(idleDelegation).toBe('day 4');
    });

    // A wallet whose history no run could read is neither new nor established, and its
    // prior transaction count is not zero. Both cells say so.
    it('marks a wallet whose history could not be read as unknown rather than established', async () => {
        const wrapper = mountPanel({
            wallets: [
                {
                    stake_key: 'stake1failed',
                    stake_key_short: 'stake1fa…failed',
                    is_new: null,
                    windowed: false,
                    activity_count: null,
                    delegation_events: null,
                    first_activity_days: null,
                    first_delegation_days: null,
                    delegated: null,
                    is_operator: false,
                    prior_tx_count: null,
                    self_initiated_count: 0,
                    claimed_at: '2026-09-01T10:00:00+00:00',
                },
            ],
        });

        await wrapper.find('.mdi-chevron-down').trigger('click');

        const cells = wrapper.findAll('tbody tr').at(-1).findAll('td').map((cell) => cell.text());

        // Classification, and the prior transaction count at the end of the row.
        expect(cells[1]).toBe('unknown');
        expect(cells[1]).not.toBe('established');
        expect(cells.at(-1)).toBe('unknown');
        expect(cells.at(-1)).not.toBe('0');
    });
});

describe('Onboarding panel: date range', () => {
    const SCOPED = {
        scope: {
            from: '2026-09-01',
            to: '2026-09-01',
            applied: true,
            error: null,
            summary: EVENT_DAY,
            wallets_total: 10,
            wallets_undated: 0,
            bounds: { first: '2026-09-01', last: '2026-09-22' },
        },
    };

    // The whole point of the range: the event's own result and the campaign's are
    // different numbers, and the difference is what is being asked for.
    it('reports the range and the whole campaign together', () => {
        const wrapper = mountPanel(SCOPED);

        const text = wrapper.text();

        expect(text).toContain('100%');
        expect(text).toContain('Whole campaign: 60%');
        expect(text).toContain('Scoped to claims from 2026-09-01');
        expect(text).toContain('6 of 10 claimant wallet(s)');
    });

    it('shows no comparison when no range is set', () => {
        const wrapper = mountPanel();

        expect(wrapper.text()).not.toContain('Whole campaign:');
    });

    it('counts coverage over the range rather than the campaign', () => {
        const wrapper = mountPanel(SCOPED);

        expect(wrapper.text()).toContain('Covers 6 of 8 claim(s)');
    });

    it('sends the range as a partial reload of the panel alone', async () => {
        const wrapper = mountPanel();

        wrapper.vm.range.onboarding_from = '2026-09-01';
        wrapper.vm.range.onboarding_to = '2026-09-02';

        const apply = wrapper
            .findAllComponents({ name: 'VBtn' })
            .find((candidate) => candidate.text().includes('Apply range'));

        await apply.trigger('click');

        const form = useForm.mock.results[0].value;

        expect(form.onboarding_from).toBe('2026-09-01');
        expect(form.onboarding_to).toBe('2026-09-02');
        expect(form.get).toHaveBeenCalledWith(
            '/campaigns/show/01HQ1234567890ABCDEFGHIJ',
            expect.objectContaining({ only: ['onboarding'], preserveState: true }),
        );
    });

    it('clears both ends when asked for the whole campaign again', async () => {
        const wrapper = mountPanel(SCOPED);

        const clear = wrapper
            .findAllComponents({ name: 'VBtn' })
            .find((candidate) => candidate.text().includes('Whole campaign'));

        await clear.trigger('click');

        const form = useForm.mock.results[0].value;

        expect(form.onboarding_from).toBe('');
        expect(form.onboarding_to).toBe('');
        expect(form.get).toHaveBeenCalled();
    });

    it('reports a range it could not read instead of answering a different question', () => {
        const wrapper = mountPanel({
            scope: {
                ...SCOPED.scope,
                applied: false,
                summary: null,
                error: 'The end of the range is before its start.',
            },
        });

        const text = wrapper.text();

        expect(text).toContain('The end of the range is before its start.');
        expect(text).toContain('Showing the whole campaign.');
        // The whole-campaign figures are still the ones on show.
        expect(text).toContain('60%');
        expect(text).not.toContain('Whole campaign:');
    });

    it('names the wallets that fall in no range at all', () => {
        const wrapper = mountPanel({
            scope: { ...SCOPED.scope, wallets_undated: 2 },
        });

        expect(wrapper.text()).toContain('2 wallet(s) have no claim date recorded');
    });

    it('offers only the days the campaign has rows for', () => {
        const wrapper = mountPanel();
        const field = wrapper.find('input[type="date"]');

        expect(field.attributes('min')).toBe('2026-09-01');
        expect(field.attributes('max')).toBe('2026-09-22');
    });
});

// A campaign with claims and no stored result, for the cases about a run in flight. The
// panel is given only what those cases turn on, so nothing else can be what makes them
// pass.
function mountBare(onboarding = {}, task = null) {
    return mount(CampaignOnboarding, {
        props: {
            campaign: CAMPAIGN,
            onboarding: { eligible_claims: 40, windowed: true, ...onboarding },
            task,
        },
    });
}

/**
 * The panel's own progress bar. Vuetify leaves an inert one in the toolbar, so the live
 * bar is picked out by the attribute that says it is live rather than by position.
 * aria-valuenow is also how Vuetify reports determinate against indeterminate: an
 * indeterminate bar deliberately carries no value.
 */
function progressBar(wrapper) {
    return wrapper.find('[role="progressbar"][aria-hidden="false"]');
}

describe("CampaignOnboarding", () => {
    beforeEach(() => {
        router.reload.mockClear();
    });

    it("names the phase the run is in, not merely that a run is happening", () => {
        const wrapper = mountBare({ status: "running" }, analysisTask());

        expect(wrapper.text()).toContain("Reading wallet history");
    });

    // The wallet loop is the only phase with one call per wallet, and so the only phase
    // with a number. Reporting it is the difference between a bar that moves and a bar
    // that spins.
    it("shows how far through the counted phase the run is", () => {
        const wrapper = mountBare({ status: "running" }, analysisTask());

        expect(wrapper.text()).toContain("12 of 40");
        expect(progressBar(wrapper).attributes("aria-valuenow")).toBe("30");
    });

    it("leaves the bar indeterminate for a phase that counts nothing", () => {
        const wrapper = mountBare(
            { status: "running" },
            analysisTask({
                stage: "Reading claim transactions",
                progress_done: 0,
                progress_total: null,
            }),
        );

        expect(wrapper.text()).toContain("Reading claim transactions");
        expect(wrapper.text()).not.toContain(" of ");
        expect(progressBar(wrapper).attributes("aria-valuenow")).toBeUndefined();
    });

    /**
     * The whole point of moving onto the shared framework. One poller on the page drives
     * every job; a panel that kept its own interval would ask a second time for the same
     * answer, and a campaign importing codes while an analysis ran would ask twice over.
     */
    it("runs no timer of its own", async () => {
        vi.useFakeTimers();
        const interval = vi.spyOn(globalThis, "setInterval");

        const wrapper = mountBare({ status: "running" }, analysisTask());

        await vi.advanceTimersByTimeAsync(60000);

        expect(interval).not.toHaveBeenCalled();
        expect(router.reload).not.toHaveBeenCalled();

        wrapper.unmount();
        interval.mockRestore();
        vi.useRealTimers();
    });

    /**
     * A run started from the console command has no task row. The stored analysis row is
     * the only thing that knows, so the panel still has to read it.
     */
    it("still reports a run it was given no task for", () => {
        const wrapper = mountBare({ status: "running" }, null);

        const text = wrapper.text();
        expect(text).toContain("Reading chain history for 40 claim(s)");
        expect(progressBar(wrapper).exists()).toBe(true);
    });

    /**
     * A run that died before it reached the analysis leaves that row on "pending". Trusting
     * it would leave the operator watching a bar that was never going to move again.
     */
    it("believes the failed run over an analysis row still saying pending", () => {
        const wrapper = mountBare(
            { status: "pending" },
            analysisTask({
                status: "failed",
                stage: null,
                error: "That campaign no longer exists.",
                completed_at: "2026-09-15T10:04:00+00:00",
            }),
        );

        const text = wrapper.text();
        expect(text).toContain("The last analysis did not finish");
        expect(text).toContain("That campaign no longer exists.");
        expect(text).not.toContain("Reading chain history");
    });

    it("falls back to the stored reason when the failure predates the task row", () => {
        const wrapper = mountBare(
            { status: "failed", error: "Koios is unreachable." },
            null,
        );

        expect(wrapper.text()).toContain("Koios is unreachable.");
    });

    it("shows the finished result once the run is over", () => {
        const wrapper = mountBare(
            {
                status: "complete",
                completed_at: "2026-09-15T10:05:00+00:00",
                summary: {
                    genuine_wallets: 30,
                    operator_wallets: 2,
                    new_wallets: 18,
                    new_pct: 60,
                    windows_available: true,
                    windows_observed: 30,
                    windows_unknown: 0,
                    new_active: 9,
                    new_active_pct: 50,
                    delegation_only: 0,
                    delegated: 12,
                    delegated_pct: 40,
                    new_delegated: 5,
                    windows: [windowRow(30), windowRow(60), windowRow(90)],
                    observation_days_avg: 14,
                },
                wallets: [],
            },
            analysisTask({
                status: "complete",
                stage: null,
                progress_done: 30,
                progress_total: 30,
                completed_at: "2026-09-15T10:05:00+00:00",
                result: { wallets: 30 },
            }),
        );

        const text = wrapper.text();
        expect(text).toContain("Newly onboarded");
        expect(text).toContain("60%");
        // A finished run reports no phase, so nothing about it should still read as work
        // in progress.
        expect(text).not.toContain("Reading wallet history");
    });

    afterEach(() => {
        vi.useRealTimers();
    });
});
