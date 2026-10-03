import { createContext } from 'reka-ui';
import type { Ref } from 'vue';

export {
    VisCrosshair as ChartCrosshair,
    VisTooltip as ChartTooltip,
} from '@unovis/vue';
export { default as ChartContainer } from './ChartContainer.vue';
export { default as ChartTooltipContent } from './ChartTooltipContent.vue';
export { componentToString } from './utils';

export type ChartConfig = Record<
    string,
    { label: string; color: string; icon?: string }
>;

interface ChartContext {
    id: string;
    config: Ref<ChartConfig>;
}

export const [useChart, provideChartContext] =
    createContext<ChartContext>('Chart');
