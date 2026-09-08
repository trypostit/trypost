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

    'legal' => 'Kontynuując, akceptujesz nasze <a href=":terms_url" target="_blank">Warunki korzystania z usługi</a> oraz <a href=":privacy_url" target="_blank">Politykę prywatności</a>.',

    'reviews' => [
        'eyebrow' => '5/5 w G2',
        'heading' => 'Uwielbiany przez tych, co publikują',
        'paulo_dantas' => [
            'role' => 'Założyciel, chatadv.com.br',
            'quote' => 'Prostota tworzenia, porządkowania i dystrybucji treści we wszystkich sieciach społecznościowych. Dzięki MCP możemy korzystać z wybranej AI, na przykład Claude lub ChatGPT, aby tworzyć treści i od razu je planować.',
        ],
        'diego' => [
            'role' => 'CEO, Globalfy.com',
            'quote' => 'Podoba mi się, jak łatwo korzysta się z TryPost. Tworzę post bezpośrednio w Claude, a potem przez MCP publikuję go i planuję na przyszłość. Konfiguracja zajęła mi pięć minut.',
        ],
        'luiz' => [
            'role' => 'Twórca treści',
            'quote' => 'Uwielbiam, jak łatwo podłączam swoje narzędzia i agentów AI oraz planuję posty w 9 sieciach społecznościowych w kilka minut.',
        ],
        'pedro' => [
            'role' => 'Założyciel, templated.io',
            'quote' => 'Naprawdę łatwe w użyciu i integracji. Z MCP interfejs jest mi potrzebny tylko do podłączenia kont społecznościowych.',
        ],
        'paulo_castellano' => [
            'role' => 'Założyciel, changelogfy.com',
            'quote' => 'Uwielbiam integrację z MCP, bo pozwala mi zarządzać wszystkimi kontami społecznościowymi z poziomu Claude lub ChatGPT.',
        ],
    ],

    'or_continue_with' => 'Lub kontynuuj przez',
    'or_continue_with_email' => 'Lub kontynuuj przez e-mail',
    'google_login' => 'Zaloguj się przez Google',
    'google_signup' => 'Zarejestruj się przez Google',
    'github_login' => 'Zaloguj się przez GitHub',
    'github_signup' => 'Zarejestruj się przez GitHub',
    'github_email_unavailable' => 'Nie udało się pobrać Twojego adresu e-mail z GitHuba. Ustaw swój adres e-mail w GitHubie jako publiczny lub przyznaj uprawnienie do e-maila, a następnie spróbuj ponownie.',

    'login' => [
        'title' => 'Zaloguj się na swoje konto',
        'description' => 'Wprowadź poniżej swój e-mail i hasło, aby się zalogować',
        'page_title' => 'Zaloguj się',
        'email' => 'Adres e-mail',
        'password' => 'Hasło',
        'show_password' => 'Pokaż hasło',
        'hide_password' => 'Ukryj hasło',
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
        'expired_title' => 'To zaproszenie jest już nieważne',
        'expired_description' => 'Przestrzeń robocza z tego zaproszenia została usunięta. Poproś właściciela konta o nowe zaproszenie, jeśli nadal potrzebujesz dostępu.',
        'expired_action' => 'Przejdź do strony głównej',
    ],

];
