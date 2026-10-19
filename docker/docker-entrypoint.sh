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

    for CANDIDATE_USER in www-data apache nginx; do
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

# Traitement des médias en arrière-plan : vignettes, aperçus, vidéos converties.
# Il repasse sur les galeries toutes les SITEIMAGEPERSO_SCAN_INTERVAL secondes.
start_media_processor() {
    WEB_USER=${WEB_OWNER%%:*}
    WEB_GROUP=${WEB_OWNER#*:}
    SCAN_INTERVAL="${SITEIMAGEPERSO_SCAN_INTERVAL:-60}"

    log_message "Traitement des médias toutes les ${SCAN_INTERVAL} s."

    (
        while true; do
            setpriv --reuid="$WEB_USER" --regid="$WEB_GROUP" --init-groups \
                php /var/www/html/bin/process-media.php --interval="$SCAN_INTERVAL" || true
            sleep 10
        done
    ) &
}

if [ "${SITEIMAGEPERSO_DISABLE_PROCESSOR:-}" = "" ]; then
    start_media_processor
fi

exec "$@"
