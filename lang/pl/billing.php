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
        'title' => 'Plany',
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
        'start_first_month' => 'Zacznij za :price',
        'per_first_month' => '/pierwszy miesiąc',
        'then_monthly' => 'Potem :price/mies.',
        'billed_yearly_total' => 'Rozliczane rocznie · :price (2 miesiące gratis)',
        'socials_tagline' => 'Dla twórców i małych marek.',
        'workspaces_tagline' => 'Dla agencji i większych firm.',
        'everything_included' => 'Wszystko w cenie',
        'features' => [
            'networks_all' => 'Wszystkie sieci społecznościowe w cenie',
            'networks_all_tooltip' => 'Możesz publikować we wszystkich tych sieciach.',
            'accounts_unlimited' => 'Nielimitowane konta społecznościowe',
            'accounts_unlimited_tooltip' => 'Podłącz tyle kont, ile chcesz, także kilka z tej samej sieci. Na przykład trzy Instagramy.',
            'calendar' => 'Kalendarz: widok miesiąca, tygodnia i dnia',
            'calendar_tooltip' => 'Zobacz cały miesiąc na jednym ekranie: co zaplanowane, ustawione w kolejce i już opublikowane. Przełącz na tydzień lub dzień, gdy potrzebujesz szczegółów.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'Twój asystent AI do pisania i poprawiania postów.',
            'mcp' => 'MCP: publikuj z Claude, ChatGPT lub Grok',
            'mcp_tooltip' => 'Podłącz Claude, ChatGPT lub Grok do swojego workspace. Poproś o tworzenie i planowanie postów, pobieranie metryk, wskazanie najlepszych postów i planowanie kolejnych treści na podstawie Twoich danych.',
            'repurpose' => 'Repurpose: z jednego posta zrób wiele',
            'repurpose_tooltip' => 'Wybierz konto źródłowe. Każdy nowy post, który tam opublikujesz, jest automatycznie publikowany w Twoich pozostałych sieciach. Nie musisz otwierać TryPost.',
            'analytics' => 'Analytics',
            'analytics_tooltip' => 'Zbieraj metryki, takie jak wyświetlenia, zasięg, polubienia i komentarze, dla każdego posta i każdego konta, wszystko w jednym miejscu.',
            'team' => 'Nielimitowani członkowie',
            'team_tooltip' => 'Zaproś do zespołu tyle osób, ile chcesz, bez dodatkowych opłat. Ustal, co każda może robić, i zatwierdzaj posty przed publikacją.',
        ],
    ],

    'plan' => [
        'trial' => 'Okres próbny',
        'cancelling' => 'Anulowanie',
        'trial_ends' => 'Okres próbny kończy się',
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
