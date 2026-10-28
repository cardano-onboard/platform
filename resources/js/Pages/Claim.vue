<script setup>
import { Head } from '@inertiajs/vue3';
import { addressToBech32, deserializeAddress } from '@meshsdk/core-cst';
import { ref } from 'vue';

const code = ref('');
const address = ref('');
const connectedWallet = ref('');
const wallets = ref([]);
const choosing = ref(false);
const busy = ref(false);
const result = ref(null);

// CIP-30 puts every installed wallet under window.cardano, keyed by its own name. It is
// read when the recipient asks for a wallet, because extensions inject at different times.
function chooseWallet() {
    wallets.value = Object.values(window.cardano ?? {}).filter((w) => typeof w?.enable === 'function' && w.name);
    choosing.value = true;
}

async function useWallet(wallet) {
    try {
        const api = await wallet.enable();
        // CIP-30 returns hex bytes and CIP-99 wants bech32; this accepts either form.
        address.value = addressToBech32(deserializeAddress(await api.getChangeAddress()));
        connectedWallet.value = wallet.name;
        result.value = null;
    } catch (e) {
        address.value = '';
        connectedWallet.value = '';
        result.value = {
            ok: false,
            text: `Could not get an address from ${wallet.name}. Approve the connection in the wallet and try again.`,
        };
    }
}

// The statuses CIP-99 defines, in this page's words. An address-type refusal arrives as
// invalidaddress with its own message, which is better than the generic line.
const failures = {
    invalidaddress: 'That is not a valid Cardano address.',
    missingcode: 'Enter your claim code.',
    invalidnetwork: 'That address is on the wrong network for this claim.',
    notfound: 'That code was not found. Check it and try again.',
    alreadyclaimed: 'This code or this wallet has already claimed.',
    expired: 'This claim has ended.',
    tooearly: 'This claim has not started yet.',
    ratelimited: 'Too many attempts. Wait a minute and try again.',
};

async function claim() {
    if (busy.value) return;
    if (!address.value) {
        result.value = { ok: false, text: 'Connect a wallet to receive your reward.' };
        return;
    }
    busy.value = true;
    result.value = null;
    try {
        // The page and the endpoint share an address, so this stays on the same host.
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ address: address.value.trim(), code: code.value.trim() }),
        });
        const body = await response.json().catch(() => ({}));
        if (['accepted', 'queued', 'claimed'].includes(body.status)) {
            result.value = {
                ok: true,
                text: body.tx_hash
                    ? `Your reward has been sent. Transaction: ${body.tx_hash}`
                    : 'Your claim was accepted. The reward arrives in your wallet shortly.',
            };
        } else {
            const status = response.status === 429 ? 'ratelimited' : body.status;
            result.value = {
                ok: false,
                text:
                    (status === 'invalidaddress' && body.message) ||
                    failures[status] ||
                    'The claim could not be completed. Try again.',
            };
        }
    } catch (e) {
        result.value = { ok: false, text: 'Could not reach the server. Check your connection and try again.' };
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <v-app>
        <Head title="Claim" />
        <v-main>
            <v-container style="max-width: 480px">
                <v-card rounded="xl" elevation="2">
                    <v-form @submit.prevent="claim">
                        <v-card-title class="px-6 pt-6"><h1 class="text-h6">Claim your reward</h1></v-card-title>
                        <v-card-text class="px-6">
                            <v-text-field v-model="code" label="Claim code" autocomplete="off" />
                            <div class="mt-2">
                                <v-btn block variant="outlined" @click="chooseWallet">Connect a wallet</v-btn>
                                <div v-if="choosing && !wallets.length" role="status" class="text-caption text-medium-emphasis mt-2">
                                    No wallet extension found. Install a Cardano wallet extension, reload this page and try again.
                                </div>
                                <div class="d-flex flex-wrap ga-2 mt-2">
                                    <v-btn v-for="wallet in wallets" :key="wallet.name" variant="outlined" @click="useWallet(wallet)">
                                        <template #prepend><img :src="wallet.icon" width="20" height="20" alt="" /></template>
                                        {{ wallet.name }}
                                    </v-btn>
                                </div>
                                <div v-if="connectedWallet" role="status" class="text-caption text-medium-emphasis mt-2" style="overflow-wrap: anywhere">
                                    Connected to {{ connectedWallet }}: {{ address }}
                                </div>
                            </div>
                            <v-alert v-if="result" :type="result.ok ? 'success' : 'error'" class="mt-4" density="compact">
                                {{ result.text }}
                            </v-alert>
                        </v-card-text>
                        <v-card-actions class="px-6 pb-6">
                            <v-spacer />
                            <v-btn color="primary" type="submit" :disabled="busy" :loading="busy">Claim</v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>
