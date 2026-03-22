.PHONY: help start build stop restart bash bash-root log log-clear dlog clear-cache

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

start: ## Start Docker containers
	docker compose up -d

build: ## Build Docker containers without cache
	docker compose build --no-cache
	docker compose up -d

stop: ## Stop Docker containers
	docker compose down

restart: ## Restart Docker containers
	docker compose restart

bash: ## Enter PHP container as www-data
	docker compose exec -u www-data php bash

bash-root: ## Enter PHP container as root
	docker compose exec -u root php bash

log: ## Tail Symfony dev logs
	docker compose exec php tail -f var/log/dev.log

log-clear: ## Clear Symfony dev logs
	docker compose exec php sh -c "echo '' > var/log/dev.log"

dlog: ## Show Docker logs
	docker compose logs -f

clear-cache: ## Clear Symfony cache
	docker compose exec -u www-data php bin/console cache:clear
	docker compose exec -u www-data php bin/console cache:pool:clear cache.global_clearer

db-migrate: ## Run migrations
	docker compose exec -u www-data php bin/console doctrine:migrations:migrate --no-interaction

watch: ## Watch and recompile Tailwind CSS on changes
	docker compose exec -u www-data php bin/console tailwind:build --watch

css: ## Build Tailwind CSS once
	docker compose exec -u www-data php bin/console tailwind:build

test: ## Run PHPUnit test suite
	docker compose exec -u www-data php bin/phpunit

lint: ## Run PHP CS Fixer (dry-run) and PHPStan
	docker compose exec -u www-data php vendor/bin/php-cs-fixer fix --dry-run --diff
	docker compose exec -u www-data php vendor/bin/phpstan analyse
