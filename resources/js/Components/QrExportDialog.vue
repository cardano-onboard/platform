<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import QrcodeVue from 'qrcode.vue';

/**
 * Choose what an export looks like, ask for it, and wait for it.
 *
 * Rendering happens on a worker, so asking for an export is the start of something rather
 * than the end of it. The dialog therefore has states: the options form, the wait, and the
 * download. It does not own the wait — the campaign page polls one endpoint for every
 * background job, and the run this dialog is watching arrives in `tasks` like any other. A
 * second poller here would ask the same question twice and disagree with itself.
 *
 * Closing cancels nothing. The run belongs to the campaign, not to this dialog: it carries
 * on, the campaign page keeps showing it, and reopening picks the same run back up.
 */

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    campaign: { type: Object, required: true },
    claimUrl: { type: String, default: '' },
    codesCount: { type: Number, default: 0 },
    gdAvailable: { type: Boolean, default: true },
    // The QR export runs the shared campaign poller is reporting. Watched, never fetched.
    tasks: { type: Array, default: () => [] },
    // Who the codes can be exported for: every code, one partner, or the ones nobody was
    // given. Each carries its count, because the count is what tells an operator the stack
    // they are about to print is the stack they meant.
    scopes: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const open = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const format = ref('pdf');
const sizePreset = ref(1);        // inches; null = custom
const customSize = ref(1);
const dpiPreset = ref(203);       // null = custom
const customDpi = ref(203);
const ecc = ref('L');
const header = ref(false);
const footer = ref(false);
const scope = ref('all');
const grouped = ref(false);

// configuring -> queued -> rendering -> ready, or -> failed from any of them. An archive
// already on the disk goes straight to ready and never shows a progress panel: a bar that
// appears and vanishes within a second reads as a render that skipped most of the job.
const phase = ref('configuring');

// What the request itself can answer. Rendering is not among them, because that is a state
// only a run can be in and the request has not started one yet.
const RESULT_STATUSES = ['ready', 'queued', 'refused'];
const posting = ref(false);
const problem = ref('');
const downloadUrl = ref('');
const fromStorage = ref(false);
const watchedTaskId = ref(null);

const ACTIVE = ['queued', 'running'];

const formats = computed(() => [
    { value: 'pdf', label: 'PDF', icon: 'mdi-file-pdf-box', hint: 'Print-ready · Windows-friendly', disabled: false },
    { value: 'png', label: 'PNG', icon: 'mdi-file-image', hint: props.gdAvailable ? 'Raster image' : 'Unavailable (server has no GD)', disabled: !props.gdAvailable },
    { value: 'svg', label: 'SVG', icon: 'mdi-svg', hint: 'Vector · design tools', disabled: false },
]);

const eccLevels = [
    { value: 'L', label: 'L', hint: '7% — simplest' },
    { value: 'M', label: 'M', hint: '15%' },
    { value: 'Q', label: 'Q', hint: '25%' },
    { value: 'H', label: 'H', hint: '30% — most robust' },
];

/**
 * Whether there is anything to choose between.
 *
 * With no partners on the campaign the only scope is every code, and grouping would produce
 * one folder called unassigned. Both controls are hidden rather than shown doing nothing:
 * the reason they are moot is not visible at the control, it is the absence of partners.
 */
const canGroup = computed(() => props.scopes.length > 1);

/** The stack the operator picked, or every code. */
const chosenScope = computed(
    () => props.scopes.find((option) => option.value === scope.value) ?? null,
);

/** How many stickers this export would hold. */
const stickerCount = computed(() => {
    if (!canGroup.value || scope.value === 'all') return props.codesCount;

    return chosenScope.value?.codes ?? 0;
});

const size = computed(() => (sizePreset.value === null ? Number(customSize.value) : sizePreset.value));
const dpi = computed(() => (dpiPreset.value === null ? Number(customDpi.value) : dpiPreset.value));

const pixels = computed(() => {
    const s = Number(size.value);
    const d = Number(dpi.value);
    if (!s || !d) return null;
    return Math.round(s * d);
});

