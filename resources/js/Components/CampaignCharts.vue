<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    campaign: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
});

// Analytics strip is collapsed by default so it doesn't dominate the page; the
// header summary still carries the headline numbers when collapsed.
const expanded = ref(false);
// Full-screen timeline detail (the small sparkline is a visual indicator only).
const showTimeline = ref(false);

const claimsOverTime = computed(() => props.stats?.claims_over_time ?? []);
const hasTimeline = computed(() => claimsOverTime.value.length > 0);
const cumulativeSeries = computed(() => claimsOverTime.value.map((p) => p.cumulative));
const timelineLabels = computed(() => claimsOverTime.value.map((p) => p.date));
const totalClaims = computed(() => claimsOverTime.value.reduce((sum, p) => sum + p.count, 0));

// v-sparkline has no axes, so the full-screen dialog uses a small hand-rolled SVG
// line chart (no new dependency) with a real y-axis: gridlines + tick labels on a
// "nice" 0-based scale, and the date range anchored on the x-axis.
const firstDate = computed(() => timelineLabels.value[0] ?? null);
const lastDate = computed(() => timelineLabels.value[timelineLabels.value.length - 1] ?? null);

// Round an axis step to a human-friendly 1/2/5 x 10^n value.
function niceStep(range, targetTicks = 4) {
    const raw = Math.max(range, 1) / targetTicks;
    const pow = Math.pow(10, Math.floor(Math.log10(raw)));
    const n = raw / pow;
    const factor = n < 1.5 ? 1 : n < 3 ? 2 : n < 7 ? 5 : 10;
    return factor * pow;
}

const chart = computed(() => {
    const data = cumulativeSeries.value;
    const n = data.length;
    const W = 600, H = 260, mL = 44, mR = 16, mT = 16, mB = 28;
    const plotW = W - mL - mR;
    const plotH = H - mT - mB;

    const max = data.length ? Math.max(...data) : 0;
    const step = niceStep(max);
    const yMax = Math.max(step, Math.ceil(max / step) * step);

    const xAt = (i) => (n <= 1 ? mL + plotW / 2 : mL + (i / (n - 1)) * plotW);
    const yAt = (v) => mT + plotH - (v / yMax) * plotH;

    const points = data.map((v, i) => `${xAt(i).toFixed(1)},${yAt(v).toFixed(1)}`).join(' ');

    const ticks = [];
    for (let t = 0; t <= yMax + step / 1000; t += step) {
        ticks.push({ value: t, y: yAt(t) });
    }

    return { W, H, mL, mR, mT, mB, points, ticks };
});

const slots = computed(() => props.stats?.claimed_vs_unclaimed ?? { claimed: 0, unclaimed: 0 });
const totalSlots = computed(() => slots.value.claimed + slots.value.unclaimed);
const claimedPct = computed(() =>
    totalSlots.value > 0 ? Math.round((slots.value.claimed / totalSlots.value) * 100) : 0,
);

const utilization = computed(
    () => props.stats?.code_utilization ?? { total: 0, claimed: 0, unclaimed: 0, available: 0, exhausted: 0 },
);

const summaryLine = computed(() => {
    const claims = `${totalClaims.value} claim${totalClaims.value === 1 ? '' : 's'}`;
    const codes = `${utilization.value.total} code${utilization.value.total === 1 ? '' : 's'}`;
    return `${claims} · ${codes}`;
});
</script>

