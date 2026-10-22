# siteImagePerso
Un petit site familial — volontairement old school — pour naviguer dans des photos et vidéos personnelles hébergées sur un partage réseau.

## Principe général
- Les médias sont stockés sur un espace réseau monté sur le serveur web.  
- L’arborescence des dossiers (« année / événement », par exemple) est directement reflétée dans la navigation du site.
- Les pages PHP se chargent de générer dynamiquement les miniatures, les aperçus et les légendes en s’appuyant sur cette arborescence.

## Fonctionnement du site

### Pages principales
- `index.php` valide la galerie et l'album demandés, puis compose la page : en-tête (`header.php`), menu des albums (`Gallery.php`), contenu, pied de page (`footer.php`). La mise en page s'adapte à l'écran : sur téléphone, le menu des albums s'ouvre avec le bouton « Albums ».
- `main.php` est la page d'accueil : photo principale, galeries et nouvelles de la famille.
- `RenderAlbum()` (dans `functions.php`) affiche un album : fil d'Ariane, introduction, sous-albums avec leur couverture, puis la grille des photos et vidéos.

### Navigation et diaporama
1. Depuis la page d'accueil, l'utilisateur choisit une galerie (ex. `GalleryPhotos` ou `GalleryCreative`).
2. Chaque dossier correspond à un album, à n'importe quelle profondeur (« 2024 / Vacances / Plage »). Le menu reproduit automatiquement l'arborescence du disque.
3. Les vignettes, aperçus et vidéos converties sont préparés à l'avance par le traitement en arrière-plan (voir plus bas) ; la page de l'album lit simplement la fiche `album.json` de l'album dans le cache.

### Visionneuse
`script/viewer.js` ouvre les photos et vidéos en plein écran :
- précédent et suivant avec les flèches, les touches ← → ou en glissant le doigt ; Échap, la croix, un glissement vers le bas ou le bouton retour du téléphone pour fermer ;
- lecture native des vidéos avec leur image d'aperçu ;
- lien « Original » vers le fichier en pleine qualité, légende et date de prise de vue ;
- l'adresse garde le média ouvert (`#media=nom`), ce qui permet de partager un lien vers une photo.

### Traitement automatique des médias
`bin/process-media.php` tourne en continu dans le conteneur Docker et repasse sur les galeries toutes les `SITEIMAGEPERSO_SCAN_INTERVAL` secondes (60 par défaut). Pour chaque fichier, il :
- reconnaît le **format réel** d'après le contenu, pas l'extension : JPEG, PNG, GIF, WebP, HEIC/HEIF (iPhone), AVIF, TIFF pour les photos ; MP4, MOV, AVI, MKV, WebM, MTS, WMV, MPEG… pour les vidéos. Les autres fichiers sont ignorés ;
- crée une vignette et un aperçu en **corrigeant l'orientation** d'après l'EXIF ;
- pour les formats que les navigateurs n'affichent pas (HEIC, TIFF…), crée en plus une version JPEG pleine taille ;
- pour les vidéos, extrait une image d'aperçu, lit la durée et l'orientation, et **convertit en MP4 (H.264/AAC) uniquement si l'original n'est pas lisible** par les navigateurs ;
- relève la date de prise de vue et la légende éventuellement présente dans les métadonnées.

Tout est rangé dans le cache (`cache/` par défaut), qui reproduit l'arborescence des galeries. **Les originaux ne sont jamais modifiés** et rien n'est écrit dans les dossiers d'albums. Un fichier déjà traité et inchangé n'est pas retraité ; un fichier supprimé disparaît du cache au passage suivant. Le cache peut être effacé sans risque : il se reconstruit tout seul.

Lancement à la main (hors Docker) : `php bin/process-media.php --once -v`. Outils nécessaires : `vipsthumbnail` avec la prise en charge HEIF (paquets `vips-tools` et `vips-heif` sous Alpine, `libvips-tools` et `libheif-plugin-libde265` sous Debian/Ubuntu), `ffmpeg` et `ffprobe`.

