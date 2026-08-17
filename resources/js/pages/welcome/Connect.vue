<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/connect';
import { SocialAccountStatus } from '@/types/social-account-status';

const props = defineProps<{
    platforms: AvailablePlatform[];
    accounts: ConnectedAccount[];
}>();

const form = useForm({});

const hasConnectedAccount = computed((): boolean =>
    props.accounts.some(
        (account) => account.status === SocialAccountStatus.Connected,
    ),
);

const submit = (): void => {
    if (form.processing || !hasConnectedAccount.value) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.connect.title')" />

    <WelcomeLayout
        :title="$t('welcome.connect.title')"
        :description="$t('welcome.connect.description')"
        :step="4"
        size="7xl"
    >
        <NetworkConnectGrid
            v-if="platforms.length > 0"
            :platforms="platforms"
            :connected-accounts="accounts"
            grid-class="grid-cols-2 sm:grid-cols-3 xl:grid-cols-6"
            data-testid="welcome-connect-grid"
            dusk="welcome-connect-grid"
        />

        <div class="mx-auto flex w-full max-w-sm flex-col items-center gap-3">
            <InputError
                :message="form.errors.connect"
                dusk="welcome-connect-error"
            />
            <Button as-child size="lg" class="w-full rounded-full">
                <button
                    type="button"
                    data-testid="welcome-start-checkout"
                    dusk="welcome-start-checkout"
                    :disabled="form.processing || !hasConnectedAccount"
                    @click="submit"
                >
                    {{ $t('welcome.continue') }}
                </button>
            </Button>
        </div>
    </WelcomeLayout>
</template>
