#!/usr/bin/env bash
set -euo pipefail

# A NEW datadir and *_vc_test schema are used; the normal application DB is never read.
# MariaDB 10.4 is the deployment target. This runner reports the native test version.
project_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
task_dir=$(mktemp -d "${TMPDIR:-/tmp}/anabelka-vc01.XXXXXXXX")
data_dir="$task_dir/data"
server_pid=''
cleanup() {
    if [[ -n "$server_pid" ]]; then
        kill -TERM "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    rm -rf -- "$task_dir"
}
trap cleanup EXIT INT TERM

for executable in php mariadbd mariadb-install-db mariadb; do
    command -v "$executable" >/dev/null || { echo "Missing executable: $executable" >&2; exit 2; }
done
php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 2);' || { echo 'pdo_mysql is required.' >&2; exit 2; }
mariadb-install-db --no-defaults --datadir="$data_dir" --auth-root-authentication-method=normal --skip-test-db >"$task_dir/install.log" 2>&1
# An empty socket also supports sandboxes that permit TCP but deny Unix sockets.
test_port=$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s)exit(1); echo parse_url("tcp://".stream_socket_get_name($s,false),PHP_URL_PORT);')
server_args=(--no-defaults "--datadir=$data_dir" --socket= "--port=$test_port" --bind-address=127.0.0.1 --skip-log-bin "--pid-file=$task_dir/server.pid" "--log-error=$task_dir/server.log")
if [[ $(id -u) == 0 ]]; then server_args+=(--user=root); fi
mariadbd "${server_args[@]}" >"$task_dir/console.log" 2>&1 &
server_pid=$!
ready=0
for (( attempt=0; attempt<100; ++attempt )); do
    if mariadb --no-defaults --protocol=tcp --host=127.0.0.1 --port="$test_port" --user=root --execute='SELECT 1' >/dev/null 2>&1; then ready=1; break; fi
    if ! kill -0 "$server_pid" 2>/dev/null; then cat "$task_dir/server.log" >&2; exit 1; fi
    sleep 0.1
done
if [[ $ready != 1 ]]; then cat "$task_dir/server.log" >&2; exit 1; fi
mariadb --no-defaults --protocol=tcp --host=127.0.0.1 --port="$test_port" --user=root --execute='CREATE DATABASE anabelka_vc_test CHARACTER SET utf8mb4; SELECT VERSION();'
export VC_TEST_DSN="mysql:host=127.0.0.1;port=$test_port;dbname=anabelka_vc_test;charset=utf8mb4"
export VC_TEST_USER=root VC_TEST_PASSWORD=''
php "$project_root/tests/visitor_counter_mariadb.php"