### Légendes
Chaque album peut contenir un fichier texte `legendes.txt` :

```
Vacances au bord de la mer, été 2024.

IMG_0001.jpg: Alice sur la plage
  une ligne qui commence par un espace prolonge la légende
video_anniversaire.mp4: Les bougies
```

- Les lignes avant la première légende forment l'introduction de l'album.
- Une légende s'écrit `nom_du_fichier: texte`. Les lignes qui commencent par `#` sont ignorées.
- Si une photo n'a pas de légende dans `legendes.txt`, le site reprend celle de ses métadonnées (EXIF), si elle existe.
- Les modifications de `legendes.txt` s'affichent immédiatement, sans attendre le traitement.

Pour convertir les anciennes légendes (dossier `Legend/` avec un fichier HTML par photo) : `php bin/migrate-legends.php --dry-run` pour voir le résultat, puis sans `--dry-run` pour écrire les fichiers. Les anciens dossiers `Legend/`, `Small/` et `Middle/` ne sont pas supprimés, ils sont simplement ignorés par le site.

### Préparer un nouvel album
1. Créer un dossier dans `GalleryPhotos/` ou `GalleryCreative/` (des sous-dossiers sont possibles) et y déposer les photos et vidéos telles quelles, sans conversion.
2. (Facultatif) Ajouter un fichier `legendes.txt`.
3. L'album apparaît sur le site au fil du traitement, en général dans la minute pour les photos. Les vidéos à convertir demandent plus de temps.

### Personnalisation
- `ImagesSite/` contient les images utilisées sur la page d’accueil (ex. `MainPhoto.jpg`, affichée en haut de l’accueil si elle existe).
- `main.php` liste les actualités à mettre à jour manuellement.
- Les noms des galeries se règlent dans `GetGalleryLabel()` (`lib/media.php`) ou avec `SITEIMAGEPERSO_GALLERY_LABELS="GalleryPhotos=Galerie des gosses;GalleryCreative=Galerie des glaces"`.
- `header.php` et `css/style.css` permettent d’adapter le bandeau et l’apparence générale (les couleurs sont regroupées en haut de `css/style.css`).

## Diagnostics sécurisés
`InfoSite.php` est protégé pour éviter toute exposition accidentelle en production. Par défaut, la page renvoie une erreur 404.

Pour utiliser les diagnostics lors d’une intervention administrative :

1. Définir la variable d’environnement `SITEIMAGEPERSO_ENABLE_ADMIN_DIAGNOSTICS` à `true`, `1`, `yes` ou `on`.
2. Définir également `SITEIMAGEPERSO_ADMIN_DIAGNOSTICS_USER` et `SITEIMAGEPERSO_ADMIN_DIAGNOSTICS_PASSWORD` avec les identifiants Basic Auth réservés à l’équipe d’administration.
3. Ouvrir `InfoSite.php` après authentification HTTP.

Ne déployez jamais ces variables d’environnement en production : tant qu’elles sont absentes ou vides, la page reste inaccessible et renvoie un statut 404. Les informations techniques (configuration PHP, capacités de GD, fiche matériel chargée depuis `matos.html`) restent ainsi limitées aux outils d’administration sécurisés.

## Dépendances techniques
- PHP avec les extensions `fileinfo`, `gd`, `exif` et `mbstring`.
- `vipsthumbnail` (libvips, avec la prise en charge HEIF), `ffmpeg` et `ffprobe` pour le traitement des médias.
- Serveur web configuré pour pointer vers ce projet (voir les fichiers du dossier `apache2/` pour un exemple de configuration).

## Déploiement avec Docker

Un environnement Docker est fourni pour simplifier la mise en place du site avec deux montages de volumes vers l’hôte, un pour chaque galerie.

