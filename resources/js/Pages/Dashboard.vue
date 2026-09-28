<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {Head, useForm, router} from '@inertiajs/vue3';
import {computed, reactive, ref} from "vue";
import {useDisplay} from "vuetify";

function doCreateCampaign() {
    dialog.campaign = true;
}

const props = defineProps({
    campaigns: Array,
    // Networks this deployment accepts (config/cardano.php). A network the server
    // would reject is left out of the selector rather than shown disabled: nothing
    // at the control could explain why it is unavailable.
    allowed_networks: {
        type: Array,
        default: () => ['preprod', 'preview', 'mainnet'],
    },
    // Where mainnet campaigns are created when this deployment does not accept them.
    // Null unless an operator has set MAINNET_APP_URL, which is what keeps the hint
    // below off the form for everyone else.
    mainnet_app_url: {
        type: String,
        default: null,
    },
});

const dialog = reactive({
    campaign: false,
    remove: false
});

const doDelete = reactive({
    campaign: null,
});

const campaign = useForm({
    name: null,
    description: null,
    start_date: null,
    end_date: null,
    network: null,
    one_per_wallet: 0,
    txn_msg: null,
    nmkr_api_key: null,
    max_codes: null,
});

// Ordered as the create page orders them, and filtered to what this deployment
// accepts. The hardcoded pair this replaced also omitted preview, so the dialog and
// the create page had been offering different sets of networks.
const networks = computed(() =>
    ['preprod', 'preview', 'mainnet'].filter((network) =>
        props.allowed_networks.includes(network),
    ),
);

// A short pointer to where mainnet campaigns actually get created, shown only when
// this deployment has mainnet off the allowlist and an operator has said where it
// moved. Silent otherwise, which is both the self-hosted and production default.
const mainnetHint = computed(() => {
    if (props.allowed_networks.includes('mainnet') || !props.mainnet_app_url) {
        return null;
    }

    return `Mainnet campaigns are created at ${props.mainnet_app_url}.`;
});

const search = ref('');

// Filtered here rather than through the table's own search so the match is limited to
// the fields someone actually looks a campaign up by. The table's built-in search spans
// every column, which means typing "mainnet" hides campaigns whose name matched, and a
// date fragment matches rows for reasons the reader cannot see.
// An account with campaigns that simply do not match a search is not an empty account,
// and telling someone they have no campaigns when they have twelve reads as data loss.
const noDataText = computed(() =>
    search.value ? 'No campaigns match that search.' : "You don't have any campaigns yet!"
);

const filteredCampaigns = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (!needle) return props.campaigns;

    return props.campaigns.filter((campaign) =>
        [campaign.name, campaign.description, campaign.network, campaign.status]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(needle))
    );
});

const headers = [
    {title: 'Name', key: 'name', sortable: true},
    {title: 'Status', key: 'status', sortable: true},
    {title: 'Network', key: 'network', sortable: true},
    {title: 'Start', key: 'start_date', sortable: true},
    {title: 'End', key: 'end_date', sortable: true},
    {title: 'Codes', key: 'codes_count', sortable: true, align: 'center'},
    {title: 'Claims', key: 'claims_count', sortable: true, align: 'center'},
    {title: '', key: 'actions', sortable: false, align: 'end'},
];

// The table's own stacked layout gives every column a full-height labelled row, which
// made each campaign over 400px tall on a phone. Below the same breakpoint the table
// uses, each campaign renders as one compact row instead. The breakpoint is shared so
// the table and the compact row can never disagree about which layout is showing.
const mobileBreakpoint = 'md';
const {mobile: compactRows} = useDisplay({mobileBreakpoint});
const {xs} = useDisplay();

