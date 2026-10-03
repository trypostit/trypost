# Configurando as integrações de mídia do TryPost

Guia para o admin do TryPost: o que criar em cada console para ligar **Google Drive**, **Google Photos**,
**Canva** e **Unsplash** no composer, e como habilitar **HEIC** (fotos do iPhone).

Cada fonte é opcional. Ela só aparece no menu de mídia do composer (botão dividido ao lado do upload, seta ▾)
quando a flag dela está ligada **e** as chaves estão preenchidas. Nada reaproveita as credenciais do login
com Google/YouTube (`GOOGLE_CLIENT_ID`) nem do Google Business (`GOOGLE_BUSINESS_*`).

**URLs usadas neste guia**

| Ambiente | URL do app |
| --- | --- |
| Local (Herd) | `https://app.trypost.test` |
| Produção (Cloud) | `https://app.trypost.it` (padrão de `APP_URL` em `config/app.php`) |

Depois de mudar o `.env`, rode `php artisan config:clear` (e reinicie os workers em produção).

> Os valores de exemplo abaixo são **falsos**, só para mostrar o formato. Nunca commite chaves reais.

---

## 1. Google Drive e Google Photos (um projeto Google só para mídia)

Drive e Photos usam o **mesmo** projeto e o mesmo OAuth client. O login roda no **servidor**, igual ao Google
Business e ao YouTube: o composer abre uma janela em `/integrations/google/start`, o Google pede consentimento e
volta para `GOOGLE_MEDIA_CLIENT_REDIRECT` (`/integrations/google/callback`), e o servidor troca o código pelo token
(PKCE S256, `state` de uso único preso ao usuário e ao workspace, `access_type=online`, um escopo por fonte). Por
isso o client precisa do **client secret** e do **URI de redirecionamento** cadastrado.

### 1.1 Criar o projeto

1. Acesse https://console.cloud.google.com e crie um projeto novo, ex.: **TryPost Media**. Não use o projeto
   do login/YouTube nem o do Google Business (evita nova revisão dos escopos já aprovados).
2. Em **APIs e serviços → Biblioteca**, ative:
   - **Google Picker API** (Drive)
   - **Google Drive API** (Drive)
   - **Photos Picker API** (Photos)

### 1.2 Tela de consentimento OAuth

1. **Google Auth Platform → Branding**: nome do app **TryPost**, e-mail de suporte, logo, links de política
   de privacidade e termos (`https://trypost.it/terms`), domínio autorizado `trypost.it`.
2. **Público-alvo**: *Externo*. Enquanto estiver em *Teste*, adicione seu e-mail em **Usuários de teste**.
3. **Acesso a dados → Adicionar escopos**:
   - `https://www.googleapis.com/auth/drive.file` — **não sensível**. Só os arquivos que o usuário escolher
     no Picker ficam acessíveis. Publicar em produção não exige revisão por causa dele.
   - `https://www.googleapis.com/auth/photospicker.mediaitems.readonly` — **sensível**. Em produção exige
     **verificação do Google** (justificativa do escopo, vídeo demo do fluxo do Photos e URL da política de
     privacidade). Sem verificação: tela "app não verificado" e limite de 100 usuários.
   - **Nunca** adicione `drive.readonly`: é escopo **restrito** e exige auditoria CASA.
4. Quando for para produção: **Publicar app**. Para o Photos, envie a verificação e só depois ligue
   `GOOGLE_PHOTOS_ENABLED=true` no Cloud.

### 1.3 OAuth client (tipo Web)

1. **Credenciais → Criar credenciais → ID do cliente OAuth → Aplicativo da Web**, nome "TryPost Media Web".
2. **URIs de redirecionamento autorizados**: o valor exato de `GOOGLE_MEDIA_CLIENT_REDIRECT` (protocolo,
   subdomínio e sem barra no fim):
   - `https://app.trypost.it/integrations/google/callback`
   - local: o Google só aceita domínio público (`.test` é recusado). Como no Google Business, use uma URL pública
     (ex.: `herd share`), cadastre `https://<host-publico>/integrations/google/callback` e troque o host de
     `GOOGLE_MEDIA_CLIENT_REDIRECT` no `.env` à mão.
3. **Origens JavaScript autorizadas**: não são mais usadas pelo login (ele não roda no navegador). Pode deixar
   vazio. A restrição por referenciador da **API key** (1.4) continua valendo para o Picker do Drive.
