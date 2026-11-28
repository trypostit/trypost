<script setup lang="ts">
import { IconClock, IconPlus } from '@tabler/icons-vue';
</script>

<template>
    <div
        class="flex justify-center overflow-hidden rounded-lg bg-sky-100 px-6 pt-4 dark:bg-sky-950/70"
        aria-hidden="true"
    >
        <div
            class="w-full space-y-1.5 rounded-t-lg bg-background p-3 pb-2.5 shadow-xs ring-1 ring-sky-900/5 dark:bg-card dark:ring-white/10"
        >
            <div
                class="flex items-center gap-1.5 pb-0.5"
            >
                <IconClock
                    class="size-3.5 text-muted-foreground"
                    :stroke-width="1.5"
                />
                <span
                    class="block h-1 w-14 rounded-full bg-muted-foreground/30"
                />
            </div>
            <div class="flex items-center gap-2">
                <span
                    class="block h-1 w-4 shrink-0 rounded-full bg-muted-foreground/20"
                />
                <div
                    class="flex h-5 flex-1 items-center gap-1.5 rounded-md border border-border bg-muted px-1.5"
                >
                    <span
                        class="size-2 shrink-0 rounded-full bg-muted-foreground/30"
                    />
                    <span
                        class="block h-1 w-3/5 rounded-full bg-muted-foreground/20"
                    />
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span
                    class="block h-1 w-4 shrink-0 rounded-full bg-sky-400 dark:bg-sky-400/80"
                />
                <div
                    class="flex h-5 flex-1 items-center gap-1 rounded-md border border-sky-400 bg-sky-50 px-1.5 dark:border-sky-400/60 dark:bg-sky-400/10"
                >
                    <IconPlus
                        class="size-3 text-sky-500 dark:text-sky-300"
                        :stroke-width="1.75"
                    />
                    <span
                        class="block h-1 w-2/5 rounded-full bg-sky-300/80 dark:bg-sky-300/40"
                    />
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span
                    class="block h-1 w-4 shrink-0 rounded-full bg-muted-foreground/20"
                />
                <span
                    class="block h-5 flex-1 rounded-md border border-dashed border-border-strong"
                />
            </div>
        </div>
    </div>
</template>
