"""Coleta a página pública de visão geral do Dotabuff de cada jogador.

Usa o Chrome instalado na máquina via Playwright (o Dotabuff bloqueia requisições
diretas com Cloudflare). Grava data/dotabuff.json no mesmo formato que o build usa.
Só sobrescreve dados de um jogador se a coleta dele deu certo.
"""
import json, os, sys, time
from playwright.sync_api import sync_playwright

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "data", "dotabuff.json")
EXTRACT = open(os.path.join(ROOT, "scraper", "extract.js"), encoding="utf-8").read()
sys.path.insert(0, ROOT)
from roster import ROSTER  # noqa: E402

headless = "--show" not in sys.argv
old = json.load(open(OUT, encoding="utf-8")) if os.path.exists(OUT) else {}
new, failed = {}, []

with sync_playwright() as pw:
    channel = os.environ.get("BROWSER_CHANNEL", "chrome")  # na nuvem: chromium (vem com o Playwright)
    browser = pw.chromium.launch(channel=None if channel == "chromium" else channel, headless=headless,
                                 args=["--disable-blink-features=AutomationControlled"])
    ctx = browser.new_context(locale="pt-BR", viewport={"width": 1366, "height": 900},
                              user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36")
    page = ctx.new_page()
    for nick, pid in ROSTER:
        data = None
        for attempt in range(3):
            try:
                page.goto(f"https://pt.dotabuff.com/players/{pid}", wait_until="domcontentloaded", timeout=60000)
                page.wait_for_selector(".header-content-title h1", timeout=30000)
                page.wait_for_timeout(2500)  # seções React terminam de montar
                data = page.evaluate(EXTRACT)
                if data.get("ok"):
                    break
            except Exception as e:
                print(f"  tentativa {attempt + 1} falhou para {nick}: {e}", flush=True)
                page.wait_for_timeout(5000)
        if data and data.get("ok"):
            data.pop("ok")
            new[str(pid)] = data
            print(f"ok {nick}: {len(data['h'])} heróis, {len(data['r'])} partidas recentes", flush=True)
        else:
            failed.append(nick)
            if str(pid) in old:
                new[str(pid)] = old[str(pid)]
            print(f"FALHOU {nick} (mantidos os dados anteriores)", flush=True)
        time.sleep(1.5)
    browser.close()

json.dump(new, open(OUT, "w", encoding="utf-8"), ensure_ascii=False)
print("salvo", OUT, "| falhas:", failed or "nenhuma")
if len(failed) == len(ROSTER):
    sys.exit(2)
