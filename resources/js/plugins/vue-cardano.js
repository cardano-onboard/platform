/**
 * Vue-Cardano Plugin — MeshJS-based wallet integration
 *
 * Provides wallet detection, connection, and utility methods via a Vue mixin.
 * Uses MeshJS BrowserWallet for CIP-30 interactions.
 *
 * Available via `this.cardano` in any component:
 *   - cardano.status: 'init' | 'found' | 'notfound' | 'connected'
 *   - cardano.Wallets: detected CIP-30 wallets
 *   - cardano.Wallet: connected BrowserWallet instance
 *   - cardano.ActiveWallet: wallet metadata (name, icon, etc.)
 */

import { BrowserWallet } from "@meshsdk/core";

const defaultOptions = {
    retries: 10,
    frequency: 200,
};

export default {
    install(Vue, options) {
        const userOptions = { ...defaultOptions, ...options };

        Vue.mixin({
            data() {
                return {
                    cardano: {
                        status: "init",
                        retries: userOptions.retries,
                        pollingFrequency: userOptions.frequency,
                        found: false,
                        Wallets: [],
                        Wallet: null,
                        ActiveWallet: false,
                        stake_key: null,
                        change_address: null,
                        lovelace_format: {
                            minimumIntegerDigits: 1,
                            maximumFractionDigits: 6,
                            minimumFractionDigits: 0,
                        },
                    },
                };
            },
            methods: {
                formatAda(value) {
                    const the_number = Number(value);
                    return (
                        the_number.toLocaleString(undefined, this.cardano.lovelace_format) +
                        " \u20B3"
                    );
                },
                toAda(lovelace) {
                    if (typeof lovelace === "bigint") {
                        return lovelace / 1000000n;
                    }
                    return lovelace / 1000000;
                },
                toLovelace(Ada) {
                    if (typeof Ada === "bigint") {
                        return Ada * 1000000n;
                    }
                    return Ada * 1000000;
                },
                hexToString(hex) {
                    let str = "";
                    for (let i = 0; i < hex.length; i += 2) {
                        str += String.fromCharCode(parseInt(hex.substr(i, 2), 16));
                    }
                    return str;
                },
                stringToHex(str) {
                    let hex = "";
                    for (let i = 0; i < str.length; i++) {
                        hex += str.charCodeAt(i).toString(16).padStart(2, "0");
                    }
                    return hex;
                },
                checkForCardano() {
                    let loop = setInterval(() => {
                        if (this.cardano.retries <= 0) {
                            clearInterval(loop);
                            if (this.cardano.found) {
                                this.cardano.status = "found";
                            } else {
                                this.cardano.status = "notfound";
                            }
                            return;
                        }

                        if (window.cardano !== undefined) {
                            this.cardano.found = true;
                            this.checkWallets();
                        }

                        this.cardano.retries--;
                    }, this.cardano.pollingFrequency);
                },
                checkWallets() {
                    try {
                        const installed = BrowserWallet.getInstalledWallets();
                        this.cardano.Wallets = installed.map((w) => ({
                            ...w,
                            loading: false,
                        }));
                    } catch (e) {
                        console.error("Error detecting wallets:", e);
                    }
                },
                async connect(wallet) {
                    wallet.loading = true;
                    try {
                        const browserWallet = await BrowserWallet.enable(wallet.id || wallet.name.toLowerCase());
                        this.cardano.Wallet = browserWallet;
                        this.cardano.ActiveWallet = wallet;
                        this.cardano.status = "connected";

                        // Get stake key
                        const rewardAddresses = await browserWallet.getRewardAddresses();
                        if (rewardAddresses && rewardAddresses.length > 0) {
                            this.cardano.stake_key = rewardAddresses[0];
                        }

                        this.$emit("connected");
                        wallet.loading = false;
                        this.$forceUpdate();
                    } catch (e) {
                        wallet.loading = false;
                        this.$forceUpdate();
                        console.error("Connection Error:", e);
                        throw e;
                    }
                },
                async getWalletNetwork() {
                    return await this.cardano.Wallet.getNetworkId();
                },
                async checkNetwork(network_id) {
                    const wallet_network = await this.cardano.Wallet.getNetworkId();
                    return wallet_network === network_id;
                },
                changeWallet() {
                    this.cardano.ActiveWallet = false;
                    this.cardano.Wallet = null;
                    this.cardano.status = "found";
                    this.cardano.stake_key = null;
                    this.cardano.change_address = null;
                },
                async getChangeAddress() {
                    try {
                        return await this.cardano.Wallet.getChangeAddress();
                    } catch (e) {
                        console.error("Change Address Error:", e);
                        return false;
                    }
                },
                async getUtxos() {
                    try {
                        return await this.cardano.Wallet.getUtxos();
                    } catch (e) {
                        console.error("Get UTxOs Error:", e);
                        return [];
                    }
                },
            },
        });
    },
};
