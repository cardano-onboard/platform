<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {Head, router} from '@inertiajs/vue3';
import {reactive} from "vue";

const form = reactive({
    name: null,
    description: null,
    start_date: null,
    end_date: null,
    one_per_wallet: null,
    network: 'preprod'
})

function submit() {
    router.post(route('campaigns.store'), form)
}

defineProps({ errors: Object })
</script>
<template>
    <Head title="Create Campaign"/>
    <AuthenticatedLayout>
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        Create New Campaign
                        <form class="px-8 pt-6 pb-8 mb-4" @submit.prevent="submit">
                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="campaign_name">
                                    Campaign Name:
                                </label>
                                <input
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                    id="campaign_name" v-model="form.name"/>
                            </div>
                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="description">
                                    Description:
                                </label>
                                <input
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                    id="description" v-model="form.description"/>
                            </div>
                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="start_date">
                                    Claim Start Date:
                                </label>
                                <input
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                    id="start_date" type="date" v-model="form.start_date"/>
                            </div>
                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="end_date">
                                    Claim End Date:
                                </label>
                                <input
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                    id="end_date" type="date" v-model="form.end_date"/>
                            </div>
                            <div class="mb-4 block min-h-[1.5rem] pl-[1.5rem]">
                                <input
                                    class="relative float-left -ml-[1.5rem] mr-[6px] mt-[0.15rem] h-[1.125rem] w-[1.125rem] appearance-none rounded-[0.25rem] border-[0.125rem] border-solid border-neutral-300 outline-none before:pointer-events-none before:absolute before:h-[0.875rem] before:w-[0.875rem] before:scale-0 before:rounded-full before:bg-transparent before:opacity-0 before:shadow-[0px_0px_0px_13px_transparent] before:content-[''] checked:border-primary checked:bg-primary checked:before:opacity-[0.16] checked:after:absolute checked:after:-mt-px checked:after:ml-[0.25rem] checked:after:block checked:after:h-[0.8125rem] checked:after:w-[0.375rem] checked:after:rotate-45 checked:after:border-[0.125rem] checked:after:border-l-0 checked:after:border-t-0 checked:after:border-solid checked:after:border-white checked:after:bg-transparent checked:after:content-[''] hover:cursor-pointer hover:before:opacity-[0.04] hover:before:shadow-[0px_0px_0px_13px_rgba(0,0,0,0.6)] focus:shadow-none focus:transition-[border-color_0.2s] focus:before:scale-100 focus:before:opacity-[0.12] focus:before:shadow-[0px_0px_0px_13px_rgba(0,0,0,0.6)] focus:before:transition-[box-shadow_0.2s,transform_0.2s] focus:after:absolute focus:after:z-[1] focus:after:block focus:after:h-[0.875rem] focus:after:w-[0.875rem] focus:after:rounded-[0.125rem] focus:after:content-[''] checked:focus:before:scale-100 checked:focus:before:shadow-[0px_0px_0px_13px_#3b71ca] checked:focus:before:transition-[box-shadow_0.2s,transform_0.2s] checked:focus:after:-mt-px checked:focus:after:ml-[0.25rem] checked:focus:after:h-[0.8125rem] checked:focus:after:w-[0.375rem] checked:focus:after:rotate-45 checked:focus:after:rounded-none checked:focus:after:border-[0.125rem] checked:focus:after:border-l-0 checked:focus:after:border-t-0 checked:focus:after:border-solid checked:focus:after:border-white checked:focus:after:bg-transparent dark:border-neutral-600 dark:checked:border-primary dark:checked:bg-primary dark:focus:before:shadow-[0px_0px_0px_13px_rgba(255,255,255,0.4)] dark:checked:focus:before:shadow-[0px_0px_0px_13px_#3b71ca]"
                                    id="one_per_wallet" type="checkbox" v-model="form.one_per_wallet"/>
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="one_per_wallet">
                                    One Per Wallet
                                </label>
                            </div>
                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="network">
                                    Network:
                                </label>
                                <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                        id="network" v-model="form.network">
                                    <option value="preprod">Preprod</option>
                                    <option value="preview">Preview</option>
                                    <option value="mainnet">Mainnet</option>
                                </select>
                            </div>
                            <button type="submit"
                                    class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                                Submit
                            </button>
                        </form>
                        <div v-if="errors">
                            <pre>{{errors}}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
