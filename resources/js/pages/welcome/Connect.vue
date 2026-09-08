<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import SocialAccountsManager from '@/components/accounts/SocialAccountsManager.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { usePageErrors } from '@/composables/usePageErrors';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/connect';
import type { WelcomeSummary } from '@/types';
import type {
    AvailablePlatform,
    ConnectedAccount,
} from '@/types/social-account';
import { SocialAccountStatus } from '@/types/social-account-status';

const props = defineProps<{
    platforms: AvailablePlatform[];
    accounts: ConnectedAccount[];
    welcome: WelcomeSummary;
}>();

const form = useForm({});

const errors = usePageErrors();

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
        size="5xl"
    >
        <SocialAccountsManager
            v-if="platforms.length > 0"
            :platforms="platforms"
            :connected-accounts="accounts"
            data-testid="welcome-connect-grid"
        />

        <template #actions>
            <div
                class="flex flex-col gap-2 sm:flex-row-reverse sm:items-center sm:gap-4"
            >
                <Button as-child size="lg" class="w-full sm:w-auto sm:min-w-48">
                    <button
                        type="button"
                        data-testid="welcome-connect-continue"
                        :disabled="form.processing || !hasConnectedAccount"
                        @click="submit"
                    >
                        {{ $t('welcome.continue') }}
                    </button>
                </Button>
                <InputError
                    data-testid="welcome-connect-error"
                    :message="errors.connect"
                />
            </div>
        </template>
    </WelcomeLayout>
</template>
