<script setup lang="ts">
import { IconCalendar, IconTrash } from '@tabler/icons-vue';
import { computed } from 'vue';

import InputError from '@/components/InputError.vue';
import PickTimePopover from '@/components/posts/PickTimePopover.vue';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { usePageErrors } from '@/composables/usePageErrors';
import { PostStatus } from '@/types/post';

withDefaults(
    defineProps<{
        isReadOnly: boolean;
        isScheduled: boolean;
        canEdit?: boolean;
        isSaving: boolean;
        isSubmitting: boolean;
        isPostActionDisabled: boolean;
        postActionTooltip: string;
        pickTimeLabel: string;
    }>(),
    {
        canEdit: true,
    },
);

const hasPickedTime = defineModel<boolean>('hasPickedTime', { required: true });
const scheduledDateTime = defineModel<string>('scheduledDateTime', { required: true });

const emit = defineEmits<{
    (e: 'delete'): void;
    (e: 'unschedule'): void;
    (e: 'submit', status: string): void;
}>();

const errors = usePageErrors();
const scheduledAtError = computed(() => errors.value.scheduled_at);
</script>

<template>
    <Button
        v-if="isScheduled && canEdit"
        type="button"
        variant="outline"
        class="bg-background hover:bg-violet-50"
        :disabled="isSubmitting"
        @click="emit('unschedule')"
    >
        {{ $t('posts.edit.unschedule_cta') }}
    </Button>

    <div v-else-if="!isReadOnly && canEdit" class="flex w-full flex-col gap-1 lg:w-auto lg:items-end">
        <div class="flex w-full items-center gap-2 lg:w-auto">
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="bg-rose-100 hover:bg-rose-200"
                            :disabled="isSaving || isSubmitting"
                            @click="emit('delete')"
                        >
                            <IconTrash class="size-4 text-rose-700" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{{ $t('posts.edit.delete') }}</TooltipContent>
                </Tooltip>

                <Tooltip>
                    <TooltipTrigger as-child>
                        <span tabindex="0">
                            <PickTimePopover
                                v-model="scheduledDateTime"
                                :disabled="isPostActionDisabled"
                                @confirm="hasPickedTime = true"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="isPostActionDisabled"
                                >
                                    <IconCalendar class="size-4" />
                                    {{ pickTimeLabel }}
                                </Button>
                            </PickTimePopover>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent v-if="postActionTooltip" class="max-w-xs whitespace-pre-line">
                        {{ postActionTooltip }}
                    </TooltipContent>
                </Tooltip>

                <Tooltip>
                    <TooltipTrigger as-child>
                        <span tabindex="0" class="flex-1 lg:flex-none">
                            <Button
                                type="button"
                                class="w-full lg:w-auto"
                                :disabled="isPostActionDisabled"
                                @click="emit('submit', hasPickedTime ? PostStatus.Scheduled : PostStatus.Publishing)"
                            >
                                {{ hasPickedTime ? $t('posts.edit.schedule') : $t('posts.edit.post_now') }}
                            </Button>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent v-if="postActionTooltip" class="max-w-xs whitespace-pre-line">
                        {{ postActionTooltip }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
        <InputError :message="scheduledAtError" />
    </div>
</template>
