<template>
    <svg
        viewBox="0 0 420 260"
        class="h-auto w-full max-w-md text-border-strong"
        fill="none"
        aria-hidden="true"
        data-testid="inbox-empty-illustration"
    >
        <g transform="translate(26 40) rotate(-8 60 22)" opacity="0.8">
            <rect
                width="120"
                height="44"
                rx="12"
                class="fill-card"
                stroke="currentColor"
            />
            <circle cx="20" cy="22" r="9" class="fill-primary/30" />
            <rect x="36" y="14" width="56" height="6" rx="3" class="fill-muted" />
            <rect x="36" y="25" width="36" height="5" rx="2.5" class="fill-muted" />
            <path d="M28 44 l-6 10 l14 -10" class="fill-card" stroke="currentColor" stroke-linejoin="round" />
        </g>
        <g transform="translate(276 28) rotate(7 60 22)" opacity="0.8">
            <rect
                width="120"
                height="44"
                rx="12"
                class="fill-card"
                stroke="currentColor"
            />
            <circle cx="20" cy="22" r="9" class="fill-warning/30" />
            <rect x="36" y="14" width="60" height="6" rx="3" class="fill-muted" />
            <rect x="36" y="25" width="30" height="5" rx="2.5" class="fill-muted" />
            <path d="M92 44 l8 10 l-16 -10" class="fill-card" stroke="currentColor" stroke-linejoin="round" />
        </g>
        <g transform="translate(300 176) rotate(-6 50 20)" opacity="0.7">
            <rect
                width="100"
                height="40"
                rx="12"
                class="fill-card"
                stroke="currentColor"
            />
            <circle cx="18" cy="20" r="8" class="fill-success/40" />
            <rect x="32" y="17" width="50" height="6" rx="3" class="fill-muted" />
        </g>

        <path
            d="M120 92 C 140 104 150 110 160 118 M300 92 C 282 104 272 110 262 118"
            class="stroke-primary/50"
            stroke-width="2"
            stroke-linecap="round"
            stroke-dasharray="4 5"
        />

        <rect
            x="130"
            y="112"
            width="160"
            height="118"
            rx="16"
            class="fill-card"
            stroke="currentColor"
        />
        <path
            d="M130 172 h40 l14 18 h52 l14 -18 h40"
            stroke="currentColor"
            stroke-linejoin="round"
        />
        <rect x="150" y="130" width="120" height="8" rx="4" class="fill-muted" />
        <rect x="150" y="146" width="84" height="8" rx="4" class="fill-muted" />
        <circle cx="254" cy="150" r="10" class="fill-primary/20 stroke-primary/50" />
        <path
            d="M249 150 l3.5 3.5 l6.5 -7"
            class="stroke-primary/80"
            stroke-width="1.75"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <rect x="184" y="200" width="52" height="8" rx="4" class="fill-primary/25" />
    </svg>
</template>
