#!/usr/bin/env bash
#
# Convenience wrapper around the Docker Compose commands in README.md.
#
# This script is OPTIONAL. It runs exactly the commands the README documents and
# adds nothing the application depends on -- anyone following the README by hand
# gets the same result. It exists because the manual sequence has three steps
# that are easy to get wrong on a clean clone (a missing .env, a missing
# vendor/, and querying the app before MySQL finishes its first-run import),
# and because failing with a clear sentence beats failing with a stack trace.
#
#   ./run.sh              list every command with what it does
#   ./run.sh start        start the stack and wait until it really answers
#
# Running it with no arguments prints the menu rather than starting anything:
# the commands here include one that destroys the database volume, so the
# default had better be the harmless one.
#
set -Eeuo pipefail

# Run from the repository root no matter where the caller invoked this from,
# so relative paths below are stable.
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- output ------------------------------------------------------------------
# Colour only when stdout is a terminal: piping to a file or a CI log should not
# collect escape sequences.
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    BOLD=$'\033[1m'; RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'
    BLUE=$'\033[34m'; DIM=$'\033[2m'; RESET=$'\033[0m'
else
    BOLD=''; RED=''; GREEN=''; YELLOW=''; BLUE=''; DIM=''; RESET=''
fi

step() { printf '%s==>%s %s\n' "$BLUE$BOLD" "$RESET$BOLD" "$*$RESET"; }
ok()   { printf '%s  ok%s %s\n' "$GREEN" "$RESET" "$*"; }
warn() { printf '%swarn%s %s\n' "$YELLOW" "$RESET" "$*" >&2; }
note() { printf '%s     %s%s\n' "$DIM" "$*" "$RESET"; }

die() {
    printf '\n%serror%s %s\n' "$RED$BOLD" "$RESET" "$1" >&2
    shift
    for line in "$@"; do
        printf '      %s\n' "$line" >&2
    done
    exit 1
}

# Names the failing line rather than exiting silently, which matters most in the
# long-running `up` path where the cause scrolls out of view.
trap 'die "Failed at line $LINENO: ${BASH_COMMAND}"' ERR

# --- environment checks ------------------------------------------------------

COMPOSE=()

require_docker() {
    command -v docker >/dev/null 2>&1 || die \
        "Docker is not installed, or not on PATH." \
        "Install Docker Desktop: https://docs.docker.com/get-docker/"

    # `docker info` talks to the daemon; `docker --version` does not, and would
    # succeed on a machine where Docker is installed but not running.
    if ! docker info >/dev/null 2>&1; then
        die "Docker is installed but the daemon is not responding." \
            "Start Docker Desktop (or 'sudo systemctl start docker') and try again."
    fi

    if docker compose version >/dev/null 2>&1; then
        COMPOSE=(docker compose)
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE=(docker-compose)
        warn "Using legacy docker-compose v1. The README assumes v2 ('docker compose')."
    else
        die "Docker Compose is not available." \
            "It ships with Docker Desktop; on Linux install the docker-compose-plugin package."
    fi
}

