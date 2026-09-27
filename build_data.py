"""Monta doteiros-TO/data/dashboard_data.js a partir do Dotabuff (data/dotabuff.json) + OpenDota (data/raw.json).

Imagens (heróis, avatares, medalhas) são baixadas e embutidas em base64 para o
dashboard funcionar offline e dentro do Artifact (que bloqueia imagens externas).
"""
import base64, datetime, io, json, os, re, time, urllib.request
from collections import defaultdict
from PIL import Image

ROOT = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.join(ROOT, "data", "img_cache")
os.makedirs(CACHE, exist_ok=True)

from roster import ROSTER  # apelido, account_id
import history, og_image

NOW = int(time.time())
SITE = "https://www.dborges.tech/"
RANKS = ["Herald", "Guardian", "Crusader", "Archon", "Legend", "Ancient", "Divine", "Immortal"]
ROMAN = {"I": 1, "II": 2, "III": 3, "IV": 4, "V": 5}

db = json.load(open(os.path.join(ROOT, "data", "dotabuff.json"), encoding="utf-8"))
od = json.load(open(os.path.join(ROOT, "data", "raw.json"), encoding="utf-8"))


def fetch(url):
    fn = os.path.join(CACHE, re.sub(r"[^\w.]+", "_", url)[-120:])
    if os.path.exists(fn):
        return open(fn, "rb").read()
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    for _ in range(3):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                data = r.read()
            open(fn, "wb").write(data)
            return data
        except Exception as e:
            print("falhou", url, e)
            time.sleep(2)
    return None


def to_data_uri(data, size, quality=80):
    if not data:
        return None
    im = Image.open(io.BytesIO(data)).convert("RGBA")
    im.thumbnail(size, Image.LANCZOS)
    buf = io.BytesIO()
    im.save(buf, "WEBP", quality=quality, method=6)
    return "data:image/webp;base64," + base64.b64encode(buf.getvalue()).decode()


def slugify(name):
    return re.sub(r"[^a-z0-9]+", "-", name.lower().replace("'", "")).strip("-")


# slug do Dotabuff -> (nome, arquivo de imagem da Valve)
hero_by_slug, hero_by_id = {}, {}
for h in od["heroes"].values():
    short = h["name"].replace("npc_dota_hero_", "")
    info = {"name": h["localized_name"], "short": short, "attr": h["primary_attr"]}
    hero_by_slug[slugify(h["localized_name"])] = info
    hero_by_id[h["id"]] = slugify(h["localized_name"])

num = lambda s: float(str(s).replace(",", "").replace("%", "")) if s not in (None, "") else 0.0

players, needed_heroes = [], set()
for nick, pid in ROSTER:
    d = db[str(pid)]
    private = not d["h"] and not d["r"]
    g = {row[0]: (num(row[1]), num(row[2])) for row in d["g"]}
    # "Outro" aparece em mais de um bloco; guardamos as linhas brutas também
    total = g.get("Estatísticas registradas", (0, 0))[0] + g.get("Nenhuma estatística registrada", (0, 0))[0]
    wins = round(sum(g[k][0] * g[k][1] / 100 for k in ("Estatísticas registradas", "Nenhuma estatística registrada") if k in g))
    if private:  # só o registro do cabeçalho está visível
        total = (d["w"] or 0) + (d["l"] or 0)
        wins = d["w"] or 0
    rank_name, rank_tier, rank_star = d["rk"], 0, 0
    m = re.match(r"(\w+)\s+([IV]+)", d["rk"] or "")
    if m and m.group(1) in RANKS:
        rank_tier, rank_star = RANKS.index(m.group(1)) + 1, ROMAN[m.group(2)]
    roles = {}
    for label, w, lanes in d["roles"]:
        kind = "Suporte" if "SUPORTE" in label.upper() else ("Core" if "CORE" in label.upper() else None)
        roles[kind or ("Core" if any("SUPORTE" in r[0].upper() for r in d["roles"]) else "Suporte")] = {"pct": w, "lanes": lanes}
    lane_tot = defaultdict(float)
    for r in roles.values():
        for ln, lw in r["lanes"]:
            lane_tot[ln] += r["pct"] * lw / 100
    heroes = [dict(zip(["name", "slug", "matches", "wr", "kda", "role", "lane", "last"], h)) for h in d["h"]]
    recent = []
    for r in d["r"]:
        slug, _, lvl, won, ts, lobby, party, mode, dur, k, de, a, icons, bracket, match = r
        parts = [int(x) for x in dur.split(":")]
        secs = parts[-1] + parts[-2] * 60 + (parts[-3] * 3600 if len(parts) == 3 else 0)
        recent.append({"slug": slug, "lvl": lvl, "won": won, "t": ts, "lobby": lobby, "party": party,
                       "mode": mode, "dur": secs, "k": k, "d": de, "a": a, "icons": icons,
                       "bracket": bracket, "match": match})
    for h in heroes:
        needed_heroes.add(h["slug"])
    for r in recent:
        needed_heroes.add(r["slug"])
    players.append({
        "nick": nick, "id": pid, "steam": d["n"], "avatarUrl": d["av"], "private": private,
        "lastMatch": d["lm"], "total": int(total), "wins": int(wins), "losses": int(total - wins),
        "abandons": d["ab"], "rank": rank_name, "rankTier": rank_tier, "rankStar": rank_star,
        "points": d["pts"], "general": d["g"], "roles": roles,
        "lanes": sorted(([k, round(v, 1)] for k, v in lane_tot.items() if v > 0), key=lambda x: -x[1]),
        "heroes": heroes, "heroSample": int(sum(h["matches"] or 0 for h in heroes)), "recent": recent, "aliases": d["al"], "friends": d["fr"], "activity": d["act"],
    })

