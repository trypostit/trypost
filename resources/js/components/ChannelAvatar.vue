<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Avatar } from '@/components/ui/avatar';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { isConnectionLost } from '@/types/social-account';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

type ChannelAvatarSize = 20 | 24 | 28 | 32 | 40 | 44 | 64;

type ChannelAvatarRing = 'background' | 'card' | 'popover' | 'sidebar';

interface SizeSpec {
    avatar: string;
    fallback: string;
    badge: number;
    offset: string;
    reserve: string;
    dot: string;
}

/**
 * The badge sits outside the avatar's bottom-end corner: it overhangs by about
 * a third of its size on the end side and a fifth at the bottom, and `reserve`
 * keeps that overhang clear of whatever follows the avatar.
 */
const SIZES: Record<ChannelAvatarSize, SizeSpec> = {
    20: {
        avatar: 'size-5 rounded-md',
        fallback: 'text-[8px]',
        badge: 10,
        offset: '-end-1 -bottom-0.5',
        reserve: 'me-0.5',
        dot: '-top-0.5 -start-0.5 size-1.5',
    },
    24: {
        avatar: 'size-6 rounded-full',
        fallback: 'text-[9px]',
        badge: 12,
        offset: '-end-1 -bottom-0.5',
        reserve: 'me-0.5',
        dot: '-top-0.5 -start-0.5 size-2',
    },
    28: {
        avatar: 'size-7 rounded-md',
        fallback: 'text-[10px]',
        badge: 18,
        offset: '-end-1.5 -bottom-1',
        reserve: 'me-1',
        dot: '-top-0.5 -start-0.5 size-2',
    },
    32: {
        avatar: 'size-8 rounded-lg',
        fallback: 'text-[10px]',
        badge: 18,
        offset: '-end-1.5 -bottom-1',
        reserve: 'me-1',
        dot: '-top-0.5 -start-0.5 size-2.5',
    },
    40: {
        avatar: 'size-10 rounded-xl',
        fallback: 'text-xs',
        badge: 22,
        offset: '-end-2 -bottom-1',
        reserve: 'me-1.5',
        dot: '-top-1 -start-1 size-2.5',
    },
    44: {
        avatar: 'size-11 rounded-xl',
        fallback: 'text-xs',
        badge: 24,
        offset: '-end-2 -bottom-1',
        reserve: 'me-1.5',
        dot: '-top-1 -start-1 size-3',
    },
    64: {
        avatar: 'size-16 rounded-2xl',
        fallback: 'text-sm',
        badge: 28,
        offset: '-end-2.5 -bottom-1.5',
        reserve: 'me-2',
        dot: '-top-1 -start-1 size-3.5',
    },
};

const props = withDefaults(
    defineProps<{
        platform: string;
        name: string;
        src?: string | null;
        size?: ChannelAvatarSize;
        ring?: ChannelAvatarRing;
        status?: SocialAccountStatusValue | null;
        accountId?: string | null;
        reserveSpace?: boolean;
        badge?: boolean;
        avatarClass?: HTMLAttributes['class'];
    }>(),
    {
        src: null,
        size: 32,
        ring: 'background',
        status: null,
        accountId: null,
        reserveSpace: true,
        badge: true,
        avatarClass: undefined,
    },
);

const spec = computed(() => SIZES[props.size]);

const ringClasses: Record<ChannelAvatarRing, string> = {
    background: 'ring-background',
    card: 'ring-card',
    popover: 'ring-popover',
    sidebar: 'ring-sidebar',
};

const disconnected = computed(() => isConnectionLost({ status: props.status }));
</script>

<template>
    <span
        :class="[
            'relative inline-flex shrink-0',
            reserveSpace && badge ? spec.reserve : '',
        ]"
    >
        <Avatar
            :src="src"
            :name="name"
            :class="cn(spec.avatar, avatarClass)"
            :fallback-class="['bg-secondary font-bold', spec.fallback]"
        />
        <PlatformLogo
            v-if="badge"
            :platform="platform"
            :size="spec.badge"
            :ring="ring"
            :title="null"
            :class="['absolute', spec.offset]"
        />
        <TooltipProvider v-if="disconnected" :delay-duration="200">
            <Tooltip>
                <TooltipTrigger as-child>
                    <span
                        role="img"
                        :aria-label="$t('channels.connection_lost_hint')"
                        :class="[
                            'absolute z-10 rounded-full bg-destructive ring-2',
                            spec.dot,
                            ringClasses[ring],
                        ]"
                        :data-testid="
                            accountId
                                ? `channel-avatar-disconnected-${accountId}`
                                : 'channel-avatar-disconnected'
                        "
                    />
                </TooltipTrigger>
                <TooltipContent>
                    {{ $t('channels.connection_lost_hint') }}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
        <slot />
    </span>
</template>
