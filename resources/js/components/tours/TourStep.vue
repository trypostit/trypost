<script setup lang="ts" generic="Tour extends TourName">
import { useElementBounding } from '@vueuse/core';
import { computed, useTemplateRef } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { useTourStep } from '@/composables/useTour';
import { filled } from '@/lib/helpers';
import { labelTextColor } from '@/lib/labelColor';
import type { TourName, TourStepName } from '@/types/tour';

/**
 * One step of a tour (`TOURS` in `lib/tours.ts`), on the control in its slot.
 * While it is the tour's current step, the page goes dark around the control
 * and the step's title and description (`tours.<tour>.steps.<step>.*`) open
 * next to it. Escape or a click outside skips the tour. A `tag` (already
 * translated, like `$t('tours.tags.new')`) shows above the title as a chip of
 * `tagColor`, the way a label shows (the brand purple unless set).
 */
const props = withDefaults(
    defineProps<{
        tour: Tour;
        step: TourStepName<Tour>;
        tag?: string;
        tagColor?: string;
    }>(),
    { tag: undefined, tagColor: '#7c3aed' },
);

const { isActive, position, total, next, back, skip } = useTourStep(
    props.tour,
    props.step,
);

/** The tag's color as the background, with the text color that reads on it, as `LabelBadge` does. */
const tagStyle = computed(() => ({
    backgroundColor: props.tagColor,
    color: labelTextColor(props.tagColor),
}));

const target = useTemplateRef<HTMLElement>('target');
const bounds = useElementBounding(target);

/** Room left around the control inside the dark backdrop, in pixels. */
const SPOTLIGHT_PADDING = 6;

/** The cut-out of the dark backdrop, over the control. */
const spotlightStyle = computed(() => ({
    top: `${bounds.top.value - SPOTLIGHT_PADDING}px`,
    left: `${bounds.left.value - SPOTLIGHT_PADDING}px`,
    width: `${bounds.width.value + SPOTLIGHT_PADDING * 2}px`,
    height: `${bounds.height.value + SPOTLIGHT_PADDING * 2}px`,
}));

const copyKey = computed(() => `tours.${props.tour}.steps.${props.step}`);
const titleKey = computed(() => `${copyKey.value}.title`);
const descriptionKey = computed(() => `${copyKey.value}.description`);

/** A tour of one step only says "Got it"; a longer one shows where the user is, Skip, Back and Next. */
const hasSeveralSteps = computed(() => total.value > 1);
const isFirst = computed(() => position.value === 1);

/** The tag and, in a longer tour, where the user is ("2 of 3") sit above the title. */
const hasHeader = computed((): boolean => {
    if (filled(props.tag)) {
        return true;
    }

    return hasSeveralSteps.value;
});

/** Without Back (the first step), Next takes Back's place at the end of the row. */
const nextButtonClass = computed((): string | undefined => {
    if (isFirst.value) {
        return 'ms-auto';
    }

    return undefined;
});

const nextLabelKey = computed((): string => {
    if (position.value === total.value) {
        return 'tours.actions.done';
    }

    return 'tours.actions.next';
});

type Translate = (key: string, replacements: Record<string, string>) => string;

const progressLabel = (translate: Translate): string =>
    translate('tours.progress', {
        current: String(position.value),
        total: String(total.value),
    });

/** A test id without dots, which a CSS selector would read as classes. */
const testId = computed(
    () => `tour-${props.tour.replaceAll('.', '-')}-${props.step}`,
);

const closeTour = (open: boolean): void => {
    if (open) {
        return;
    }

    skip();
};
</script>

<template>
    <div ref="target" class="inline-flex">
        <Popover :open="isActive" modal @update:open="closeTour">
            <PopoverAnchor as-child>
                <div class="inline-flex">
                    <slot />
                </div>
            </PopoverAnchor>
            <Teleport v-if="isActive" to="body">
                <div
                    class="motion-dialog-overlay pointer-events-none fixed z-50 rounded-xl shadow-[0_0_0_9999px_rgb(0_0_0/0.5)] dark:shadow-[0_0_0_9999px_rgb(0_0_0/0.6)]"
                    :style="spotlightStyle"
                    data-state="open"
                    :data-testid="`${testId}-backdrop`"
                />
            </Teleport>
            <PopoverContent
                align="end"
                :side-offset="12"
                class="z-[60] w-80"
                :data-testid="`${testId}-content`"
            >
                <div
                    v-if="hasHeader"
                    class="mb-2 flex min-h-6 items-center gap-2"
                >
                    <span
                        v-if="filled(tag)"
                        class="inline-flex h-6 items-center rounded-full px-2.5 text-xs font-medium text-foreground"
                        :style="tagStyle"
                        :data-testid="`${testId}-tag`"
                    >
                        {{ tag }}
                    </span>
                    <span
                        v-if="hasSeveralSteps"
                        class="ms-auto text-xs text-muted-foreground"
                        :data-testid="`${testId}-progress`"
                    >
                        {{ progressLabel($t) }}
                    </span>
                </div>
                <p class="text-base font-semibold">{{ $t(titleKey) }}</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $t(descriptionKey) }}
                </p>
                <div
                    v-if="hasSeveralSteps"
                    class="mt-4 flex items-center gap-2"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        :data-testid="`${testId}-skip`"
                        @click="skip"
                    >
                        {{ $t('tours.actions.skip') }}
                    </Button>
                    <Button
                        v-if="!isFirst"
                        variant="outline"
                        size="sm"
                        class="ms-auto"
                        :data-testid="`${testId}-back`"
                        @click="back"
                    >
                        {{ $t('tours.actions.back') }}
                    </Button>
                    <Button
                        size="sm"
                        :class="nextButtonClass"
                        :data-testid="`${testId}-next`"
                        @click="next"
                    >
                        {{ $t(nextLabelKey) }}
                    </Button>
                </div>
                <div v-else class="mt-4 flex justify-end">
                    <Button
                        size="sm"
                        :data-testid="`${testId}-next`"
                        @click="next"
                    >
                        {{ $t('tours.actions.got_it') }}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    </div>
</template>
