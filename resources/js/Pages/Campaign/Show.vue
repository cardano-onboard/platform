<script setup>
import {Head, useForm, router} from '@inertiajs/vue3';
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import {computed, getCurrentInstance, onMounted, reactive, ref} from "vue";
import QrcodeVue from "qrcode.vue";
// import * as CSL from "@emurgo/cardano-serialization-lib-asmjs";
import {
    Address,
    BigNum,
    Transaction,
    TransactionBuilder,
    TransactionOutputBuilder,
    TransactionUnspentOutput,
    TransactionUnspentOutputs,
    TransactionWitnessSet,
    Vkeywitnesses
} from "@emurgo/cardano-serialization-lib-asmjs"

const props = defineProps({
    flash: Object,
    campaign: Object,
    claim_url: String,
    encoded_claim_url: String,
    balance: Array,
    wallet_pending: {
        type: Boolean,
        default: false,
    },
    backend_mismatch: {
        type: Boolean,
        default: false,
    },
    wallet_backend: {
        type: String,
        default: null,
    },
    max_file_size: {
        type: Number,
        default: 10 * 1024 * 1024,
    },
});

const maxFileSizeMB = computed(() => Math.round(props.max_file_size / 1024 / 1024));

const dialog = reactive({
    code: false,
    token: false,
    import: false,
    missing: false,
    show_balance: false,
    wallet: false,
    wallet_balance: false,
    show_toast: false,
    edit: false,
    refund: false,
});

const hasClaims = computed(() => props.campaign.claims && props.campaign.claims.length > 0);

const editForm = useForm({
    name: props.campaign.name,
    description: props.campaign.description,
    start_date: props.campaign.start_date,
    end_date: props.campaign.end_date,
    txn_msg: props.campaign.txn_msg,
    nmkr_api_key: props.campaign.nmkr_api_key,
    network: props.campaign.network,
    one_per_wallet: props.campaign.one_per_wallet,
});

function openEditDialog() {
    editForm.name = props.campaign.name;
    editForm.description = props.campaign.description;
    editForm.start_date = props.campaign.start_date;
    editForm.end_date = props.campaign.end_date;
    editForm.txn_msg = props.campaign.txn_msg;
    editForm.nmkr_api_key = props.campaign.nmkr_api_key;
    editForm.network = props.campaign.network;
    editForm.one_per_wallet = props.campaign.one_per_wallet;
    dialog.edit = true;
}

function submitEdit() {
    editForm.patch(route('campaigns.update', props.campaign.id), {
        onSuccess: () => {
            dialog.edit = false;
        }
    });
}

const checkingClaims = ref(false);

function checkClaimedStatus() {
    checkingClaims.value = true;
    router.post(route('campaigns.check-claims', props.campaign.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            checkingClaims.value = false;
        }
    });
}

const refundForm = useForm({
    address: '',
});

function submitRefund() {
    refundForm.post(route('campaigns.refund', props.campaign.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.refund = false;
            refundForm.reset();
        }
    });
}

class Paginate {
    constructor(page, limit) {
        this.page = page;
        this.limit = limit;
    }
}

const connectedWalletDetails = reactive({
    utxo: TransactionUnspentOutputs.new(),
    hashes: [],
    checkingBalance: false,
    pager: new Paginate(0, 100),
    selectedUTxO: []
});

const imported = useForm({
    campaign_id: props.campaign.id,
    uploadedCodes: true
});

const file_ref = ref(null);

const code = useForm({
    campaign_id: null,
    uses: 1,
    perWallet: 1,
    lovelace: 1000000,
    tokens: [],
    nmkr_project_uid: '',
    nmkr_count_nft: 0,
});

const token = reactive({
    policy_id: null,
    token_id: null,
    quantity: 1
});

const rules = {
    code: [
        v => !!v || 'Code is required',
        v => {
            if (/^[a-zA-Z0-9_-]+$/.test(v)) {
                return true
            }
            return 'Code can only contain letters, numbers, dashes and underscores!'
        }
    ],
    lovelace: [
        v => {
            if (v < 1000000) {
                return 'Lovelace must be at least 1000000 (1 ADA)';
            }
            if (v > 45000000000000000) {
                return 'Lovelace cannot be greater than total maximum supply!';
            }
            return true;
        }
    ]
};

const qrViewer = reactive({
    show: false,
    code: null,
    code_uri: null
});

