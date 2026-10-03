<script setup lang="ts">
import {
    VisAxis,
    VisAxisSelectors,
    VisStackedBar,
    VisStackedBarSelectors,
    VisXYContainer,
} from '@unovis/vue';
import { computed, getCurrentInstance } from 'vue';

import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import { accountColor } from '@/lib/analyticsColors';
import type { AccountIdentityData } from '@/types/analytics';
import { isConnectionLost } from '@/types/social-account';

import {
    formatCountTick,
    socialAccountChartConfig,
} from './socialAccountChart';

const props = defineProps<{
    rows: { account: AccountIdentityData; value: number | null }[];
    colors: Record<string, string>;
    valueLabel?: string;
    detail?: (index: number) => string | null;
}>();

type BarPoint = { index: number; value: number };
const chartData = computed<BarPoint[]>(() =>
    props.rows.map((row, index) => ({
        index,
        value: row.value ?? 0,
    })),
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
const colorAccessor = (point: BarPoint): string => {
    const key = props.rows[point.index]?.account.social_account_key;
    return (key && props.colors[key]) || accountColor(point.index);
};
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
const AVATAR_SIZE = 20;
const BADGE_SIZE = 12;
const AVATAR_GAP = 6;
const chartUid = getCurrentInstance()?.uid ?? 0;

const svgElement = (
    name: string,
    attributes: Record<string, string>,
): SVGElement => {
    const element = document.createElementNS(SVG_NAMESPACE, name);
    Object.entries(attributes).forEach(([key, value]) =>
        element.setAttribute(key, value),
    );

    return element;
};

const accountLabel = (account: AccountIdentityData): string =>
    account.username
        ? `@${account.username}`
        : account.name || getPlatformLabel(account.platform);

const buildAxisChannel = (
    account: AccountIdentityData,
    index: number,
): SVGGElement => {
    const radius = AVATAR_SIZE / 2;
    const left = -AVATAR_SIZE - AVATAR_GAP;
    const centerX = left + radius;
    const clipId = `axis-avatar-clip-${chartUid}-${index}`;
    const group = svgElement('g', {
        'data-account-logo': '',
        'data-testid': 'analytics-axis-channel',
    }) as SVGGElement;

    const clip = svgElement('clipPath', { id: clipId });
    clip.appendChild(
        svgElement('circle', { cx: String(centerX), cy: '0', r: String(radius) }),
    );
    group.appendChild(clip);

    const fallback = svgElement('g', {
        'data-testid': 'analytics-axis-avatar-fallback',
    });
    const fallbackCircle = svgElement('circle', {
        cx: String(centerX),
        cy: '0',
        r: String(radius),
    });
    fallbackCircle.style.fill = 'var(--secondary)';
    const initial = svgElement('text', {
        x: String(centerX),
        y: '0',
        'text-anchor': 'middle',
        'dominant-baseline': 'central',
        'font-size': '9',
        'font-weight': '700',
    });
    initial.style.fill = 'var(--foreground)';
    initial.textContent = (
        account.name ||
        account.username ||
        getPlatformLabel(account.platform)
    )
        .charAt(0)
        .toUpperCase();
    fallback.append(fallbackCircle, initial);
    group.appendChild(fallback);

    if (account.avatar_url) {
        const photo = svgElement('image', {
            href: account.avatar_url,
            x: String(left),
            y: String(-radius),
            width: String(AVATAR_SIZE),
            height: String(AVATAR_SIZE),
            'clip-path': `url(#${clipId})`,
            preserveAspectRatio: 'xMidYMid slice',
            'data-testid': 'analytics-axis-avatar',
            'aria-label': accountLabel(account),
        });
        photo.addEventListener('error', () => photo.remove());
        group.appendChild(photo);
    }

    const badgeCenterX = left + AVATAR_SIZE - BADGE_SIZE / 2 + 3;
    const badgeCenterY = radius - BADGE_SIZE / 2 + 2;
    const badgeRing = svgElement('circle', {
        cx: String(badgeCenterX),
        cy: String(badgeCenterY),
        r: String(BADGE_SIZE / 2 + 1),
    });
    badgeRing.style.fill = 'var(--card)';
    group.appendChild(badgeRing);
    group.appendChild(
        svgElement('image', {
            href: getPlatformLogo(account.platform),
            x: String(badgeCenterX - BADGE_SIZE / 2),
            y: String(badgeCenterY - BADGE_SIZE / 2),
            width: String(BADGE_SIZE),
            height: String(BADGE_SIZE),
            'data-testid': 'analytics-axis-logo',
            'aria-label': getPlatformLabel(account.platform),
        }),
    );

    if (isConnectionLost({ status: account.status ?? null })) {
        const dot = svgElement('circle', {
            cx: String(left + 2),
            cy: String(-radius + 2),
            r: '4',
            'data-testid': 'analytics-axis-disconnected',
        });
        dot.style.fill = 'var(--destructive)';
        dot.style.stroke = 'var(--card)';
        dot.style.strokeWidth = '1.5';
        group.appendChild(dot);
    }

    return group as SVGGElement;
};

const decorateTicks = (svg: SVGSVGElement): void => {
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

            tick.appendChild(buildAxisChannel(account, index));
        },
    );
};
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
                :y="valueAccessor"
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
