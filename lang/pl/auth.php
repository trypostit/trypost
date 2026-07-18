<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'Te dane logowania nie pasują do naszych rekordów.',
    'password' => 'Podane hasło jest nieprawidłowe.',
    'throttle' => 'Zbyt wiele prób logowania. Spróbuj ponownie za :seconds s.',

    'flash' => [
        'welcome' => 'Witamy w TryPost!',
        'welcome_trial' => 'Witamy w TryPost! Twój okres próbny właśnie się rozpoczął.',
    ],

    'legal' => 'Kontynuując, akceptujesz nasze <a href="https://trypost.it/terms" target="_blank">Warunki korzystania z usługi</a> oraz <a href="https://trypost.it/privacy" target="_blank">Politykę prywatności</a>.',

    'slides' => [
        'calendar' => [
            'title' => 'Wizualny kalendarz',
            'description' => 'Planuj i harmonogramuj treści na wszystkich swoich kontach społecznościowych dzięki intuicyjnemu kalendarzowi z funkcją przeciągnij i upuść.',
        ],
        'scheduling' => [
            'title' => 'Inteligentne planowanie',
            'description' => 'Planuj posty na LinkedIn, X, Instagram, TikTok, YouTube i nie tylko — wszystko z jednego miejsca.',
        ],
        'media' => [
            'title' => 'Bogate multimedia',
            'description' => 'Publikuj zdjęcia, karuzele, relacje i rolki. Każda platforma automatycznie otrzymuje właściwy format.',
        ],
        'video' => [
            'title' => 'Publikowanie wideo',
            'description' => 'Prześlij film raz i opublikuj go na TikTok, YouTube Shorts, Instagram Reels i Facebook Reels.',
        ],
        'team' => [
            'title' => 'Zespołowe przestrzenie robocze',
            'description' => 'Zaproś swój zespół, przypisz role i zarządzaj wieloma markami z osobnych przestrzeni roboczych.',
        ],
        'signatures' => [
            'title' => 'Sygnatury',
            'description' => 'Zapisuj wielokrotnego użytku sygnatury (hasztagi, linki, zakończenia) i dołączaj je do postów jednym kliknięciem.',
        ],
    ],

    'or_continue_with' => 'Lub kontynuuj przez',
    'or_continue_with_email' => 'Lub kontynuuj przez e-mail',
    'google_login' => 'Zaloguj się przez Google',
    'google_signup' => 'Zarejestruj się przez Google',
    'github_login' => 'Zaloguj się przez GitHub',
    'github_signup' => 'Zarejestruj się przez GitHub',
    'github_email_unavailable' => 'Nie udało się pobrać Twojego adresu e-mail z GitHuba. Ustaw swój adres e-mail w GitHubie jako publiczny lub przyznaj uprawnienie do e-maila, a następnie spróbuj ponownie.',

    'signup_success' => [
        'page_title' => 'Witamy',
        'title' => 'Konfigurowanie Twojego konta',
        'description' => 'Zwykle zajmuje to tylko kilka sekund...',
    ],

    'login' => [
        'title' => 'Zaloguj się na swoje konto',
        'description' => 'Wprowadź poniżej swój e-mail i hasło, aby się zalogować',
        'page_title' => 'Zaloguj się',
        'email' => 'Adres e-mail',
        'password' => 'Hasło',
        'forgot_password' => 'Nie pamiętasz hasła?',
        'remember_me' => 'Zapamiętaj mnie',
        'submit' => 'Zaloguj się',
        'no_account' => 'Nie masz konta?',
        'sign_up' => 'Zarejestruj się',
    ],

    'register' => [
        'title' => 'Cały Twój kalendarz społecznościowy w jednym miejscu',
        'description' => 'Załóż konto i zacznij planować posty w każdej sieci.',
        'page_title' => 'Rejestracja',
        'signup_with_email' => 'Zarejestruj się przez e-mail',
        'name' => 'Imię i nazwisko',
        'name_placeholder' => 'Imię i nazwisko',
        'email' => 'Adres e-mail',
        'password' => 'Hasło',
        'show_password' => 'Pokaż hasło',
        'hide_password' => 'Ukryj hasło',
        'submit' => 'Utwórz konto',
        'has_account' => 'Masz już konto?',
        'log_in' => 'Zaloguj się',
    ],

    'forgot_password' => [
        'title' => 'Nie pamiętasz hasła',
        'description' => 'Wprowadź swój e-mail, aby otrzymać link do zresetowania hasła',
        'page_title' => 'Nie pamiętasz hasła',
        'email' => 'Adres e-mail',
        'submit' => 'Wyślij link do resetu hasła',
        'return_to' => 'Lub wróć do',
        'log_in' => 'logowania',
    ],

    'reset_password' => [
        'title' => 'Zresetuj hasło',
        'description' => 'Wprowadź poniżej swoje nowe hasło',
        'page_title' => 'Zresetuj hasło',
        'email' => 'E-mail',
        'password' => 'Hasło',
        'confirm_password' => 'Potwierdź hasło',
        'confirm_placeholder' => 'Potwierdź hasło',
        'submit' => 'Zresetuj hasło',
    ],

    'verify_email' => [
        'title' => 'Zweryfikuj e-mail',
        'description' => 'Zweryfikuj swój adres e-mail, klikając w link, który właśnie do Ciebie wysłaliśmy.',
        'page_title' => 'Weryfikacja e-maila',
        'link_sent' => 'Nowy link weryfikacyjny został wysłany na adres e-mail podany podczas rejestracji.',
        'resend' => 'Wyślij ponownie e-mail weryfikacyjny',
        'log_out' => 'Wyloguj się',
    ],

    'accept_invite' => [
        'page_title' => 'Zaakceptuj zaproszenie',
        'title' => 'Otrzymałeś zaproszenie!',
        'description' => 'Otrzymałeś zaproszenie do przestrzeni roboczej :workspace.',
        'workspace' => 'Przestrzeń robocza',
        'your_role' => 'Twoja rola',
        'email' => 'E-mail',
        'accept' => 'Zaakceptuj zaproszenie',
        'decline' => 'Odrzuć zaproszenie',
        'login_prompt' => 'Zaloguj się lub załóż konto, aby zaakceptować to zaproszenie.',
        'log_in' => 'Zaloguj się',
        'create_account' => 'Utwórz konto',
    ],

];
