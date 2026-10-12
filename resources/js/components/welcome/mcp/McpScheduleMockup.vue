<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconClock, IconPhoto } from '@tabler/icons-vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Badge } from '@/components/ui/badge';
import date from '@/date';
import type { SharedData } from '@/types';

const slideCount = 4;

/** The reply schedules the carousel on both networks: one post each. */
const platforms = ['instagram', 'linkedin'] as const;

const owner = usePage<SharedData>().props.auth.user;
const ownerPhoto = owner.has_photo ? owner.photo_url : null;
</script>

<template>
    <div
        class="grid gap-y-2 sm:grid-cols-[4.5rem_minmax(0,1fr)] sm:gap-x-4"
    >
        <time class="text-sm font-medium text-foreground sm:pt-3">
            {{ date.formatClockTime('10:00') }}
        </time>
        <div class="flex flex-col gap-2">
            <article
                v-for="platform in platforms"
                :key="platform"
                class="flex items-center gap-3 rounded-xl border border-border-strong bg-card p-3"
                :data-testid="`welcome-mcp-schedule-post-${platform}`"
            >
                <ChannelAvatar
                    :platform="platform"
                    :name="owner.name"
                    :src="ownerPhoto"
                    :size="32"
                    ring="card"
                />
                <div class="flex min-w-0 flex-1 flex-col items-start gap-1.5">
                    <span class="max-w-full truncate text-sm font-emphasis">{{
                        owner.name
                    }}</span>
                    <Badge class="h-6 gap-1 px-2 [&>svg]:size-4">
                        <IconClock />
                        {{ $t('posts.status.scheduled') }}
                    </Badge>
                </div>
                <div class="flex shrink-0 gap-1">
                    <span
                        v-for="slide in slideCount"
                        :key="slide"
                        class="flex size-9 items-center justify-center rounded-md border border-border-strong bg-secondary max-sm:[&:nth-child(n+3)]:hidden"
                    >
                        <IconPhoto class="size-4 text-muted-foreground" />
                    </span>
                </div>
            </article>
        </div>
    </div>
</template>
