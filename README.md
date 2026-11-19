<h1>
  <img src="./assets/galleryPerso-icon.png" alt="" width="48" height="48" align="middle">
  galleryPerso
</h1>

![galleryPerso — Photo prints and a video thumbnail in a family album](./assets/galleryPerso-social.jpg)

# galleryPerso
A small family website — deliberately old school — for browsing personal photos and videos hosted on a network share.

## Overview
- The media are stored on a network share mounted on the web server.  
- The folder tree ("year / event", for example) is mirrored directly in the site navigation.
- The PHP pages generate thumbnails and previews dynamically based on that tree.

## How the site works

### Main pages
- `index.php` validates the requested gallery and album, then builds the page: header (`header.php`), album menu (`Gallery.php`), content, footer (`footer.php`). The layout adapts to the screen: on a phone, the album menu opens with the « Albums » button.
- `main.php` is the home page: main photo, galleries and family news.
- `RenderAlbum()` (in `functions.php`) renders an album: breadcrumb, introduction, sub-albums with their cover, then the grid of photos and videos.

### Navigation and slideshow
1. From the home page, the user picks a gallery (e.g. `GalleryPhotos` or `GalleryCreative`).
2. Each folder is an album, at any depth ("2024 / Holidays / Beach"). The menu automatically mirrors the folder tree on disk.
3. Thumbnails, previews and converted videos are prepared in advance by the background processing (see below); the album page simply reads the album's `album.json` file from the cache.

### Viewer
`script/viewer.js` opens photos and videos full screen:
- previous and next with the arrows, the ← → keys or by swiping; Esc, the cross, a downward swipe or the phone's back button to close;
- native video playback with their preview image;
- an « Original » link to the full-quality file, and the date the picture was taken;
- the address keeps the open media (`#media=name`), so you can share a link to a photo.

### Automatic media processing
`bin/process-media.php` runs continuously in the Docker container and rescans the galleries every `SITEIMAGEPERSO_SCAN_INTERVAL` seconds (60 by default). For each file, it:
- recognises the **actual format** from the content, not the extension: JPEG, PNG, GIF, WebP, HEIC/HEIF (iPhone), AVIF, TIFF for photos; MP4, MOV, AVI, MKV, WebM, MTS, WMV, MPEG… for videos. Other files are ignored;
- creates a thumbnail and a preview, **fixing the orientation** from the EXIF data;
- for formats browsers cannot display (HEIC, TIFF…), also creates a full-size JPEG version;
- for videos, extracts a preview image, reads the duration and orientation, and **converts to MP4 (H.264/AAC) only if the original cannot be played** by browsers;
- reads the capture date from the metadata.

Everything goes into the cache (`cache/` by default), which mirrors the gallery tree. **Originals are never modified** and nothing is written into the album folders. A file that was already processed and has not changed is not processed again; a deleted file disappears from the cache on the next pass. The cache can be deleted safely: it rebuilds itself.

**Failed media**: a file the processing cannot prepare (damaged photo or video, format unknown to vips or ffmpeg) does not appear on the site. It is listed, with the failure date and the reason, in `journal/medias-en-erreur.txt` in the configuration folder (`SITEIMAGEPERSO_CONFIG`, mounted at `/config/journal` in the site container; another location can be set with `SITEIMAGEPERSO_ERROR_REPORT`). The list is rewritten on every pass: a file that is replaced, fixed or deleted drops out on its own, and the list says « Aucun média en erreur. » (no failed media) when everything is fine. A failed file is only retried if it changes. Example line:

```
2026-10-22 13:05 UTC | GalleryPhotos/2024/Noël/clip.mp4 | vidéo illisible par ffprobe : moov atom not found
```

Running it by hand (outside Docker): `php bin/process-media.php --once -v`. Required tools: `vipsthumbnail` with HEIF support (packages `vips-tools` and `vips-heif` on Alpine, `libvips-tools` and `libheif-plugin-libde265` on Debian/Ubuntu), `ffmpeg` and `ffprobe`.

