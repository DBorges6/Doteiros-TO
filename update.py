"""Atualiza o dashboard de ponta a ponta.

1. OpenDota  -> data/raw.json          (duplas / partidas em comum)
2. Dotabuff  -> data/dotabuff.json     (Chrome invisível via Playwright)
3. Build     -> data/dashboard_data.js (com imagens embutidas)
4. Git       -> commit + push (pule com --no-push)

Uso:  python update.py [--no-push] [--skip-opendota]
"""
import datetime, os, subprocess, sys

ROOT = os.path.dirname(os.path.abspath(__file__))
PY = sys.executable
env = {**os.environ, "PYTHONIOENCODING": "utf-8"}


def run(*cmd, check=True):
    print("\n$", " ".join(cmd), flush=True)
    r = subprocess.run(cmd, cwd=ROOT, env=env)
    if check and r.returncode != 0:
        sys.exit(f"falhou: {' '.join(cmd)} (código {r.returncode})")
    return r.returncode


if "--no-push" not in sys.argv:
    # a nuvem (GitHub Actions) também atualiza o repositório; pega a versão mais nova antes
    run("git", "pull", "--rebase", "--autostash")

if "--skip-opendota" not in sys.argv:
    # Falha no OpenDota não é fatal: mantém o raw.json anterior.
    run(PY, "fetch.py", check=False)
run(PY, os.path.join("scraper", "scrape_dotabuff.py"))
run(PY, "build_data.py")

if "--no-push" not in sys.argv:
    run("git", "add", "-A")
    if subprocess.run(["git", "diff", "--cached", "--quiet"], cwd=ROOT).returncode == 0:
        print("\nNada mudou desde a última atualização.")
    else:
        stamp = datetime.datetime.now().strftime("%d/%m/%Y %H:%M")
        run("git", "commit", "-m", f"Atualiza dados do dashboard ({stamp})")
        run("git", "push")
print("\nAtualização concluída.")
