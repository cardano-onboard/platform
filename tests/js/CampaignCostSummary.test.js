import { describe, it, expect, vi, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import CampaignCostSummary from "../../resources/js/Components/CampaignCostSummary.vue";

const USDM_POLICY = "c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad";
const USDM_HEX = "0014df105553444d";
const MASCOT_HEX = "4d6173636f74";

function costs(policies) {
    return {
        claims: 1,
        claims_unrecorded: 0,
        platform_fee_lovelace: null,
        network_fee_lovelace: 0,
        reward_lovelace: 0,
        policies,
        distinct_assets: policies.length,
    };
}

function mountSummary(policies) {
    return mount(CampaignCostSummary, {
        props: {
            campaign: { id: "c1", network: "mainnet" },
            costs: costs(policies),
        },
    });
}

function answering(found) {
    window.axios = { post: vi.fn().mockResolvedValue({ data: found }) };
}

afterEach(() => {
    delete window.axios;
});

describe("CampaignCostSummary native assets", () => {
    it("shows a fungible token by its ticker and in whole units", async () => {
        answering({
            [USDM_POLICY + USDM_HEX]: {
                ticker: "USDM",
                name: "USDM",
                decimals: 6,
                logo: null,
            },
        });

        const wrapper = mountSummary([
            {
                policy_hex: USDM_POLICY,
                asset_hex: USDM_HEX,
                asset_name: "(333)USDM",
                assets: 1,
                quantity: "251000000",
            },
        ]);
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledTimes(1);
        expect(window.axios.post).toHaveBeenCalledWith(expect.any(String), {
            network: "mainnet",
            assets: [{ policy: USDM_POLICY, asset_name: USDM_HEX }],
        });
        expect(wrapper.text()).toContain("USDM");
        expect(wrapper.text()).not.toContain("(333)USDM");
        expect(wrapper.text()).toContain("251");
        expect(wrapper.text()).not.toContain("251000000");
    });

    it("keeps the decoded name and raw quantity when the lookup finds nothing", async () => {
        answering({ [USDM_POLICY + MASCOT_HEX]: null });

        const wrapper = mountSummary([
            {
                policy_hex: USDM_POLICY,
                asset_hex: MASCOT_HEX,
                asset_name: "Mascot",
                assets: 1,
                quantity: "314159",
            },
        ]);
        await flushPromises();

        expect(wrapper.text()).toContain("Mascot");
        expect(wrapper.text()).toContain((314159).toLocaleString());
    });

    it("keeps the decoded name and raw quantity when the lookup fails", async () => {
        window.axios = { post: vi.fn().mockRejectedValue(new Error("504")) };

        const wrapper = mountSummary([
            {
                policy_hex: USDM_POLICY,
                asset_hex: MASCOT_HEX,
                asset_name: "Mascot",
                assets: 1,
                quantity: "314159",
            },
        ]);
        await flushPromises();

        expect(wrapper.text()).toContain("Mascot");
        expect(wrapper.text()).toContain((314159).toLocaleString());
    });

    it("prefers the decoded name over the chain reading when the token has no ticker", async () => {
        answering({
            [USDM_POLICY + MASCOT_HEX]: {
                ticker: null,
                name: "garbled",
                decimals: 0,
                logo: null,
            },
        });

        const wrapper = mountSummary([
            {
                policy_hex: USDM_POLICY,
                asset_hex: MASCOT_HEX,
                asset_name: "Mascot",
                assets: 1,
                quantity: "1",
            },
        ]);
        await flushPromises();

        expect(wrapper.text()).toContain("Mascot");
        expect(wrapper.text()).not.toContain("garbled");
    });

    it("uses the metadata the server sent and asks nothing", async () => {
        window.axios = { post: vi.fn() };

        const wrapper = mountSummary([
            {
                policy_hex: USDM_POLICY,
                asset_hex: USDM_HEX,
                asset_name: "(333)USDM",
                assets: 1,
                quantity: "251000000",
                meta: { ticker: "USDM", name: "USDM", decimals: 6, logo: null },
            },
            {
                policy_hex: USDM_POLICY.replace("c4", "d4"),
                asset_hex: MASCOT_HEX,
                asset_name: "Mascot",
                assets: 1,
                quantity: "314159",
                meta: null,
            },
        ]);
        await flushPromises();

        expect(window.axios.post).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain("251000000");
        expect(wrapper.text()).toContain("Mascot");
    });

    // A policy minting a serial per recipient has no single asset to look up.
    it("looks nothing up for a policy holding several assets", async () => {
        window.axios = { post: vi.fn() };

        mountSummary([
            {
                policy_hex: USDM_POLICY,
                asset_hex: null,
                asset_name: null,
                assets: 12,
                quantity: "12",
            },
        ]);
        await flushPromises();

        expect(window.axios.post).not.toHaveBeenCalled();
    });
});
