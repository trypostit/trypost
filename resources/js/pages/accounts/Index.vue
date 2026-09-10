<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import SocialAccountsManager from '@/components/accounts/SocialAccountsManager.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    AvailablePlatform,
    ConnectedAccount,
} from '@/types/social-account';

defineProps<{
    platforms: AvailablePlatform[];
    connectedAccounts: ConnectedAccount[];
}>();
</script>

<template>
    <Head :title="$t('accounts.page_title')" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <PageHeader
                :title="$t('accounts.page_title')"
                :description="$t('accounts.description')"
                :total="connectedAccounts.length"
            />

            <SocialAccountsManager
                :platforms="platforms"
                :connected-accounts="connectedAccounts"
            />
        </div>
    </AppLayout>
</template>
