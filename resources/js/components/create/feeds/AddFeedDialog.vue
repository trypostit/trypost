<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/app/create/feeds';

const props = defineProps<{
    collectionId?: string | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm({ url: '' });

const canSubmit = computed(
    () => form.url.trim().length > 0 && !form.processing,
);

const submit = (): void => {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => ({
        url: data.url.trim(),
        rss_feed_collection_id: props.collectionId ?? null,
    })).post(store.url(), {
        preserveScroll: true,
        preserveState: 'errors',
    });
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="true" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-[420px]" data-testid="feeds-add-dialog">
            <DialogHeader>
                <DialogTitle>{{ $t('create.feeds.add.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('create.feeds.add.explainer') }}
                </DialogDescription>
            </DialogHeader>

            <form
                id="feeds-add-form"
                class="space-y-1.5"
                novalidate
                @submit.prevent="submit"
            >
                <label for="feeds-add-url" class="text-sm font-medium">{{
                    $t('create.feeds.add.url_label')
                }}</label>
                <Input
                    id="feeds-add-url"
                    v-model="form.url"
                    type="text"
                    inputmode="url"
                    autocomplete="off"
                    autofocus
                    :placeholder="$t('create.feeds.add.url_placeholder')"
                    :aria-invalid="form.errors.url ? true : undefined"
                    :aria-describedby="
                        form.errors.url ? 'feeds-add-error' : undefined
                    "
                    data-testid="feeds-add-url"
                />
                <p
                    v-if="form.errors.url"
                    id="feeds-add-error"
                    role="alert"
                    class="text-sm text-destructive-text"
                    data-testid="feeds-add-error"
                >
                    {{ form.errors.url }}
                </p>
            </form>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    data-testid="feeds-add-cancel"
                    @click="emit('close')"
                >
                    {{ $t('create.feeds.add.cancel') }}
                </Button>
                <Button
                    type="submit"
                    form="feeds-add-form"
                    :disabled="!canSubmit"
                    data-testid="feeds-add-submit"
                >
                    {{ $t('create.feeds.add.submit') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
