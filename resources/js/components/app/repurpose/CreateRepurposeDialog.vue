<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { IconPlugConnected } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import ChannelAvatar from '@/components/app/ChannelAvatar.vue';
import SearchableSelect from '@/components/app/SearchableSelect.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    getPlatformLabel,
} from '@/composables/usePlatformLogo';
import { store } from '@/routes/app/repurposes';
import { channels as channelsSettings } from '@/routes/app/workspace';
import type { ChannelAccount } from '@/types/app/channel';

const props = defineProps<{
    sourceAccounts: ChannelAccount[];
}>();

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const form = useForm({
    source_social_account_id: '',
});

const accountOptions = computed(() =>
    props.sourceAccounts.map((account) => ({
        value: account.id,
        label: account.display_name,
        platform: account.platform,
        avatar: account.avatar_url,
        status: account.status ?? null,
    })),
);

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();

        return;
    }

    form.source_social_account_id = props.sourceAccounts[0]?.id ?? '';
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
                <DialogDescription>{{
                    $t('repurposes.create.description')
                }}</DialogDescription>
            </DialogHeader>

            <div v-if="sourceAccounts.length === 0" class="space-y-4 py-2">
                <div
                    class="flex items-start gap-3 rounded-md border border-dashed border-border bg-muted/30 p-4"
                >
                    <IconPlugConnected
                        class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{ $t('repurposes.create.no_accounts') }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="closeDialog">
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button as-child data-testid="connect-account-cta">
                        <Link :href="channelsSettings.url()">{{
                            $t('repurposes.create.connect')
                        }}</Link>
                    </Button>
                </DialogFooter>
            </div>

            <form v-else class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <p
                        class="text-sm font-medium text-foreground"
                    >
                        {{ $t('repurposes.create.source_label') }}
                    </p>

                    <div data-testid="source-account-select">
                        <SearchableSelect
                            v-model="form.source_social_account_id"
                            :options="accountOptions"
                            :placeholder="
                                $t('repurposes.create.source_placeholder')
                            "
                            :search-placeholder="
                                $t('repurposes.create.source_search')
                            "
                            :empty-text="$t('repurposes.create.source_empty')"
                            :invalid="
                                Boolean(form.errors.source_social_account_id)
                            "
                        >
                            <template #option="{ option, compact }">
                                <ChannelAvatar
                                    v-if="compact"
                                    :platform="option.platform"
                                    :name="option.label"
                                    :src="option.avatar"
                                    :size="20"
                                />
                                <ChannelAvatar
                                    v-else
                                    :platform="option.platform"
                                    :name="option.label"
                                    :src="option.avatar"
                                    :status="option.status"
                                    :size="32"
                                    ring="popover"
                                    data-testid="source-account-option"
                                />

                                <span v-if="compact" class="truncate">{{
                                    option.label
                                }}</span>
                                <span v-else class="min-w-0 text-left">
                                    <span
                                        class="block truncate text-sm leading-tight font-emphasis"
                                        >{{ option.label }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                    >
                                        {{ getPlatformLabel(option.platform) }}
                                    </span>
                                </span>
                            </template>
                        </SearchableSelect>
                    </div>

                    <InputError
                        :message="form.errors.source_social_account_id"
                    />
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="closeDialog">
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        data-testid="create-repurpose-submit"
                        :disabled="
                            form.processing || !form.source_social_account_id
                        "
                    >
                        {{ $t('repurposes.create.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
