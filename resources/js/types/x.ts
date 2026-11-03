/** The poll limits X sets, served by `Platform::publishConfig()` (`publishConfig.poll`). */
export interface XPollLimits {
    minOptions: number;
    maxOptions: number;
    optionMaxLength: number;
    minDuration: number;
    maxDuration: number;
    defaultDuration: number;
    questionMaxLength: number;
}

/** A poll's length split into the units the editor picks. */
export interface XPollDuration {
    days: number;
    hours: number;
    minutes: number;
}

/** An X poll as `meta.poll` (or a thread reply's `poll`) stores it. */
export interface XPoll {
    options: string[];
    duration_minutes: number;
}
