<script setup>
import { router, usePage } from '@inertiajs/vue3';
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

function logout() {
    router.post(route('logout'));
}
</script>

<template>
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
        <v-app-bar flat density="comfortable" color="surface" elevation="1">
            <div class="d-flex align-center ms-4 me-4">
                <a :href="route('dashboard')" class="d-flex align-center text-decoration-none">
                    <LogoSvg />
                </a>
            </div>

            <v-btn :href="route('dashboard')" variant="text" prepend-icon="mdi-view-dashboard">
                Dashboard
            </v-btn>

            <v-spacer />

            <v-btn icon variant="text" @click="toggleTheme" class="me-2">
                <v-icon :icon="isDark ? 'mdi-white-balance-sunny' : 'mdi-weather-night'" />
            </v-btn>

            <v-menu>
                <template v-slot:activator="{ props }">
                    <v-btn v-bind="props" variant="tonal" color="primary" rounded append-icon="mdi-chevron-down">
                        <template v-slot:prepend>
                            <v-icon icon="mdi-account" />
                        </template>
                        {{ $page.props.auth.user.name }}
                    </v-btn>
                </template>
                <v-list density="compact" nav>
                    <v-list-item
                        prepend-icon="mdi-account"
                        title="Profile"
                        :href="route('profile.edit')"
                    />
                    <v-divider />
                    <v-list-item
                        prepend-icon="mdi-logout"
                        title="Log Out"
                        @click="logout"
                    />
                </v-list>
            </v-menu>
        </v-app-bar>

        <v-container class="fill-height" fluid>
            <v-main>
                <slot />
            </v-main>
        </v-container>

        <v-footer class="d-flex flex-column">
            <div class="mb-4">
                <v-btn variant="text" :href="route('terms')" size="small" class="text-grey">Terms &amp; Conditions</v-btn>
                <v-btn variant="text" :href="route('privacy')" size="small" class="text-grey">Privacy Policy</v-btn>
                <v-btn variant="text" :href="route('faqs')" size="small" class="text-grey">FAQs</v-btn>
            </div>
            <div class="text-body-2 text-grey">
                &copy; {{ new Date().getFullYear() }} — <strong>Onboard.Ninja</strong> — All rights reserved.
            </div>
        </v-footer>
    </v-app>
</template>
