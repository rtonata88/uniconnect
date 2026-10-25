# UniConnect — every service runs natively under PM2 (no Docker).
SHELL := /bin/bash
ROOT  := $(shell pwd)
ENVV   = $(shell grep -E '^$(1)=' backend/.env 2>/dev/null | cut -d= -f2-)
PHP   := $(or $(PHP_BIN),$(call ENVV,PHP_BIN),php)
API    ?= 3
REVERB ?= 2
RESULTS := docs/testing/results

.PHONY: verify-realtime help setup deps env certs db bucket migrate build up down restart status logs seed \
        scale kill-api test test-backend test-frontend test-e2e loadtest report docs

help:
	@echo "make setup | up | down | seed | status | scale API=3 REVERB=3 | kill-api | test | loadtest | report | docs"

setup: ## prerequisites, packages, keys, certs, replication, bucket, migrations, frontend build
	./scripts/check-prereqs.sh
	$(MAKE) deps env certs db bucket migrate build
	@echo "✓ setup complete — now: make up && make seed"

deps:
	cd backend && $(PHP) $$(command -v composer) install --no-interaction
	cd frontend && npm install --no-audit --no-fund

env:
	PHP_BIN=$(PHP) ./scripts/init-env.sh

certs:
	./scripts/make-certs.sh

db:
	./infra/mysql/setup-replication.sh

bucket:
	./infra/minio/init-bucket.sh

migrate:
	cd backend && $(PHP) artisan migrate --force

build:
	cd frontend && npm run build

frontend/dist/index.html:
	cd frontend && npm run build

up: frontend/dist/index.html
	pm2 start ecosystem.config.cjs
	@echo "App: $(call ENVV,APP_URL)  Traefik dashboard: http://localhost:$(call ENVV,GATEWAY_DASHBOARD_PORT)"

down:
	-pm2 delete ecosystem.config.cjs

restart:
	pm2 restart ecosystem.config.cjs --update-env

status:
	@pm2 ls
	@./scripts/status.sh

logs:
	pm2 logs --lines 50

seed:
	cd backend && $(PHP) artisan migrate:fresh --seed --force

scale: ## pm2 errors when the count is unchanged, so ignore that
	-pm2 scale api $(API)
	-pm2 scale reverb $(REVERB)

kill-api: ## stops one API instance (failover demo)
	@ID=$$(pm2 jlist | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const a=JSON.parse(s).filter(p=>p.name==="api"&&p.pm2_env.status==="online");if(!a.length)process.exit(1);console.log(a[a.length-1].pm_id)})') \
	  && echo "stopping api pm_id $$ID" && pm2 stop $$ID

verify-realtime: ## DIST-3: two users on different Reverb instances chat in real time
	NODE_EXTRA_CA_CERTS="$$(mkcert -CAROOT)/rootCA.pem" node scripts/verify-realtime.mjs

test: test-backend test-frontend test-e2e report

test-backend:
	mkdir -p $(RESULTS)
	cd backend && XDEBUG_MODE=coverage $(PHP) -d memory_limit=1G vendor/bin/pest \
	  --log-junit ../$(RESULTS)/backend-junit.xml $(if $(NO_COVERAGE),,--coverage-clover ../$(RESULTS)/backend-coverage.xml --coverage-text=../$(RESULTS)/backend-coverage.txt)

test-frontend:
	mkdir -p $(RESULTS)
	cd frontend && npx vitest run --reporter=default --reporter=junit --outputFile.junit=../$(RESULTS)/frontend-junit.xml

test-e2e:
	mkdir -p $(RESULTS)
	cd frontend && npx playwright test

loadtest:
	./loadtests/run.sh

report:
	cd backend && $(PHP) artisan report:testing

docs:
	cd backend && $(PHP) artisan scribe:generate
