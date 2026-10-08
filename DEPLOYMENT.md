# Deploy da fusão de `post_platforms` em `posts`

Roteiro para levar à produção a branch `feat/merge-post-platforms-into-posts`, na
ordem. Cada post passa a guardar o seu canal e o resultado da publicação na
própria linha de `posts`; a tabela `post_platforms` é copiada para `posts` e
removida no mesmo deploy.

O roteiro do deploy do TryPost 2.0 (`release:trypost-2`) fica na tag `v2.0.0`.
Self-hosted que ainda está na `v1.1.0` **precisa passar por ela antes**: atualizar
para a `v2.0.0`, rodar `php artisan release:trypost-2 --force` e só então
atualizar para esta versão. A primeira migration desta versão para com uma
mensagem explicando isso se encontrar posts com mais de um canal.

Por ser um deploy só (sem releases intermediários), ele roda **em manutenção, com
todas as filas drenadas**: nenhum job antigo, guardando o id de um destino que
deixa de existir, pode sobrar para o código novo.

---

## 1. Antes do deploy

1. **Ensaio numa cópia da produção** (banco e bucket próprios), com o código desta
   branch:
   ```bash
   php artisan migrate --force
   ```
   As quatro migrations novas (`2026_10_08_2229*`) devem terminar sem erro. A
   primeira resolve sozinha os restos do split do 2.0 (destinos desligados que
   nunca publicaram, posts sem canal, `partially_published` com um destino só) e
   **para** se ainda houver: post com mais de um destino, destino desligado que
   publicou, `partially_published` com destino em andamento, post publicado sem
   destino ou destino de outro workspace. A mensagem diz quantos de cada.
   Anote quanto tempo o `migrate` levou (estimativa: 20–60 s para ~20 mil posts).
2. **Se o ensaio parou na primeira migration**, resolva os casos na produção antes
   do deploy (com o código que está no ar):
   ```bash
   php artisan posts:split-legacy-active
   php artisan posts:audit-legacy --strict
   ```
   O que sobrar (alvo preso em revisão, mídia que não copiou) é tratado à mão.
3. **Script de deploy do Forge**: adicione, depois de `$RESTART_QUEUES()`,
   ```bash
   $FORGE_PHP artisan horizon:terminate
   ```
   Sem ele o Horizon reinicia os workers dentro da pasta do release antigo e
   continua rodando o código antigo. Vale para todo deploy, não só este. Confira
   também que `storage/` é compartilhado entre releases (`readlink releases/*/storage`).
4. **Comunicação**: avise os usuários do horário da janela (alguns minutos sem
   publicar; os posts agendados para a janela saem logo depois). Avise quem usa a
   API, o MCP ou webhooks sobre a mudança de contrato (seção 4) **antes** do deploy.

## 2. Deploy, na ordem

1. No release atual:
   ```bash
   php artisan down --retry=60 --secret=<token>
   ```
   Web, Horizon e scheduler param (nenhum agendamento usa `evenInMaintenanceMode`).
2. **Drene todas as filas** com o código antigo (o `--force` processa mesmo em
   manutenção):
   ```bash
   php artisan queue:work redis --queue=social-linkedin,social-linkedin-page,social-x,social-tiktok,social-youtube,social-facebook,social-instagram,social-instagram-facebook,social-threads,social-pinterest,social-bluesky,social-mastodon,social-telegram,social-discord,social-google_business,default,posthog,broadcasts,analytics,webhooks,media-imports,media-adoption,rss-feeds --force --stop-when-empty
   ```
   Confira a lista de filas em `config/horizon.php`. Espere também os jobs com
   atraso (até 10 minutos, os retries de rede indisponível) e rode o comando de
   novo até o Redis não ter nada em `queues:*:delayed` nem `queues:*:reserved`.
   Confirme que não sobrou comando agendado rodando:
   `ps -ef | grep "[a]rtisan" | grep -v horizon`.
3. Deploy pelo Forge, com o build **antes** do `migrate`, para um build quebrado
   falhar antes de qualquer escrita:
   `composer install` → `artisan optimize` → `npm ci && npm run build` →
   `artisan migrate --force` → `$ACTIVATE_RELEASE()` → `$RESTART_QUEUES()` →
   `artisan horizon:terminate` → `artisan reverb:restart`.
4. Teste rápido pelo link secreto, ainda em manutenção: abrir a página de publicar,
   abrir um post enviado e um agendado, salvar um rascunho.
5. ```bash
   php artisan up
   ```

## 3. Depois do deploy

- Horizon sem jobs falhos novos.
- Um post de teste publicado em cada rede conectada no workspace de testes.
- Um webhook de teste (`post.published`) e o email de "publicado".
- Os posts agendados para os minutos da janela saíram.
- `post_platforms` não existe mais e `analytics_publications.post_platform_id` foi
  removida.

### Se algo falhar

- **Antes do `up`** (migration, build ou teste rápido): ou corrija para frente com
  o app em manutenção, ou apague a linha da migration que falhou em `migrations` e
  volte o release; as migrations podem rodar de novo (a cópia é idempotente e limpa
  cópias de destinos que sumiram).
- **Depois do `up`**: só correção para frente. O código novo grava a publicação só
  em `posts`; voltar o código antigo republicaria posts que já saíram.

## 4. Mudança de contrato (API, MCP, webhooks)

Muda de uma vez, sem formato de compatibilidade:

- **Objeto Post** (API `GET/POST/PUT /posts`, tools de post do MCP): sem
  `platforms[]`. No topo: `publish_status`, `social_account`, `platform`,
  `content_type`, `meta`, `platform_url`, `error_message`, `display_name`,
  `display_username`, `display_avatar`.
- **Criar post** (API `POST /posts`, `create-post-tool`): `social_account_id`,
  `content_type` e `meta` no topo. Enviar `platforms` é erro de validação.
- **Editar post** (API `PUT /posts/{id}`, `update-post-tool`): `content_type` e
  `meta` no topo; `platforms` é recusado.
- **Erros de validação**: `meta.*` e `content_type` (antes `platforms.0.*`); nos
  lotes, `destinations.{i}.meta.*`.
- **Status**: `partially_published` deixa de existir; o resultado na rede fica em
  `publish_status` (`pending`, `publishing`, `retrying`, `pending_review`,
  `published`, `failed`, `rejected`).
- **Preview** (API e `preview-post-tool`) e **métricas** (API e
  `get-post-metrics-tool`): um objeto só, sem `platforms[]` nem `post_platform_id`.
- **Analytics**: publicações e relatório trazem `post_id` (sem `post_platform_id`).
- **Repurpose**: cada post do item traz `platform` e `publish_status`.
- **Webhooks**: o payload `post.*` traz os campos do canal no topo (incluindo
  `social_account_id` e `platform_post_id`, sem `error_context`). O evento
  `post.partially_published` foi removido; webhooks que o assinavam passam a
  assinar `post.published` e `post.failed`. Entregas que já estavam na fila e o
  replay de logs antigos reenviam o payload no formato antigo.

Páginas da docs.trypost.it a atualizar: objeto Post e endpoints (create, update,
list, show, preview, attach media), erros de validação, status, Webhooks (payload e
lista de eventos), Repurpose items, métricas do post, publicações e relatório de
Analytics, e a página do MCP.
