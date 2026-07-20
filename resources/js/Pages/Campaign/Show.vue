<script setup>
import {Head, useForm, router} from '@inertiajs/vue3';
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import {computed, getCurrentInstance, onMounted, reactive, ref} from "vue";
import QrcodeVue from "qrcode.vue";
import {Transaction} from "@meshsdk/core";
import CampaignCharts from "@/Components/CampaignCharts.vue";
import WalletTokenList from "@/Components/WalletTokenList.vue";
import QrExportDialog from "@/Components/QrExportDialog.vue";
import {knownAssetLabel, assetToToken, toBaseUnits} from "@/utils/knownAssets.js";

const props = defineProps({
    flash: Object,
    campaign: Object,
    stats: {
        type: Object,
        default: () => ({}),
    },
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
    gd_available: {
        type: Boolean,
        default: true,
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
    qr_export: false,
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

const connectedWalletDetails = reactive({
    utxos: [],
    checkingBalance: false,
});

const imported = useForm({
    campaign_id: props.campaign.id,
    uploadedCodes: true
});

const file_ref = ref(null);

const code = useForm({
    campaign_id: null,
    quantity: 1,
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
    quantity: 1,
    decimals: 0, // decimals of the selected/looked-up token; drives the amount input + conversion
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
    ],
    quantity: [
        v => {
            const n = Number(v);
            if (!Number.isInteger(n) || n < 1) {
                return 'Quantity must be a whole number of at least 1';
            }
            if (n > 500) {
                return 'Quantity cannot exceed 500 codes per batch';
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
    searchKnownAssets('');
}

function cancelAddToken() {
    resetToken();
}

function addTokenToCode() {
    // For a decimal token the user enters a human amount (e.g. 10 USDM); convert to the
    // on-chain base-unit integer that is actually distributed (10 * 10^6 = 10000000).
    const baseUnits = toBaseUnits(token.quantity, token.decimals);

    code.tokens.push({
        policy_id: token.policy_id,
        token_id: token.token_id,
        quantity: baseUnits,
    });
    // Ensure the row can render a human name/decimals even if entered manually.
    resolveTokenMeta({policy_hex: token.policy_id, asset_hex: token.token_id});
    resetToken();
}

function removeCodeToken(index) {
    code.tokens.splice(index, 1);
}

function resetToken() {
    dialog.token = false;
    token.policy_id = null;
    token.token_id = null;
    token.quantity = 1;
    token.decimals = 0;
    knownAssetSearch.items = [];
    knownAssetSearch.selected = null;
    tokenLookup.result = null;
    tokenLookup.error = null;
}

// --- Known-asset import (Feature 1c) --------------------------------------
// Lets users add reward tokens like "HOSKY"/"USDM" by ticker/name without
// typing hex, backed by the Koios-fed known_assets registry.
const knownAssetSearch = reactive({
    loading: false,
    selected: null,
    items: [],
});

const tokenLookup = reactive({
    loading: false,
    result: null,
    error: null,
});

function searchKnownAssets(query) {
    knownAssetSearch.loading = true;
    window.axios.get(route('known-assets.index'), {
        params: { q: query || '', network: props.campaign.network },
    }).then(({ data }) => {
        knownAssetSearch.items = data;
    }).catch(() => {
        knownAssetSearch.items = [];
    }).finally(() => {
        knownAssetSearch.loading = false;
    });
}

function onKnownAssetSelect(asset) {
    if (!asset) return;
    const mapped = assetToToken(asset);
    token.policy_id = mapped.policy_id;
    token.token_id = mapped.token_id;
    token.decimals = mapped.meta.decimals || 0;
    tokenLookup.result = asset;
    tokenLookup.error = null;
    // Cache metadata so the reward-detail view shows ticker/decimals immediately.
    tokenMeta.value[mapped.policy_id + mapped.token_id] = mapped.meta;
}

// Resolve a manually-entered policy/asset against Koios, then save it to the
// shared registry so it's reusable by ticker next time.
function lookupTokenOnChain() {
    if (!token.policy_id) {
        tokenLookup.error = 'Enter a Policy ID first.';
        return;
    }
    tokenLookup.loading = true;
    tokenLookup.error = null;
    window.axios.get(route('known-assets.lookup'), {
        params: {
            policy: token.policy_id,
            asset_name: token.token_id || '',
            network: props.campaign.network,
        },
    }).then(({ data }) => {
        tokenLookup.result = data;
        token.token_id = data.asset_name || token.token_id;
        token.decimals = data.decimals || 0;
        tokenMeta.value[data.policy_id + (data.asset_name || '')] = {
            name: data.name,
            ticker: data.ticker,
            decimals: data.decimals || 0,
            logo: data.logo || null,
        };
        // Persist to the registry for reuse (best-effort).
        window.axios.post(route('known-assets.store'), {
            policy_id: data.policy_id,
            asset_name: data.asset_name || '',
            ticker: data.ticker,
            name: data.name,
            fingerprint: data.fingerprint,
            decimals: data.decimals || 0,
            logo: data.logo,
            description: data.description,
            network: props.campaign.network,
        }).catch(() => { /* non-fatal */ });
    }).catch((err) => {
        tokenLookup.result = null;
        tokenLookup.error = err?.response?.status === 404
            ? 'Asset not found in the registry for this network.'
            : 'Lookup failed. You can still enter the values manually.';
    }).finally(() => {
        tokenLookup.loading = false;
    });
}

function resetCode() {
    code.quantity = 1;
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

const codeFilter = ref('all');

const filteredCodes = computed(() => {
    if (!props.campaign.codes) return [];
    if (codeFilter.value === 'all') return props.campaign.codes;
    if (codeFilter.value === 'claimed') return props.campaign.codes.filter(c => c.claims_count > 0);
    if (codeFilter.value === 'unclaimed') return props.campaign.codes.filter(c => c.claims_count === 0);
    if (codeFilter.value === 'available') return props.campaign.codes.filter(c => c.uses === 0 || c.claims_count < c.uses);
    if (codeFilter.value === 'exhausted') return props.campaign.codes.filter(c => c.uses > 0 && c.claims_count >= c.uses);
    return props.campaign.codes;
});

const dataTable = reactive({
    headers: [
        {title: 'Code', align: 'start', key: 'code'},
        {title: 'Uses', align: 'start', key: 'uses'},
        {title: 'Per Wallet', align: 'start', key: 'perWallet'},
        {title: 'Lovelace', align: 'start', key: 'lovelace'},
        {title: 'Tokens', align: 'start', key: 'rewards_count'},
        {title: 'Claims', align: 'start', key: 'claims_count'},
        {title: 'Actions', align: 'end', key: 'id', sortable: false, filterable: false}
    ],
    perPage: 10,
    search: null,
    selected: []
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

// A campaign whose redemption window has closed. Claims are rejected server-side once
// ended, so funding the bucket or adding codes is futile — these drive disabled states.
// `status` is appended on the model, so it's already on the campaign prop.
const isEnded = computed(() => props.campaign.status === 'ended');

// No shortfall for the needs-based top-up to send. This is null both when the bucket is
// genuinely funded AND when it's empty with nothing outstanding (e.g. refunded, or no
// codes yet) — so it means "nothing to top up", not "funded". Drives the disabled state.
const noShortfall = computed(() => wallet_missing.value === null);

// Genuinely funded: covers the codes' needs AND actually holds a balance. An empty/
// refunded bucket is NOT funded even though it has no shortfall.
const isFunded = computed(() => noShortfall.value && !wallet_empty.value);

// Explains why Top Up is disabled instead of leaving a dead-looking button, and never
// calls an empty bucket "funded".
const topUpTooltip = computed(() => {
    if (isEnded.value) return 'Campaign has ended — funding is closed';
    if (isFunded.value) return 'Bucket is already fully funded';
    if (noShortfall.value) return 'Nothing to fund right now';
    return 'Top Up';
});

// Compact, glanceable bucket status for the chip beside the wallet address. The loud
// directive card is reserved for the one state that needs action (still-open + underfunded);
// every other state collapses to this badge, with the balance available on click.
const bucketBadge = computed(() => {
    if (wallet_missing.value) return { label: 'Underfunded', color: 'warning', icon: 'mdi-progress-wrench' };
    if (wallet_empty.value) return { label: 'Empty', color: 'grey', icon: 'mdi-wallet-outline' };
    return { label: 'Funded', color: 'success', icon: 'mdi-check-decagram' };
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

const self = getCurrentInstance().proxy;

// Funding/status card can be collapsed (but never dismissed) to reclaim space.
const fundingOpen = ref(true);

// Copy-to-clipboard for long values (addresses, claim URL) that would otherwise
// overflow — see the truncating fields in the header.
const snackbar = reactive({show: false, text: ''});

function copyText(text) {
    if (!text) return;
    navigator.clipboard?.writeText(text).then(() => {
        snackbar.text = 'Copied to clipboard';
        snackbar.show = true;
    }).catch(() => {
        snackbar.text = 'Could not copy';
        snackbar.show = true;
    });
}

// --- Reward token detail (Feature 1b) -------------------------------------
// Lazily resolves human-readable metadata (ticker/decimals/logo) for reward
// tokens via the Koios-backed known-assets endpoint, cached per subject.
const tokenMeta = ref({});

function tokenSubject(reward) {
    return `${reward.policy_hex}${reward.asset_hex}`;
}

function resolveTokenMeta(reward) {
    const subject = tokenSubject(reward);
    if (!reward.policy_hex || tokenMeta.value[subject] !== undefined) {
        return;
    }
    // Placeholder so we don't fire duplicate requests while in flight.
    tokenMeta.value[subject] = { name: self.hexToString(reward.asset_hex), ticker: null, decimals: 0 };

    window.axios.get(route('known-assets.lookup'), {
        params: {
            policy: reward.policy_hex,
            asset_name: reward.asset_hex,
            network: props.campaign.network,
        },
    }).then(({ data }) => {
        tokenMeta.value[subject] = {
            name: data.name || self.hexToString(reward.asset_hex),
            ticker: data.ticker || null,
            decimals: data.decimals || 0,
            logo: data.logo || null,
        };
    }).catch(() => {
        // Keep the hex-decoded fallback already set above.
    });
}

function rewardDisplayName(reward) {
    const meta = tokenMeta.value[tokenSubject(reward)];
    if (meta) {
        return meta.ticker || meta.name;
    }
    return self.hexToString(reward.asset_hex);
}

function rewardDecimals(reward) {
    const meta = tokenMeta.value[tokenSubject(reward)];
    return meta ? (meta.decimals || 0) : 0;
}

function rewardDisplayQuantity(reward) {
    const decimals = rewardDecimals(reward);
    if (!decimals) {
        return Number(reward.quantity).toLocaleString();
    }
    // Clean, trimmed amount; the decimals chip signals it's a decimal-denominated token.
    return (Number(reward.quantity) / 10 ** decimals).toLocaleString(undefined, {
        maximumFractionDigits: decimals,
    });
}

// The raw on-chain amount (base units) — what is actually transferred. For a token with
// decimals, this differs from the human-readable display amount.
function rewardRawQuantity(reward) {
    return Number(reward.quantity).toLocaleString();
}

function loadRewardMeta(code) {
    (code.rewards || []).forEach(resolveTokenMeta);
}

// --- Wallet status token display (Feature: branded wallet cards) ----------
// The wallet balance / "still needed" computeds key tokens by {policy_id, asset_id};
// map them into resolved display rows (name/logo/decimals/amount) for WalletTokenList,
// reusing the same Koios-backed tokenMeta cache as the reward details.
function walletDisplayToken(policyId, assetId, rawAmount) {
    const meta = tokenMeta.value[`${policyId}${assetId}`];
    const decimals = meta ? (meta.decimals || 0) : 0;
    const raw = Number(rawAmount);
    return {
        policy: policyId,
        asset: assetId,
        name: meta ? (meta.ticker || meta.name) : self.hexToString(assetId),
        logo: meta ? meta.logo : null,
        decimals,
        raw: raw.toLocaleString(),
        amount: decimals
            ? (raw / 10 ** decimals).toLocaleString(undefined, {maximumFractionDigits: decimals})
            : raw.toLocaleString(),
    };
}

const missingTokens = computed(
    () => (wallet_missing.value?.tokens ?? []).map(t => walletDisplayToken(t.policy_id, t.asset_id, t.needed)),
);

const balanceTokens = computed(
    () => formatted_token_balance.value.map(t => walletDisplayToken(t.policy_id, t.asset_id, t.quantity)),
);

// Tokens staged on the code being created (stored as base-unit quantities), resolved to
// human names/decimals for display in the create-code dialog.
const codeTokensDisplay = computed(
    () => code.tokens.map(t => walletDisplayToken(t.policy_id, t.token_id, t.quantity)),
);

// Live "= N base units" preview for the amount input when a decimal token is selected.
const tokenBaseUnitsPreview = computed(() => {
    if (!(token.decimals > 0) || !token.quantity) {
        return null;
    }
    return Math.round(Number(token.quantity) * 10 ** token.decimals).toLocaleString();
});

function loadWalletTokenMeta() {
    (wallet_missing.value?.tokens ?? []).forEach(t => resolveTokenMeta({policy_hex: t.policy_id, asset_hex: t.asset_id}));
    formatted_token_balance.value.forEach(t => resolveTokenMeta({policy_hex: t.policy_id, asset_hex: t.asset_id}));
}

onMounted(() => {
    self.checkForCardano();

    // Pre-resolve reward-token metadata (deduped client-side, cached server-side)
    // so the per-code reward details are ready when a row is expanded.
    (props.campaign.codes || []).forEach(loadRewardMeta);
    // ...and the wallet balance / needed tokens shown in the status cards.
    loadWalletTokenMeta();

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
                doError('No dApp account set in your wallet. Please set it and try again!');
                break;
            default:
                doError('Could not connect to your wallet! Please try again!');
                break;
        }
        console.error('Connecting Wallet Error:', e.message);
        disconnect();
        return;
    }

    wallet.loading = true;
    const wallet_network = await self.getWalletNetwork();

    const expectedNetwork = props.campaign.network === 'mainnet' ? 1 : 0;
    if (wallet_network !== expectedNetwork) {
        const needed_network = props.campaign.network === 'mainnet' ? 'Mainnet' : 'Preproduction';
        doError(`The connected wallet is on the wrong network. Please use a wallet connected to the Cardano ${needed_network} Network!`);
        wallet.loading = false;
        disconnect();
        return;
    }

    wallet.loading = false;
    dialog.wallet = false;
    dialog.wallet_balance = true;
    await buildTopUpTransaction();
}

function disconnect() {
    connectedWalletDetails.utxos = [];
    self.changeWallet();
}

async function buildTopUpTransaction() {
    connectedWalletDetails.checkingBalance = true;

    try {
        const utxos = await self.getUtxos();
        if (!utxos || utxos.length === 0) {
            doError('No UTxOs found in your wallet. Please check your wallet and try again.');
            connectedWalletDetails.checkingBalance = false;
            return;
        }

        connectedWalletDetails.utxos = utxos;
        connectedWalletDetails.checkingBalance = false;

        // Build the top-up transaction using MeshJS
        const campaignAddress = props.campaign.wallet.address;
        const missing = wallet_missing.value;

        // wallet_missing returns null when nothing is needed; lovelace can be the
        // number 0 (when only tokens are missing) or a string of needed lovelaces.
        const lovelaceNeeded = missing && missing.lovelace && missing.lovelace !== '0' && missing.lovelace !== 0;
        const tokensNeeded = missing && Array.isArray(missing.tokens) && missing.tokens.length > 0;

        if (!missing || (!lovelaceNeeded && !tokensNeeded)) {
            // Nothing to top up — wallet is already funded
            return;
        }

        // Summarize what the connected wallet actually holds so we don't try
        // to send tokens the user doesn't have.
        const holdings = { tokens: {} };
        for (const u of utxos) {
            for (const a of u.output.amount) {
                if (a.unit === 'lovelace') continue;
                holdings.tokens[a.unit] = (holdings.tokens[a.unit] ?? 0n) + BigInt(a.quantity);
            }
        }

        // Split missing tokens into what the user can actually send vs what
        // they don't have (or don't have enough of).
        const sendableTokens = [];
        const unsendableTokens = [];
        if (tokensNeeded) {
            for (const token of missing.tokens) {
                const unit = token.policy_id + token.asset_id;
                const available = holdings.tokens[unit] ?? 0n;
                const needed = BigInt(token.needed);
                if (available >= needed) {
                    sendableTokens.push({ ...token, sending: needed.toString() });
                } else if (available > 0n) {
                    sendableTokens.push({ ...token, sending: available.toString() });
                    unsendableTokens.push({ ...token, shortfall: (needed - available).toString() });
                } else {
                    unsendableTokens.push({ ...token, shortfall: needed.toString() });
                }
            }
        }

        // If anything is missing from the connected wallet, ask the user whether
        // to proceed with a partial top-up.
        if (unsendableTokens.length > 0) {
            const list = unsendableTokens
                .map(t => `  • policy ${t.policy_id.slice(0, 12)}… asset ${t.asset_id.slice(0, 12)}…  short by ${t.shortfall}`)
                .join('\n');
            const proceed = confirm(
                `Your connected wallet is missing some required tokens:\n\n${list}\n\n`
                + `Top up what you have now and send the missing tokens later?`
            );
            if (!proceed) {
                connectedWalletDetails.checkingBalance = false;
                return;
            }
        }

        // If after the split there's literally nothing left to send, bail out.
        const willSendLovelace = !!lovelaceNeeded;
        const willSendTokens = sendableTokens.length > 0;
        if (!willSendLovelace && !willSendTokens) {
            doError('Your wallet has none of the required tokens. Top up cancelled.');
            return;
        }

        const tx = new Transaction({ initiator: self.cardano.Wallet });

        // Create a separate UTxO for ADA (lovelace)
        if (willSendLovelace) {
            tx.sendLovelace(campaignAddress, missing.lovelace.toString());
        }

        // Create a separate UTxO for each token policy/asset pair —
        // Phyrhose expects one asset class per UTxO.
        for (const token of sendableTokens) {
            tx.sendAssets(campaignAddress, [
                {
                    unit: token.policy_id + token.asset_id,
                    quantity: token.sending,
                },
            ]);
        }

        const unsignedTx = await tx.build();
        const signedTx = await self.cardano.Wallet.signTx(unsignedTx);
        const txHash = await self.cardano.Wallet.submitTx(signedTx);

        console.log(`Top-up Tx Hash: ${txHash}`);
        dialog.show_toast = true;
    } catch (e) {
        if (e.message && e.message.includes('user')) {
            // User declined to sign
            console.log('User declined transaction signing.');
        } else {
            doError('Could not complete the top-up transaction. Please try again.');
            console.error('Top-up Error:', e);
        }
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
                        <p v-if="campaign.txn_msg">
                            Transaction Message: {{campaign.txn_msg}}
                        </p>
                        <!-- Claim URL + wallet address shown in full (never truncated) so users can
                             verify every character before copying/sending. Values are rendered as
                             escaped text (no v-html), so they can't inject markup or script. -->
                        <div class="mt-3">
                            <div class="text-medium-emphasis text-caption">Claim URL</div>
                            <div class="d-flex align-start">
                                <code class="font-mono text-body-2 flex-grow-1" style="word-break: break-all">{{ claim_url }}</code>
                                <v-btn icon variant="text" size="x-small" class="ms-1 flex-shrink-0" @click="copyText(claim_url)">
                                    <v-icon icon="mdi-content-copy" size="small"></v-icon>
                                    <v-tooltip activator="parent" location="top">Copy claim URL</v-tooltip>
                                </v-btn>
                            </div>
                        </div>
                        <div v-if="campaign.wallet" class="mt-2">
                            <div class="text-medium-emphasis text-caption d-flex align-center ga-2">
                                Wallet Address
                                <v-menu location="bottom start" :close-on-content-click="false">
                                    <template #activator="{ props }">
                                        <v-chip v-bind="props" :color="bucketBadge.color" size="x-small" label link
                                                :aria-label="`Bucket ${bucketBadge.label} — view contents`">
                                            <v-icon start :icon="bucketBadge.icon" size="x-small"></v-icon>{{ bucketBadge.label }}
                                        </v-chip>
                                    </template>
                                    <v-card min-width="260" rounded="lg" border>
                                        <v-list density="compact" class="py-1">
                                            <v-list-subheader>Bucket contents</v-list-subheader>
                                            <v-list-item prepend-icon="mdi-cardano"
                                                         :title="`${formatAda(toAda(wallet_balance.lovelace))} ADA`">
                                                <v-list-item-subtitle v-if="wallet_balance.token_count">
                                                    {{ wallet_balance.token_count }} token{{ wallet_balance.token_count === 1 ? '' : 's' }}
                                                    across {{ wallet_balance.policy_count }} polic{{ wallet_balance.policy_count === 1 ? 'y' : 'ies' }}
                                                </v-list-item-subtitle>
                                                <v-list-item-subtitle v-else>No tokens held</v-list-item-subtitle>
                                            </v-list-item>
                                        </v-list>
                                        <template v-if="wallet_balance.token_count">
                                            <v-divider></v-divider>
                                            <div class="pa-2"><WalletTokenList :tokens="balanceTokens"/></div>
                                        </template>
                                        <template v-if="wallet_missing">
                                            <v-divider></v-divider>
                                            <v-list density="compact" class="py-1">
                                                <v-list-subheader class="text-warning">Still needed</v-list-subheader>
                                                <v-list-item prepend-icon="mdi-progress-wrench"
                                                             :title="`${formatAda(toAda(wallet_missing.lovelace))} ADA`"
                                                             :subtitle="missingTokens.length ? `${missingTokens.length} token type${missingTokens.length === 1 ? '' : 's'}` : null" />
                                            </v-list>
                                        </template>
                                    </v-card>
                                </v-menu>
                            </div>
                            <div class="d-flex align-start">
                                <code class="font-mono text-body-2 flex-grow-1" style="word-break: break-all">{{ campaign.wallet.address }}</code>
                                <v-btn icon variant="text" size="x-small" class="ms-1 flex-shrink-0" @click="copyText(campaign.wallet.address)">
                                    <v-icon icon="mdi-content-copy" size="small"></v-icon>
                                    <v-tooltip activator="parent" location="top">Copy wallet address</v-tooltip>
                                </v-btn>
                            </div>
                        </div>
                    </div>
                    <!-- The one loud, directive state: still-open campaign that needs funding.
                         Funded / empty / ended states collapse to the badge by the address. -->
                    <v-card v-if="campaign.wallet && wallet_missing && !isEnded" variant="tonal" color="warning"
                            class="mb-4" rounded="lg" border>
                        <v-card-item>
                            <template #prepend>
                                <v-icon icon="mdi-progress-wrench" size="large"></v-icon>
                            </template>
                            <v-card-title>{{ wallet_empty ? 'Fund your campaign bucket' : "Almost there — top up what's left" }}</v-card-title>
                            <v-card-subtitle class="text-wrap">
                                Add the amounts below so every unclaimed code can pay out its reward.
                            </v-card-subtitle>
                            <template #append>
                                <v-btn :icon="fundingOpen ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                                       variant="text" size="small" @click="fundingOpen = !fundingOpen"
                                       :aria-label="fundingOpen ? 'Collapse funding details' : 'Expand funding details'">
                                    <v-icon :icon="fundingOpen ? 'mdi-chevron-up' : 'mdi-chevron-down'"></v-icon>
                                </v-btn>
                            </template>
                        </v-card-item>
                        <v-expand-transition>
                            <div v-show="fundingOpen">
                                <v-card-text>
                                    <div class="d-flex align-center py-1">
                                        <v-avatar size="36" color="primary" class="me-3" rounded="lg">
                                            <v-icon icon="mdi-cardano"></v-icon>
                                        </v-avatar>
                                        <div class="flex-grow-1 font-weight-medium">ADA</div>
                                        <div class="font-weight-bold">{{ formatAda(toAda(wallet_missing.lovelace)) }}</div>
                                    </div>
                                    <template v-if="missingTokens.length">
                                        <v-divider class="my-2"></v-divider>
                                        <WalletTokenList :tokens="missingTokens"/>
                                    </template>
                                </v-card-text>
                                <v-card-actions class="px-4 pb-4">
                                    <v-btn v-if="cardano.status === 'found'" color="primary" variant="flat"
                                           prepend-icon="mdi-wallet-plus" :disabled="isEnded"
                                           @click="dialog.wallet = true">
                                        {{ isEnded ? 'Campaign ended' : 'Top Up Bucket' }}
                                    </v-btn>
                                    <span v-else class="text-caption text-medium-emphasis">
                                        Connect a wallet (top right) to fund this bucket.
                                    </span>
                                </v-card-actions>
                            </div>
                        </v-expand-transition>
                    </v-card>

                    <CampaignCharts
                        v-if="campaign.codes && campaign.codes.length > 0"
                        :campaign="campaign"
                        :stats="stats"
                    />
                    <v-toolbar color="transparent">
                        <v-toolbar-title>Campaign Codes</v-toolbar-title>
                        <v-spacer></v-spacer>
                        <span class="ms-1 d-inline-flex">
                            <v-btn icon color="primary" variant="tonal" :disabled="isEnded" @click="dialog.code = true">
                                <v-icon icon="mdi-plus"></v-icon>
                            </v-btn>
                            <v-tooltip activator="parent" location="top">
                                {{ isEnded ? 'Campaign has ended — extend the end date to add codes' : 'Create Code' }}
                            </v-tooltip>
                        </span>
                        <span class="ms-1 d-inline-flex">
                            <v-btn icon color="secondary" variant="tonal" :disabled="isEnded" @click="dialog.import = true">
                                <v-icon icon="mdi-cloud-upload"></v-icon>
                            </v-btn>
                            <v-tooltip activator="parent" location="top">
                                {{ isEnded ? 'Campaign has ended — extend the end date to import codes' : 'Import Codes' }}
                            </v-tooltip>
                        </span>
                        <v-btn icon color="accent" variant="tonal" class="ms-1" @click="checkClaimedStatus" :loading="checkingClaims">
                            <v-icon icon="mdi-reload"></v-icon>
                            <v-tooltip activator="parent" location="top">Check Claimed</v-tooltip>
                        </v-btn>
                        <v-btn icon color="info" variant="tonal" class="ms-1" @click="dialog.qr_export = true"
                               v-if="campaign.codes && campaign.codes.length > 0">
                            <v-icon icon="mdi-qrcode"></v-icon>
                            <v-tooltip activator="parent" location="top">Export QR Codes</v-tooltip>
                        </v-btn>
                        <span class="ms-1 d-inline-flex" v-if="cardano.status === 'found' && campaign.wallet">
                            <v-btn icon color="success" variant="tonal" :disabled="noShortfall || isEnded" @click="dialog.wallet = true">
                                <v-icon icon="mdi-wallet-plus"></v-icon>
                            </v-btn>
                            <v-tooltip activator="parent" location="top">{{ topUpTooltip }}</v-tooltip>
                        </span>
                        <span class="ms-1 d-inline-flex" v-if="campaign.wallet">
                            <v-btn icon color="warning" variant="tonal" :disabled="wallet_empty" @click="dialog.refund = true">
                                <v-icon icon="mdi-cash-refund"></v-icon>
                            </v-btn>
                            <v-tooltip activator="parent" location="top">
                                {{ wallet_empty ? 'Bucket is empty — nothing to refund' : 'Refund Bucket' }}
                            </v-tooltip>
                        </span>
                    </v-toolbar>
                    <v-row class="mt-1 mb-1" align="center" no-gutters>
                        <v-col cols="12" sm="4" md="3">
                            <v-select
                                v-model="codeFilter"
                                :items="[
                                    {title: 'All Codes', value: 'all'},
                                    {title: 'Claimed', value: 'claimed'},
                                    {title: 'Unclaimed', value: 'unclaimed'},
                                    {title: 'Available', value: 'available'},
                                    {title: 'Exhausted', value: 'exhausted'},
                                ]"
                                label="Filter"
                                density="compact"
                                hide-details
                                variant="outlined"
                            />
                        </v-col>
                        <v-col cols="12" sm="8" md="9">
                            <v-text-field v-model="dataTable.search" append-icon="mdi-magnify" label="Search" single-line
                                          hide-details density="compact" variant="outlined" class="ms-sm-2"></v-text-field>
                        </v-col>
                    </v-row>
                    <v-data-table :items="filteredCodes" :items-per-page="dataTable.perPage"
                                  v-model="dataTable.selected" :search="dataTable.search" :headers="dataTable.headers"
                                  item-value="id" return-object show-select show-expand>
                        <template v-slot:item.id="{ item }">
                            <v-btn color="primary" @click="showQR(item)">
                                <v-icon icon="mdi-qrcode"></v-icon>
                            </v-btn>
                        </template>
                        <template v-slot:expanded-row="{ columns, item }">
                            <tr>
                                <td :colspan="columns.length" class="py-3">
                                    <div class="text-subtitle-2 mb-2">Reward Details</div>
                                    <v-chip class="me-2 mb-2" color="primary" label size="small">
                                        <v-icon start icon="mdi-cardano"></v-icon>
                                        {{ formatAda(toAda(item.lovelace)) }} ADA
                                    </v-chip>
                                    <span v-if="!item.rewards || item.rewards.length === 0"
                                          class="text-medium-emphasis text-body-2">No native-token rewards on this code.</span>
                                    <v-table v-else density="compact">
                                        <thead>
                                            <tr>
                                                <th class="text-left">Token</th>
                                                <th class="text-left">Quantity</th>
                                                <th class="text-left">Policy</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="reward in item.rewards" :key="reward.policy_hex + reward.asset_hex">
                                                <td>
                                                    <v-avatar v-if="tokenMeta[reward.policy_hex + reward.asset_hex]?.logo"
                                                              size="20" class="me-1">
                                                        <v-img :src="`data:image/png;base64,${tokenMeta[reward.policy_hex + reward.asset_hex].logo}`"></v-img>
                                                    </v-avatar>
                                                    {{ rewardDisplayName(reward) }}
                                                </td>
                                                <td>
                                                    {{ rewardDisplayQuantity(reward) }}
                                                    <v-chip v-if="rewardDecimals(reward) > 0" size="x-small"
                                                            variant="tonal" color="info" class="ms-1"
                                                            :title="`${rewardRawQuantity(reward)} base units (${rewardDecimals(reward)} decimals)`">
                                                        <v-icon start icon="mdi-decimal" size="x-small"></v-icon>
                                                        {{ rewardDecimals(reward) }} decimals
                                                    </v-chip>
                                                </td>
                                                <td>
                                                    <code class="text-caption">{{ reward.policy_hex.slice(0, 8) }}…{{ reward.policy_hex.slice(-6) }}</code>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </td>
                            </tr>
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
                    This will create codes for this campaign. Set quantity to generate multiple codes with the same
                    configuration.
                </v-card-text>
                <v-form @submit.prevent="createCode">
                    <v-card-text>
                        <v-text-field label="Quantity" v-model="code.quantity" required min="1" max="500" step="1"
                                      type="number"
                                      hint="Number of codes to generate (1-500). Each code gets a unique ULID."
                                      persistent-hint :rules="rules.quantity"/>
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
                    <v-card-text v-if="code.tokens.length === 0" class="text-medium-emphasis">
                        No native tokens yet — this code pays ADA only. Add a token to sweeten the reward.
                    </v-card-text>
                    <v-card-text v-else class="pt-0">
                        <WalletTokenList :tokens="codeTokensDisplay" removable @remove="removeCodeToken"/>
                    </v-card-text>
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
                    Search for a known token by ticker/name, or enter the Policy ID and Token ID manually.
                </v-card-text>
                <v-form fast-fail @submit.prevent @submit="addTokenToCode">
                    <v-card-text>
                        <v-autocomplete
                            label="Find a known token (e.g. HOSKY, USDM)"
                            v-model="knownAssetSearch.selected"
                            :items="knownAssetSearch.items"
                            :item-title="knownAssetLabel"
                            :loading="knownAssetSearch.loading"
                            return-object
                            clearable
                            no-filter
                            prepend-inner-icon="mdi-magnify"
                            hint="Resolves the Policy ID, Token ID, and decimals for you."
                            persistent-hint
                            class="mb-2"
                            @update:search="searchKnownAssets"
                            @update:model-value="onKnownAssetSelect"
                        />
                        <v-text-field label="Policy ID" v-model="token.policy_id" required>
                            <template v-slot:append>
                                <v-btn size="small" variant="tonal" :loading="tokenLookup.loading"
                                       @click="lookupTokenOnChain">
                                    <v-icon start icon="mdi-cloud-search"/>Look up
                                </v-btn>
                            </template>
                        </v-text-field>
                        <v-text-field label="Token ID" v-model="token.token_id" required/>
                        <v-alert v-if="tokenLookup.result" type="success" variant="tonal" density="compact" class="mb-2">
                            Resolved: <strong>{{ tokenLookup.result.ticker || tokenLookup.result.name }}</strong>
                            <span v-if="tokenLookup.result.decimals"> ({{ tokenLookup.result.decimals }} decimals)</span>
                        </v-alert>
                        <v-alert v-if="tokenLookup.error" type="warning" variant="tonal" density="compact" class="mb-2">
                            {{ tokenLookup.error }}
                        </v-alert>
                        <v-text-field
                            :label="token.decimals > 0 ? 'Amount' : 'Quantity'"
                            v-model="token.quantity" required min="0" type="number"
                            :step="token.decimals > 0 ? 'any' : '1'"
                            :suffix="tokenLookup.result ? (tokenLookup.result.ticker || tokenLookup.result.name || '') : ''"
                            :hint="tokenBaseUnitsPreview
                                ? `= ${tokenBaseUnitsPreview} base units (${token.decimals} decimals) sent on-chain`
                                : 'Whole number of base units sent on-chain'"
                            persistent-hint/>
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
        <v-dialog v-model="dialog.wallet_balance" width="512" transition="dialog-bottom-transition"
                  persistent scrollable>
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Top Up Campaign Wallet</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.wallet_balance = false; disconnect()">
                        <v-icon icon="mdi-close"/>
                    </v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-card-text>
                    <v-progress-linear indeterminate height="8" color="primary"
                                       v-if="connectedWalletDetails.checkingBalance" class="mb-4"></v-progress-linear>
                    <template v-if="!connectedWalletDetails.checkingBalance">
                        <p class="mb-2">Connected wallet: <strong>{{ cardano.ActiveWallet?.name || 'Unknown' }}</strong></p>
                        <p class="mb-2">UTxOs found: <strong>{{ connectedWalletDetails.utxos.length }}</strong></p>
                        <p class="mb-4">Campaign wallet: <code class="text-caption">{{ campaign.wallet?.address }}</code></p>
                        <v-alert v-if="dialog.show_toast" type="success" class="mb-4" closable @click:close="dialog.show_toast = false">
                            Transaction submitted successfully! It may take a few minutes to confirm on-chain. Refresh to check the updated balance.
                        </v-alert>
                    </template>
                </v-card-text>
                <v-card-actions v-if="!connectedWalletDetails.checkingBalance">
                    <v-btn color="red" variant="text" @click="dialog.wallet_balance = false; disconnect()">
                        Disconnect
                    </v-btn>
                </v-card-actions>
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
                                    :true-value="1" :false-value="0"
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
        <QrExportDialog
            v-model="dialog.qr_export"
            :campaign="campaign"
            :claim-url="claim_url"
            :codes-count="campaign.codes ? campaign.codes.length : 0"
            :gd-available="gd_available"
        />
        <v-snackbar v-model="snackbar.show" :timeout="1800" location="bottom">
            {{ snackbar.text }}
        </v-snackbar>
        <!--        <pre>{{ campaign }}</pre>-->
    </AuthenticatedLayout>
</template>