4. Copie o **ID do cliente** → `GOOGLE_MEDIA_CLIENT_ID` e a **chave secreta do cliente** (começa com `GOCSPX-`)
   → `GOOGLE_MEDIA_CLIENT_SECRET`. O secret fica só no servidor.

### 1.4 API key (Picker)

1. **Credenciais → Criar credenciais → Chave de API**.
2. **Restrições de aplicativo → Sites (referenciadores HTTP)**:
   - `https://app.trypost.it/*`
   - `https://app.trypost.test/*`
   - `https://docs.google.com/*` (o iframe do Picker roda nesse domínio)
3. **Restrições de API → Restringir chave → Google Picker API**.
4. Copie a chave → `GOOGLE_MEDIA_API_KEY`. Ela começa com `AIza`; não confunda com o client secret
   (`GOCSPX-...`), que vai em `GOOGLE_MEDIA_CLIENT_SECRET`.

### 1.5 App ID

1. **IAM e administrador → Configurações** do mesmo projeto.
2. Copie o **Número do projeto** (só dígitos, não o ID do projeto) → `GOOGLE_MEDIA_APP_ID`.

### 1.6 Variáveis

```dotenv
# Exemplo FALSO
GOOGLE_MEDIA_CLIENT_ID=123456789012-abc123def456.apps.googleusercontent.com
GOOGLE_MEDIA_CLIENT_SECRET=GOCSPX-EXEMPLO-FALSO-000000000000
GOOGLE_MEDIA_CLIENT_REDIRECT="${APP_URL}/integrations/google/callback"
GOOGLE_MEDIA_API_KEY=AIzaSyEXEMPLO-FALSO-0000000000000000
GOOGLE_MEDIA_APP_ID=123456789012
GOOGLE_DRIVE_ENABLED=true     # aparece com client id, secret, redirect, API key e App ID
GOOGLE_PHOTOS_ENABLED=false   # ligue só depois da verificação do escopo sensível (precisa de id, secret e redirect)
# GOOGLE_PHOTOS_MAX_ITEMS=10  # itens por escolha no Photos; nunca passa de 10 (limite da bandeja de ideias)
```

### 1.7 Como testar

1. Abra o composer (Criar post) e clique na seta ▾ do botão de mídia.
2. **Google Drive**: aparece no menu → janela de login do Google (abre no clique) → a janela fecha sozinha →
   Picker → escolha **um** arquivo → vira um tile no composer (primeiro "enviando", depois a miniatura).
3. **Google Photos**: com a flag ligada, a mesma janela faz o login e segue para o Photos Picker; marque e
   desmarque à vontade (até `GOOGLE_PHOTOS_MAX_ITEMS`, padrão 10) e confirme com **Concluído**. Cada item vira
   um tile próprio, na ordem escolhida; um item que falhar (ex.: vídeo ainda processando) mostra o erro só no
   tile dele.

### 1.8 Erros comuns

| Sintoma | Causa / correção |
| --- | --- |
| Item não aparece no menu | Flag desligada ou alguma chave `GOOGLE_MEDIA_*` vazia (Photos precisa de id, secret e redirect; Drive também de API key e App ID); rode `php artisan config:clear` |
| `redirect_uri_mismatch` (erro 400 na janela do Google) | `GOOGLE_MEDIA_CLIENT_REDIRECT` diferente do URI cadastrado em **URIs de redirecionamento autorizados** (protocolo, subdomínio, barra final) |
| Console recusa `https://app.trypost.test/...` ("must end with a public top-level domain") | Use uma URL pública (`herd share`), cadastre o callback dela e troque o host de `GOOGLE_MEDIA_CLIENT_REDIRECT` |
| Janela mostra "Não foi possível conectar ao Google" | Client secret errado/vazio (`invalid_client`), ou a janela foi aberta por outro usuário/workspace; confira `GOOGLE_MEDIA_CLIENT_SECRET` |
| Picker abre em branco / `The API developer key is invalid` | API key sem `https://docs.google.com/*` nos referenciadores, ou sem a Google Picker API na restrição |
| Picker mostra "arquivo não pode ser aberto" / 403 no download | `GOOGLE_MEDIA_APP_ID` não é o número **deste** projeto |
| "Este app não foi verificado" no Photos | Escopo sensível sem verificação; use usuários de teste até aprovar |
| `access_denied` | Usuário fora da lista de testes enquanto o app está em *Teste* |

