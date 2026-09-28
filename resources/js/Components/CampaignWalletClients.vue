<script setup>
import { computed } from 'vue';

const props = defineProps({
    walletClients: {
        type: Object,
        default: () => ({ state: 'too_few', floor: 10, clients: null }),
    },
    endDate: {
        type: String,
        default: null,
    },
    // Rendered inside the analytics card rather than as a panel of its own, so the
    // frame and the title come from the tab around it.
    embedded: { type: Boolean, default: false },
});

// Vuetify has no stacked bar, so the bar itself is plain elements. The colours are
// theme background utilities rather than literals, so the segments follow the light and
// dark themes the way every other surface on this page does.
const SEGMENT_COLOURS = [
    'bg-primary',
    'bg-warning',
    'bg-success',
    'bg-info',
    'bg-secondary',
];

// A client that maps to no wallet is always the muted segment, wherever it sorts, so a
// reader is never invited to read it as another wallet brand.
const UNRECOGNISED_COLOUR = 'bg-surface-variant';

const state = computed(() => props.walletClients?.state ?? 'too_few');
const floor = computed(() => props.walletClients?.floor ?? 10);
const hasBreakdown = computed(() => state.value === 'ready' || state.value === 'early');

const rows = computed(() =>
    (props.walletClients?.clients ?? []).map((row, index) => ({
        ...row,
        label: row.wallet ?? 'Not recognised',
        colour: row.wallet === null ? UNRECOGNISED_COLOUR : SEGMENT_COLOURS[index % SEGMENT_COLOURS.length],
    })),
);

// The bar is one image to a screen reader rather than a row of unlabelled boxes.
const barLabel = computed(
    () => 'Share of claims by wallet: ' + rows.value.map((r) => `${r.label} ${r.share} percent`).join(', '),
);
</script>

<template>
    <v-card rounded="lg" :elevation="embedded ? 0 : 1" :class="embedded ? '' : 'mb-6'" :flat="embedded">
        <v-toolbar v-if="!embedded" color="surface-variant" class="flex-wrap">
            <v-toolbar-title>Wallets used</v-toolbar-title>
        </v-toolbar>

        <v-card-text :class="embedded ? 'pt-0' : ''">
            <v-alert
                v-if="state === 'running'"
                type="info"
                variant="tonal"
                density="comfortable"
            >
                The wallet breakdown appears once this campaign closes<span v-if="endDate"> on {{ endDate }}</span>.
                While claims are still arriving, a breakdown that updated with each one
                would say which wallet each claimant used, and this page shows who
                claimed.
            </v-alert>

            <v-alert
                v-else-if="state === 'too_few'"
                type="info"
                variant="tonal"
                density="comfortable"
            >
                A breakdown appears once at least {{ floor }} claims have been recorded.
                Below that it would name the wallet a handful of identifiable people used.
            </v-alert>

            <template v-else-if="hasBreakdown">
                <v-alert
                    v-if="state === 'early'"
                    type="warning"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                >
                    Shown to you as an administrator before this campaign closes. The
                    campaign's own operator does not see this until it has.
                </v-alert>

                <p class="text-body-2 text-medium-emphasis mb-3">
                    The share of claims that arrived from each wallet. The wallet is
                    inferred from the client software the claim was made with, so it is a
                    best guess rather than a signature, and nothing here is tied to an
                    individual claim.
                </p>

                <div
                    class="d-flex rounded overflow-hidden mb-4"
                    style="height: 28px"
                    role="img"
                    :aria-label="barLabel"
                >
                    <div
                        v-for="row in rows"
                        :key="row.client"
                        :class="row.colour"
                        :style="{ width: row.share + '%' }"
                        :title="`${row.label}: ${row.share}%`"
                    />
                </div>

                <div class="d-flex flex-wrap ga-4">
                    <div
                        v-for="row in rows"
                        :key="row.client"
                        class="d-flex align-center ga-2"
                    >
                        <div
                            :class="row.colour"
                            class="rounded"
                            style="width: 12px; height: 12px"
                        />
                        <span class="text-body-2">{{ row.label }}</span>
                        <span class="text-body-2 text-medium-emphasis">
                            {{ row.share }}%
                        </span>
                    </div>
                </div>
            </template>
        </v-card-text>
    </v-card>
</template>
