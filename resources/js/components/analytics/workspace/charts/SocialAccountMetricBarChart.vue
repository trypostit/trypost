<script setup lang="ts">
import {
    VisAxis,
    VisAxisSelectors,
    VisStackedBar,
    VisStackedBarSelectors,
    VisXYContainer,
} from '@unovis/vue';
import { computed, getCurrentInstance, h, onBeforeUnmount, render } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { accountColor } from '@/lib/analyticsColors';
import type { AccountIdentityData } from '@/types/analytics';

import {
    formatCountTick,
    socialAccountChartConfig,
} from './socialAccountChart';

const props = defineProps<{
    rows: {
        account: AccountIdentityData;
        value: number | null;
        gained?: number | null;
    }[];
    colors: Record<string, string>;
    valueLabel?: string;
    detail?: (index: number) => string | null;
}>();

type BarPoint = { index: number; value: number; base: number; gained: number };
const split = computed(() =>
    props.rows.some((row) => row.gained !== undefined),
);
const chartData = computed<BarPoint[]>(() =>
    props.rows.map((row, index) => {
        const value = row.value ?? 0;
        const gained = Math.min(Math.max(row.gained ?? 0, 0), value);

        return { index, value, base: value - gained, gained };
    }),
);
const chartConfig = computed(() =>
    socialAccountChartConfig(
        props.rows.map((row) => row.account),
        props.colors,
    ),
);
const chartHeight = computed(
    () => `${Math.max(160, props.rows.length * 37 + 60)}px`,
);
const indexAccessor = (point: BarPoint): number => point.index;
const valueAccessor = (point: BarPoint): number => point.value;
const splitAccessors = [
    (point: BarPoint): number => point.base,
    (point: BarPoint): number => point.gained,
];
const rowColor = (point: BarPoint): string => {
    const key = props.rows[point.index]?.account.social_account_key;
    return (key && props.colors[key]) || accountColor(point.index);
};
const colorAccessor = (point: BarPoint, stack: number): string =>
    split.value && stack === 0 && point.gained > 0
        ? `color-mix(in srgb, ${rowColor(point)} 40%, var(--card))`
        : rowColor(point);
const formatAccount = (tick: number | Date): string => {
    const index = typeof tick === 'number' ? Math.round(tick) : 0;
    const account = props.rows[index]?.account;
    if (!account) return '';

    const label = account.username
        ? `@${account.username}`
        : account.name || getPlatformLabel(account.platform);
    return label.length > 12 ? `${label.slice(0, 11)}…` : label;
};
const categoryTicks = computed(() => props.rows.map((_, index) => index));
const detailFormatter = (key: string): string | null =>
    props.detail?.(Number(key.replace('account_', ''))) ?? null;
const tooltipTemplate = computed(() =>
    componentToString(chartConfig.value, ChartTooltipContent, {
        detailFormatter,
    }),
);
const tooltipTriggers = computed(() => ({
    [VisStackedBarSelectors.bar]: (bar: {
        datum: BarPoint;
    }): string | undefined => {
        const point = bar.datum;
        return tooltipTemplate.value?.({
            [`account_${point.index}`]: props.rows[point.index]?.value,
        });
    },
}));
const tickAttributes = {
    [VisAxisSelectors.tick]: {
        'data-account-tick': (tick: number): string => String(Math.round(tick)),
    },
};
const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
const AVATAR_SIZE = 24;
const AVATAR_OVERHANG = 4;
const AVATAR_GAP = 8;
const appContext = getCurrentInstance()?.appContext ?? null;
const avatarHosts: HTMLElement[] = [];

const accountLabel = (account: AccountIdentityData): string =>
    account.username
        ? `@${account.username}`
        : account.name || getPlatformLabel(account.platform);

const unmountAvatars = (): void => {
    avatarHosts.splice(0).forEach((host) => render(null, host));
};

const buildAxisChannel = (account: AccountIdentityData): SVGElement => {
    const box = AVATAR_SIZE + AVATAR_OVERHANG * 2;
    const frame = document.createElementNS(SVG_NAMESPACE, 'foreignObject');
    frame.setAttribute('x', String(-AVATAR_SIZE - AVATAR_GAP - AVATAR_OVERHANG));
    frame.setAttribute('y', String(-AVATAR_SIZE / 2 - AVATAR_OVERHANG));
    frame.setAttribute('width', String(box));
    frame.setAttribute('height', String(box));
    frame.setAttribute('data-account-logo', '');
    frame.setAttribute('data-testid', 'analytics-axis-channel');
    frame.setAttribute('data-platform', account.platform);
    frame.style.overflow = 'visible';

    const host = document.createElement('div');
    host.style.padding = `${AVATAR_OVERHANG}px`;
    host.style.lineHeight = '0';

    const avatar = h(ChannelAvatar, {
        platform: account.platform,
        src: account.avatar_url,
        name: accountLabel(account),
        size: AVATAR_SIZE,
        ring: 'card',
        status: account.status ?? null,
        accountId: account.social_account_key,
        reserveSpace: false,
    });
    avatar.appContext = appContext;
    render(avatar, host);
    avatarHosts.push(host);
    frame.appendChild(host);

    return frame;
};

const decorateTicks = (svg: SVGSVGElement): void => {
    unmountAvatars();
    svg.querySelectorAll('[data-account-logo]').forEach((logo) =>
        logo.remove(),
    );
    svg.querySelectorAll<SVGGElement>('g[data-account-tick]').forEach(
        (tick) => {
            const index = Number(tick.getAttribute('data-account-tick'));
            const account = props.rows[index]?.account;

            if (!account) {
                return;
            }

            tick.appendChild(buildAxisChannel(account));
        },
    );
};
onBeforeUnmount(unmountAvatars);

const barAttributes = {
    [VisStackedBarSelectors.bar]: { 'data-testid': 'analytics-account-bar' },
};
</script>

<template>
    <ChartContainer
        :config="chartConfig"
        class="w-full"
        :style="{ height: chartHeight }"
        data-testid="accounts-unovis-bar-chart"
    >
        <VisXYContainer
            :data="chartData"
            y-direction="south"
            :padding="{ top: 12, right: 16, bottom: 0, left: 0 }"
            :on-render-complete="decorateTicks"
        >
            <VisStackedBar
                :x="indexAccessor"
                :y="split ? splitAccessors : valueAccessor"
                :color="colorAccessor"
                orientation="horizontal"
                :rounded-corners="2"
                :bar-padding="0.27"
                :bar-max-width="27"
                :attributes="barAttributes"
            />
            <VisAxis
                type="x"
                :tick-format="formatCountTick"
                :num-ticks="5"
                :label="valueLabel"
                label-font-size="12px"
                label-color="var(--muted-foreground)"
                :grid-line="true"
                :domain-line="false"
                :tick-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="formatAccount"
                :tick-values="categoryTicks"
                :tick-padding="AVATAR_SIZE + AVATAR_GAP + 6"
                :attributes="tickAttributes"
                :grid-line="false"
                :domain-line="false"
                :tick-line="false"
            />
            <ChartTooltip :triggers="tooltipTriggers" />
        </VisXYContainer>
    </ChartContainer>
</template>
