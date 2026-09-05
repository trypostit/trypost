<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import date from '@/date';
import { activate, disable, pause, resume } from '@/routes/app/repurposes';
import type { Repurpose } from '@/types/repurpose';

const props = defineProps<{
    repurpose: Repurpose;
}>();

const canActivate = computed(
    () => props.repurpose.status !== 'active' && props.repurpose.destinations.length > 0,
);

const send = (url: string) => {
    router.post(url, {}, { preserveScroll: true });
};
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
                    v-if="repurpose.status === 'active'"
                    variant="outline"
                    data-testid="pause-repurpose"
                    @click="send(pause.url(repurpose.id))"
                >
                    {{ $t('repurposes.status_card.pause') }}
                </Button>

                <Button
                    v-if="repurpose.status === 'paused'"
                    variant="outline"
                    data-testid="resume-repurpose"
                    @click="send(resume.url(repurpose.id))"
                >
                    {{ $t('repurposes.status_card.resume') }}
                </Button>

                <Button
                    v-if="repurpose.status !== 'disabled' && repurpose.status !== 'draft'"
                    variant="ghost"
                    data-testid="disable-repurpose"
                    @click="send(disable.url(repurpose.id))"
                >
                    {{ $t('repurposes.status_card.disable') }}
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
