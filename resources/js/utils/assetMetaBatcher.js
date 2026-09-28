// Collects asset metadata requests made in the same tick and sends them to the
// known-assets lookup in batches, one batch at a time.
//
// A campaign that gives a distinct NFT to each code has hundreds of reward assets. Asking
// for each in its own request, all at once, held one server worker per asset until the
// host stopped answering anybody. Sending them in sequence keeps a page to one request in
// flight however many assets it shows.

export const BATCH_SIZE = 50;

/**
 * @param {(assets: Array<{policy: string, asset_name: string}>) => Promise<Object>} fetchBatch
 *        resolves to an object keyed by policy + asset name hex
 * @param {(asset: {policy: string, asset_name: string}, meta: Object|null, failed: boolean) => void} onResult
 *        called once per asset; meta is null when the asset is unknown or the batch failed,
 *        and failed says which
 */
export function createAssetMetaBatcher(
    fetchBatch,
    onResult,
    batchSize = BATCH_SIZE,
) {
    let pending = [];
    let scheduled = false;
    let chain = Promise.resolve();

    // A listener that throws must not strand the rest of its batch or fail the next one.
    function answer(asset, meta, failed) {
        try {
            onResult(asset, meta, failed);
        } catch (error) {
            console.error(error);
        }
    }

    function flush() {
        scheduled = false;
        const assets = pending;
        pending = [];

        for (let i = 0; i < assets.length; i += batchSize) {
            const chunk = assets.slice(i, i + batchSize);
            chain = chain
                .then(() => fetchBatch(chunk))
                .then(
                    (found) =>
                        chunk.forEach((asset) =>
                            answer(
                                asset,
                                found?.[
                                    (
                                        asset.policy + asset.asset_name
                                    ).toLowerCase()
                                ] ?? null,
                                false,
                            ),
                        ),
                    () => chunk.forEach((asset) => answer(asset, null, true)),
                );
        }
    }

    return {
        request(policy, assetName) {
            pending.push({ policy, asset_name: assetName ?? "" });
            if (!scheduled) {
                scheduled = true;
                queueMicrotask(flush);
            }
        },
        // Settles once every batch requested so far has answered. For tests.
        settled: () =>
            new Promise((resolve) => queueMicrotask(resolve)).then(() => chain),
    };
}

const shared = new Map();

/**
 * The one batcher for `network` on this page, shared by every component that asks, so
 * the campaign page and its costs tab queue behind each other rather than each keeping a
 * request in flight. Each asset is asked about once per page load: a later request for it
 * is answered from what came back, or joins the answer still on its way. Call dispose()
 * when the component unmounts.
 */
export function knownAssetBatcher(network, onResult) {
    let entry = shared.get(network);
    if (!entry) {
        entry = {};
        shared.set(network, entry);
        const listeners = new Set();
        const answers = new Map();
        const batcher = createAssetMetaBatcher(
            (assets) =>
                window.axios
                    .post(route("known-assets.lookup-many"), {
                        network,
                        assets,
                    })
                    .then(({ data }) => data),
            (asset, meta, failed) => {
                const subject = asset.policy + asset.asset_name;
                entry.pending.delete(subject);
                // A failed batch (a 429, a timeout) is not an answer: the next page to
                // want the asset asks again.
                if (!failed) {
                    entry.answers.set(subject, meta);
                }
                listeners.forEach((listener) => {
                    try {
                        listener(asset, meta);
                    } catch (error) {
                        console.error(error);
                    }
                });
            },
        );
        entry.listeners = listeners;
        entry.answers = answers;
        entry.pending = new Set();
        entry.batcher = batcher;
    }
    entry.listeners.add(onResult);

    return {
        request(policy, assetName) {
            const asset = {
                policy: String(policy).toLowerCase(),
                asset_name: String(assetName ?? "").toLowerCase(),
            };
            const subject = asset.policy + asset.asset_name;
            if (entry.answers.has(subject)) {
                const meta = entry.answers.get(subject);
                queueMicrotask(() => onResult(asset, meta));
                return;
            }
            if (entry.pending.has(subject)) {
                return; // Every listener hears the answer when it arrives.
            }
            entry.pending.add(subject);
            entry.batcher.request(asset.policy, asset.asset_name);
        },
        settled: entry.batcher.settled,
        dispose() {
            entry.listeners.delete(onResult);
        },
    };
}

/** Forgets the shared batchers. For tests, which mount many pages in one process. */
export function resetKnownAssetBatchers() {
    shared.clear();
}
