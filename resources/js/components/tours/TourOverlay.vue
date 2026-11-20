<script setup lang="ts">
import { IconChevronsLeft, IconChevronsRight } from '@tabler/icons-vue';
import { computed, useId, useTemplateRef, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { useOpenTourStep } from '@/composables/tours/useTour';
import { filled } from '@/lib/helpers';
import { labelTextColor } from '@/lib/labelColor';

/**
 * The dark backdrop and the card of the open tour step, mounted once in the
 * app layout. The backdrop is cut out around the step's control, and the card
 * is anchored to that cut-out and follows it every frame, so moving to the
 * next step slides both to the next control together. Using the control
 * itself moves the tour on; a click elsewhere on the page does nothing, and
 * Escape skips it. In a longer
 * tour the arrow keys move between the steps, without ever ending it.
 */
const openStep = useOpenTourStep();
const backdrop = useTemplateRef<HTMLElement>('backdrop');

const titleId = useId();
const descriptionId = useId();

const isCardOpen = computed((): boolean => {
    if (!filled(openStep.value)) {
        return false;
    }

    return filled(backdrop.value);
});

/** The cut-out of the dark backdrop, over the open step's control. */
const spotlightStyle = computed(() => {
    if (!filled(openStep.value)) {
        return undefined;
    }

    const { frame } = openStep.value;

    return {
        top: `${frame.top}px`,
        left: `${frame.left}px`,
        width: `${frame.width}px`,
        height: `${frame.height}px`,
    };
});

/** The tag's color as the background, with the text color that reads on it, as `LabelBadge` does. */
const tagStyle = computed(() => {
    if (!filled(openStep.value)) {
        return undefined;
    }

    return {
        backgroundColor: openStep.value.tagColor,
        color: labelTextColor(openStep.value.tagColor),
    };
});

const copyKey = computed((): string => {
    if (!filled(openStep.value)) {
        return '';
    }

    return `tours.${openStep.value.tour}.steps.${openStep.value.step}`;
});
const titleKey = computed(() => `${copyKey.value}.title`);
const descriptionKey = computed(() => `${copyKey.value}.description`);

/** A test id without dots, which a CSS selector would read as classes. */
const testId = computed((): string => {
    if (!filled(openStep.value)) {
        return 'tour';
    }

    return `tour-${openStep.value.tour.replaceAll('.', '-')}-${openStep.value.step}`;
});

/** Where the user is in the tour, from 1, and how many steps it shows; 0 while none is open. */
const position = computed((): number => {
    if (!filled(openStep.value)) {
        return 0;
    }

    return openStep.value.position;
});

const total = computed((): number => {
    if (!filled(openStep.value)) {
        return 0;
    }

    return openStep.value.total;
});

/** A tour of one step only says "Got it"; a longer one shows where the user is, Skip, Back and Next. */
const hasSeveralSteps = computed(() => total.value > 1);
const isFirst = computed(() => position.value === 1);
const isLast = computed(() => position.value === total.value);

/** In a longer tour, where the user is ("2 of 3") sits next to the tag, above the title. */
const progressBesideTag = computed((): boolean => {
    if (!filled(openStep.value?.tag)) {
        return false;
    }

    return hasSeveralSteps.value;
});

/** Without a tag, it sits on the title's line instead, so the title stays at the top. */
const progressBesideTitle = computed((): boolean => {
    if (filled(openStep.value?.tag)) {
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

const next = (): void => {
    openStep.value?.next();
};

const back = (): void => {
    openStep.value?.back();
};

const skip = (): void => {
    openStep.value?.skip();
};

type PointerDownOutside = CustomEvent<{ originalEvent: PointerEvent }>;

/**
 * A press outside the card never closes the tour: only its buttons and
 * Escape do. A press on the control the step points at moves the tour on
 * once the click lands on the control; moving on at once would take the
 * control's pointer events away before the click.
 */
const followControl = (event: PointerDownOutside): void => {
    event.preventDefault();

    const clicked = event.detail.originalEvent.target;

    if (!(clicked instanceof Node)) {
        return;
    }

    if (!filled(openStep.value)) {
        return;
    }

    if (!openStep.value.control.contains(clicked)) {
        return;
    }

    pendingControl = openStep.value.control;
    pendingControl.addEventListener('click', next, { once: true });
};

/**
 * The control waiting for the click a press on it started. A press that ends
 * off the control never clicks, so its listener goes when the step changes
 * (another control), never when the same step only moves on screen.
 */
let pendingControl: HTMLElement | null = null;

/** The open step's control: the same while the step only moves on screen. */
const openControl = (): HTMLElement | null => {
    if (!filled(openStep.value)) {
        return null;
    }

    return openStep.value.control;
};

watch(openControl, () => {
    if (!filled(pendingControl)) {
        return;
    }

    pendingControl.removeEventListener('click', next);
    pendingControl = null;
});

/** Focus leaving the card does not close the tour either. */
const keepTourOpen = (event: Event): void => {
    event.preventDefault();
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

/**
 * In a longer tour, the arrow keys move between the steps like Next and
 * Back. They never end the tour: on the last step the forward arrow does
 * nothing, and only Done closes it.
 */
const followArrowKey = (event: KeyboardEvent): void => {
    if (!hasSeveralSteps.value) {
        return;
    }

    if (event.key === forwardKey()) {
        event.preventDefault();

        if (isLast.value) {
            return;
        }

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
    <div class="contents">
        <Teleport to="body">
            <Transition
                enter-active-class="transition-opacity duration-200 ease-out"
                enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-150 ease-in"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="filled(openStep)"
                    ref="backdrop"
                    class="pointer-events-none fixed z-50 rounded-xl shadow-[0_0_0_9999px_rgb(0_0_0/0.5)] transition-[top,left,width,height] duration-300 ease-in-out motion-reduce:transition-none dark:shadow-[0_0_0_9999px_rgb(0_0_0/0.6)]"
                    :style="spotlightStyle"
                    data-testid="tour-backdrop"
                />
            </Transition>
        </Teleport>
        <Popover :open="isCardOpen" modal @update:open="closeTour">
            <PopoverAnchor v-if="filled(backdrop)" :reference="backdrop" />
            <PopoverContent
                v-if="filled(openStep)"
                :align="openStep.align"
                :side-offset="12"
                update-position-strategy="always"
                class="z-[60] w-80"
                :aria-labelledby="titleId"
                :aria-describedby="descriptionId"
                :data-testid="`${testId}-content`"
                @pointer-down-outside="followControl"
                @focus-outside="keepTourOpen"
                @keydown="followArrowKey"
            >
                <div
                    v-if="filled(openStep.tag)"
                    class="mb-2 flex items-center gap-2"
                >
                    <span
                        class="inline-flex h-6 items-center rounded-full px-2.5 text-xs font-medium text-foreground"
                        :style="tagStyle"
                        :data-testid="`${testId}-tag`"
                    >
                        {{ openStep.tag }}
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
