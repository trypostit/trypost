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
