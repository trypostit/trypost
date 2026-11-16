<script setup lang="ts" generic="Tour extends TourName">
import { useElementBounding } from '@vueuse/core';
import { computed, useTemplateRef, watchEffect } from 'vue';

import { showTourStep, useTourStep } from '@/composables/tours/useTour';
import { filled } from '@/lib/helpers';
import type { TourName, TourStepName } from '@/types/tour';

/**
 * One step of a tour (`TOURS` in `lib/tours.ts`), on the control in its slot.
 * While it is the tour's current step, the tour's overlay (`TourOverlay`)
 * darkens the page around the control and opens the step's title and
 * description (`tours.<tour>.steps.<step>.*`) next to it. A `tag` (already
 * translated, like `$t('tours.tags.new')`) shows above the title as a chip of
 * `tagColor`, the way a label shows (the brand purple unless set). `align`
 * places the card along the control (`end` by default, `start` for a control
 * on the left). A `disabled` step renders its control and never opens; it is
 * read once, when the step mounts.
 */
const props = withDefaults(
    defineProps<{
        tour: Tour;
        step: TourStepName<Tour>;
        tag?: string;
        tagColor?: string;
        disabled?: boolean;
        align?: 'start' | 'center' | 'end';
    }>(),
    { tag: undefined, tagColor: '#7c3aed', disabled: false, align: 'end' },
);

const { isActive, position, total, next, back, skip } = useTourStep(
    props.tour,
    props.step,
    !props.disabled,
);

const target = useTemplateRef<HTMLElement>('target');
const bounds = useElementBounding(target);

/** Room left around the control inside the dark backdrop, in pixels. */
const SPOTLIGHT_PADDING = 6;

/** While this step is open, the overlay shows it on its control. */
watchEffect(() => {
    if (!isActive.value) {
        return;
    }

    if (!filled(target.value)) {
        return;
    }

    showTourStep({
        tour: props.tour,
        step: props.step,
        position: position.value,
        total: total.value,
        tag: props.tag,
        tagColor: props.tagColor,
        align: props.align,
        control: target.value,
        frame: {
            top: bounds.top.value - SPOTLIGHT_PADDING,
            left: bounds.left.value - SPOTLIGHT_PADDING,
            width: bounds.width.value + SPOTLIGHT_PADDING * 2,
            height: bounds.height.value + SPOTLIGHT_PADDING * 2,
        },
        next,
        back,
        skip,
    });
});

/**
 * The open tour blocks the page around it; the control it points at stays
 * usable, so it still reacts to the click that moves the tour on.
 */
const targetClass = computed((): string | undefined => {
    if (isActive.value) {
        return 'pointer-events-auto';
    }

    return undefined;
});
</script>

<template>
    <div ref="target" class="inline-flex" :class="targetClass">
        <slot />
    </div>
</template>