Docs oficiais:
- https://developers.google.com/identity/protocols/oauth2/web-server
- https://developers.google.com/workspace/drive/picker/guides/overview
- https://developers.google.com/workspace/drive/api/guides/api-specific-auth
- https://developers.google.com/photos/picker/guides/get-started-picker
- https://developers.google.com/photos/picker/guides/media-items
- https://developers.google.com/photos/picker/reference/rest/v1/sessions (`maxItemCount`)
- https://support.google.com/cloud/answer/13463073 (verificação)

---

## 2. Canva (Connect API)

Na prática é **só Cloud**: exige uma integração **pública** aprovada pelo Canva. Integração privada só
funciona dentro do seu time e só no plano Enterprise.

### 2.1 Criar a integração

1. Ative **MFA** na conta Canva que vai ser dona da integração (o Developer Portal exige).
2. Acesse https://www.canva.com/developers/integrations/connect-api → **Create an integration** → escolha
   **Public**.
3. **Configuration → Credentials**:
   - copie o **Client ID** → `CANVA_CLIENT_ID`;
   - gere o **Client secret** → `CANVA_CLIENT_SECRET` (aparece **uma vez só**; guarde num cofre).
4. **Scopes**: marque só
   - `design:content:write` (criar design)
   - `design:content:read` (exportar PNG)
   - `design:meta:read` (return navigation e reabrir design com "Editar no Canva")

   Não marque `profile:read`, `asset:*` nem `folder:*`.
5. **Authentication → Redirect URLs** (rota `app.integrations.canva.callback`):
   - `https://app.trypost.it/integrations/canva/callback`
   - local: `https://app.trypost.test/integrations/canva/callback` (se o portal recusar, use
     `http://127.0.0.1:<porta>/integrations/canva/callback`). **Remova as URLs locais antes de enviar para
     revisão.**
6. **Return navigation**: ligue e defina a **Return URL** (rota `app.integrations.canva.return`):
   - `https://app.trypost.it/integrations/canva/return`
   - local: `https://app.trypost.test/integrations/canva/return` (sem isso o Canva responde **400** ao abrir o
     design). Antes de enviar para revisão, deixe a de produção.
7. Nome **TryPost**, ícone, e rode o **Submission checklist** (nome público, redirect não local, nenhuma API
   em preview, guia de marca do Canva: o item do menu se chama "Canva" e usa o logo).
8. **Submit for review**. O Canva abre um ticket (Jira Service Desk) e toda a conversa segue por lá. Não há
   SLA publicado (conte com 1 a 3 semanas). Até aprovar, só membros do time dono conseguem conectar.

### 2.2 Variáveis

```dotenv
# Exemplo FALSO
CANVA_ENABLED=true
CANVA_CLIENT_ID=OC-EXEMPLOfalso123
CANVA_CLIENT_SECRET=cnvcaEXEMPLOfalsoNaoUse0000000000
```

As URLs de callback e retorno **não** vão no `.env`: vêm das rotas nomeadas (`APP_URL` + caminho).

### 2.3 Como testar

1. Composer → seta ▾ do botão de mídia → **Canva ▸** → escolha um tamanho (ex.: Quadrado 1:1).
2. Abre um popup: na primeira vez, login/autorização do Canva; depois, o editor do Canva.
3. Clique **Return to TryPost** no Canva → o popup fecha e o PNG aparece como tile.
4. Passe o mouse no tile → selo **Canva** ("Editar no Canva") reabre o mesmo design e substitui o tile ao
   voltar.

### 2.4 Erros comuns

| Sintoma | Causa / correção |
| --- | --- |
| `invalid_redirect_uri` / redirect mismatch | URL em **Redirect URLs** diferente de `APP_URL` + `/integrations/canva/callback` (protocolo, subdomínio, barra final) |
| Volta do Canva não traz a imagem | Return navigation desligada ou Return URL errada |
| `invalid_scope` / 403 ao exportar | Falta `design:content:read` ou `design:meta:read` |
| "Popup bloqueado" | O navegador bloqueou; o usuário precisa permitir popups para o domínio |
| Outros usuários não conseguem conectar | Integração ainda não aprovada (só o time dono funciona) |
| "Editar no Canva" falha | O design pertence à conta Canva de outra pessoa (comportamento esperado) |

