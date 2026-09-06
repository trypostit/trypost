<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { IconPlugConnected } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { accounts } from '@/routes/app';
import { store } from '@/routes/app/repurposes';
import type { ChannelAccount } from '@/types/channel';

const props = defineProps<{
    sourceAccounts: ChannelAccount[];
    lockedPlatform?: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    source_social_account_id: '',
});

const selectableAccounts = computed(() =>
    props.lockedPlatform
        ? props.sourceAccounts.filter((account) => account.platform === props.lockedPlatform)
        : props.sourceAccounts,
);

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();

        return;
    }

    form.source_social_account_id = selectableAccounts.value[0]?.id ?? '';
});

const select = (account: ChannelAccount) => {
    form.source_social_account_id = account.id;
};

const submit = () => {
    form.post(store.url(), {
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-testid="create-repurpose-dialog">
            <DialogHeader>
                <DialogTitle>{{ $t('repurposes.create.title') }}</DialogTitle>
                <DialogDescription>{{ $t('repurposes.create.description') }}</DialogDescription>
            </DialogHeader>

            <div v-if="selectableAccounts.length === 0" class="space-y-4 py-2">
                <div class="flex items-start gap-3 rounded-lg border-2 border-dashed border-foreground/20 p-4">
                    <IconPlugConnected class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">
                        {{ $t('repurposes.create.no_accounts') }}
                    </p>
                </div>

                <DialogFooter>
                    <Button as-child data-testid="connect-account-cta">
                        <Link :href="accounts.url()">{{ $t('repurposes.create.connect') }}</Link>
                    </Button>
                    <Button type="button" variant="ghost" @click="open = false">
                        {{ $t('common.cancel') }}
                    </Button>
                </DialogFooter>
            </div>

            <form v-else class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">
                        {{ $t('repurposes.create.source_label') }}
                    </p>

                    <div class="grid gap-2">
                        <button
                            v-for="account in selectableAccounts"
                            :key="account.id"
                            type="button"
                            class="group flex items-center gap-3 rounded-xl border-2 border-foreground p-3 text-left shadow-xs transition-shadow hover:shadow-md"
                            :class="
                                form.source_social_account_id === account.id
                                    ? 'bg-emerald-50'
                                    : 'bg-card'
                            "
                            :data-testid="`source-account-${account.id}`"
                            @click="select(account)"
                        >
                            <PlatformLogo :platform="account.platform" />

                            <span class="min-w-0">
                                <span class="block truncate text-sm font-bold">{{ account.display_name }}</span>
                                <span class="block truncate text-xs text-muted-foreground">
                                    {{ getPlatformLabel(account.platform) }}
                                </span>
                            </span>
                        </button>
                    </div>

                    <InputError :message="form.errors.source_social_account_id" />
                </div>

                <DialogFooter>
                    <Button
                        type="submit"
                        data-testid="create-repurpose-submit"
                        :disabled="form.processing || !form.source_social_account_id"
                    >
                        {{ $t('repurposes.create.submit') }}
                    </Button>
                    <Button type="button" variant="ghost" @click="open = false">
                        {{ $t('common.cancel') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
