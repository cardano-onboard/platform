<script setup>
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <v-app>
        <Head title="Open this link in a Cardano wallet" />
        <v-main>
            <v-container style="max-width: 480px">
                <v-card rounded="xl" elevation="2">
                    <v-card-title class="px-6 pt-6 text-wrap"><h1 class="text-h6">Open this link in a Cardano wallet</h1></v-card-title>
                    <v-card-text class="px-6 pb-6">
                        This link opens in a Cardano wallet app. Scan the QR code with a Cardano wallet app that supports claim links.
                    </v-card-text>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>
