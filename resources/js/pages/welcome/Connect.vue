<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import { Button } from '@/components/ui/button';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/connect';

defineProps<{
    platforms: AvailablePlatform[];
    accounts: ConnectedAccount[];
}>();

const form = useForm({});

const submit = (): void => {
    if (form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.connect_title')" />

    <WelcomeLayout
        :title="$t('welcome.connect_title')"
        :description="$t('welcome.connect_description')"
        :step="4"
        wide
    >
        <NetworkConnectGrid
            :platforms="platforms"
            :connected-accounts="accounts"
            grid-class="grid-cols-2 sm:grid-cols-3 xl:grid-cols-5"
            data-testid="welcome-connect-grid"
        />

        <div class="mx-auto flex w-full max-w-sm flex-col items-center gap-3">
            <Button
                type="button"
                size="lg"
                class="w-full rounded-full"
                :disabled="form.processing"
                data-testid="welcome-start-checkout"
                @click="submit"
            >
                {{ $t('welcome.continue') }}
            </Button>
        </div>
    </WelcomeLayout>
</template>
