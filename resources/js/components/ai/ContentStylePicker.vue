<script setup lang="ts">
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

interface StyleOption {
    key: string;
    preview: string;
    name: string;
    description?: string;
}

const props = withDefaults(
    defineProps<{
        modelValue: string;
        styles: StyleOption[];
        mini?: boolean;
    }>(),
    { mini: false },
);

const emit = defineEmits<{
    'update:modelValue': [string];
}>();

const open = ref(false);

const selectedOption = computed(
    () =>
        props.styles.find((style) => style.key === props.modelValue) ??
        props.styles[0],
);

const select = (key: string) => {
    emit('update:modelValue', key);
    open.value = false;
};
</script>

<template>
    <!-- Full gallery: one card per style, side by side. -->
    <div v-if="!props.mini" class="grid gap-3 sm:grid-cols-3">
        <button
            v-for="style in props.styles"
            :key="style.key"
            type="button"
            class="relative flex cursor-pointer flex-col overflow-hidden rounded-xl border border-border bg-card text-left shadow-2xs transition-all hover:bg-foreground/5"
            :class="
                modelValue === style.key
                    ? 'border-amber-300 !bg-amber-100 shadow-sm'
                    : 'hover:border-amber-200 hover:!bg-amber-50'
            "
            @click="select(style.key)"
        >
            <div class="aspect-video w-full overflow-hidden bg-muted">
                <img
                    :src="style.preview"
                    :alt="style.name"
                    class="size-full object-cover"
                />
            </div>
            <div class="flex items-start gap-2 p-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-foreground">
                        {{ style.name }}
                    </p>
                    <p
                        v-if="style.description"
                        class="mt-0.5 text-xs leading-snug text-foreground/60"
                    >
                        {{ style.description }}
                    </p>
                </div>
                <IconCheck
                    v-if="modelValue === style.key"
                    class="mt-0.5 size-4 shrink-0 text-foreground"
                    stroke-width="3"
                />
            </div>
        </button>
    </div>

    <!-- Compact: collapsed to the selected style, expands into the list. -->
    <Collapsible v-else v-model:open="open">
        <CollapsibleTrigger as-child>
            <button
                type="button"
                class="flex w-full cursor-pointer items-center gap-2.5 overflow-hidden rounded-xl border border-border bg-card p-1.5 text-left transition-all hover:bg-foreground/5"
            >
                <div
                    class="aspect-video w-28 shrink-0 overflow-hidden rounded-lg bg-muted"
                >
                    <img
                        :src="selectedOption.preview"
                        :alt="selectedOption.name"
                        class="size-full object-cover"
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-foreground">
                        {{ selectedOption.name }}
                    </p>
                    <p
                        v-if="selectedOption.description"
                        class="mt-0.5 text-xs leading-snug text-foreground/60"
                    >
                        {{ selectedOption.description }}
                    </p>
                </div>
                <IconChevronDown
                    class="mr-1 size-4 shrink-0 text-foreground/60 transition-transform"
                    :class="open ? 'rotate-180' : ''"
                />
            </button>
        </CollapsibleTrigger>
        <CollapsibleContent class="mt-2 space-y-2">
            <button
                v-for="style in props.styles"
                :key="style.key"
                type="button"
                class="flex w-full cursor-pointer items-center gap-2.5 overflow-hidden rounded-xl border border-border bg-card p-1.5 text-left transition-all hover:bg-foreground/5"
                :class="
                    modelValue === style.key
                        ? 'border-amber-300 !bg-amber-100 shadow-sm'
                        : 'hover:border-amber-200 hover:!bg-amber-50'
                "
                @click="select(style.key)"
            >
                <div
                    class="aspect-video w-28 shrink-0 overflow-hidden rounded-lg bg-muted"
                >
                    <img
                        :src="style.preview"
                        :alt="style.name"
                        class="size-full object-cover"
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-foreground">
                        {{ style.name }}
                    </p>
                    <p
                        v-if="style.description"
                        class="mt-0.5 text-xs leading-snug text-foreground/60"
                    >
                        {{ style.description }}
                    </p>
                </div>
                <IconCheck
                    v-if="modelValue === style.key"
                    class="mr-1 size-4 shrink-0 text-foreground"
                    stroke-width="3"
                />
            </button>
        </CollapsibleContent>
    </Collapsible>
</template>
