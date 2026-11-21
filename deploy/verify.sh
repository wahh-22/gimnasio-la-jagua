#!/usr/bin/env bash
# Read-only checks apart from normal anonymous HTTP session/log writes.
set -Eeuo pipefail
[[ $EUID == 0 && $# == 1 ]] || { printf '%s\n' 'Usage: sudo bash /srv/gimnasio/deploy/verify.sh HOST' >&2; exit 1; }
host=$1
[[ $host =~ ^[a-z0-9][a-z0-9.-]+[a-z0-9]$ ]] || exit 1
for service in mysql apache2 caddy; do systemctl is-active --quiet "$service"; done
php -r 'foreach (["pdo_mysql", "mbstring", "session"] as $m) if (!extension_loaded($m)) exit(1); if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) exit(1);'
php /srv/gimnasio/deploy/provision.php --check
apache2ctl configtest
apache2ctl -M 2>/dev/null | grep -q evasive20_module
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
# Check actual listeners, not just the configuration files.
ss -H -lnt | awk '
$4 ~ /:(3306|33060|8080)$/ {
    seen=1
    if ($4 !~ /^127\.0\.0\.1:/) bad=1
    if ($4 ~ /:3306$/) mysql=1
    if ($4 ~ /:8080$/) apache=1
}
END {exit (!seen || bad || !mysql || !apache)}'
# TLS certificate chain/hostname must validate against OS trust. No -k fallback.
ready=0
for ((i=0;i<20;i++)); do
    if curl --silent --fail --connect-timeout 3 --max-time 8 --resolve "$host:443:127.0.0.1" "https://$host/?page=ingreso" -o /dev/null 2>/dev/null; then ready=1; break; fi
    sleep 3
done
[[ $ready == 1 ]] || { printf '%s\n' 'HTTPS failed: check DNS, Lightsail firewall 80/443 and local Caddy journal (do not share secrets).' >&2; exit 1; }
redirect=$(curl --silent --show-error --max-time 10 --resolve "$host:80:127.0.0.1" "http://$host/" -o /dev/null -w '%{redirect_url}')
[[ $redirect == "https://$host/" ]]
# Inspect cookie attributes without emitting session identifiers.
curl --silent --show-error --fail --max-time 10 --resolve "$host:443:127.0.0.1" "https://$host/?page=ingreso" -D - -o /dev/null |
    awk 'tolower($0) ~ /^set-cookie:/ {s=tolower($0); if (s ~ /; secure/ && s ~ /; httponly/ && s ~ /; samesite=strict/) ok=1} END {exit !ok}'
for path in app/bootstrap.php deploy/provision.php var/sessions; do
    status=$(curl --silent --show-error --max-time 10 --resolve "$host:443:127.0.0.1" "https://$host/$path" -o /dev/null -w '%{http_code}')
    [[ $status == 404 || $status == 403 ]]
done
printf '%s\n' 'PASS: services, PHP, SQL grants, private listeners, trusted HTTPS, redirect, secure session cookie, nonpublic paths.'
printf '%s\n' 'External reachability, login, burst limiting and browser workflows still require operator verification.'
