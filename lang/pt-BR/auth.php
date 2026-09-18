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

    'failed' => 'Essas credenciais não correspondem aos nossos registros.',
    'password' => 'A senha fornecida está incorreta.',
    'throttle' => 'Muitas tentativas de login. Por favor, tente novamente em :seconds segundos.',

    'flash' => [
        'welcome' => 'Bem-vindo ao TryPost!',
        'welcome_trial' => 'Bem-vindo ao TryPost! Seu período de teste começou.',
    ],

    'legal' => 'Ao continuar, você concorda com nossos <a href=":terms_url" target="_blank">Termos de Serviço</a> e <a href=":privacy_url" target="_blank">Política de Privacidade</a>.',

    'reviews' => [
        'eyebrow' => '5/5 no G2',
        'heading' => 'Amado por quem publica todo dia',
        'paulo_dantas' => [
            'role' => 'Fundador, chatadv.com.br',
            'quote' => 'A simplicidade de criar, organizar e distribuir conteúdo em todas as redes sociais. Com o MCP, podemos usar a IA que preferimos, como Claude ou ChatGPT, para criar conteúdo e agendar por ali mesmo.',
        ],
        'diego' => [
            'role' => 'CEO, Globalfy.com',
            'quote' => 'Gosto de como o TryPost é fácil de usar. Consigo criar um post direto no Claude e depois usar o MCP para publicar e agendar para o futuro. Configurei em cinco minutos.',
        ],
        'luiz' => [
            'role' => 'Criador de conteúdo',
            'quote' => 'Adoro como é fácil conectar minhas ferramentas e agentes de IA e agendar meus posts em 9 redes sociais em poucos minutos.',
        ],
        'pedro' => [
            'role' => 'Fundador, templated.io',
            'quote' => 'Muito fácil de usar e integrar. Com o MCP, só preciso da interface para conectar as contas das redes sociais.',
        ],
        'paulo_castellano' => [
            'role' => 'Fundador, changelogfy.com',
            'quote' => 'Adoro a integração com o MCP porque ela me permite organizar todas as minhas contas de redes sociais a partir do Claude ou do ChatGPT.',
        ],
    ],

    'or_continue_with' => 'Ou continue com',
    'or_continue_with_email' => 'Ou continue com e-mail',
    'google_login' => 'Entrar com Google',
    'google_signup' => 'Cadastrar com Google',
    'github_login' => 'Entrar com GitHub',
    'github_signup' => 'Cadastrar com GitHub',
    'github_email_unavailable' => 'Não foi possível obter seu e-mail do GitHub. Torne seu e-mail público ou conceda a permissão de e-mail e tente novamente.',

    'login' => [
        'title' => 'Entrar na sua conta',
        'description' => 'Digite seu email e senha abaixo para entrar',
        'page_title' => 'Entrar',
        'email' => 'Endereço de email',
        'password' => 'Senha',
        'show_password' => 'Mostrar senha',
        'hide_password' => 'Esconder senha',
        'forgot_password' => 'Esqueceu a senha?',
        'remember_me' => 'Lembrar de mim',
        'submit' => 'Entrar',
        'no_account' => 'Não tem uma conta?',
        'sign_up' => 'Cadastre-se',
    ],

    'register' => [
        'title' => 'Todo o seu calendário social num só lugar',
        'description' => 'Crie sua conta e comece a agendar posts em todas as redes.',
        'page_title' => 'Cadastro',
        'signup_with_email' => 'Cadastrar com e-mail',
        'name' => 'Nome',
        'name_placeholder' => 'Nome completo',
        'email' => 'Endereço de email',
        'password' => 'Senha',
        'show_password' => 'Mostrar senha',
        'hide_password' => 'Esconder senha',
        'submit' => 'Criar conta',
        'has_account' => 'Já tem uma conta?',
        'log_in' => 'Entrar',
    ],

    'forgot_password' => [
        'title' => 'Esqueceu a senha',
        'description' => 'Digite seu email para receber um link de redefinição de senha',
        'page_title' => 'Esqueceu a senha',
        'email' => 'Endereço de email',
        'submit' => 'Enviar link de redefinição',
        'return_to' => 'Ou, volte para',
        'log_in' => 'entrar',
    ],

    'reset_password' => [
        'title' => 'Redefinir senha',
        'description' => 'Por favor, digite sua nova senha abaixo',
        'page_title' => 'Redefinir senha',
        'email' => 'Email',
        'password' => 'Senha',
        'confirm_password' => 'Confirmar Senha',
        'confirm_placeholder' => 'Confirmar senha',
        'submit' => 'Redefinir senha',
    ],

    'verify_email' => [
        'title' => 'Verificar email',
        'description' => 'Por favor, verifique seu endereço de email clicando no link que acabamos de enviar.',
        'page_title' => 'Verificação de email',
        'link_sent' => 'Um novo link de verificação foi enviado para o endereço de email que você forneceu durante o cadastro.',
        'resend' => 'Reenviar email de verificação',
        'log_out' => 'Sair',
    ],

    'accept_invite' => [
        'page_title' => 'Aceitar Convite',
        'title' => 'Você foi convidado!',
        'description' => 'Você foi convidado para participar do workspace :workspace.',
        'workspace' => 'Workspace',
        'your_role' => 'Seu cargo',
        'email' => 'Email',
        'accept' => 'Aceitar Convite',
        'decline' => 'Recusar Convite',
        'login_prompt' => 'Entre ou crie uma conta para aceitar este convite.',
        'log_in' => 'Entrar',
        'create_account' => 'Criar Conta',
        'expired_title' => 'Este convite não é mais válido',
        'expired_description' => 'O workspace deste convite foi excluído. Peça ao dono da conta um novo convite se ainda precisar de acesso.',
        'expired_action' => 'Ir para o início',
    ],

];
