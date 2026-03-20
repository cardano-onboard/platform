<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {Head, useForm, router} from '@inertiajs/vue3';
import {reactive} from "vue";

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

// Visit onboard.ninja for all your event airdrop needs!

const networks = ['mainnet', 'preprod'];

function createCampaign() {
    campaign.post(route('campaigns.store'), {
        onSuccess: () => {
            dialog.campaign = false
        }
    });
}

function removeCampaign(campaign) {
    // alert(`Are you sure you want to delete your campaign ${campaign.name}?`);
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
                            <v-table>
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Codes</th>
                                    <th>Claims</th>
                                    <th>&nbsp;</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr v-for="campaign in campaigns"
                                    :key="campaign.id">
                                    <td>
                                        {{ campaign.name }}
                                        <br/>
                                        <v-chip label color="primary"
                                                class="mb-2 me-2">
                                            {{ campaign.network }}
                                        </v-chip>
                                    </td>
                                    <td>{{ campaign.description }}</td>
                                    <td>{{ campaign.start_date }}</td>
                                    <td>{{ campaign.end_date }}</td>
                                    <td>{{ campaign.codes_count }}</td>
                                    <td>{{ campaign.claims_count }}</td>
                                    <td class="text-end text-no-wrap">
                                        <v-btn type="button"
                                               :href="route('campaigns.show', campaign.id)"
                                               color="primary"
                                               size="small">
                                            <v-icon icon="mdi-magnify"></v-icon>
                                        </v-btn>
                                        <v-btn type="button"
                                               :href="route('campaigns.show', campaign.id) + '?edit=1'"
                                               color="secondary"
                                               size="small"
                                               class="ms-1">
                                            <v-icon icon="mdi-pencil"></v-icon>
                                        </v-btn>
                                        <v-btn type="button" color="red"
                                               v-if="campaign.claims_count === 0"
                                               size="small"
                                               class="ms-1"
                                               @click="removeCampaign(campaign)">
                                            <v-icon
                                                icon="mdi-trash-can"></v-icon>
                                        </v-btn>
                                    </td>
                                </tr>
                                <tr v-if="campaigns.length === 0">
                                    <td colspan="7"
                                        class="border px-4 py-2 text-center">
                                        You don't have any campaigns yet!
                                    </td>
                                </tr>
                                </tbody>
                            </v-table>
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