### Préparation
1. Copiez le fichier `.env.example` en `.env` puis adaptez les chemins `SITEIMAGEPERSO_GALLERY_PHOTOS` et `SITEIMAGEPERSO_GALLERY_CREATIVE` vers vos dossiers d’albums (par exemple un partage Samba monté localement), et `SITEIMAGEPERSO_CACHE` vers le dossier du cache.
2. Vérifiez que les dossiers pointés existent et que l’utilisateur Docker pourra y écrire. À défaut, ils seront créés au démarrage du conteneur.
3. Réglez la connexion Google (section suivante) : sans elle, le site ne s’affiche pas.

### Connexion Google
Le site n’est visible qu’après connexion avec un compte Google dont l’adresse figure dans une liste. C’est [oauth2-proxy](https://oauth2-proxy.github.io/oauth2-proxy/), un second conteneur placé devant le site, qui s’en charge : le conteneur du site ne publie plus aucun port. Il occupe environ 20 Mo de mémoire.

**Côté Google** (une seule fois), dans la [Google Cloud Console](https://console.cloud.google.com/) :
1. Créez un projet (par exemple « Galerie famille »).
2. *API et services > Écran de consentement OAuth* (ou *Google Auth Platform*) : type d’utilisateurs **Externe**, nom de l’application, adresse de contact. Les autorisations par défaut (`email`, `profile`, `openid`) suffisent. Publiez l’application (*En production*) : en mode *Test*, seuls les comptes déclarés comme testeurs chez Google peuvent se connecter. Ces autorisations de base ne demandent normalement pas de validation par Google.
3. *Identifiants > Créer des identifiants > ID client OAuth* : type **Application Web**. Dans *URI de redirection autorisés*, ajoutez `https://<votre-domaine>/oauth2/callback` (et `http://localhost:8080/oauth2/callback` pour tester sur votre ordinateur).
4. Recopiez l’ID client et le code secret dans `.env` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`).

**Dans `.env`** :
- `SITEIMAGEPERSO_PUBLIC_URL` : l’adresse du site, sans `/` final (`https://famille.example.com`) ;
- `SITEIMAGEPERSO_COOKIE_SECRET` : 32 caractères aléatoires, obtenus avec `openssl rand -hex 16` ;
- `SITEIMAGEPERSO_SESSION_DURATION` : durée avant de devoir se reconnecter (`720h`, 30 jours, par défaut).

**Liste des adresses autorisées** : fichier `emails.txt` dans le dossier de configuration du site (`SITEIMAGEPERSO_CONFIG`, `./config` par défaut, monté en lecture seule dans le conteneur de connexion), une adresse par ligne ; les lignes qui commencent par `#` sont ignorées, majuscules et minuscules sont équivalentes. Exemple : `config.example/emails.txt`. Une modification est prise en compte immédiatement, sans redémarrer : une adresse retirée est refusée dès sa visite suivante. Une personne connectée mais absente de la liste reçoit une erreur 403. Le fichier doit exister et être lisible par tous (`chmod 644`), car oauth2-proxy ne tourne pas en root ; sinon le conteneur de connexion ne démarre pas.

Une fois connecté, l’adresse et un lien « Déconnexion » s’affichent en haut à droite. Après une déconnexion, Google propose de choisir le compte, ce qui permet de changer de personne sur un ordinateur partagé.

**HTTPS** : Google n’accepte une adresse de retour en `http` que pour `localhost`. Sur le serveur, un reverse proxy garde le certificat et transmet les requêtes au conteneur :
- **Caddy** : voir `caddy/Caddyfile.example` (une ligne `reverse_proxy 127.0.0.1:8080`). Si Caddy tourne lui-même dans Docker, le plus simple est de déclarer la galerie et sa connexion Google directement dans le docker-compose de Caddy : voir `caddy/compose-avec-caddy.example.yml` (réglages dans `environment:`, liste des adresses dans un fichier monté, relu sans redémarrage) et `reverse_proxy galerie-auth:4180` ;
- **Apache** : voir `apache2/site-available/002-example.com-docker-le-ssl.conf` (modules `proxy`, `proxy_http` et `headers`).

**Moteurs de recherche** : oauth2-proxy répond lui-même à `/robots.txt`, sans connexion, par `Disallow: /` (aucune page à explorer), et les deux exemples de reverse proxy ajoutent l’en-tête `X-Robots-Tag: noindex, nofollow` à toutes les réponses, page de connexion comprise. Vérification : `curl -sI https://<votre-domaine>/robots.txt` doit afficher cet en-tête, et `curl -s https://<votre-domaine>/robots.txt` le contenu `Disallow: /`. Le fichier `robots.txt` du dépôt dit la même chose, pour le cas où le site serait publié sans la connexion Google.

Le port du conteneur n’écoute que sur `127.0.0.1` (`SITEIMAGEPERSO_HTTP_BIND`) : on ne peut pas contourner la connexion en passant par le port 8080 depuis l’extérieur.

**Test sur son ordinateur** : `SITEIMAGEPERSO_PUBLIC_URL=http://localhost:8080` et `SITEIMAGEPERSO_COOKIE_SECURE=false` dans `.env`, avec l’adresse de retour `localhost` déclarée chez Google.

### Construction et lancement
1. Construisez l’image et démarrez le service :
   ```
   docker compose up --build -d
   ```
2. Le site est accessible sur le port défini par `SITEIMAGEPERSO_HTTP_PORT` (8080 par défaut), sur `127.0.0.1` seulement, ou à l’adresse publique à travers l’Apache de l’hôte. Si la connexion ne s’affiche pas, `docker compose logs oauth2-proxy` indique le réglage manquant.

### Notes complémentaires
- Les répertoires `GalleryPhotos/` et `GalleryCreative/` présents dans ce dépôt servent de points de montage par défaut. Ils restent vides grâce aux `.gitignore` internes afin d’éviter d’ajouter les médias au dépôt.
- Mémoire : au repos, le conteneur occupe environ 50 Mo. Le pic vient de la conversion d'une vidéo (environ 300 Mo en 1080p avec `SITEIMAGEPERSO_FFMPEG_THREADS=2`, la valeur par défaut ; 1 fil réduit encore la mémoire mais allonge la conversion). Apache est limité à `SITEIMAGEPERSO_MAX_WORKERS` processus (10 par défaut, au lieu de 150). Chaque connexion ouverte, vidéo en cours de lecture comprise, occupe un processus : augmentez cette valeur si plusieurs personnes regardent des vidéos en même temps.
- HTTPS : le conteneur sert du HTTP et ne redirige pas vers HTTPS. Derrière un reverse proxy qui gère le certificat et transmet l'en-tête `X-Forwarded-Proto`, mettez `SITEIMAGEPERSO_FORCE_HTTPS=1` pour rediriger les visiteurs arrivés en HTTP. Hors Docker, `.htaccess` redirige toujours vers HTTPS.
- Le script d’entrée du conteneur prépare le dossier du cache et lance le traitement des médias en arrière-plan (désactivable avec `SITEIMAGEPERSO_DISABLE_PROCESSOR=1`). Son journal est visible avec `docker compose logs -f`.

## Tests
`php tests/MediaProcessingTest.php` vérifie la détection des formats, l'orientation EXIF, la conversion des vidéos, le cache, la lecture et la migration des légendes (les cas qui demandent `vipsthumbnail` ou `ffmpeg` sont ignorés si ces outils manquent). Dans Docker, tout doit passer, y compris la photo HEIC d'exemple (`tests/samples/iphone.heic`) : `docker compose build && docker compose run --rm --entrypoint php siteimageperso tests/MediaProcessingTest.php`. C'est la vérification à faire après chaque changement de l'image (version d'Alpine, paquets).

Le fichier `tests/VideoAccessTest.php` lance un serveur PHP intégré et vérifie que `video.php` répond correctement (statuts 200/403/404). Exécuter le script `php tests/VideoAccessTest.php` permet de s’assurer que la diffusion vidéo reste sécurisée.

