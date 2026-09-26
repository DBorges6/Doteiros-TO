import json, time, urllib.request, urllib.error, os, sys
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "raw.json")
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from roster import ROSTER
PLAYERS = dict(ROSTER)
B = "https://api.opendota.com/api"
def get(path):
    for a in range(6):
        try:
            req = urllib.request.Request(B+path, headers={"User-Agent":"dota-dash"})
            with urllib.request.urlopen(req, timeout=90) as r:
                d = json.load(r); time.sleep(1.1); return d
        except urllib.error.HTTPError as e:
            print("HTTP", e.code, path, file=sys.stderr); time.sleep(5*(a+1))
        except Exception as e:
            print("ERR", e, path, file=sys.stderr); time.sleep(5*(a+1))
    return None
F = ["hero_id","kills","deaths","assists","gold_per_min","xp_per_min","last_hits","denies","hero_damage","tower_damage","hero_healing","duration","lane_role","start_time","player_slot","radiant_win","party_size","game_mode","lobby_type","leaver_status","level"]
proj = "&".join("project="+p for p in F)
raw = {"heroes": get("/constants/heroes"), "fetched": int(time.time()), "players": {}}
for name, pid in PLAYERS.items():
    raw["players"][name] = {"id": pid, "profile": get(f"/players/{pid}"),
        "matches": get(f"/players/{pid}/matches?significant=0&{proj}")}
    print("ok", name, len(raw["players"][name]["matches"] or []), flush=True)
if sum(len(p["matches"] or []) for p in raw["players"].values()) == 0:
    sys.exit("OpenDota não retornou nada; mantendo raw.json anterior")
json.dump(raw, open(OUT,"w"))
print("DONE")
