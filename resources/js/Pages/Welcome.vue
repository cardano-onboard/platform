<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useTheme } from 'vuetify';
import LogoSvg from '@/Components/LogoSvg.vue';
import DeploymentNoticeBar from '@/Components/DeploymentNoticeBar.vue';
import BetaNoticeBar from '@/Components/BetaNoticeBar.vue';

const theme = useTheme();
const isDark = computed(() => theme.global.name.value === 'onboard_dark');

function toggleTheme() {
    const next = isDark.value ? 'onboard' : 'onboard_dark';
    theme.global.name.value = next;
    localStorage.setItem('theme', next);
}

const page = usePage();

// The theme toggle sits below whichever banners are showing. The two notices measure
// themselves, because their messages wrap on a phone; the test-mode bar is a fixed 36px.
const noticeHeight = ref(0);
const betaHeight = ref(0);
const toggleTop = computed(() => {
    let offset = 8 + noticeHeight.value + betaHeight.value;
    if (page.props.transaction_backend === 'null') offset += 36;
    return offset + 'px';
});

// The self-hosted build ships a reduced route table — the marketing pages
// (terms/privacy/faqs) and registration exist only on the SaaS deployment.
// Calling route() for a name Ziggy doesn't know throws, which would take the
// whole page down, so check before linking.
const hasRoute = (name) => route().has(name);

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});
</script>

<template>
    <Head title="Welcome" />

    <v-app>
        <DeploymentNoticeBar @height="noticeHeight = $event" />
        <v-system-bar
            v-if="page.props.transaction_backend === 'null'"
            color="error"
            class="text-center font-weight-bold"
            height="36"
        >
            <v-icon icon="mdi-flask-outline" class="me-2" size="small" />
            TEST MODE — No real transactions will be sent. Do NOT send tokens to any displayed wallet addresses.
        </v-system-bar>
        <BetaNoticeBar @height="betaHeight = $event" />
        <div :style="{ position: 'absolute', top: toggleTop, right: '16px', zIndex: 10 }">
            <v-btn icon variant="text" @click="toggleTheme">
                <v-icon :icon="isDark ? 'mdi-white-balance-sunny' : 'mdi-weather-night'" />
            </v-btn>
        </div>

        <v-container class="fill-height" fluid>
            <v-main>
                <v-row justify="center" align="center">
                    <v-col cols="12" sm="8" md="6" lg="4" xl="3">
                        <div class="text-center mb-8">
                            <LogoSvg :width="188" :height="30" class="mx-auto" />
                            <p class="text-body-2 text-grey mt-4">Ninja-fast Cardano airdrops for your event</p>
                        </div>

                        <v-card rounded="xl" elevation="2">
                            <v-card-text class="pa-8 text-center">
                                <template v-if="$page.props.auth.user">
                                    <p class="text-body-1 mb-6">Welcome back, {{ $page.props.auth.user.name }}!</p>
                                    <v-btn
                                        color="primary"
                                        size="large"
                                        block
                                        rounded
                                        :href="route('dashboard')"
                                    >
                                        Go to Dashboard
                                    </v-btn>
                                </template>
                                <template v-else>
                                    <v-btn
                                        v-if="canLogin"
                                        color="primary"
                                        size="large"
                                        block
                                        rounded
                                        :href="route('login')"
                                        class="mb-3"
                                    >
                                        Log In
                                    </v-btn>
                                    <v-btn
                                        v-if="canRegister && hasRoute('register')"
                                        color="primary"
                                        variant="outlined"
                                        size="large"
                                        block
                                        rounded
                                        :href="route('register')"
                                    >
                                        Register
                                    </v-btn>
                                </template>
                            </v-card-text>
                        </v-card>

                        <div class="text-center mt-6">
                            <v-btn v-if="hasRoute('terms')" variant="text" :href="route('terms')" size="small" class="text-grey">Terms</v-btn>
                            <v-btn v-if="hasRoute('privacy')" variant="text" :href="route('privacy')" size="small" class="text-grey">Privacy</v-btn>
                        </div>
                    </v-col>
                </v-row>
            </v-main>
        </v-container>
    </v-app>
</template>
