#!/usr/bin/env bash

set -Eeuo pipefail

readonly IMAGE_NAME="tcc-latex:local"
readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly REPOSITORY_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"

latex_path="${1:-artigo}"
output_path="${2:-.}"

if [[ ! -d "$latex_path" ]]; then
    printf 'Erro: pasta LaTeX não encontrada: %s\n' "$latex_path" >&2
    exit 1
fi

latex_path="$(cd -- "$latex_path" && pwd)"
output_path="$(mkdir -p -- "$output_path" && cd -- "$output_path" && pwd)"

if [[ -f "$latex_path/principal.tex" ]]; then
    main_file="principal.tex"
else
    mapfile -t tex_files < <(find "$latex_path" -maxdepth 1 -type f -name '*.tex' -printf '%f\n' | sort)
    if (( ${#tex_files[@]} != 1 )); then
        printf 'Erro: informe uma pasta com principal.tex ou exatamente um arquivo .tex.\n' >&2
        exit 1
    fi
    main_file="${tex_files[0]}"
fi

document_name="${DOCUMENT_NAME:-$(basename -- "$latex_path")}"
version=1
for previous_file in "$output_path/${document_name}"-v*.pdf; do
    if [[ "$previous_file" =~ -v([0-9]+)\.pdf$ ]] && (( BASH_REMATCH[1] >= version )); then
        version=$((BASH_REMATCH[1] + 1))
    fi
done
output_file="${document_name}-v${version}.pdf"
previous_version=$((version - 1))

if ! docker image inspect "$IMAGE_NAME" >/dev/null 2>&1; then
    printf 'Imagem %s não encontrada; construindo...\n' "$IMAGE_NAME"
    docker build --tag "$IMAGE_NAME" "$REPOSITORY_DIR"
fi

printf 'Compilando %s/%s -> %s/%s\n' "$latex_path" "$main_file" "$output_path" "$output_file"
docker run --rm \
    --user "$(id -u):$(id -g)" \
    --mount "type=bind,src=$latex_path,dst=/source,readonly" \
    --mount "type=bind,src=$output_path,dst=/output" \
    "$IMAGE_NAME" \
    sh -c 'rm -rf /tmp/work && mkdir -p /tmp/work && cp -a /source/. /tmp/work/ && latexmk -pdf -interaction=nonstopmode -halt-on-error -outdir=/tmp/work "/tmp/work/$1" && cp "/tmp/work/${1%.tex}.pdf" "/output/$2"' \
    sh "$main_file" "$output_file"

if (( previous_version > 0 )); then
    rm -- "$output_path/${document_name}-v${previous_version}.pdf"
fi

printf 'PDF gerado: %s\n' "$output_path/$output_file"
