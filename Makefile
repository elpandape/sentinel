DC = docker compose
PHP = $(DC) run --rm php

.PHONY: build install update test test-quiet bench coverage types stan lint lint-fix rector rector-fix mutation validate ci shell dbs-up test-mysql test-pgsql test-dbs

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
MUTATION_PATHS = src/Support src/Context src/Http src/Diff src/Integrity src/Ledger src/Query src/Snapshot src/Pipeline src/Presentation src/Security src/Capture src/Concerns src/Models src/Transactions src/Transitions src/Restore src/Events src/SentinelServiceProvider.php

mutation: ## Mutation testing over the core, one pass per path
	@for path in $(MUTATION_PATHS); do \
		echo "== $$path"; \
		$(PHP) php -d pcov.directory=/app -d 'pcov.exclude=~/(vendor|tests|\.cache)/~' -d memory_limit=2G \
			vendor/bin/pest --mutate --parallel --covered-only --path=$$path || exit 1; \
	done

dbs-up:
	$(DC) up -d --wait mysql postgres

# A pass cut in half leaves fixture tables behind and the next one fails on them, with a
# QueryException that has nothing to do with the code. The schema is recreated here rather
# than trusted to whoever ran it last.
#
# One process walks the whole suite here, unlike `make test`, so what every earlier test left
# on the heap is still there for the last one. The default limit is not a budget this pass fits
# in, the same way coverage and mutation do not.
test-mysql: dbs-up ## Suite against MySQL 9 (make test-mysql ARGS=tests/Capture)
	$(DC) exec -T mysql mysql -uroot -psecret -e "drop database if exists sentinel; create database sentinel"
	$(PHP) sh -c "DB_CONNECTION=mysql DB_HOST=mysql DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=secret DB_DATABASE=sentinel php -d memory_limit=1G vendor/bin/pest --ci $(ARGS)"

test-pgsql: dbs-up ## Suite against PostgreSQL 16 (make test-pgsql ARGS=tests/Capture)
	$(DC) exec -T postgres psql -q -U postgres -d sentinel -c "drop schema public cascade" -c "create schema public"
	$(PHP) sh -c "DB_CONNECTION=pgsql DB_HOST=postgres DB_PORT=5432 DB_USERNAME=postgres DB_PASSWORD=secret DB_DATABASE=sentinel php -d memory_limit=1G vendor/bin/pest --ci $(ARGS)"

# Two independent servers: the wall time is the longer pass, not the sum. Output is synced per
# target so the two reports do not interleave; each one appears whole when its pass ends.
test-dbs: ## Suite against MySQL & Postgres, both at once (make test-dbs ARGS=tests/Capture)
	$(MAKE) --no-print-directory -j2 -O test-mysql test-pgsql
