<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {Head, useForm, router} from '@inertiajs/vue3';
import {computed, reactive} from "vue";

function doCreateCampaign() {
    dialog.campaign = true;
}

const props = defineProps({
    campaigns: Array
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
});

const networks = ['mainnet', 'preprod'];

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

const statusConfig = {
    active: {color: 'success', icon: 'mdi-check-circle'},
    upcoming: {color: 'info', icon: 'mdi-clock-outline'},
    ended: {color: 'default', icon: 'mdi-flag-checkered'},
    draft: {color: 'warning', icon: 'mdi-pencil-outline'},
};

function createCampaign() {
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
        <v-container>
            <v-row class="justify-center">
                <v-col rows="12" xl="7">
                    <v-card rounded="lg" elevation="1">
                        <v-toolbar title="Your Campaigns" color="surface-variant">
                            <v-spacer></v-spacer>
                            <v-btn color="primary" variant="flat" @click="doCreateCampaign">
                                Create Campaign
                            </v-btn>
                        </v-toolbar>
                        <v-card-text>
                            <v-data-table
                                :headers="headers"
                                :items="campaigns"
                                :items-per-page="10"
                                item-value="id"
                                no-data-text="You don't have any campaigns yet!"
                                class="elevation-0"
                            >
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
                        </v-card-text>
                    </v-card>
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
                                  label="Network" required></v-select>
                        <v-text-field v-model="campaign.txn_msg" label="Transaction Message"
                                      counter="64"
                                      hint="Optional message included in claim transactions (max 64 chars)"
                                      persistent-hint></v-text-field>
                        <v-text-field v-model="campaign.nmkr_api_key" label="NMKR API Key"
                                      hint="Optional — your NMKR Studio API key for NFT minting"
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
