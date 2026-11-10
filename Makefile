BASE_URL ?= http://127.0.0.1:9999
ARCHITECTURE ?= async
CONFIG ?= code/k6/config.json

.PHONY: stress pdf

# Grava dados; execute contra uma base experimental vazia.
stress:
	python3 code/k6/run.py \
		--base-url "$(BASE_URL)" \
		--architecture "$(ARCHITECTURE)" \
		--config "$(CONFIG)"

pdf:
	bash scripts/render-latex.sh
