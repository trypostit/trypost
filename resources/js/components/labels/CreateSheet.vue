<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

import HexColorInput from '@/components/HexColorInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { store as labelsStore } from '@/routes/app/labels';

const open = defineModel<boolean>('open', { default: false });

const DEFAULT_COLOR = '#7c3aed';

const form = useForm({
    name: '',
    color: DEFAULT_COLOR,
});

const submit = () => {
    form.post(labelsStore.url(), {
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
};

const handleOpenChange = (value: boolean) => {
    if (value) {
        form.reset();
        form.color = DEFAULT_COLOR;
        form.clearErrors();
    }
    open.value = value;
};
</script>

<template>
    <Sheet :open="open" @update:open="handleOpenChange">
        <SheetContent
            data-testid="create-label-sheet"
            class="w-full gap-0 sm:max-w-lg"
        >
            <SheetHeader class="border-b px-6 py-5 pr-12">
                <SheetTitle>{{ $t('labels.create.title') }}</SheetTitle>
                <SheetDescription>
                    {{ $t('labels.create.description') }}
                </SheetDescription>
            </SheetHeader>
            <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
                <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-6">
                    <div class="space-y-2">
                        <Label for="create-name">{{
                            $t('labels.create.name')
                        }}</Label>
                        <Input
                            id="create-name"
                            v-model="form.name"
                            data-testid="create-label-name"
                            :placeholder="
                                trans('labels.create.name_placeholder')
                            "
                            :class="{ 'border-destructive': form.errors.name }"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="create-color">{{
                            $t('labels.create.color')
                        }}</Label>
                        <HexColorInput v-model="form.color" name="color" />
                        <p
                            v-if="form.errors.color"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.color }}
                        </p>
                    </div>
                </div>

                <SheetFooter class="flex-row justify-end border-t px-6 py-4">
                    <Button
                        type="button"
                        variant="secondary"
                        data-testid="cancel-create-label"
                        @click="open = false"
                    >
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        data-testid="submit-create-label"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? $t('labels.create.submitting')
                                : $t('labels.create.submit')
                        }}
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
