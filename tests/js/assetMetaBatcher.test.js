import { describe, it, expect, vi } from "vitest";
import {
    createAssetMetaBatcher,
    knownAssetBatcher,
} from "../../resources/js/utils/assetMetaBatcher.js";

const POLICY = "ab".repeat(28);
const nameHex = (i) => i.toString(16).padStart(4, "0");

describe("createAssetMetaBatcher", () => {
    // The case that took the host down: a distinct NFT per code, hundreds on one page.
    it("sends hundreds of requests as batches of fifty, one batch in flight at a time", async () => {
        let inFlight = 0;
        let most = 0;
        const fetchBatch = vi.fn(async (assets) => {
            inFlight += 1;
            most = Math.max(most, inFlight);
            await new Promise((resolve) => setTimeout(resolve, 1));
            inFlight -= 1;
            return Object.fromEntries(
                assets.map((a) => [
                    a.policy + a.asset_name,
                    { ticker: a.asset_name },
                ]),
            );
        });
        const results = new Map();
        const batcher = createAssetMetaBatcher(fetchBatch, (asset, meta) =>
            results.set(asset.asset_name, meta),
        );

        for (let i = 0; i < 120; i++) {
            batcher.request(POLICY, nameHex(i));
        }
        await batcher.settled();

        expect(fetchBatch).toHaveBeenCalledTimes(3);
        expect(fetchBatch.mock.calls.map(([assets]) => assets.length)).toEqual([
            50, 50, 20,
        ]);
        expect(most).toBe(1);
        expect(results.size).toBe(120);
        expect(results.get(nameHex(119))).toEqual({ ticker: nameHex(119) });
    });

    it("answers every asset of a failed batch with null and carries on with the next", async () => {
        const fetchBatch = vi
            .fn()
            .mockRejectedValueOnce(new Error("504"))
            .mockResolvedValueOnce({ [POLICY + nameHex(1)]: { ticker: "B" } });
        const onResult = vi.fn();
        const batcher = createAssetMetaBatcher(fetchBatch, onResult, 1);

        batcher.request(POLICY, nameHex(0));
        batcher.request(POLICY, nameHex(1));
        await batcher.settled();

        expect(onResult).toHaveBeenCalledWith(
            { policy: POLICY, asset_name: nameHex(0) },
            null,
            true,
        );
        expect(onResult).toHaveBeenCalledWith(
            { policy: POLICY, asset_name: nameHex(1) },
            { ticker: "B" },
            false,
        );
    });

    it("matches an answer keyed in lowercase to a request made in uppercase", async () => {
        const upper = POLICY.toUpperCase();
        const onResult = vi.fn();
        const batcher = createAssetMetaBatcher(
            async () => ({ [POLICY + "abcd"]: { ticker: "X" } }),
            onResult,
        );

        batcher.request(upper, "ABCD");
        await batcher.settled();

        expect(onResult).toHaveBeenCalledWith(
            { policy: upper, asset_name: "ABCD" },
            { ticker: "X" },
            false,
        );
    });

    // The campaign page and its costs tab share one queue: one request, both answered,
    // and an asset asked for again later is answered without another request.
    it("shares one queue per network between components and asks about each asset once", async () => {
        window.axios = {
            post: vi.fn(async (url, { assets }) => ({
                data: Object.fromEntries(
                    assets.map((a) => [
                        a.policy + a.asset_name,
                        { ticker: "T" },
                    ]),
                ),
            })),
        };
        const page = vi.fn();
        const costs = vi.fn();
        const pageBatcher = knownAssetBatcher("mainnet", page);
        const costsBatcher = knownAssetBatcher("mainnet", costs);

        pageBatcher.request(POLICY, "AB");
        costsBatcher.request(POLICY, "ab");
        await pageBatcher.settled();

        expect(window.axios.post).toHaveBeenCalledTimes(1);
        expect(window.axios.post.mock.calls[0][1].assets).toEqual([
            { policy: POLICY, asset_name: "ab" },
        ]);
        expect(costs).toHaveBeenCalledWith(
            { policy: POLICY, asset_name: "ab" },
            { ticker: "T" },
        );

        const later = vi.fn();
        knownAssetBatcher("mainnet", later).request(POLICY, "ab");
        await new Promise((resolve) => queueMicrotask(resolve));

        expect(window.axios.post).toHaveBeenCalledTimes(1);
        expect(later).toHaveBeenCalledWith(
            { policy: POLICY, asset_name: "ab" },
            { ticker: "T" },
        );
        delete window.axios;
    });

    // A 429 or a timeout is not an answer: the next page to want the asset asks again.
    it("asks again for an asset whose batch failed", async () => {
        window.axios = {
            post: vi
                .fn()
                .mockRejectedValueOnce(new Error("429"))
                .mockResolvedValueOnce({
                    data: { [POLICY + "ab"]: { ticker: "T" } },
                }),
        };
        const first = vi.fn();
        const firstBatcher = knownAssetBatcher("mainnet", first);
        firstBatcher.request(POLICY, "ab");
        await firstBatcher.settled();
        expect(first).toHaveBeenCalledWith(
            { policy: POLICY, asset_name: "ab" },
            null,
        );

        const second = vi.fn();
        const secondBatcher = knownAssetBatcher("mainnet", second);
        secondBatcher.request(POLICY, "ab");
        await secondBatcher.settled();

        expect(window.axios.post).toHaveBeenCalledTimes(2);
        expect(second).toHaveBeenCalledWith(
            { policy: POLICY, asset_name: "ab" },
            { ticker: "T" },
        );
        delete window.axios;
    });

    it("answers the rest of a batch when one listener throws", async () => {
        const onResult = vi.fn((asset) => {
            if (asset.asset_name === "aa") {
                throw new Error("boom");
            }
        });
        const errors = vi.spyOn(console, "error").mockImplementation(() => {});
        const batcher = createAssetMetaBatcher(async () => ({}), onResult, 2);

        ["aa", "bb", "cc"].forEach((name) => batcher.request(POLICY, name));
        await batcher.settled();

        expect(
            onResult.mock.calls.map(([asset, , failed]) => [
                asset.asset_name,
                failed,
            ]),
        ).toEqual([
            ["aa", false],
            ["bb", false],
            ["cc", false],
        ]);
        errors.mockRestore();
    });
});
