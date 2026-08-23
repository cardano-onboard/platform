<script setup>
import { computed, ref } from 'vue';
import QrcodeVue from 'qrcode.vue';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    campaign: { type: Object, required: true },
    claimUrl: { type: String, default: '' },
    codesCount: { type: Number, default: 0 },
    gdAvailable: { type: Boolean, default: true },
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
const canDownload = computed(
    () => props.codesCount > 0 && !sizeError.value && !dpiError.value && !bothCaptionsTooSmall.value,
);

function download() {
    if (!canDownload.value) return;
    const params = new URLSearchParams({
        format: format.value,
        size: String(size.value),
        dpi: String(dpi.value),
        ecc: ecc.value,
        header: header.value ? '1' : '0',
        footer: footer.value ? '1' : '0',
    });
    // GET download endpoint — navigating triggers the ZIP download without leaving the SPA.
    window.location.href = `${route('campaigns.download-qr', props.campaign.id)}?${params.toString()}`;
    open.value = false;
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

            <v-row no-gutters>
                <!-- Options -->
                <v-col cols="12" md="7">
                    <v-card-text>
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

            <v-divider></v-divider>
            <v-card-actions class="px-4 py-3">
                <span class="text-caption text-medium-emphasis">
                    {{ codesCount }} code{{ codesCount === 1 ? '' : 's' }} · one unique QR each
                </span>
                <v-spacer></v-spacer>
                <v-btn variant="text" @click="open = false">Cancel</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-download" :disabled="!canDownload" @click="download">
                    Download {{ codesCount }} sticker{{ codesCount === 1 ? '' : 's' }}
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