function addToken() {
    dialog.token = true;
}

function cancelAddToken() {
    resetToken();
}

function addTokenToCode() {
    code.tokens.push(JSON.parse(JSON.stringify(token)));
    resetToken();
}

function resetToken() {
    dialog.token = false;
    token.policy_id = null;
    token.token_id = null;
    token.quantity = 1;
}

function resetCode() {
    code.uses = 1;
    code.perWallet = 1;
    code.tokens = [];
    code.lovelace = 1000000;
    dialog.code = false;
}

function createCode() {
    code.transform(data => ({
            ...data,
            campaign_id: props.campaign.id
        })
    ).post(route('codes.store'), {
        onSuccess: () => {
            code.reset()
            dialog.code = false;
        }
    });
}

function importCodes() {

    console.log(file_ref.value.files[0], imported);

    Vapor.store(file_ref.value.files[0], {
        progress: progress => {
            console.log(`Progress`, progress);
            imported.progress = Math.round(progress * 100);
        }
    }).then(response => {
        console.log(`Import Response`, response);
        imported.transform(data => ({
            ...data,
            file_key: response.key
        })).post(route('codes.store'), {
            onSuccess: () => {
                imported.reset();
                dialog.import = false;
            }
        })
    })

    /*imported.transform(data => ({
        ...data,
        campaign_id: props.campaign.id,
        upload: true
    })).post(route('codes.store'), {
        onSuccess: () => {
            imported.reset();
            dialog.import = false;
        }
    });*/
}

function showQR(code) {
    qrViewer.show = true;
    qrViewer.code = code;
    qrViewer.code_uri = `web+cardano://claim/v1?faucet_url=${props.encoded_claim_url}&code=${code.code}`;
}

const dataTable = reactive({
    headers: [
        {title: 'Code', align: 'start', key: 'code'},
        {title: 'Uses', align: 'start', key: 'uses'},
        {title: 'Per Wallet', align: 'start', key: 'perWallet'},
        {title: 'Lovelace', align: 'start', key: 'lovelace'},
        {title: 'Tokens', align: 'start', key: 'rewards_count'},
        {title: 'Claims', align: 'start', key: 'claims_count'},
        {title: 'Status', align: 'start', key: 'claim_status', sortable: false},
        {title: 'Actions', align: 'end', key: 'id', sortable: false, filterable: false}
    ],
    perPage: 10,
    search: null,
    selected: []
});

const needed_tokens_table = reactive({
    headers: [
        {title: 'Policy', align: 'start', key: 'policy_id'},
        {title: 'Asset', align: 'start', key: 'asset_id'},
        {title: 'Name', align: 'start', key: 'asset_name', value: item => Buffer.from(item.asset_id, 'hex').toString()},
        {title: 'Quantity', align: 'start', key: 'needed'},
    ],
    perPage: 10
});

const token_balance_table = reactive({
    headers: [
        {title: 'Policy', align: 'start', key: 'policy_id'},
        {title: 'Asset', align: 'start', key: 'asset_id'},
        {title: 'Name', align: 'start', key: 'asset_name', value: item => Buffer.from(item.asset_id, 'hex').toString()},
        {title: 'Quantity', align: 'start', key: 'quantity'},
    ],
    perPage: 10
});

const wallet_balance = computed(() => {
    const balance = {
        lovelace: 0n,
        tokens: {},
        policy_count: 0,
        token_count: 0
    };

    props.balance.forEach((utxo) => {
        balance.lovelace += BigInt(utxo.lovelace);
        utxo.nativeAssets.forEach((asset) => {
            if (balance.tokens[asset.policy] === undefined) {
                balance.tokens[asset.policy] = {};
                balance.policy_count++;
            }
            if (balance.tokens[asset.policy][asset.name] === undefined) {
                balance.tokens[asset.policy][asset.name] = BigInt(0);
                balance.token_count++;
            }
            balance.tokens[asset.policy][asset.name] = (BigInt(balance.tokens[asset.policy][asset.name]) + BigInt(asset.amount)).toString();
        });
    });

    balance.lovelace = balance.lovelace.toString();

    // console.log("Wallet balance", balance);

    return balance;
});

