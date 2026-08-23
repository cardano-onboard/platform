<script setup>
// Presentational list of native-asset rows for the campaign wallet status cards and
// the "create code" reward list. Each token is pre-resolved (name/logo/decimals/amount)
// by the parent so this stays a dumb, reusable renderer. Human name + logo lead; the
// policy id is demoted to a quiet caption instead of dominating the row.
defineProps({
    tokens: { type: Array, default: () => [] },
    removable: { type: Boolean, default: false },
});

const emit = defineEmits(['remove']);
</script>

<template>
    <v-list class="bg-transparent pa-0" density="comfortable">
        <v-list-item v-for="(t, index) in tokens" :key="t.policy + t.asset + index" class="px-0">
            <template #prepend>
                <v-avatar size="36" :color="t.logo ? undefined : 'primary'" class="me-3" rounded="lg">
                    <v-img v-if="t.logo" :src="`data:image/png;base64,${t.logo}`" :alt="t.name"></v-img>
                    <span v-else class="text-caption font-weight-bold text-white">
                        {{ (t.name || '?').replace(/[^a-zA-Z0-9]/g, '').slice(0, 3).toUpperCase() || '?' }}
                    </span>
                </v-avatar>
            </template>

            <v-list-item-title class="font-weight-medium">{{ t.name }}</v-list-item-title>
            <v-list-item-subtitle>
                <code class="text-caption text-medium-emphasis">{{ t.policy.slice(0, 8) }}…{{ t.policy.slice(-4) }}</code>
            </v-list-item-subtitle>

            <template #append>
                <div class="d-flex align-center">
                    <div class="text-end">
                        <div class="font-weight-bold">{{ t.amount }}</div>
                        <v-chip v-if="t.decimals > 0" size="x-small" variant="tonal" color="info"
                                :title="`${t.raw} base units (${t.decimals} decimals)`">
                            <v-icon start icon="mdi-decimal" size="x-small"></v-icon>
                            {{ t.decimals }} decimals
                        </v-chip>
                    </div>
                    <v-btn v-if="removable" icon="mdi-close" size="x-small" variant="text" color="error"
                           class="ms-2" @click="emit('remove', index)"></v-btn>
                </div>
            </template>
        </v-list-item>
    </v-list>
</template>