require_files() {
    local missing=()
    for file in compose.yaml Dockerfile database/schema-and-seed.sql composer.json; do
        [ -f "$file" ] || missing+=("$file")
    done
    [ ${#missing[@]} -eq 0 ] || die \
        "This does not look like a complete checkout; missing: ${missing[*]}" \
        "Run this script from the repository root."
}

# The MySQL entrypoint imports database/schema-and-seed.sql only when the data
# volume is created. A truncated or absent file produces an empty database whose
# symptom appears much later, as a login that never works.
require_seed() {
    local lines
    lines=$(wc -l < database/schema-and-seed.sql | tr -d ' ')
    if [ "$lines" -lt 100 ]; then
        die "database/schema-and-seed.sql looks truncated ($lines lines)." \
            "Regenerate it:" \
            "  python3 scripts/generate-seed.py" \
            "  { cat database/schema.sql; echo; echo; cat database/seed.sql; } > database/schema-and-seed.sql"
    fi
}

ensure_env() {
    if [ -f .env ]; then
        return
    fi
    [ -f .env.example ] || die ".env is missing and there is no .env.example to copy."
    cp .env.example .env
    ok "Created .env from .env.example"
    note "Demo defaults. Change DB_PASSWORD and DB_ROOT_PASSWORD before using this anywhere real."
}

# Reads one variable out of .env without sourcing the file -- sourcing would
# execute whatever it contains.
env_value() {
    local key="$1" default="${2:-}" line
    line=$(grep -E "^${key}=" .env 2>/dev/null | tail -1 || true)
    if [ -z "$line" ]; then
        printf '%s' "$default"
    else
        printf '%s' "${line#*=}"
    fi
}

# A port already taken by an unrelated process is the single most common reason
# `up` fails on someone else's machine, and Compose reports it late and tersely.
check_port() {
    local port="$1" label="$2"
    if command -v lsof >/dev/null 2>&1; then
        if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
            # Our own stack holding the port is fine: `up` is idempotent.
            # `ps -q` prints ids only. Matching on plain `ps` output was wrong:
            # it prints a header row even when nothing is running, so the guard
            # silently passed on every clean start.
            if [ -n "$("${COMPOSE[@]}" ps -q --status running 2>/dev/null || true)" ]; then
                return 0
            fi
            die "Port $port ($label) is already in use by another process." \
                "Either stop it, or change the port in .env and run ./run.sh start again."
        fi
    fi
}

# --- commands ----------------------------------------------------------------

wait_for_http() {
    local url="$1" attempts="${2:-90}" code=""
    for ((i = 1; i <= attempts; i++)); do
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$url" 2>/dev/null || true)
        # Any HTTP answer means Apache and PHP are up; / redirects to /login.
        case "$code" in
            2??|3??) return 0 ;;
        esac
        printf '\r%s     waiting for %s ... %ss%s' "$DIM" "$url" "$i" "$RESET"
        sleep 1
    done
    printf '\r%*s\r' 60 ''
    return 1
}

cmd_start() {
    require_docker; require_files; require_seed; ensure_env

    local app_port db_port
    app_port=$(env_value APP_PORT 8080)
    db_port=$(env_value DB_PORT_HOST 3307)
    check_port "$app_port" "APP_PORT"
    check_port "$db_port" "DB_PORT_HOST"

    step "Building and starting containers"
    # Not piped through tail: a pipeline reports the exit status of the LAST
    # command, so a failed build would have looked like a success.
    "${COMPOSE[@]}" up -d --build

    step "Waiting for MySQL to report healthy"
    local health=""
    for ((i = 1; i <= 120; i++)); do
        health=$("${COMPOSE[@]}" ps db --format '{{.Health}}' 2>/dev/null | head -1 || true)
        [ "$health" = "healthy" ] && break
        if [ "$health" = "unhealthy" ]; then
            die "The database container is unhealthy." "Inspect it with: ./run.sh logs db"
        fi
        printf '\r%s     %s ... %ss%s' "$DIM" "${health:-starting}" "$i" "$RESET"
        sleep 1
    done
    printf '\r%*s\r' 60 ''
    [ "$health" = "healthy" ] || die "MySQL did not become healthy in time." \
        "On a first run it imports the schema and seed, which takes longer." \
        "Check progress with: ./run.sh logs db"
    ok "MySQL healthy"

    # vendor/ is git-ignored, so a clean clone has no autoloader and every page
    # would fail with a class-not-found error.
    if [ ! -f vendor/autoload.php ]; then
        step "Installing Composer dependencies (first run only)"
        "${COMPOSE[@]}" exec -T app composer install --no-interaction
        ok "Dependencies installed"
    fi

    step "Checking the application answers"
    if wait_for_http "http://localhost:${app_port}/login"; then
        ok "Application is up"
    else
        die "The containers started but http://localhost:${app_port}/login never answered." \
            "Inspect it with: ./run.sh logs app"
    fi

    printf '\n  %sOpen%s   http://localhost:%s\n' "$BOLD" "$RESET" "$app_port"
    printf '  %sSign in%s admin@example.com / Password123!  %s(all demo accounts are in README.md)%s\n' \
        "$BOLD" "$RESET" "$DIM" "$RESET"
    printf '  %sMySQL%s   localhost:%s\n\n' "$BOLD" "$RESET" "$db_port"
}

cmd_stop() {
    require_docker
    step "Stopping containers (data is kept)"
    "${COMPOSE[@]}" stop
    ok "Stopped. Start again with ./run.sh start"
}

cmd_down() {
    require_docker
    step "Removing containers (the database volume is kept)"
    "${COMPOSE[@]}" down
    ok "Removed. ./run.sh start restores the same data."
}

cmd_reset() {
    require_docker
    # down -v destroys the volume. That is the only way to re-run schema-and-seed,
    # and also the only command here that can lose work, so it asks first.
    if [ "${1:-}" != "--yes" ]; then
        printf '%sThis DELETES the database volume.%s Every product, order and stock movement\n' "$BOLD$RED" "$RESET"
        printf 'created since the last reset is lost, and the seed is re-imported from scratch.\n\n'
        read -r -p "Type 'reset' to continue: " reply
        [ "$reply" = "reset" ] || { echo "Cancelled."; exit 0; }
    fi
    step "Destroying containers and volumes"
    "${COMPOSE[@]}" down -v
    ok "Volumes removed"
    cmd_start
}

cmd_status() {
    require_docker
    "${COMPOSE[@]}" ps
    local app_port; app_port=$(env_value APP_PORT 8080)
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "http://localhost:${app_port}/login" 2>/dev/null || true)
    if [ -n "$code" ] && [ "$code" != "000" ]; then
        ok "http://localhost:${app_port}/login -> HTTP $code"
    else
        warn "http://localhost:${app_port}/login is not answering"
    fi
}

