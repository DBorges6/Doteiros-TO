"""Baixa a lista de jogadores aprovados no /admin do site e grava data/roster_extra.json.

O roster.py junta esses jogadores à lista fixa. Se o site não responder, mantém o arquivo
anterior (ninguém some do ranking por causa de uma falha de rede).
"""
import json, os, sys, urllib.request

ROOT = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(ROOT, "data", "roster_extra.json")
URL = "https://doteiros.com.br/api/aprovados.php"

try:
    req = urllib.request.Request(URL, headers={"User-Agent": "doteiros-sync"})
    with urllib.request.urlopen(req, timeout=30) as r:
        data = json.load(r)
    approved = [{"id": int(a["id"]), "nick": str(a["nick"])[:24], "aprovadoEm": a.get("aprovadoEm")}
                for a in data.get("aprovados", []) if str(a.get("id", "")).isdigit()]
except Exception as e:
    sys.exit(f"não consegui ler {URL} ({e}); mantendo data/roster_extra.json anterior")

json.dump(approved, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print(f"aprovados pelo site: {len(approved)}", [a["nick"] for a in approved])
