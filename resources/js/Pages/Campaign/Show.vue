<script setup>
import { Head, useForm, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import {
    computed,
    getCurrentInstance,
    onMounted,
    onUnmounted,
    reactive,
    ref,
    watch,
} from "vue";
import QrcodeVue from "qrcode.vue";
import { Transaction } from "@meshsdk/core";
import CampaignCharts from "@/Components/CampaignCharts.vue";
import WalletTokenList from "@/Components/WalletTokenList.vue";
import QrExportDialog from "@/Components/QrExportDialog.vue";
import PartnerPicker from "@/Components/PartnerPicker.vue";
import {
    knownAssetLabel,
    assetToToken,
    toBaseUnits,
} from "@/utils/knownAssets.js";
import { knownAssetBatcher } from "@/utils/assetMetaBatcher.js";
import { storeFile } from "@/utils/upload.js";
import { assetNameOrHex } from "@/utils/assetName.js";
import {
    useCampaignTasks,
    taskLabel,
    isActiveTask,
} from "@/composables/useCampaignTasks.js";

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
    allowed_networks: {
        type: Array,
        default: () => ["preprod", "preview", "mainnet"],
    },
    gd_available: {
        type: Boolean,
        default: true,
    },
    onboarding: {
        type: Object,
        default: () => ({}),
    },
    // Which wallets this campaign's claimants used, and whether it may be shown yet.
    // The server decides that; this component only renders what it is given.
    wallet_clients: {
        type: Object,
        default: () => ({ state: "too_few", floor: 10, clients: null }),
    },
    // What this campaign cost its operator: fees to us, fees to the chain, and
    // what was given away. Three different kinds of money, kept apart.
    costs: { type: Object, default: () => ({}) },
    // Cached display metadata for this campaign's reward assets, keyed by policy id +
    // asset name hex; null for an asset the chain does not know. Anything missing here
    // is looked up after mount, in batches.
    asset_meta: { type: Object, default: () => ({}) },
    // Whether this campaign should be telling its operator it is running short, and
    // the settings that decide it. The edit dialog shows `claims_remaining` beside the
    // threshold field, so the number being typed has something to be compared with.
    alerts: {
        type: Object,
        default: () => ({
            enabled: true,
            threshold: 10,
            claims_remaining: null,
            held: 0,
            alerting: false,
            reason: null,
        }),
    },
    // What this campaign may spend of the account's credit, in credits. `applies` is
    // false wherever a limit could not do anything — a path already paid from the
    // campaign's own bucket, or a deployment that is not charging — and the control is
    // left out there rather than shown unable to bite.
    spending: {
        type: Object,
        default: () => ({
            applies: false,
            limit_credits: null,
            spent_credits: "0",
        }),
    },
    // Who this campaign hands codes out through, live rows only.
    partners: {
        type: Array,
        default: () => [],
    },
    // The partner the last batch was generated for, which is what the picker starts on.
    // Null means there is not one yet, and the picker starts on Unassigned.
    default_partner_id: {
        type: String,
        default: null,
    },
    // Which of those partners produced claims. A partner who produced none arrives as a
    // row of zeros, because an absent row would read as figures that failed to load.
    partner_conversion: {
        type: Object,
        default: () => ({ rows: [], summary: {} }),
    },
    // What still has to be in the bucket, split into rewards, network fees and the
    // platform fee. The last is null where this deployment does not charge.
    funding: {
        type: Object,
        default: () => ({
            remaining_claims: 0,
            reward_lovelace: 0,
            network_fee_lovelace: 0,
            platform_fee_lovelace: null,
        }),
    },
    // The chain's minimum for an output, and how many still-claimable codes are under
    // it. A code below its own minimum is not underfunded, it is unpayable, so it is
    // reported apart from the top-up figure.
    min_utxo: {
        type: Object,
        default: () => ({
            coins_per_utxo_byte: 4310,
            headroom_lovelace: 1000000,
            below_minimum_codes: 0,
            tight_codes: 0,
        }),
    },
    // Background work running on this campaign when the page was drawn, and the server
    // clock the poller counts from.
    tasks: {
        type: Object,
        default: () => ({ now: null, items: [] }),
    },
    // Sticker archives already built for this campaign. A finished render names this prop,
    // so the poller reloads exactly it and the download appears without a page reload.
    qr_exports: {
        type: Array,
        default: () => [],
    },
    // Who an export can be asked for: every code, one partner, or the codes nobody was
    // given, each with its count.
    export_scopes: {
        type: Array,
        default: () => [],
    },
});

// One timer for every background job on this page. Panels read what it holds; nothing
// else on the page polls anything.
const {
    active: activeTasks,
    failed: failedTasks,
    stalled: tasksStalled,
    resume: resumeTasks,
    byType: tasksByType,
} = useCampaignTasks(props.campaign.id, () => props.tasks);

// The QR export runs, handed to the export dialog so it can show the one it started. The
// dialog watches this and fetches nothing of its own.
const qrExportTasks = computed(() => tasksByType("qr-export"));

// The onboarding analysis reports itself like every other job, but it has a panel of its
// own to report into. One run per campaign for this type, so there is at most one row.
const ONBOARDING_TASK = "onboarding-analysis";

const onboardingTask = computed(
    () => tasksByType(ONBOARDING_TASK).at(-1) ?? null,
);

// The strip below carries work that has nowhere else to appear. Anything with a panel of
// its own is left out of it, because two progress bars for one run reads as two runs. The
// QR export stays in the strip: its card is where a running render is reopened from.
const stripTasks = computed(() =>
    activeTasks.value.filter((task) => task.type !== ONBOARDING_TASK),
);

const stripFailures = computed(() =>
    failedTasks.value.filter((task) => task.type !== ONBOARDING_TASK),
);

function taskPercent(task) {
    if (!task.progress_total) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((task.progress_done / task.progress_total) * 100),
    );
}

const maxFileSizeMB = computed(() =>
    Math.round(props.max_file_size / 1024 / 1024),
);

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
    edit_code: false,
    refund: false,
    qr_export: false,
    spend_limit: false,
});

const hasClaims = computed(
    () => props.campaign.claims && props.campaign.claims.length > 0,
);

// Networks this deployment accepts (config/cardano.php). One it will not accept is
// left out of the selector rather than shown disabled, since nothing at the control
// could explain why. A campaign already on an excluded network keeps it in the list,
// or the select would sit empty and no other edit to that campaign could be saved.
const networkOptions = computed(() => {
    const allowed = ["preprod", "preview", "mainnet"].filter((network) =>
        props.allowed_networks.includes(network),
    );
    const current = props.campaign.network;

    return current && !allowed.includes(current)
        ? [...allowed, current]
        : allowed;
});

// The alert switch is carried as 1/0 rather than true/false, the same way one-per-wallet
// is: the column is a tinyint and arrives as a number, so a control bound to a boolean
// would render a campaign with alerts on as though they were off.
const editForm = useForm({
    name: props.campaign.name,
    description: props.campaign.description,
    start_date: props.campaign.start_date,
    end_date: props.campaign.end_date,
    txn_msg: props.campaign.txn_msg,
    nmkr_api_key: props.campaign.nmkr_api_key,
    network: props.campaign.network,
    one_per_wallet: props.campaign.one_per_wallet,
    alerts_enabled: props.campaign.alerts_enabled ? 1 : 0,
    alert_threshold_claims: props.campaign.alert_threshold_claims ?? null,
    max_codes: props.campaign.max_codes ?? null,
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
    editForm.alerts_enabled = props.campaign.alerts_enabled ? 1 : 0;
    editForm.alert_threshold_claims =
        props.campaign.alert_threshold_claims ?? null;
    editForm.max_codes = props.campaign.max_codes ?? null;
    dialog.edit = true;
}

// An empty box means "no limit" for either of these, which is a null column rather than
// a zero or the empty string a blank number field posts: nullable|integer accepts null
// and refuses "", and zero is a value of its own for the alert threshold, not "none".
function blankToNull(typed) {
    return typed === null || typed === undefined || String(typed).trim() === ""
        ? null
        : Number(typed);
}

function submitEdit() {
    editForm.alert_threshold_claims = blankToNull(
        editForm.alert_threshold_claims,
    );
    editForm.max_codes = blankToNull(editForm.max_codes);

    editForm.patch(route("campaigns.update", props.campaign.id), {
        onSuccess: () => {
            dialog.edit = false;
        },
    });
}

// What the threshold is measured against, so the number being typed is not a guess.
// Null where the question cannot be answered, which is the service's own answer on a
// path it cannot price rather than a figure of zero.
const alertThresholdHint = computed(() => {
    const base =
        "In claims, not ADA: how many more people can claim before this stops working. Leave blank for 10.";
    const remaining = props.alerts?.claims_remaining;

    return remaining === null || remaining === undefined
        ? base
        : `${base} This campaign can serve about ${remaining} more claim(s) right now.`;
});

// True while the request is in flight, and then for as long as the run it started is still
// going. The run itself shows in the strip of background work and reloads the claims when it
// finishes, so the button only has to stop a second press in the meantime.
const postingClaimCheck = ref(false);

const claimCheckRunning = computed(() =>
    tasksByType("check-claims").some(isActiveTask),
);

const checkingClaims = computed(
    () => postingClaimCheck.value || claimCheckRunning.value,
);

function checkClaimedStatus() {
    postingClaimCheck.value = true;
    router.post(
        route("campaigns.check-claims", props.campaign.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                postingClaimCheck.value = false;
            },
        },
    );
}

const refundForm = useForm({
    address: "",
});

function submitRefund() {
    refundForm.post(route("campaigns.refund", props.campaign.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.refund = false;
            refundForm.reset();
        },
    });
}

// --- Spend limit ----------------------------------------------------------
// Offered only where a limit could do anything and where there is an endpoint to set it
// with. The published edition ships without the billing routes, and Ziggy throws on a
// name that is not in its table, so the route is checked rather than assumed.
const canSetSpendLimit = computed(
    () =>
        props.spending?.applies === true &&
        route().has("campaigns.spend-limit"),
);

const spendLimitSummary = computed(() => {
    const limit = props.spending?.limit_credits;

    if (limit === null || limit === undefined || limit === "") {
        return "No limit. This campaign will spend whatever the account's credit covers.";
    }

    return `${limit} credit(s), of which ${props.spending?.spent_credits ?? "0"} spent. Claims past the limit wait rather than fail.`;
});

// A credit is stored in millionths, so six decimal places is the whole of the precision
// there is. A seventh is refused here rather than truncated, so nobody sets a limit and
// is quietly given a different one.
const spendLimitRules = [
    (v) =>
        v === null ||
        v === undefined ||
        String(v).trim() === "" ||
        /^\d+(\.\d{1,6})?$/.test(String(v).trim()) ||
        "Enter a number of credits, to at most six decimal places.",
];

const spendLimitForm = useForm({
    spend_limit_credits: props.spending?.limit_credits ?? "",
});

function openSpendLimitDialog() {
    spendLimitForm.spend_limit_credits = props.spending?.limit_credits ?? "";
    dialog.spend_limit = true;
}

function submitSpendLimit() {
    // An empty box lifts the limit. Sent as null rather than as an empty string so the
    // endpoint reads it as "no limit" rather than as a figure it has to interpret.
    const typed = String(spendLimitForm.spend_limit_credits ?? "").trim();
    spendLimitForm.spend_limit_credits = typed === "" ? null : typed;

    spendLimitForm.patch(route("campaigns.spend-limit", props.campaign.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.spend_limit = false;
        },
    });
}

const connectedWalletDetails = reactive({
    utxos: [],
    checkingBalance: false,
});

const imported = useForm({
    campaign_id: props.campaign.id,
    uploadedCodes: true,
    partner_id: props.default_partner_id,
    partner_name: null,
    partner_kind: null,
});

const file_ref = ref(null);

const code = useForm({
    campaign_id: null,
    quantity: 1,
    uses: 1,
    perWallet: 1,
    lovelace: 1000000,
    tokens: [],
    nmkr_project_uid: "",
    nmkr_count_nft: 0,
    // Who the batch is for. An id picks a partner that exists, a name adds one, and both
    // null is the named choice "Unassigned".
    partner_id: props.default_partner_id,
    partner_name: null,
    partner_kind: null,
});

