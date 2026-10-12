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
        <time class="text-sm font-medium text-foreground sm:pt-4">
            {{ date.formatClockTime('10:00') }}
        </time>
        <div class="flex flex-col gap-1.5">
        <article
            v-for="platform in platforms"
            :key="platform"
            class="rounded-xl border border-border-strong bg-card"
            :data-testid="`welcome-mcp-schedule-post-${platform}`"
        >
            <div class="flex flex-col gap-4 p-4 sm:flex-row">
                <div class="flex min-w-0 flex-1 flex-col gap-2.5">
                    <div class="flex items-center gap-3">
                        <ChannelAvatar
                            :platform="platform"
                            :name="owner.name"
                    :src="ownerPhoto"
                            :size="32"
                            ring="card"
                        />
                        <span class="truncate text-sm font-emphasis">{{
                            owner.name
                        }}</span>
                    </div>
                    <div class="flex flex-col gap-2">
                        <span class="h-2 w-full rounded-full bg-muted" />
                        <span class="h-2 w-5/6 rounded-full bg-muted" />
                    </div>
                    <Badge class="h-6 gap-1 self-start px-2 [&>svg]:size-4">
                        <IconClock />
                        {{ $t('posts.status.scheduled') }}
                    </Badge>
                </div>
                <div class="grid shrink-0 grid-cols-4 content-start gap-1.5 sm:w-24 sm:grid-cols-2">
                    <span
                        v-for="slide in slideCount"
                        :key="slide"
                        class="flex aspect-square items-center justify-center rounded-md border border-border-strong bg-secondary"
                    >
                        <IconPhoto class="size-4 text-muted-foreground" />
                    </span>
                </div>
            </div>
        </article>
        </div>
    </div>
</template>
