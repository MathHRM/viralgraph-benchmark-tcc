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

# Cenário de viralização (ver code/docs/viralizacao.md). ARCH=sync|async, MODE=smoke|tcc.
VIRAL_ARCH ?= sync
MODE ?= smoke
.PHONY: viral-dataset viral-snapshot viral-restore viral-load viral-obs
viral-dataset:
	bash scripts/viral.sh dataset
viral-snapshot:
	bash scripts/viral.sh snapshot $(VIRAL_ARCH)
viral-restore:
	bash scripts/viral.sh restore $(VIRAL_ARCH)
viral-load:
	MODE=$(MODE) bash scripts/viral.sh load $(VIRAL_ARCH)
viral-obs:
	bash scripts/viral.sh obs up
