<script setup lang="ts">
import NetworkCatalog from '@/components/accounts/NetworkCatalog.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type {
    AvailablePlatform,
    ConnectedAccount,
} from '@/types/social-account';

const open = defineModel<boolean>('open', { required: true });

withDefaults(
    defineProps<{
        platforms: AvailablePlatform[];
        connectedAccounts?: ConnectedAccount[];
    }>(),
    {
        connectedAccounts: () => [],
    },
);

const emit = defineEmits<{
    select: [platform: string];
}>();

const choose = (platform: string) => {
    open.value = false;
    emit('select', platform);
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
            data-testid="connect-account-dialog"
        >
            <DialogHeader class="text-left">
                <DialogTitle>{{
                    $t('accounts.connect_dialog.title')
                }}</DialogTitle>
                <DialogDescription>{{
                    $t('accounts.connect_dialog.description')
                }}</DialogDescription>
            </DialogHeader>

            <NetworkCatalog
                :platforms="platforms"
                :connected-accounts="connectedAccounts"
                grid-class="grid-cols-2 sm:grid-cols-3"
                class="py-2"
                @select="choose"
            />
        </DialogContent>
    </Dialog>
</template>
