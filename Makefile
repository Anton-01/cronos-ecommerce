.PHONY: up down build shell test lint stan arch migrate fresh codegen

up:
	docker compose up -d --build
down:
	docker compose down
shell:
	docker compose exec app bash
test:
	docker compose exec app php artisan test
lint:
	docker compose exec app ./vendor/bin/pint --test
stan:
	docker compose exec app ./vendor/bin/phpstan analyse
arch:
	docker compose exec app ./vendor/bin/deptrac analyse
migrate:
	docker compose exec app php artisan migrate
fresh:
	docker compose exec app php artisan migrate:fresh --seed