Docs oficiais:
- https://www.canva.dev/docs/connect/
- https://www.canva.dev/docs/connect/authentication/
- https://www.canva.dev/docs/connect/appendix/scopes/
- https://www.canva.dev/docs/connect/return-navigation-guide/
- https://www.canva.dev/docs/connect/creating-integrations/
- https://www.canva.dev/docs/connect/submitting-integrations/
- https://www.canva.dev/docs/connect/submission-checklist/

---

## 3. Unsplash

Já integrado; a variável mantém o nome atual.

### 3.1 Passos

1. Acesse https://unsplash.com/oauth/applications → **New Application**, aceite as API Guidelines, nome
   **TryPost**.
2. Copie a **Access Key** → `UNSPLASH_ACCESS_KEY`. A Secret Key não é usada (`UNSPLASH_SECRET_KEY` pode ficar
   vazia).
3. O app começa em **Demo** (50 requisições/hora), suficiente para testar e para self-host pequeno.
4. Para produção, peça **Production** na página do app. O revisor confere:
   - crédito ao fotógrafo e ao Unsplash com `?utm_source=trypost&utm_medium=referral`;
   - uso direto (hotlink) das URLs de imagem que a API devolve;
   - chamada ao `download_location` quando a foto é usada (download tracking).

   Mande screenshots do modal do Unsplash no composer mostrando o crédito.

```dotenv
# Exemplo FALSO
UNSPLASH_ACCESS_KEY=exemploFALSO_abc123DEF456ghi789
```

### 3.2 Como testar e erros comuns

- Composer → seta ▾ → **Unsplash** → busca → clique numa foto → vira tile com o crédito salvo.
- Item não aparece: `UNSPLASH_ACCESS_KEY` vazia.
- `401 OAuth error: The access token is invalid`: chave errada ou revogada.
- `403 Rate Limit Exceeded`: limite do modo Demo; peça Production.

Docs: https://unsplash.com/documentation · https://help.unsplash.com/en/articles/2511245-unsplash-api-guidelines

---

## 4. HEIC (fotos do iPhone) com Imagick

HEIC/HEIF é aceito quando o PHP tem **Imagick com suporte a HEIC** e `MEDIA_HEIC_CONVERSION=true`. O arquivo é
convertido para JPEG no upload. Sem isso, o upload de `.heic` mostra "Este servidor não converte fotos HEIC.
Exporte como JPG e tente de novo."

### 4.1 Local (Herd)

O Herd já vem com Imagick + HEIC. Confira:

```bash
php -r 'var_dump(extension_loaded("imagick") && in_array("HEIC", Imagick::queryFormats("HEI*")));'
```

`bool(true)` = pronto.

### 4.2 Docker (`docker/Dockerfile`)

Na imagem Alpine, adicione os pacotes e a extensão (o plano de implementação faz essa mudança):

```dockerfile
RUN apk add --no-cache imagemagick imagemagick-heic \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS imagemagick-dev \
 && pecl install imagick \
 && docker-php-ext-enable imagick \
 && apk del .build-deps
```

Depois do build, rode o mesmo `php -r` de 4.1 dentro do container.

### 4.3 Laravel Cloud

1. Confirme que a extensão **imagick** está ativa no ambiente e que `Imagick::queryFormats('HEI*')` lista
   `HEIC` (rode o comando de 4.1 pelo console de comandos do Cloud).
2. Se listar: `MEDIA_HEIC_CONVERSION=true`. Se não: `MEDIA_HEIC_CONVERSION=false` até ter suporte (os usuários
   recebem a mensagem "exporte como JPG").

### 4.4 Como testar

Arraste um `.heic` para o composer: o tile vira uma imagem JPEG normal e o editor (lápis) abre.

---

## 5. Retenção (referência rápida)

| Variável | Padrão | Efeito |
| --- | --- | --- |
| `MEDIA_UPLOAD_RETENTION_HOURS` | `24` | Uploads temporários não usados são apagados depois disso (`media:prune-uploads`) |
| `POST_HISTORY_RETENTION_DAYS` | `730` | Posts publicados mais antigos são apagados com a mídia (`posts:prune-history`). `0`/vazio é recusado |

---

## 6. Checklist final

- [ ] Projeto Google "TryPost Media" com Picker API, Drive API e Photos Picker API
- [ ] OAuth client **Web** com client secret em `GOOGLE_MEDIA_CLIENT_SECRET` e o URI de redirecionamento
      `GOOGLE_MEDIA_CLIENT_REDIRECT` (`/integrations/google/callback`) cadastrado