function plural(count, word) {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

const statusConfig = {
    active: {color: 'success', icon: 'mdi-check-circle'},
    upcoming: {color: 'info', icon: 'mdi-clock-outline'},
    ended: {color: 'default', icon: 'mdi-flag-checkered'},
    draft: {color: 'warning', icon: 'mdi-pencil-outline'},
};

function createCampaign() {
    // An empty box means "no limit", which is a null column rather than the empty string
    // a blank number field posts: nullable|integer accepts null and refuses "".
    const typed = campaign.max_codes;
    campaign.max_codes =
        typed === null || typed === undefined || String(typed).trim() === ""
            ? null
            : Number(typed);

    campaign.post(route('campaigns.store'), {
        onSuccess: () => {
            dialog.campaign = false
        }
    });
}

function removeCampaign(campaign) {
    doDelete.campaign = campaign;
    dialog.remove = true;
}

function confirmRemove() {
    router.delete(route('campaigns.destroy', doDelete.campaign));
    dialog.remove = false;
}
</script>
<template>
    <Head title="Dashboard"/>
    <AuthenticatedLayout>
        <v-container class="px-3 px-sm-4">
            <v-row class="justify-center">
                <v-col cols="12" xl="7">
                    <!-- The list sits on the page, as the codes list on a campaign does,
                         rather than inside a card of its own. -->
                    <v-toolbar color="transparent">
                        <!-- No spacer: the title already grows to fill the row, and a spacer
                             beside it takes half the room and cuts the title short on a phone. -->
                        <v-toolbar-title>Your Campaigns</v-toolbar-title>
                        <!-- On a phone the full label pushes the title into an ellipsis;
                             the plus icon and the page title carry the rest of it. -->
                        <v-btn
                            color="primary"
                            variant="flat"
                            prepend-icon="mdi-plus"
                            :height="xs ? 44 : undefined"
                            :aria-label="xs ? 'Create Campaign' : undefined"
                            class="me-sm-4"
                            @click="doCreateCampaign"
                        >
                            {{ xs ? 'Create' : 'Create Campaign' }}
                        </v-btn>
                    </v-toolbar>
                    <!-- Only worth the space once there are enough campaigns to hunt
                         through; below that the list itself is the search. -->
                    <v-text-field
                        v-if="campaigns.length > 5"
                        v-model="search"
                        label="Search campaigns"
                        prepend-inner-icon="mdi-magnify"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details
                        class="mb-4"
                    ></v-text-field>
                    <v-data-table
                        :headers="headers"
                        :items="filteredCampaigns"
                        :items-per-page="10"
                        item-value="id"
                        :mobile-breakpoint="mobileBreakpoint"
                        :no-data-text="noDataText"
                        class="elevation-0"
                    >
                        <!-- Phones: one compact row per campaign carrying every
                             column, with dates and counts sharing lines. -->
                        <template v-if="compactRows" v-slot:item="{ item }">
                            <tr class="campaign-card">
                                <td :colspan="headers.length">
                                    <div class="d-flex align-start ga-2">
                                        <div class="flex-grow-1 campaign-card__text">
                                            <div class="text-body-1 font-weight-bold campaign-card__name">
                                                {{ item.name }}
                                            </div>
                                            <div v-if="item.description"
                                                 class="text-caption text-medium-emphasis">
                                                {{ item.description }}
                                            </div>
                                        </div>
                                        <div class="d-flex flex-shrink-0 campaign-card__actions">
                                            <v-btn
                                                :href="route('campaigns.show', item.id)"
                                                color="primary"
                                                :size="44"
                                                icon="mdi-magnify"
                                                variant="text"
                                                aria-label="View campaign"
                                            />
                                            <v-btn
                                                :href="route('campaigns.show', item.id) + '?edit=1'"
                                                color="secondary"
                                                :size="44"
                                                icon="mdi-pencil"
                                                variant="text"
                                                aria-label="Edit campaign"
                                            />
                                            <v-btn
                                                v-if="item.claims_count === 0"
                                                color="red"
                                                :size="44"
                                                icon="mdi-trash-can"
                                                variant="text"
                                                aria-label="Remove campaign"
                                                @click="removeCampaign(item)"
                                            />
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap align-center ga-1 mt-1">
                                        <v-chip
                                            :color="statusConfig[item.status]?.color || 'default'"
                                            :prepend-icon="statusConfig[item.status]?.icon"
                                            size="x-small"
                                            label
                                        >
                                            {{ item.status }}
                                        </v-chip>
                                        <v-chip label color="primary" size="x-small">
                                            {{ item.network }}
                                        </v-chip>
                                        <span class="text-caption text-medium-emphasis ms-auto text-no-wrap">
                                            {{ plural(item.codes_count, 'code') }},
                                            {{ plural(item.claims_count, 'claim') }}
                                        </span>
                                    </div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ item.start_date }} to {{ item.end_date }}
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <template v-slot:item.name="{ item }">
                            <strong>{{ item.name }}</strong>
                            <div v-if="item.description" class="text-caption text-medium-emphasis">
                                {{ item.description }}
                            </div>
                        </template>

                        <template v-slot:item.status="{ item }">
                            <v-chip
                                :color="statusConfig[item.status]?.color || 'default'"
                                :prepend-icon="statusConfig[item.status]?.icon"
                                size="small"
                                label
                            >
                                {{ item.status }}
                            </v-chip>
                        </template>

                        <template v-slot:item.network="{ item }">
                            <v-chip label color="primary" size="small">
                                {{ item.network }}
                            </v-chip>
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <div class="text-no-wrap">
                                <v-btn
                                    :href="route('campaigns.show', item.id)"
                                    color="primary"
                                    size="small"
                                    icon="mdi-magnify"
                                    variant="text"
                                />
                                <v-btn
                                    :href="route('campaigns.show', item.id) + '?edit=1'"
                                    color="secondary"
                                    size="small"
                                    icon="mdi-pencil"
                                    variant="text"
                                />
                                <v-btn
                                    v-if="item.claims_count === 0"
                                    color="red"
                                    size="small"
                                    icon="mdi-trash-can"
                                    variant="text"
                                    @click="removeCampaign(item)"
                                />
                            </div>
                        </template>
                    </v-data-table>
                </v-col>
            </v-row>
        </v-container>
        <v-dialog v-model="dialog.campaign" width="512"
                  transition="dialog-bottom-transition" persistent>
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Create New Campaign</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="dialog.campaign = false">
                        <v-icon icon="mdi-close"></v-icon>
                    </v-btn>
                </v-toolbar>
                <v-form @submit.prevent="createCampaign">
                    <v-card-text>
                        <v-text-field v-model="campaign.name" label="Name"
                                      required></v-text-field>
                        <v-text-field v-model="campaign.description"
                                      label="Description"
                                      required></v-text-field>
                        <v-text-field v-model="campaign.start_date"
                                      label="Start" required
                                      type="date"></v-text-field>
                        <v-text-field v-model="campaign.end_date" label="End"
                                      required type="date"></v-text-field>
                        <v-switch v-model="campaign.one_per_wallet"
                                  color="primary"
                                  label="Limit one claim per wallet?"
                                  :true-value="1" :false-value="0"/>
                        <v-select v-model="campaign.network" :items="networks"
                                  label="Network" required
                                  :hint="mainnetHint" :persistent-hint="!!mainnetHint"></v-select>
                        <v-text-field v-model="campaign.txn_msg" label="Transaction Message"
                                      counter="64"
                                      hint="Optional message included in claim transactions (max 64 chars)"
                                      persistent-hint></v-text-field>
                        <v-text-field v-model="campaign.nmkr_api_key" label="NMKR API Key"
                                      hint="Optional — your NMKR Studio API key for NFT minting"
                                      persistent-hint></v-text-field>
                        <v-text-field v-model="campaign.max_codes" label="Max Codes"
                                      type="number" min="1"
                                      hint="Optional — the most codes this campaign may ever hold, counting every path that creates one"
                                      persistent-hint></v-text-field>
                    </v-card-text>
                    <v-card-text v-if="Object.keys($page.props.errors).length">
                        <v-alert type="error" title="Error Creating Campaign">
                            <p v-for="error in $page.props.errors" :key="error">
                                {{ error }}
                            </p>
                        </v-alert>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn type="submit" color="primary"
                               :disabled="campaign.processing">Create Campaign
                        </v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
        <v-dialog v-model="dialog.remove" width="512"
                  transition="dialog-bottom-transition" persistent>
            <v-card>
                <v-toolbar color="error" title="Remove Campaign"></v-toolbar>
                <v-card-title>Are you sure you want to remove this campaign?
                </v-card-title>
                <v-card-text>
                    You have chosen to remove your
                    <strong>{{ doDelete.campaign.name }}</strong> campaign. This
                    campaign
                    will be removed and no future claims will be possible even
                    if the codes associated with this
                    campaign have been distributed to the public.
                </v-card-text>
                <v-card-text>
                    Are you sure you wish to remove this campaign?
                </v-card-text>
                <v-card-actions>
                    <v-btn type="button" color="primary"
                           @click="confirmRemove()">Yes
                    </v-btn>
                    <v-spacer></v-spacer>
                    <v-btn type="button" color="red"
                           @click="dialog.remove = false">No
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
/* The table's row rule fixes every cell at the row height with 16px side padding;
   a compact row sizes to its content instead. */
.v-table .campaign-card > td {
    height: auto;
    padding: 8px 4px 8px 8px;
}

.campaign-card__text {
    min-width: 0;
    padding-top: 10px;
}

.campaign-card__name {
    line-height: 1.3;
    overflow-wrap: anywhere;
}

/* The buttons stay 44px square as tap targets; pulling the cluster up and right lets
   the name line and the buttons share one band instead of stacking. */
.campaign-card__actions {
    margin: -2px -4px -4px 0;
}
</style>
