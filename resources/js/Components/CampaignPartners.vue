<script setup>
import { computed } from "vue";

const props = defineProps({
    campaign: { type: Object, required: true },
    // One row per partner plus the unassigned row, and the headline figures over them.
    report: {
        type: Object,
        default: () => ({ rows: [], summary: {} }),
    },
    // Rendered inside the analytics card rather than as a panel of its own, so the
    // frame and the title come from the tab around it.
    embedded: { type: Boolean, default: false },
});

const rows = computed(() => props.report?.rows ?? []);
const summary = computed(() => props.report?.summary ?? {});

// Partners only. The unassigned row is codes nobody was handed, so it is not somebody an
// organiser decides whether to give cards to again.
const partnerRows = computed(() => rows.value.filter((row) => !row.is_unassigned));

// The finding. Named here as well as flagged on the row, because an organiser reading a
// table of five looks at the totals first and the row second.
const producedNothing = computed(() =>
    partnerRows.value.filter((row) => row.produced_nothing),
);

const anyCodes = computed(() => (summary.value.codes ?? 0) > 0);

// Hidden rather than disabled where the route is not registered. The self-hosted build
// ships a reduced route table, and asking Ziggy for a name that is not in it throws,
// which blanks the page rather than dimming a button.
const exportUrl = computed(() =>
    route().has("campaigns.export-partners")
        ? route("campaigns.export-partners", props.campaign.id)
        : null,
);

function rateLabel(row) {
    return row.claim_rate === null ? "No codes issued" : `${row.claim_rate}%`;
}

function kindLabel(row) {
    if (row.is_unassigned) {
        return "Codes generated without a partner";
    }

    return row.kind ?? "";
}
</script>

<template>
    <v-card
        rounded="lg"
        :elevation="embedded ? 0 : 1"
        :class="embedded ? '' : 'mb-6'"
        :flat="embedded"
    >
        <v-toolbar v-if="!embedded" color="surface-variant" class="flex-wrap">
            <v-toolbar-title>Partners</v-toolbar-title>
        </v-toolbar>

        <v-card-text :class="embedded ? 'pt-0' : ''">
            <div class="d-flex align-center flex-wrap ga-2 mb-3">
                <p class="text-body-2 text-medium-emphasis mb-0">
                    How many codes each partner was handed, and how many came back
                    as claims. A partner who produced nothing is a row reading zero
                    rather than a row that is not here.
                </p>
                <v-spacer />
                <v-btn
                    v-if="exportUrl"
                    :href="exportUrl"
                    size="small"
                    variant="tonal"
                    prepend-icon="mdi-file-delimited-outline"
                >
                    Export CSV
                    <v-tooltip activator="parent" location="top">
                        Every row on this table, including the partners who
                        produced nothing
                    </v-tooltip>
                </v-btn>
            </div>

            <v-alert
                v-if="producedNothing.length"
                type="warning"
                variant="tonal"
                density="comfortable"
                class="mb-4"
            >
                {{ producedNothing.length }} of
                {{ summary.partners_with_codes }} partners handed out codes and
                got no claims back:
                {{ producedNothing.map((row) => row.name).join(", ") }}.
            </v-alert>

            <v-alert
                v-else-if="!partnerRows.length"
                type="info"
                variant="tonal"
                density="comfortable"
                class="mb-4"
            >
                No partners have been recorded on this campaign, so every code is
                unassigned. Pick a partner when you generate a batch of codes and
                this table will compare them.
            </v-alert>

            <v-table density="compact">
                <thead>
                    <tr>
                        <th>Partner</th>
                        <th class="text-end">Codes issued</th>
                        <th class="text-end">Codes claimed</th>
                        <th class="text-end">Claims</th>
                        <th class="text-end">Claim rate</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.partner_id ?? 'unassigned'"
                        :class="{ 'text-medium-emphasis': row.is_unassigned }"
                    >
                        <td>
                            <div class="d-flex align-center ga-2">
                                <span>{{ row.name }}</span>
                                <!-- The zero row has to read as a result, so it says
                                     so rather than leaving a reader to spot a nought. -->
                                <v-chip
                                    v-if="row.produced_nothing"
                                    color="warning"
                                    size="x-small"
                                    variant="flat"
                                >
                                    No claims
                                </v-chip>
                                <v-chip
                                    v-if="row.removed"
                                    size="x-small"
                                    variant="tonal"
                                >
                                    Removed
                                </v-chip>
                            </div>
                            <div
                                v-if="kindLabel(row)"
                                class="text-caption text-medium-emphasis"
                            >
                                {{ kindLabel(row) }}
                            </div>
                        </td>
                        <td class="text-end">{{ row.codes }}</td>
                        <td class="text-end">{{ row.codes_claimed }}</td>
                        <td class="text-end">{{ row.claims }}</td>
                        <td class="text-end text-no-wrap">
                            <span
                                :class="
                                    row.claim_rate === null
                                        ? 'text-caption text-medium-emphasis'
                                        : ''
                                "
                            >
                                {{ rateLabel(row) }}
                            </span>
                            <v-progress-linear
                                v-if="row.claim_rate !== null"
                                :model-value="row.claim_rate"
                                :color="
                                    row.produced_nothing ? 'warning' : 'primary'
                                "
                                height="4"
                                rounded
                                class="mt-1"
                            />
                        </td>
                    </tr>
                    <tr v-if="anyCodes" class="font-weight-bold">
                        <td>All codes</td>
                        <td class="text-end">{{ summary.codes }}</td>
                        <td class="text-end">{{ summary.codes_claimed }}</td>
                        <td class="text-end">{{ summary.claims }}</td>
                        <td class="text-end text-no-wrap">
                            {{ summary.claim_rate }}%
                        </td>
                    </tr>
                </tbody>
            </v-table>

            <p class="text-caption text-medium-emphasis mt-3">
                The claim rate is the share of a partner's codes that were claimed
                at least once, so a code allowing several uses counts as one code
                however many claims it takes. Claims counts them all.
            </p>
        </v-card-text>
    </v-card>
</template>
