# SmartEinzug: Backup-Container. Alpine-Basis mit mariadb-client, age, rclone (bzw. curl als
# Ausweichpfad) und einem taeglichen Cron (busybox crond) innerhalb des Containers, der
# backup.sh ausfuehrt (siehe crontab und docker-compose.yml, Dienst "backup").
FROM alpine:3.20

RUN set -eux; \
    apk add --no-cache \
        bash \
        coreutils \
        gzip \
        mariadb-client \
        age \
        curl \
        tzdata \
        ca-certificates; \
    # rclone liegt im Community-Repository; falls es beim Bau nicht erreichbar ist, bleibt der
    # curl-Ausweichpfad in backup.sh (BACKUP_REMOTE mit https://-Ziel) nutzbar.
    (apk add --no-cache rclone || echo "rclone nicht installierbar, Ausweichpfad ueber curl bleibt verfuegbar")

COPY backup.sh /usr/local/bin/backup.sh
COPY restore-test.sh /usr/local/bin/restore-test.sh
RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/restore-test.sh

RUN printf '%s\n' '0 3 * * * /usr/local/bin/backup.sh >>/proc/1/fd/1 2>>/proc/1/fd/2' > /etc/crontabs/root

# crond im Vordergrund (PID 1), damit "restart: unless-stopped" und Docker-Logs greifen.
CMD ["crond", "-f", "-d", "8"]
