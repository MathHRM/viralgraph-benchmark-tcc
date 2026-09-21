#!/usr/bin/env bash

set -Eeuo pipefail

readonly IMAGE_NAME="tcc-latex:local"
readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly REPOSITORY_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"

bump_major=false

while (( $# > 0 )); do
    case "$1" in
        -v|--version)
            bump_major=true
            shift
            ;;
        -h|--help)
            printf 'Uso: %s [-v|--version] [pasta-latex] [diretório-saída]\n' "$0"
            exit 0
            ;;
        --)
            shift
            break
            ;;
        -*)
            printf 'Erro: opção desconhecida: %s\n' "$1" >&2
            exit 1
            ;;
        *)
            break
            ;;
    esac
done

if (( $# > 2 )); then
    printf 'Erro: argumentos demais. Use --help para consultar o formato esperado.\n' >&2
    exit 1
fi

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
latest_major=0
latest_patch=-1
for previous_file in "$output_path/${document_name}"-v*.pdf; do
    if [[ "$previous_file" =~ -v([0-9]+)([.]([0-9]+))?\.pdf$ ]]; then
        previous_major="${BASH_REMATCH[1]}"
        previous_patch="${BASH_REMATCH[3]:-0}"
        if (( previous_major > latest_major || (previous_major == latest_major && previous_patch > latest_patch) )); then
            latest_major=$previous_major
            latest_patch=$previous_patch
        fi
    fi
done

if (( latest_patch < 0 )); then
    major=1
    patch=0
elif [[ "$bump_major" == true ]]; then
    major=$((latest_major + 1))
    patch=0
else
    major=$latest_major
    patch=$((latest_patch + 1))
fi

output_file="${document_name}-v${major}.${patch}.pdf"

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

for previous_file in "$output_path/${document_name}"-v*.pdf; do
    if [[ -f "$previous_file" && "$previous_file" != "$output_path/$output_file" ]]; then
        rm -- "$previous_file"
    fi
done

printf 'PDF gerado: %s\n' "$output_path/$output_file"
