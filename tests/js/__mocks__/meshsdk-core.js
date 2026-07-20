// Mock for @meshsdk/core (WASM/browser APIs unavailable in jsdom test environment)
export class BrowserWallet {
    static getInstalledWallets() {
        return [];
    }

    static async enable() {
        return new BrowserWallet();
    }

    async getNetworkId() {
        return 0;
    }

    async getChangeAddress() {
        return 'addr_test1mock';
    }

    async getRewardAddresses() {
        return ['stake_test1mock'];
    }

    async getUtxos() {
        return [];
    }

    async signTx(tx) {
        return tx;
    }

    async submitTx(tx) {
        return 'mock_tx_hash_123';
    }
}

export class Transaction {
    constructor() {
        this.outputs = [];
    }

    sendLovelace() {
        return this;
    }

    sendAssets() {
        return this;
    }

    async build() {
        return 'mock_unsigned_tx';
    }
}
