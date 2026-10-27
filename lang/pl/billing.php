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
        'per_year' => '/rok',
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
            'networks_all' => 'Wszystkie sieci społecznościowe w cenie',
            'networks_all_tooltip' => 'Publikuj we wszystkich z jednego miejsca.',
            'accounts_unlimited' => 'Nielimitowane konta społecznościowe',
            'accounts_unlimited_tooltip' => 'Podłącz tyle kont, ile chcesz, także kilka z tej samej sieci. Na przykład trzy Instagramy.',
            'calendar' => 'Kalendarz treści',
            'ideas' => 'Planowanie treści',
            'ideas_tooltip' => 'Zapisuj pomysły, porządkuj je w jednym miejscu i zamieniaj najlepsze w treści, kiedy chcesz.',
            'calendar_tooltip' => 'Zobacz wszystkie zaplanowane i opublikowane posty w jednym miejscu, w widoku miesiąca lub tygodnia.',
            'ai' => 'Asystent AI do pisania',
            'ai_tooltip' => 'Pisz, przepisuj i dopasowuj ton postów z AI, w swoim języku.',
            'mcp' => 'Korzystaj z TryPost w Claude lub ChatGPT',
            'mcp_tooltip' => 'Połącz Claude, ChatGPT lub inną AI. Poproś o tworzenie i planowanie postów, wypełnianie kolejki, porządkowanie pomysłów i pokazanie wzrostu.',
            'repurpose' => 'Automatyczne ponowne publikowanie filmów',
            'repurpose_tooltip' => 'Opublikowałeś film na Instagramie? TryPost automatycznie publikuje go ponownie na TikToku, YouTube, Facebooku i w innych twoich sieciach.',
            'analytics' => 'Zaawansowana analityka',
            'analytics_tooltip' => 'Zobacz wszystkie sieci razem, np. łączną liczbę obserwujących, albo filtruj po sieci i poście. Wyświetlenia, zasięg, polubienia, komentarze i więcej – wiesz, co działa.',
            'team' => 'Nielimitowani członkowie zespołu',
            'team_tooltip' => 'Zaproś do zespołu tyle osób, ile chcesz, bez dodatkowych opłat. Ustal, co każda może robić, i zatwierdzaj posty przed publikacją.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'Aktywny',
            'trial' => 'Okres próbny',
            'cancelling' => 'Anulowany',
            'past_due' => 'Zaległy',
        ],
        'change' => 'Zmień plan',
        'price_monthly' => ':price/miesiąc',
        'price_yearly' => ':price/rok',
        'trial_until' => 'Okres próbny do :date',
        'cancelled' => 'Anulowano, kończy się :date',
        'discount_until' => 'Rabat :discount do :date',
        'discount_forever' => 'Rabat :discount na każdą płatność',
        'discount_once' => 'Rabat :discount na następną płatność',
    ],

    'subscription' => [
        'title' => 'Metody płatności',
        'default' => 'Domyślna',
        'description' => 'Zaktualizuj kartę lub dane rozliczeniowe w Stripe.',
        'no_payment_method' => 'Brak zapisanej metody płatności.',
        'expires_on' => 'Wygasa :month/:year',
        'manage_stripe' => 'Zarządzaj w Stripe',
    ],

    'tabs' => [
        'plan' => 'Plan',
        'invoices' => 'Faktury',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'Data',
            'amount' => 'Kwota',
            'status' => 'Status',
        ],
        'empty_title' => 'Brak faktur',
        'empty_description' => 'Faktury pojawią się tutaj po pierwszej płatności.',
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
