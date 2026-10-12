<?php

return [
    'title' => 'Rozliczenia',

    'past_due_notice' => [
        'title' => 'Zaległa płatność',
        'description' => 'Zaktualizuj metodę płatności, aby utrzymać aktywną subskrypcję.',
        'cta' => 'Zaktualizuj płatność',
    ],

    'subscribe' => [
        'billed_monthly' => 'Rozliczane miesięcznie',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => 'Zmień plan w dowolnym momencie.',
        'monthly' => 'Miesięcznie',
        'yearly' => 'Rocznie',
        'save_two_months' => '2 miesiące gratis',
        'per_month' => '/miesiąc',
        'workspaces_one' => 'Jeden workspace',
        'workspaces_unlimited' => 'Nielimitowane workspace’y',
        'workspaces_tooltip' => 'Workspace to jedna marka lub klient, oddzielony od pozostałych: własne konta społecznościowe, podpisy, etykiety, analityka, uprawnienia członków i połączenie MCP.',
        'current' => 'Aktualny plan',
        'switch_to_yearly' => 'Przełącz na roczny',
        'switch_to_monthly' => 'Przełącz na miesięczny',
        'select' => 'Wybierz :plan',
        'upgrade' => 'Przejdź na :plan',
        'downgrade' => 'Zmień na :plan',
        'start_first_month' => 'Aktywuj za :price',
        'billed_yearly_total' => 'Rozliczane rocznie · :price (2 miesiące gratis)',
        'socials_tagline' => 'Dla twórców i małych marek.',
        'workspaces_tagline' => 'Dla agencji i większych firm.',
        'everything_included' => 'Wszystko w cenie',
        'features' => [
            'networks_all' => 'Wszystkie 13 sieci społecznościowych',
            'networks_all_tooltip' => 'Publikuj we wszystkich z jednego miejsca.',
            'accounts_unlimited' => 'Nielimitowane konta społecznościowe',
            'accounts_unlimited_tooltip' => 'Podłącz tyle kont, ile chcesz, także kilka z tej samej sieci. Na przykład trzy Instagramy.',
            'calendar' => 'Kalendarz treści',
            'calendar_tooltip' => 'Zobacz cały miesiąc na jednym ekranie: co zaplanowane, ustawione w kolejce i już opublikowane. Przełącz na tydzień, gdy potrzebujesz szczegółów.',
            'ai' => 'Asystent AI do pisania',
            'ai_tooltip' => 'Twój asystent AI do pisania i poprawiania postów.',
            'mcp' => 'Korzystaj z TryPost w Claude lub ChatGPT',
            'mcp_tooltip' => 'Połącz Claude, ChatGPT lub inną AI. Poproś o tworzenie i planowanie postów, wypełnianie kolejki, porządkowanie pomysłów i pokazanie wzrostu.',
            'repurpose' => 'Twoje filmy we wszystkich sieciach',
            'repurpose_tooltip' => 'Wybierz konto na Instagramie lub Facebooku. Każdy nowy Reel lub film, który tam opublikujesz, trafi sam do innych sieci, bez otwierania TryPost.',
            'analytics' => 'Statystyki każdego posta i konta',
            'analytics_tooltip' => 'Zbieraj metryki, takie jak wyświetlenia, zasięg, polubienia i komentarze, dla każdego posta i każdego konta, wszystko w jednym miejscu.',
            'team' => 'Nielimitowani członkowie zespołu',
            'team_tooltip' => 'Zaproś do zespołu tyle osób, ile chcesz, bez dodatkowych opłat. Ustal, co każda może robić, i zatwierdzaj posty przed publikacją.',
        ],
    ],

    'current_plan' => [
        'change' => 'Zmień plan',
        'price_monthly' => ':price/miesiąc',
        'price_yearly' => ':price/rok',
        'trial_until' => 'Okres próbny do :date',
        'renews' => 'Odnawia się automatycznie',
        'cancelled' => 'Anulowano, kończy się :date',
        'workspaces_usage' => 'Workspace’y: :count z :limit',
    ],

    'subscription' => [
        'title' => 'Metoda płatności',
        'description' => 'Zaktualizuj kartę lub dane rozliczeniowe w Stripe.',
        'no_payment_method' => 'Brak zapisanej metody płatności.',
        'expires_on' => 'Wygasa :month/:year',
        'manage_stripe' => 'Zarządzaj w Stripe',
    ],

    'invoices' => [
        'title' => 'Faktury',
        'description' => 'Pobierz swoje wcześniejsze faktury.',
        'paid' => 'Opłacona',
        'download' => 'Pobierz fakturę',
    ],

    'flash' => [
        'plan_changed' => 'Korzystasz teraz z planu :plan.',
        'cannot_manage' => 'Tylko właściciel konta może zarządzać rozliczeniami.',
        'too_many_workspaces' => 'Masz :count workspace’ów. Ten plan obejmuje :limit — usuń nadmiar przed zmianą.',
        'subscription_required' => 'Aby korzystać z funkcji AI, wymagana jest aktywna subskrypcja.',
    ],

    'processing' => [
        'page_title' => 'Przetwarzanie...',
        'title' => 'Przetwarzanie Twojej subskrypcji',
        'description' => 'Poczekaj, aż skonfigurujemy Twoje konto. Zajmie to tylko chwilę.',
    ],
];
