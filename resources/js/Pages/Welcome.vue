<script setup>
import { Head, usePage } from '@inertiajs/vue3';
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
        <v-system-bar
            v-if="page.props.transaction_backend === 'null'"
            color="error"
            class="text-center font-weight-bold"
            height="36"
        >
            <v-icon icon="mdi-flask-outline" class="me-2" size="small" />
            TEST MODE — No real transactions will be sent. Do NOT send tokens to any displayed wallet addresses.
        </v-system-bar>
        <v-system-bar
            v-if="betaBanner && !betaDismissed"
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
        <div style="position: absolute; top: 8px; right: 16px; z-index: 10;">
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
                                        v-if="canRegister"
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
                            <v-btn variant="text" :href="route('terms')" size="small" class="text-grey">Terms</v-btn>
                            <v-btn variant="text" :href="route('privacy')" size="small" class="text-grey">Privacy</v-btn>
                            <v-btn variant="text" :href="route('faqs')" size="small" class="text-grey">FAQs</v-btn>
                        </div>
                    </v-col>
                </v-row>
            </v-main>
        </v-container>
    </v-app>
</template>
