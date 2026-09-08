<?php

return [
    'title' => 'Rozliczenia',

    'past_due_notice' => [
        'title' => 'Zaległa płatność',
        'description' => 'Zaktualizuj metodę płatności, aby utrzymać aktywną subskrypcję.',
        'cta' => 'Zaktualizuj płatność',
    ],

    'annual_banner' => [
        'title' => 'Otrzymaj 2 miesiące gratis',
        'description' => 'Przejdź na rozliczenie roczne i płać mniej każdego miesiąca — ten sam plan, nic więcej się nie zmienia.',
        'cta' => 'Przejdź na plan roczny',
    ],

    'subscribe' => [
        'billed_monthly' => 'Rozliczane miesięcznie',
        'billed_yearly' => 'Rozliczane rocznie',
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
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Nielimitowane workspace’y',
        'current' => 'Aktualny plan',
        'select' => 'Wybierz :plan',
        'start_first_month' => 'Zacznij pierwszy miesiąc za :price',

        'billed_yearly_total' => 'Rozliczane rocznie · :price (2 miesiące gratis)',
        'socials_tagline' => 'Dla twórców i małych marek.',
        'workspaces_tagline' => 'Dla agencji i większych firm.',
        'everything_included' => 'Wszystko w cenie',
        'features' => [
            'networks_all' => 'Wszystkie sieci społecznościowe w cenie',
            'networks_all_tooltip' => 'Podłącz dowolną z nich — wszystkie w cenie.',
            'accounts_unlimited' => 'Nielimitowane konta społecznościowe',
            'calendar' => 'Wizualny kalendarz z automatyczną publikacją',
            'ai' => 'AI: podpisy, obrazy i głos marki',
            'mcp' => 'MCP: publikuj z Claude, ChatGPT lub Grok',
            'repurpose' => 'Repurpose: z jednego posta zrób wiele',
            'analytics' => 'Analityka per post i konto',
            'team' => 'Nielimitowany zespół, role i akceptacje',
        ],
    ],

    'plan' => [
        'title' => 'Plan',
        'description' => 'Zarządzaj swoim planem subskrypcji.',
        'label' => 'Plan',
        'price' => 'Cena',
        'month' => 'miesiąc',
        'trial' => 'Okres próbny',
        'active' => 'Aktywny',
        'past_due' => 'Zaległa płatność',
        'cancelling' => 'Anulowanie',
        'trial_ends' => 'Okres próbny kończy się',
    ],

    'subscription' => [
        'title' => 'Subskrypcja',
        'description' => 'Zarządzaj metodą płatności, danymi rozliczeniowymi i subskrypcją.',
        'payment_method' => 'Metoda płatności',
        'no_payment_method' => 'Brak zapisanej metody płatności.',
        'expires_on' => 'Wygasa :month/:year',
        'manage_label' => 'Subskrypcja',
        'manage_stripe' => 'Zarządzaj w Stripe',
    ],

    'invoices' => [
        'title' => 'Faktury',
        'description' => 'Pobierz swoje wcześniejsze faktury.',
        'empty' => 'Nie znaleziono faktur',
        'paid' => 'Opłacona',
    ],

    'flash' => [
        'plan_changed' => 'Korzystasz teraz z planu :plan.',
        'switched_to_yearly' => 'Korzystasz teraz z rozliczenia rocznego.',
        'cannot_manage' => 'Tylko właściciel konta może zarządzać rozliczeniami.',
        'too_many_workspaces' => 'Masz :count workspace’ów. Ten plan obejmuje :limit — usuń nadmiar przed zmianą.',
        'subscription_required' => 'Aby korzystać z funkcji AI, wymagana jest aktywna subskrypcja.',
    ],

    'processing' => [
        'page_title' => 'Przetwarzanie...',
        'title' => 'Przetwarzanie Twojej subskrypcji',
        'description' => 'Poczekaj, aż skonfigurujemy Twoje konto. Zajmie to tylko chwilę.',
        'success_title' => 'Wszystko gotowe!',
        'success_description' => 'Twoja subskrypcja jest aktywna. Przekierowujemy Cię do Twoich przestrzeni roboczych...',
        'cancelled_title' => 'Anulowano płatność',
        'cancelled_description' => 'Twoja płatność została anulowana. Nie pobrano żadnych opłat.',
        'retry' => 'Spróbuj ponownie',
    ],
];
