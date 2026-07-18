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
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
        ],
    ],

    'plan' => [
        'title' => 'Plan',
        'description' => 'Zarządzaj swoim planem subskrypcji.',
        'label' => 'Plan',
        'workspaces' => ':count przestrzeń robocza|:count przestrzenie robocze|:count przestrzeni roboczych',
        'per_workspace' => 'za przestrzeń roboczą',
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
        'credits_exhausted' => 'Brak kredytów AI — Twój miesięczny limit :limit został wykorzystany. Ulepsz plan lub poczekaj do następnego miesiąca.',
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