- [ ] API key restrita a referenciadores (incluindo `https://docs.google.com/*`) e à Google Picker API
- [ ] `GOOGLE_MEDIA_APP_ID` = número do projeto
- [ ] Escopos: `drive.file` e `photospicker.mediaitems.readonly`; verificação enviada antes de ligar Photos
- [ ] Integração Canva **pública**, MFA ativo, 3 escopos, redirect `/integrations/canva/callback`, return
      `/integrations/canva/return`, enviada para revisão
- [ ] Unsplash em Production (crédito + download tracking)
- [ ] Imagick com HEIC no Docker e no Laravel Cloud, ou `MEDIA_HEIC_CONVERSION=false`
- [ ] `php artisan config:clear` e teste de cada item pelo menu ▾ do composer

---

## 7. Deploy deste release (fluxo de criar e agendar post)

Faça na ordem. Os passos 1 e 2 vêm **antes** do deploy, porque o passo 4 apaga arquivos de forma irreversível.

### 7.1 Antes do deploy

1. **Snapshot do storage.** Tire um snapshot do bucket (R2/S3) ou ligue o versionamento. O
   `media:adopt-library` apaga os arquivos que só existiam na biblioteca de mídia, e não há ferramenta de export.
2. **Ensaio da migração da biblioteca.** Numa cópia da produção, rode `php artisan media:adopt-library --dry-run`
   (mostra linhas da biblioteca, referências a copiar, órfãos a apagar, arquivos faltando e posts em Publishing que
   vão adiar) e uma adoção real de um workspace grande, para confirmar que cabe no tempo do job.
3. **Imagem Docker.** Rebuild e publique a imagem com o `docker/Dockerfile` novo (`imagemagick`, `imagemagick-dev`,
   `imagemagick-heic` e `pecl imagick`).
4. **HEIC no Laravel Cloud.** Confirme que o Imagick de lá converte HEIC (seção 4.3). Se não converter, defina
   `MEDIA_HEIC_CONVERSION=false`; o upload de HEIC então é recusado com uma mensagem clara.

### 7.2 Variáveis de ambiente

Todas estão no `.env.example` e no `docker/.env.docker.example`. As obrigatórias para cada fonte estão nas seções
1–3. Novas neste release, com padrão seguro:

| Variável | Padrão | Para quê |
| --- | --- | --- |
| `MEDIA_UPLOAD_RETENTION_HOURS` | `24` | Uploads temporários não usados |
| `POST_HISTORY_RETENTION_DAYS` | `730` | Histórico de posts publicados (2 anos) |
| `MEDIA_HEIC_CONVERSION` | `true` | Converter HEIC/HEIF para JPEG |
| `GOOGLE_MEDIA_CLIENT_SECRET` | vazio | Client secret do OAuth client de mídia (login do Drive/Photos no servidor); obrigatório para as duas fontes |
| `GOOGLE_MEDIA_CLIENT_REDIRECT` | `${APP_URL}/integrations/google/callback` | Tem que bater com o URI cadastrado no Google |
| `GOOGLE_PHOTOS_MAX_ITEMS` | `10` | Itens por escolha no Photos (nunca acima de 10) |
| `GOOGLE_PHOTOS_DOWNLOAD_HOSTS` | `lh3`–`lh6.googleusercontent.com`, `video-downloads.googleusercontent.com` | Confirmar o host real dos vídeos no teste manual; ajustar se for outro |
| `CANVA_CODE_CHALLENGE_METHOD` | `s256` | A doc do Canva se contradiz (`s256`/`S256`); confirmar no primeiro login |
| `CANVA_DOWNLOAD_HOSTS` | `*.canva.com` | Hosts permitidos para baixar a exportação |
| `CANVA_EDITOR_HOSTS` | `*.canva.com` | Hosts permitidos no link "Editar no Canva" |

Depois de mudar variáveis: `php artisan config:clear`.

### 7.3 No deploy (Laravel Cloud)

1. **Comando de deploy, só neste release**, depois das migrations:
   `php artisan release:trypost-2 --force` (a adoção da biblioteca é o passo 3 dele e termina no próprio processo;
   roteiro em [`DEPLOYMENT.md` §3](DEPLOYMENT.md)). O `media:adopt-library` avulso fica em
   `app/Console/Commands/Scripts/` (no Docker ele roda no `docker/entrypoint.sh` a cada boot e não faz nada depois
   que tudo foi migrado).