// PNG resolution is the readout that matters; PDF/SVG carry a physical size instead.
const readout = computed(() => {
    if (!size.value) return '—';
    if (format.value === 'png') {
        return pixels.value ? `${pixels.value} × ${pixels.value} px @ ${dpi.value} dpi` : '—';
    }
    return `${size.value}" × ${size.value}" (vector)`;
});

const previewHeader = computed(() => (props.campaign.end_date ? `Expires ${props.campaign.end_date}` : 'Expires —'));
const previewFooter = 'SAMPLE-CODE';
const sampleUri = computed(
    () => `web+cardano://claim/v1?faucet_url=${encodeURIComponent(props.claimUrl)}&code=SAMPLE-CODE`,
);

// Both captions need a large-enough sticker or the QR shrinks too far to scan
// (mirrors QrStickerService::MIN_SIZE_BOTH_CAPTIONS on the backend).
const MIN_SIZE_BOTH_CAPTIONS = 1.5;
const bothCaptionsTooSmall = computed(
    () => header.value && footer.value && Number(size.value) < MIN_SIZE_BOTH_CAPTIONS,
);

const sizeError = computed(() => {
    const s = Number(size.value);
    return s >= 0.5 && s <= 4 ? null : 'Size must be between 0.5" and 4"';
});
const dpiError = computed(() => {
    const d = Number(dpi.value);
    return Number.isInteger(d) && d >= 72 && d <= 1200 ? null : 'DPI must be a whole number 72–1200';
});
const canRequest = computed(
    () => stickerCount.value > 0 && !sizeError.value && !dpiError.value && !bothCaptionsTooSmall.value,
);

const configuring = computed(() => phase.value === 'configuring');
const waiting = computed(() => phase.value === 'queued' || phase.value === 'rendering');

/** The run this dialog is following, or null when it is not following one. */
const watchedTask = computed(
    () => props.tasks.find((task) => task.id === watchedTaskId.value) ?? null,
);

const percent = computed(() => {
    const task = watchedTask.value;
    if (!task?.progress_total) return 0;
    return Math.min(100, Math.round((task.progress_done / task.progress_total) * 100));
});

const rendered = computed(() => {
    const task = watchedTask.value;
    if (!task?.progress_total) return '';
    return `${task.progress_done} of ${task.progress_total} stickers`;
});

function params() {
    return {
        format: format.value,
        size: String(size.value),
        dpi: String(dpi.value),
        ecc: ecc.value,
        header: header.value ? '1' : '0',
        footer: footer.value ? '1' : '0',
        // Sent as what they are rather than left out when they are the default, so the
        // request and the archive it keys never disagree about which is which.
        group: grouped.value ? 'partner' : 'flat',
        scope: scope.value,
    };
}

function request() {
    if (!canRequest.value || posting.value) return;

    posting.value = true;
    problem.value = '';

    router.post(route('campaigns.qr-exports.store', props.campaign.id), params(), {
        // The run outlives this request, so the dialog has to still be in the state the
        // answer put it in once the page has re-rendered around it.
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            posting.value = false;
        },
        onSuccess: (page) => adopt(page?.props?.flash?.qr_export),
        onError: (errors) => {
            phase.value = 'failed';
            problem.value =
                Object.values(errors ?? {})[0] ||
                'Those settings were refused. Change them and ask again.';
        },
    });
}

/** Take the answer the request gave and move to the state it describes. */
function adopt(result) {
    if (!result || !RESULT_STATUSES.includes(result.status)) {
        phase.value = 'failed';
        problem.value = 'The export was not started, and the server did not say why.';
        return;
    }

    if (result.status === 'ready') {
        // Straight to the download. No run was started, so there is nothing to watch and
        // nothing to show a bar for.
        watchedTaskId.value = null;
        fromStorage.value = true;
        downloadUrl.value = result.download_url ?? '';
        phase.value = 'ready';
        return;
    }

    if (result.status === 'queued') {
        watchedTaskId.value = result.task_id ?? null;
        fromStorage.value = false;
        downloadUrl.value = '';
        phase.value = 'queued';
        return;
    }

    phase.value = 'failed';
    problem.value = result.message || 'That export could not be started.';
}

