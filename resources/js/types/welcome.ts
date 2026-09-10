/** What the user has built so far during welcome — see WelcomeSummaryResource. */
export interface WelcomeNetwork {
    id: string;
    platform: string;
    display_label: string;
    username: string | null;
    avatar_url: string | null;
}

export interface WelcomeSummary {
    persona: string | null;
    goals: string[];
    networks: WelcomeNetwork[];
}
