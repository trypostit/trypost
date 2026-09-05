<script setup lang="ts">
import { IconArrowRight } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { RepurposeTemplate } from '@/types/repurpose';

defineProps<{
    template: RepurposeTemplate;
}>();

const emit = defineEmits<{
    use: [template: RepurposeTemplate];
}>();
</script>

<template>
    <Card class="flex flex-col">
        <CardHeader>
            <CardTitle>{{ $t(`repurposes.templates.${template.key}.title`) }}</CardTitle>
            <CardDescription>{{ $t(`repurposes.templates.${template.key}.description`) }}</CardDescription>
        </CardHeader>

        <CardContent class="mt-auto flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                <span>{{ template.source_platform }}</span>
                <IconArrowRight class="size-3.5" />
                <span>{{ template.destination_platforms.join(', ') }}</span>
            </div>

            <Button
                variant="outline"
                size="sm"
                :data-testid="`use-template-${template.key}`"
                @click="emit('use', template)"
            >
                {{ $t('repurposes.templates.use') }}
            </Button>
        </CardContent>
    </Card>
</template>
