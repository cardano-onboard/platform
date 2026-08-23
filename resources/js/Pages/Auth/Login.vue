<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import {Head, useForm} from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>
<template>
    <GuestLayout>
        <Head title="Log in"/>
<!--        <div v-if="status" class="mb-4 font-medium text-sm text-green-600">
            {{ status }}
        </div>-->
        <v-alert color="success" v-if="status" class="mb-4">{{ status }}</v-alert>
        <v-card rounded="xl" elevation="2">
            <v-form @submit.prevent="submit">
                <v-card-text class="pa-8">
                    <v-alert type="error" v-if="form.errors.email" class="mb-4" density="compact">
                        {{ form.errors.email }}
                    </v-alert>
                    <v-text-field type="email" v-model="form.email" required label="Email"
                                  :error-messages="form.errors.email" />
                    <v-text-field type="password" v-model="form.password" required label="Password"
                                  :error-messages="form.errors.password" />
                    <v-switch v-model="form.remember" color="primary" label="Remember me?" />
                </v-card-text>
                <v-card-actions class="px-8 pb-6">
                    <v-btn variant="text" v-if="canResetPassword" :href="route('password.request')">
                        Forgot your password?
                    </v-btn>
                    <v-spacer />
                    <v-btn color="primary" type="submit" :disabled="form.processing" :loading="form.processing">
                        Log In
                    </v-btn>
                </v-card-actions>
            </v-form>
        </v-card>
<!--        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email"/>
                <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autofocus
                           autocomplete="username"/>
                <InputError class="mt-2" :message="form.errors.email"/>
            </div>
            <div class="mt-4">
                <InputLabel for="password" value="Password"/>
                <TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" required
                           autocomplete="current-password"/>
                <InputError class="mt-2" :message="form.errors.password"/>
            </div>
            <div class="block mt-4">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember"/>
                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400">Remember me</span>
                </label>
            </div>
            <div class="flex items-center justify-end mt-4">
                <Link v-if="canResetPassword" :href="route('password.request')"
                      class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">
                    Forgot your password?
                </Link>
                <PrimaryButton class="ml-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Log in
                </PrimaryButton>
            </div>
        </form>-->
    </GuestLayout>
</template>
