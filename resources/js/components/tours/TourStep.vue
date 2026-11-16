<script setup lang="ts" generic="Tour extends TourName">
import { useElementBounding } from '@vueuse/core';
import { computed, useId, useTemplateRef } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { useTourStep } from '@/composables/tours/useTour';
import { filled } from '@/lib/helpers';
import { labelTextColor } from '@/lib/labelColor';
import type { TourName, TourStepName } from '@/types/tour';

/**
 * One step of a tour (`TOURS` in `lib/tours.ts`), on the control in its slot.
 * While it is the tour's current step, the page goes dark around the control
 * and the step's title and description (`tours.<tour>.steps.<step>.*`) open
 * next to it. Using the control itself moves the tour on (it completes a
 * one-step tour); Escape or a click elsewhere skips it. A `tag` (already
 * translated, like `$t('tours.tags.new')`) shows above the title as a chip of
 * `tagColor`, the way a label shows (the brand purple unless set). A
 * `disabled` step renders its control and never opens.
 */
const props = withDefaults(
    defineProps<{
        tour: Tour;
        step: TourStepName<Tour>;
        tag?: string;
        tagColor?: string;
        disabled?: boolean;
    }>(),
    { tag: undefined, tagColor: '#7c3aed', disabled: false },
);

const { isActive, position, total, next, back, skip } = useTourStep(
    props.tour,
    props.step,
    !props.disabled,
);

const titleId = useId();
const descriptionId = useId();

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

type PointerDownOutside = CustomEvent<{ originalEvent: PointerEvent }>;

/**
 * A press on the control the step points at keeps the tour open until the
 * click lands on the control, then moves the tour on, instead of skipping it:
 * closing it at once would take the control's pointer events away before the
 * click.
 */
const followControl = (event: PointerDownOutside): void => {
    const clicked = event.detail.originalEvent.target;

    if (!(clicked instanceof Node)) {
        return;
    }

    if (!filled(target.value)) {
        return;
    }

    if (!target.value.contains(clicked)) {
        return;
    }

    event.preventDefault();
    target.value.addEventListener('click', next, { once: true });
};

const closeTour = (open: boolean): void => {
    if (open) {
        return;
    }

    skip();
};
</script>

<template>
    <div ref="target" class="inline-flex" :class="targetClass">
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
                :aria-labelledby="titleId"
                :aria-describedby="descriptionId"
                :data-testid="`${testId}-content`"
                @pointer-down-outside="followControl"
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
                <p :id="titleId" class="text-base font-semibold">
                    {{ $t(titleKey) }}
                </p>
                <p
                    :id="descriptionId"
                    class="mt-1 text-sm text-muted-foreground"
                >
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
