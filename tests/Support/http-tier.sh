#!/usr/bin/env bash
#
# The real HTTP tier, reproducibly: two throwaway web servers, php_test and
# php_cms, serving THIS checkout (the main one or a git worktree) against a
# test database, and PHPUnit run inside php_test. See TESTING.md, "De
# HTTP-tier".
#
#   tests/Support/http-tier.sh up [--db <test database>]
#   tests/Support/http-tier.sh run [--db <test database>] [phpunit arguments]
#   tests/Support/http-tier.sh down
#   tests/Support/http-tier.sh status
#
# `run` without phpunit arguments runs `--testsuite http`. `run` starts the
# servers itself when they are not up yet, and leaves them running; `down`
# removes them. The database defaults to TEST_DB_DATABASE, then mygdala_tests.
#
# Why not `docker compose --profile test up -d`: that serves the MAIN
# checkout's code (its bind mount is ./), so from a worktree it would test
# the wrong tree, and it needs the main checkout's .env. These containers
# join the installation's own Docker network under the aliases php_test and
# php_cms, the names the suite talks to (Tests\Support\TestEnvironment), and
# mount the checkout this script lives in.
#
# Everything the servers see is set here, explicitly, not inherited from
# .env: the database, APP_ENV=production (the default a live site has, and
# what the SEO/robots tests assert), and every module switch. The only values
# taken from the installation are its database credentials, read from its
# running `php` container and handed over as environment variables; they are
# never written to a file or printed. Uploads and sessions of the servers
# live in a tmpfs, so nothing a test leaves behind outlives `down`.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/../.." && pwd)"

# The installation = the Compose project of the MAIN checkout, also when this
# runs from a worktree under .claude/worktrees/.
common_dir="$(git -C "$root" rev-parse --path-format=absolute --git-common-dir)"
main_checkout="$(dirname "$common_dir")"
project="${COMPOSE_PROJECT_NAME:-$(basename "$main_checkout" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')}"

# Container names are per checkout, so a worktree and the main checkout never
# remove each other's servers.
checkout_id="$(basename "$root" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-' | sed 's/-*$//')"
test_name="${project}-httptier-${checkout_id}-php_test"
cms_name="${project}-httptier-${checkout_id}-php_cms"

fail() {
    echo "http-tier: $*" >&2
    exit 1
}

# Git Bash on Windows rewrites arguments that look like POSIX paths
# (/var/www/html) into Windows paths unless told not to.
export MSYS_NO_PATHCONV=1

# The host path Docker must mount: a Windows path under Git Bash.
host_root() {
    (cd "$root" && pwd -W 2>/dev/null) || echo "$root"
}

php_container() {
    local id="" candidate
    # The servers this script starts are built from the same image and so
    # carry its Compose labels too; they are recognised by their own label.
    for candidate in $(docker ps -q \
        --filter "label=com.docker.compose.project=${project}" \
        --filter "label=com.docker.compose.service=php"); do
        if [ -z "$(docker inspect -f '{{index .Config.Labels "mygdala.http-tier"}}' "$candidate")" ]; then
            id="$candidate"
            break
        fi
    done
    if [ -z "$id" ]; then
        # Containers created before the installation dropped container_name
        # carry the old fixed name (TESTING.md, "Als .env ontbreekt").
        id="$(docker ps -q --filter "name=^/${project}_php$" | head -n 1)"
    fi
    if [ -n "$id" ] && [ -n "$(docker inspect -f '{{index .Config.Labels "mygdala.http-tier"}}' "$id")" ]; then
        id=""
    fi
    [ -n "$id" ] || fail "no running php container of the Compose project '${project}'. Start the installation first (docker compose up -d)."
    echo "$id"
}

container_env() {
    docker exec "$1" printenv "$2" 2>/dev/null || true
}

database=""
parse_db() {
    database="${TEST_DB_DATABASE:-mygdala_tests}"
    rest=()
    while [ $# -gt 0 ]; do
        case "$1" in
            --db) [ $# -ge 2 ] || fail "--db needs a database name"; database="$2"; shift 2 ;;
            --db=*) database="${1#--db=}"; shift ;;
            *) rest+=("$1"); shift ;;
        esac
    done
}

is_running() {
    [ -n "$(docker ps -q --filter "name=^/$1$")" ]
}