<template>
    <v-card variant="outlined" rounded="lg" class="mb-4">
        <v-card-item class="cursor-pointer" @click="expanded = !expanded">
            <template #prepend>
                <v-icon icon="mdi-chart-line" color="primary"></v-icon>
            </template>
            <v-card-title class="text-subtitle-1">Analytics</v-card-title>
            <v-card-subtitle>{{ summaryLine }}</v-card-subtitle>
            <template #append>
                <v-btn :icon="expanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                       variant="text" size="small" :aria-label="expanded ? 'Collapse analytics' : 'Expand analytics'">
                    <v-icon :icon="expanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"></v-icon>
                </v-btn>
            </template>
        </v-card-item>
        <v-expand-transition>
            <div v-show="expanded">
                <v-divider></v-divider>
                <v-card-text>
                    <v-row>
                        <!-- Claims over time (visual indicator; click to expand for detail) -->
                        <v-col cols="12" md="4">
                            <v-card variant="tonal" height="100%"
                                    :class="{ 'cursor-pointer': hasTimeline }"
                                    @click="hasTimeline && (showTimeline = true)">
                                <v-card-item>
                                    <v-card-title class="text-subtitle-1">Claims Over Time</v-card-title>
                                    <v-card-subtitle>
                                        {{ totalClaims }} total claim{{ totalClaims === 1 ? '' : 's' }}
                                        <span v-if="hasTimeline"> · click to expand</span>
                                    </v-card-subtitle>
                                </v-card-item>
                                <v-card-text>
                                    <v-sparkline
                                        v-if="hasTimeline"
                                        :model-value="cumulativeSeries"
                                        line-width="2"
                                        color="primary"
                                        smooth
                                        auto-draw
                                    />
                                    <p v-else class="text-medium-emphasis text-body-2">No claims recorded yet.</p>
                                </v-card-text>
                            </v-card>
                        </v-col>

                        <!-- Reward slots claimed vs unclaimed -->
                        <v-col cols="12" md="4">
                            <v-card variant="tonal" height="100%">
                                <v-card-item>
                                    <v-card-title class="text-subtitle-1">Reward Slots</v-card-title>
                                    <v-card-subtitle>{{ claimedPct }}% of {{ totalSlots }} slots claimed</v-card-subtitle>
                                </v-card-item>
                                <v-card-text>
                                    <v-progress-linear
                                        :model-value="claimedPct"
                                        color="success"
                                        bg-color="grey-lighten-1"
                                        height="20"
                                        rounded
                                    >
                                        <strong class="text-caption">{{ claimedPct }}%</strong>
                                    </v-progress-linear>
                                    <div class="d-flex justify-space-between mt-3">
                                        <span class="text-body-2">
                                            <v-icon icon="mdi-circle" color="success" size="x-small" /> Claimed: {{ slots.claimed }}
                                        </span>
                                        <span class="text-body-2">
                                            <v-icon icon="mdi-circle" color="grey-lighten-1" size="x-small" /> Unclaimed: {{ slots.unclaimed }}
                                        </span>
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>

                        <!-- Code utilization -->
                        <v-col cols="12" md="4">
                            <v-card variant="tonal" height="100%">
                                <v-card-item>
                                    <v-card-title class="text-subtitle-1">Code Utilization</v-card-title>
                                    <v-card-subtitle>{{ utilization.total }} code{{ utilization.total === 1 ? '' : 's' }}</v-card-subtitle>
                                </v-card-item>
                                <v-card-text>
                                    <div class="d-flex flex-wrap ga-2">
                                        <v-chip color="success" size="small" label>
                                            <v-icon start icon="mdi-check-circle" />Claimed: {{ utilization.claimed }}
                                        </v-chip>
                                        <v-chip color="grey" size="small" label>
                                            <v-icon start icon="mdi-circle-outline" />Unclaimed: {{ utilization.unclaimed }}
                                        </v-chip>
                                        <v-chip color="info" size="small" label>
                                            <v-icon start icon="mdi-ticket-confirmation" />Available: {{ utilization.available }}
                                        </v-chip>
                                        <v-chip color="warning" size="small" label>
                                            <v-icon start icon="mdi-ticket" />Exhausted: {{ utilization.exhausted }}
                                        </v-chip>
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-card-text>
            </div>
        </v-expand-transition>
    </v-card>

    <!-- Full-screen claims timeline with readable labels + per-day detail -->
    <v-dialog v-model="showTimeline" max-width="900" transition="dialog-bottom-transition">
        <v-card>
            <v-toolbar color="primary">
                <v-toolbar-title>Claims Over Time</v-toolbar-title>
                <v-spacer></v-spacer>
                <v-btn icon @click="showTimeline = false">
                    <v-icon icon="mdi-close"></v-icon>
                </v-btn>
            </v-toolbar>
            <v-card-text>
                <div class="text-caption text-medium-emphasis mb-1">Cumulative claims</div>
                <svg :viewBox="`0 0 ${chart.W} ${chart.H}`" width="100%"
                     role="img" aria-label="Cumulative claims over time" style="max-height: 280px">
                    <!-- y-axis gridlines + scale labels -->
                    <g v-for="tick in chart.ticks" :key="tick.value">
                        <line :x1="chart.mL" :x2="chart.W - chart.mR" :y1="tick.y" :y2="tick.y"
                              stroke="currentColor" stroke-opacity="0.12" stroke-width="1" />
                        <text :x="chart.mL - 6" :y="tick.y + 3" text-anchor="end"
                              font-size="10" fill="currentColor" fill-opacity="0.6">{{ tick.value }}</text>
                    </g>
                    <!-- data line -->
                    <polyline :points="chart.points" fill="none" stroke="rgb(var(--v-theme-primary))"
                              stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    <!-- x-axis endpoints -->
                    <text :x="chart.mL" :y="chart.H - 8" text-anchor="start"
                          font-size="10" fill="currentColor" fill-opacity="0.6">{{ firstDate }}</text>
                    <text :x="chart.W - chart.mR" :y="chart.H - 8" text-anchor="end"
                          font-size="10" fill="currentColor" fill-opacity="0.6">{{ lastDate }}</text>
                </svg>
                <v-table density="compact" class="mt-4">
                    <thead>
                        <tr>
                            <th class="text-left">Date</th>
                            <th class="text-right">New Claims</th>
                            <th class="text-right">Cumulative</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="point in claimsOverTime" :key="point.date">
                            <td>{{ point.date }}</td>
                            <td class="text-right">{{ point.count }}</td>
                            <td class="text-right">{{ point.cumulative }}</td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>