/** The picker's value, in the shape PartnerPicker reads and writes. */
function partnerSelection(form) {
    return {
        id: form.partner_id,
        name: form.partner_name,
        kind: form.partner_kind,
    };
}

function applyPartner(form, selection) {
    form.partner_id = selection.id;
    form.partner_name = selection.name;
    form.partner_kind = selection.kind;
}

// After a batch is generated the page comes back with the partner that batch was for
// as the new default, so the picker follows it rather than holding the value the form was
// built with. A name that was typed to add a partner is dropped at the same time: the
// partner exists now, and sending the name again would be a second one.
watch(
    () => props.default_partner_id,
    (id) => {
        applyPartner(code, { id: id ?? null, name: null, kind: null });
        applyPartner(imported, { id: id ?? null, name: null, kind: null });
    },
);

const token = reactive({
    policy_id: null,
    token_id: null,
    quantity: 1,
    decimals: 0, // decimals of the selected/looked-up token; drives the amount input + conversion
});

// Lovelace as ADA, for text built in script rather than in the template. The mixin's
// formatAda is only reachable from template scope, and a validation rule runs in neither.
function adaText(lovelace) {
    const ada = Number(lovelace || 0) / 1000000;
    return ada.toFixed(6).replace(/\.?0+$/, "");
}

// The reward being edited on a code that already exists. Separate from the create form
// because the two dialogs can both be reached from this page and neither should be able
// to overwrite the other's half-finished work.
const editCode = useForm({
    lovelace: 1000000,
    tokens: [],
    nmkr_project_uid: "",
    nmkr_count_nft: 0,
});

const editingCode = ref(null);

// Which form the token dialog is adding to. One add-token dialog serves both, because it
// is the same question either way and two copies of it would drift.
const tokenTarget = ref("create");

const activeCodeForm = computed(() =>
    tokenTarget.value === "edit" ? editCode : code,
);

function newQuote() {
    // What the reward bundle currently being built has to be worth. Answered by the
    // server from the same calculation that validates the form, so what a dialog shows
    // and what the save accepts cannot disagree. It depends on the tokens rather than on
    // the amount, so it is asked once per change to the token list and the typed amount
    // is compared against it here.
    return reactive({
        loading: false,
        min_lovelace: 0,
        recommended_lovelace: 0,
        asset_count: 0,
        policy_count: 0,
    });
}

const codeQuote = newQuote();
const editQuote = newQuote();

const activeQuote = computed(() =>
    tokenTarget.value === "edit" ? editQuote : codeQuote,
);

// Rounded up to the next whole ADA, because that is how an operator thinks about the
// number they are about to type into a box.
function suggested(quote) {
    return quote.recommended_lovelace
        ? Math.ceil(quote.recommended_lovelace / 1000000) * 1000000
        : 0;
}

const suggestedLovelace = computed(() => suggested(codeQuote));
const suggestedEditLovelace = computed(() => suggested(editQuote));

function quoteMinUtxo(quote, tokens) {
    // Guarded: the campaign page is rendered in environments with no axios bound.
    if (!window.axios) {
        return;
    }

    quote.loading = true;
    window.axios
        .post(route("campaigns.min-utxo", props.campaign.id), { tokens })
        .then(({ data }) => {
            quote.min_lovelace = data.min_lovelace;
            quote.recommended_lovelace = data.recommended_lovelace;
            quote.asset_count = data.asset_count;
            quote.policy_count = data.policy_count;
        })
        .catch(() => {
            // Left at whatever was last known. A failed lookup must not silently
            // report a bundle as costing nothing.
        })
        .finally(() => {
            quote.loading = false;
        });
}

function refreshCodeQuote() {
    quoteMinUtxo(codeQuote, code.tokens);
}

function refreshEditQuote() {
    quoteMinUtxo(editQuote, editCode.tokens);
}

function refreshActiveQuote() {
    if (tokenTarget.value === "edit") {
        refreshEditQuote();
    } else {
        refreshCodeQuote();
    }
}

// Whether what is typed clears the chain's floor, and whether it leaves the claimant
// anything to spend. Two different answers: the first blocks the save, the second is
// said out loud and then allowed.
function belowMinimum(quote, lovelace) {
    return quote.min_lovelace > 0 && Number(lovelace) < quote.min_lovelace;
}

function isTight(quote, lovelace) {
    return (
        quote.min_lovelace > 0 &&
        !belowMinimum(quote, lovelace) &&
        Number(lovelace) < quote.recommended_lovelace
    );
}

const codeBelowMinimum = computed(() => belowMinimum(codeQuote, code.lovelace));
const codeIsTight = computed(() => isTight(codeQuote, code.lovelace));
const editBelowMinimum = computed(() =>
    belowMinimum(editQuote, editCode.lovelace),
);
const editIsTight = computed(() => isTight(editQuote, editCode.lovelace));

// One rule for both dialogs, against whichever quote belongs to the form being filled in.
function lovelaceRule(value, quote) {
    if (value < 1000000) {
        return "Lovelace must be at least 1000000 (1 ADA)";
    }
    if (value > 45000000000000000) {
        return "Lovelace cannot be greater than total maximum supply!";
    }
    // The chain's own floor for what this bundle carries. Below it the payment cannot be
    // submitted at all, so it is an error rather than a warning.
    if (quote.min_lovelace > 0 && Number(value) < quote.min_lovelace) {
        return `An output carrying these tokens needs at least ${quote.min_lovelace} lovelace (${adaText(quote.min_lovelace)} ADA)`;
    }
    return true;
}

const rules = {
    code: [
        (v) => !!v || "Code is required",
        (v) => {
            if (/^[a-zA-Z0-9_-]+$/.test(v)) {
                return true;
            }
            return "Code can only contain letters, numbers, dashes and underscores!";
        },
    ],
    lovelace: [(v) => lovelaceRule(v, codeQuote)],
    editLovelace: [(v) => lovelaceRule(v, editQuote)],
    quantity: [
        (v) => {
            const n = Number(v);
            if (!Number.isInteger(n) || n < 1) {
                return "Quantity must be a whole number of at least 1";
            }
            if (n > 500) {
                return "Quantity cannot exceed 500 codes per batch";
            }
            return true;
        },
    ],
};

const qrViewer = reactive({
    show: false,
    code: null,
    code_uri: null,
});

function addToken(target = "create") {
    tokenTarget.value = target;
    dialog.token = true;
    searchKnownAssets("");
}

function cancelAddToken() {
    resetToken();
}

function addTokenToCode() {
    // For a decimal token the user enters a human amount (e.g. 10 USDM); convert to the
    // on-chain base-unit integer that is actually distributed (10 * 10^6 = 10000000).
    const baseUnits = toBaseUnits(token.quantity, token.decimals);

    activeCodeForm.value.tokens.push({
        policy_id: token.policy_id,
        token_id: token.token_id,
        quantity: baseUnits,
    });
    // Ensure the row can render a human name/decimals even if entered manually.
    resolveTokenMeta({
        policy_hex: token.policy_id,
        asset_hex: token.token_id,
    });
    resetToken();
    refreshActiveQuote();
}

function removeCodeToken(index) {
    code.tokens.splice(index, 1);
    refreshCodeQuote();
}