### Old folders
The `Legend/`, `Small/` and `Middle/` folders of the old site are neither read nor deleted: the site ignores them. The site does not show captions.

### Preparing a new album
1. Create a folder in `GalleryPhotos/` or `GalleryCreative/` (sub-folders are allowed) and drop the photos and videos in as they are, without converting them.
2. The album appears on the site as processing goes, usually within a minute for photos. Videos that need converting take longer.

### Customisation
- `ImagesSite/MainPhoto.jpg` (folder to create, not in the repository) is shown at the top of the home page if it exists.
- `main.php` lists the news, to update by hand.
- Gallery names are set in `GetGalleryLabel()` (`lib/media.php`) or with `SITEIMAGEPERSO_GALLERY_LABELS="GalleryPhotos=Galerie des gosses;GalleryCreative=Galerie des glaces"`.
- `header.php` and `css/style.css` let you adapt the banner and the overall look (the colours are grouped at the top of `css/style.css`).

## Secured diagnostics
`InfoSite.php` is protected to avoid any accidental exposure in production. By default, the page returns a 404 error.

To use the diagnostics during an administrative intervention:

1. Set the environment variable `SITEIMAGEPERSO_ENABLE_ADMIN_DIAGNOSTICS` to `true`, `1`, `yes` or `on`.
2. Also set `SITEIMAGEPERSO_ADMIN_DIAGNOSTICS_USER` and `SITEIMAGEPERSO_ADMIN_DIAGNOSTICS_PASSWORD` to the Basic Auth credentials reserved for the administration team.
3. Open `InfoSite.php` after HTTP authentication.

Never deploy these environment variables in production: as long as they are absent or empty, the page stays unreachable and returns a 404 status. The technical information (PHP configuration, GD capabilities, hardware sheet loaded from `matos.html`) thus stays limited to secured administration tools.

## Technical dependencies
- PHP with the `fileinfo`, `gd`, `exif` and `mbstring` extensions.
- `vipsthumbnail` (libvips, with HEIF support), `ffmpeg` and `ffprobe` for media processing.
- A web server configured to point to this project (see the files in the `apache2/` folder for an example configuration).

## Deploying with Docker

A Docker environment is provided to simplify setting up the site, with two volume mounts to the host, one per gallery.

### Preparation
1. Copy `.env.example` to `.env`, then point `SITEIMAGEPERSO_GALLERY_PHOTOS` and `SITEIMAGEPERSO_GALLERY_CREATIVE` to your album folders (for example a locally mounted Samba share), and `SITEIMAGEPERSO_CACHE` to the cache folder.
2. Check that the target folders exist and that the Docker user can write to them. Otherwise, they are created when the container starts.
3. Set up the Google login (next section): without it, the site does not display.

