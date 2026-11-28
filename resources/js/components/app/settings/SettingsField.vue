<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineProps<{
    label: string;
    for?: string;
    hint?: string;
    error?: string;
}>();
</script>

<template>
    <div class="flex flex-col gap-2">
        <Label :for="$props.for" class="leading-normal">{{ label }}</Label>
        <slot />
        <p v-if="hint" class="text-sm text-muted-foreground">{{ hint }}</p>
        <InputError :message="error" />
    </div>
</template>
