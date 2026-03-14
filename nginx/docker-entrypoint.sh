#!/bin/sh
set -e

NGINX_CONF="${NGINX_CONF:-nginx.conf}"

if [ "$NGINX_CONF" = "nginx.dev.conf" ]; then
    cp /etc/nginx/nginx.dev.conf /etc/nginx/conf.d/default.conf
else
    # Substitute only ${DOMAIN}, leave nginx variables intact
    envsubst '${DOMAIN}' < /etc/nginx/templates/nginx.conf.template \
        > /etc/nginx/conf.d/default.conf
fi

exec "$@"
