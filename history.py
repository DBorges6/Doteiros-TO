"""Histórico acumulado de partidas, fotos diárias dos números e as novidades do dia.

- data/history/matches.json: todas as partidas conhecidas dos últimos 400 dias.
  Junta o histórico completo do OpenDota (quem tem perfil público lá) com as
  15 partidas recentes do Dotabuff de cada coleta diária. Nunca apaga partidas
  antigas só porque uma fonte falhou num dia.
- data/history/snapshots/AAAA-MM-DD.json: números de carreira de cada jogador no
  dia (uma foto por dia; a última coleta do dia sobrescreve). Alimenta o gráfico
  de evolução e a comparação "desde a última atualização".
"""
import datetime, glob, json, os

ROOT = os.path.dirname(os.path.abspath(__file__))
HDIR = os.path.join(ROOT, "data", "history")
SNAP_DIR = os.path.join(HDIR, "snapshots")
MATCHES = os.path.join(HDIR, "matches.json")
KEEP_DAYS = 400
BRT = datetime.timezone(datetime.timedelta(hours=-3))  # horário de Brasília (sem horário de verão)
GAME_MODES = {1: "All Pick", 2: "Captains Mode", 3: "Random Draft", 4: "Single Draft", 5: "All Random",
              12: "Least Played", 16: "Captains Draft", 18: "Ability Draft", 22: "All Pick", 23: "Turbo"}
FIELDS = ["nick", "match", "t", "slug", "won", "k", "d", "a", "dur", "mode", "ranked", "party", "src"]


