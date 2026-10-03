# Deploy da branch `codex/independent-social-posts`

Roteiro completo para levar esta branch à produção, na ordem. Todos os comandos, variáveis e
agendamentos citados aqui já existem na branch, incluindo os da importação de
posts externos.

O roteiro detalhado da mídia (snapshot do bucket, Google Drive/Photos, Canva, HEIC,
testes manuais e comunicação) continua em
[`MEDIA_INTEGRATIONS_SETUP.md` §7](MEDIA_INTEGRATIONS_SETUP.md). Este arquivo
referencia aquela seção em vez de repeti-la.

---

## 1. Antes do deploy

> **Snapshot do storage (R2/S3) ou versionamento ligado, sem exceção.** A adoção da
> biblioteca de mídia (passo 3 do `release:trypost-2`) apaga arquivos que só
> existiam na biblioteca, e não há ferramenta de export. Detalhes em
> `MEDIA_INTEGRATIONS_SETUP.md` §7.1.

1. **Snapshot do storage** (acima).
2. **Ensaio numa cópia da produção**, depois do `migrate`:
   ```bash
   php artisan release:trypost-2 --dry-run   # mostra o que cada passo faria, não muda nada
   ```
   > **Nunca rode `--force` numa cópia do banco que aponta para o bucket de
   > produção.** A adoção apaga da cópia as linhas da biblioteca e, em seguida, os
   > arquivos que nenhuma linha *da cópia* usa; os posts de produção ainda usam esses
   > arquivos, e a perda só volta pelo snapshot. Para ensaiar de verdade (saída e
   > tempo), a cópia precisa de **um bucket só dela** (uma cópia do bucket, ou
   > `FILESYSTEM_DISK=local` sem credenciais de produção). Sem isso, só `--dry-run`.
   ```bash
   php artisan release:trypost-2 --force     # só numa cópia com bucket próprio
   ```
   O dry-run mostra quantos posts o passo 1 apagaria e quantos destinos tiraria, a
   tabela do `posts:audit-legacy` (o passo 2 divide os "Editable with multiple
   enabled targets"), o plano da adoção por workspace e a auditoria atual da mídia.
3. **Imagem Docker** reconstruída com o `docker/Dockerfile` novo (Imagick + HEIC).
4. **Variáveis de ambiente** (seção 2).
5. **Conte os posts agendados do Instagram com mais de 5 hashtags** (só leitura):
   ```bash
   php artisan tinker --execute '$tag = "/(?:^|[^\p{L}\p{N}_&#\/])#(?=[\p{N}_]*\p{L})[\p{L}\p{N}_]+/u"; echo App\Models\PostPlatform::query()->where("enabled", true)->whereIn("platform", ["instagram", "instagram-facebook"])->where("content_type", "!=", "instagram_story")->whereHas("post", fn ($post) => $post->where("status", "scheduled"))->with("post:id,content")->get()->filter(fn ($target) => preg_match_all($tag, html_entity_decode(preg_replace("/<[^>]+>/", " ", (string) $target->post->content))) > 5)->count(), PHP_EOL;'
   ```
   Troque `->count()` por `->pluck("post_id")` para ver quais são. O comando roda
   com o código que está em produção hoje (não depende de nada desta branch). O
   limite de 5 hashtags vale só ao salvar (web, API e MCP): esses posts continuam
   publicando, mas quem abrir um deles para editar precisa tirar as hashtags a
   mais antes de salvar. Stories não contam (a legenda não é enviada).

## 2. Variáveis de ambiente

As da mídia estão em `MEDIA_INTEGRATIONS_SETUP.md` §7.2. Novas desta branch:

| Variável | Padrão | Para quê | Status |
| --- | --- | --- | --- |
| `TELEGRAM_DEEP_LINK` | `https://t.me` | Link do bot no fluxo de conectar o Telegram | já existe |
| `EXTERNAL_POSTS_IMPORT_LIMIT` | `50` | Quantos posts mais recentes por canal viram post em Enviados (`0` desliga a importação) | já existe |
| `X_EXTERNAL_POSTS_IMPORT_DAYS` | `30` | No X, importar só os posts dos últimos N dias | já existe |
| `EXTERNAL_POSTS_MATCH_WINDOW_MINUTES` | `120` | Minutos em torno de um post da rede em que um post do TryPost com o mesmo texto é tratado como esse post, em vez de importar uma cópia | nova |
| `EXTERNAL_POSTS_MEDIA_REQUESTS_PER_MINUTE` | `30` | Chamadas por minuto, por rede, para buscar as mídias dos posts importados (orçamento separado do Analytics) | nova |
| `PUBLICATION_DISCOVERY_INTERVAL_HOURS` | `3` | Intervalo da busca de posts novos nas redes | já existe |
| `HORIZON_MEDIA_ADOPTION_PROCESSES` | `20` | Processos do supervisor `media-adoption` em produção, que adota a biblioteca em paralelo no passo 3 do release | nova |
| `X_PUBLICATION_DISCOVERY_INTERVAL_HOURS` | `24` | Mesmo intervalo, só para o X (cobra por leitura) | já existe |
| `PUBLICATION_DISCOVERY_OVERLAP_HOURS` | `6` | Margem para trás em cada busca, para não perder post atrasado | já existe |

Depois de mudar variáveis:

```bash
php artisan config:clear
```

## 3. Deploy, na ordem

1. **Deploy** normal. Ele roda `php artisan migrate --force` (esquema: grupos de
   posts, recorrência, origem do post, aprovações etc.).
   - `widen_content_on_posts_table`: `posts.content` vira `mediumText` para caber o post longo do X (25000 caracteres).
     - **PostgreSQL** (Cloud): não reescreve a tabela; é instantâneo.
     - **MySQL** (self-hosted): `TEXT` → `MEDIUMTEXT` **recria a tabela `posts`**
       (`ALGORITHM=COPY`), e as escritas em `posts` ficam bloqueadas enquanto ela é
       copiada. Numa tabela grande, planeje uma janela de manutenção e meça antes o
       tempo numa cópia do banco com o mesmo tamanho.
     - O `down()` falha (ou corta o texto) depois que algum post passar de 64 KB.
   - `add_thread_reply_ids_to_post_platforms_table`: coluna JSON nula
     (`thread_reply_ids`), instantânea nos dois bancos.
2. **Passos únicos do release**, num comando só:
   ```bash
   php artisan release:trypost-2 --force
   ```
3. **Acompanhe o Horizon** até as filas `analytics` e `media-imports` drenarem
   (backfill do Analytics, importação dos posts externos e das mídias deles).
4. **Checagens pós-deploy** (seção 6).

O `release:trypost-2` para com erro, sem fazer nada, se houver migration pendente.
Roda os passos nesta ordem, imprime a saída de cada um e termina com uma tabela de
resumo:

| # | Passo | Comando reaproveitado |
| --- | --- | --- |
| 1 | Apaga os posts que ficaram sem canal por desconexões antigas (agora desconectar apaga os posts do canal); num post com outro canal vivo, só tira o destino morto | `posts:purge-orphaned` |
| 2 | Divide posts antigos com várias redes em um post por rede, ligados pelo mesmo `post_group_id`: rascunhos e agendados, e também os já terminados (publicado, parcialmente publicado, falhou). Cada post terminado fica com o status do próprio destino (publicado → `published`, falhou/rejeitado → `failed`); `partially_published` deixa de existir | `posts:split-legacy-active` |
| 3 | Biblioteca de mídia antiga → mídia por post e por ideia: um `AdoptWorkspaceLibraryJob` por workspace na fila `media-adoption` (em paralelo no Horizon), e o comando **espera** até terminar | `media:adopt-library` |
| 4 | Backfill do Analytics + importação dos posts externos (assíncrono, seção 5) | `analytics:backfill-existing` |
| 5 | Busca imediata dos stories do Instagram (assíncrono) | `analytics:dispatch-publication-discovery --platform=instagram --platform=instagram-facebook` |
| 6 | Auditoria da mídia (só leitura), comparada com a de antes do release | `AuditMedia` |

- **A ordem importa.** Purgar antes de dividir: um post com um destino num canal
  apagado e outro num canal vivo perde só o destino morto e mantém o id (dividido
  antes, a metade morta podia levar o id original junto). Purgar antes de adotar
  evita copiar mídia de posts que vão ser apagados. A divisão dá a cada post novo
  as próprias mídias (arquivo e linha), antes ou depois da adoção, então a adoção
  no boot do Docker não atrapalha.
- **Como a divisão trata o histórico.** O destino (`post_platforms`) é **movido**
  para o post novo, nunca copiado: link do post na rede, erro, `published_at`,
  métricas e o vínculo do Analytics (`analytics_publications.post_platform_id`)
  continuam no mesmo registro. Cada post novo ganha o mesmo texto, autor, origem,
  datas, etiquetas, notas e as próprias mídias. Destinos desligados não viram post
  e ficam no post original, como histórico de um destino que nunca rodou. Posts em
  `publishing` e posts terminados com algum destino ainda em andamento (ex.: Google
  Business em revisão) ficam como estão; o comando diz quantos. Um post
  `partially_published` que ficou com um só destino (o outro era de um canal
  apagado e saiu no passo 1) passa a ter o status desse destino. Nada disso dispara
  e-mail, webhook ou evento.
- **Rodar de novo é seguro:** cada passo pula o que já foi feito. Se um passo
  falhar, o comando segue para os outros, mostra o resumo e sai com código
  diferente de 0; corrija e rode de novo.
- **O backfill (passo 4) é despachado uma vez só.** Os jobs dele podem ficar horas
  esperando por causa do `--backfill-delay`; despachar de novo antes deles rodarem
  leria cada conta duas vezes (o X cobra por leitura). O comando guarda no cache
  quando despachou (na tabela `cache` do banco, não no Redis: sobrevive a um flush
  e viaja junto com um snapshot do banco) e, nas próximas rodadas, pula o passo
  ("Skipped: already dispatched at …"). Para despachar de novo de propósito:
  `--rerun-backfill`.
- **Adoção (passo 3).** O comando enfileira um job por workspace com biblioteca na
  fila `media-adoption` e fica esperando, com uma linha de progresso a cada 15s
  (`N/M workspace(s) done, X library row(s) left, Y stopped`). Só segue para os
  passos 4–6 quando todos terminam ou quando passa o `--adoption-timeout` (padrão
  `21600`, 6h). Dentro de um workspace a cópia é sequencial (um job por workspace:
  a biblioteca só é apagada depois de todas as cópias, e dividir um workspace em
  vários jobs quebraria essa garantia). Um workspace com post em `Publishing` é
  tentado de novo pelo próprio job (a cada 5 min, por até 6h). Ao fim da espera:
  - ainda rodando: fica para o job, que continua sozinho; o resumo diz quantos;
  - parado com biblioteca sobrando (o job desistiu, ou os arquivos não foram
    achados no disco): o passo falha, o resumo lista os workspaces e o comando sai
    com código diferente de 0; veja o log, corrija e rode
    `php artisan media:adopt-library --force`.

  Rodar o release de novo com jobs ainda na fila não duplica nada (job único por
  workspace) e volta a esperar.
- **Tempo da adoção.** Cada cópia no R2 levou ~1,9s na cópia de produção
  (sequencial). Tempo total ≈ cópias × 1,9s ÷ processos do supervisor
  `media-adoption`, limitado pelo maior workspace (um workspace roda num processo
  só). Exemplo: ~14,6 mil cópias com 20 processos ≈ 14.600 × 1,9 ÷ 20 ≈ 23 min.
  No release, deixe `HORIZON_MEDIA_ADOPTION_PROCESSES` em 20 (padrão em produção;
  dá para subir para 30–40 se o servidor aguentar) e reinicie o Horizon
  (`php artisan horizon:terminate`) **antes** de rodar o comando, para o supervisor
  novo existir.
- **Opções repassadas ao backfill:** `--backfill-chunk=50 --backfill-delay=300`
  (contas por lote e segundos entre lotes; padrão `100` e `0`) e
  `--include-unsubscribed`.
- **Como ler a auditoria (passo 6).** Antes do passo 1 o comando tira uma foto
  rápida (só banco e a listagem do bucket, sem conferir arquivo por arquivo, então
  não atrasa os passos de dados). No fim, depois dos passos de dados, roda a
  auditoria completa, que confere o arquivo de cada mídia no bucket: conte com
  **uns 10 a 30 minutos** em produção (um pedido ao R2 por mídia; o resumo mostra o
  tempo). Ela mostra por checagem quantos achados são **esperados** e quantos são
  **inesperados**. Esperados:
  - os que já estavam na foto de antes (sujeira antiga, não do release);
  - itens de post que apontam para uma linha da biblioteca que ficou (usada por
    post de outro workspace, ou de um workspace que ficou para a fila);
  - itens que apontam para uma mídia que não existe mais, **só** se ela já era da
    biblioteca ou já estava sumida antes do release;
  - arquivos sem linha que tinham linha antes do release (exclusão na fila do
    `DeleteMediaFiles`), e mídias de antes do release sem arquivo (a foto não
    confere arquivos).

  Qualquer outra coisa, como uma mídia ainda usada por um post que o release
  apagou, é **inesperada**: aparece uma por linha (`unexpected json_drift: … media_id=…`)
  e faz o comando sair com código diferente de 0.

Migrations que entram nesta branch (entre outras):

- `add_post_group_id_to_posts_table`: posts criados juntos compartilham um grupo.
- `add_recurrence_to_posts_table` e `add_recurrence_origin_at_to_posts_table`: posts recorrentes.
- `add_origin_to_posts_table`, `change_platform_url_to_text_on_post_platforms_table` e `add_post_dismissed_at_to_analytics_publications_table`: origem do post (`trypost` | `network`), permalinks longos e posts importados apagados pelo usuário (não voltam).
- `add_permissions_to_user_workspace_and_invites_tables` → `drop_role_from_user_workspace_and_invites_tables`:
  os papéis Admin / Member / Viewer viram duas flags por membro e por convite
  (`is_admin`, `requires_approval`). Admin → admin; Member → publica direto;
  Viewer → precisa de aprovação; o dono da conta vira admin. Rodam juntas, nessa
  ordem; a segunda apaga a coluna `role`.
- `add_approval_columns_to_posts_table`: `approval_requested_at`, `approved_by`,
  `approved_at`, `approval_queue_position` e o novo status `pending_approval`.
- `add_approval_requested_by_to_posts_table`: `approval_requested_by`, quem pediu
  a aprovação (pode não ser o autor: um membro que edita um post já aprovado de
  outra pessoa). Vira `null` se o usuário for apagado; aí vale o autor do post.
- `add_collaboration_to_notification_preferences_table`: preferência
  "Colaboração" (ligada por padrão).
- `flatten_replies_and_drop_reactions_from_post_notes_table`: notas ficam
  simples (sem respostas nem reações). Respostas viram notas normais do mesmo
  post, na ordem em que foram criadas; as colunas `parent_id` e `reactions` são
  apagadas (reações somem).
- `fold_legacy_workspace_media_into_library`: as imagens de IA antigas (coleção
  `ai-generated`, algumas gravadas com `App\Models\Workspace` em vez do alias
  `workspace`) entram na biblioteca, para a adoção copiá-las para os posts que as
  usam e apagar as que nenhum post usa. Linhas `assets` gravadas com o nome da
  classe também passam a ser adotadas. Linhas de outras coleções (ex.: `logo`) e de
  workspaces que não existem mais ficam como estão.
- `make_time_format_required_on_users_table`: `users.time_format` passa a ser
  obrigatório (12h ou 24h; acaba o "seguir o idioma"). Quem estava sem formato
  recebe o que já via: `12h` em inglês, `24h` nos outros idiomas. Ninguém percebe
  mudança.

Sem migração de dados para a fila: posts já na fila mantêm os horários que têm
(a fila não é mais compactada nos primeiros horários livres), então nada muda
para quem já tem posts enfileirados.

## 4. Filas e scheduler

Os workers/Horizon precisam consumir todas estas filas (`config/horizon.php`):

| Fila | Para quê |
| --- | --- |
| `default`, `posthog`, `broadcasts` | App em geral |
| `webhooks` | Webhooks de saída |
| `analytics` | Analytics e, com a importação, a criação dos posts externos |
| `media-imports` | Google Drive, Photos, Canva e, com a importação, as mídias dos posts externos |
| `media-adoption` | Adoção da biblioteca de mídia (só neste release). Supervisor próprio no Horizon; em produção até `HORIZON_MEDIA_ADOPTION_PROCESSES` processos (padrão 20) |
| `rss-feeds` | Feeds RSS |

Com `queue:work` em vez de Horizon:

```bash
php artisan queue:work --queue=default,posthog,broadcasts,webhooks,analytics,media-imports,media-adoption,rss-feeds
```

Agendamentos (o scheduler já cuida, só confirme que ele está rodando):

| Quando (UTC) | O quê | Status |
| --- | --- | --- |
| a cada 3h (X: a cada 24h, às 00:00) | Busca posts novos nas redes: importa para Enviados e Analytics | já existe |
| 02:00 | Snapshot de seguidores | já existe |
| 03:00 | Atualização das métricas dos últimos 30 dias (X: 20) | já existe |
| 23:30 / 00:30 | Fechamento do snapshot do dia / recuperação do dia anterior | já existe |
| de hora em hora | `media:prune-uploads` | já existe |
| diário | `posts:prune-history` | já existe |
| a cada 5 min | `rss-feeds:poll`, `repurposes:poll` | já existe |

## 5. Backfill do Analytics + importação dos posts externos

É o passo 4 do `release:trypost-2`, depois da divisão dos posts e da adoção da biblioteca. A ordem importa: o
backfill primeiro liga os posts que o TryPost já publicou às publicações do
Analytics e só então importa os posts feitos direto nas redes. Ao contrário, um
post do TryPost viraria um "importado" duplicado.

O comando despacha tudo e termina, não fica parado esperando. O que acontece por
conta:

1. O Analytics busca o histórico de **365 dias** (no X, no máximo 3.200 posts).
2. Os **50 posts mais recentes** de cada canal (no X, os dos **últimos 30 dias**)
   viram post em Enviados e no calendário, com origem `network`.
3. As mídias completas desses posts (carrossel, vídeo, capa) são baixadas para o
   bucket na fila `media-imports`.
4. As métricas são buscadas pelo Analytics.
5. No Instagram, os **stories** entram também, mas só os que estão no ar (últimas
   24 horas): a API não devolve stories mais antigos. Eles têm um limite próprio
   de 50, separado de feed e reels. Para contas que já tinham feito o backfill, o
   passo 5 do release traz os que estão no ar na hora; depois disso a busca de
   posts novos (a cada 3h) cuida deles.

Rodar de novo é seguro: nenhum post é criado em dobro, e mídias já baixadas não são
baixadas outra vez.

O resumo do backfill tem uma linha por contador:

- `accounts_dispatched`: contas que tiveram o backfill despachado.
- `publications_to_link`: publicações do TryPost sem registro no Analytics, que o comando liga a ele.
- `posts_imported`: posts importados que existem agora. É a contagem atual; a
  importação é assíncrona, então o número cresce enquanto as filas drenam.
- `historical_identity_unrecoverable`: publicações antigas sem canal associado, cuja
  identidade não dá para recuperar e que por isso ficam fora do Analytics.

## 6. Depois do deploy

- A auditoria da mídia já roda no fim do `release:trypost-2` (esperados ×
  inesperados, seção 3). Se algum workspace ficou para a fila, rode o
  `release:trypost-2 --force` de novo quando os `AdoptWorkspaceLibraryJob` zerarem
  (o backfill não é despachado de novo).
- Confira em alguns canais que Enviados e o calendário mostram os posts importados,
  com mídia e métricas.
- **Limite do X por conta.** Contas do X com assinatura paga (Basic, Premium,
  PremiumPlus) ou verificadas como empresa passam a ter 25000 caracteres. O plano
  de cada conta é lido na próxima verificação da conexão (diária) ou ao reconectar;
  até lá, a conta segue com 280. Para valer na hora:
  `php artisan social:check-connections` (opcional).
- **Título do YouTube.** Sem título preenchido, o título é a primeira linha não
  vazia do texto (sem `<` e `>`, até 100 caracteres). Ele não ganha mais ` #Shorts`
  no fim e não é mais cortado no primeiro `.`.

### Canais desconectados

- Rodar `php artisan posts:purge-orphaned` de novo deve imprimir `0 orphaned post(s) deleted, 0 orphaned target(s) removed from posts on other channels.`
- Desconectar um canal apaga todos os posts dele, sem disparar o webhook `post.deleted`
  para cada post; reconectar a mesma conta importa de novo os posts recentes.

### Aprovação de posts

- Em Configurações → Membros, confira que quem era Viewer aparece como
  "Precisa de aprovação" e quem era Admin aparece como Admin.
- Com um membro que precisa de aprovação: crie um post na fila e confira que ele
  aparece na aba Aprovações (e não na Fila), que o e-mail chega para os
  aprovadores e que "Adicionar à fila" coloca o post no próximo horário.
- `posts:process-scheduled` nunca publica `pending_approval`; nada a configurar.
- Em uma automação de repurpose criada por um membro que precisa de aprovação, o
  item continua marcado como Publicado enquanto os posts dele aguardam aprovação:
  o item representa o envio à fila, não a publicação nas redes.

## 7. Se precisar voltar atrás

Apagar só os posts importados (origem `network`) e as mídias deles. O Analytics fica
intacto: as publicações apenas voltam a ficar sem ligação.

Antes, defina `EXTERNAL_POSTS_IMPORT_LIMIT=0` e rode `php artisan config:clear`;
sem isso a próxima busca importa os posts de novo.

```bash
php artisan posts:purge-imported            # já existe
php artisan posts:purge-imported --workspace=<uuid>
```

### Aprovação de posts: papéis

O `migrate:rollback` das migrations de papéis recria a coluna `role` em
`user_workspace` e em `invites` e preenche pelas flags: `is_admin` →
Admin; `requires_approval` → Viewer; os demais → Member. O dono da conta volta
como Admin. Posts em `pending_approval` não existem no esquema antigo: antes de
voltar atrás, aprove ou rejeite todos (ou mova-os para rascunho), senão ficam com
um status desconhecido.

As outras migrations da aprovação também voltam atrás sem perda fora delas:

- `add_approval_requested_by_to_posts_table`: o `down()` apaga a chave
  estrangeira e a coluna `approval_requested_by`.
- `add_approval_columns_to_posts_table`: o `down()` apaga `approved_by` (com a
  chave estrangeira), `approval_requested_at`, `approved_at` e
  `approval_queue_position`. Quem aprovou e quando se perde.
- `add_collaboration_to_notification_preferences_table`: o `down()` apaga a
  preferência `collaboration`; os e-mails de aprovação deixam de existir junto com
  o código.

## 8. Teste manual antes do release

- **X:** uma sincronização real com uma conta de verdade, conferindo os campos
  `note_tweet` (texto longo) e as variantes de mídia.
- **Threads:** um carrossel, conferindo a expansão de `children{}`.
- **Pinterest:** um pin, conferindo a imagem 1200x e o `video_url`.

## 9. Comunicação

Changelog, docs.trypost.it, instruções do MCP e e-mail do release:
`MEDIA_INTEGRATIONS_SETUP.md` §7.6. Somar a isso:

- Enviados e o calendário passam a mostrar também os posts feitos direto nas redes
  (últimos 50 por canal; no X, último mês).
- A API e o MCP ganham o campo `origin` (`trypost` | `network`) nos posts.
- Fuso horário: quem já tinha conta fica em **UTC** (a produção nunca guardou fuso), e os
  canais existentes ganham a grade de horários em UTC. Isso é intencional: no changelog e no
  e-mail do release, peça para cada um conferir o fuso em Configurações → Preferências e o fuso
  de cada canal em Configurações do canal. Cadastros novos já detectam o fuso do navegador.

Aprovação de posts (mudança que quebra compatibilidade para quem lê o status):

- Novo status `pending_approval` em posts na API e no MCP. Posts criados ou
  editados por um membro que precisa de aprovação terminam nesse status em vez de
  `scheduled` / `publishing`.
- Novos endpoints `POST /api/posts/{post}/approve` (aceita `scheduled_at` ou
  `publish_now`) e `POST /api/posts/{post}/reject`; novas tools MCP
  `approve-post-tool` e `reject-post-tool`; `list-posts-tool` filtra
  `pending_approval`.
- Os papéis Admin / Member / Viewer deixam de existir: a API e o MCP não expõem
  membros nem convites, então só a documentação e o changelog mudam.
- Atualizar docs.trypost.it (membros e aprovações, API de posts) e o changelog.

## 10. Comandos avulsos (opcional/manual)

Os comandos que o `release:trypost-2` usa, e os de diagnóstico e de reversão deste
release, ficam em `app/Console/Commands/Scripts/`. Só servem para rodar um passo
isolado, ensaiar ou investigar; o deploy normal não precisa deles.

```bash
php artisan posts:audit-legacy [--strict]          # contagens dos posts antigos, só leitura
php artisan posts:purge-orphaned [--workspace=<uuid>]   # passo 1
php artisan posts:split-legacy-active              # passo 2
php artisan media:adopt-library --dry-run          # o que a adoção faria, por workspace
php artisan media:adopt-library --force            # adoção pela fila (AdoptWorkspaceLibraryJob), em vez do passo 3
php artisan analytics:backfill-existing [--workspace=<uuid>] [--include-unsubscribed] [--platforms=instagram,facebook] [--chunk=100] [--delay=0]   # passo 4
php artisan media:audit [--json]                   # passo 6
php artisan posts:purge-imported [--workspace=<uuid>]   # reversão (seção 7)
```

Exemplos do backfill isolado:

```bash
# Um workspace só (bom para testar primeiro)
php artisan analytics:backfill-existing --workspace=<uuid>

# Só algumas redes (ex.: deixar o X, que cobra por leitura, para depois)
php artisan analytics:backfill-existing --platforms=instagram,instagram-facebook,facebook,threads
php artisan analytics:backfill-existing --platforms=x --chunk=10 --delay=600
```

`analytics:dispatch-publication-discovery` (passo 5) não é avulso: o scheduler usa,
então fica em `app/Console/Commands/Analytics/`.

**Depois do release, a pasta `app/Console/Commands/Scripts/` pode ser apagada
inteira** (com os testes dos comandos dela e as menções em
`docker/entrypoint.sh`, `README.md` e `MEDIA_INTEGRATIONS_SETUP.md`), na mesma
limpeza da issue #376 (fallback do `PostAtRisk`).
