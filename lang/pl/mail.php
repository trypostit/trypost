<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Otwartoźródłowe narzędzie do planowania postów w social mediach',
        'manage_notifications' => 'Zarządzaj powiadomieniami',
        'signoff' => 'Pozdrawiamy,',
        'team' => 'Zespół TryPost',
    ],

    'account_disconnected' => [
        'subject' => 'Twoje konto :platform w :workspace wymaga ponownego połączenia',
        'title' => 'Twoje konto :platform wymaga ponownego połączenia',
        'preview' => 'Połącz ponownie konto :platform w :workspace, aby dalej planować posty.',
        'heading' => 'Konto rozłączone',
        'intro' => 'Twoje konto <strong>:platform</strong> <strong>:account</strong> zostało rozłączone z przestrzenią roboczą <strong>:workspace</strong>.',
        'reasons_title' => 'Mogło się to stać, ponieważ:',
        'reason_expired' => 'Twój token dostępu wygasł',
        'reason_revoked' => 'Cofnięto dostęp dla TryPost',
        'reason_error' => 'Wystąpił błąd uwierzytelniania',
        'reconnect_cta' => 'Połącz konto ponownie, aby dalej planować i publikować posty.',
        'button' => 'Połącz konto ponownie',
    ],

    'email_verification' => [
        'subject' => 'Potwierdź swój adres e-mail',
        'preview' => 'Potwierdź swój adres e-mail.',
        'greeting' => 'Cześć :name,',
        'body' => 'Potwierdź swój adres e-mail, klikając przycisk poniżej:',
        'button' => 'Potwierdź e-mail',
        'ignore' => 'Jeśli nie zakładałeś konta, możesz zignorować tę wiadomość.',
    ],

    'password_reset' => [
        'subject' => 'Zresetuj hasło',
        'preview' => 'Zresetuj hasło.',
        'greeting' => 'Cześć :name,',
        'body' => 'Otrzymaliśmy prośbę o zresetowanie hasła. Kliknij przycisk, aby ustawić nowe:',
        'button' => 'Zresetuj hasło',
        'expiry' => 'Ten link wygasa za 60 minut. Jeśli nie prosiłeś o reset hasła, możesz zignorować tę wiadomość.',
    ],

    'post_at_risk' => [
        'subject' => ':count post jest zagrożony w :workspace|:count posty są zagrożone w :workspace|:count postów jest zagrożonych w :workspace',
        'title' => 'Posty mogą się nie opublikować',
        'heading' => 'Posty mogą się nie opublikować',
        'intro' => 'Poniższe konta w przestrzeni roboczej :workspace wymagają ponownego połączenia, zanim te zaplanowane posty będą mogły zostać opublikowane:',
        'posts_label' => ':count zaplanowany post: :times (:timezone)|:count zaplanowane posty: :times (:timezone)|:count zaplanowanych postów: :times (:timezone)',
        'reconnect_cta' => 'Połącz te konta ponownie już teraz, aby nie przegapić zaplanowanych postów.',
        'button' => 'Połącz konta ponownie',
    ],

    'post_note_added' => [
        'subject' => ':author dodał(a) notatkę do posta',
        'title' => 'Nowa notatka od :author',
        'heading' => 'Nowa notatka do posta',
        'body' => ':author dodał(a) notatkę do posta w obszarze roboczym :workspace.',
        'post_title' => 'Post',
        'post_without_text' => 'Ten post nie ma jeszcze tekstu.',
        'button' => 'Zobacz notatkę',
    ],
    'post_approval_requested' => [
        'subject' => ':name prosi o zatwierdzenie posta',
        'title' => 'Post czeka na Twoje zatwierdzenie',
        'preview' => ':name prosi o zatwierdzenie w :workspace.',
        'heading' => 'Post czeka na Twoje zatwierdzenie',
        'body' => ':name (:email) prosi o zatwierdzenie w przestrzeni roboczej :workspace.',
        'channels' => 'Kanały',
        'requested_time' => 'Wybrany czas',
        'next_queue_slot' => 'Następne miejsce w kolejce',
        'as_soon_as_approved' => 'Gdy tylko zostanie zatwierdzony',
        'post_without_text' => 'Ten post nie ma jeszcze tekstu.',
        'button' => 'Zobacz posty czekające na zatwierdzenie',
    ],

    'post_approved' => [
        'subject' => ':name zatwierdził(a) Twój post',
        'title' => 'Twój post został zatwierdzony',
        'preview' => ':name zatwierdził(a) Twój post w :workspace.',
        'heading' => 'Twój post został zatwierdzony',
        'body' => ':name zatwierdził(a) Twój post w przestrzeni roboczej :workspace.',
        'channels' => 'Kanały',
        'goes_out' => 'Zostanie opublikowany',
        'channel_time' => ':channel: :time',
        'publishing_now' => 'Publikowanie teraz',
        'button' => 'Zobacz w kolejce',
    ],

    'post_rejected' => [
        'subject' => ':name nie zatwierdził(a) Twojego posta',
        'title' => 'Twój post nie został zatwierdzony',
        'preview' => ':name przeniósł(a) Twój post z powrotem do wersji roboczych.',
        'heading' => 'Twój post nie został zatwierdzony',
        'body' => ':name przeniósł(a) Twój post w przestrzeni roboczej :workspace z powrotem do wersji roboczych.',
        'channels' => 'Kanały',
        'button' => 'Zobacz w wersjach roboczych',
    ],

    'post_publish_failed' => [
        'subject' => 'Nie udało się opublikować posta w :workspace',
        'title' => 'Nie udało się opublikować posta',
        'preview' => 'Co najmniej jedna platforma nie opublikowała posta.',
        'heading' => 'Nie udało się opublikować posta',
        'body' => 'Twój zaplanowany post w przestrzeni roboczej :workspace nie został opublikowany na co najmniej jednej platformie.',
        'platforms_title' => 'Platformy z błędem:',
        'button' => 'Zobacz post',
    ],

    'post_published' => [
        'subject' => 'Twój post został opublikowany w :workspace',
        'title' => 'Twój post został opublikowany',
        'preview' => 'Twój post został opublikowany pomyślnie.',
        'heading' => 'Twój post został opublikowany',
        'body' => 'Twój post w przestrzeni roboczej :workspace został opublikowany pomyślnie.',
        'platforms_title' => 'Opublikowano na:',
        'view_post' => 'Zobacz post',
        'button' => 'Zobacz post',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook wstrzymany: :endpoint',
        'title' => 'Webhook wstrzymany po powtarzających się błędach',
        'preview' => 'Wstrzymaliśmy webhook po 5 kolejnych błędach dostarczenia.',
        'heading' => 'Webhook wstrzymany po powtarzających się błędach',
        'body' => 'Wstrzymaliśmy webhook pod adresem :endpoint po 5 kolejnych błędach dostarczenia. Sprawdź endpoint i włącz go ponownie na stronie szczegółów webhooka.',
        'button' => 'Zobacz webhook',
    ],

    'workspace_connections_disconnected' => [
        'subject' => ':count konto wymaga ponownego połączenia w przestrzeni roboczej :workspace|:count konta wymagają ponownego połączenia w przestrzeni roboczej :workspace|:count kont wymaga ponownego połączenia w przestrzeni roboczej :workspace',
        'title' => 'Konta wymagają ponownego połączenia',
        'heading' => 'Konta wymagają ponownego połączenia',
        'intro' => 'Następujące konta społecznościowe w Twojej przestrzeni roboczej <strong>:workspace</strong> zostały rozłączone i wymagają ponownego połączenia:',
        'reasons_title' => 'Mogło się to zdarzyć, ponieważ:',
        'reason_expired' => 'Tokeny dostępu wygasły',
        'reason_revoked' => 'Cofnąłeś dostęp do TryPost na danej platformie',
        'reason_changed' => 'Platforma zmieniła swoje wymagania dotyczące uwierzytelniania',
        'reconnect_cta' => 'Połącz te konta ponownie, aby kontynuować planowanie i publikowanie postów.',
        'button' => 'Połącz konta ponownie',
    ],

    'workspace_invite' => [
        'subject' => 'Zaproszono Cię do :account',
        'title' => 'Zaproszono Cię do :account',
        'preview' => 'Zaproszono Cię do :account',
        'heading' => 'Masz zaproszenie!',
        'intro' => 'Zaproszono Cię do współpracy w przestrzeni roboczej <strong>:account</strong>.',
        'role' => 'Zaproszono Cię jako <strong>:role</strong>.',
        'roles' => ['admin' => 'Administrator', 'member' => 'Członek', 'needs_approval' => 'Członek (posty wymagają zatwierdzenia)'],
        'button' => 'Przyjmij zaproszenie',
        'expiry' => 'To zaproszenie wygasa za 7 dni.',
    ],

];