# ---- Química: partidas em comum (OpenDota: histórico completo; Dotabuff: últimas 15) ----
by_match = defaultdict(dict)  # match_id -> {nick: (radiant|None, won)}
for nick, pid in ROSTER:
    p = od["players"].get(nick) or {}
    for m in p.get("matches") or []:
        rad = m["player_slot"] < 128
        by_match[str(m["match_id"])][nick] = (rad, rad == m["radiant_win"], m["start_time"])
for pl in players:
    for r in pl["recent"]:
        by_match[r["match"]].setdefault(pl["nick"], (None, bool(r["won"]), r["t"]))
pairs = defaultdict(lambda: {"with": 0, "withWin": 0, "vs": 0, "last": 0})
for mid, who in by_match.items():
    names = sorted(who)
    for i in range(len(names)):
        for j in range(i + 1, len(names)):
            a, b = who[names[i]], who[names[j]]
            same = (a[0] == b[0]) if (a[0] is not None and b[0] is not None) else (a[1] == b[1])
            s = pairs[(names[i], names[j])]
            s["last"] = max(s["last"], a[2] or 0)
            if same:
                s["with"] += 1
                s["withWin"] += int(a[1])
            else:
                s["vs"] += 1
chem = [{"a": a, "b": b, **v} for (a, b), v in pairs.items()]
chem.sort(key=lambda x: -x["with"])
print("pares:", [(c["a"], c["b"], c["with"], c["withWin"]) for c in chem[:12]])

# ---- Histórico, fotos diárias e novidades ----
hist, coverage = history.merge_matches(players, od, hero_by_id, NOW)
snaps, prev_snap = history.save_snapshot(players, NOW)
news, news_label = history.build_news(players, hist, prev_snap, NOW)
print("novidades:", [n["nick"] + " " + n["text"] for n in news])
nick_idx = {p["nick"]: i for i, p in enumerate(players)}
recent_hist = [r for r in hist if r["t"] >= NOW - 92 * 86400 and r["slug"]]
MODE_CODE = {"Turbo": "T", "All Pick": "A", "Single Draft": "S"}
hist_rows = [[nick_idx[r["nick"]], r["t"], r["slug"], r["won"], r["k"], r["d"], r["a"], r["dur"],
              MODE_CODE.get(r["mode"], "O"), r["ranked"], r["party"] or 0] for r in recent_hist if r["nick"] in nick_idx]
for r in recent_hist:
    needed_heroes.add(r["slug"])
for n in news:
    if n.get("slug"):
        needed_heroes.add(n["slug"])
for p in players:
    p["coverage"] = coverage.get(p["nick"])
evolution = [{"date": s["date"], "p": {n: [v["total"], v["wins"]] for n, v in s["players"].items()}} for s in snaps]

# ---- Imagens ----
hero_imgs = {}
for slug in sorted(needed_heroes):
    info = hero_by_slug.get(slug)
    if not info:
        print("herói sem mapeamento:", slug)
        continue
    url = f"https://cdn.cloudflare.steamstatic.com/apps/dota2/images/dota_react/heroes/{info['short']}.png"
    hero_imgs[slug] = {"name": info["name"], "attr": info["attr"], "img": to_data_uri(fetch(url), (128, 72), 70)}
