<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store } from '@/routes/app/repurposes';
import type { ChannelAccount } from '@/types/channel';

const props = defineProps<{
    sourceAccounts: ChannelAccount[];
    template?: string | null;
    lockedPlatform?: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    source_social_account_id: '',
    template: null as string | null,
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

    form.template = props.template ?? null;
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

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="repurpose-source">{{ $t('repurposes.create.source_label') }}</Label>

                    <Select v-model="form.source_social_account_id">
                        <SelectTrigger id="repurpose-source" data-testid="repurpose-source-select">
                            <SelectValue :placeholder="$t('repurposes.create.source_placeholder')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="account in selectableAccounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.display_label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <InputError :message="form.errors.source_social_account_id" />

                    <p v-if="selectableAccounts.length === 0" class="text-sm text-muted-foreground">
                        {{ $t('repurposes.create.no_accounts') }}
                    </p>
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
