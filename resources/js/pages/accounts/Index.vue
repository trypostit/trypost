<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconPlugConnected } from '@tabler/icons-vue';

import SocialAccountsManager from '@/components/accounts/SocialAccountsManager.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
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

    <AppLayout full-width>
        <template #header>
            <HeaderTitle
                :title="$t('accounts.page_title')"
                :total="connectedAccounts.length"
                :icon="IconPlugConnected"
            />
        </template>

        <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
            <div
                class="min-h-0 min-w-0 flex-1 overflow-auto overscroll-contain pb-px"
                data-testid="accounts-scroll"
            >
                <div class="flex min-h-full flex-col gap-6 p-6">
                    <p class="text-sm text-muted-foreground">
                        {{ $t('accounts.description') }}
                    </p>

                    <SocialAccountsManager
                        :platforms="platforms"
                        :connected-accounts="connectedAccounts"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
