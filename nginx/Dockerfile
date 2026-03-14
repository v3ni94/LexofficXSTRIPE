FROM nginx:alpine

# Install gettext for envsubst
RUN apk add --no-cache gettext

# Remove default config
RUN rm -f /etc/nginx/conf.d/default.conf

# Copy both configs (dev and prod template)
COPY nginx.conf /etc/nginx/templates/nginx.conf.template
COPY nginx.dev.conf /etc/nginx/nginx.dev.conf

# Entrypoint: substitute ${DOMAIN} in template then start nginx
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 80 443

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
