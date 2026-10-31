import { inject, provide, type InjectionKey } from 'vue';

const PRINTING: InjectionKey<boolean> = Symbol('insights-print');

/**
 * The PDF prints the Insights components themselves. What only works on screen
 * is hidden with `print:` classes; this flag is for what CSS cannot stop: a
 * post's video, which would download even when hidden, prints its network
 * preview instead.
 */
export const provideInsightsPrint = (): void => {
    provide(PRINTING, true);
};

export const useInsightsPrint = (): boolean => inject(PRINTING, false);