### Google login
The site is only visible after logging in with a Google account whose address is on a list. [oauth2-proxy](https://oauth2-proxy.github.io/oauth2-proxy/), a second container placed in front of the site, takes care of this: the site container no longer publishes any port. It uses about 20 MB of memory.

**On the Google side** (once), in the [Google Cloud Console](https://console.cloud.google.com/):
1. Create a project (for example "Family gallery").
2. *APIs & Services > OAuth consent screen* (or *Google Auth Platform*): user type **External**, application name, contact address. The default scopes (`email`, `profile`, `openid`) are enough. Publish the app (*In production*): in *Testing* mode, only accounts declared as testers at Google can log in. These basic scopes do not normally require verification by Google.
3. *Credentials > Create credentials > OAuth client ID*: type **Web application**. In *Authorized redirect URIs*, add `https://<your-domain>/oauth2/callback` (and `http://localhost:8080/oauth2/callback` to test on your computer).
4. Copy the client ID and secret into `.env` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`).

**In `.env`**:
- `SITEIMAGEPERSO_PUBLIC_URL`: the site address, without a trailing `/` (`https://family.example.com`);
- `SITEIMAGEPERSO_COOKIE_SECRET`: 32 random characters, from `openssl rand -hex 16`;
- `SITEIMAGEPERSO_SESSION_DURATION`: time before having to log in again (`720h`, 30 days, by default).

**List of allowed addresses**: the `emails.txt` file in the site configuration folder (`SITEIMAGEPERSO_CONFIG`, `./config` by default, mounted read-only in both containers), one address per line followed by a comma and the first name shown on the site (`marie.dupont@gmail.com, Marie`); lines starting with `#` are ignored, and upper and lower case are equivalent. Example: `config.example/emails.txt`. A change takes effect immediately, without a restart: a removed address is refused on its next visit. A person who is logged in but not on the list gets a 403 error. The file must exist and be world-readable (`chmod 644`), because oauth2-proxy does not run as root; otherwise the login container does not start. **Mind the format**: oauth2-proxy reads this file as CSV and only keeps the address, but it requires the same number of commas on every line. A line without a first name, with an extra comma or with quotes makes the whole file unreadable: the login keeps the previous list (message `error reading authenticated-emails-file` in `docker compose logs`), or refuses everybody if it starts that way. Without a first name for an address, the site shows the part before `@`.

Visitors who are not logged in land on a welcome page with a « Se connecter avec Google » (sign in with Google) button; an address that is not on the list sees a "no access" page with a button to switch accounts. These pages live in `oauth2-proxy/` (`sign_in.html`, `error.html`, oauth2-proxy Go templates), mounted in the login container at `/templates`.

**Likes** (« j’aime »): the ♡ heart in the corner of each thumbnail, or in the viewer, highlights a photo or a video; a second click removes the like (`L` key in the viewer). The heart shows the number of likes, in pink when you are one of them. Once logged in, the home page starts with the **« Font l’unanimité »** (unanimous favourites) section: every media item with the highest number of likes (if it is 3, all those with 3, whoever gave them), then each person's **coups de cœur** (favourites), under the first name given in `emails.txt` (yours first), most liked first, with no limit. Each person's favourites are collapsed: clicking the title (which shows how many there are) shows or hides them. Likes are stored in `likes/likes.json` in the configuration folder (`SITEIMAGEPERSO_CONFIG`, mounted at `/config/likes` in the site container; another location can be set with `SITEIMAGEPERSO_LIKES_FILE`), per address and per media item (album path and file name): a renamed or moved item loses its likes. Without the Google login (site run outside Docker), the button does not appear.

**Photo rotation**: in the viewer, the ↺ and ↻ buttons rotate a photo a quarter turn to the left or to the right (`R` key, or `Shift` + `R` for left). The thumbnail, the preview and the full-size cached version are regenerated immediately, rotated; **the original is never modified** (the « Original » link shows it as is). Rotations are stored in `rotations/rotations.json` in the configuration folder (`SITEIMAGEPERSO_CONFIG`, mounted at `/config/rotations` in the site container; another location can be set with `SITEIMAGEPERSO_ROTATIONS_FILE`), outside the cache: a deleted cache is rebuilt with the rotated photos. As with likes, a renamed or moved photo loses its rotation. Videos and animated GIFs cannot be rotated. Without the Google login (site run outside Docker), the buttons do not appear.

Once logged in, the first name (the full address on hover) and a « Déconnexion » (log out) link appear in the top right. After logging out, Google offers to choose the account, which makes it possible to switch person on a shared computer.

**HTTPS**: Google only accepts an `http` callback address for `localhost`. On the server, a reverse proxy holds the certificate and forwards requests to the container:
- **Caddy**: see `caddy/Caddyfile.example` (one line `reverse_proxy 127.0.0.1:8080`). If Caddy itself runs in Docker, the simplest is to declare the gallery and its Google login directly in Caddy's docker-compose: see `caddy/compose-avec-caddy.example.yml` (settings in `environment:`, paths, Google credentials and site address in a single `.env` next to that docker-compose, based on `caddy/galerie.env.example`, list of addresses in a mounted file, reread without a restart) and `reverse_proxy galerie-auth:4180`;
- **Apache**: see `apache2/site-available/002-example.com-docker-le-ssl.conf` (modules `proxy`, `proxy_http` and `headers`).

**Search engines**: oauth2-proxy answers `/robots.txt` itself, without login, with `Disallow: /` (no page to crawl), and both reverse proxy examples add the `X-Robots-Tag: noindex, nofollow` header to every response, login page included. Check: `curl -sI https://<your-domain>/robots.txt` must show this header, and `curl -s https://<your-domain>/robots.txt` the content `Disallow: /`. The repository's `robots.txt` says the same, in case the site is published without the Google login.

The container port only listens on `127.0.0.1` (`SITEIMAGEPERSO_HTTP_BIND`): the login cannot be bypassed through port 8080 from outside.

**Testing on your computer**: `SITEIMAGEPERSO_PUBLIC_URL=http://localhost:8080` and `SITEIMAGEPERSO_COOKIE_SECURE=false` in `.env`, with the `localhost` callback address declared at Google.

### Build and start
1. Build the image and start the service:
   ```
   docker compose up --build -d
   ```
2. The site is reachable on the port set by `SITEIMAGEPERSO_HTTP_PORT` (8080 by default), on `127.0.0.1` only, or at the public address through the host's Apache. If the login page does not appear, `docker compose logs oauth2-proxy` shows the missing setting.

### Additional notes
- The `GalleryPhotos/` and `GalleryCreative/` folders in this repository are the default mount points. They stay empty thanks to their internal `.gitignore` files, to avoid adding media to the repository.
- Memory: at rest, the container uses about 50 MB. The peak comes from converting a video (about 300 MB in 1080p with `SITEIMAGEPERSO_FFMPEG_THREADS=2`, the default; 1 thread reduces memory further but makes the conversion longer). Apache is limited to `SITEIMAGEPERSO_MAX_WORKERS` processes (10 by default, instead of 150). Every open connection, including a video being played, uses one process: increase this value if several people watch videos at the same time.
- HTTPS: the container serves HTTP and does not redirect to HTTPS. Behind a reverse proxy that handles the certificate and forwards the `X-Forwarded-Proto` header, set `SITEIMAGEPERSO_FORCE_HTTPS=1` to redirect visitors who arrive over HTTP. Outside Docker, `.htaccess` always redirects to HTTPS.
- The container entry script prepares the cache folder and starts the media processing in the background (can be disabled with `SITEIMAGEPERSO_DISABLE_PROCESSOR=1`). Its log is visible with `docker compose logs -f`.

## Tests
`php tests/MediaProcessingTest.php` checks format detection, EXIF orientation, video conversion, the cache and album rendering (cases that need `vipsthumbnail` or `ffmpeg` are skipped if those tools are missing). In Docker, everything must pass, including the sample HEIC photo (`tests/samples/iphone.heic`): `docker compose build && docker compose run --rm -v ./tests:/var/www/html/tests:ro --entrypoint php siteimageperso tests/MediaProcessingTest.php` (the image does not contain `tests/`, hence the mount). This is the check to run after every change to the image (Alpine version, packages).

`php tests/LikesTest.php` checks likes: one click likes, a second removes, and the home page sections (unanimous favourites, each person's favourites).

`php tests/RotationsTest.php` checks photo rotation: quarter turns recorded, thumbnails regenerated rotated without touching the original, and rotation picked up again by the background processing after the cache is deleted (cases that need `vipsthumbnail` are skipped if it is missing).

`tests/VideoAccessTest.php` starts a built-in PHP server and checks that `video.php` responds correctly (statuses 200/403/404). Running `php tests/VideoAccessTest.php` makes sure video streaming stays secured.