avatar_bytes = {}
for pl in players:
    h = pl["avatarUrl"].rsplit("/", 1)[-1]
    avatar_bytes[pl["nick"]] = fetch("https://avatars.steamstatic.com/" + h)
    pl["avatar"] = to_data_uri(avatar_bytes[pl["nick"]], (96, 96), 82)
    del pl["avatarUrl"]
medals = {}
for t in range(1, 9):
    medals[f"t{t}"] = to_data_uri(fetch(f"https://www.opendota.com/assets/images/dota2/rank_icons/rank_icon_{t}.png"), (72, 72), 85)
for s in range(1, 6):
    medals[f"s{s}"] = to_data_uri(fetch(f"https://www.opendota.com/assets/images/dota2/rank_icons/rank_star_{s}.png"), (72, 72), 85)
medals["t0"] = to_data_uri(fetch("https://www.opendota.com/assets/images/dota2/rank_icons/rank_icon_0.png"), (72, 72), 85)

out = {"generatedAt": NOW, "players": players, "heroes": hero_imgs, "medals": medals, "chem": chem,
       "hist": hist_rows, "news": news, "newsLabel": news_label, "evolution": evolution, "site": SITE}
js = "window.DASH = " + json.dumps(out, ensure_ascii=False, separators=(",", ":")) + ";"
open(os.path.join(ROOT, "doteiros-TO", "data", "dashboard_data.js"), "w", encoding="utf-8").write(js)
print("ok", len(js) // 1024, "KB", len(hero_imgs), "heróis")

# ---- Prévia de link (WhatsApp etc.) ----
stamp = datetime.datetime.fromtimestamp(NOW, history.BRT)
ranked_players = sorted((p for p in players if not p["private"] and p["total"] >= 100), key=lambda p: -p["wins"] / p["total"])
podium = [(p["nick"], f"{p['wins'] / p['total'] * 100:.2f}% de vitória".replace(".", ","), avatar_bytes[p["nick"]]) for p in ranked_players[:3]]
og_image.make(podium, "Atualizado em " + stamp.strftime("%d/%m/%Y às %H:%M"),
              "Ranking, prêmios e duelos da galera", os.path.join(ROOT, "og.png"))
leader = ranked_players[0]
leader_wr = f"{leader['wins'] / leader['total'] * 100:.2f}".replace(".", ",")
desc = (f"Líder: {leader['nick']} com {leader_wr}% de vitória. "
        + f"Ranking, prêmios, duelos e duplas da galera. Atualizado em {stamp.strftime('%d/%m/%Y')}.")
meta = "\n".join([
    '<!--META-->',
    '<meta name="description" content="%s">' % desc,
    '<meta property="og:type" content="website">',
    '<meta property="og:site_name" content="Taverna do Ancient">',
    '<meta property="og:title" content="Taverna do Ancient · Dota 2 da galera">',
    '<meta property="og:description" content="%s">' % desc,
    '<meta property="og:url" content="%s">' % SITE,
    '<meta property="og:image" content="%sog.png?v=%s">' % (SITE, stamp.strftime("%Y%m%d%H%M")),
    '<meta property="og:image:width" content="1200">',
    '<meta property="og:image:height" content="630">',
    '<meta name="twitter:card" content="summary_large_image">',
    '<!--/META-->'])
src_path = os.path.join(ROOT, "doteiros-TO", "index.html")
page = open(src_path, encoding="utf-8").read()
page, n_meta = re.subn(r"<!--META-->.*?<!--/META-->", lambda _: meta, page, flags=re.S)
assert n_meta == 1, "marcadores <!--META--> não encontrados em doteiros-TO/index.html"
open(src_path, "w", encoding="utf-8").write(page)
print("prévia gerada: og.png |", desc)

# Cópia do dashboard na raiz do site (página principal do domínio), apontando
# para os mesmos dados de doteiros-TO/. A fonte é sempre doteiros-TO/index.html.
# ?v=... muda a cada atualização, para o navegador não usar o arquivo de dados antigo do cache
page, n_src = re.subn(r'<script src="data/dashboard_data\.js(\?v=\d+)?"', f'<script src="data/dashboard_data.js?v={NOW}"', page)
assert n_src == 1, "caminho dos dados não encontrado em doteiros-TO/index.html"
open(src_path, "w", encoding="utf-8").write(page)
open(os.path.join(ROOT, "index.html"), "w", encoding="utf-8").write(page.replace('<script src="data/', '<script src="doteiros-TO/data/'))
print("index.html da raiz atualizado")
