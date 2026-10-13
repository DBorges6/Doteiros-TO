"""Lista de jogadores do dashboard: (apelido, account_id do Dotabuff).

BASE é a lista fixa. Quem pede para entrar pelo site e é aprovado no /admin vem de
data/roster_extra.json (atualizado pelo sync_roster.py a cada atualização).
"""
import json, os

BASE = [
    ("Dinamite", 136650626), ("Ghost", 124422519), ("Soma", 87522607), ("Bahia", 112000736),
    ("Dr. Sem Doutorado", 91601867), ("El Desperuanizador", 339842799), ("Kaus", 100311594),
    ("Lestat", 224047111), ("Neo", 76975473), ("Pesadelo", 100351844), ("Panda", 110609987),
    ("Tanner", 88251580), ("VacaPower", 39463901), ("DBorges", 151490176),
    ("BAUA", 116119734),
    ("CX", 186798535), ("Lule", 299090742), ("MotherBoard", 1592826064),
    ("Milan Buzano Diporra", 237132537), ("AzAfApower", 210201414),
    ("PATIXA", 143876329),
]

EXTRA_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "roster_extra.json")
EXTRA = json.load(open(EXTRA_PATH, encoding="utf-8")) if os.path.exists(EXTRA_PATH) else []
APPROVED_AT = {int(e["id"]): e.get("aprovadoEm") for e in EXTRA}

ROSTER = list(BASE)
_ids = {pid for _, pid in ROSTER}
_nicks = {n.lower() for n, _ in ROSTER}
for e in EXTRA:
    pid, nick = int(e["id"]), (e.get("nick") or "").strip() or str(e["id"])
    if pid in _ids:
        continue
    while nick.lower() in _nicks:  # apelido repetido ganha um sufixo
        nick += "²"
    ROSTER.append((nick, pid))
    _ids.add(pid)
    _nicks.add(nick.lower())