const campaign_needs = computed(() => {
    // console.log("Calculating campaign needs?");
    const needed_tokens = {
        lovelace: 0n,
        tokens: {}
    }

    let unclaimed_codes = 0;

    props.campaign.codes.forEach((code) => {
        if (code.uses <= code.claims_count) {
            return;
        }
        unclaimed_codes += Math.max(0, code.uses - code.claims_count);
    });

    for (const [token, quantity] of Object.entries(props.campaign.rewards)) {
        if (token === "lovelace") {
            needed_tokens.lovelace = BigInt(quantity);
        } else {
            const [policy_hex, asset_hex] = token.split('.');
            if (needed_tokens.tokens[policy_hex] === undefined) {
                needed_tokens.tokens[policy_hex] = {};
            }
            needed_tokens.tokens[policy_hex][asset_hex] = BigInt(quantity);
        }
    }

    // props.campaign.codes.forEach((code) => {
    //     if (code.uses === code.claims_count && code.transaction_hash) {
    //         return;
    //     }
    //     unclaimed_codes++;
    //     needed_tokens.lovelace += BigInt(code.lovelace);
    //     if (code.rewards?.length) {
    //         code.rewards.forEach((reward) => {
    //             if (needed_tokens.tokens[reward.policy_hex] === undefined) {
    //                 needed_tokens.tokens[reward.policy_hex] = {};
    //             }
    //             if (needed_tokens.tokens[reward.policy_hex][reward.asset_hex] === undefined) {
    //                 needed_tokens.tokens[reward.policy_hex][reward.asset_hex] = 0n;
    //             }
    //             needed_tokens.tokens[reward.policy_hex][reward.asset_hex] += BigInt(reward.quantity);
    //         });
    //     }
    // });

    needed_tokens.lovelace += BigInt(unclaimed_codes) * BigInt(1200000);

    console.log(needed_tokens);

    return needed_tokens;
});

const wallet_missing = computed(() => {
    console.log("Calculating what's missing from the wallet!", campaign_needs, wallet_balance);
    const lovelace_needed = (campaign_needs.value.lovelace ?? 0n) - BigInt(wallet_balance.value.lovelace ?? 0);
    const needed_tokens = [];
    for (const policy_id in campaign_needs.value.tokens) {
        const tokens = campaign_needs.value.tokens[policy_id];
        for (const asset_id in tokens) {
            const quantity = tokens[asset_id];
            const token_balance = wallet_balance.value.tokens && wallet_balance.value.tokens[policy_id] && wallet_balance.value.tokens[policy_id][asset_id];
            const needed = quantity - BigInt(token_balance ?? 0);
            // console.log(policy_id, asset_id, quantity, needed, token_balance)
            if (needed > 0n) {
                needed_tokens.push({
                    policy_id: policy_id,
                    asset_id: asset_id,
                    needed: needed.toString()
                });
            }
        }
    }
    if (lovelace_needed > 0n || needed_tokens.length) {
        return {
            lovelace: lovelace_needed > 0n ? lovelace_needed.toString() : 0,
            tokens: needed_tokens
        }
    } else {
        return null;
    }
});

const wallet_empty = computed(() => {
    if (wallet_balance.value.lovelace === undefined) {
        return true;
    }
    return (wallet_balance.value.lovelace === '0');
});

const formatted_token_balance = computed(() => {
    const formatted = [];
    for (const policy_id in wallet_balance.value.tokens) {
        const tokens = wallet_balance.value.tokens[policy_id];
        for (const asset_id in tokens) {
            const quantity = tokens[asset_id];
            formatted.push({
                policy_id,
                asset_id,
                quantity
            });
        }
    }
    return formatted;
});

const self = getCurrentInstance().ctx;

onMounted(() => {
    self.checkForCardano();

    const params = new URLSearchParams(window.location.search);
    if (params.get('edit') === '1') {
        openEditDialog();
    }
});

const error = reactive({
    show: false,
    message: null
});

function doError(msg) {
    error.message = msg;
    error.show = true;
}

