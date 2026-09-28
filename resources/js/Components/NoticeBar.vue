<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

// A full-width, dismissible banner at the top of the page. It is a Vuetify layout item,
// like the system bars and app bar below it: those are fixed to the top of the viewport,
// each offset by the sizes of the items registered before it, so anything that is not a
// layout item is drawn over. Its size is measured rather than fixed, because the message
// wraps onto two or three lines on a phone and a fixed size would clip it.
const props = defineProps({
    name: { type: String, required: true },
    message: { type: String, required: true },
    type: { type: String, default: 'info' },
    link: { type: Object, default: null },
});

const emit = defineEmits(['height']);

// Dismissed once per browser tab: the choice is kept in sessionStorage, so it holds
// across Inertia navigation and refreshes, and a new tab or a later visit shows the bar
// again. The stored value is the message itself, so changing the message shows it again
// even in a tab where the old one was dismissed. Storage can be missing or throw (blocked
// site data); the bar then falls back to being dismissed for this page only.
const storageKey = `notice-dismissed:${props.name}`;

function wasDismissed() {
    try {
        return window.sessionStorage.getItem(storageKey) === props.message;
    } catch {
        return false;
    }
}

const dismissed = ref(wasDismissed());

function dismiss() {
    dismissed.value = true;
    try {
        window.sessionStorage.setItem(storageKey, props.message);
    } catch {
        // Nothing to keep it in; it stays dismissed until the next page load.
    }
}

const content = ref(null);
const height = ref(0);
let observer = null;

watch(content, (el) => {
    observer?.disconnect();
    observer = null;

    if (!el) {
        height.value = 0;
        return;
    }

    height.value = el.offsetHeight;
    observer = new ResizeObserver(() => {
        height.value = el.offsetHeight;
    });
    observer.observe(el);
});

watch(
    () => (dismissed.value ? 0 : height.value),
    (value) => emit('height', value),
    { immediate: true },
);

onBeforeUnmount(() => {
    observer?.disconnect();
    emit('height', 0);
});
</script>

<template>
    <v-layout-item
        v-if="!dismissed"
        :name="props.name"
        position="top"
        :size="height"
        model-value
    >
        <div ref="content">
            <v-alert
                :type="props.type"
                density="comfortable"
                tile
                border="start"
                closable
                class="notice-bar text-body-2"
                @click:close="dismiss"
            >
                <span class="notice-bar__message">{{ props.message }}</span>
                <a
                    v-if="props.link"
                    :href="props.link.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="notice-bar__link"
                >{{ props.link.text }}</a>
            </v-alert>
        </div>
    </v-layout-item>
</template>

<style scoped>
/* Content wraps as a flexible run of text + link rather than a single nowrap line, so a
   long message or link text never forces horizontal scroll on a narrow phone screen. */
.notice-bar :deep(.v-alert__content) {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    column-gap: 8px;
    row-gap: 2px;
}

.notice-bar__message,
.notice-bar__link {
    overflow-wrap: anywhere;
}

.notice-bar__link {
    color: inherit;
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
}
</style>
