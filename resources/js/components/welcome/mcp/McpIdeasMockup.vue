<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';

const columns = [
    { count: 3, cards: ['#6D43CC', '#159B92'] },
    { count: 5, cards: ['#E9833D', '#347AB7'] },
];
</script>

<template>
    <div class="grid grid-cols-2 gap-3">
        <section
            v-for="(column, index) in columns"
            :key="index"
            class="flex flex-col gap-2 rounded-lg bg-secondary p-2"
        >
            <header class="flex h-7 items-center gap-1.5 px-1">
                <span class="h-2 w-16 rounded-full bg-border-strong" />
                <span
                    class="inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-card px-1 text-xs font-medium text-muted-foreground"
                >
                    {{ column.count }}
                </span>
            </header>
            <article
                v-for="color in column.cards"
                :key="color"
                class="flex flex-col gap-2 rounded-lg border border-border bg-card p-3"
            >
                <span class="h-2 w-3/4 rounded-full bg-border-strong" />
                <span class="h-2 w-full rounded-full bg-muted" />
                <span class="h-2 w-2/3 rounded-full bg-muted" />
                <span
                    class="inline-flex items-center gap-1.5 self-start rounded-full border border-border bg-card px-2 py-0.5 shadow-2xs"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{ backgroundColor: color }"
                    />
                    <span class="h-1.5 w-8 rounded-full bg-muted" />
                </span>
            </article>
            <span
                class="flex h-7 items-center justify-center gap-1 text-xs font-medium text-muted-foreground"
            >
                <IconPlus class="size-3.5" />
                {{ $t('create.ideas.new') }}
            </span>
        </section>
    </div>
</template>
