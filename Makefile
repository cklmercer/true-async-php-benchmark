# Everything runs in containers. Nothing here needs PHP, k6 or a database on
# the host.
#
#   make benchmark    both, in order
#
# Or one at a time: make jitter / make postgres. Each brings up what it needs,
# seeds, and prints its numbers.

DC := docker compose

# Which server the tests point at.
#
#   async           the TrueAsync coroutine server, this repo's subject
#   laravel         stock Laravel on Octane/FrankenPHP, the control
#   laravel-async   that same Laravel on the TrueAsync FrankenPHP worker
#
# The third is the control's application on the subject's runtime, so the three
# together separate the two things the first two numbers confound: the
# framework, and the concurrency model underneath it.
#
# Never more than one at once — `serve` stops the others — because sharing the
# box between them would measure the scheduler rather than any of them.
STACK ?= async

# Every stack, for the targets that have to reach all of them.
PROFILES := --profile laravel --profile laravel-async

ifeq ($(STACK),async)
  SERVICE  := app
  OTHERS   := laravel laravel-async
  PROFILE  :=
  LABEL    :=
  # Empty: the k6 service already defaults to the app's address and port block.
  K6_STACK :=
else ifeq ($(STACK),laravel)
  SERVICE  := laravel
  OTHERS   := app laravel-async
  PROFILE  := --profile laravel
  LABEL    := laravel-
  # Octane listens on one port, not a block, so the VU spread that target.js
  # does across app:8080-8083 has to be turned off. One port is plenty at the
  # concurrency this stack reaches.
  K6_STACK := --no-deps -e TARGET=http://laravel:8080 -e TARGET_PORTS=1
else ifeq ($(STACK),laravel-async)
  SERVICE  := laravel-async
  OTHERS   := app laravel
  PROFILE  := --profile laravel-async
  LABEL    := laravel-async-
  # One listener, same as the control: the worker threads multiplex on it, so a
  # port block would spread VUs without adding capacity.
  K6_STACK := --no-deps -e TARGET=http://laravel-async:8080 -e TARGET_PORTS=1
else
  $(error STACK must be async, laravel or laravel-async, not "$(STACK)")
endif

# Recursive, not simple: WORKSPACES is defined below this line, and `:=` would
# expand it to empty here — leaving the generator on its own default while the
# server used yours.
K6 = $(DC) run --rm $(if $(TARGET),--no-deps -e TARGET=$(TARGET),$(K6_STACK)) -e WORKSPACES=$(WORKSPACES) k6 run

# Where the generator points. Empty means "the app this compose file starts".
#
# k6 is the limit on a single box — it was measured taking 4 to 6 cores to the
# server's 3 — so the biggest single win available is running it somewhere
# else. On the second machine, with both on the same Tailscale network:
#
#   make serve                                   (on the server machine)
#   make jitter TARGET=http://100.x.y.z:8080     (on the generator machine)
#
# With TARGET set, the generator neither starts nor seeds a server; it assumes
# the one you pointed it at is up, and WORKSPACES has to match what that server
# was started with.
TARGET ?=

# Load shape. 3 minutes total: 30s ramp, 2m15s hold, 15s ramp-down.
RAMP     ?= 30s
HOLD     ?= 2m15s
DOWN     ?= 15s

