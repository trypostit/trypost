import { h, render, type Component } from 'vue';

import { activeLocale } from '@/language';

import type { ChartConfig } from '.';

export const componentToString = (
    config: ChartConfig,
    component: Component,
    props: Record<string, unknown> = {},
): ((data: Record<string, unknown>, x?: number | Date) => string) | undefined => {
    if (typeof document === 'undefined') return undefined;

    const cache = new Map<string, string>();

    return (source, x) => {
        const data =
            'data' in source && source.data && typeof source.data === 'object'
                ? (source.data as Record<string, unknown>)
                : source;
        const key = `${activeLocale.value}-${x}-${JSON.stringify(data)}`;
        const cached = cache.get(key);
        if (cached) return cached;

        const element = document.createElement('div');
        render(h(component, { ...props, payload: data, config, x }), element);
        const content = element.innerHTML;
        render(null, element);
        cache.set(key, content);
        return content;
    };
};
