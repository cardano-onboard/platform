import { describe, it, expect, afterEach } from "vitest";
import { mount } from "@vue/test-utils";
import CampaignPartners from "../../resources/js/Components/CampaignPartners.vue";

// The panel's job is that a partner who produced nothing is visible. A row that is
// filtered out here is as good as one the query never returned, so most of what follows
// is about rows surviving to the screen.
const CAMPAIGN = { id: "01HQ1234567890ABCDEFGHIJ", name: "Summit Booth" };

function row(overrides = {}) {
    return {
        partner_id: "01HQPARTNER0000000000001",
        name: "Booth East",
        kind: "staff",
        is_unassigned: false,
        removed: false,
        codes: 100,
        codes_claimed: 40,
        claims: 40,
        claim_rate: 40,
        produced_nothing: false,
        ...overrides,
    };
}

// Five partners of a hundred cards each, one of which produced nothing, plus the codes
// nobody was assigned.
function fiveCardPartners() {
    return {
        rows: [
            row({ partner_id: "p1", name: "Booth East", codes_claimed: 61, claims: 61, claim_rate: 61 }),
            row({ partner_id: "p2", name: "Booth West", codes_claimed: 38, claims: 38, claim_rate: 38 }),
            row({ partner_id: "p3", name: "Meetup Host", codes_claimed: 12, claims: 12, claim_rate: 12 }),
            row({ partner_id: "p4", name: "Newsletter", codes_claimed: 4, claims: 4, claim_rate: 4 }),
            row({
                partner_id: "p5",
                name: "Print Run",
                kind: "print",
                codes_claimed: 0,
                claims: 0,
                claim_rate: 0,
                produced_nothing: true,
            }),
            row({
                partner_id: null,
                name: "Unassigned",
                kind: null,
                is_unassigned: true,
                codes: 20,
                codes_claimed: 9,
                claims: 9,
                claim_rate: 45,
            }),
        ],
        summary: {
            partners: 5,
            partners_with_codes: 5,
            partners_producing_nothing: 1,
            codes: 520,
            codes_claimed: 124,
            claims: 124,
            claim_rate: 23.8,
            unassigned_codes: 20,
            unassigned_claims: 9,
        },
    };
}

function mountPanel(report, props = {}) {
    return mount(CampaignPartners, {
        props: { campaign: CAMPAIGN, report, embedded: true, ...props },
    });
}

function bodyRows(wrapper) {
    return wrapper.findAll("tbody tr");
}

afterEach(() => {
    globalThis.setAvailableRoutes(null);
});

describe("Campaign partners panel", () => {
    it("shows a partner who produced nothing as a row rather than leaving it out", () => {
        const wrapper = mountPanel(fiveCardPartners());
        const text = wrapper.text();

        expect(text).toContain("Print Run");
        expect(text).toContain("No claims");
        // Six data rows plus the totals row. Nothing was filtered on the way in.
        expect(bodyRows(wrapper)).toHaveLength(7);
    });

    it("names the partners who produced nothing above the table", () => {
        const wrapper = mountPanel(fiveCardPartners());

        expect(wrapper.text()).toContain("1 of 5 partners handed out codes");
        expect(wrapper.text()).toContain("Print Run");
    });

    it("keeps every partner's own figures on its own row", () => {
        const cells = bodyRows(mountPanel(fiveCardPartners()))[4]
            .findAll("td")
            .map((cell) => cell.text());

        expect(cells[0]).toContain("Print Run");
        expect(cells[1]).toBe("100");
        expect(cells[2]).toBe("0");
        expect(cells[3]).toBe("0");
        expect(cells[4]).toContain("0%");
    });

    // A partner with no codes was not measured. Reporting nought per cent would put them
    // beside the partner whose hundred cards produced nothing, and those call for
    // opposite responses.
    it("says no codes were issued rather than showing a rate of zero", () => {
        const wrapper = mountPanel({
            rows: [
                row({ name: "Never Used", codes: 0, codes_claimed: 0, claims: 0, claim_rate: null }),
                row({ partner_id: null, name: "Unassigned", is_unassigned: true, kind: null, codes: 0, codes_claimed: 0, claims: 0, claim_rate: null }),
            ],
            summary: { partners: 1, partners_with_codes: 0, partners_producing_nothing: 0, codes: 0, codes_claimed: 0, claims: 0, claim_rate: null },
        });

        expect(wrapper.text()).toContain("No codes issued");
        expect(wrapper.text()).not.toContain("0%");
    });

    it("carries the unassigned codes as their own row", () => {
        const wrapper = mountPanel(fiveCardPartners());
        const unassigned = bodyRows(wrapper)[5].findAll("td").map((cell) => cell.text());

        expect(unassigned[0]).toContain("Unassigned");
        expect(unassigned[0]).toContain("Codes generated without a partner");
        expect(unassigned[1]).toBe("20");
    });

    it("counts only partners in the finding, not the unassigned codes", () => {
        const report = fiveCardPartners();
        report.rows[5].claims = 0;
        report.rows[5].codes_claimed = 0;
        report.rows[5].claim_rate = 0;
        report.rows[5].produced_nothing = true;

        const wrapper = mountPanel(report);

        // The alert still speaks for the one partner. The unassigned row carries its own
        // chip, because those codes did produce nothing, but nobody was handed them.
        expect(wrapper.text()).toContain("1 of 5 partners handed out codes");
    });

    it("marks a partner that has since been removed from the picker", () => {
        const report = fiveCardPartners();
        report.rows[2].removed = true;

        expect(mountPanel(report).text()).toContain("Removed");
    });

    it("tells an operator with no partners why the table has one row", () => {
        const wrapper = mountPanel({
            rows: [
                row({ partner_id: null, name: "Unassigned", is_unassigned: true, kind: null, codes: 40, codes_claimed: 11, claims: 11, claim_rate: 27.5 }),
            ],
            summary: { partners: 0, partners_with_codes: 0, partners_producing_nothing: 0, codes: 40, codes_claimed: 11, claims: 11, claim_rate: 27.5 },
        });

        expect(wrapper.text()).toContain("No partners have been recorded");
    });

    it("offers the export where the route exists", () => {
        const link = mountPanel(fiveCardPartners()).find("a[href*='export-partners']");

        expect(link.exists()).toBe(true);
        expect(link.text()).toContain("Export CSV");
    });

    // The self-hosted build ships a reduced route table. Asking Ziggy for a name that is
    // not in it throws, which blanks the page rather than dimming one button, so the
    // control is hidden instead of rendered against a route that is not there.
    it("hides the export where the route is not registered", () => {
        globalThis.setAvailableRoutes(["campaigns.show"]);

        const wrapper = mountPanel(fiveCardPartners());

        expect(wrapper.text()).not.toContain("Export CSV");
        expect(wrapper.text()).toContain("Print Run");
    });

    it("renders without a report rather than failing on an empty prop", () => {
        const wrapper = mount(CampaignPartners, { props: { campaign: CAMPAIGN } });

        expect(wrapper.text()).toContain("No partners have been recorded");
        expect(bodyRows(wrapper)).toHaveLength(0);
    });
});
