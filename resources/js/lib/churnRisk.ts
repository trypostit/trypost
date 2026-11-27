import {
    IconAlertTriangle,
    IconClockPause,
    IconCreditCardOff,
    IconDoorExit,
    IconPencilOff,
    IconPlugConnectedX,
    IconPlugX,
    IconTrendingDown,
    IconZzz,
} from '@tabler/icons-vue';
import type { Component } from 'vue';

import { filled } from '@/lib/helpers';
import type { AdminRiskLevel } from '@/types/admin';

export interface ChurnSignal {
    label: string;
    icon: Component;
}

/** Each churn signal: how it reads, and its icon. The color is the risk level's, never the signal's. */
const CHURN_SIGNALS: Record<string, ChurnSignal> = {
    cancelling: { label: 'Cancelling', icon: IconDoorExit },
    past_due: { label: 'Payment past due', icon: IconCreditCardOff },
    dormant: { label: 'No post in 21+ days', icon: IconZzz },
    at_risk: { label: 'No post in 7+ days', icon: IconClockPause },
    never_posted: { label: 'Never posted', icon: IconPencilOff },
    no_channels: { label: 'No channel', icon: IconPlugX },
    channels_broken: { label: 'Channels need reconnecting', icon: IconPlugConnectedX },
    posting_dropped: { label: 'Posting dropped by half', icon: IconTrendingDown },
    failing: { label: 'Posts failing', icon: IconAlertTriangle },
};

const RISK_LEVELS: Record<AdminRiskLevel, { label: string; dot: string }> = {
    high: { label: 'High', dot: 'bg-destructive' },
    medium: { label: 'Medium', dot: 'bg-warning' },
    low: { label: 'Low', dot: 'bg-muted-foreground' },
};

/** A signal the backoffice does not know yet still shows, under its own key. */
export const churnSignal = (key: string): ChurnSignal => {
    const known = CHURN_SIGNALS[key];

    if (filled(known)) {
        return known;
    }

    return { label: key, icon: IconAlertTriangle };
};

export const riskLevel = (level: AdminRiskLevel): { label: string; dot: string } => RISK_LEVELS[level];