function removeEditToken(index) {
    editCode.tokens.splice(index, 1);
    refreshEditQuote();
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
    window.axios
        .get(route("known-assets.index"), {
            params: { q: query || "", network: props.campaign.network },
        })
        .then(({ data }) => {
            knownAssetSearch.items = data;
        })
        .catch(() => {
            knownAssetSearch.items = [];
        })
        .finally(() => {
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
    tokenMeta.value[
        `${mapped.policy_id}${mapped.token_id ?? ""}`.toLowerCase()
    ] = mapped.meta;
}

// Resolve a manually-entered policy/asset against Koios. The server writes a registry
// token to the shared table itself; nothing typed here is ever saved there.
function lookupTokenOnChain() {
    if (!token.policy_id) {
        tokenLookup.error = "Enter a Policy ID first.";
        return;
    }
    tokenLookup.loading = true;
    tokenLookup.error = null;
    window.axios
        .get(route("known-assets.lookup"), {
            params: {
                policy: token.policy_id,
                asset_name: token.token_id || "",
                network: props.campaign.network,
            },
        })
        .then(({ data }) => {
            tokenLookup.result = data;
            token.token_id = data.asset_name || token.token_id;
            token.decimals = data.decimals || 0;
            tokenMeta.value[
                (data.policy_id + (data.asset_name || "")).toLowerCase()
            ] = {
                name: data.name,
                ticker: data.ticker,
                decimals: data.decimals || 0,
                logo: data.logo || null,
            };
        })
        .catch((err) => {
            tokenLookup.result = null;
            tokenLookup.error =
                err?.response?.status === 404
                    ? "Asset not found in the registry for this network."
                    : "Lookup failed. You can still enter the values manually.";
        })
        .finally(() => {
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
    refreshCodeQuote();
}

// Opened from the toolbar. The minimum for an empty bundle is asked for straight away so
// the dialog can state a figure rather than appearing to have no opinion until a token
// is added.
function openCodeDialog() {
    tokenTarget.value = "create";
    dialog.code = true;
    refreshCodeQuote();
}

// Editing what a code already pays. Filled from the row rather than fetched, because the
// campaign page already carries every code and its rewards.
function openEditCode(item) {
    editingCode.value = item;
    tokenTarget.value = "edit";
    editCode.lovelace = Number(item.lovelace);
    editCode.tokens = (item.rewards ?? []).map((reward) => ({
        policy_id: reward.policy_hex,
        token_id: reward.asset_hex,
        quantity: Number(reward.quantity),
    }));
    editCode.nmkr_project_uid = item.nmkr_project_uid ?? "";
    editCode.nmkr_count_nft = Number(item.nmkr_count_nft ?? 0);
    editCode.clearErrors?.();
    dialog.edit_code = true;
    refreshEditQuote();
}

function closeEditCode() {
    dialog.edit_code = false;
    editingCode.value = null;
    tokenTarget.value = "create";
}

function saveCodeRewards() {
    if (!editingCode.value) {
        return;
    }

    editCode.put(route("codes.update", editingCode.value.id), {
        preserveScroll: true,
        onSuccess: () => closeEditCode(),
    });
}

// How many claims this code has taken that have not been sent yet. Those claimants have
// already been told what they are getting, and an edit changes it, so the dialog says so
// before the change is made rather than after.
const editPendingClaims = computed(() => {
    const id = editingCode.value?.id;

    if (!id) {
        return 0;
    }

    return (props.campaign.claims ?? []).filter(
        (claim) => claim.code_id === id && !claim.transaction_id,
    ).length;
});

const editSettledClaims = computed(() => {
    const id = editingCode.value?.id;

    if (!id) {
        return 0;
    }

    return (props.campaign.claims ?? []).filter(
        (claim) => claim.code_id === id && claim.transaction_id,
    ).length;
});

function createCode() {
    code.transform((data) => ({
        ...data,
        campaign_id: props.campaign.id,
    })).post(route("codes.store"), {
        onSuccess: () => {
            code.reset();
            dialog.code = false;
        },
    });
}

function importCodes() {
    storeFile(file_ref.value.files[0], {
        signingUrl: route("uploads.signed-url"),
        progress: (progress) => {
            imported.progress = Math.round(progress * 100);
        },
    })
        .then((response) => {
            imported
                .transform((data) => ({
                    ...data,
                    file_key: response.key,
                }))
                .post(route("codes.store"), {
                    onSuccess: () => {
                        imported.reset();
                        dialog.import = false;
                    },
                });
        })
        .catch((e) => {
            // A server with no object storage attached answers the signed-URL request with a
            // 503 and an explanation. Without this the upload simply stalled at its last
            // progress reading and told the user nothing at all.
            imported.progress = 0;
            dialog.import = false;
            doError(
                e?.response?.data?.message ??
                    "The codes file could not be uploaded. Please try again.",
            );
        });

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

const codeFilter = ref("all");

/** The partner whose codes the table is showing: an id, "unassigned", or "all". */
const partnerFilter = ref("all");

/**
 * Whether this campaign has ever handed codes to a partner.
 *
 * A campaign that has not says so on every row of the table, which is a column of
 * "Unassigned" telling the operator nothing, and a filter with nothing to filter. Both
 * are left off until there is an attribution to read.
 */
const campaignUsesPartners = computed(
    () =>
        (props.partners ?? []).length > 0 ||
        (props.campaign.codes ?? []).some((code) => code.partner_id),
);

/**
 * What the partner filter offers.
 *
 * The campaign's live partners, plus any partner that is off the picker but still named on
 * codes it was given. A deleted partner keeps its codes, so leaving it out would leave rows
 * in the table that no filter can reach.
 */
const partnerFilterOptions = computed(() => {
    const live = (props.partners ?? []).map((partner) => ({
        title: partner.name,
        value: partner.id,
    }));
    const listed = new Set(live.map((option) => option.value));
    const removed = [];

    for (const code of props.campaign.codes ?? []) {
        if (!code.partner || listed.has(code.partner.id)) continue;

        listed.add(code.partner.id);
        removed.push({
            title: `${code.partner.name} (removed)`,
            value: code.partner.id,
        });
    }

    removed.sort((a, b) => a.title.localeCompare(b.title));

    return [
        { title: "All Partners", value: "all" },
        { title: "Unassigned", value: "unassigned" },
        ...live,
        ...removed,
    ];
});

const filteredCodes = computed(() => {
    let codes = props.campaign.codes ?? [];

    if (codeFilter.value === "claimed")
        codes = codes.filter((c) => c.claims_count > 0);
    else if (codeFilter.value === "unclaimed")
        codes = codes.filter((c) => c.claims_count === 0);
    else if (codeFilter.value === "available")
        codes = codes.filter((c) => c.uses === 0 || c.claims_count < c.uses);
    else if (codeFilter.value === "exhausted")
        codes = codes.filter((c) => c.uses > 0 && c.claims_count >= c.uses);

    // Read from partner_id rather than from the loaded partner, so a code keeps answering
    // the filter whether or not the partner it names is still live.
    if (partnerFilter.value === "unassigned")
        codes = codes.filter((c) => !c.partner_id);
    else if (partnerFilter.value !== "all")
        codes = codes.filter((c) => c.partner_id === partnerFilter.value);

    return codes;
});

const codeHeaders = computed(() => [
    { title: "Code", align: "start", key: "code" },
    ...(campaignUsesPartners.value
        ? [{ title: "Partner", align: "start", key: "partner.name" }]
        : []),
    { title: "Uses", align: "start", key: "uses" },
    { title: "Per Wallet", align: "start", key: "perWallet" },
    { title: "Lovelace", align: "start", key: "lovelace" },
    { title: "Tokens", align: "start", key: "rewards_count" },
    { title: "Claims", align: "start", key: "claims_count" },
    {
        title: "Actions",
        align: "end",
        key: "id",
        sortable: false,
        filterable: false,
    },
]);

const dataTable = reactive({
    perPage: 10,
    search: null,
    selected: [],
});

// Code deletion. Claimed codes are never offered for deletion: their claims are the
// record that someone was paid.
const deleteCodes = reactive({
    dialog: false,
    scope: "selected", // 'selected' | 'all_unclaimed'
    working: false,
});

const selectedDeletable = computed(() =>
    dataTable.selected.filter((code) => (code.claims_count ?? 0) === 0),
);

const selectedClaimed = computed(
    () => dataTable.selected.length - selectedDeletable.value.length,
);

const unclaimedCodeCount = computed(
    () =>
        (props.campaign.codes ?? []).filter(
            (code) => (code.claims_count ?? 0) === 0,
        ).length,
);

function confirmDeleteCodes(scope) {
    deleteCodes.scope = scope;
    deleteCodes.dialog = true;
}

function deleteSingleCode(code) {
    dataTable.selected = [code];
    confirmDeleteCodes("selected");
}

function performDeleteCodes() {
    deleteCodes.working = true;

    const payload =
        deleteCodes.scope === "all_unclaimed"
            ? { all_unclaimed: true }
            : { codes: selectedDeletable.value.map((code) => code.id) };

    router.delete(route("campaigns.codes.bulk-destroy", props.campaign.id), {
        data: payload,
        preserveScroll: true,
        onFinish: () => {
            deleteCodes.working = false;
            deleteCodes.dialog = false;
            dataTable.selected = [];
        },
    });
}

const wallet_balance = computed(() => {
    const balance = {
        lovelace: 0n,
        tokens: {},
        policy_count: 0,
        token_count: 0,
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
            balance.tokens[asset.policy][asset.name] = (
                BigInt(balance.tokens[asset.policy][asset.name]) +
                BigInt(asset.amount)
            ).toString();
        });
    });

    balance.lovelace = balance.lovelace.toString();

    // console.log("Wallet balance", balance);

    return balance;
});

// What the ADA in the bucket is for, over the codes that can still be claimed. Three
// different things, and the platform fee is absent rather than zero where this deployment
// does not charge, because a line reading zero invites the question of what it would
// otherwise have been.
const fundingParts = computed(() => {
    const funding = props.funding ?? {};
    const claims = funding.remaining_claims ?? 0;
    const parts = [
        {
            label: "Rewards still to pay out",
            value: funding.reward_lovelace ?? 0,
            hint: `₳ owed to claimants across ${claims} unclaimed code use(s).`,
        },
        {
            label: "Network fees",
            value: funding.network_fee_lovelace ?? 0,
            hint: "Paid to the Cardano network as each claim is sent. An estimate.",
        },
    ];

    if (
        funding.platform_fee_lovelace !== null &&
        funding.platform_fee_lovelace !== undefined
    ) {
        parts.push({
            label: "Platform fees",
            value: funding.platform_fee_lovelace,
            hint: "Taken from this bucket as each claim is sent.",
        });
    }

    return parts;
});

const campaign_needs = computed(() => {
    // console.log("Calculating campaign needs?");
    const needed_tokens = {
        lovelace: 0n,
        tokens: {},
    };

    for (const [token, quantity] of Object.entries(props.campaign.rewards)) {
        if (token === "lovelace") {
            needed_tokens.lovelace = BigInt(quantity);
        } else {
            const [policy_hex, asset_hex] = token.split(".");
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

    // The fees come from the server rather than a constant here, so the figure an
    // operator is asked to send is built from the rates actually in force. Its parts are
    // in props.funding and are shown broken out below.
    needed_tokens.lovelace +=
        BigInt(props.funding?.network_fee_lovelace ?? 0) +
        BigInt(props.funding?.platform_fee_lovelace ?? 0);

    console.log(needed_tokens);

    return needed_tokens;
});

const wallet_missing = computed(() => {
    console.log(
        "Calculating what's missing from the wallet!",
        campaign_needs,
        wallet_balance,
    );
    const lovelace_needed =
        (campaign_needs.value.lovelace ?? 0n) -
        BigInt(wallet_balance.value.lovelace ?? 0);
    const needed_tokens = [];
    for (const policy_id in campaign_needs.value.tokens) {
        const tokens = campaign_needs.value.tokens[policy_id];
        for (const asset_id in tokens) {
            const quantity = tokens[asset_id];
            const token_balance =
                wallet_balance.value.tokens &&
                wallet_balance.value.tokens[policy_id] &&
                wallet_balance.value.tokens[policy_id][asset_id];
            const needed = quantity - BigInt(token_balance ?? 0);
            // console.log(policy_id, asset_id, quantity, needed, token_balance)
            if (needed > 0n) {
                needed_tokens.push({
                    policy_id: policy_id,
                    asset_id: asset_id,
                    needed: needed.toString(),
                });
            }
        }
    }
    if (lovelace_needed > 0n || needed_tokens.length) {
        return {
            lovelace: lovelace_needed > 0n ? lovelace_needed.toString() : 0,
            tokens: needed_tokens,
        };
    } else {
        return null;
    }
});

const wallet_empty = computed(() => {
    if (wallet_balance.value.lovelace === undefined) {
        return true;
    }
    return wallet_balance.value.lovelace === "0";
});

// A campaign whose redemption window has closed. Claims are rejected server-side once
// ended, so funding the bucket or adding codes is futile — these drive disabled states.
// `status` is appended on the model, so it's already on the campaign prop.
const isEnded = computed(() => props.campaign.status === "ended");

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
    if (isEnded.value) return "Campaign has ended — funding is closed";
    if (isFunded.value) return "Bucket is already fully funded";
    if (noShortfall.value) return "Nothing to fund right now";
    return "Top Up";
});

// Compact, glanceable bucket status for the chip beside the wallet address. The loud
// directive card is reserved for the one state that needs action (still-open + underfunded);
// every other state collapses to this badge, with the balance available on click.
const bucketBadge = computed(() => {
    if (wallet_missing.value)
        return {
            label: "Underfunded",
            color: "warning",
            icon: "mdi-progress-wrench",
        };
    if (wallet_empty.value)
        return { label: "Empty", color: "grey", icon: "mdi-wallet-outline" };
    return { label: "Funded", color: "success", icon: "mdi-check-decagram" };
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
                quantity,
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
const snackbar = reactive({ show: false, text: "" });

function copyText(text) {
    if (!text) return;
    navigator.clipboard
        ?.writeText(text)
        .then(() => {
            snackbar.text = "Copied to clipboard";
            snackbar.show = true;
        })
        .catch(() => {
            snackbar.text = "Could not copy";
            snackbar.show = true;
        });
}

// --- Reward token detail (Feature 1b) -------------------------------------
// Lazily resolves human-readable metadata (ticker/decimals/logo) for reward
// tokens via the Koios-backed known-assets endpoint, cached per subject.
const tokenMeta = ref(
    Object.fromEntries(
        Object.entries(props.asset_meta ?? {}).map(([subject, meta]) => {
            const assetHex = subject.slice(56);
            return [
                subject,
                {
                    name: meta?.name || assetNameOrHex(assetHex),
                    ticker: meta?.ticker || null,
                    decimals: meta?.decimals || 0,
                    logo: meta?.logo || null,
                },
            ];
        }),
    ),
);

// Lowercase, as the server keys asset_meta, so a reward stored with uppercase hex is still
// found in what the page arrived with.
function tokenSubject(reward) {
    return `${reward.policy_hex}${reward.asset_hex ?? ""}`.toLowerCase();
}

function resolveTokenMeta(reward) {
    const subject = tokenSubject(reward);
    if (!reward.policy_hex || tokenMeta.value[subject] !== undefined) {
        return;
    }
    // Placeholder so we don't fire duplicate requests while in flight.
    tokenMeta.value[subject] = {
        name: assetNameOrHex(reward.asset_hex),
        ticker: null,
        decimals: 0,
    };

    tokenMetaBatcher.request(reward.policy_hex, reward.asset_hex);
}

// Batched: a campaign with a distinct NFT per code resolves hundreds of assets on mount.
const tokenMetaBatcher = knownAssetBatcher(
    props.campaign.network,
    (asset, data) => {
        if (!data) {
            return; // Unknown or failed: keep the hex-decoded fallback set above.
        }
        tokenMeta.value[`${asset.policy}${asset.asset_name}`.toLowerCase()] = {
            name: data.name || assetNameOrHex(asset.asset_name),
            ticker: data.ticker || null,
            decimals: data.decimals || 0,
            logo: data.logo || null,
        };
    },
);

function rewardDisplayName(reward) {
    const meta = tokenMeta.value[tokenSubject(reward)];
    if (meta) {
        return meta.ticker || meta.name;
    }
    return assetNameOrHex(reward.asset_hex);
}

function rewardDecimals(reward) {
    const meta = tokenMeta.value[tokenSubject(reward)];
    return meta ? meta.decimals || 0 : 0;
}

function rewardDisplayQuantity(reward) {
    const decimals = rewardDecimals(reward);
    if (!decimals) {
        return Number(reward.quantity).toLocaleString();
    }
    // Clean, trimmed amount; the decimals chip signals it's a decimal-denominated token.
    return (Number(reward.quantity) / 10 ** decimals).toLocaleString(
        undefined,
        {
            maximumFractionDigits: decimals,
        },
    );
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
    const meta = tokenMeta.value[`${policyId}${assetId ?? ""}`.toLowerCase()];
    const decimals = meta ? meta.decimals || 0 : 0;
    const raw = Number(rawAmount);
    return {
        policy: policyId,
        asset: assetId,
        name: meta ? meta.ticker || meta.name : assetNameOrHex(assetId),
        logo: meta ? meta.logo : null,
        decimals,
        raw: raw.toLocaleString(),
        amount: decimals
            ? (raw / 10 ** decimals).toLocaleString(undefined, {
                  maximumFractionDigits: decimals,
              })
            : raw.toLocaleString(),
    };
}

const missingTokens = computed(() =>
    (wallet_missing.value?.tokens ?? []).map((t) =>
        walletDisplayToken(t.policy_id, t.asset_id, t.needed),
    ),
);

const balanceTokens = computed(() =>
    formatted_token_balance.value.map((t) =>
        walletDisplayToken(t.policy_id, t.asset_id, t.quantity),
    ),
);

// Tokens staged on the code being created (stored as base-unit quantities), resolved to
// human names/decimals for display in the create-code dialog.
const codeTokensDisplay = computed(() =>
    code.tokens.map((t) =>
        walletDisplayToken(t.policy_id, t.token_id, t.quantity),
    ),
);

const editTokensDisplay = computed(() =>
    editCode.tokens.map((t) =>
        walletDisplayToken(t.policy_id, t.token_id, t.quantity),
    ),
);

// Live "= N base units" preview for the amount input when a decimal token is selected.
const tokenBaseUnitsPreview = computed(() => {
    if (!(token.decimals > 0) || !token.quantity) {
        return null;
    }
    return Math.round(
        Number(token.quantity) * 10 ** token.decimals,
    ).toLocaleString();
});

function loadWalletTokenMeta() {
    (wallet_missing.value?.tokens ?? []).forEach((t) =>
        resolveTokenMeta({ policy_hex: t.policy_id, asset_hex: t.asset_id }),
    );
    formatted_token_balance.value.forEach((t) =>
        resolveTokenMeta({ policy_hex: t.policy_id, asset_hex: t.asset_id }),
    );
}

onUnmounted(() => tokenMetaBatcher.dispose());

onMounted(() => {
    self.checkForCardano();

    // Pre-resolve reward-token metadata (deduped client-side, cached server-side)
    // so the per-code reward details are ready when a row is expanded.
    (props.campaign.codes || []).forEach(loadRewardMeta);
    // ...and the wallet balance / needed tokens shown in the status cards.
    loadWalletTokenMeta();

    const params = new URLSearchParams(window.location.search);
    if (params.get("edit") === "1") {
        openEditDialog();
    }
});

const error = reactive({
    show: false,
    message: null,
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
            case "no account set":
                doError(
                    "No dApp account set in your wallet. Please set it and try again!",
                );
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

    const expectedNetwork = props.campaign.network === "mainnet" ? 1 : 0;
    if (wallet_network !== expectedNetwork) {
        const needed_network =
            props.campaign.network === "mainnet" ? "Mainnet" : "Preproduction";
        doError(
            `The connected wallet is on the wrong network. Please use a wallet connected to the Cardano ${needed_network} Network!`,
        );
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
            doError(
                "No UTxOs found in your wallet. Please check your wallet and try again.",
            );
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
        const lovelaceNeeded =
            missing &&
            missing.lovelace &&
            missing.lovelace !== "0" &&
            missing.lovelace !== 0;
        const tokensNeeded =
            missing &&
            Array.isArray(missing.tokens) &&
            missing.tokens.length > 0;

        if (!missing || (!lovelaceNeeded && !tokensNeeded)) {
            // Nothing to top up — wallet is already funded
            return;
        }

        // Summarize what the connected wallet actually holds so we don't try
        // to send tokens the user doesn't have.
        const holdings = { tokens: {} };
        for (const u of utxos) {
            for (const a of u.output.amount) {
                if (a.unit === "lovelace") continue;
                holdings.tokens[a.unit] =
                    (holdings.tokens[a.unit] ?? 0n) + BigInt(a.quantity);
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
                    sendableTokens.push({
                        ...token,
                        sending: needed.toString(),
                    });
                } else if (available > 0n) {
                    sendableTokens.push({
                        ...token,
                        sending: available.toString(),
                    });
                    unsendableTokens.push({
                        ...token,
                        shortfall: (needed - available).toString(),
                    });
                } else {
                    unsendableTokens.push({
                        ...token,
                        shortfall: needed.toString(),
                    });
                }
            }
        }

        // If anything is missing from the connected wallet, ask the user whether
        // to proceed with a partial top-up.
        if (unsendableTokens.length > 0) {
            const list = unsendableTokens
                .map(
                    (t) =>
                        `  • policy ${t.policy_id.slice(0, 12)}… asset ${t.asset_id.slice(0, 12)}…  short by ${t.shortfall}`,
                )
                .join("\n");
            const proceed = confirm(
                `Your connected wallet is missing some required tokens:\n\n${list}\n\n` +
                    `Top up what you have now and send the missing tokens later?`,
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
            doError(
                "Your wallet has none of the required tokens. Top up cancelled.",
            );
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
        if (e.message && e.message.includes("user")) {
            // User declined to sign
            console.log("User declined transaction signing.");
        } else {
            doError(
                "Could not complete the top-up transaction. Please try again.",
            );
            console.error("Top-up Error:", e);
        }
    }
}
</script>
<template>
    <Head title="View Campaign" />
    <AuthenticatedLayout>
        <v-container>
            <v-row justify="center">
                <v-col cols="12" md="10" lg="9" xl="7">
                    <div class="mb-4">
                        <v-alert
                            type="info"
                            v-if="$page.props.flash.message"
                            closeable
                            class="mb-4"
                        >
                            {{ $page.props.flash.message }}
                        </v-alert>
                        <!-- Background work. Every job on this campaign reports itself
                             through one poller, so the operator can see an import moving
                             instead of reloading the page to find out. -->
                        <v-card
                            v-for="task in stripTasks"
                            :key="task.id"
                            variant="tonal"
                            color="primary"
                            class="mb-4"
                        >
                            <v-card-text class="py-3">
                                <div class="d-flex align-center ga-2 flex-wrap">
                                    <v-progress-circular
                                        indeterminate
                                        size="18"
                                        width="2"
                                    />
                                    <strong>{{ taskLabel(task.type) }}</strong>
                                    <span
                                        v-if="task.stage"
                                        class="text-medium-emphasis"
                                        >{{ task.stage }}</span
                                    >
                                    <v-spacer />
                                    <span
                                        v-if="task.progress_total"
                                        class="text-caption"
                                        >{{ task.progress_done }} of
                                        {{ task.progress_total }}</span
                                    >
                                </div>
                                <v-progress-linear
                                    class="mt-2"
                                    color="primary"
                                    height="6"
                                    rounded
                                    :indeterminate="!task.progress_total"
                                    :model-value="taskPercent(task)"
                                />
                                <!-- The way back into a render whose dialog was closed.
                                     Closing cancelled nothing, so there has to be
                                     somewhere to pick it up again. -->
                                <div
                                    v-if="task.type === 'qr-export'"
                                    class="mt-2 d-flex justify-end"
                                >
                                    <v-btn
                                        size="small"
                                        variant="tonal"
                                        data-test="qr-export-reopen"
                                        @click="dialog.qr_export = true"
                                    >
                                        Show export
                                    </v-btn>
                                </div>
                            </v-card-text>
                        </v-card>
                        <!-- Archives that finished. The poller reloads this prop when a
                             render completes, so an operator who closed the dialog, or
                             never opened it, still finds the download here. -->
                        <v-card
                            v-if="qr_exports.length > 0"
                            variant="tonal"
                            color="success"
                            class="mb-4"
                            data-test="qr-export-ready-panel"
                        >
                            <v-card-text class="py-3">
                                <div
                                    class="d-flex align-center ga-2 flex-wrap mb-2"
                                >
                                    <v-icon icon="mdi-package-variant-closed" />
                                    <strong>QR exports ready</strong>
                                </div>
                                <div
                                    v-for="qrExport in qr_exports"
                                    :key="qrExport.id"
                                    class="d-flex align-center ga-2 flex-wrap py-1"
                                >
                                    <span class="text-body-2">
                                        {{ qrExport.codes_total }} sticker{{
                                            qrExport.codes_total === 1
                                                ? ""
                                                : "s"
                                        }}
                                        ·
                                        {{
                                            (
                                                qrExport.settings?.format || ""
                                            ).toUpperCase()
                                        }}
                                        ·
                                        {{ qrExport.settings?.size }}"
                                        <template v-if="qrExport.scope_label">
                                            · {{ qrExport.scope_label }}
                                        </template>
                                        <template
                                            v-if="qrExport.layout === 'partner'"
                                        >
                                            · one folder per partner
                                        </template>
                                    </span>
                                    <v-spacer />
                                    <v-btn
                                        size="small"
                                        variant="tonal"
                                        prepend-icon="mdi-download"
                                        :href="qrExport.download_url"
                                        data-test="qr-export-panel-download"
                                    >
                                        Download
                                    </v-btn>
                                </div>
                            </v-card-text>
                        </v-card>
                        <v-alert
                            v-for="task in stripFailures"
                            :key="task.id"
                            type="error"
                            border="start"
                            class="mb-4"
                            density="compact"
                            icon="mdi-alert-circle-outline"
                        >
                            <v-alert-title
                                >{{
                                    taskLabel(task.type)
                                }}
                                failed</v-alert-title
                            >
                            {{
                                task.error ||
                                "It stopped before it finished and recorded no reason."
                            }}
                        </v-alert>
                        <v-alert
                            v-if="tasksStalled && activeTasks.length > 0"
                            type="info"
                            border="start"
                            class="mb-4"
                            density="compact"
                            icon="mdi-clock-outline"
                        >
                            This is taking longer than usual. It is still
                            running; the page has just stopped asking.
                            <template #append>
                                <v-btn
                                    size="small"
                                    variant="tonal"
                                    @click="resumeTasks"
                                >
                                    Check again
                                </v-btn>
                            </template>
                        </v-alert>
                        <v-alert
                            type="error"
                            v-if="$page.props.transaction_backend === 'null'"
                            border="start"
                            class="mb-4"
                            density="compact"
                            icon="mdi-flask-outline"
                        >
                            <v-alert-title>TEST MODE</v-alert-title>
                            The transaction backend is set to
                            <strong>null</strong>. Wallet addresses shown are
                            fake. Do NOT send any ADA or tokens to them — they
                            will be lost permanently.
                        </v-alert>
                        <v-alert
                            type="warning"
                            v-if="backend_mismatch"
                            border="start"
                            class="mb-4"
                            density="compact"
                            icon="mdi-swap-horizontal"
                        >
                            <v-alert-title>BACKEND MISMATCH</v-alert-title>
                            This wallet was created under the
                            <strong>{{ wallet_backend }}</strong> backend, but
                            the system is currently configured to use
                            <strong>{{
                                $page.props.transaction_backend
                            }}</strong
                            >. Balance queries, claims, and refunds will still
                            use the original
                            <strong>{{ wallet_backend }}</strong> backend as
                            long as its credentials remain configured.
                        </v-alert>
                        <v-alert
                            type="info"
                            v-if="wallet_pending"
                            border="start"
                            class="mb-4"
                            density="compact"
                            icon="mdi-clock-outline"
                        >
                            <v-alert-title>WALLET PROVISIONING</v-alert-title>
                            Your campaign bucket is being provisioned. Refresh
                            to check.
                        </v-alert>
                        <h3>
                            {{ campaign.name }}
                            <v-chip label class="text-capitalize">{{
                                campaign.network
                            }}</v-chip>
                            <v-btn
                                size="small"
                                color="primary"
                                variant="tonal"
                                @click="openEditDialog"
                                class="ms-2"
                            >
                                <v-icon icon="mdi-pencil" class="me-1" />
                                Edit
                            </v-btn>
                        </h3>
                        <p
                            class="font-italic text-sm text-gray-600 dark:text-gray-400"
                        >
                            {{ campaign.description }}
                        </p>
                        <p>
                            Redemption Period: {{ campaign.start_date }} through
                            {{ campaign.end_date }}
                            <v-chip
                                v-if="campaign.one_per_wallet"
                                label
                                size="small"
                                class="ms-2"
                                >One Per Wallet</v-chip
                            >
                        </p>
                        <p v-if="campaign.txn_msg">
                            Transaction Message: {{ campaign.txn_msg }}
                        </p>
                        <!-- Claim URL + wallet address shown in full (never truncated) so users can
                             verify every character before copying/sending. Values are rendered as
                             escaped text (no v-html), so they can't inject markup or script. -->
                        <div class="mt-3">
                            <div class="text-medium-emphasis text-caption">
                                Claim URL
                            </div>
                            <div class="d-flex align-start">
                                <code
                                    class="font-mono text-body-2 flex-grow-1"
                                    style="word-break: break-all"
                                    >{{ claim_url }}</code
                                >
                                <v-btn
                                    icon
                                    variant="text"
                                    size="x-small"
                                    class="ms-1 flex-shrink-0"
                                    @click="copyText(claim_url)"
                                >
                                    <v-icon
                                        icon="mdi-content-copy"
                                        size="small"
                                    ></v-icon>
                                    <v-tooltip activator="parent" location="top"
                                        >Copy claim URL</v-tooltip
                                    >
                                </v-btn>
                            </div>
                        </div>
                        <div v-if="campaign.wallet" class="mt-2">
                            <div
                                class="text-medium-emphasis text-caption d-flex align-center ga-2"
                            >
                                Wallet Address
                                <v-menu
                                    location="bottom start"
                                    :close-on-content-click="false"
                                >
                                    <template #activator="{ props }">
                                        <v-chip
                                            v-bind="props"
                                            :color="bucketBadge.color"
                                            size="x-small"
                                            label
                                            link
                                            :aria-label="`Bucket ${bucketBadge.label} — view contents`"
                                        >
                                            <v-icon
                                                start
                                                :icon="bucketBadge.icon"
                                                size="x-small"
                                            ></v-icon
                                            >{{ bucketBadge.label }}
                                        </v-chip>
                                    </template>
                                    <v-card min-width="260" rounded="lg" border>
                                        <v-list density="compact" class="py-1">
                                            <v-list-subheader
                                                >Bucket
                                                contents</v-list-subheader
                                            >
                                            <v-list-item
                                                prepend-icon="mdi-cardano"
                                                :title="`${formatAda(toAda(wallet_balance.lovelace))} ADA`"
                                            >
                                                <v-list-item-subtitle
                                                    v-if="
                                                        wallet_balance.token_count
                                                    "
                                                >
                                                    {{
                                                        wallet_balance.token_count
                                                    }}
                                                    token{{
                                                        wallet_balance.token_count ===
                                                        1
                                                            ? ""
                                                            : "s"
                                                    }}
                                                    across
                                                    {{
                                                        wallet_balance.policy_count
                                                    }}
                                                    polic{{
                                                        wallet_balance.policy_count ===
                                                        1
                                                            ? "y"
                                                            : "ies"
                                                    }}
                                                </v-list-item-subtitle>
                                                <v-list-item-subtitle v-else
                                                    >No tokens
                                                    held</v-list-item-subtitle
                                                >
                                            </v-list-item>
                                        </v-list>
                                        <template
                                            v-if="wallet_balance.token_count"
                                        >
                                            <v-divider></v-divider>
                                            <div class="pa-2">
                                                <WalletTokenList
                                                    :tokens="balanceTokens"
                                                />
                                            </div>
                                        </template>
                                        <template v-if="wallet_missing">
                                            <v-divider></v-divider>
                                            <v-list
                                                density="compact"
                                                class="py-1"
                                            >
                                                <v-list-subheader
                                                    class="text-warning"
                                                    >Still
                                                    needed</v-list-subheader
                                                >
                                                <v-list-item
                                                    prepend-icon="mdi-progress-wrench"
                                                    :title="`${formatAda(toAda(wallet_missing.lovelace))} ADA`"
                                                    :subtitle="
                                                        missingTokens.length
                                                            ? `${missingTokens.length} token type${missingTokens.length === 1 ? '' : 's'}`
                                                            : null
                                                    "
                                                />
                                            </v-list>
                                        </template>
                                    </v-card>
                                </v-menu>
                            </div>
                            <div class="d-flex align-start">
                                <code
                                    class="font-mono text-body-2 flex-grow-1"
                                    style="word-break: break-all"
                                    >{{ campaign.wallet.address }}</code
                                >
                                <v-btn
                                    icon
                                    variant="text"
                                    size="x-small"
                                    class="ms-1 flex-shrink-0"
                                    @click="copyText(campaign.wallet.address)"
                                >
                                    <v-icon
                                        icon="mdi-content-copy"
                                        size="small"
                                    ></v-icon>
                                    <v-tooltip activator="parent" location="top"
                                        >Copy wallet address</v-tooltip
                                    >
                                </v-btn>
                            </div>
                        </div>
                    </div>
                    <!-- Codes the chain will refuse. Deliberately outside the funding card:
                         no amount of ADA in the bucket fixes an output that is below its
                         own minimum, and showing it as a shortfall would send an operator
                         to top up a wallet that is already full enough. -->
                    <v-alert
                        v-if="min_utxo.below_minimum_codes > 0"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                        border="start"
                        data-test="codes-below-minimum"
                    >
                        <v-alert-title>
                            {{ min_utxo.below_minimum_codes }} code{{
                                min_utxo.below_minimum_codes === 1 ? "" : "s"
                            }}
                            cannot be paid
                        </v-alert-title>
                        Their ADA is below the minimum an output carrying their
                        tokens has to hold, so the chain will reject the payment
                        however much is in the bucket. Open the code in the
                        table below and raise its reward.
                    </v-alert>
                    <v-alert
                        v-else-if="min_utxo.tight_codes > 0"
                        type="warning"
                        variant="tonal"
                        class="mb-4"
                        border="start"
                        density="compact"
                        data-test="codes-tight"
                    >
                        {{ min_utxo.tight_codes }} code{{
                            min_utxo.tight_codes === 1 ? "" : "s"
                        }}
                        pay just enough to be accepted and nothing to spend.
                        Claimants with no other ADA will not be able to move
                        what they receive.
                    </v-alert>
                    <!-- The one loud, directive state: still-open campaign that needs funding.
                         Funded / empty / ended states collapse to the badge by the address. -->
                    <v-card
                        v-if="campaign.wallet && wallet_missing && !isEnded"
                        variant="tonal"
                        color="warning"
                        class="mb-4"
                        rounded="lg"
                        border
                    >
                        <v-card-item>
                            <template #prepend>
                                <v-icon
                                    icon="mdi-progress-wrench"
                                    size="large"
                                ></v-icon>
                            </template>
                            <v-card-title>{{
                                wallet_empty
                                    ? "Fund your campaign bucket"
                                    : "Almost there — top up what's left"
                            }}</v-card-title>
                            <v-card-subtitle class="text-wrap">
                                Add the amounts below so every unclaimed code
                                can pay out its reward.
                            </v-card-subtitle>
                            <template #append>
                                <v-btn
                                    :icon="
                                        fundingOpen
                                            ? 'mdi-chevron-up'
                                            : 'mdi-chevron-down'
                                    "
                                    variant="text"
                                    size="small"
                                    @click="fundingOpen = !fundingOpen"
                                    :aria-label="
                                        fundingOpen
                                            ? 'Collapse funding details'
                                            : 'Expand funding details'
                                    "
                                >
                                    <v-icon
                                        :icon="
                                            fundingOpen
                                                ? 'mdi-chevron-up'
                                                : 'mdi-chevron-down'
                                        "
                                    ></v-icon>
                                </v-btn>
                            </template>
                        </v-card-item>
                        <v-expand-transition>
                            <div v-show="fundingOpen">
                                <v-card-text>
                                    <div class="d-flex align-center py-1">
                                        <v-avatar
                                            size="36"
                                            color="primary"
                                            class="me-3"
                                            rounded="lg"
                                        >
                                            <v-icon icon="mdi-cardano"></v-icon>
                                        </v-avatar>
                                        <div
                                            class="flex-grow-1 font-weight-medium"
                                        >
                                            ADA
                                        </div>
                                        <div class="font-weight-bold">
                                            {{
                                                formatAda(
                                                    toAda(
                                                        wallet_missing.lovelace,
                                                    ),
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <!-- What that ADA is for. The top-up has always covered the fees as
                                         well as the rewards, so a full bucket serves every code that can
                                         still be claimed, but it was a single number and an operator
                                         could not see how much of it reached claimants. -->
                                    <v-list
                                        density="compact"
                                        class="bg-transparent py-0 ps-12"
                                    >
                                        <v-list-item
                                            v-for="part in fundingParts"
                                            :key="part.label"
                                            class="px-0"
                                        >
                                            <v-list-item-title
                                                class="text-body-2"
                                            >
                                                {{ part.label }}
                                            </v-list-item-title>
                                            <v-list-item-subtitle
                                                class="text-caption"
                                            >
                                                {{ part.hint }}
                                            </v-list-item-subtitle>
                                            <template #append>
                                                <span
                                                    class="text-body-2 text-no-wrap"
                                                >
                                                    {{
                                                        formatAda(
                                                            toAda(part.value),
                                                        )
                                                    }}
                                                </span>
                                            </template>
                                        </v-list-item>
                                    </v-list>
                                    <template v-if="missingTokens.length">
                                        <v-divider class="my-2"></v-divider>
                                        <WalletTokenList
                                            :tokens="missingTokens"
                                        />
                                    </template>
                                </v-card-text>
                                <v-card-actions class="px-4 pb-4">
                                    <v-btn
                                        v-if="cardano.status === 'found'"
                                        color="primary"
                                        variant="flat"
                                        prepend-icon="mdi-wallet-plus"
                                        :disabled="isEnded"
                                        @click="dialog.wallet = true"
                                    >
                                        {{
                                            isEnded
                                                ? "Campaign ended"
                                                : "Top Up Bucket"
                                        }}
                                    </v-btn>
                                    <span
                                        v-else
                                        class="text-caption text-medium-emphasis"
                                    >
                                        Connect a wallet (top right) to fund
                                        this bucket.
                                    </span>
                                </v-card-actions>
                            </div>
                        </v-expand-transition>
                    </v-card>

                    <!-- What this campaign may spend of the account's credit. Next to
                         the funding panel rather than in the campaign settings dialog,
                         because it answers a funding question and its answer is read
                         against what the campaign has already cost. -->
                    <v-card
                        v-if="canSetSpendLimit"
                        variant="tonal"
                        class="mb-4"
                        rounded="lg"
                        border
                    >
                        <v-card-item>
                            <template #prepend>
                                <v-icon icon="mdi-speedometer"></v-icon>
                            </template>
                            <v-card-title>Spend limit</v-card-title>
                            <v-card-subtitle class="text-wrap">{{
                                spendLimitSummary
                            }}</v-card-subtitle>
                            <template #append>
                                <v-btn
                                    variant="text"
                                    size="small"
                                    prepend-icon="mdi-pencil"
                                    @click="openSpendLimitDialog"
                                >
                                    {{
                                        spending.limit_credits === null
                                            ? "Set a limit"
                                            : "Change"
                                    }}
                                </v-btn>
                            </template>
                        </v-card-item>
                    </v-card>

                    <CampaignCharts
                        v-if="campaign.codes && campaign.codes.length > 0"
                        :campaign="campaign"
                        :stats="stats"
                        :onboarding="onboarding"
                        :onboarding-task="onboardingTask"
                        :claim-check-running="claimCheckRunning"
                        :wallet-clients="wallet_clients"
                        :costs="costs"
                        :partner-conversion="partner_conversion"
                    />
                    <v-toolbar color="transparent">
                        <v-toolbar-title>Campaign Codes</v-toolbar-title>
                        <v-spacer></v-spacer>
                        <span class="ms-1 d-inline-flex">
                            <v-btn
                                icon
                                color="primary"
                                variant="tonal"
                                :disabled="isEnded"
                                data-test="add-code"
                                @click="openCodeDialog"
                            >
                                <v-icon icon="mdi-plus"></v-icon>
                            </v-btn>
                            <v-tooltip activator="parent" location="top">
                                {{
                                    isEnded
                                        ? "Campaign has ended — extend the end date to add codes"
                                        : "Create Code"
                                }}
                            </v-tooltip>
                        </span>
                        <span class="ms-1 d-inline-flex">
                            <v-btn
                                icon
                                color="secondary"
                                variant="tonal"
                                :disabled="isEnded"
                                @click="dialog.import = true"
                            >
                                <v-icon icon="mdi-cloud-upload"></v-icon>
                            </v-btn>
                            <v-tooltip activator="parent" location="top">
                                {{
                                    isEnded
                                        ? "Campaign has ended — extend the end date to import codes"
                                        : "Import Codes"
                                }}
                            </v-tooltip>
                        </span>
                        <span
                            class="ms-1 d-inline-flex"
                            v-if="selectedDeletable.length > 0"
                        >
                            <v-btn
                                icon
                                color="red"
                                variant="tonal"
                                @click="confirmDeleteCodes('selected')"
                            >
                                <v-icon icon="mdi-trash-can"></v-icon>
                            </v-btn>
                            <v-tooltip activator="parent" location="top">
                                Delete {{ selectedDeletable.length }} selected
                                code(s)
                            </v-tooltip>
                        </span>
                        <!-- Labelled rather than an icon with a tooltip. This is the
                             action most likely to be needed before the onboarding
                             analysis, which cannot see a claim until its status has been
                             checked, and it was the hardest control on the page to
                             find. -->
                        <v-btn
                            color="accent"
                            variant="tonal"
                            class="ms-1"
                            prepend-icon="mdi-reload"
                            @click="checkClaimedStatus"
                            :loading="checkingClaims"
                        >
                            Check claims
                            <v-tooltip activator="parent" location="top"
                                >Ask the transaction backend what happened to
                                claims that have no confirmed transaction
                                yet</v-tooltip
                            >
                        </v-btn>
                        <v-btn
                            icon
                            color="info"
                            variant="tonal"
                            class="ms-1"
                            @click="dialog.qr_export = true"
                            v-if="campaign.codes && campaign.codes.length > 0"
                        >
                            <v-icon icon="mdi-qrcode"></v-icon>
                            <v-tooltip activator="parent" location="top"
                                >Export QR Codes</v-tooltip
                            >
                        </v-btn>
                        <span
                            class="ms-1 d-inline-flex"
                            v-if="cardano.status === 'found' && campaign.wallet"
                        >
                            <v-btn
                                icon
                                color="success"
                                variant="tonal"
                                :disabled="noShortfall || isEnded"
                                @click="dialog.wallet = true"
                            >
                                <v-icon icon="mdi-wallet-plus"></v-icon>
                            </v-btn>
                            <v-tooltip activator="parent" location="top">{{
                                topUpTooltip
                            }}</v-tooltip>
                        </span>
                        <span class="ms-1 d-inline-flex" v-if="campaign.wallet">
                            <v-btn
                                icon
                                color="warning"
                                variant="tonal"
                                :disabled="wallet_empty"
                                @click="dialog.refund = true"
                            >
                                <v-icon icon="mdi-cash-refund"></v-icon>
                            </v-btn>
                            <v-tooltip activator="parent" location="top">
                                {{
                                    wallet_empty
                                        ? "Bucket is empty — nothing to refund"
                                        : "Refund Bucket"
                                }}
                            </v-tooltip>
                        </span>
                    </v-toolbar>
                    <v-row class="mt-1 mb-1" align="center" no-gutters>
                        <v-col cols="12" sm="4" md="3">
                            <v-select
                                v-model="codeFilter"
                                :items="[
                                    { title: 'All Codes', value: 'all' },
                                    { title: 'Claimed', value: 'claimed' },
                                    { title: 'Unclaimed', value: 'unclaimed' },
                                    { title: 'Available', value: 'available' },
                                    { title: 'Exhausted', value: 'exhausted' },
                                ]"
                                label="Filter"
                                density="compact"
                                hide-details
                                variant="outlined"
                            />
                        </v-col>
                        <v-col
                            v-if="campaignUsesPartners"
                            cols="12"
                            sm="4"
                            md="3"
                        >
                            <v-select
                                v-model="partnerFilter"
                                :items="partnerFilterOptions"
                                label="Partner"
                                density="compact"
                                hide-details
                                variant="outlined"
                                class="ms-sm-2"
                            />
                        </v-col>
                        <v-col
                            cols="12"
                            :sm="campaignUsesPartners ? 4 : 8"
                            :md="campaignUsesPartners ? 6 : 9"
                        >
                            <v-text-field
                                v-model="dataTable.search"
                                append-icon="mdi-magnify"
                                label="Search"
                                single-line
                                hide-details
                                density="compact"
                                variant="outlined"
                                class="ms-sm-2"
                            ></v-text-field>
                        </v-col>
                    </v-row>
                    <v-data-table
                        :items="filteredCodes"
                        :items-per-page="dataTable.perPage"
                        v-model="dataTable.selected"
                        :search="dataTable.search"
                        :headers="codeHeaders"
                        item-value="id"
                        return-object
                        show-select
                        show-expand
                    >
                        <template v-slot:item.partner.name="{ item }">
                            <span v-if="item.partner">{{
                                item.partner.name
                            }}</span>
                            <span v-else class="text-medium-emphasis"
                                >Unassigned</span
                            >
                        </template>
                        <template v-slot:item.id="{ item }">
                            <v-btn color="primary" @click="showQR(item)">
                                <v-icon icon="mdi-qrcode"></v-icon>
                            </v-btn>
                            <!-- Hidden rather than disabled on an ended campaign: nothing
                                 can be claimed after the window closes, so there is no
                                 forward for an edit to apply to. -->
                            <v-btn
                                v-if="!isEnded"
                                color="primary"
                                variant="text"
                                class="ms-1"
                                data-test="edit-code"
                                @click="openEditCode(item)"
                            >
                                <v-icon icon="mdi-pencil"></v-icon>
                                <v-tooltip activator="parent" location="top"
                                    >Change this code's reward</v-tooltip
                                >
                            </v-btn>
                            <v-btn
                                v-if="(item.claims_count ?? 0) === 0"
                                color="red"
                                variant="text"
                                class="ms-1"
                                @click="deleteSingleCode(item)"
                            >
                                <v-icon icon="mdi-trash-can"></v-icon>
                                <v-tooltip activator="parent" location="top"
                                    >Delete this code</v-tooltip
                                >
                            </v-btn>
                        </template>
                        <template v-slot:expanded-row="{ columns, item }">
                            <tr>
                                <td :colspan="columns.length" class="py-3">
                                    <div class="text-subtitle-2 mb-2">
                                        Reward Details
                                    </div>
                                    <v-chip
                                        class="me-2 mb-2"
                                        color="primary"
                                        label
                                        size="small"
                                    >
                                        <v-icon
                                            start
                                            icon="mdi-cardano"
                                        ></v-icon>
                                        {{ formatAda(toAda(item.lovelace)) }}
                                        ADA
                                    </v-chip>
                                    <span
                                        v-if="
                                            !item.rewards ||
                                            item.rewards.length === 0
                                        "
                                        class="text-medium-emphasis text-body-2"
                                        >No native-token rewards on this
                                        code.</span
                                    >
                                    <v-table v-else density="compact">
                                        <thead>
                                            <tr>
                                                <th class="text-left">Token</th>
                                                <th class="text-left">
                                                    Quantity
                                                </th>
                                                <th class="text-left">
                                                    Policy
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="reward in item.rewards"
                                                :key="
                                                    reward.policy_hex +
                                                    reward.asset_hex
                                                "
                                            >
                                                <td>
                                                    <v-avatar
                                                        v-if="
                                                            tokenMeta[
                                                                reward.policy_hex +
                                                                    reward.asset_hex
                                                            ]?.logo
                                                        "
                                                        size="20"
                                                        class="me-1"
                                                    >
                                                        <v-img
                                                            :src="`data:image/png;base64,${tokenMeta[reward.policy_hex + reward.asset_hex].logo}`"
                                                        ></v-img>
                                                    </v-avatar>
                                                    {{
                                                        rewardDisplayName(
                                                            reward,
                                                        )
                                                    }}
                                                </td>
                                                <td>
                                                    {{
                                                        rewardDisplayQuantity(
                                                            reward,
                                                        )
                                                    }}
                                                    <v-chip
                                                        v-if="
                                                            rewardDecimals(
                                                                reward,
                                                            ) > 0
                                                        "
                                                        size="x-small"
                                                        variant="tonal"
                                                        color="info"
                                                        class="ms-1"
                                                        :title="`${rewardRawQuantity(reward)} base units (${rewardDecimals(reward)} decimals)`"
                                                    >
                                                        <v-icon
                                                            start
                                                            icon="mdi-decimal"
                                                            size="x-small"
                                                        ></v-icon>
                                                        {{
                                                            rewardDecimals(
                                                                reward,
                                                            )
                                                        }}
                                                        decimals
                                                    </v-chip>
                                                </td>
                                                <td>
                                                    <code class="text-caption"
                                                        >{{
                                                            reward.policy_hex.slice(
                                                                0,
                                                                8,
                                                            )
                                                        }}…{{
                                                            reward.policy_hex.slice(
                                                                -6,
                                                            )
                                                        }}</code
                                                    >
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
        <v-dialog
            v-model="dialog.code"
            width="auto"
            transition="dialog-bottom-transition"
            persistent
        >
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Create New Code</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.code = false">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-card-text>
                    This will create codes for this campaign. Set quantity to
                    generate multiple codes with the same configuration.
                </v-card-text>
                <v-form @submit.prevent="createCode">
                    <v-card-text>
                        <PartnerPicker
                            class="mb-4"
                            :partners="partners"
                            :model-value="partnerSelection(code)"
                            :name-error="$page.props.errors.partner_name ?? []"
                            @update:model-value="
                                (selection) => applyPartner(code, selection)
                            "
                        />
                        <v-text-field
                            label="Quantity"
                            v-model="code.quantity"
                            required
                            min="1"
                            max="500"
                            step="1"
                            type="number"
                            hint="Number of codes to generate (1-500). Each code gets a unique ULID."
                            persistent-hint
                            :rules="rules.quantity"
                        />
                        <v-text-field
                            label="Uses"
                            v-model="code.uses"
                            required
                            min="0"
                            step="1"
                            type="number"
                            hint="Set this to 0 to create a code with unlimited uses. This is not recommended!"
                            persistent-hint
                        />
                        <v-text-field
                            label="Claims Per Wallet"
                            v-model="code.perWallet"
                            required
                            min="0"
                            step="1"
                            type="number"
                            hint="Set this to 1 to limit the code to one-per-wallet claiming. Set to 0 for unlimited claims per wallet [NOT RECOMMENDED]."
                            persistent-hint
                        />
                        <v-text-field
                            label="Lovelace"
                            v-model="code.lovelace"
                            required
                            min="1000000"
                            step="1"
                            type="number"
                            hint="The amount of Lovelace to send when this code is claimed."
                            persistent-hint
                            :rules="rules.lovelace"
                        />
                        <!-- What this bundle has to be worth, from the server, so the
                             figure quoted here is the figure the save enforces. -->
                        <div
                            v-if="codeQuote.min_lovelace > 0"
                            class="text-caption text-medium-emphasis mt-1"
                            data-test="min-utxo-hint"
                        >
                            Minimum for
                            {{
                                codeQuote.asset_count === 1
                                    ? "1 asset"
                                    : `${codeQuote.asset_count} assets`
                            }}
                            across
                            {{
                                codeQuote.policy_count === 1
                                    ? "1 policy"
                                    : `${codeQuote.policy_count} policies`
                            }}:
                            <strong
                                >{{
                                    adaText(codeQuote.min_lovelace)
                                }}
                                ADA</strong
                            >. Suggested
                            <strong
                                >{{ adaText(suggestedLovelace) }} ADA</strong
                            >
                            so the claimant can pay a fee and move it.
                        </div>
                        <v-alert
                            v-if="codeBelowMinimum"
                            type="error"
                            density="compact"
                            variant="tonal"
                            class="mt-2"
                            data-test="min-utxo-below"
                        >
                            This is below what the chain will accept for these
                            tokens. A claim on it could never be sent.
                        </v-alert>
                        <v-alert
                            v-else-if="codeIsTight"
                            type="warning"
                            density="compact"
                            variant="tonal"
                            class="mt-2"
                            data-test="min-utxo-tight"
                        >
                            This clears the minimum with nothing to spare. A
                            claimant whose wallet is minutes old has no other
                            ADA to pay a fee with, so the tokens will sit where
                            you sent them. You can create it anyway.
                        </v-alert>
                        <v-text-field
                            label="NMKR Project UID"
                            v-model="code.nmkr_project_uid"
                            hint="Optional — NMKR project UID to mint an NFT on claim. Leave blank to skip."
                            persistent-hint
                        />
                        <v-text-field
                            label="NFTs Per Claim"
                            v-model="code.nmkr_count_nft"
                            min="0"
                            step="1"
                            type="number"
                            hint="Number of NFTs to mint per claim from the NMKR project. Set to 0 to disable."
                            persistent-hint
                        />
                    </v-card-text>
                    <v-card-text v-if="Object.keys($page.props.errors).length">
                        <v-alert type="error" title="Errors">
                            <p v-for="error in $page.props.errors" :key="error">
                                {{ error }}
                            </p>
                        </v-alert>
                    </v-card-text>
                    <v-card-title>Tokens</v-card-title>
                    <v-card-text
                        v-if="code.tokens.length === 0"
                        class="text-medium-emphasis"
                    >
                        No native tokens yet — this code pays ADA only. Add a
                        token to sweeten the reward.
                    </v-card-text>
                    <v-card-text v-else class="pt-0">
                        <WalletTokenList
                            :tokens="codeTokensDisplay"
                            removable
                            @remove="removeCodeToken"
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-btn
                            type="button"
                            color="dark"
                            @click="addToken('create')"
                            :disabled="code.processing"
                        >
                            Add Token
                        </v-btn>
                        <v-btn
                            type="submit"
                            color="primary"
                            :disabled="code.processing"
                        >
                            Create Code
                        </v-btn>
                        <v-btn
                            type="button"
                            color="red"
                            :disabled="code.processing"
                            @click="resetCode()"
                        >
                            Cancel
                        </v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="dialog.edit_code"
            width="auto"
            transition="dialog-bottom-transition"
            persistent
        >
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Change Reward</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="closeEditCode">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-card-text>
                    Changing what
                    <strong>{{ editingCode?.code }}</strong>
                    pays out. The code, its QR and its usage limits are not
                    affected, so anything already printed keeps working.
                </v-card-text>
                <!-- Who the change reaches. A claim records what it was paid when it was
                     paid, so a claim already sent is untouched by anything set here. One
                     accepted and not yet sent is a different matter: that claimant has
                     been shown what they were promised and will receive this instead. -->
                <v-card-text
                    v-if="editSettledClaims > 0"
                    class="pt-0"
                    data-test="edit-settled"
                >
                    <v-alert
                        type="info"
                        density="compact"
                        variant="tonal"
                        icon="mdi-history"
                    >
                        {{ editSettledClaims }} claim{{
                            editSettledClaims === 1 ? " has" : "s have"
                        }}
                        already been paid from this code and will keep what
                        {{ editSettledClaims === 1 ? "it was" : "they were" }}
                        sent. This change applies to claims from here on.
                    </v-alert>
                </v-card-text>
                <v-card-text
                    v-if="editPendingClaims > 0"
                    class="pt-0"
                    data-test="edit-pending"
                >
                    <v-alert
                        type="warning"
                        density="compact"
                        variant="tonal"
                        icon="mdi-clock-alert-outline"
                    >
                        {{ editPendingClaims }} claim{{
                            editPendingClaims === 1 ? " has" : "s have"
                        }}
                        been accepted and not sent yet.
                        {{ editPendingClaims === 1 ? "It" : "They" }}
                        will be paid the new reward, not the one
                        {{ editPendingClaims === 1 ? "its" : "their" }}
                        claimant was shown.
                    </v-alert>
                </v-card-text>
                <v-form @submit.prevent="saveCodeRewards">
                    <v-card-text>
                        <v-text-field
                            label="Lovelace"
                            v-model="editCode.lovelace"
                            required
                            min="1000000"
                            step="1"
                            type="number"
                            hint="The amount of Lovelace to send when this code is claimed."
                            persistent-hint
                            :rules="rules.editLovelace"
                        />
                        <div
                            v-if="editQuote.min_lovelace > 0"
                            class="text-caption text-medium-emphasis mt-1"
                            data-test="edit-min-utxo-hint"
                        >
                            Minimum for
                            {{
                                editQuote.asset_count === 1
                                    ? "1 asset"
                                    : `${editQuote.asset_count} assets`
                            }}
                            across
                            {{
                                editQuote.policy_count === 1
                                    ? "1 policy"
                                    : `${editQuote.policy_count} policies`
                            }}:
                            <strong
                                >{{
                                    adaText(editQuote.min_lovelace)
                                }}
                                ADA</strong
                            >. Suggested
                            <strong
                                >{{
                                    adaText(suggestedEditLovelace)
                                }}
                                ADA</strong
                            >
                            so the claimant can pay a fee and move it.
                        </div>
                        <v-alert
                            v-if="editBelowMinimum"
                            type="error"
                            density="compact"
                            variant="tonal"
                            class="mt-2"
                            data-test="edit-min-utxo-below"
                        >
                            This is below what the chain will accept for these
                            tokens. A claim on it could never be sent.
                        </v-alert>
                        <v-alert
                            v-else-if="editIsTight"
                            type="warning"
                            density="compact"
                            variant="tonal"
                            class="mt-2"
                            data-test="edit-min-utxo-tight"
                        >
                            This clears the minimum with nothing to spare. A
                            claimant whose wallet is minutes old has no other
                            ADA to pay a fee with, so the tokens will sit where
                            you sent them. You can save it anyway.
                        </v-alert>
                        <v-text-field
                            label="NMKR Project UID"
                            v-model="editCode.nmkr_project_uid"
                            hint="Optional — NMKR project UID to mint an NFT on claim. Leave blank to skip."
                            persistent-hint
                        />
                        <v-text-field
                            label="NFTs Per Claim"
                            v-model="editCode.nmkr_count_nft"
                            min="0"
                            step="1"
                            type="number"
                            hint="Number of NFTs to mint per claim from the NMKR project. Set to 0 to disable."
                            persistent-hint
                        />
                    </v-card-text>
                    <v-card-text v-if="Object.keys(editCode.errors).length">
                        <v-alert type="error" title="Errors">
                            <p v-for="error in editCode.errors" :key="error">
                                {{ error }}
                            </p>
                        </v-alert>
                    </v-card-text>
                    <v-card-title>Tokens</v-card-title>
                    <v-card-text
                        v-if="editCode.tokens.length === 0"
                        class="text-medium-emphasis"
                    >
                        No native tokens — this code pays ADA only.
                    </v-card-text>
                    <v-card-text v-else class="pt-0">
                        <WalletTokenList
                            :tokens="editTokensDisplay"
                            removable
                            @remove="removeEditToken"
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-btn
                            type="button"
                            color="dark"
                            @click="addToken('edit')"
                            :disabled="editCode.processing"
                        >
                            Add Token
                        </v-btn>
                        <v-btn
                            type="submit"
                            color="primary"
                            data-test="save-rewards"
                            :disabled="editCode.processing"
                        >
                            Save Rewards
                        </v-btn>
                        <v-btn
                            type="button"
                            color="red"
                            :disabled="editCode.processing"
                            @click="closeEditCode"
                        >
                            Cancel
                        </v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="dialog.token"
            width="auto"
            transition="dialog-top-transition"
            persistent
        >
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Add Token to Code</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.token = false">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-card-text>
                    The specified token will be added to this code and
                    distributed whenever the code is claimed. Search for a known
                    token by ticker/name, or enter the Policy ID and Token ID
                    manually.
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
                        <v-text-field
                            label="Policy ID"
                            v-model="token.policy_id"
                            required
                        >
                            <template v-slot:append>
                                <v-btn
                                    size="small"
                                    variant="tonal"
                                    :loading="tokenLookup.loading"
                                    @click="lookupTokenOnChain"
                                >
                                    <v-icon start icon="mdi-cloud-search" />Look
                                    up
                                </v-btn>
                            </template>
                        </v-text-field>
                        <v-text-field
                            label="Token ID"
                            v-model="token.token_id"
                            required
                        />
                        <v-alert
                            v-if="tokenLookup.result"
                            type="success"
                            variant="tonal"
                            density="compact"
                            class="mb-2"
                        >
                            Resolved:
                            <strong>{{
                                tokenLookup.result.ticker ||
                                tokenLookup.result.name
                            }}</strong>
                            <span v-if="tokenLookup.result.decimals">
                                ({{
                                    tokenLookup.result.decimals
                                }}
                                decimals)</span
                            >
                        </v-alert>
                        <v-alert
                            v-if="tokenLookup.error"
                            type="warning"
                            variant="tonal"
                            density="compact"
                            class="mb-2"
                        >
                            {{ tokenLookup.error }}
                        </v-alert>
                        <v-text-field
                            :label="token.decimals > 0 ? 'Amount' : 'Quantity'"
                            v-model="token.quantity"
                            required
                            min="0"
                            type="number"
                            :step="token.decimals > 0 ? 'any' : '1'"
                            :suffix="
                                tokenLookup.result
                                    ? tokenLookup.result.ticker ||
                                      tokenLookup.result.name ||
                                      ''
                                    : ''
                            "
                            :hint="
                                tokenBaseUnitsPreview
                                    ? `= ${tokenBaseUnitsPreview} base units (${token.decimals} decimals) sent on-chain`
                                    : 'Whole number of base units sent on-chain'
                            "
                            persistent-hint
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-btn type="submit" color="primary">Add Token</v-btn>
                        <v-btn type="button" color="red" @click="cancelAddToken"
                            >Cancel</v-btn
                        >
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="qrViewer.show"
            width="auto"
            transition="dialog-bottom-transition"
        >
            <v-card>
                <v-card-text>
                    <qrcode-vue
                        :value="qrViewer.code_uri"
                        :size="512"
                        render-as="svg"
                        margin="5"
                    ></qrcode-vue>
                </v-card-text>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="dialog.import"
            width="512"
            transition="dialog-bottom-transition"
        >
            <v-card>
                <v-toolbar color="secondary">
                    <v-toolbar-title>Import Codes</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.import = false">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-card-text>
                    Use this form to upload a JSON file containing codes + token
                    rewards.
                </v-card-text>
                <v-form @submit.prevent="importCodes">
                    <v-card-text>
                        <!--                        <v-file-input v-model="imported.uploadedCodes" accept=".json" label="Codes File" required
                                                              name="uploadedCodes" :multiple="false"></v-file-input>-->
                        <PartnerPicker
                            class="mb-4"
                            :partners="partners"
                            :model-value="partnerSelection(imported)"
                            :name-error="$page.props.errors.partner_name ?? []"
                            @update:model-value="
                                (selection) => applyPartner(imported, selection)
                            "
                        />
                        <v-file-input
                            accept=".json"
                            :label="`Codes File (max ${maxFileSizeMB}MB)`"
                            required
                            name="uploadedCodes"
                            :multiple="false"
                            id="import_file"
                            ref="file_ref"
                            :rules="[
                                (v) =>
                                    !v ||
                                    !v.length ||
                                    v[0].size < props.max_file_size ||
                                    `File must be less than ${maxFileSizeMB}MB`,
                            ]"
                        ></v-file-input>
                        <v-progress-linear
                            height="12"
                            color="primary"
                            v-if="imported.progress"
                            v-model="imported.progress"
                        ></v-progress-linear>
                        <!--                        <progress v-if="imported.progress" :value="imported.progress.percentage" max="100">-->
                        <!--                            {{ imported.progress.percentage }}%-->
                        <!--                        </progress>-->
                    </v-card-text>
                    <v-card-actions>
                        <v-btn type="submit" color="primary">Import</v-btn>
                        <v-btn
                            type="button"
                            color="red"
                            @click="
                                imported.reset();
                                dialog.import = false;
                            "
                            >Cancel</v-btn
                        >
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="dialog.wallet"
            width="512"
            transition="dialog-bottom-transition"
            persistent
            scrollable
        >
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Connect Your Wallet</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.wallet = false">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-card-text>
                    Please choose the wallet you'd like to connect
                    <div class="pt-5">
                        <v-btn
                            v-for="wallet in cardano.Wallets"
                            :key="wallet.name"
                            block
                            class="wallet-btn mb-2 text-start"
                            x-large
                            @click="connectTo(wallet)"
                            :loading="wallet.loading"
                        >
                            <v-img
                                :src="wallet.icon"
                                width="24"
                                height="24"
                                class="me-2"
                                contain
                                :alt="wallet.name"
                            ></v-img>
                            Connect {{ wallet.name.replace(" Wallet", "") }}
                        </v-btn>
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="dialog.wallet_balance"
            width="512"
            transition="dialog-bottom-transition"
            persistent
            scrollable
        >
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Top Up Campaign Wallet</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn
                        icon
                        @click="
                            dialog.wallet_balance = false;
                            disconnect();
                        "
                    >
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-card-text>
                    <v-progress-linear
                        indeterminate
                        height="8"
                        color="primary"
                        v-if="connectedWalletDetails.checkingBalance"
                        class="mb-4"
                    ></v-progress-linear>
                    <template v-if="!connectedWalletDetails.checkingBalance">
                        <p class="mb-2">
                            Connected wallet:
                            <strong>{{
                                cardano.ActiveWallet?.name || "Unknown"
                            }}</strong>
                        </p>
                        <p class="mb-2">
                            UTxOs found:
                            <strong>{{
                                connectedWalletDetails.utxos.length
                            }}</strong>
                        </p>
                        <p class="mb-4">
                            Campaign wallet:
                            <code class="text-caption">{{
                                campaign.wallet?.address
                            }}</code>
                        </p>
                        <v-alert
                            v-if="dialog.show_toast"
                            type="success"
                            class="mb-4"
                            closable
                            @click:close="dialog.show_toast = false"
                        >
                            Transaction submitted successfully! It may take a
                            few minutes to confirm on-chain. Refresh to check
                            the updated balance.
                        </v-alert>
                    </template>
                </v-card-text>
                <v-card-actions v-if="!connectedWalletDetails.checkingBalance">
                    <v-btn
                        color="red"
                        variant="text"
                        @click="
                            dialog.wallet_balance = false;
                            disconnect();
                        "
                    >
                        Disconnect
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="error.show"
            width="512"
            transition="dialog-bottom-transition"
            persistent
        >
            <v-card color="">
                <v-toolbar color="transparent">
                    <v-toolbar-title>
                        <v-icon icon="mdi-alert" />
                        ERROR
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="error.show = false">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-card-text>{{ error.message }}</v-card-text>
                <v-card-actions>
                    <v-btn @click="error.show = false">OKAY</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="deleteCodes.dialog"
            width="560"
            transition="dialog-bottom-transition"
            persistent
        >
            <v-card>
                <v-toolbar color="red">
                    <v-toolbar-title>Delete Codes</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="deleteCodes.dialog = false">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-card-text>
                    <p
                        v-if="deleteCodes.scope === 'all_unclaimed'"
                        class="mb-3"
                    >
                        This deletes all
                        <strong>{{ unclaimedCodeCount }}</strong> unclaimed
                        code(s) in this campaign, along with the reward tokens
                        attached to them.
                    </p>
                    <p v-else class="mb-3">
                        This deletes
                        <strong>{{ selectedDeletable.length }}</strong> code(s)
                        and the reward tokens attached to them.
                    </p>

                    <v-alert
                        v-if="
                            selectedClaimed > 0 &&
                            deleteCodes.scope === 'selected'
                        "
                        type="info"
                        variant="tonal"
                        density="comfortable"
                        class="mb-3"
                    >
                        {{ selectedClaimed }} selected code(s) have already been
                        claimed and will be kept. Their claims are the record
                        that someone was paid.
                    </v-alert>

                    <p class="text-medium-emphasis text-body-2">
                        Any QR codes already printed or shared for these codes
                        will stop working. This cannot be undone.
                    </p>
                </v-card-text>
                <v-card-actions>
                    <v-btn
                        color="red"
                        variant="flat"
                        :loading="deleteCodes.working"
                        @click="performDeleteCodes"
                    >
                        Delete
                    </v-btn>
                    <v-btn
                        :disabled="deleteCodes.working"
                        @click="deleteCodes.dialog = false"
                        >Cancel</v-btn
                    >
                    <v-spacer></v-spacer>
                    <!-- Offered here rather than in the toolbar: clearing a whole batch is the
                         reason someone opened this dialog, and it saves paging a table to
                         tick several hundred boxes. -->
                    <v-btn
                        v-if="
                            deleteCodes.scope === 'selected' &&
                            unclaimedCodeCount > selectedDeletable.length
                        "
                        variant="text"
                        color="red"
                        :disabled="deleteCodes.working"
                        @click="deleteCodes.scope = 'all_unclaimed'"
                    >
                        Delete all {{ unclaimedCodeCount }} unclaimed instead
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="dialog.refund"
            width="500"
            transition="dialog-bottom-transition"
            persistent
        >
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
                        <p class="mb-4">
                            Enter the Cardano address where remaining bucket
                            contents should be sent.
                        </p>
                        <v-text-field
                            label="Destination Address"
                            v-model="refundForm.address"
                            required
                            placeholder="addr1... or addr_test1..."
                            :error-messages="refundForm.errors.address"
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-btn
                            type="submit"
                            color="warning"
                            :disabled="refundForm.processing"
                            >Refund</v-btn
                        >
                        <v-btn
                            color="red"
                            @click="
                                dialog.refund = false;
                                refundForm.reset();
                            "
                            :disabled="refundForm.processing"
                            >Cancel</v-btn
                        >
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="dialog.edit"
            width="600"
            transition="dialog-bottom-transition"
            persistent
        >
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
                        <v-text-field
                            label="Campaign Name"
                            v-model="editForm.name"
                            required
                            :error-messages="editForm.errors.name"
                        />
                        <v-textarea
                            label="Description"
                            v-model="editForm.description"
                            :error-messages="editForm.errors.description"
                        />
                        <v-text-field
                            label="Start Date"
                            type="date"
                            v-model="editForm.start_date"
                            required
                            :error-messages="editForm.errors.start_date"
                        />
                        <v-text-field
                            label="End Date"
                            type="date"
                            v-model="editForm.end_date"
                            required
                            :error-messages="editForm.errors.end_date"
                        />
                        <v-text-field
                            label="Transaction Message"
                            v-model="editForm.txn_msg"
                            counter="64"
                            hint="Optional message included in claim transactions (max 64 chars)"
                            persistent-hint
                            :error-messages="editForm.errors.txn_msg"
                        />
                        <v-text-field
                            label="NMKR API Key"
                            v-model="editForm.nmkr_api_key"
                            hint="Optional — your NMKR Studio API key for NFT minting"
                            persistent-hint
                            :error-messages="editForm.errors.nmkr_api_key"
                        />
                        <v-select
                            label="Network"
                            v-model="editForm.network"
                            :items="networkOptions"
                            :disabled="hasClaims"
                            :hint="
                                hasClaims
                                    ? 'Locked — campaign has existing claims'
                                    : ''
                            "
                            persistent-hint
                            :error-messages="editForm.errors.network"
                        />
                        <v-checkbox
                            label="One Per Wallet"
                            v-model="editForm.one_per_wallet"
                            :true-value="1"
                            :false-value="0"
                            :disabled="hasClaims"
                            :hint="
                                hasClaims
                                    ? 'Locked — campaign has existing claims'
                                    : ''
                            "
                            persistent-hint
                        />
                        <!-- When this campaign should tell its operator it is running
                             short. A campaign setting, saved by the same endpoint that
                             already validates the other ones. -->
                        <v-divider class="my-4"></v-divider>
                        <div class="text-subtitle-2 mb-1">Running short</div>
                        <v-switch
                            label="Warn me when this campaign is running short"
                            v-model="editForm.alerts_enabled"
                            :true-value="1"
                            :false-value="0"
                            color="primary"
                            density="compact"
                            hint="Only while the campaign is running. Nothing is warned about before it starts or after it ends."
                            persistent-hint
                            :error-messages="editForm.errors.alerts_enabled"
                        />
                        <v-text-field
                            class="mt-3"
                            label="Warn me with this many claims left"
                            type="number"
                            min="0"
                            placeholder="10"
                            v-model="editForm.alert_threshold_claims"
                            :disabled="!editForm.alerts_enabled"
                            :hint="alertThresholdHint"
                            persistent-hint
                            :error-messages="
                                editForm.errors.alert_threshold_claims
                            "
                        />
                        <!-- How many codes this campaign may ever hold, across every path
                             that creates one: this form, the bulk import, and the
                             code-creation API. -->
                        <v-divider class="my-4"></v-divider>
                        <v-text-field
                            label="Max Codes"
                            type="number"
                            min="1"
                            placeholder="No limit"
                            v-model="editForm.max_codes"
                            hint="Optional — refuses to create another code once the campaign holds this many, however it was asked for."
                            persistent-hint
                            :error-messages="editForm.errors.max_codes"
                        />
                    </v-card-text>
                    <v-card-text v-if="Object.keys(editForm.errors).length">
                        <v-alert type="error" title="Errors">
                            <p
                                v-for="(error, key) in editForm.errors"
                                :key="key"
                            >
                                {{ error }}
                            </p>
                        </v-alert>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn
                            type="submit"
                            color="primary"
                            :disabled="editForm.processing"
                            >Save Changes</v-btn
                        >
                        <v-btn
                            color="red"
                            @click="dialog.edit = false"
                            :disabled="editForm.processing"
                            >Cancel</v-btn
                        >
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="dialog.spend_limit"
            width="520"
            transition="dialog-bottom-transition"
            persistent
        >
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Spend limit</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.spend_limit = false">
                        <v-icon icon="mdi-close" />
                    </v-btn>
                </v-toolbar>
                <v-form @submit.prevent="submitSpendLimit">
                    <v-card-text>
                        <p class="text-body-2 mb-4">
                            The most credit this campaign may spend. Claims past
                            the limit are accepted and wait rather than being
                            turned away, so raising it serves them.
                        </p>
                        <v-text-field
                            label="Credits"
                            v-model="spendLimitForm.spend_limit_credits"
                            :rules="spendLimitRules"
                            placeholder="No limit"
                            hint="Leave blank to lift the limit."
                            persistent-hint
                            :error-messages="
                                spendLimitForm.errors.spend_limit_credits
                            "
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-btn
                            type="submit"
                            color="primary"
                            :disabled="spendLimitForm.processing"
                            >Save Limit</v-btn
                        >
                        <v-btn
                            color="red"
                            @click="dialog.spend_limit = false"
                            :disabled="spendLimitForm.processing"
                            >Cancel</v-btn
                        >
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
            :tasks="qrExportTasks"
            :scopes="export_scopes"
        />
        <v-snackbar v-model="snackbar.show" :timeout="1800" location="bottom">
            {{ snackbar.text }}
        </v-snackbar>
        <!--        <pre>{{ campaign }}</pre>-->
    </AuthenticatedLayout>
</template>
