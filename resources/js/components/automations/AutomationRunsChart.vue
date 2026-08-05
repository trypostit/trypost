<script setup lang="ts">
import { CurveType } from '@unovis/ts';
import { VisArea, VisAxis, VisCrosshair, VisLine, VisTooltip, VisXYContainer } from '@unovis/vue';

import date from '@/date';

type Point = { date: string; started: number; completed: number; failed: number };

const props = defineProps<{ data: Point[] }>();

const seriesColors = {
    started: '#6366f1',
    completed: '#22c55e',
    failed: '#ef4444',
};

const x = (_d: Point, i: number) => i;
const yStarted = (d: Point) => d.started;
const yCompleted = (d: Point) => d.completed;
const yFailed = (d: Point) => d.failed;

const xTickFormat = (value: number): string => {
    const point = props.data[Math.round(value)];
    return point ? date.formatMonthDay(point.date) : '';
};

const tooltipTemplate = (d: Point): string =>
    `<div style="font-size:12px;line-height:1.5">
        <div style="font-weight:600;margin-bottom:2px">${date.formatMonthDayYear(d.date)}</div>
        <div style="color:${seriesColors.started}">● started: ${d.started}</div>
        <div style="color:${seriesColors.completed}">● completed: ${d.completed}</div>
        <div style="color:${seriesColors.failed}">● failed: ${d.failed}</div>
    </div>`;
</script>

<template>
    <VisXYContainer :data="data" :height="260" :margin="{ top: 12, right: 8, bottom: 4, left: 8 }">
        <VisArea :x="x" :y="yStarted" :color="seriesColors.started" :opacity="0.1" :curve-type="CurveType.MonotoneX" />
        <VisLine :x="x" :y="yStarted" :color="seriesColors.started" :line-width="2.5" :curve-type="CurveType.MonotoneX" />
        <VisLine :x="x" :y="yCompleted" :color="seriesColors.completed" :line-width="2.5" :curve-type="CurveType.MonotoneX" />
        <VisLine :x="x" :y="yFailed" :color="seriesColors.failed" :line-width="2.5" :curve-type="CurveType.MonotoneX" />
        <VisAxis
            type="x"
            :tick-format="xTickFormat"
            :num-ticks="6"
            :grid-line="false"
            :domain-line="false"
            :tick-line="false"
            color="var(--color-foreground)"
        />
        <VisAxis
            type="y"
            :num-ticks="3"
            :grid-line="false"
            :domain-line="false"
            :tick-line="false"
            color="var(--color-foreground)"
        />
        <VisCrosshair :template="tooltipTemplate" :color="seriesColors.started" />
        <VisTooltip />
    </VisXYContainer>
</template>

<style scoped>
:deep(.unovis-xy-container) {
    --vis-axis-tick-label-color: color-mix(in oklab, var(--color-foreground) 45%, transparent);
    --vis-axis-tick-label-font-size: 11px;
    --vis-crosshair-line-stroke-color: color-mix(in oklab, var(--color-foreground) 20%, transparent);
}
</style>
