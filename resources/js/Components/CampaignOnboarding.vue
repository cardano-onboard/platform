<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    campaign: { type: Object, required: true },
    onboarding: { type: Object, default: () => ({}) },
});

// Collapsed by default, like the performance strip above it: the headline numbers
// live in the summary row, and the per-wallet table is detail you ask for.
const expanded = ref(false);
const starting = ref(false);

const summary = computed(() => props.onboarding?.summary ?? null);
const status = computed(() => props.onboarding?.status ?? null);
const wallets = computed(() => props.onboarding?.wallets ?? []);
const eligibleClaims = computed(() => props.onboarding?.eligible_claims ?? 0);

const isRunning = computed(() => ['pending', 'running'].includes(status.value));
const hasResults = computed(() => status.value === 'complete' && summary.value);
const hasFailed = computed(() => status.value === 'failed');

const completedAt = computed(() => {
    if (!props.onboarding?.completed_at) return null;
    return new Date(props.onboarding.completed_at).toLocaleString();
});

// Headline tiles. Activation is deliberately shown as a share of NEW wallets rather
// than of everyone: an established wallet transacting again says nothing about whether
// this campaign onboarded anybody.
const tiles = computed(() => {
    if (!summary.value) return [];
    const s = summary.value;
    return [
        {
            label: 'Claimant wallets',
            value: s.genuine_wallets,
            hint: s.operator_wallets > 0 ? `${s.operator_wallets} operator wallet(s) excluded` : 'Distinct wallets that claimed',
            color: 'primary',
        },
        {
            label: 'Newly onboarded',
            value: `${s.new_pct}%`,
            hint: `${s.new_wallets} wallet(s) had no prior on-chain history`,
            color: 'success',
        },
        {
            label: 'New wallets activated',
            value: `${s.new_activated_pct}%`,
            hint: `${s.new_activated} of ${s.new_wallets} later sent a transaction of their own`,
            color: 'info',
        },
        {
            label: 'Delegated to a pool',
            value: `${s.delegated_pct}%`,
            // Both denominators, because they answer different questions and are easy to
            // confuse: established claimants often already delegated before your campaign
            // existed, so the all-claimants figure can look like an effect you produced.
            hint: `${s.delegated} of ${s.genuine_wallets} claimants; ${s.new_delegated} of ${s.new_wallets} new wallet(s)`,
            color: 'secondary',
        },
    ];
});

function analyze() {
    starting.value = true;
    router.post(route('campaigns.analyze-onboarding', props.campaign.id), {}, {
        preserveScroll: true,
        onFinish: () => (starting.value = false),
    });
}

function exportClaims() {
    window.location = route('campaigns.export-claims', props.campaign.id);
}
</script>

