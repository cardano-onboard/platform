<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import NoticeBar from '@/Components/NoticeBar.vue';

// Shared by HandleInertiaRequests only when APP_NOTICE is set, and left out of the
// props entirely on attendee-facing pages (see App\Http\Middleware\HandleInertiaRequests),
// so `notice` is null on every page this component has no business appearing on.
//
// Dismissible once per browser tab, the same as the beta banner it sits beside. A new
// tab or a later visit shows it again, which is the point: an operator returning to a
// deployment that has moved should not be able to permanently silence the one thing
// telling them so.
const page = usePage();
const notice = computed(() => page.props.deployment_notice ?? null);

defineEmits(['height']);
</script>

<template>
    <NoticeBar
        v-if="notice"
        name="deployment-notice"
        :type="notice.type"
        :message="notice.message"
        :link="notice.link"
        @height="$emit('height', $event)"
    />
</template>