up() {
    local php development network image
    php="$(php_container)"
    development="$(container_env "$php" DB_DATABASE)"
    [ -n "$development" ] || fail "the php container names no DB_DATABASE."

    # The same refusal tests/bootstrap.php makes, before a server exists that
    # could write to development.
    [ "$database" != "$development" ] || fail "refusing: '${database}' is the development database."
    case "$database" in
        *test*) ;;
        *) fail "refusing: '${database}' does not look like a test database (its name must contain 'test')." ;;
    esac

    # Compose's own php_test/php_cms would answer on the same aliases.
    if [ -n "$(docker ps -q --filter "label=com.docker.compose.project=${project}" --filter "label=com.docker.compose.service=php_test")" ]; then
        fail "the Compose php_test service of '${project}' is running; stop it (docker compose --profile test stop) or run the suite there."
    fi

    network="$(docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}} {{end}}' "$php" | awk '{print $1}')"
    image="$(docker inspect -f '{{.Config.Image}}' "$php")"
    [ -d "$root/vendor" ] || fail "no vendor/ in ${root}; run composer install for this checkout first (TESTING.md, \"Vanuit een git worktree\")."

    # Credentials travel as variables of this process and reach docker run
    # by name only (-e NAME), so they appear on no command line.
    DB_USERNAME="$(container_env "$php" DB_USERNAME)"
    DB_PASSWORD="$(container_env "$php" DB_PASSWORD)"
    DB_ROOT_PASSWORD="$(container_env "$php" DB_ROOT_PASSWORD)"
    export DB_USERNAME DB_PASSWORD DB_ROOT_PASSWORD

    local mount
    mount="$(host_root)"
    local common=(
        -d
        --network "$network"
        -v "${mount}:/var/www/html"
        --tmpfs /var/www/storage
        -e DB_HOST=mysql -e DB_PORT=3306
        -e DB_USERNAME -e DB_PASSWORD -e DB_ROOT_PASSWORD
        -e MAIL_HOST=mailpit -e MAIL_PORT=1025
        -e "DB_DATABASE=${database}" -e "TEST_DB_DATABASE=${database}"
        -e APP_ENV=production
        --label "mygdala.http-tier=${checkout_id}"
    )

    docker rm -f "$test_name" "$cms_name" >/dev/null 2>&1 || true

    # Every module the suite covers is ON here, whatever the test database
    # has stored as preference.
    docker run "${common[@]}" --name "$test_name" --network-alias php_test \
        -e MODULE_SHOP_ENABLED=true -e MODULE_PERSONALIZATION_ENABLED=true \
        -e MODULE_BLOG_ENABLED=true -e MODULE_ARTICLES_ENABLED=true -e MODULE_PORTFOLIO_ENABLED=true \
        -e MODULE_MULTILINGUAL_ENABLED=true -e MODULE_PAGE_THEMES_ENABLED=true \
        "$image" >/dev/null

    # The CMS-only deployment: every optional content module OFF (Page
    # Themes too, so a themed page is proven to fall back to the site theme),
    # the language layer the same as php_test so both render the same header.
    docker run "${common[@]}" --name "$cms_name" --network-alias php_cms \
        -e MODULE_SHOP_ENABLED=false -e MODULE_PERSONALIZATION_ENABLED=false \
        -e MODULE_BLOG_ENABLED=false -e MODULE_ARTICLES_ENABLED=false -e MODULE_PORTFOLIO_ENABLED=false \
        -e MODULE_MULTILINGUAL_ENABLED=true -e MODULE_PAGE_THEMES_ENABLED=false \
        "$image" >/dev/null

    # The entrypoint migrates the test database, then starts Apache.
    local tries=0
    until docker exec "$test_name" curl -fsS -o /dev/null http://php_test/index.php 2>/dev/null \
        && docker exec "$test_name" curl -fsS -o /dev/null http://php_cms/index.php 2>/dev/null; do
        tries=$((tries + 1))
        [ "$tries" -lt 90 ] || fail "the servers did not answer within 3 minutes; see docker logs ${test_name}"
        sleep 2
    done
    echo "http-tier: php_test and php_cms serve ${root} against ${database}"
}

run() {
    if ! is_running "$test_name" || ! is_running "$cms_name"; then
        up
    fi
    local php development
    php="$(php_container)"
    development="$(container_env "$php" DB_DATABASE)"
    [ ${#rest[@]} -gt 0 ] || rest=(--testsuite http)

    # DB_DATABASE names development ONLY so tests/bootstrap.php knows what to
    # refuse; the bootstrap then points every connection at the test database.
    docker exec -w /var/www/html \
        -e "DB_DATABASE=${development}" -e "TEST_DB_DATABASE=${database}" \
        "$test_name" php vendor/bin/phpunit "${rest[@]}"
}

down() {
    docker rm -f "$test_name" "$cms_name" >/dev/null 2>&1 || true
    echo "http-tier: removed ${test_name} and ${cms_name}"
}

status() {
    docker ps -a --filter "name=^/${project}-httptier-${checkout_id}-" --format '{{.Names}}\t{{.Status}}'
}

command="${1:-}"
[ $# -gt 0 ] && shift
parse_db "$@"

case "$command" in
    up) up ;;
    run) run ;;
    down) down ;;
    status) status ;;
    *) fail "usage: tests/Support/http-tier.sh up|run|down|status [--db <test database>] [phpunit arguments]" ;;
esac
