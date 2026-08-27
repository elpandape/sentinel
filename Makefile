DC = docker compose
PHP = $(DC) run --rm php

.PHONY: build install update test test-quiet verify bench coverage types stan lint lint-fix rector rector-fix mutation validate ci shell test-dbs

build: ## Build the dev image
	$(DC) build php

install: ## composer install
	$(PHP) composer install

update: ## composer update
	$(PHP) composer update

test: ## Run the test suite (make test ARGS="tests/Support/ConfigTest.php")
	$(PHP) vendor/bin/pest --parallel $(ARGS)

# Paratest already prints one character per test; the colour around it is what costs.
# --no-progress would trim further, but it drops the count of tests that passed with it.
test-quiet: ## Same suite, output trimmed to its result
	$(PHP) vendor/bin/pest --parallel --colors=never $(ARGS)

verify: ## One-off expectation (make verify ARGS='expect(1 + 1)->toBe(2);')
	$(PHP) vendor/bin/pest --agent '$(ARGS)'

bench: ## Write-path baseline (report, not a gate)
	$(PHP) php -d memory_limit=1G benchmarks/bench.php

coverage: ## Tests + 100% coverage gate
	$(PHP) php -d memory_limit=1G -d pcov.directory=/app -d 'pcov.exclude=~/(vendor|tests|\.cache)/~' vendor/bin/pest --ci --coverage --min=100

types: ## 100% type coverage gate
	$(PHP) php -d memory_limit=1G vendor/bin/pest --type-coverage --min=100

stan: ## PHPStan (level max)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

lint: ## Pint check (no changes)
	$(PHP) vendor/bin/pint --test

lint-fix: ## Pint apply
	$(PHP) vendor/bin/pint

rector: ## Rector dry-run
	$(PHP) vendor/bin/rector process --dry-run

rector-fix: ## Rector apply
	$(PHP) vendor/bin/rector process

validate: ## composer manifest and advisory checks
	$(PHP) composer validate --strict
	$(PHP) composer audit

ci: lint stan rector coverage types validate ## Everything CI runs

shell: ## Shell inside the container
	$(PHP) sh

# pest --mutate does not accumulate repeated --path flags: one pass per path.
MUTATION_PATHS = src/Support src/Context src/Http src/Diff src/Integrity src/Ledger src/Snapshot src/Capture src/Concerns src/Models src/SentinelServiceProvider.php

mutation: ## Mutation testing over the core, one pass per path
	@for path in $(MUTATION_PATHS); do \
		echo "== $$path"; \
		$(PHP) php -d pcov.directory=/app -d 'pcov.exclude=~/(vendor|tests|\.cache)/~' -d memory_limit=2G \
			vendor/bin/pest --mutate --parallel --covered-only --path=$$path || exit 1; \
	done

test-dbs: ## Suite against MySQL & Postgres (waits for healthchecks)
	$(DC) up -d --wait mysql postgres
	$(PHP) sh -c "DB_CONNECTION=mysql DB_HOST=mysql DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=secret DB_DATABASE=sentinel vendor/bin/pest --ci"
	$(PHP) sh -c "DB_CONNECTION=pgsql DB_HOST=postgres DB_PORT=5432 DB_USERNAME=postgres DB_PASSWORD=secret DB_DATABASE=sentinel vendor/bin/pest --ci"