async function connectTo(wallet) {
    try {
        await self.connect(wallet);
    } catch (e) {
        switch (e.message) {
            case 'no account set':
                doError(`No dApp account set in your wallet. Please set it and try again!`);
                break;
            default:
                doError("Could not connect to your wallet! Please try again!");
                break;
        }
        console.error("Connecting Wallet Error:", e.message);
        disconnect();
        return;
    }

    wallet.loading = true;
    const wallet_network = await self.getWalletNetwork();

    let valid_network = false;

    switch (props.campaign.network) {
        case 'preprod':
            if (wallet_network === 0) {
                valid_network = true;
            }
            break;
        case 'mainnet':
            if (wallet_network === 1) {
                valid_network = true;
            }
            break;
    }

    if (!valid_network) {
        console.error("Wallet connected to invalid network!");
        const needed_network = props.campaign.network === 'mainnet' ? 'Mainnet' : 'Preproduction';
        doError(`The connected wallet is connected to an invalid network. Please use a wallet connected to the Cardano ${needed_network} Network!`);
        wallet.loading = false;
        disconnect();
        return;
    }

    wallet.loading = false;
    dialog.wallet = false;
    dialog.wallet_balance = true;
    await checkBalance();
}

function disconnect() {
    connectedWalletDetails.utxo = TransactionUnspentOutputs.new();
    connectedWalletDetails.hashes = [];
    connectedWalletDetails.pager.page = 0;
    self.changeWallet();
}

