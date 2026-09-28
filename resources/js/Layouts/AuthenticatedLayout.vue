<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useTheme } from 'vuetify';
import LogoSvg from '@/Components/LogoSvg.vue';
import DeploymentNoticeBar from '@/Components/DeploymentNoticeBar.vue';
import BetaNoticeBar from '@/Components/BetaNoticeBar.vue';

// The self-hosted build ships a reduced route table — profile management and
// the marketing pages exist only on the SaaS deployment. route() throws on an
// unknown name, which would blank every page using this layout, so check first.
const hasRoute = (name) => route().has(name);

const theme = useTheme();
const isDark = computed(() => theme.global.name.value === 'onboard_dark');

function toggleTheme() {
    const next = isDark.value ? 'onboard' : 'onboard_dark';
    theme.global.name.value = next;
    localStorage.setItem('theme', next);
}

const page = usePage();

// The operator's view of the whole deployment. Guarded on the flag as well as the route,
// because the route exists for everyone signed in and the gate is what refuses them.
const isAdmin = computed(() => page.props.auth?.user?.is_admin === true);

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <v-app>
        <DeploymentNoticeBar />
        <v-system-bar
            v-if="page.props.transaction_backend === 'null'"
            color="error"
            class="text-center font-weight-bold"
            height="36"
        >
            <v-icon icon="mdi-flask-outline" class="me-2" size="small" />
            TEST MODE — No real transactions will be sent. Do NOT send tokens to any displayed wallet addresses.
        </v-system-bar>
        <BetaNoticeBar />
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
                        v-if="hasRoute('profile.edit')"
                        :href="route('profile.edit')"
                    />
                    <v-list-item
                        prepend-icon="mdi-chart-box-outline"
                        title="Platform Metrics"
                        v-if="isAdmin && hasRoute('admin.metrics')"
                        :href="route('admin.metrics')"
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
                <v-btn v-if="hasRoute('terms')" variant="text" :href="route('terms')" size="small" class="text-grey">Terms &amp; Conditions</v-btn>
                <v-btn v-if="hasRoute('privacy')" variant="text" :href="route('privacy')" size="small" class="text-grey">Privacy Policy</v-btn>
                <v-btn v-if="hasRoute('faqs')" variant="text" :href="route('faqs')" size="small" class="text-grey">FAQs</v-btn>
            </div>
            <div class="text-body-2 text-grey">
                &copy; {{ new Date().getFullYear() }} — <strong>Onboard.Ninja</strong> — All rights reserved.
            </div>
        </v-footer>
    </v-app>
</template>
