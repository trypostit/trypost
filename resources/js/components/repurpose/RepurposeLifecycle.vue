<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconDots, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { activate, disable, pause, resume } from '@/routes/app/repurposes';
import type { Repurpose } from '@/types/repurpose';
import { RepurposeStatus } from '@/types/repurpose-status';

const props = defineProps<{
    repurpose: Repurpose;
}>();

const emit = defineEmits<{ delete: [] }>();

const status = computed(() => props.repurpose.status);

const isIdle = computed(
    () => status.value === RepurposeStatus.Draft || status.value === RepurposeStatus.Disabled,
);

const canActivate = computed(() => isIdle.value && props.repurpose.destinations.length > 0);

const send = (url: string) =>
    router.post(url, {}, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(errors.status ?? errors.destinations ?? trans('repurposes.errors.action_failed')),
    });
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-testid="repurpose-lifecycle">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button variant="outline" size="icon" data-testid="repurpose-menu" :aria-label="$t('repurposes.menu.label')">
                    <IconDots class="size-4" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start">
                <DropdownMenuItem variant="destructive" data-testid="delete-repurpose" @select="emit('delete')">
                    <IconTrash class="size-4" />
                    {{ $t('repurposes.danger.delete') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

                <Button
                    v-if="canActivate"
                    data-testid="activate-repurpose"
                    @click="send(activate.url(repurpose.id))"
                >
                    {{ $t('repurposes.status_card.activate') }}
                </Button>

                <Button
                    v-if="status === RepurposeStatus.Active"
                    variant="outline"
                    data-testid="pause-repurpose"
                    @click="send(pause.url(repurpose.id))"
                >
                    {{ $t('repurposes.status_card.pause') }}
                </Button>

                <Button
                    v-if="status === RepurposeStatus.Paused"
                    variant="outline"
                    data-testid="resume-repurpose"
                    @click="send(resume.url(repurpose.id))"
                >
                    {{ $t('repurposes.status_card.resume') }}
                </Button>

                <Button
                    v-if="!isIdle"
                    variant="destructive"
                    data-testid="disable-repurpose"
                    @click="send(disable.url(repurpose.id))"
                >
                    {{ $t('repurposes.status_card.disable') }}
                </Button>
    </div>
</template>