async function checkBalance() {
    connectedWalletDetails.checkingBalance = true;
    /**
     * @type Uint8Array[]
     */
    const UTxOs = await self.cardano.Wallet.getUtxos();
    if (UTxOs === null || UTxOs === undefined) {
        console.log("No UTxO found! :(");
        doError(`Sorry, we could not find any UTxO on your wallet. Please check your wallet and try again later!`);
        connectedWalletDetails.checkingBalance = false;
        return;
    }


    (UTxOs).map((utxo) => {
        const UTxO = TransactionUnspentOutput.from_bytes(self.fromHex(utxo));
        const txin = UTxO.input().transaction_id().to_hex() + '#' + UTxO.input().index();
        if (connectedWalletDetails.hashes.includes(txin)) {
            return;
        }
        connectedWalletDetails.utxo.add(UTxO);
        connectedWalletDetails.hashes.push(txin);
        // new_utxo_found++;
    });

    connectedWalletDetails.checkingBalance = false;

    /**
     * @type {Array<TransactionUnspentOutput>}
     */
    const inputs_to_use = self.findInputs(connectedWalletDetails.utxo, {
        lovelace: wallet_missing.value.lovelace,
        assets: wallet_missing.tokens ?? null
    });

    if (!inputs_to_use.length) {
        doError(`Sorry, we could not find any eligible UTxO to use to complete the top up!`);
        console.error("Could not find any inputs to use?! :(");
        return;
    }

    /**
     * @type TransactionBuilder
     */
    const txBuilder = await self.prepareTransaction();
    inputs_to_use.forEach((input) => {

        txBuilder.add_input(
            input.output().address(),
            input.input(),
            input.output().amount()
        );
    });

    txBuilder.add_output(TransactionOutputBuilder
        .new()
        .with_address(Address.from_bech32(props.campaign.wallet.address))
        .next()
        .with_coin(
            BigNum.from_str(wallet_missing.value.lovelace)
        ).build()
    );

    const changeAddress = await self.getChangeAddress();

    try {
        txBuilder.add_change_if_needed(changeAddress);
        const txBuilt = await txBuilder.build();
        const witnessSet = TransactionWitnessSet.new();
        const tx = Transaction.new(txBuilt, witnessSet, null);
        const witness = await self.cardano.Wallet.signTx(tx.to_hex());

        const totalVkeys = Vkeywitnesses.new();
        const addWitness = TransactionWitnessSet.from_hex(witness);
        const addVkeys = addWitness.vkeys();
        if (addVkeys) {
            for (let i = 0; i < addVkeys.len(); i++) {
                totalVkeys.add(addVkeys.get(i));
            }
        }
        witnessSet.set_vkeys(totalVkeys);

        const signed = await Transaction.new(
            tx.body(),
            witnessSet,
            tx.auxiliary_data()
        );

        try {
            const response = await self.cardano.Wallet.submitTx(signed.to_hex());
            console.log(`Tx ID: ${response}`);
        } catch (e) {
            doError("Could not submit the transaction!");
            console.error("Tx Submit Error:", e);
        }
    } catch (e) {
        console.error("Tx Building Error:", e);
    }

}
</script>
<template>
    <Head title="View Campaign"/>
    <AuthenticatedLayout>
        <v-container>
            <v-row justify="center">
                <v-col cols="12" md="10" lg="9" xl="7">
                    <div class="mb-4">
                        <v-alert type="info" v-if="$page.props.flash.message" closeable class="mb-4">
                            {{ $page.props.flash.message }}
                        </v-alert>
                        <v-alert type="error" v-if="$page.props.transaction_backend === 'null'" border="start" class="mb-4" density="compact" icon="mdi-flask-outline">
                            <v-alert-title>TEST MODE</v-alert-title>
                            The transaction backend is set to <strong>null</strong>. Wallet addresses shown are fake.
                            Do NOT send any ADA or tokens to them — they will be lost permanently.
                        </v-alert>
                        <v-alert type="warning" v-if="backend_mismatch" border="start" class="mb-4" density="compact" icon="mdi-swap-horizontal">
                            <v-alert-title>BACKEND MISMATCH</v-alert-title>
                            This wallet was created under the <strong>{{ wallet_backend }}</strong> backend,
                            but the system is currently configured to use <strong>{{ $page.props.transaction_backend }}</strong>.
                            Balance queries, claims, and refunds will still use the original <strong>{{ wallet_backend }}</strong> backend
                            as long as its credentials remain configured.
                        </v-alert>
                        <v-alert type="info" v-if="wallet_pending" border="start" class="mb-4" density="compact" icon="mdi-clock-outline">
                            <v-alert-title>WALLET PROVISIONING</v-alert-title>
                            Your campaign bucket is being provisioned. Refresh to check.
                        </v-alert>
                        <v-row>
                            <v-col cols="12" lg="6">
                                <h3>
                                    {{ campaign.name }}
                                    <v-chip label class="text-capitalize">{{ campaign.network }}</v-chip>
                                    <v-btn size="small" color="primary" variant="tonal" @click="openEditDialog" class="ms-2">
                                        <v-icon icon="mdi-pencil" class="me-1" />
                                        Edit
                                    </v-btn>
                                </h3>
                                <p class="font-italic text-sm text-gray-600 dark:text-gray-400">
                                    {{ campaign.description }}
                                </p>
                                <p>
                                    Redemption Period: {{ campaign.start_date }} through {{ campaign.end_date }}
                                    <v-chip v-if="campaign.one_per_wallet" label size="small" class="ms-2">One Per Wallet</v-chip>
                                </p>
                            </v-col>
                            <v-col cols="12" lg="6">
                                <p>
                                    Claim URL: {{ claim_url }}
                                </p>
                                <p v-if="campaign.wallet">
                                    Wallet Address: {{ campaign.wallet.address }}
                                </p>
                                <p v-if="campaign.txn_msg">
                                    Transaction Message: {{campaign.txn_msg}}
                                </p>
                            </v-col>
                        </v-row>
                    </div>
                    <v-alert type="error" v-if="campaign.wallet && wallet_empty" border="start" class="mb-4" density="compact">
                        <v-alert-title>WALLET IS EMPTY!</v-alert-title>
                    </v-alert>
                    <v-alert type="info" v-else-if="campaign.wallet" border="start" class="mb-4" density="compact">
                        <v-alert-title>WALLET BALANCE</v-alert-title>
                        <p>
                            {{ formatAda(toAda(wallet_balance.lovelace)) }} <small>({{ wallet_balance.lovelace }}
                                                                                   Lovelace)</small> +
                            {{ wallet_balance.token_count }} token from {{ wallet_balance.policy_count }} policy.
                            <v-btn size="small" @click="dialog.show_balance = !dialog.show_balance"
                                   v-if="wallet_balance.token_count" class="ms-2">
                                {{ dialog.show_balance ? 'Hide' : 'Show' }} Details
                            </v-btn>
                        </p>
                        <div v-if="dialog.show_balance && wallet_balance.token_count" class="py-4">
                            <v-data-table :items="formatted_token_balance" :items-per-page="token_balance_table.perPage"
                                          :headers="token_balance_table.headers"></v-data-table>
                        </div>
                    </v-alert>
                    <v-alert type="error" v-if="campaign.wallet && wallet_missing" border="start" class="mb-4" density="compact">
                        <v-alert-title>INSUFFICIENT BALANCE!</v-alert-title>
                        <p class="my-2">
                            <v-btn color="black" size="small" @click="dialog.missing = !dialog.missing" class="me-2">
                                {{ dialog.missing ? 'Hide' : 'Show' }} Details
                            </v-btn>
                            <v-btn color="black" size="small" class="me-2" @click="dialog.wallet = true"
                                   v-if="cardano.status === 'found'">Top Up
                            </v-btn>
                        </p>
                        <div v-if="dialog.missing">
                            <p class="mb-2">
                                Lovelace needed: {{ formatAda(toAda(wallet_missing.lovelace)) }}
                                <small>({{ wallet_missing.lovelace }} Lovelace)</small>
                            </p>
                            <p class="mb-2">
                                Tokens Needed:
                                <v-chip label v-if="wallet_missing.tokens.length === 0">None</v-chip>
                            </p>
                            <v-data-table :items="wallet_missing.tokens" :items-per-page="needed_tokens_table.perPage"
                                          v-if="wallet_missing.tokens.length"
                                          :headers="needed_tokens_table.headers"></v-data-table>
                        </div>
                    </v-alert>
                    <v-toolbar color="transparent">
                        <v-toolbar-title>Campaign Codes</v-toolbar-title>
                        <v-spacer></v-spacer>
                        <v-btn color="primary" @click="dialog.code = true">
                            <v-icon icon="mdi-plus"></v-icon>
                            Create Code
                        </v-btn>
                        <v-btn color="secondary" @click="dialog.import = true">
                            <v-icon icon="mdi-cloud-upload"></v-icon>
                            Import Codes
                        </v-btn>
                        <v-btn color="accent" @click="checkClaimedStatus" :loading="checkingClaims">
                            <v-icon icon="mdi-reload"></v-icon>
                            Check Claimed
                        </v-btn>
                        <v-btn color="warning" @click="dialog.refund = true" v-if="!wallet_empty">
                            <v-icon icon="mdi-cash-refund"></v-icon>
                            Refund Bucket
                        </v-btn>
                    </v-toolbar>
                    <v-text-field v-model="dataTable.search" append-icon="mdi-magnify" label="Search" single-line
                                  hide-details></v-text-field>
                    <v-data-table :items="campaign.codes" :items-per-page="dataTable.perPage"
                                  v-model="dataTable.selected" :search="dataTable.search" :headers="dataTable.headers"
                                  return-object show-select>
                        <template v-slot:item.id="{ item }">
                            <v-btn color="primary" @click="showQR(item.columns)">
                                <v-icon icon="mdi-qrcode"></v-icon>
                            </v-btn>
                        </template>
                    </v-data-table>
                </v-col>
            </v-row>
        </v-container>
        <v-dialog v-model="dialog.code" width="auto" transition="dialog-bottom-transition" persistent>
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Create New Code</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.code = false">
                        <v-icon icon="mdi-close"/>
                    </v-btn>
                </v-toolbar>
                <v-card-text>
                    This will create a new code for this campaign. Please fill out the information below to create the
                    code.
                </v-card-text>
                <v-form @submit.prevent="createCode">
                    <v-card-text>
                        <v-text-field label="Uses" v-model="code.uses" required min="0" step="1" type="number"
                                      hint="Set this to 0 to create a code with unlimited uses. This is not recommended!"
                                      persistent-hint/>
                        <v-text-field label="Claims Per Wallet" v-model="code.perWallet" required min="0" step="1"
                                      type="number"
                                      hint="Set this to 1 to limit the code to one-per-wallet claiming. Set to 0 for unlimited claims per wallet [NOT RECOMMENDED]."
                                      persistent-hint/>
                        <v-text-field label="Lovelace" v-model="code.lovelace" required min="1000000" step="1"
                                      type="number" hint="The amount of Lovelace to send when this code is claimed."
                                      persistent-hint :rules="rules.lovelace"/>
                        <v-text-field label="NMKR Project UID" v-model="code.nmkr_project_uid"
                                      hint="Optional — NMKR project UID to mint an NFT on claim. Leave blank to skip."
                                      persistent-hint/>
                        <v-text-field label="NFTs Per Claim" v-model="code.nmkr_count_nft" min="0" step="1"
                                      type="number"
                                      hint="Number of NFTs to mint per claim from the NMKR project. Set to 0 to disable."
                                      persistent-hint/>
                    </v-card-text>
                    <v-card-text v-if="Object.keys($page.props.errors).length">
                        <v-alert type="error" title="Errors">
                            <p v-for="error in $page.props.errors" :key="error">
                                {{ error }}
                            </p>
                        </v-alert>
                    </v-card-text>
                    <v-card-title>Tokens</v-card-title>
                    <v-card-text v-if="code.tokens.length === 0">Currently no tokens included!</v-card-text>
                    <v-table v-if="code.tokens.length">
                        <thead>
                        <tr>
                            <th>Policy</th>
                            <th>Asset</th>
                            <th>Quantity</th>
                            <th>&nbsp;</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="token in code.tokens">
                            <td>{{ token.policy_id }}</td>
                            <td>{{ token.token_id }}</td>
                            <td>{{ token.quantity }}</td>
                            <td>
                                <v-btn color="red" icon class="m-2"
                                       @click="code.tokens.splice(code.tokens.indexOf(token), 1)">
                                    <v-icon icon="mdi-trash-can"/>
                                </v-btn>
                            </td>
                        </tr>
                        </tbody>
                    </v-table>
                    <v-card-actions>
                        <v-btn type="button" color="dark" @click="addToken" :disabled="code.processing">
                            Add Token
                        </v-btn>
                        <v-btn type="submit" color="primary" :disabled="code.processing">
                            Create Code
                        </v-btn>
                        <v-btn type="button" color="red" :disabled="code.processing" @click="resetCode()">
                            Cancel
                        </v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog v-model="dialog.token" width="auto" transition="dialog-top-transition" persistent>
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Add Token to Code</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.token = false">
                        <v-icon icon="mdi-close"/>
                    </v-btn>
                </v-toolbar>
                <v-card-text>
                    The specified token will be added to this code and distributed whenever the code is claimed.
                </v-card-text>
                <v-form fast-fail @submit.prevent @submit="addTokenToCode">
                    <v-card-text>
                        <v-text-field label="Policy ID" v-model="token.policy_id" required/>
                        <v-text-field label="Token ID" v-model="token.token_id" required/>
                        <v-text-field label="Quantity" v-model="token.quantity" required min="1" step="1"
                                      type="number"/>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn type="submit" color="primary">Add Token</v-btn>
                        <v-btn type="button" color="red" @click="cancelAddToken">Cancel</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog v-model="qrViewer.show" width="auto" transition="dialog-bottom-transition">
            <v-card>
                <v-card-text>
                    <qrcode-vue :value="qrViewer.code_uri" :size="512" render-as="svg" margin="5"></qrcode-vue>
                </v-card-text>
            </v-card>
        </v-dialog>
        <v-dialog v-model="dialog.import" width="512" transition="dialog-bottom-transition">
            <v-card>
                <v-toolbar color="secondary">
                    <v-toolbar-title>Import Codes</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.import = false">
                        <v-icon icon="mdi-close"/>
                    </v-btn>
                </v-toolbar>
                <v-card-text>
                    Use this form to upload a JSON file containing codes + token rewards.
                </v-card-text>
                <v-form @submit.prevent="importCodes">
                    <v-card-text>
                        <!--                        <v-file-input v-model="imported.uploadedCodes" accept=".json" label="Codes File" required
                                                              name="uploadedCodes" :multiple="false"></v-file-input>-->
                        <v-file-input accept=".json" :label="`Codes File (max ${maxFileSizeMB}MB)`" required name="uploadedCodes" :multiple="false"
                                      id="import_file" ref="file_ref"
                                      :rules="[v => !v || !v.length || v[0].size < props.max_file_size || `File must be less than ${maxFileSizeMB}MB`]"></v-file-input>
                        <v-progress-linear height="12" color="primary" v-if="imported.progress"
                                           v-model="imported.progress"></v-progress-linear>
                        <!--                        <progress v-if="imported.progress" :value="imported.progress.percentage" max="100">-->
                        <!--                            {{ imported.progress.percentage }}%-->
                        <!--                        </progress>-->
                    </v-card-text>
                    <v-card-actions>
                        <v-btn type="submit" color="primary">Import</v-btn>
                        <v-btn type="button" color="red" @click="imported.reset(); dialog.import = false">Cancel</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog v-model="dialog.wallet" width="512" transition="dialog-bottom-transition" persistent scrollable>
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Connect Your Wallet</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.wallet = false">
                        <v-icon icon="mdi-close"/>
                    </v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-card-text>
                    Please choose the wallet you'd like to connect
                    <div class="pt-5">
                        <v-btn v-for="wallet in cardano.Wallets" :key="wallet.name" block
                               class="wallet-btn mb-2 text-start" x-large @click="connectTo(wallet)"
                               :loading="wallet.loading">
                            <v-img :src="wallet.icon" width="24" height="24" class="me-2" contain
                                   :alt="wallet.name"></v-img>
                            Connect {{ wallet.name.replace(" Wallet", "") }}
                        </v-btn>
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>
        <v-dialog v-model="dialog.wallet_balance" width="auto" min-width="512" transition="dialog-bottom-transition"
                  persistent scrollable>
            <v-card>
                <v-toolbar>
                    <v-toolbar-title>Find Available Assets</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.wallet_balance = false">
                        <v-icon icon="mdi-close"/>
                    </v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-card-text>
                    <v-progress-linear indeterminate height="24" color="primary"
                                       v-if="connectedWalletDetails.checkingBalance"></v-progress-linear>
                    <pre>{{ connectedWalletDetails }}</pre>
                </v-card-text>
            </v-card>
        </v-dialog>
        <v-dialog v-model="error.show" width="512" transition="dialog-bottom-transition" persistent>
            <v-card color="">
                <v-toolbar color="transparent">
                    <v-toolbar-title>
                        <v-icon icon="mdi-alert"/>
                        ERROR
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="error.show = false">
                        <v-icon icon="mdi-close"/>
                    </v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-card-text>{{ error.message }}</v-card-text>
                <v-card-actions>
                    <v-btn @click="error.show = false">OKAY</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
        <v-dialog v-model="dialog.refund" width="500" transition="dialog-bottom-transition" persistent>
            <v-card>
                <v-toolbar color="warning">
                    <v-toolbar-title>Refund Bucket</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.refund = false">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-form @submit.prevent="submitRefund">
                    <v-card-text>
                        <p class="mb-4">Enter the Cardano address where remaining bucket contents should be sent.</p>
                        <v-text-field label="Destination Address" v-model="refundForm.address" required
                                      placeholder="addr1... or addr_test1..."
                                      :error-messages="refundForm.errors.address" />
                    </v-card-text>
                    <v-card-actions>
                        <v-btn type="submit" color="warning" :disabled="refundForm.processing">Refund</v-btn>
                        <v-btn color="red" @click="dialog.refund = false; refundForm.reset()" :disabled="refundForm.processing">Cancel</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog v-model="dialog.edit" width="600" transition="dialog-bottom-transition" persistent>
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Edit Campaign</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.edit = false">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-form @submit.prevent="submitEdit">
                    <v-card-text>
                        <v-text-field label="Campaign Name" v-model="editForm.name" required
                                      :error-messages="editForm.errors.name" />
                        <v-textarea label="Description" v-model="editForm.description"
                                    :error-messages="editForm.errors.description" />
                        <v-text-field label="Start Date" type="date" v-model="editForm.start_date" required
                                      :error-messages="editForm.errors.start_date" />
                        <v-text-field label="End Date" type="date" v-model="editForm.end_date" required
                                      :error-messages="editForm.errors.end_date" />
                        <v-text-field label="Transaction Message" v-model="editForm.txn_msg" counter="64"
                                      hint="Optional message included in claim transactions (max 64 chars)"
                                      persistent-hint :error-messages="editForm.errors.txn_msg" />
                        <v-text-field label="NMKR API Key" v-model="editForm.nmkr_api_key"
                                      hint="Optional — your NMKR Studio API key for NFT minting"
                                      persistent-hint :error-messages="editForm.errors.nmkr_api_key" />
                        <v-select label="Network" v-model="editForm.network"
                                  :items="['preprod', 'preview', 'mainnet']"
                                  :disabled="hasClaims"
                                  :hint="hasClaims ? 'Locked — campaign has existing claims' : ''"
                                  persistent-hint :error-messages="editForm.errors.network" />
                        <v-checkbox label="One Per Wallet" v-model="editForm.one_per_wallet"
                                    :disabled="hasClaims"
                                    :hint="hasClaims ? 'Locked — campaign has existing claims' : ''"
                                    persistent-hint />
                    </v-card-text>
                    <v-card-text v-if="Object.keys(editForm.errors).length">
                        <v-alert type="error" title="Errors">
                            <p v-for="(error, key) in editForm.errors" :key="key">{{ error }}</p>
                        </v-alert>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn type="submit" color="primary" :disabled="editForm.processing">Save Changes</v-btn>
                        <v-btn color="red" @click="dialog.edit = false" :disabled="editForm.processing">Cancel</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <!--        <pre>{{ campaign }}</pre>-->
    </AuthenticatedLayout>
</template>
