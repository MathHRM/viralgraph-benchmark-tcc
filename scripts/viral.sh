#!/usr/bin/env bash
# Cenário de viralização: dataset Zipf pré-semeado, snapshot de volumes e carga k6.
#
#   scripts/viral.sh dataset                 gera code/dataset/out (SQL, Cypher, IDs do k6)
#   scripts/viral.sh snapshot sync|async     aplica o seed num volume vazio e salva o volume em tar
#   scripts/viral.sh restore  sync|async     recria o volume a partir do tar e sobe o ambiente
#   scripts/viral.sh down     sync|async     derruba o ambiente (remove volumes do projeto)
#   scripts/viral.sh obs up|down             Prometheus + Grafana + cAdvisor
#   MODE=smoke|tcc scripts/viral.sh load sync|async
#
# Opera somente nos projetos Compose tcc-viral-sync e tcc-viral-async.
set -euo pipefail
export LC_NUMERIC=C

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATASET="${DATASET:-$ROOT/code/dataset/out}"
SNAPSHOTS="${SNAPSHOTS:-$DATASET/snapshots}"
SYNC_PORT="${SYNC_PORT:-19998}"
ASYNC_PORT="${ASYNC_PORT:-19999}"
ALPINE="alpine:3.20"

log() { printf '[viral] %s\n' "$*" >&2; }
die() { log "erro: $*"; exit 1; }

compose() {
  local arch="$1"; shift
  case "$arch" in
    sync)
      CORPUS_DIR="$DATASET" BENCHMARK_PORT="$SYNC_PORT" docker compose -p tcc-viral-sync \
        -f "$ROOT/backends/laravel-sync/compose.benchmark.yaml" "$@" ;;
    async)
      CORPUS_DIR="$DATASET" BENCHMARK_PORT="$ASYNC_PORT" docker compose -p tcc-viral-async \
        -f "$ROOT/backends/hyperf-async/compose.yaml" \
        -f "$ROOT/backends/hyperf-async/compose.benchmark.yaml" "$@" ;;
    *) die "arquitetura inválida: $arch (sync|async)" ;;
  esac
}

volume_of() { [ "$1" = sync ] && echo tcc-viral-sync_mysql_benchmark || echo tcc-viral-async_neo4j; }
store_of()  { [ "$1" = sync ] && echo mysql || echo neo4j; }

require_dataset() {
  [ -f "$DATASET/manifest.json" ] || die "dataset ausente em $DATASET; rode: scripts/viral.sh dataset"
}

cmd_dataset() {
  [ -e "$DATASET/manifest.json" ] && die "$DATASET já existe; remova-o para gerar de novo."
  python3 "$ROOT/code/dataset/generate.py" --output "$DATASET" "$@"
}

