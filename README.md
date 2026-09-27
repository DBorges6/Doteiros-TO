# Taverna do Ancient

Dashboard de Dota 2 da galera: ranking, prêmios, duelos 1x1, comparador, heróis e duplas.
Os dados vêm das páginas públicas de visão geral do Dotabuff, e as duplas vêm da API do OpenDota.

**Site:** https://dborges6.github.io/Doteiros-TO/doteiros-TO/

Ou abra `doteiros-TO/index.html` num navegador (ele carrega `doteiros-TO/data/dashboard_data.js`).
No servidor, o dashboard abre na página principal e também em `/doteiros-TO/` (maiúsculas e minúsculas fazem diferença no endereço).
Edite sempre `doteiros-TO/index.html`: o `index.html` da raiz é gerado a partir dele pelo `build_data.py`.
Os dados são atualizados automaticamente todo dia às 10h.

## Atualizar

```bash
python update.py            # coleta tudo, gera os dados e faz commit + push
python update.py --no-push  # só atualiza localmente
```

Requisitos: Python 3, `pip install playwright pillow` e o Google Chrome instalado.
As fontes usadas na prévia (Cinzel e Barlow Condensed, licença OFL) ficam em `data/fonts/`.
O Dotabuff bloqueia requisições diretas, então a coleta usa o Chrome da máquina em modo invisível.

| Arquivo | O que faz |
|---|---|
| `roster.py` | lista de jogadores (apelido e ID) |
| `fetch.py` | baixa as partidas do OpenDota (para as duplas) |
| `scraper/scrape_dotabuff.py` + `scraper/extract.js` | coleta o Dotabuff |
| `build_data.py` | junta tudo e embute as imagens em `doteiros-TO/data/dashboard_data.js` |
| `history.py` | guarda o histórico de partidas (`data/history/matches.json`), a foto diária dos números (`data/history/snapshots/`) e monta as novidades do dia |
| `og_image.py` | gera `og.png`, a prévia que aparece ao mandar o link no WhatsApp |
| `update.py` | roda as etapas acima e publica no GitHub |
| `.htaccess` | no servidor, esconde scripts e dados brutos (só o dashboard fica público) |

Perfis privados no Dotabuff (hoje: Neo, Lestat, Kaus e El Desperuanizador) aparecem só com medalha e registro parcial.
Para aparecer completo, ative "Expor dados públicos de partidas" nas configurações do Dota 2.
