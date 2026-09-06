<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconAlertTriangle } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import date from '@/date';
import { activate, disable, pause, resume } from '@/routes/app/repurposes';
import type { Repurpose } from '@/types/repurpose';
import { RepurposeStatus } from '@/types/repurpose-status';

const props = defineProps<{
    repurpose: Repurpose;
}>();

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
    <Card data-testid="repurpose-status-card">
        <CardHeader>
            <CardTitle>{{ $t('repurposes.status_card.title') }}</CardTitle>
            <CardDescription>{{ $t(`repurposes.status_card.${repurpose.status}_hint`) }}</CardDescription>
        </CardHeader>

        <CardContent class="space-y-4">
            <p v-if="repurpose.last_error" class="flex items-start gap-2 rounded-lg border-2 border-foreground bg-rose-50 p-2 text-xs font-semibold text-rose-700">
                <IconAlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                {{ repurpose.last_error }}
            </p>

            <dl class="space-y-2 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">
                        {{ $t('repurposes.status_card.watermark') }}
                    </dt>
                    <dd class="text-right">{{ repurpose.activated_at ? date.formatDateTime(repurpose.activated_at) : '—' }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">
                        {{ $t('repurposes.status_card.last_polled') }}
                    </dt>
                    <dd class="text-right">{{ repurpose.last_polled_at ? date.diffForHumans(repurpose.last_polled_at) : '—' }}</dd>
                </div>
            </dl>

            <div class="flex flex-wrap gap-2">
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
        </CardContent>
    </Card>
</template>
