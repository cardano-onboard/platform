<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import { fromBaseUnits } from "@/utils/knownAssets.js";
import { knownAssetBatcher } from "@/utils/assetMetaBatcher.js";

const props = defineProps({
    campaign: { type: Object, required: true },
    costs: {
        type: Object,
        default: () => ({
            claims: 0,
            claims_unrecorded: 0,
            platform_fee_lovelace: null,
            network_fee_lovelace: 0,
            reward_lovelace: 0,
            policies: [],
            distinct_assets: 0,
        }),
    },
    embedded: { type: Boolean, default: false },
});

function ada(lovelace) {
    return (Number(lovelace ?? 0) / 1_000_000).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    });
}

// The platform fee is absent rather than zero where this deployment does not charge.
// Somebody running campaigns for their own project pays nothing, and a line reading zero
// invites the question of what it would otherwise have been.
const rows = computed(() => {
    const out = [];

    if (props.costs.platform_fee_lovelace !== null) {
        out.push({
            label: "Platform fees",
            value: props.costs.platform_fee_lovelace,
            hint: "What running this campaign cost you in fees to Onboard.Ninja.",
        });
    }

    out.push(
        {
            label: "Network fees",
            value: props.costs.network_fee_lovelace,
            hint: "Paid to the Cardano network, not to anybody here.",
        },
        {
            label: "Rewards given away",
            value: props.costs.reward_lovelace,
            hint: "₳ handed to claimants. Native assets are listed below and are not counted here.",
        },
    );

    return out;
});

const total = computed(() =>
    rows.value.reduce((running, row) => running + Number(row.value ?? 0), 0),
);

const policies = computed(() => props.costs.policies ?? []);

// Ticker, decimals and logo for a policy that gave away one asset, from the same lookup
// the campaign page uses for reward tokens. Without the decimals a fungible token's
// quantity is its base units: 251 USDM reads as 251000000.
const tokenMeta = ref({});

function subject(policy) {
    return `${policy.policy_hex}${policy.asset_hex}`.toLowerCase();
}

const batcher = knownAssetBatcher(props.campaign.network, (asset, data) => {
    if (data) {
        tokenMeta.value[`${asset.policy}${asset.asset_name}`.toLowerCase()] =
            data;
    }
    // Not found on chain, or the lookup failed: the row keeps its decoded name and raw
    // quantity.
});

function resolve(policy) {
    if (
        policy.asset_hex == null ||
        tokenMeta.value[subject(policy)] !== undefined
    ) {
        return;
    }
    // The server sends what it already has cached; only a row without it is looked up.
    if ("meta" in policy) {
        tokenMeta.value[subject(policy)] = policy.meta ?? null;
        return;
    }
    tokenMeta.value[subject(policy)] = null;
    batcher.request(policy.policy_hex, policy.asset_hex);
}

watch(policies, (list) => list.forEach(resolve), { immediate: true });

onUnmounted(() => batcher.dispose());

function metaFor(policy) {
    return policy.asset_hex == null ? null : tokenMeta.value[subject(policy)];
}

function displayName(policy) {
    const meta = metaFor(policy);
    // A token with no registry entry still comes back from the lookup, named from the
    // chain's own reading of its bytes. The name decoded here is kept over that one.
    return meta?.ticker || policy.asset_name || meta?.name;
}

function displayQuantity(policy) {
    return fromBaseUnits(policy.quantity, metaFor(policy)?.decimals || 0);
}

// One policy minting a serial per recipient is one row here and a thousand in the export.
const collapsed = computed(() =>
    policies.value.some((policy) => policy.assets > 1),
);

const exportUrl = computed(() =>
    route("campaigns.export-costs", props.campaign.id),
);
</script>

<template>
    <v-card
        rounded="lg"
        :elevation="embedded ? 0 : 1"
        :class="embedded ? '' : 'mb-6'"
        :flat="embedded"
    >
        <v-toolbar v-if="!embedded" color="surface-variant" class="flex-wrap">
            <v-toolbar-title>What this campaign cost</v-toolbar-title>
        </v-toolbar>

        <v-card-text :class="embedded ? 'pt-0' : ''">
            <p class="text-body-2 text-medium-emphasis mb-3">
                Read from what each claim recorded at the time it happened, not
                from today's rates, so a claim taken under an earlier rate still
                reports what it was actually charged.
            </p>

            <v-table density="compact">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.label">
                        <td>
                            <div>{{ row.label }}</div>
                            <div class="text-caption text-medium-emphasis">
                                {{ row.hint }}
                            </div>
                        </td>
                        <td class="text-end text-no-wrap">
                            ₳{{ ada(row.value) }}
                        </td>
                    </tr>
                    <tr>
                        <td class="font-weight-bold">
                            Total out of the bucket
                        </td>
                        <td class="text-end font-weight-bold text-no-wrap">
                            ₳{{ ada(total) }}
                        </td>
                    </tr>
                </tbody>
            </v-table>

            <v-alert
                v-if="costs.claims_unrecorded > 0"
                type="info"
                variant="tonal"
                density="compact"
                class="mt-4"
            >
                {{ costs.claims_unrecorded }} of {{ costs.claims }} claims carry
                no recorded cost. Those were taken before this campaign recorded
                one, and they are counted apart rather than as costing nothing.
            </v-alert>

            <div class="d-flex align-center flex-wrap ga-2 mt-6 mb-2">
                <span class="text-body-2 font-weight-medium">
                    Native assets given away
                </span>
                <v-spacer />
                <v-btn
                    v-if="costs.distinct_assets > 0"
                    :href="exportUrl"
                    size="small"
                    variant="tonal"
                    prepend-icon="mdi-file-delimited-outline"
                >
                    Export CSV
                    <v-tooltip activator="parent" location="top">
                        Every asset on its own row, with its policy, name and
                        quantity
                    </v-tooltip>
                </v-btn>
            </div>

            <p v-if="collapsed" class="text-caption text-medium-emphasis mb-2">
                Grouped by policy. A campaign that mints a serial for each
                recipient has one asset per claim, so the individual assets are
                in the export rather than on this page.
            </p>

            <v-table v-if="policies.length" density="compact">
                <thead>
                    <tr>
                        <th>Policy</th>
                        <th class="text-end">Distinct assets</th>
                        <th class="text-end">Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="policy in policies" :key="policy.policy_hex">
                        <td>
                            <div class="d-flex align-center ga-2">
                                <v-avatar
                                    v-if="metaFor(policy)?.logo"
                                    size="24"
                                    rounded="lg"
                                >
                                    <v-img
                                        :src="`data:image/png;base64,${metaFor(policy).logo}`"
                                        :alt="displayName(policy)"
                                    />
                                </v-avatar>
                                <div class="min-w-0">
                                    <div v-if="displayName(policy)">
                                        {{ displayName(policy) }}
                                    </div>
                                    <div
                                        class="text-caption text-medium-emphasis font-mono text-break"
                                    >
                                        {{ policy.policy_hex }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="text-end">{{ policy.assets }}</td>
                        <td class="text-end text-no-wrap">
                            {{ displayQuantity(policy) }}
                            <v-tooltip
                                v-if="metaFor(policy)?.decimals > 0"
                                activator="parent"
                                location="top"
                            >
                                {{ policy.quantity }} base units ({{
                                    metaFor(policy).decimals
                                }}
                                decimals)
                            </v-tooltip>
                        </td>
                    </tr>
                </tbody>
            </v-table>

            <p v-else class="text-body-2 text-medium-emphasis">
                This campaign gave away no native assets.
            </p>
        </v-card-text>
    </v-card>
</template>