cmd_snapshot() {
  local arch="$1" store volume started
  require_dataset
  store="$(store_of "$arch")"; volume="$(volume_of "$arch")"
  log "$arch: recriando volume vazio $volume"
  compose "$arch" down -v --remove-orphans >/dev/null 2>&1 || true
  started=$(date +%s)
  if [ "$arch" = sync ]; then
    # Construção do snapshot fora do orçamento medido.
    MYSQL_CPUS="${SNAPSHOT_CPUS:-2}" compose sync up -d --wait mysql
    log "sync: migrations"
    compose sync run --rm --no-deps -T api1 php artisan migrate --force --no-interaction
    log "sync: aplicando mysql/seed.sql.gz"
    gunzip -c "$DATASET/mysql/seed.sql.gz" | compose sync exec -T mysql \
      mysql -ubenchmark -pbenchmark-local laravel_sync_benchmark
    compose sync exec -T mysql mysql -N -ubenchmark -pbenchmark-local laravel_sync_benchmark -e \
      "SELECT 'users',COUNT(*) FROM users UNION ALL SELECT 'follows',COUNT(*) FROM follows UNION ALL
       SELECT 'posts',COUNT(*) FROM posts UNION ALL SELECT 'shares',COUNT(*) FROM shares UNION ALL
       SELECT 'feed_items',COUNT(*) FROM feed_items"
  else
    local image
    image="$(compose async config --images neo4j | head -1)"
    compose async create neo4j >/dev/null 2>&1   # cria o volume do projeto
    log "async: neo4j-admin database import full (offline)"
    docker run --rm -v "$volume:/data" -v "$DATASET/neo4j:/seed:ro" "$image" \
      neo4j-admin database import full neo4j --overwrite-destination=true \
        --nodes=User=/seed/users.csv.gz --nodes=Post=/seed/posts.csv.gz \
        --relationships=AUTHORED=/seed/authored.csv.gz --relationships=FOLLOWS=/seed/follows.csv.gz \
        --relationships=SHARED=/seed/shared.csv.gz --relationships=RESHARED_FROM=/seed/reshared_from.csv.gz \
        --relationships=FEED=/seed/feed.csv.gz
    NEO4J_CPUS="${SNAPSHOT_CPUS:-2}" NEO4J_MEMORY="${SNAPSHOT_NEO4J_MEMORY:-3g}" compose async up -d --wait neo4j
    # Cliente cypher-shell (JVM) em container próprio: não disputa a memória do servidor.
    local shell=(docker run --rm -i --network tcc-viral-async_default "$image"
      cypher-shell -a neo4j://neo4j:7687 -u neo4j -p "${NEO4J_PASSWORD:-benchmark-local}" --format plain)
    log "async: schema.cypher (constraints e índices)"
    "${shell[@]}" < "$DATASET/neo4j/schema.cypher"
    "${shell[@]}" \
      "CALL { MATCH (u:User) RETURN count(u) AS users } CALL { MATCH ()-[f:FOLLOWS]->() RETURN count(f) AS follows }
       CALL { MATCH (p:Post) RETURN count(p) AS posts } CALL { MATCH ()-[s:SHARED]->() RETURN count(s) AS shares }
       CALL { MATCH ()-[e:FEED]->() RETURN count(e) AS feed_items } RETURN users, follows, posts, shares, feed_items"
  fi
  log "$arch: seed aplicado em $(( $(date +%s) - started ))s; salvando volume"
  compose "$arch" stop "$store"
  mkdir -p "$SNAPSHOTS"
  docker run --rm -v "$volume:/data:ro" -v "$SNAPSHOTS:/snap" "$ALPINE" \
    tar -C /data -cf "/snap/$arch-$store.tar" .
  compose "$arch" down >/dev/null
  log "$arch: snapshot em $SNAPSHOTS/$arch-$store.tar ($(du -h "$SNAPSHOTS/$arch-$store.tar" | cut -f1))"
}

cmd_restore() {
  local arch="$1" store volume tarball t0 t1
  store="$(store_of "$arch")"; volume="$(volume_of "$arch")"
  tarball="$SNAPSHOTS/$arch-$store.tar"
  [ -f "$tarball" ] || die "snapshot ausente: $tarball; rode: scripts/viral.sh snapshot $arch"
  t0=$(date +%s.%N)
  compose "$arch" down -v --remove-orphans >/dev/null 2>&1 || true
  # Rótulos do Compose: o volume restaurado é reconhecido como do projeto (sem aviso de "external").
  docker volume create --label "com.docker.compose.project=tcc-viral-$arch" \
    --label "com.docker.compose.volume=${volume#tcc-viral-${arch}_}" "$volume" >/dev/null
  docker run --rm -v "$volume:/data" -v "$SNAPSHOTS:/snap:ro" "$ALPINE" tar -C /data -xpf "/snap/$arch-$store.tar"
  t1=$(date +%s.%N)
  log "$arch: volume restaurado em $(printf '%.1f' "$(echo "$t1 - $t0" | bc)")s; subindo serviços"
  compose "$arch" up -d --wait --build
  log "$arch: pronto em $(printf '%.1f' "$(echo "$(date +%s.%N) - $t0" | bc)")s (restauração + healthchecks)"
}

cmd_down() { compose "$1" down -v --remove-orphans; }

cmd_obs() {
  docker compose -p tcc-observability -f "$ROOT/observability/compose.yaml" "${1:-up}" \
    $([ "${1:-up}" = up ] && echo "-d --wait")
}

cmd_load() {
  local arch="$1" port metrics_url out
  require_dataset
  port=$([ "$arch" = sync ] && echo "$SYNC_PORT" || echo "$ASYNC_PORT")
  metrics_url=""
  [ "$arch" = async ] && metrics_url="http://127.0.0.1:${RABBITMQ_METRICS_PORT:-15692}/metrics/per-object"
  out="$ROOT/code/k6/results/viral-$arch-${MODE:-smoke}-$(date -u +%Y%m%dT%H%M%SZ)"
  mkdir -p "$out"
  local outputs=()
  if curl -fsS -o /dev/null "http://127.0.0.1:9090/-/ready" 2>/dev/null; then
    outputs=(-o experimental-prometheus-rw)
    export K6_PROMETHEUS_RW_SERVER_URL="http://127.0.0.1:9090/api/v1/write"
    export K6_PROMETHEUS_RW_TREND_STATS="p(50),p(95),p(99),avg,max"
  fi
  log "k6 $arch MODE=${MODE:-smoke} -> $out"
  MODE="${MODE:-smoke}" ARCH="$arch" BASE_URL="http://127.0.0.1:$port" \
    DATASET="$DATASET/k6/dataset.json" RABBITMQ_METRICS_URL="$metrics_url" \
    k6 run "${outputs[@]}" --tag "arch=$arch" --tag "mode=${MODE:-smoke}" \
      --summary-export "$out/summary.json" "$ROOT/code/k6/viral.js" 2>&1 | tee "$out/output.log"
  curl -fsS "http://127.0.0.1:$port/metricas" > "$out/metricas.json" || true
  log "métricas finais: $(cat "$out/metricas.json" 2>/dev/null)"
}

case "${1:-}" in
  dataset) shift; cmd_dataset "$@" ;;
  snapshot) cmd_snapshot "${2:?sync|async}" ;;
  restore) cmd_restore "${2:?sync|async}" ;;
  down) cmd_down "${2:?sync|async}" ;;
  obs) cmd_obs "${2:-up}" ;;
  load) cmd_load "${2:?sync|async}" ;;
  *) sed -n '2,12p' "$0"; exit 1 ;;
esac