<template>
    <v-card class="mb-4" rounded="lg" border>
        <v-toolbar color="transparent" density="comfortable">
            <v-icon class="ms-4 me-2" icon="mdi-account-multiple-check-outline"></v-icon>
            <v-toolbar-title class="text-subtitle-1 font-weight-medium">Onboarding</v-toolbar-title>
            <v-spacer></v-spacer>

            <v-btn
                v-if="eligibleClaims > 0"
                variant="tonal"
                color="secondary"
                size="small"
                class="me-2"
                prepend-icon="mdi-file-delimited-outline"
                @click="exportClaims"
            >
                Export claims
                <v-tooltip activator="parent" location="top">
                    Download every claimed address as CSV, with its onboarding classification
                </v-tooltip>
            </v-btn>

            <v-btn
                v-if="eligibleClaims > 0"
                variant="tonal"
                color="primary"
                size="small"
                class="me-2"
                :loading="starting || isRunning"
                prepend-icon="mdi-chart-timeline-variant"
                @click="analyze"
            >
                {{ hasResults ? 'Re-run' : 'Analyze' }}
            </v-btn>

            <v-btn
                v-if="hasResults"
                :icon="expanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                variant="text"
                class="me-2"
                @click="expanded = !expanded"
            ></v-btn>
        </v-toolbar>

        <v-card-text v-if="eligibleClaims === 0" class="text-medium-emphasis pt-0">
            Onboarding analysis becomes available once this campaign has confirmed claims on
            chain. It reads public chain data to tell you how many claimants were new wallets
            and how many went on to transact for themselves.
        </v-card-text>

        <v-card-text v-else-if="isRunning" class="pt-0">
            <v-progress-linear indeterminate color="primary" class="mb-3" rounded></v-progress-linear>
            <span class="text-medium-emphasis">
                Reading chain history for {{ eligibleClaims }} claim(s). This makes about one
                query per wallet, so it can take a few minutes. Results appear here when it
                finishes — you can leave the page.
            </span>
        </v-card-text>

        <v-card-text v-else-if="hasFailed" class="pt-0">
            <v-alert type="warning" variant="tonal" density="comfortable">
                The last analysis did not finish. {{ onboarding.error }}
            </v-alert>
        </v-card-text>

        <v-card-text v-else-if="!hasResults" class="text-medium-emphasis pt-0">
            This campaign has {{ eligibleClaims }} confirmed claim(s). Run the analysis to see
            how many of those wallets were new to Cardano, and how many have since transacted
            on their own.
        </v-card-text>

        <template v-else>
            <v-card-text class="pt-0">
                <v-row dense>
                    <v-col v-for="tile in tiles" :key="tile.label" cols="12" sm="6" md="3">
                        <v-card variant="tonal" :color="tile.color" rounded="lg" class="h-100">
                            <v-card-item>
                                <div class="text-h5 font-weight-medium">{{ tile.value }}</div>
                                <div class="text-caption font-weight-medium">{{ tile.label }}</div>
                                <div class="text-caption text-medium-emphasis mt-1">{{ tile.hint }}</div>
                            </v-card-item>
                        </v-card>
                    </v-col>
                </v-row>

                <!-- The window is not a footnote. An activation rate without the time it was
                     measured over is not a number anyone can act on. -->
                <div class="text-caption text-medium-emphasis mt-3">
                    Measured over roughly {{ summary.observation_days_avg }} day(s) since claim on
                    average<span v-if="completedAt">, analyzed {{ completedAt }}</span>.
                    A wallet counts as activated only when it sends a transaction itself, not when
                    it receives one.
                </div>
            </v-card-text>

            <v-expand-transition>
                <div v-if="expanded">
                    <v-divider></v-divider>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>Wallet</th>
                                <th>Classification</th>
                                <th>Activated</th>
                                <th>Delegated</th>
                                <th class="text-right">Prior txs</th>
                                <th class="text-right">Own txs since</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="wallet in wallets" :key="wallet.stake_key">
                                <td class="text-caption font-monospace">
                                    {{ wallet.stake_key_short }}
                                    <v-chip v-if="wallet.is_operator" size="x-small" class="ms-1" variant="tonal">
                                        operator
                                    </v-chip>
                                </td>
                                <td>
                                    <v-chip
                                        :color="wallet.is_new ? 'success' : 'default'"
                                        size="small"
                                        variant="tonal"
                                    >
                                        {{ wallet.is_new ? 'new' : 'established' }}
                                    </v-chip>
                                </td>
                                <td>
                                    <v-icon
                                        :icon="wallet.activated ? 'mdi-check-circle' : 'mdi-minus'"
                                        :color="wallet.activated ? 'info' : 'disabled'"
                                        size="small"
                                    ></v-icon>
                                </td>
                                <td>
                                    <v-icon
                                        :icon="wallet.delegated ? 'mdi-check-circle' : 'mdi-minus'"
                                        :color="wallet.delegated ? 'secondary' : 'disabled'"
                                        size="small"
                                    ></v-icon>
                                </td>
                                <td class="text-right">{{ wallet.prior_tx_count }}</td>
                                <td class="text-right">{{ wallet.self_initiated_count }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </div>
            </v-expand-transition>
        </template>
    </v-card>
</template>