cmd_logs() {
    require_docker
    "${COMPOSE[@]}" logs -f --tail=100 "$@"
}

running_or_die() {
    # `ps -q` prints a container id only for containers matching the filter, so
    # empty output means "not running". Matching on the text of `ps` instead was
    # brittle: the service name also appears inside the NAME and IMAGE columns.
    local id
    id=$("${COMPOSE[@]}" ps -q --status running app 2>/dev/null || true)
    [ -n "$id" ] || die "The stack is not running." "Start it with: ./run.sh start"
}

cmd_test() {
    require_docker; running_or_die
    step "PHPUnit (unit + integration)"
    "${COMPOSE[@]}" exec -T app composer test
}

cmd_check() {
    require_docker; running_or_die
    local failed=()
    step "PHPUnit (unit + integration)"
    "${COMPOSE[@]}" exec -T app composer test || failed+=("tests")
    step "PHPStan (level 6)"
    "${COMPOSE[@]}" exec -T app composer stan || failed+=("phpstan")
    step "PHP_CodeSniffer (PSR-12)"
    "${COMPOSE[@]}" exec -T app composer sniff || failed+=("phpcs")

    step "Stock ledger invariant"
    local drift
    drift=$("${COMPOSE[@]}" exec -T db sh -c \
        'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -N -s -e "
            SELECT COUNT(*) FROM product_stocks ps
            LEFT JOIN (SELECT product_id, warehouse_id, SUM(quantity) s
                         FROM stock_ledger GROUP BY product_id, warehouse_id) l
              ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id
            WHERE COALESCE(l.s, 0) <> ps.quantity;"' 2>/dev/null | tr -d '[:space:]')
    if [ "$drift" = "0" ]; then
        ok "product_stocks matches SUM(stock_ledger) for every product and warehouse"
    else
        warn "$drift stock rows disagree with the ledger"
        failed+=("ledger")
    fi

    echo
    if [ ${#failed[@]} -eq 0 ]; then
        printf '%s  All checks passed.%s\n' "$GREEN$BOLD" "$RESET"
    else
        die "Failed: ${failed[*]}"
    fi
}

cmd_shell() {
    require_docker; running_or_die
    "${COMPOSE[@]}" exec app bash
}

cmd_mysql() {
    require_docker; running_or_die
    # Credentials come from the container's own environment, so they are never
    # written into shell history.
    "${COMPOSE[@]}" exec db sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
}

cmd_job() {
    require_docker; running_or_die
    # check-low-stock.php exits non-zero on purpose when products need
    # reordering, so a cron wrapper can alert without parsing the text. That is
    # a finding, not a failure, and must not trip `set -e` here.
    local status=0
    "${COMPOSE[@]}" exec -T app php scripts/check-low-stock.php || status=$?
    case "$status" in
        0) ok "No product is below its reorder point." ;;
        1) note "Exit 1: products need reordering (this is the script's normal 'action needed' signal)." ;;
        *) die "The low-stock script failed with exit code $status." ;;
    esac
}

