<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';

import HexColorInput from '@/components/app/HexColorInput.vue';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

withDefaults(
    defineProps<{
        errors?: Partial<Record<'name' | 'color', string>>;
        processing?: boolean;
        idPrefix: string;
        compact?: boolean;
    }>(),
    { errors: () => ({}), processing: false, compact: false },
);

const name = defineModel<string>('name', { required: true });
const color = defineModel<string>('color', { required: true });

const emit = defineEmits<{
    submit: [];
    cancel: [];
}>();

const cancel = (): void => {
    emit('cancel');
};
</script>

<template>
    <form class="flex min-h-0 flex-col" @submit.prevent="emit('submit')">
        <div :class="compact ? 'space-y-3' : 'space-y-6'">
            <div class="space-y-2">
                <Label :for="`${idPrefix}-name`">{{
                    $t('labels.create.name')
                }}</Label>
                <Input
                    :id="`${idPrefix}-name`"
                    v-model="name"
                    :data-testid="`${idPrefix}-name`"
                    :placeholder="trans('labels.create.name_placeholder')"
                    :class="{ 'border-destructive': errors.name }"
                />
                <p v-if="errors.name" class="text-sm text-destructive-text">
                    {{ errors.name }}
                </p>
            </div>

            <div class="space-y-2">
                <Label :for="`${idPrefix}-color`">{{
                    $t('labels.create.color')
                }}</Label>
                <HexColorInput v-model="color" name="color" />
                <p v-if="errors.color" class="text-sm text-destructive-text">
                    {{ errors.color }}
                </p>
            </div>
        </div>
        <component
            :is="compact ? 'div' : DialogFooter"
            :class="compact ? 'mt-auto flex justify-end gap-2 pt-4' : 'mt-6'"
        >
            <Button
                type="button"
                variant="ghost"
                :data-testid="`cancel-${idPrefix}`"
                @click="cancel"
            >
                {{ $t('common.cancel') }}
            </Button>
            <Button
                type="submit"
                :data-testid="`submit-${idPrefix}`"
                :disabled="processing"
            >
                {{
                    processing
                        ? $t('labels.create.submitting')
                        : $t('labels.create.submit')
                }}
            </Button>
        </component>
    </form>
</template>