# VU count is concurrency, not throughput, and each test wants a different
# amount of it. Too few and there is not enough in flight to keep the server
# busy; too many and every extra VU is a goroutine and a JS runtime on the
# generator, which saturates well before the server does. Measured, not
# guessed — sustained rps over the hold:
#
#   jitter                    database
#   3000 -> 42,858            50 -> 52,043   med 0.7ms
#   4000 -> 51,475            100 -> 55,116  med 1.3ms
#   4500 -> 54,088  <-        150 -> 54,099  med 2.1ms
#   5000 -> 52,025            200 -> 51,684  med 2.8ms
#                             400 -> 46,534  med 6.1ms
#                             1000 -> 43,633 <- med 17.6ms
#
# The two curves have opposite shapes because the tests are shaped differently.
# jitter parks each VU in a 2-120ms sleep, so throughput is VUs/iteration and
# it needs thousands in flight to reach a plateau. The database test has no
# sleep, so every extra VU buys nothing but another goja runtime competing with
# the server for the same cores — the curve slopes down the whole way, and its
# peak is at an almost idle server. 1000 is the default anyway, because a
# thousand connections in flight is a load worth quoting.
#
# Both curves bend on k6, not on the server — see the TARGET note above for
# how to take the generator off the box and find out where the server bends.
#
# One knob for both tests: VUS. `make jitter VUS=6000`, `make postgres VUS=400`.
# They only start from different defaults, because they want different amounts
# of concurrency.
VUS      ?= 4500
POSTGRES_VUS ?= 1000

# The tenancy boundary: ~10-20 users in each. VUs are pinned to a workspace,
# so this also decides how wide the read load spreads over the index.
WORKSPACES ?= 512
ROWS_PER_WORKSPACE ?= 1000

.DEFAULT_GOAL := help

.PHONY: help
help: ## List targets
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "};{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

.PHONY: jitter
jitter: ## Response-time jitter, no database on the path
	@$(if $(TARGET),,$(MAKE) --no-print-directory serve)
	$(K6) -e RUN_NAME=$(LABEL)jitter -e VUS=$(VUS) -e RAMP=$(RAMP) -e HOLD=$(HOLD) -e DOWN=$(DOWN) /scripts/jitter.js

.PHONY: postgres
postgres: VUS := $(POSTGRES_VUS)
postgres: ## The chat log, read and written per workspace
	@$(if $(TARGET),,$(MAKE) --no-print-directory serve seed)
	$(K6) -e RUN_NAME=$(LABEL)postgres -e VUS=$(VUS) -e RAMP=$(RAMP) -e HOLD=$(HOLD) -e DOWN=$(DOWN) \
	      -e MAX_ID=$(ROWS_PER_WORKSPACE) /scripts/database.js

.PHONY: benchmark
benchmark: jitter postgres ## Run everything

# --- plumbing the targets above use -------------------------------------------

.PHONY: serve
serve: ## Start the server and database (what the other machine points at)
	@$(DC) stop $(OTHERS) >/dev/null 2>&1 || true
	@WORKSPACES=$(WORKSPACES) $(DC) $(PROFILE) up -d --wait $(SERVICE) postgres >/dev/null
	@docker logs benchmark-$(SERVICE) 2>&1 | tail -1

.PHONY: seed
seed: ## Refill one log per workspace (the database test does this for you)
	@WORKSPACES=$(WORKSPACES) ROWS_PER_WORKSPACE=$(ROWS_PER_WORKSPACE) \
	  $(DC) run --rm seed 2>&1 | tail -1

.PHONY: build
build: ## Rebuild the async and Octane images
	$(DC) --profile laravel build app laravel

# Separate, and not part of `build`, because it is not the same kind of job:
# it compiles PHP from the true-async branch and then builds FrankenPHP from
# source against it. Tens of minutes on a cold cache, against about one for the
# other two. Once built it is cached like anything else.
.PHONY: build-laravel-async
build-laravel-async: ## Rebuild the TrueAsync Laravel image (compiles PHP and FrankenPHP; slow)
	$(DC) --profile laravel-async build laravel-async

.PHONY: down
down: ## Stop everything
	$(DC) $(PROFILES) down

.PHONY: clean
clean: ## Stop everything and drop the data
	$(DC) $(PROFILES) down -v

.PHONY: stats
stats: ## What the server thinks is happening
	@curl -s http://127.0.0.1:$${APP_PORT:-8080}/stats | python3 -m json.tool

.PHONY: logs
logs: ## Follow app + database logs
	$(DC) $(PROFILE) logs -f $(SERVICE) postgres
