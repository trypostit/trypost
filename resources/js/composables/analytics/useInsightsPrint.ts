import { inject, provide, type InjectionKey } from 'vue';

const PRINTING: InjectionKey<boolean> = Symbol('insights-print');

/**
 * The PDF prints the Insights components themselves. The print page says so
 * once, and each component hides what only works on screen (menus, mode
 * toggles, share buttons, pagination) and shows the rest exactly as the page.
 */
export const provideInsightsPrint = (): void => {
    provide(PRINTING, true);
};

export const useInsightsPrint = (): boolean => inject(PRINTING, false);
