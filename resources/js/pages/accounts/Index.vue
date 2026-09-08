<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { ref } from 'vue';

import ConnectAccountDialog from '@/components/accounts/ConnectAccountDialog.vue';
import SocialAccountsManager from '@/components/accounts/SocialAccountsManager.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    AvailablePlatform,
    ConnectedAccount,
} from '@/types/social-account';

defineProps<{
    platforms: AvailablePlatform[];
    connectedAccounts: ConnectedAccount[];
}>();

const catalogOpen = ref(false);
const manager = ref<InstanceType<typeof SocialAccountsManager> | null>(null);

const connectFromCatalog = (platform: string) =>
    manager.value?.startConnect(platform);
</script>

<template>
    <Head :title="$t('accounts.page_title')" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <PageHeader
                    :title="$t('accounts.page_title')"
                    :description="$t('accounts.description')"
                    :total="connectedAccounts.length"
                />

                <Button
                    class="shrink-0"
                    data-testid="connect-account-button"
                    @click="catalogOpen = true"
                >
                    <IconPlus class="size-4" stroke-width="2.5" />
                    {{ $t('accounts.connect_account') }}
                </Button>
            </div>

            <SocialAccountsManager
                ref="manager"
                :platforms="platforms"
                :connected-accounts="connectedAccounts"
            />
        </div>

        <ConnectAccountDialog
            v-model:open="catalogOpen"
            :platforms="platforms"
            :connected-accounts="connectedAccounts"
            @select="connectFromCatalog"
        />
    </AppLayout>
</template>
