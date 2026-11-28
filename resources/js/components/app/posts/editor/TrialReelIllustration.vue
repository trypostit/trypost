<script setup lang="ts">
import { IconFlask } from '@tabler/icons-vue';
</script>

<template>
    <div
        class="flex justify-center overflow-hidden rounded-lg bg-amber-100 px-6 pt-4 dark:bg-amber-300/10"
        aria-hidden="true"
        data-testid="instagram-trial-reel-illustration"
    >
        <div
            class="w-full space-y-2 rounded-t-lg bg-background p-3 pb-2.5 shadow-xs ring-1 ring-amber-900/5 dark:bg-card dark:ring-white/10"
        >
            <div
                class="space-y-1.5 rounded-md border border-amber-400 bg-amber-50 p-2 dark:border-amber-400/60 dark:bg-amber-400/10"
            >
                <div class="flex items-center gap-1.5">
                    <IconFlask
                        class="size-3.5 text-amber-600 dark:text-amber-300"
                        :stroke-width="1.5"
                    />
                    <span
                        class="flex-1 text-[10px] font-medium text-foreground"
                        >{{
                            $t('posts.form.instagram.trial_reel.badge')
                        }}</span
                    >
                    <span
                        class="flex h-3 w-5 items-center justify-end rounded-full bg-amber-400 p-0.5 dark:bg-amber-400/80"
                    >
                        <span
                            class="size-2 rounded-full bg-white dark:bg-amber-950"
                        />
                    </span>
                </div>
                <span
                    class="block h-1 w-full rounded-full bg-amber-300/60 dark:bg-amber-300/25"
                />
                <span
                    class="block h-1 w-4/5 rounded-full bg-amber-300/60 dark:bg-amber-300/25"
                />
            </div>
            <span
                class="block h-1 w-2/3 rounded-full bg-muted-foreground/20"
            />
            <span
                class="block h-1 w-1/2 rounded-full bg-muted-foreground/20"
            />
            <div class="flex gap-2 pt-1">
                <span
                    class="h-4 flex-1 rounded-md border border-border bg-muted"
                />
                <span
                    class="h-4 flex-1 rounded-md bg-primary-strong dark:bg-primary"
                />
            </div>
        </div>
    </div>
</template>
