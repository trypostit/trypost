<script setup lang="ts">
import { computed } from 'vue';

import { useTourSpotlight } from '@/composables/tours/useTour';
import { filled } from '@/lib/helpers';

/**
 * The dark backdrop of the open tour, cut out around the control its step
 * frames. There is one for every tour, mounted in the app layout, so moving
 * to the next step slides the cut-out from one control to the other instead
 * of closing one backdrop and opening another.
 */
const spotlight = useTourSpotlight();

const spotlightStyle = computed(() => {
    if (!filled(spotlight.value)) {
        return undefined;
    }

    return {
        top: `${spotlight.value.top}px`,
        left: `${spotlight.value.left}px`,
        width: `${spotlight.value.width}px`,
        height: `${spotlight.value.height}px`,
    };
});
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
                    v-if="filled(spotlight)"
                    class="pointer-events-none fixed z-50 rounded-xl shadow-[0_0_0_9999px_rgb(0_0_0/0.5)] transition-[top,left,width,height] duration-300 ease-in-out motion-reduce:transition-none dark:shadow-[0_0_0_9999px_rgb(0_0_0/0.6)]"
                    :style="spotlightStyle"
                    data-testid="tour-backdrop"
                />
            </Transition>
        </Teleport>
    </div>
</template>
