import { useMediaQuery } from '@vueuse/core';

export const useCanHover = () => useMediaQuery('(hover: hover)');
