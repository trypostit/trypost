<script setup lang="ts">
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    useTemplateDialogForm,
    type TemplateDialogMode,
} from '@/composables/useTemplateDialogForm';
import { templateVisibilityIcons } from '@/lib/templateVisibility';
import { duplicate } from '@/routes/app/create/templates';
import { duplicate as duplicateLibrary } from '@/routes/app/create/templates/library';
import type {
    DuplicateSource,
    PostTemplate,
    TemplateVisibility,
} from '@/types/template';

const props = defineProps<{
    mode: TemplateDialogMode;
    source: DuplicateSource;
    defaultVisibility?: TemplateVisibility;
}>();

const emit = defineEmits<{
    saved: [template: PostTemplate];
    close: [];
}>();

const { form, open, processing, errors, close, onOpenChange, sendLocal } =
    useTemplateDialogForm<{ visibility: TemplateVisibility }>({
        mode: props.mode,
        initial: { visibility: props.defaultVisibility ?? 'personal' },
        onClose: () => emit('close'),
    });

const visibilities: TemplateVisibility[] = ['personal', 'team'];

const url = computed(() =>
    props.source.kind === 'library'
        ? duplicateLibrary.url(props.source.key)
        : duplicate.url(props.source.id),
);

const confirm = async (): Promise<void> => {
    if (processing.value) {
        return;
    }

    if (props.mode === 'page') {
        form.post(url.value, {
            preserveScroll: true,
            preserveState: 'errors',
            onSuccess: () => close(),
        });

        return;
    }

    const saved = await sendLocal('post', url.value);

    if (saved) {
        emit('saved', saved);
        close();
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            class="sm:max-w-[400px]"
            :aria-describedby="undefined"
            data-testid="template-duplicate-dialog"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('create.templates.duplicate_dialog.title', {
                        title: source.title,
                    })
                }}</DialogTitle>
            </DialogHeader>

            <div class="space-y-1.5">
                <label
                    for="template-duplicate-visibility"
                    class="text-sm font-medium"
                    >{{ $t('create.templates.duplicate_dialog.target') }}</label
                >
                <Select v-model="form.visibility">
                    <SelectTrigger
                        id="template-duplicate-visibility"
                        class="w-full"
                        :aria-label="$t('create.templates.duplicate_dialog.target')"
                        data-testid="template-duplicate-visibility"
                    >
                        <SelectValue class="flex items-center gap-2">
                            <component
                                :is="templateVisibilityIcons[form.visibility]"
                                class="size-4 shrink-0 text-muted-foreground"
                                :data-testid="`template-duplicate-visibility-icon-${form.visibility}`"
                            />
                            {{
                                $t(
                                    `create.templates.visibility.${form.visibility}`,
                                )
                            }}
                        </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in visibilities"
                            :key="option"
                            :value="option"
                            :data-testid="`template-duplicate-visibility-${option}`"
                        >
                            <component
                                :is="templateVisibilityIcons[option]"
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            {{ $t(`create.templates.visibility.${option}`) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div
                v-if="errors.length"
                role="alert"
                class="space-y-1 text-sm text-destructive"
                data-testid="template-duplicate-error"
            >
                <p v-for="message in errors" :key="message">{{ message }}</p>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    data-testid="template-duplicate-cancel"
                    @click="close()"
                >
                    {{ $t('create.templates.cancel') }}
                </Button>
                <Button
                    type="button"
                    data-testid="template-duplicate-confirm"
                    :disabled="processing"
                    @click="confirm"
                >
                    {{ $t('create.templates.duplicate_dialog.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
