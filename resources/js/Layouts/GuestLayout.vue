<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useTheme } from 'vuetify';
import LogoSvg from '@/Components/LogoSvg.vue';

const theme = useTheme();
const isDark = computed(() => theme.global.name.value === 'onboard_dark');

function toggleTheme() {
    const next = isDark.value ? 'onboard' : 'onboard_dark';
    theme.global.name.value = next;
    localStorage.setItem('theme', next);
}

const page = usePage();
const betaBanner = ref(page.props.beta_banner ?? false);
const betaDismissed = ref(false);
const showNullBackend = computed(() => page.props.transaction_backend === 'null');
const showBeta = computed(() => betaBanner.value && !betaDismissed.value);

const toggleTop = computed(() => {
    let offset = 8;
    if (showNullBackend.value) offset += 36;
    if (showBeta.value) offset += 32;
    return offset + 'px';
});
</script>

<template>
    <v-app>
        <v-system-bar
            v-if="showNullBackend"
            color="error"
            class="text-center font-weight-bold"
            height="36"
        >
            <v-icon icon="mdi-flask-outline" class="me-2" size="small" />
            TEST MODE — No real transactions will be sent. Do NOT send tokens to any displayed wallet addresses.
        </v-system-bar>
        <v-system-bar
            v-if="showBeta"
            color="warning"
            class="text-center"
            height="32"
        >
            <v-icon icon="mdi-alert" class="me-2" size="small" />
            This system is currently in beta. Features may be incomplete or subject to change.
            <v-spacer />
            <v-btn icon size="x-small" variant="text" @click="betaDismissed = true">
                <v-icon icon="mdi-close" size="small" />
            </v-btn>
        </v-system-bar>

        <div :style="{ position: 'absolute', top: toggleTop, right: '16px', zIndex: 10 }">
            <v-btn icon variant="text" @click="toggleTheme">
                <v-icon :icon="isDark ? 'mdi-white-balance-sunny' : 'mdi-weather-night'" />
            </v-btn>
        </div>

        <v-container class="fill-height" fluid>
            <v-main>
                <v-row justify="center" align="center">
                    <v-col cols="12" sm="10" md="8" lg="5" xl="4">
                        <div class="text-center mb-8">
                            <Link href="/" class="text-decoration-none">
                                <LogoSvg :width="188" :height="30" class="mx-auto" />
                            </Link>
                        </div>
                        <slot />
                    </v-col>
                </v-row>
            </v-main>
        </v-container>
    </v-app>
</template>