/** Where a finished run's archive is fetched from. */
function downloadFor(task) {
    const id = task?.result?.export_id;

    if (!id) return '';

    try {
        return route('campaigns.qr-exports.download', [props.campaign.id, id]);
    } catch (e) {
        // The route is absent from this build. Better to show the export as ready with no
        // button than to take the dialog down.
        return '';
    }
}

/** Follow the run wherever the shared poller reports it has got to. */
watch(
    watchedTask,
    (task) => {
        if (!task) return;

        if (task.status === 'queued') {
            phase.value = 'queued';
        } else if (task.status === 'running') {
            phase.value = 'rendering';
        } else if (task.status === 'complete') {
            fromStorage.value = Boolean(task.result?.from_storage);
            downloadUrl.value = downloadFor(task);
            phase.value = 'ready';
        } else if (task.status === 'failed') {
            problem.value =
                task.error || 'The render stopped before it finished and recorded no reason.';
            phase.value = 'failed';
        }
    },
    { deep: true, immediate: true },
);

/**
 * Opened with a render already going, pick it up.
 *
 * This is the way back from the campaign page: the panel there offers to show a running
 * export, and what it opens has to be the wait rather than the options form the operator
 * already filled in.
 */
watch(open, (isOpen) => {
    if (!isOpen || watchedTaskId.value !== null) return;

    const running = props.tasks.find((task) => ACTIVE.includes(task.status));

    if (running) {
        watchedTaskId.value = running.id;
    }
});

/** Back to the form, without touching the run. */
function reconfigure() {
    phase.value = 'configuring';
    problem.value = '';
}

/**
 * Ask for the same export again after one that failed.
 *
 * A render retries itself while it has attempts left, and a retry carries on from what was
 * already rendered rather than starting over. What reaches this state is a run that has
 * used them up, and the operator's own answer to that is to ask again, which is one button
 * rather than filling the form in a second time.
 */
function tryAgain() {
    if (posting.value) return;

    problem.value = '';
    watchedTaskId.value = null;
    request();
}
</script>

