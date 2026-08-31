export const SocialAccountStatus = {
    Connected: 'connected',
    Disconnected: 'disconnected',
    TokenExpired: 'token_expired',
} as const;

export type SocialAccountStatusValue =
    (typeof SocialAccountStatus)[keyof typeof SocialAccountStatus];
