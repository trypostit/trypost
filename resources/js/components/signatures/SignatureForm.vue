<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

const props = withDefaults(
    defineProps<{
        mode: 'create' | 'edit';
        errors?: Partial<Record<'name' | 'content', string>>;
        processing?: boolean;
        idPrefix: string;
        compact?: boolean;
    }>(),
    { errors: () => ({}), processing: false, compact: false },
);

const name = defineModel<string>('name', { required: true });
const content = defineModel<string>('content', { required: true });

const emit = defineEmits<{
    submit: [];
    cancel: [];
}>();
</script>

<template>
    <form class="flex min-h-0 flex-col" @submit.prevent="emit('submit')">
        <div :class="compact ? 'space-y-3' : 'space-y-4'">
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-name`">{{
                    $t(`signatures.${mode}.name`)
                }}</Label>
                <Input
                    :id="`${idPrefix}-name`"
                    v-model="name"
                    :data-testid="`${idPrefix}-name`"
                    :placeholder="trans(`signatures.${mode}.name_placeholder`)"
                    :aria-invalid="Boolean(props.errors.name)"
                />
                <p v-if="props.errors.name" class="text-sm text-destructive">
                    {{ props.errors.name }}
                </p>
            </div>
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-content`">{{
                    $t(`signatures.${mode}.content`)
                }}</Label>
                <Textarea
                    :id="`${idPrefix}-content`"
                    v-model="content"
                    :data-testid="`${idPrefix}-content`"
                    :placeholder="
                        trans(`signatures.${mode}.content_placeholder`)
                    "
                    :aria-invalid="Boolean(props.errors.content)"
                    rows="4"
                />
                <p class="text-xs text-muted-foreground">
                    {{ $t(`signatures.${mode}.content_hint`) }}
                </p>
                <p v-if="props.errors.content" class="text-sm text-destructive">
                    {{ props.errors.content }}
                </p>
            </div>
        </div>
        <div class="mt-auto flex justify-end gap-2 pt-4">
            <Button
                type="button"
                variant="outline"
                :data-testid="`cancel-${idPrefix}`"
                @click="emit('cancel')"
            >
                {{ $t('common.cancel') }}
            </Button>
            <Button
                type="submit"
                :data-testid="`submit-${idPrefix}`"
                :disabled="processing"
            >
                {{
                    $t(
                        `signatures.${mode}.${processing ? 'submitting' : 'submit'}`,
                    )
                }}
            </Button>
        </div>
    </form>
</template>
