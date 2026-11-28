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

import type { ChurnSignal, RiskLevel } from '@/types/admin';

/** How a churn signal shows: its words and its icon. */
interface ChurnSignalDisplay {
    label: string;
    icon: Component;
}

/** Each churn signal: how it reads, and its icon. The color is the risk level's, never the signal's. */
const CHURN_SIGNALS: Record<ChurnSignal, ChurnSignalDisplay> = {
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

const RISK_LEVELS: Record<RiskLevel, { label: string; dot: string }> = {
    high: { label: 'High', dot: 'bg-destructive' },
    medium: { label: 'Medium', dot: 'bg-warning' },
    low: { label: 'Low', dot: 'bg-muted-foreground' },
};

export const churnSignal = (signal: ChurnSignal): ChurnSignalDisplay => CHURN_SIGNALS[signal];

/** Whether the key names a signal the admin knows, as a count map's keys come back as text. */
export const isChurnSignal = (key: string): key is ChurnSignal => key in CHURN_SIGNALS;

export const riskLevel = (level: RiskLevel): { label: string; dot: string } => RISK_LEVELS[level];
