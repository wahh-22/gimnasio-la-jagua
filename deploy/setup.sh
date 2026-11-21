#!/usr/bin/env bash
# Fresh dedicated Ubuntu 24.04 appliance only; invoked by root, never with bash -x.
set -Eeuo pipefail
umask 077
fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ $EUID == 0 ]] || fail 'Run as root.'
[[ ( $# == 2 || ( $# == 4 && $3 == --acme-email ) ) && $1 == --hostname ]] || fail 'Usage: bash /srv/gimnasio/deploy/setup.sh --hostname HOST [--acme-email EMAIL]'
host=$2; email=${4:-}
# DNS names only. Ubuntu Caddy does not provide public IP certificates.
[[ ${#host} -le 253 && $host == *.* && $host =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ && ! $host =~ ^[0-9.]+$ && $host != *..* ]] || fail 'Supply a lowercase public DNS hostname, not a bare IP.'
IFS=. read -r -a labels <<< "$host"
for label in "${labels[@]}"; do
    [[ ${#label} -le 63 && $label != -* && $label != *- ]] || fail 'Invalid DNS label.'
done
[[ -z $email || $email =~ ^[a-zA-Z0-9._+%-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]+$ ]] || fail 'Supply a valid ACME contact email.'
# shellcheck disable=SC1091
source /etc/os-release
[[ $ID == ubuntu && $VERSION_ID == 24.04 ]] || fail 'Requires Ubuntu 24.04.'
[[ -f /srv/gimnasio/public/index.php && -f /srv/gimnasio/app/Schema.php ]] || fail 'Extract the prototype into /srv/gimnasio first.'
[[ $(readlink -f /srv/gimnasio) == /srv/gimnasio ]] || fail 'Application root must not be a symlink.'
# Never follow package-provided symlinks into other paths.
[[ -z $(find /srv/gimnasio -type l -print -quit) ]] || fail 'Symlinks in the package are not supported.'
exec 9>/run/lock/gimnasio-setup.lock
flock -n 9 || fail 'Another setup is running.'
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y apache2 libapache2-mod-php8.3 php8.3-cli php8.3-mysql php8.3-mbstring mysql-server caddy libapache2-mod-evasive curl dnsutils
install -d -o root -g root -m 0700 /etc/gimnasio
# Require DNS to point to this Lightsail static public IP before invocation. The
# operator verifies external routing; ACME is the final reachability check.
getent ahostsv4 "$host" >/dev/null || fail 'Hostname does not resolve.'
install -d -o root -g www-data -m 0750 /var/lib/gimnasio
install -d -o www-data -g www-data -m 0700 /var/lib/gimnasio/sessions
install -d -o www-data -g www-data -m 0700 /var/log/apache2/evasive
chown -R root:www-data /srv/gimnasio
find /srv/gimnasio -type d -exec chmod 0750 {} +
find /srv/gimnasio -type f -exec chmod 0640 {} +
# Low-memory defaults: no swap file or destructive existing-data operations.
cat > /etc/mysql/mysql.conf.d/zz-gimnasio.cnf <<'MYSQL'
[mysqld]
bind-address = 127.0.0.1
mysqlx-bind-address = 127.0.0.1
skip-name-resolve
innodb_buffer_pool_size = 128M
innodb_log_buffer_size = 8M
max_connections = 20
performance_schema = OFF
table_open_cache = 400
tmp_table_size = 8M
max_heap_table_size = 8M
MYSQL
chmod 0644 /etc/mysql/mysql.conf.d/zz-gimnasio.cnf
systemctl enable mysql
systemctl restart mysql
php /srv/gimnasio/deploy/provision.php
cat > /etc/apache2/ports.conf <<'PORTS'
# Managed by gimnasio; no public HTTP or TLS listener here.
Listen 127.0.0.1:8080
PORTS
cat > /etc/apache2/conf-available/gimnasio.conf <<'APACHE'
ServerName localhost
ServerTokens Prod
ServerSignature Off
TraceEnable Off
Timeout 30
KeepAlive On
KeepAliveTimeout 2
MaxKeepAliveRequests 50
<IfModule mpm_prefork_module>
    StartServers 1
    MinSpareServers 1
    MaxSpareServers 2
    MaxRequestWorkers 4
    MaxConnectionsPerChild 500
</IfModule>
<IfModule evasive20_module>
    DOSHashTableSize 3097
    DOSPageCount 5
    DOSSiteCount 30
    DOSPageInterval 2
    DOSSiteInterval 2
    DOSBlockingPeriod 60
    DOSLogDir /var/log/apache2/evasive
</IfModule>
APACHE
cat > /etc/apache2/sites-available/gimnasio.conf <<'APACHE'
<VirtualHost 127.0.0.1:8080>
    ServerName gimnasio
    DocumentRoot /srv/gimnasio/public
    RemoteIPHeader X-Forwarded-For
    RemoteIPTrustedProxy 127.0.0.1
    # Only Caddy can reach this loopback listener. Do NOT expose port 8080.
    SetEnv HTTPS on
    Include /etc/gimnasio/apache-env.conf
    <Directory /srv/gimnasio/public>
        AllowOverride None
        Options -Indexes -Includes -ExecCGI -MultiViews
        Require all granted
        DirectoryIndex index.php
        <FilesMatch "^(?!index\.php$).*\.php$">
            Require all denied
        </FilesMatch>
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/gimnasio-error.log
    # Deliberately omit query string, referer, cookies and request bodies.
    CustomLog ${APACHE_LOG_DIR}/gimnasio-access.log "%a %t %m %U %>s %b"
</VirtualHost>
APACHE
cat > /etc/php/8.3/apache2/conf.d/99-gimnasio.ini <<'PHPINI'
expose_php = Off
display_errors = Off
log_errors = On
memory_limit = 64M
max_execution_time = 20
post_max_size = 1M
upload_max_filesize = 1M
file_uploads = Off
session.use_strict_mode = 1
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = Strict
session.gc_maxlifetime = 1200
PHPINI
a2dissite 000-default default-ssl
a2enmod php8.3 remoteip evasive
a2enconf gimnasio
a2ensite gimnasio
apache2ctl configtest
systemctl enable apache2
systemctl restart apache2
# DNS hostname triggers Caddy's normal public ACME HTTPS (not tls internal).
# Ubuntu's packaged Caddy needs no plugin or network-installed binary.
cat > /etc/caddy/Caddyfile <<CADDY
{
    ${email:+email $email}
    admin 127.0.0.1:2019
}
$host {
    reverse_proxy 127.0.0.1:8080
}
CADDY
chmod 0644 /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
systemctl enable caddy
systemctl restart caddy
printf '%s\n' 'Setup complete; public certificate may still be provisioning. Run deploy/verify.sh.'
printf 'URL: https://%s/?page=ingreso\n' "$host"
printf '%s\n' 'Synthetic admin credentials: /etc/gimnasio/state.json (root only; never paste into logs/chat).'
