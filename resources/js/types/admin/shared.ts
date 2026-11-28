import type { BadgeVariants } from '@/components/ui/badge';

/** A `Badge` variant, as the admin maps a state to one. */
export type BadgeVariant = NonNullable<BadgeVariants['variant']>;

/** A figure's change from a past value, as the admin's tiles show it. */
export interface AdminChange {
    /** The change in percent; its sign picks the arrow. */
    value: number;
    label: string;
    inverted?: boolean;
}

/** One tile of an `AdminStatGrid`: a muted label over a large figure. */
export interface AdminStat {
    key: string;
    label: string;
    value: string;
    hint?: string;
    change?: AdminChange | null;
    /** The figure is still on its way (a deferred prop): the tile pulses in its place. */
    loading?: boolean;
}

/** One figure of an `AdminMetricTabs` row, picking the chart under it. */
export interface AdminMetricTab<Value extends string = string> {
    value: Value;
    label: string;
    figure: string;
    hint?: string;
    change?: AdminChange | null;
}

/** One label / value row of an `AdminDetailList`. */
export interface AdminDetailRow {
    key: string;
    label: string;
    value?: string;
}

/** One column of an `AdminSortableTable`. */
export interface AdminTableColumn {
    key: string;
    label: string;
    numeric?: boolean;
}

export interface ScrollPage<T> {
    data: T[];
    meta: { hasNextPage: boolean };
}

export interface AdminBarRow {
    key: string;
    label: string;
    count: number;
    /** The row's share of the whole, in percent; a row without it shows no share. */
    share?: number;
    /** A network's row shows its logo. */
    platform?: string;
    /** A short note shown before the count. */
    hint?: string;
    /** The row links to this page. */
    href?: string;
    /** The bar's length in percent, when it is not measured against the largest count (a rate, a share). */
    bar?: number;
    /** The bar's color class, when it is not the brand color. */
    barClass?: string;
}
