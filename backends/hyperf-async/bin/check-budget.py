#!/usr/bin/env python3
"""Confere limites declarados e efetivos; não mede consumo nem pontuação."""
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
COMPOSE = ["docker", "compose", "-f", str(ROOT / "compose.yaml")]
config = json.loads(subprocess.check_output(COMPOSE + ["config", "--format", "json"]))
services = config["services"]
cpu = sum(float(service["cpus"]) for service in services.values())
memory = sum(int(service["mem_limit"]) for service in services.values())
if cpu > 1.5 + 1e-9 or memory > 3000 * 1024**2:
    raise SystemExit(f"Orçamento excedido: {cpu} CPU, {memory / 1024**2} MiB")
print(f"total: {cpu:.2f} CPU, {memory / 1024**2:.0f} MiB")
if "--config-only" in sys.argv:
    raise SystemExit(0)
for name in services:
    ids = subprocess.check_output(COMPOSE + ["ps", "-aq", name], text=True).split()
    if len(ids) != 1:
        raise SystemExit(f"Esperado um container para {name}; execute docker compose up -d")
    host = json.loads(subprocess.check_output(["docker", "inspect", ids[0]]))[0]["HostConfig"]
    expected = services[name]
    if (host["NanoCpus"] != round(float(expected["cpus"]) * 1e9)
            or host["Memory"] != int(expected["mem_limit"])
            or host["MemorySwap"] != host["Memory"]):
        raise SystemExit(f"Limite efetivo divergente em {name}")
    print(f"{name}: CPU/memória/swap conferidos")
