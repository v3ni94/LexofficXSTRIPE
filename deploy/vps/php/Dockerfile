# SmartEinzug: PHP-Image fuer Web (php-fpm), Scheduler und alle Worker-Pools (dasselbe Image,
# nur der "command:" in docker-compose.yml unterscheidet die Rolle). Basis: php:8.4-fpm-alpine.
#
# Ein Deployment baut dieses Image nur neu, wenn sich etwas unter deploy/vps/php/ geaendert hat
# (siehe scripts/deploy.sh); sonst wird das zuletzt gebaute Image weiterverwendet.
FROM php:8.4-fpm-alpine

# Erweiterungen laut Vorgabe: pdo_mysql, gd, intl, opcache (im Basisimage meist schon vorhanden,
# hier zur Klarheit erneut installiert), redis (PECL), zip, bcmath, pcntl.
# .build-deps ist eine virtuelle Gruppe: sie wird nach dem Bau vollstaendig wieder entfernt, ohne
# die dafuer zusaetzlich installierten Laufzeitbibliotheken (icu-libs, libpng, ...) zu beruehren.
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        libzip-dev \
        linux-headers; \
    apk add --no-cache \
        icu-libs \
        libpng \
        libjpeg-turbo \
        libwebp \
        freetype \
        libzip \
        tzdata; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql gd intl opcache zip bcmath pcntl; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear /tmp/*.tar.gz

# Eigene php.ini- und Pool-Einstellungen (ersetzen die Standarddateien des Basisimages vollstaendig,
# siehe Kommentare in den Dateien selbst).
COPY php.ini /usr/local/etc/php/conf.d/zz-smarteinzug.ini
COPY www.conf /usr/local/etc/php-fpm.d/www.conf

# Sitzungsordner (in docker-compose.yml als Bind-Mount /opt/smarteinzug/shared/sessions eingebunden;
# hier nur als Fallback mit sicheren Rechten angelegt). Arbeitsverzeichnis ist das aktive Release.
RUN install -d -m 1733 /var/lib/php/sessions
WORKDIR /opt/smarteinzug/releases/current

# Bewusst KEIN Standard-Healthcheck im Image: dasselbe Image traegt Web (php-fpm), Scheduler, Worker und
# den Metrik-Sammler; ein gemeinsamer Vorgabewert waere fuer mindestens eine dieser Rollen falsch. Genau das
# ist beim ersten Deployment passiert: der Dienst "metrics" erbte den Worker-Healthcheck
# (bin/healthcheck.php --heartbeat), erzeugt aber keinen Worker-Heartbeat, meldete dauerhaft
# "UNGESUND: heartbeat: kein frischer Heartbeat" und brach deploy.sh ab, obwohl der Prozess lief.
# Seitdem definiert jeder Dienst in docker-compose.yml seinen passenden Healthcheck selbst
# (Web: --db, Scheduler/Worker: --heartbeat, Metrik-Sammler: --metrics); tools/compose-check.py prueft das.
# HEALTHCHECK NONE stellt sicher, dass ein vergessener Eintrag als "kein Healthcheck" auffaellt und nicht
# als falsch-negatives Ergebnis eines fremden Checks.
HEALTHCHECK NONE
