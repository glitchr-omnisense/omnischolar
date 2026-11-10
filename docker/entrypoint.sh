#!/bin/sh
# Composes the harness (this core + every source), installs it when it
# changed, then runs the command: the console by default, "test" for PHPUnit, "phpstan" for PHPStan on each package that has a phpstan.neon,
# "bare" for the script that uses the packages with no bundle, no container
# and no console, "composer ..." or "sh" as they are.
set -e
php /omnischolar/core/docker/harness/setup.php
cd /harness
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
elif [ composer.json -nt composer.lock ]; then
    composer update --no-interaction --no-progress
fi
case "${1:-}" in
    test) shift; exec vendor/bin/phpunit "$@" ;;
    bare) shift; exec php /omnischolar/core/docker/harness/bin/bare "$@" ;;
    phpstan)
        status=0
        for dir in /omnischolar/core /harness/packages/*; do
            [ -f "$dir/phpstan.neon" ] || continue
            echo "== $dir"
            vendor/bin/phpstan analyse --no-progress --memory-limit=1G -c "$dir/phpstan.neon" --autoload-file=/harness/vendor/autoload.php "$dir" || status=1
        done
        exit $status ;;
    composer|sh|php) exec "$@" ;;
    *) exec php /omnischolar/core/docker/harness/bin/omnischolar "$@" ;;
esac
