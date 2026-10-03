<script setup lang="ts">
import { IconPhotoPlus, IconX } from '@tabler/icons-vue';

import type { LinkCard } from '@/composables/useLinkCard';

withDefaults(
    defineProps<{
        card: LinkCard;
        testIdPrefix: string;
        removable?: boolean;
        replacing?: boolean;
        disabled?: boolean;
    }>(),
    { removable: true, replacing: false, disabled: false },
);

const emit = defineEmits<{ remove: []; replace: [] }>();
</script>

<template>
    <div class="space-y-2">
        <div
            class="relative flex gap-4 rounded-md bg-muted p-4"
            :data-testid="testIdPrefix"
        >
            <img
                v-if="card.image"
                :src="card.image"
                :alt="card.title"
                class="aspect-[1.91/1] w-2/5 shrink-0 rounded-sm object-cover"
            />
            <div
                v-else
                class="aspect-[1.91/1] w-2/5 shrink-0 rounded-sm bg-background"
            />
            <div class="min-w-0 flex-1 space-y-1 pe-6">
                <p
                    class="line-clamp-2 text-sm font-semibold"
                    :data-testid="`${testIdPrefix}-title`"
                >
                    {{ card.title }}
                </p>
                <p class="truncate text-xs text-muted-foreground">
                    {{ card.domain }}
                </p>
                <p
                    v-if="card.description"
                    class="line-clamp-3 text-xs"
                >
                    {{ card.description }}
                </p>
            </div>
            <button
                v-if="removable"
                type="button"
                class="absolute end-2 top-2 rounded-sm p-1 text-muted-foreground transition-control hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :aria-label="$t('posts.composer.link_preview.remove')"
                :disabled="disabled"
                :data-testid="`${testIdPrefix}-remove`"
                @click="emit('remove')"
            >
                <IconX class="size-4" />
            </button>
        </div>
        <button
            type="button"
            class="flex items-center gap-1.5 rounded-sm text-sm text-muted-foreground transition-control hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:opacity-50"
            :disabled="disabled || replacing"
            :data-testid="`${testIdPrefix}-replace`"
            @click="emit('replace')"
        >
            <IconPhotoPlus class="size-4 shrink-0" />
            <span data-single-line>{{
                $t('posts.composer.link_preview.replace_with_media')
            }}</span>
        </button>
    </div>
</template>
