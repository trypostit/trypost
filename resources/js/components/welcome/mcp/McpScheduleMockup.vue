<script setup lang="ts">
import { IconClock, IconPhoto } from '@tabler/icons-vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Badge } from '@/components/ui/badge';
import date from '@/date';

const slides = [
    'bg-violet-100 dark:bg-violet-400/15',
    'bg-amber-100 dark:bg-amber-400/15',
    'bg-sky-100 dark:bg-sky-400/15',
    'bg-emerald-100 dark:bg-emerald-400/15',
];
</script>

<template>
    <div class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-4">
        <time class="pt-4 text-sm font-medium text-foreground">
            {{ date.formatClockTime('10:00') }}
        </time>
        <article class="rounded-xl border border-border-strong bg-card">
            <div class="flex gap-4 p-4">
                <div class="flex min-w-0 flex-1 flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <ChannelAvatar
                            platform="instagram"
                            name="Acme"
                            :size="32"
                            ring="card"
                        />
                        <span class="truncate text-sm font-emphasis">Acme</span>
                    </div>
                    <div class="flex flex-col gap-2">
                        <span class="h-2 w-full rounded-full bg-muted" />
                        <span class="h-2 w-5/6 rounded-full bg-muted" />
                        <span class="h-2 w-2/3 rounded-full bg-muted" />
                    </div>
                    <Badge class="h-6 gap-1 self-start px-2 [&>svg]:size-4">
                        <IconClock />
                        {{ $t('posts.status.scheduled') }}
                    </Badge>
                </div>
                <div class="grid w-28 shrink-0 grid-cols-2 content-start gap-1.5">
                    <span
                        v-for="(slide, index) in slides"
                        :key="slide"
                        :class="[
                            'relative flex aspect-square items-center justify-center rounded-md border border-border-strong',
                            slide,
                        ]"
                    >
                        <IconPhoto class="size-4 text-muted-foreground" />
                        <span
                            v-if="index === slides.length - 1"
                            class="absolute end-1 bottom-1 flex size-5 items-center justify-center rounded-full bg-foreground/60 text-[10px] font-medium text-background"
                        >
                            +3
                        </span>
                    </span>
                </div>
            </div>
        </article>
    </div>
</template>
