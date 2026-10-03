<script setup lang="ts">
import { IconCheck, IconCopy, IconPencil } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { copyToClipboard } from '@/lib/utils';
import {
    isLibraryTemplate,
    type LibraryTemplate,
    type PostTemplate,
} from '@/types/template';

const props = defineProps<{
    template: LibraryTemplate | PostTemplate;
    editable: boolean;
}>();

const emit = defineEmits<{ edit: []; close: [] }>();

const copied = ref(false);
let copiedTimeout: ReturnType<typeof setTimeout> | null = null;

const library = computed(() =>
    isLibraryTemplate(props.template) ? props.template : null,
);

const onOpenChange = (value: boolean): void => {
    if (!value) {
        emit('close');
    }
};

const copy = async (): Promise<void> => {
    const didCopy = await copyToClipboard(props.template.body, undefined, {
        showSuccessToast: false,
    });

    if (!didCopy) {
        return;
    }

    copied.value = true;

    if (copiedTimeout) {
        clearTimeout(copiedTimeout);
    }

    copiedTimeout = setTimeout(() => {
        copied.value = false;
    }, 2000);
};

const use = (): void => {
    const draft = {
        content: props.template.body,
        media: [],
        scheduled_at: null,
        label_ids: [],
    };

    emit('close');
    openPostComposer({ draft });
};

onBeforeUnmount(() => {
    if (copiedTimeout) {
        clearTimeout(copiedTimeout);
    }
});
</script>

<template>
    <Dialog :open="true" @update:open="onOpenChange">
        <DialogContent
            class="flex max-h-[calc(100dvh-2rem)] flex-col gap-4 overflow-hidden sm:max-w-[470px]"
            data-testid="template-detail"
        >
            <div class="flex items-start gap-3 pe-8">
                <span
                    class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-muted text-2xl"
                    aria-hidden="true"
                    >{{ template.emoji || '📝' }}</span
                >
                <div class="min-w-0">
                    <DialogTitle
                        class="font-heading text-lg leading-snug font-semibold"
                        data-testid="template-detail-title"
                    >
                        {{ template.title }}
                    </DialogTitle>
                    <DialogDescription
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ template.description }}
                    </DialogDescription>
                </div>
            </div>

            <div
                class="relative min-h-0 flex-1 overflow-y-auto rounded-xl bg-primary-subtle p-4"
            >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="absolute end-3 top-3 bg-card"
                    data-testid="template-copy"
                    @click="copy"
                >
                    <IconCheck v-if="copied" class="size-4" />
                    <IconCopy v-else class="size-4" />
                    {{
                        copied
                            ? $t('create.templates.copied')
                            : $t('create.templates.copy')
                    }}
                </Button>
                <p
                    class="pt-10 text-sm leading-relaxed whitespace-pre-wrap text-foreground"
                    data-testid="template-detail-body"
                >
                    {{ template.body }}
                </p>
            </div>

            <DialogFooter class="items-center sm:justify-between">
                <p
                    class="text-xs text-muted-foreground"
                    data-testid="template-detail-meta"
                >
                    <template v-if="library">
                        {{ $t('create.templates.by_trypost') }} &bull;
                        {{ $t(`create.templates.facets.goal.${library.goal}`) }}
                        &bull;
                        {{ $t(`create.templates.facets.type.${library.type}`) }}
                    </template>
                    <template v-else-if="'author' in template && template.author">
                        {{
                            $t('create.templates.by_author', {
                                name: template.author,
                            })
                        }}
                    </template>
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="editable"
                        type="button"
                        variant="outline"
                        data-testid="template-detail-edit"
                        @click="emit('edit')"
                    >
                        <IconPencil class="size-4" />
                        {{ $t('create.templates.edit') }}
                    </Button>
                    <Button
                        type="button"
                        data-testid="template-use"
                        @click="use"
                    >
                        {{ $t('create.templates.use') }}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
