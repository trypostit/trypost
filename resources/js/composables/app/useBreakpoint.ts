import { breakpointsTailwind, useBreakpoints } from '@vueuse/core';

type Breakpoint = keyof typeof breakpointsTailwind;

export const useBelowBreakpoint = (breakpoint: Breakpoint) =>
    useBreakpoints(breakpointsTailwind).smaller(breakpoint);

export const useAtLeastBreakpoint = (breakpoint: Breakpoint) =>
    useBreakpoints(breakpointsTailwind).greaterOrEqual(breakpoint);
