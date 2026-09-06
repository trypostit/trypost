<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { IconPlugConnected } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
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

const accountOptions = computed(() =>
    selectableAccounts.value.map((account) => ({
        value: account.id,
        label: account.display_name,
        platform: account.platform,
    })),
);

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();

        return;
    }

    form.source_social_account_id = selectableAccounts.value[0]?.id ?? '';
});

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

                    <div data-testid="source-account-select">
                        <SearchableSelect
                            v-model="form.source_social_account_id"
                            :options="accountOptions"
                            :placeholder="$t('repurposes.create.source_placeholder')"
                            :search-placeholder="$t('repurposes.create.source_search')"
                            :empty-text="$t('repurposes.create.source_empty')"
                            :invalid="Boolean(form.errors.source_social_account_id)"
                        >
                            <template #option="{ option }">
                                <PlatformLogo :platform="option.platform" size="sm" data-testid="source-account-option" />

                                <span class="min-w-0 text-left">
                                    <span class="block truncate text-sm font-bold">{{ option.label }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">
                                        {{ getPlatformLabel(option.platform) }}
                                    </span>
                                </span>
                            </template>
                        </SearchableSelect>
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
