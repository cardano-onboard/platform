<script setup>
import { usePage } from '@inertiajs/vue3';
import NoticeBar from '@/Components/NoticeBar.vue';

// Shown while BETA_BANNER is on (the default). Read once at mount, so it stays put
// across Inertia visits within the page the same way it did as a system bar.
const page = usePage();
const show = page.props.beta_banner ?? false;

defineEmits(['height']);
</script>

<template>
    <NoticeBar
        v-if="show"
        name="beta-notice"
        type="warning"
        message="This system is currently in beta. Features may be incomplete or subject to change."
        @height="$emit('height', $event)"
    />
</template>