<template>
    <v-dialog v-model="open" max-width="820" transition="dialog-bottom-transition">
        <v-card>
            <v-toolbar color="primary">
                <v-toolbar-title>Export QR Codes</v-toolbar-title>
                <v-spacer></v-spacer>
                <v-btn icon @click="open = false">
                    <v-icon icon="mdi-close"></v-icon>
                </v-btn>
            </v-toolbar>

            <v-row v-if="configuring" no-gutters data-test="qr-export-options">
                <!-- Options -->
                <v-col cols="12" md="7">
                    <v-card-text>
                        <template v-if="canGroup">
                            <div class="text-overline mb-1">Given to</div>
                            <v-select
                                v-model="scope"
                                :items="scopes"
                                item-title="label"
                                item-value="value"
                                density="compact"
                                variant="outlined"
                                hide-details="auto"
                                class="mb-2"
                                data-test="qr-export-scope"
                            >
                                <template #item="{ props: itemProps, item }">
                                    <v-list-item
                                        v-bind="itemProps"
                                        :subtitle="`${item.raw.codes} code${item.raw.codes === 1 ? '' : 's'}`"
                                    />
                                </template>
                            </v-select>
                            <v-switch
                                v-model="grouped"
                                color="primary"
                                density="compact"
                                hide-details
                                class="mb-2"
                                data-test="qr-export-grouped"
                                label="One folder per partner"
                            />
                            <div class="text-caption text-medium-emphasis mb-3">
                                <span v-if="grouped">
                                    Each partner gets a folder with their stickers, a count and
                                    their code list, plus a handover sheet at the top to print.
                                </span>
                                <span v-else>Every sticker at the top level of the archive.</span>
                            </div>
                        </template>

                        <div class="text-overline mb-1">Format</div>
                        <v-btn-toggle v-model="format" mandatory divided density="comfortable" class="mb-1 flex-wrap">
                            <v-btn v-for="f in formats" :key="f.value" :value="f.value" :disabled="f.disabled" size="small">
                                <v-icon :icon="f.icon" start></v-icon>{{ f.label }}
                                <v-tooltip activator="parent" location="top">{{ f.hint }}</v-tooltip>
                            </v-btn>
                        </v-btn-toggle>

                        <div class="text-overline mb-1 mt-3">Sticker size</div>
                        <v-btn-toggle v-model="sizePreset" mandatory divided density="comfortable" class="mb-2 flex-wrap">
                            <v-btn :value="1" size="small">1"</v-btn>
                            <v-btn :value="1.5" size="small">1.5"</v-btn>
                            <v-btn :value="2" size="small">2"</v-btn>
                            <v-btn :value="null" size="small">Custom</v-btn>
                        </v-btn-toggle>
                        <v-text-field v-if="sizePreset === null" v-model="customSize" type="number" step="0.25"
                                      density="compact" variant="outlined" label="Size (inches)" suffix="in"
                                      :error-messages="sizeError" hide-details="auto" class="mb-2" />

                        <div class="text-overline mb-1 mt-2">Resolution (DPI)</div>
                        <v-btn-toggle v-model="dpiPreset" mandatory divided density="comfortable" class="mb-2 flex-wrap">
                            <v-btn :value="203" size="small">203</v-btn>
                            <v-btn :value="300" size="small">300</v-btn>
                            <v-btn :value="null" size="small">Custom</v-btn>
                        </v-btn-toggle>
                        <v-text-field v-if="dpiPreset === null" v-model="customDpi" type="number" step="1"
                                      density="compact" variant="outlined" label="DPI" suffix="dpi"
                                      :error-messages="dpiError" hide-details="auto" class="mb-2" />

                        <div class="text-overline mb-1 mt-2">Error correction</div>
                        <v-btn-toggle v-model="ecc" mandatory divided density="comfortable" class="mb-3">
                            <v-btn v-for="e in eccLevels" :key="e.value" :value="e.value" size="small">
                                {{ e.label }}
                                <v-tooltip activator="parent" location="top">{{ e.hint }}</v-tooltip>
                            </v-btn>
                        </v-btn-toggle>

                        <v-switch v-model="header" color="primary" density="compact" hide-details
                                  :label="`Header — expiration date${campaign.end_date ? ' (' + campaign.end_date + ')' : ''}`" />
                        <v-switch v-model="footer" color="primary" density="compact" hide-details
                                  label="Footer — claim code" />
                        <v-alert v-if="bothCaptionsTooSmall" type="warning" variant="tonal" density="compact"
                                 class="mt-2" icon="mdi-alert">
                            A {{ size }}" sticker is too small for both a header and footer — the QR would
                            shrink too far to scan. Use just one caption, or a sticker at least
                            {{ MIN_SIZE_BOTH_CAPTIONS }}".
                        </v-alert>
                    </v-card-text>
                </v-col>

                <!-- Live preview -->
                <v-col cols="12" md="5" class="d-flex flex-column align-center justify-center pa-4 bg-grey-lighten-4">
                    <div class="text-overline mb-2 text-medium-emphasis">Preview</div>
                    <div class="qr-sticker">
                        <div v-if="header" class="qr-band text-truncate">{{ previewHeader }}</div>
                        <div class="qr-code">
                            <qrcode-vue :value="sampleUri" :size="150" render-as="svg" level="L" margin="1" />
                        </div>
                        <div v-if="footer" class="qr-band text-truncate">{{ previewFooter }}</div>
                    </div>
                    <div class="text-caption text-medium-emphasis mt-3 text-center">{{ readout }}</div>
                </v-col>
            </v-row>

            <!-- Waiting on a worker. Queued and rendering are different things to an
                 operator: one means nothing has picked it up yet. -->
            <v-card-text v-else-if="waiting" class="py-8 text-center" data-test="qr-export-progress">
                <v-progress-circular indeterminate size="42" width="4" color="primary" class="mb-4" />
                <div class="text-h6 mb-1">
                    {{ phase === 'queued' ? 'Waiting for a worker' : 'Rendering your stickers' }}
                </div>
                <div class="text-body-2 text-medium-emphasis mb-4">
                    <span v-if="phase === 'queued'">
                        The export is queued. It starts as soon as a worker is free.
                    </span>
                    <span v-else-if="rendered">{{ rendered }}</span>
                    <span v-else>{{ watchedTask?.stage || 'Working through the codes.' }}</span>
                </div>
                <v-progress-linear
                    class="mb-4"
                    color="primary"
                    height="6"
                    rounded
                    :indeterminate="phase === 'queued' || !watchedTask?.progress_total"
                    :model-value="percent"
                />
                <v-alert type="info" variant="tonal" density="compact" icon="mdi-information-outline">
                    You can close this. The export keeps going, and the campaign page will offer
                    it when it is ready.
                </v-alert>
            </v-card-text>

            <v-card-text v-else-if="phase === 'ready'" class="py-8 text-center" data-test="qr-export-ready">
                <v-icon icon="mdi-check-circle-outline" color="success" size="48" class="mb-3"></v-icon>
                <div class="text-h6 mb-1">Your export is ready</div>
                <div class="text-body-2 text-medium-emphasis mb-5">
                    <span v-if="fromStorage">
                        This one had already been built, so nothing had to be rendered again.
                    </span>
                    <span v-else>
                        {{ stickerCount }} sticker{{ stickerCount === 1 ? '' : 's' }}, one per code.
                    </span>
                </div>
                <v-btn
                    v-if="downloadUrl"
                    color="primary"
                    size="large"
                    variant="flat"
                    prepend-icon="mdi-download"
                    :href="downloadUrl"
                    data-test="qr-export-download"
                >
                    Download the archive
                </v-btn>
                <v-alert v-else type="info" variant="tonal" density="compact" class="text-left">
                    The archive is built. It is listed on the campaign page under QR exports.
                </v-alert>
            </v-card-text>

            <v-card-text v-else class="py-8 text-center" data-test="qr-export-failed">
                <v-icon icon="mdi-alert-circle-outline" color="error" size="48" class="mb-3"></v-icon>
                <div class="text-h6 mb-1">That export did not finish</div>
                <div class="text-body-2 text-medium-emphasis mb-5">{{ problem }}</div>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-refresh"
                    :loading="posting"
                    data-test="qr-export-retry"
                    @click="tryAgain"
                >
                    Try again
                </v-btn>
                <div class="text-caption text-medium-emphasis mt-3">
                    Anything already rendered is kept, so this carries on from where it stopped.
                </div>
            </v-card-text>

            <v-divider></v-divider>
            <v-card-actions class="px-4 py-3">
                <span v-if="configuring" class="text-caption text-medium-emphasis">
                    {{ stickerCount }} code{{ stickerCount === 1 ? '' : 's' }} · one unique QR each
                </span>
                <v-btn
                    v-else
                    variant="text"
                    prepend-icon="mdi-cog-outline"
                    data-test="qr-export-reconfigure"
                    @click="reconfigure"
                >
                    Export settings
                </v-btn>
                <v-spacer></v-spacer>
                <v-btn variant="text" @click="open = false">
                    {{ configuring ? 'Cancel' : 'Close' }}
                </v-btn>
                <v-btn
                    v-if="configuring"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-cog-play-outline"
                    :disabled="!canRequest"
                    :loading="posting"
                    data-test="qr-export-request"
                    @click="request"
                >
                    Prepare {{ stickerCount }} sticker{{ stickerCount === 1 ? '' : 's' }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.qr-sticker {
    width: 180px;
    background: #fff;
    border: 1px solid rgba(0, 0, 0, 0.15);
    border-radius: 4px;
    padding: 5px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
}
.qr-band {
    font-family: Helvetica, Arial, sans-serif;
    font-size: 15px;
    font-weight: 500;
    line-height: 1.1;
    color: #000;
    width: 100%;
    text-align: center;
}
.qr-code {
    line-height: 0;
}
</style>