cmd_help() {
    # Printed whenever run.sh is called with no arguments. Each line says what
    # the command does AND what it costs, because the difference between `down`
    # and `reset` is the difference between a pause and losing the database.
    local app_port db_port
    app_port=$(env_value APP_PORT 8080)
    db_port=$(env_value DB_PORT_HOST 3307)

    cat <<HELP
${BOLD}Stockpile — Inventory & Order Management System${RESET}
${DIM}Optional wrapper around the Docker Compose commands in README.md.${RESET}

  ${BOLD}./run.sh <command>${RESET}

${BOLD}Starting and stopping${RESET}

  ${GREEN}start${RESET}        Build the images, start the containers, and wait until the app
               really answers over HTTP. Safe to run repeatedly. On a first run
               it also creates .env, installs Composer dependencies, and waits
               for MySQL to import the schema and seed.
  ${BOLD}stop${RESET}         Stop the containers. Nothing is deleted — 'start' brings the
               same data back.
  ${BOLD}down${RESET}         Remove the containers but keep the database volume. Use this to
               free memory; your data survives.
  ${BOLD}restart${RESET}      stop, then start.
  ${RED}reset${RESET}        ${RED}Deletes the database volume.${RESET} Every product, order and stock
               movement created since the last reset is lost, and the seed is
               re-imported from scratch. Asks for confirmation first; pass
               --yes to skip the prompt.

${BOLD}Looking at it${RESET}

  ${BOLD}status${RESET}       Container state, plus an HTTP probe of the login page.
  ${BOLD}logs${RESET} [svc]   Follow the logs. Add 'app' or 'db' to narrow it down.

${BOLD}Checking it${RESET}

  ${BOLD}test${RESET}         PHPUnit: unit + integration suites.
  ${BOLD}check${RESET}        Everything: PHPUnit, PHPStan (level 6), PHP_CodeSniffer (PSR-12),
               and the stock-ledger invariant — that product_stocks still equals
               SUM(stock_ledger) for every product and warehouse.

${BOLD}Getting inside${RESET}

  ${BOLD}shell${RESET}        A bash shell in the app container.
  ${BOLD}mysql${RESET}        A MySQL client on the application database. Credentials come
               from the container's environment, so they never reach your shell
               history.
  ${BOLD}job${RESET}          Run the scheduled low-stock script (JOB-01). It exits 1 when
               products need reordering — that is a signal, not a failure.

  ${BOLD}help${RESET}         This menu. Printed when run.sh is called with no command.

${BOLD}Once it is running${RESET}

  Open      ${BOLD}http://localhost:${app_port}${RESET}
  Sign in   admin@example.com / Password123!   ${DIM}(every demo account is in README.md)${RESET}
  MySQL     localhost:${db_port}

${DIM}Ports are read from .env (APP_PORT, DB_PORT_HOST), which is created from
.env.example on first run. Change them there if either is taken.

This script is a convenience only. Every command it runs is documented in
README.md and works by hand without it.${RESET}
HELP
}

# --- dispatch ----------------------------------------------------------------

case "${1:-help}" in
    start|up)      shift || true; cmd_start "$@" ;;
    stop)          cmd_stop ;;
    down)          cmd_down ;;
    restart)       cmd_stop; cmd_start ;;
    reset|rebuild) shift || true; cmd_reset "${1:-}" ;;
    status|ps)     cmd_status ;;
    logs)          shift; cmd_logs "$@" ;;
    test)          cmd_test ;;
    check)         cmd_check ;;
    shell|bash|sh) cmd_shell ;;
    mysql|db)      cmd_mysql ;;
    job)           cmd_job ;;
    help|-h|--help) cmd_help ;;
    *)             printf '%sUnknown command: %s%s\n\n' "$RED" "$1" "$RESET" >&2; cmd_help; exit 2 ;;
esac
