import { createContext } from 'reka-ui';
import type { Ref } from 'vue';

import type { VerifiedBadge } from '@/types/social-account';

export {
    VisCrosshair as ChartCrosshair,
    VisTooltip as ChartTooltip,
} from '@unovis/vue';
export { default as ChartContainer } from './ChartContainer.vue';
export { default as ChartTooltipContent } from './ChartTooltipContent.vue';
export { componentToString } from './utils';

export type ChartConfig = Record<
    string,
    {
        label: string;
        color: string;
        avatar?: {
            platform: string;
            name: string;
            src: string | null;
            verified?: VerifiedBadge | null;
        };
    }
>;

/** One labelled number under an entry of the tooltip; a tone colors a gain or a loss. */
export type ChartTooltipDetail = {
    label: string;
    value: string;
    tone?: 'up' | 'down';
};

interface ChartContext {
    id: string;
    config: Ref<ChartConfig>;
}

export const [useChart, provideChartContext] =
    createContext<ChartContext>('Chart');
