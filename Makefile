DC = docker compose

# Storage::fake cleans one directory per disk name, and that directory lives under the bind mount
# every container shares. The two engine passes run at once over it, so one of them wiping the
# archive disk between another's write and its read-back fails a test that has nothing wrong with
# it. An anonymous volume gives each container that subtree to itself, and --rm takes it away.
EPHEMERAL = /app/vendor/orchestra/testbench-core/laravel/storage/framework/testing
PHP = $(DC) run --rm -v $(EPHEMERAL) php

.PHONY: build install update test test-quiet bench bench-up bench-volume coverage types stan lint lint-fix rector rector-fix mutation validate ci shell redis-up dbs-up test-mysql test-pgsql test-dbs

build: ## Build the dev image
	$(DC) build php

install: ## composer install
	$(PHP) composer install

update: ## composer update
	$(PHP) composer update

# The buffered mode is covered against a real Redis, because the 100% gate admits no baseline and
# a path nobody exercises "because it needs a service" is a path nobody covers. Already up is a
# no-op, so this costs a second once and nothing after that.
redis-up:
	$(DC) up -d --wait redis

# paratest builds each worker's command line from scratch, so the -d flags of the parent
# never reach them and the arch pass exhausts the 128M default. This is the only channel
# paratest offers into a worker's ini.
WORKER_PHP = --passthru-php="'-d' 'memory_limit=1G'"

test: redis-up ## Run the test suite (make test ARGS="tests/Support/ConfigTest.php")
	$(PHP) vendor/bin/pest --parallel $(WORKER_PHP) $(ARGS)

# Paratest already prints one character per test; the colour around it is what costs.
# --no-progress would trim further, but it drops the count of tests that passed with it.
test-quiet: redis-up ## Same suite, output trimmed to its result
	$(PHP) vendor/bin/pest --parallel $(WORKER_PHP) --colors=never $(ARGS)

bench: ## Write-path baseline (report, not a gate)
	$(PHP) php -d memory_limit=1G benchmarks/bench.php

bench-up:
	$(DC) --profile bench up -d --wait postgres-bench mysql-bench

# Not a gate and not part of any suite: it writes millions of rows and needs a data directory on
# real disk, which is what the two bench services exist for. ENGINE=pgsql|mysql, ROWS, SHAPE=flat|
# partitioned. `make test-dbs` never touches these containers.
bench-volume: bench-up ## Cost of the trail at volume (make bench-volume ENGINE=mysql ROWS=10000000 SHAPE=partitioned)
	$(PHP) sh -c "ENGINE=$${ENGINE:-pgsql} ROWS=$${ROWS:-1000000} SHAPE=$${SHAPE:-flat} WRITES=$${WRITES:-2000} php -d memory_limit=2G benchmarks/volume.php"

coverage: redis-up ## Tests + 100% coverage gate
	$(PHP) php -d memory_limit=1G -d pcov.directory=/app -d 'pcov.exclude=~/(vendor|tests|\.cache)/~' vendor/bin/pest --ci --coverage --min=100

types: redis-up ## 100% type coverage gate
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
MUTATION_PATHS = src/Telemetry src/Support src/Context src/Http src/Diff src/Integrity src/Ledger src/Query src/Snapshot src/Pipeline src/Presentation src/Security src/Capture src/Concerns src/Models src/Transactions src/Transitions src/Restore src/Events src/Dispatch src/Buffer src/Jobs src/Console src/SentinelServiceProvider.php

# Serial on purpose, and not for memory. Each mutant is run by relaunching pest with the
# parent's arguments, so --parallel is inherited by a subprocess that cannot fork one and
# dies at startup — and a non-zero exit is scored as a mutant killed. The pass ends up
# reporting a score it did not measure: 100% where a serial run says 68%.
mutation: ## Mutation testing over the core, one pass per path
	@for path in $(MUTATION_PATHS); do \
		echo "== $$path"; \
		$(PHP) php -d pcov.directory=/app -d 'pcov.exclude=~/(vendor|tests|\.cache)/~' -d memory_limit=2G \
			vendor/bin/pest --mutate --covered-only --path=$$path || exit 1; \
	done

dbs-up: redis-up
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
