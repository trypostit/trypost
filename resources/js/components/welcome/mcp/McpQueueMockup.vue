<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import date from '@/date';
import type { SharedData } from '@/types';

const posts = [
    { time: '09:00', platform: 'linkedin' },
    { time: '12:30', platform: 'instagram' },
    { time: '15:00', platform: 'facebook' },
];

const owner = usePage<SharedData>().props.auth.user;
const ownerPhoto = owner.has_photo ? owner.photo_url : null;
</script>

<template>
    <section class="flex flex-col gap-3">
        <h3 class="text-sm font-emphasis text-foreground">
            {{ $t('posts.publish.tomorrow') }}
        </h3>
        <div
            v-for="post in posts"
            :key="post.time"
            class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-center gap-x-4"
        >
            <time class="text-sm font-medium text-foreground">
                {{ date.formatClockTime(post.time) }}
            </time>
            <article
                class="flex items-center gap-3 rounded-xl border border-border-strong bg-card p-3"
            >
                <ChannelAvatar
                    :platform="post.platform"
                    :name="owner.name"
                    :src="ownerPhoto"
                    :size="28"
                    ring="card"
                />
                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <span class="h-2 w-4/5 rounded-full bg-muted" />
                    <span class="h-2 w-1/2 rounded-full bg-muted" />
                </div>
            </article>
        </div>
        <div class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-center gap-x-4">
            <time class="text-sm font-medium text-foreground">
                {{ date.formatClockTime('18:00') }}
            </time>
            <span
                class="flex h-12 items-center gap-1 rounded-xl border border-border-strong bg-card px-2 text-sm font-medium text-foreground"
            >
                <PlatformBrandIcon platform="x" colored />
                <IconPlus class="size-4" />
                {{ $t('posts.publish.new_in_slot') }}
            </span>
        </div>
    </section>
</template>
