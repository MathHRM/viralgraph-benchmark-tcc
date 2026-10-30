CMD ?= up -d --build --wait

.PHONY: laravel async

laravel:
	docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml $(CMD)

async:
	docker compose -f code/compose.yaml -f code/async/compose.override.yaml $(CMD)
