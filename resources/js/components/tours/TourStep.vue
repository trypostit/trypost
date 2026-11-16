<script setup lang="ts" generic="Tour extends TourName">
import { IconChevronsLeft, IconChevronsRight } from '@tabler/icons-vue';
import { useElementBounding } from '@vueuse/core';
import { computed, useId, useTemplateRef, watchEffect } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { frameTourControl, useTourStep } from '@/composables/tours/useTour';
import { filled } from '@/lib/helpers';
import { labelTextColor } from '@/lib/labelColor';
import type { TourName, TourStepName } from '@/types/tour';

/**
 * One step of a tour (`TOURS` in `lib/tours.ts`), on the control in its slot.
 * While it is the tour's current step, the page goes dark around the control
 * (`TourBackdrop`, which moves from step to step) and the step's title and
 * description (`tours.<tour>.steps.<step>.*`) open next to it. Using the control itself moves the tour on (it completes a
 * one-step tour); Escape or a click elsewhere skips it. A `tag` (already
 * translated, like `$t('tours.tags.new')`) shows above the title as a chip of
 * `tagColor`, the way a label shows (the brand purple unless set). A
 * `disabled` step renders its control and never opens. `align` places the
 * card along the control (`end` by default, `start` for a control on the left).
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

/** While this step is open, the tour's one backdrop (`TourBackdrop`) frames its control. */
watchEffect(() => {
    if (!isActive.value) {
        return;
    }

    frameTourControl({
        top: bounds.top.value - SPOTLIGHT_PADDING,
        left: bounds.left.value - SPOTLIGHT_PADDING,
        width: bounds.width.value + SPOTLIGHT_PADDING * 2,
        height: bounds.height.value + SPOTLIGHT_PADDING * 2,
    });
});

const copyKey = computed(() => `tours.${props.tour}.steps.${props.step}`);
const titleKey = computed(() => `${copyKey.value}.title`);
const descriptionKey = computed(() => `${copyKey.value}.description`);

/** A tour of one step only says "Got it"; a longer one shows where the user is, Skip, Back and Next. */
const hasSeveralSteps = computed(() => total.value > 1);
const isFirst = computed(() => position.value === 1);
const isLast = computed(() => position.value === total.value);

/** In a longer tour, where the user is ("2 of 3") sits next to the tag, above the title. */
const progressBesideTag = computed((): boolean => {
    if (!filled(props.tag)) {
        return false;
    }

    return hasSeveralSteps.value;
});

/** Without a tag, it sits on the title's line instead, so the title stays at the top. */
const progressBesideTitle = computed((): boolean => {
    if (filled(props.tag)) {
        return false;
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
    if (isLast.value) {
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

/** The arrow key that reads forward: right, or left in a right-to-left language. */
const forwardKey = (): string => {
    if (document.documentElement.dir === 'rtl') {
        return 'ArrowLeft';
    }

    return 'ArrowRight';
};

const backwardKey = (): string => {
    if (document.documentElement.dir === 'rtl') {
        return 'ArrowRight';
    }

    return 'ArrowLeft';
};

/** In a longer tour, the arrow keys do what Next (or Done) and Back do. */
const followArrowKey = (event: KeyboardEvent): void => {
    if (!hasSeveralSteps.value) {
        return;
    }

    if (event.key === forwardKey()) {
        event.preventDefault();
        next();

        return;
    }

    if (event.key === backwardKey()) {
        event.preventDefault();
        back();
    }
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
            <PopoverContent
                :align="align"
                :side-offset="12"
                class="z-[60] w-80"
                :aria-labelledby="titleId"
                :aria-describedby="descriptionId"
                :data-testid="`${testId}-content`"
                @pointer-down-outside="followControl"
                @keydown="followArrowKey"
            >
                <div v-if="filled(tag)" class="mb-2 flex items-center gap-2">
                    <span
                        class="inline-flex h-6 items-center rounded-full px-2.5 text-xs font-medium text-foreground"
                        :style="tagStyle"
                        :data-testid="`${testId}-tag`"
                    >
                        {{ tag }}
                    </span>
                    <span
                        v-if="progressBesideTag"
                        class="ms-auto text-xs text-muted-foreground"
                        :data-testid="`${testId}-progress`"
                    >
                        {{ progressLabel($t) }}
                    </span>
                </div>
                <div class="flex items-baseline gap-2">
                    <p :id="titleId" class="text-base font-semibold">
                        {{ $t(titleKey) }}
                    </p>
                    <span
                        v-if="progressBesideTitle"
                        class="ms-auto shrink-0 text-xs text-muted-foreground"
                        :data-testid="`${testId}-progress`"
                    >
                        {{ progressLabel($t) }}
                    </span>
                </div>
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
                        <IconChevronsLeft class="size-4 rtl:-scale-x-100" />
                        {{ $t('tours.actions.back') }}
                    </Button>
                    <Button
                        size="sm"
                        :class="nextButtonClass"
                        :data-testid="`${testId}-next`"
                        @click="next"
                    >
                        {{ $t(nextLabelKey) }}
                        <IconChevronsRight
                            v-if="!isLast"
                            class="size-4 rtl:-scale-x-100"
                        />
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
