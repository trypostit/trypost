<script setup lang="ts">
import {
    VisAxis,
    VisAxisSelectors,
    VisStackedBar,
    VisStackedBarSelectors,
    VisXYContainer,
} from '@unovis/vue';
import {
    computed,
    getCurrentInstance,
    h,
    onBeforeUnmount,
    render,
    useTemplateRef,
} from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartTooltipDetail,
    componentToString,
} from '@/components/ui/chart';
import { accountColor } from '@/lib/analyticsColors';
import { type AccountIdentityData, accountLabel } from '@/types/analytics';

import {
    formatCountTick,
    socialAccountChartConfig,
} from './socialAccountChart';

const props = defineProps<{
    rows: {
        account: AccountIdentityData;
        value: number | null;
    }[];
    colors: Record<string, string>;
    detail?: (index: number) => ChartTooltipDetail[];
}>();

type BarPoint = { index: number; value: number };
const chartData = computed<BarPoint[]>(() =>
    props.rows.map((row, index) => ({ index, value: row.value ?? 0 })),
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
const rowColor = (point: BarPoint): string => {
    const key = props.rows[point.index]?.account.social_account_key;
    return (key && props.colors[key]) || accountColor(point.index);
};
const accountAt = (index: number): AccountIdentityData | undefined =>
    props.rows[Math.round(index)]?.account;
const formatAccount = (tick: number | Date): string => {
    if (typeof tick !== 'number') {
        return '';
    }

    const account = accountAt(tick);

    if (!account) {
        return '';
    }

    return accountLabel(account);
};
const categoryTicks = computed(() => props.rows.map((_, index) => index));
const detailFormatter = (key: string): ChartTooltipDetail[] =>
    props.detail?.(Number(key.replace('account_', ''))) ?? [];
const tooltipTemplate = computed(() =>
    componentToString(
        chartConfig.value,
        ChartTooltipContent,
        { detailFormatter },
        appContext,
    ),
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
const AVATAR_SIZE = 24;
/** The avatar host's `p-1`, room for the status ring to overhang the avatar. */
const AVATAR_OVERHANG = 4;
const AVATAR_GAP = 8;
/** Between the avatar and the channel's name. */
const AXIS_LABEL_GAP = 6;
const ACCOUNT_TICK_PADDING = AVATAR_SIZE + AVATAR_GAP + AXIS_LABEL_GAP;
/** The widest a channel's name gets next to its bar before the axis trims it with an ellipsis. */
const ACCOUNT_LABEL_WIDTH = 88;
const appContext = getCurrentInstance()?.appContext ?? null;
const avatarHosts: HTMLElement[] = [];
const avatarLayer = useTemplateRef<HTMLDivElement>('avatarLayer');

const unmountAvatars = (): void => {
    avatarHosts.splice(0).forEach((host) => {
        render(null, host);
        host.remove();
    });
};

/**
 * The channel's avatar next to its axis label, as HTML laid over the chart at
 * the tick's position. Not a `<foreignObject>` inside the SVG: WebKit (every
 * browser on iOS) ignores the tick's transform there and draws every avatar at
 * the chart's corner.
 */
const placeAxisChannel = (
    layer: HTMLDivElement,
    svgOffset: { x: number; y: number },
    tick: SVGGElement,
    account: AccountIdentityData,
): void => {
    const origin = tick.getCTM();

    if (!origin) {
        return;
    }

    const host = document.createElement('div');

    host.className = 'absolute p-1 leading-none';
    host.dataset.testid = 'analytics-axis-channel';
    host.dataset.platform = account.platform;
    host.style.left = `${svgOffset.x + origin.e - AVATAR_SIZE - AVATAR_GAP - AVATAR_OVERHANG}px`;
    host.style.top = `${svgOffset.y + origin.f - AVATAR_SIZE / 2 - AVATAR_OVERHANG}px`;

    const avatar = h(ChannelAvatar, {
        platform: account.platform,
        src: account.avatar_url,
        name: accountLabel(account),
        size: AVATAR_SIZE,
        ring: 'card',
        status: account.status ?? null,
        accountId: account.social_account_key,
        verified: account.verified_badge ?? null,
        reserveSpace: false,
    });
    avatar.appContext = appContext;
    render(avatar, host);
    avatarHosts.push(host);
    layer.appendChild(host);
};

const decorateTicks = (svg: SVGSVGElement): void => {
    unmountAvatars();

    const layer = avatarLayer.value;

    if (!layer) {
        return;
    }

    const svgBox = svg.getBoundingClientRect();
    const layerBox = layer.getBoundingClientRect();
    const svgOffset = {
        x: svgBox.left - layerBox.left,
        y: svgBox.top - layerBox.top,
    };

    svg.querySelectorAll<SVGGElement>('g[data-account-tick]').forEach(
        (tick) => {
            const account = accountAt(
                Number(tick.getAttribute('data-account-tick')),
            );

            if (account) {
                placeAxisChannel(layer, svgOffset, tick, account);
            }
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
        class="relative w-full"
        :style="{ height: chartHeight }"
        data-testid="accounts-unovis-bar-chart"
    >
        <div
            ref="avatarLayer"
            class="pointer-events-none absolute inset-0 z-10"
            aria-hidden="true"
        />
        <VisXYContainer
            :data="chartData"
            y-direction="south"
            :padding="{ top: 12, right: 16, bottom: 0, left: 0 }"
            :on-render-complete="decorateTicks"
        >
            <VisStackedBar
                :x="indexAccessor"
                :y="valueAccessor"
                :color="rowColor"
                orientation="horizontal"
                :rounded-corners="4"
                :bar-padding="0.4"
                :bar-max-width="20"
                :attributes="barAttributes"
            />
            <VisAxis
                type="x"
                :tick-format="formatCountTick"
                :num-ticks="5"
                :grid-line="true"
                :domain-line="false"
                :tick-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="formatAccount"
                :tick-text-width="ACCOUNT_LABEL_WIDTH"
                tick-text-fit-mode="trim"
                tick-text-trim-type="end"
                :tick-values="categoryTicks"
                :tick-padding="ACCOUNT_TICK_PADDING"
                :duration="0"
                :attributes="tickAttributes"
                :grid-line="false"
                :domain-line="false"
                :tick-line="false"
            />
            <ChartTooltip :triggers="tooltipTriggers" />
        </VisXYContainer>
    </ChartContainer>
</template>
