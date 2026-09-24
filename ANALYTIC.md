# Analytics por rede social

Este documento descreve as métricas que o TryPost consegue consultar atualmente por conta e por publicação.

> Este é um inventário do comportamento atual, anterior ao novo módulo. O desenho aprovado está em `docs/superpowers/specs/2026-09-23-workspace-follower-analytics-design.md` e o plano executável está em `docs/superpowers/plans/2026-09-23-workspace-analytics-backfill.md`. Na V1 nova, LinkedIn, Telegram, Discord e Google Business Profile ficam fora de todas as superfícies de analytics; LinkedIn fica planejado para V2.

| Rede / integração | Métricas por conta | Métricas por publicação | Observações |
| --- | --- | --- | --- |
| TikTok | Seguidores; seguindo; curtidas totais; quantidade de vídeos; visualizações, curtidas, comentários e compartilhamentos agregados dos vídeos recentes | Visualizações; curtidas; comentários; compartilhamentos | O agregado da conta considera os 20 vídeos mais recentes. O seletor de período não é aplicado a essa consulta. Posts privados podem não fornecer um ID público consultável. |
| Instagram (conexão direta ou via Facebook) | Alcance; seguidores; curtidas; comentários; compartilhamentos; salvamentos; visualizações; interações | **Feed:** alcance, curtidas, comentários, compartilhamentos, salvamentos e interações.<br>**Reel:** alcance, curtidas, comentários, compartilhamentos, salvamentos e visualizações.<br>**Story:** alcance, visualizações e respostas. | Disponível no painel por conta e no detalhe da publicação. |
| Threads | Visualizações; curtidas; respostas; reposts; citações | Visualizações; curtidas; respostas; reposts; citações | Disponível no painel por conta e no detalhe da publicação. |
| Facebook Page | Alcance da página; alcance dos posts; engajamento dos posts; novos seguidores; visualizações da página | **Feed:** impressões, alcance, curtidas e cliques.<br>**Story:** impressões, alcance, interações, reações, respostas e compartilhamentos.<br>**Vídeo/Reel:** reproduções, reações e interações. | As métricas disponíveis dependem do tipo e do identificador da publicação. |
| X | Impressões; curtidas; reposts; respostas; citações; bookmarks | Impressões; curtidas; reposts; respostas; citações; bookmarks | A consulta da conta soma as métricas dos posts encontrados no período, com limite de 100 dias e de cinco páginas de resultados. |
| LinkedIn — perfil pessoal | Não disponível no painel por conta | Curtidas; comentários | A API usada pela integração de perfil pessoal não fornece ao TryPost o conjunto completo de analytics disponível para páginas. |
| LinkedIn — página de empresa | Visualizações da página; novos seguidores orgânicos; novos seguidores pagos; impressões; cliques; curtidas; comentários; compartilhamentos | Impressões; cliques; curtidas; comentários; compartilhamentos | Métricas com valor zero podem ser omitidas no painel por conta. |
| Pinterest | Impressões; cliques no Pin; engajamentos; salvamentos; taxa média de clique | Impressões; salvamentos; cliques no Pin; cliques externos; visualizações de vídeo | A consulta por publicação usa uma janela fixa dos últimos 90 dias. |
| YouTube Shorts | Visualizações; minutos assistidos; duração média da visualização; percentual médio assistido; inscritos ganhos; inscritos perdidos; curtidas | Visualizações; minutos assistidos; duração média da visualização; curtidas; comentários; compartilhamentos | As métricas da publicação são consultadas desde a data de publicação até o dia atual. |
| Telegram | Número de inscritos do canal | Número de inscritos do canal; reações separadas por emoji | A Bot API não fornece visualizações das mensagens para bots. As reações são recebidas pelo webhook e armazenadas nos metadados da publicação. |
| Bluesky | Não disponível no painel por conta | Curtidas; reposts; citações; respostas | Atualmente existe apenas analytics por publicação. |
| Mastodon | Não disponível no painel por conta | Favoritos; boosts/reblogs; respostas | Atualmente existe apenas analytics por publicação. |
| Discord | Quantidade aproximada de membros do servidor, disponível no serviço interno, mas ainda não exibida no painel geral | Quantidade aproximada de membros; reações separadas por emoji; respostas na thread | O Discord não fornece impressões, alcance ou visualizações para mensagens de bot. |
| Google Business Profile | Impressões no Search em desktop; impressões no Search em mobile; impressões no Maps em desktop; impressões no Maps em mobile; cliques no site; cliques para ligar; solicitações de rota; conversas; palavras-chave de busca; quando aplicável, agendamentos, pedidos de comida e cliques no cardápio | Não disponível | Palavras-chave são agregadas mensalmente. Contagens de termos com baixo volume podem ser estimadas. Agendamentos e métricas de comida com valor zero são ocultados. |

## Disponibilidade atual

O painel geral de analytics permite selecionar contas de TikTok, Instagram, Threads, Facebook, X, LinkedIn Page, Pinterest, YouTube, Telegram e Google Business Profile.

LinkedIn pessoal, Bluesky e Mastodon possuem apenas métricas por publicação. O Discord também possui métricas implementadas por publicação e uma métrica de conta, mas ainda não aparece no painel geral.

As métricas por publicação só são consultadas quando a publicação está com status `published` e possui um identificador retornado pela plataforma. Esses resultados ficam em cache por cinco minutos. As métricas do painel por conta usam, em geral, o período selecionado e ficam em cache por uma hora em produção.
