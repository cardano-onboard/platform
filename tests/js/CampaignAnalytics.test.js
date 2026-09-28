import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import CampaignCharts from "../../resources/js/Components/CampaignCharts.vue";

// Onboarding and wallets used to be cards of their own, which pushed the codes table
// down the page for two panels that frequently have nothing to say. They are tabs of the
// analytics card now, and that card starts collapsed.
function mountAnalytics(overrides = {}) {
    return mount(CampaignCharts, {
        props: {
            campaign: {
                id: "01HQ1234567890ABCDEFGHIJ",
                name: "Test Campaign",
                end_date: "2026-04-30",
                codes: [],
                claims_count: 0,
            },
            stats: {},
            onboarding: {},
            walletClients: { state: "running", floor: 10, clients: null },
            costs: {
                claims: 0,
                claims_unrecorded: 0,
                platform_fee_lovelace: 0,
                network_fee_lovelace: 0,
                reward_lovelace: 0,
                policies: [],
                distinct_assets: 0,
            },
            partnerConversion: { rows: [], summary: {} },
            ...overrides,
        },
    });
}

describe("Campaign analytics card", () => {
    it("starts collapsed, so an empty campaign does not open on a wall of panels", () => {
        const wrapper = mountAnalytics();

        expect(wrapper.vm.expanded).toBe(false);
    });

    it("carries every section once expanded", async () => {
        const wrapper = mountAnalytics();
        wrapper.vm.expanded = true;
        await wrapper.vm.$nextTick();

        const tabs = wrapper.findAll(".v-tab").map((t) => t.text());

        expect(tabs).toEqual([
            "Performance",
            "Onboarding",
            "Wallets",
            "Partners",
            "Costs",
        ]);
    });

    // The three kinds of money are kept apart because they are not the same thing to
    // somebody doing their accounts: one is a payment to us, one goes to the chain, and
    // one was given away.
    it("separates fees to us, fees to the chain, and rewards given away", async () => {
        const wrapper = mountAnalytics({
            costs: {
                claims: 12,
                claims_unrecorded: 0,
                platform_fee_lovelace: 12_000_000,
                network_fee_lovelace: 2_400_000,
                reward_lovelace: 24_000_000,
                policies: [],
                distinct_assets: 0,
            },
        });
        wrapper.vm.expanded = true;
        wrapper.vm.tab = "costs";
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain("Platform fees");
        expect(wrapper.text()).toContain("Network fees");
        expect(wrapper.text()).toContain("Rewards given away");
        expect(wrapper.text()).toContain("12.00");
        expect(wrapper.text()).toContain("2.40");
    });

    it("counts claims with no recorded charge apart rather than as costing nothing", async () => {
        const wrapper = mountAnalytics({
            costs: {
                claims: 10,
                claims_unrecorded: 4,
                platform_fee_lovelace: 6_000_000,
                network_fee_lovelace: 1_200_000,
                reward_lovelace: 0,
                policies: [],
                distinct_assets: 0,
            },
        });
        wrapper.vm.expanded = true;
        wrapper.vm.tab = "costs";
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain("4 of 10 claims carry no");
    });

    it("opens on performance rather than a section that may be empty", () => {
        expect(mountAnalytics().vm.tab).toBe("performance");
    });

    // A tab that disappears cannot be told from a tab that is broken, so one with
    // nothing to show yet still appears and says when its figures arrive.
    it("keeps the wallets section when the campaign is still running", async () => {
        const wrapper = mountAnalytics();
        wrapper.vm.expanded = true;
        wrapper.vm.tab = "wallets";
        await wrapper.vm.$nextTick();

        expect(wrapper.findAll(".v-tab").map((t) => t.text())).toContain(
            "Wallets",
        );
        expect(wrapper.text()).toContain("once this campaign closes");
    });

    // A self-hosted install has no pricing at all: somebody running campaigns for their
    // own project pays us nothing, so a statement of what they paid us is a line that can
    // only ever read zero. The server sends null and the section is absent.
    // A self-hoster pays us nothing, so that line is absent rather than reading zero. Their
    // network fees and what they gave away are real and stay.
    it("withholds the platform fee line where the deployment does not charge", async () => {
        const wrapper = mountAnalytics({
            costs: {
                claims: 5,
                claims_unrecorded: 0,
                platform_fee_lovelace: null,
                network_fee_lovelace: 1_000_000,
                reward_lovelace: 10_000_000,
                policies: [],
                distinct_assets: 0,
            },
        });
        wrapper.vm.expanded = true;
        wrapper.vm.tab = "costs";
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain("Platform fees");
        expect(wrapper.text()).toContain("Network fees");
        expect(wrapper.text()).toContain("Rewards given away");
    });

    // The parts and what they come to. An operator doing their accounts needs both, and
    // a line the deployment withholds must not be counted into the total as a zero.
    it("totals the money it shows and nothing it withholds", async () => {
        const wrapper = mountAnalytics({
            costs: {
                claims: 3,
                claims_unrecorded: 0,
                platform_fee_lovelace: 3_000_000,
                network_fee_lovelace: 600_000,
                reward_lovelace: 6_000_000,
                policies: [],
                distinct_assets: 0,
            },
        });
        wrapper.vm.expanded = true;
        wrapper.vm.tab = "costs";
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain("Total out of the bucket");
        expect(wrapper.text()).toContain("9.60");

        const selfHosted = mountAnalytics({
            costs: {
                claims: 3,
                claims_unrecorded: 0,
                platform_fee_lovelace: null,
                network_fee_lovelace: 600_000,
                reward_lovelace: 6_000_000,
                policies: [],
                distinct_assets: 0,
            },
        });
        selfHosted.vm.expanded = true;
        selfHosted.vm.tab = "costs";
        await selfHosted.vm.$nextTick();

        expect(selfHosted.text()).toContain("6.60");
        expect(selfHosted.text()).not.toContain("9.60");
    });

    // An NFT drop mints a serial per recipient, so the page shows one row per policy and
    // the individual assets go to the export.
    it("groups assets by policy and says why when a policy holds many", async () => {
        const wrapper = mountAnalytics({
            costs: {
                claims: 900,
                claims_unrecorded: 0,
                platform_fee_lovelace: 900_000_000,
                network_fee_lovelace: 180_000_000,
                reward_lovelace: 0,
                policies: [
                    {
                        policy_hex:
                            "a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235",
                        assets: 900,
                        quantity: "900",
                        asset_name: null,
                    },
                ],
                distinct_assets: 900,
            },
        });
        wrapper.vm.expanded = true;
        wrapper.vm.tab = "costs";
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain("Grouped by policy");
        expect(wrapper.text()).toContain("Export CSV");
        expect(
            wrapper.findAll("tbody tr").some((r) => r.text().includes("900")),
        ).toBe(true);
    });

    // The onboarding panel is two components below the page's poller, so the run it is
    // meant to report has to travel through this card. It does no polling of its own.
    it("passes the analysis run through to the onboarding section", async () => {
        const wrapper = mountAnalytics({
            onboarding: { status: "running", eligible_claims: 40 },
            onboardingTask: {
                id: "task-analysis",
                type: "onboarding-analysis",
                status: "running",
                stage: "Reading wallet history",
                progress_done: 12,
                progress_total: 40,
                error: null,
                result: null,
                completed_at: null,
                reloads: ["onboarding"],
            },
        });
        wrapper.vm.expanded = true;
        wrapper.vm.tab = "onboarding";
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain("Reading wallet history");
        expect(wrapper.text()).toContain("12 of 40");
    });
});
