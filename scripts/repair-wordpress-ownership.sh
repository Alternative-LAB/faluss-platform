#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 2 ]]; then
  echo "Usage: $0 BACKUP_DIRECTORY DOCKER_CONTAINER [DOCKER_CONTAINER...]" >&2
  exit 2
fi

backup_directory="$(realpath -m "$1")"
shift
umask 077
mkdir -p "$backup_directory"

for container in "$@"; do
  if [[ ! "$container" =~ ^[a-zA-Z0-9_.-]+$ ]]; then
    echo "Invalid container name: $container" >&2
    exit 2
  fi

  docker exec "$container" sh -lc '
    set -eu
    plugin=/var/www/html/wp-content/plugins/faluss-platform
    test -f "$plugin/faluss-platform.php"
    test -z "$(find "$plugin" -type l -print -quit)"
    test "$(id -u www-data)" -eq 33
    su -s /bin/sh www-data -c "test -w /var/www/html/wp-content/plugins"
  '

  backup="$backup_directory/$container-faluss-platform-before-ownership-repair.tar.gz"
  if [[ -e "$backup" ]]; then
    echo "Backup already exists: $backup" >&2
    exit 1
  fi
  docker exec "$container" tar -C /var/www/html/wp-content/plugins -czf - faluss-platform > "$backup"
  tar -tzf "$backup" >/dev/null

  docker exec "$container" sh -lc '
    set -eu
    plugin=/var/www/html/wp-content/plugins/faluss-platform
    chown -R www-data:www-data "$plugin"
    su -s /bin/sh www-data -c "test -w $plugin/faluss-platform.php && test -w $plugin/assets && test -w $plugin/vendor"
  '
  echo "Validated writable plugin in $container; backup: $backup"
done
