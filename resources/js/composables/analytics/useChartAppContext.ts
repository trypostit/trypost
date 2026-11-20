import { type AppContext, getCurrentInstance } from 'vue';

/**
 * The app's context for a chart's tooltip, which unovis renders outside the
 * component tree: without it, `$t` and the app's components are missing in
 * there. Read in `setup`, the only place the current instance exists.
 */
export const useChartAppContext = (): AppContext | null =>
    getCurrentInstance()?.appContext ?? null;