def merge_matches(players, od, hero_by_id, now):
    """Atualiza e devolve o histórico de partidas (lista de dicts) e a cobertura por jogador."""
    os.makedirs(HDIR, exist_ok=True)
    rows = {}
    if os.path.exists(MATCHES):
        saved = json.load(open(MATCHES, encoding="utf-8"))
        for r in saved["rows"]:
            d = dict(zip(FIELDS, r))
            if d["src"] == "d" and not saved.get("tstart"):
                d["t"] -= (d["dur"] or 0) + 90  # arquivos antigos guardavam o fim da partida (padrão do Dotabuff)
            rows[(d["nick"], d["match"])] = d
    before = len(rows)
    for nick, p in (od.get("players") or {}).items():
        for m in p.get("matches") or []:
            if not m.get("start_time") or m.get("hero_id") is None:
                continue
            rad = m["player_slot"] < 128
            key = (nick, str(m["match_id"]))
            if key in rows and rows[key]["src"] == "d":
                continue  # o registro do Dotabuff tem modo e grupo mais confiáveis
            rows[key] = {"nick": nick, "match": str(m["match_id"]), "t": m["start_time"],
                         "slug": hero_by_id.get(m["hero_id"]), "won": int(rad == m["radiant_win"]),
                         "k": m["kills"], "d": m["deaths"], "a": m["assists"], "dur": m["duration"],
                         "mode": GAME_MODES.get(m.get("game_mode"), "Outro"), "ranked": int(m.get("lobby_type") == 7),
                         "party": m.get("party_size"), "src": "o"}
    for p in players:
        for r in p["recent"]:
            # O Dotabuff marca o fim da partida (início + duração + 90 s); o OpenDota, o início.
            # Guardamos sempre o início, preferindo o horário exato do OpenDota quando existir.
            key = (p["nick"], r["match"])
            start = rows[key]["t"] if key in rows and rows[key]["src"] == "o" else r["t"] - (r["dur"] or 0) - 90
            rows[key] = {"nick": p["nick"], "match": r["match"], "t": start, "slug": r["slug"],
                                             "won": int(r["won"]), "k": r["k"], "d": r["d"], "a": r["a"], "dur": r["dur"],
                                             "mode": r["mode"], "ranked": int(r["lobby"] == "Classificado"),
                                             "party": r["party"], "src": "d"}
    cutoff = now - KEEP_DAYS * 86400
    kept = sorted((r for r in rows.values() if r["t"] and r["t"] >= cutoff), key=lambda r: -r["t"])
    json.dump({"updated": now, "tstart": True, "fields": FIELDS, "rows": [[r[f] for f in FIELDS] for r in kept]},
              open(MATCHES, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
    print(f"histórico: {len(kept)} partidas ({len(kept) - before:+d} desde a última vez)")
    # Cobertura: com OpenDota o histórico é completo; só com Dotabuff, vale a partir
    # da partida mais antiga que já coletamos.
    has_od = {n for n, p in (od.get("players") or {}).items() if p.get("matches")}
    coverage = {}
    for p in players:
        mine = [r["t"] for r in kept if r["nick"] == p["nick"]]
        coverage[p["nick"]] = 0 if p["nick"] in has_od else (min(mine) if mine else None)
    return kept, coverage


def snapshot_of(p):
    return {"total": p["total"], "wins": p["wins"], "rank": p["rank"],
            "rankVal": p["rankTier"] * 10 + p["rankStar"] if p["rankTier"] else None,
            "points": p["points"], "lastMatch": p["lastMatch"], "private": p["private"]}


def save_snapshot(players, now):
    """Grava a foto de hoje e devolve (fotos em ordem de data, foto anterior a hoje ou None)."""
    os.makedirs(SNAP_DIR, exist_ok=True)
    today = datetime.datetime.fromtimestamp(now, BRT).strftime("%Y-%m-%d")
    snap = {"date": today, "t": now, "players": {p["nick"]: snapshot_of(p) for p in players}}
    json.dump(snap, open(os.path.join(SNAP_DIR, today + ".json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    snaps = [json.load(open(f, encoding="utf-8")) for f in sorted(glob.glob(os.path.join(SNAP_DIR, "*.json")))]
    prev = [s for s in snaps if s["date"] < today]
    return snaps, (prev[-1] if prev else None)


def fmt_days(d):
    if d >= 365:
        y = int(d // 365)
        return f"{y} ano" + ("s" if y > 1 else "")
    if d >= 60:
        return f"{int(d // 30)} meses"
    return f"{int(d)} dias"


def build_news(players, history, prev_snap, now):
    """Novidades curtas para a faixa "O que mudou". Cada item: {nick, kind, text, score}."""
    news = []
    by_nick = {p["nick"]: p for p in players}
    since = prev_snap["t"] if prev_snap else now - 86400
    label = "desde a última atualização" if prev_snap else "nas últimas 24 horas"
    for p in players:
        if p["private"]:
            continue
        mine = [r for r in history if r["nick"] == p["nick"]]
        fresh = [r for r in mine if r["t"] > since]
        if fresh:
            w = sum(r["won"] for r in fresh)
            news.append({"nick": p["nick"], "kind": "played",
                         "text": f"jogou {len(fresh)} partida{'s' if len(fresh) > 1 else ''} {label}: {w}V {len(fresh) - w}D",
                         "score": len(fresh) + (3 if w == len(fresh) and len(fresh) >= 3 else 0)})
            best = max(fresh, key=lambda r: (r["k"] + r["a"]) / max(1, r["d"]))
            if (best["k"] + best["a"]) / max(1, best["d"]) >= 6 or best["k"] >= 15:
                news.append({"nick": p["nick"], "kind": "big", "slug": best["slug"],
                             "text": f"fez {best['k']}/{best['d']}/{best['a']} de {{hero:{best['slug']}}}" + (" e venceu" if best["won"] else " (mas perdeu)"),
                             "score": 6})
            worst = max(fresh, key=lambda r: r["d"] - r["k"] * .3)
            if worst["d"] >= 13:
                news.append({"nick": p["nick"], "kind": "feed", "slug": worst["slug"],
                             "text": f"morreu {worst['d']} vezes de {{hero:{worst['slug']}}}. Acontece.", "score": 5})
            # voltou depois de um tempo sumido
            older = [r for r in mine if r["t"] <= since]
            if older and fresh:
                gap = (min(r["t"] for r in fresh) - max(r["t"] for r in older)) / 86400
                if gap >= 30:
                    news.append({"nick": p["nick"], "kind": "back", "text": f"voltou a jogar depois de {fmt_days(gap)} sumido",
                                 "score": 9})
        rec = p["recent"]
        if rec:
            streak = 1
            for r in rec[1:]:
                if r["won"] != rec[0]["won"]:
                    break
                streak += 1
            if streak >= 4 and rec[0]["t"] > since:
                news.append({"nick": p["nick"], "kind": "hot" if rec[0]["won"] else "cold",
                             "text": f"está com {streak} {'vitórias' if rec[0]['won'] else 'derrotas'} seguidas", "score": 7 + streak / 2})
    if prev_snap:
        for nick, old in prev_snap["players"].items():
            p = by_nick.get(nick)
            if not p or p["private"]:
                continue
            new = snapshot_of(p)
            if old.get("rankVal") and new["rankVal"] and new["rankVal"] != old["rankVal"]:
                up = new["rankVal"] > old["rankVal"]
                news.append({"nick": nick, "kind": "rankup" if up else "rankdown",
                             "text": f"{'subiu' if up else 'caiu'} de {old['rank']} para {new['rank']}", "score": 10})
        # mudança de posição no ranking por taxa de vitória
        def order(snap_players):
            q = [(n, v["wins"] / v["total"]) for n, v in snap_players.items() if not v["private"] and v["total"] >= 100]
            return [n for n, _ in sorted(q, key=lambda x: -x[1])]
        old_o = order(prev_snap["players"])
        new_o = order({p["nick"]: snapshot_of(p) for p in players})
        for i, n in enumerate(new_o):
            if n in old_o and old_o.index(n) - i >= 1:
                news.append({"nick": n, "kind": "up", "text": f"subiu para {i + 1}º no ranking (era {old_o.index(n) + 1}º)",
                             "score": 8 - i * .2})
    news.sort(key=lambda x: -x["score"])
    return news[:10], label
