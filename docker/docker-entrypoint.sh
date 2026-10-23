#!/bin/sh
set -eu

log_message() {
    MESSAGE="$1"
    echo "[siteimageperso] $MESSAGE"
}

prepare_directory() {
    TARGET_PATH="$1"

    if [ -d "$TARGET_PATH" ]; then
        return 0
    fi

    log_message "Création du dossier $TARGET_PATH"
    mkdir -p "$TARGET_PATH"
}

assign_owner() {
    TARGET_PATH="$1"
    OWNER_VALUE="$2"

    if chown "$OWNER_VALUE" "$TARGET_PATH"; then
        return 0
    fi

    log_message "Impossible de modifier le propriétaire de $TARGET_PATH."
    log_message "Vérifiez les droits du dossier monté."
    return 0
}

assign_permissions() {
    TARGET_PATH="$1"
    PERMISSIONS_VALUE="$2"

    if chmod "$PERMISSIONS_VALUE" "$TARGET_PATH"; then
        return 0
    fi

    log_message "Impossible de modifier les permissions de $TARGET_PATH."
    log_message "Vérifiez les droits du dossier monté."
    return 0
}

select_web_owner() {
    if [ "${SITEIMAGEPERSO_WEB_OWNER:-}" != "" ]; then
        log_message "Utilisation du propriétaire configuré ${SITEIMAGEPERSO_WEB_OWNER}."
        echo "$SITEIMAGEPERSO_WEB_OWNER"
        return 0
    fi

    for CANDIDATE_USER in apache www-data nginx; do
        if id -u "$CANDIDATE_USER" >/dev/null 2>&1; then
            PRIMARY_GROUP=$(id -gn "$CANDIDATE_USER")
            echo "$CANDIDATE_USER:$PRIMARY_GROUP"
            return 0
        fi
    done

    log_message "Aucun utilisateur web standard détecté. Utilisation de root:root."
    echo "root:root"
    return 0
}

initialize_gallery_directory() {
    TARGET_PATH="$1"
    OWNER_VALUE="$2"

    prepare_directory "$TARGET_PATH"
    assign_owner "$TARGET_PATH" "$OWNER_VALUE"
    assign_permissions "$TARGET_PATH" "0775"
}

WEB_OWNER=$(select_web_owner)
log_message "Propriétaire détecté pour les galeries : $WEB_OWNER"

initialize_gallery_directory "/var/www/html/GalleryPhotos" "$WEB_OWNER"
initialize_gallery_directory "/var/www/html/GalleryCreative" "$WEB_OWNER"
initialize_gallery_directory "/var/www/html/cache" "$WEB_OWNER"

# Liste des médias en erreur (voir bin/process-media.php), seulement si le dossier est monté
if [ -d /config/journal ]; then
    assign_owner "/config/journal" "$WEB_OWNER"
fi

# « J'aime » des médias (voir lib/likes.php), seulement si le dossier est monté
if [ -d /config/likes ]; then
    assign_owner "/config/likes" "$WEB_OWNER"
fi

# su-exec sur Alpine, setpriv ailleurs
run_as_web_user() {
    if command -v su-exec >/dev/null 2>&1; then
        su-exec "$WEB_USER:$WEB_GROUP" "$@"
    else
        setpriv --reuid="$WEB_USER" --regid="$WEB_GROUP" --init-groups "$@"
    fi
}

# Traitement des médias en arrière-plan : vignettes, aperçus, vidéos converties.
# Il repasse sur les galeries toutes les SITEIMAGEPERSO_SCAN_INTERVAL secondes.
start_media_processor() {
    WEB_USER=${WEB_OWNER%%:*}
    WEB_GROUP=${WEB_OWNER#*:}
    SCAN_INTERVAL="${SITEIMAGEPERSO_SCAN_INTERVAL:-60}"

    log_message "Traitement des médias toutes les ${SCAN_INTERVAL} s."

    (
        while true; do
            run_as_web_user php /var/www/html/bin/process-media.php --interval="$SCAN_INTERVAL" || true
            sleep 10
        done
    ) &
}

# Nombre maximal de processus Apache (voir docker/apache/site.conf)
export SITEIMAGEPERSO_MAX_WORKERS="${SITEIMAGEPERSO_MAX_WORKERS:-10}"

# Redirection vers HTTPS (voir .htaccess) : 1 seulement derrière un reverse proxy HTTPS
if [ "${SITEIMAGEPERSO_FORCE_HTTPS:-}" = "1" ]; then
    export SITEIMAGEPERSO_FORCE_HTTPS=1
else
    export SITEIMAGEPERSO_FORCE_HTTPS=0
fi

if [ "${SITEIMAGEPERSO_DISABLE_PROCESSOR:-}" = "" ]; then
    start_media_processor
fi

exec "$@"