2. **Filas novas.** Os workers/Horizon precisam consumir `media-imports` e `rss-feeds` (supervisores novos em
   `config/horizon.php`). Em quem usa `queue:work`: `--queue=default,media-imports,rss-feeds,...`. Sem isso, Google
   Drive, Google Photos e Canva nunca importam e os feeds RSS não atualizam.
3. **Scheduler** já agenda sozinho: `media:prune-uploads` (de hora em hora), `posts:prune-history` (diário) e
   `rss-feeds:poll` (a cada 5 min). Só confirme que o scheduler está rodando.

### 7.4 Depois do deploy

1. Se o `release:trypost-2` deixou algum workspace para a fila, acompanhe os jobs `AdoptWorkspaceLibraryJob` no
   Horizon até zerarem. Se algum workspace falhar de vez, aparece um erro no log; rode
   `php artisan release:trypost-2 --force` de novo.
2. O `release:trypost-2` termina com `media:audit` (só leitura); confira que todas as checagens dão 0, ou rode
   `php artisan media:audit` de novo depois que a fila drenar.
3. **Janela até a migração terminar:** duplicar um post ou recuperar um rascunho pode manter a mídia antiga da
   biblioteca até ela ser migrada, e o texto alternativo por IA dá 404 nessas mídias. Some sozinho quando a adoção
   termina.
4. **Proxy:** se o servidor usa `HTTP_PROXY`/`HTTPS_PROXY`, a fixação de IP contra DNS rebinding não vale (quem
   resolve o DNS é o proxy). Vale para self-hosted.

### 7.5 Teste manual com as credenciais reais

- **Google Drive:** escolher um arquivo pelo menu ▾ no composer e no editor de ideias; o seletor do Google por cima
  do composer recebe clique e teclado; testar com VoiceOver e só teclado; primeiro clique depois de recarregar a
  página no Safari (a janela de login não pode ser bloqueada); recusar o escopo mostra o aviso certo; a URL da
  janela nunca carrega o token.
- **Google Photos:** escolher várias fotos e um vídeo, desmarcar um e confirmar com **Concluído**; cada item vira
  um tile na ordem escolhida; confirmar que o Picker para em `GOOGLE_PHOTOS_MAX_ITEMS`; cancelar um tile ainda
  pendente; anotar o host do download do vídeo (ajustar `GOOGLE_PHOTOS_DOWNLOAD_HOSTS` se preciso); fechar a janela sem escolher não adiciona
  nada.
- **Canva:** conectar a conta (confirmar `s256`); criar um design, clicar "Return to TryPost" e ver o bloco virar
  imagem na bandeja; abrir um design existente pelo painel de projetos e voltar; revogar o acesso no Canva e ver a
  reconexão pedida; conferir o host do download da exportação.
- **Editar no Canva:** num rascunho, num post agendado e numa cópia de post já publicado, editar a imagem e salvar;
  um colega com outra conta do Canva recebe o erro claro; testar também num canal com mídia própria e no editor de
  ideias.

### 7.6 Comunicação

- **Changelog + docs.trypost.it + instruções do MCP** (mudanças que quebram):
  - saíram `GET /api/assets`, `GET /api/assets/{media}` e `POST /api/posts/{post}/media/from-asset`;
  - saíram as ferramentas MCP `list-assets-tool`, `get-asset-tool` e `attach-existing-asset-tool`;
  - um item de mídia agora é exatamente um de `upload_token`, `url` ou `id` (+ `alt`/`meta`); snapshots antigos
    (`id` + `path` + `url`) são recusados;
  - `upload_token` é de uso único e expira em 24 h; IDs de mídia são por post;
  - um `id` de mídia antiga da biblioteca falha como ID desconhecido;
  - posts publicados há mais de 2 anos são apagados com a mídia;
  - apagar um post apaga os arquivos dele (TikTok/Instagram às vezes ainda buscam o arquivo logo após publicar);
  - posts agendados com mídia fora das proporções da rede falham na publicação;
  - novos: `POST /api/uploads`, `POST /api/posts/{post}/media/from-upload`, HEIC aceito.
- **E-mail do release:** histórico de 2 anos e fim da biblioteca de mídia (arquivos só da biblioteca foram apagados
  no deploy, sem export).
