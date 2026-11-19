/*
 * Full-screen viewer for an album's photos and videos, and the album
 * menu on phones. The media come from the #album-media JSON block
 * written by RenderAlbum() (functions.php).
 *
 * Navigation: arrow keys and Esc on the keyboard, swiping on mobile,
 * the phone's back button to close.
 *
 * Like button (if the JSON block has data-likes): one click likes the
 * media item, a second click removes the like. The L key does the same.
 *
 * Rotation buttons (if the JSON block has data-rotate): rotate the
 * photo a quarter turn, left or right. The server regenerates its
 * thumbnail and preview; the original is not modified. R key: right,
 * Shift + R: left.
 */
(function () {
        'use strict';

        function onReady(callback) {
                if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', callback);
                } else {
                        callback();
                }
        }

        function setupNavToggle() {
                var toggle = document.querySelector('.nav-toggle');
                var nav = document.getElementById('site-nav');

                if (!toggle || !nav) {
                        return;
                }

                toggle.addEventListener('click', function () {
                        var isOpen = nav.classList.toggle('is-open');
                        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });
        }

        function formatDate(value) {
                if (!value) {
                        return '';
                }

                var date = new Date(value);

                if (isNaN(date.getTime())) {
                        return '';
                }

                return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
        }

        function createElement(tag, className, attributes) {
                var element = document.createElement(tag);

                if (className) {
                        element.className = className;
                }

                for (var name in attributes || {}) {
                        element.setAttribute(name, attributes[name]);
                }

                return element;
        }

        function Viewer(items, likesUrl, rotateUrl) {
                this.items = items;
                this.likesUrl = likesUrl;
                this.rotateUrl = rotateUrl;
                this.likeBusy = {};
                this.rotateBusy = {};
                this.index = -1;
                this.build();
        }

        Viewer.prototype.build = function () {
                var self = this;

                this.root = createElement('div', 'viewer', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Visionneuse' });
                this.root.hidden = true;

                var bar = createElement('div', 'viewer__bar');
                this.counter = createElement('span', 'viewer__counter');
                this.originalLink = createElement('a', 'viewer__link', { target: '_blank', rel: 'noopener' });
                this.originalLink.textContent = 'Original';
                var closeButton = createElement('button', 'viewer__button', { type: 'button', 'aria-label': 'Fermer' });
                closeButton.textContent = '✕';
                bar.appendChild(this.counter);

                if (this.likesUrl) {
                        this.likeButton = createElement('button', 'viewer__button viewer__like', { type: 'button', 'aria-pressed': 'false' });
                        this.likeButton.addEventListener('click', function () { self.toggleLike(); });
                        bar.appendChild(this.likeButton);
                }

                if (this.rotateUrl) {
                        this.rotateLeftButton = createElement('button', 'viewer__button viewer__rotate', { type: 'button', 'aria-label': 'Tourner à gauche', title: 'Tourner à gauche (Maj + R)' });
                        this.rotateLeftButton.textContent = '↺';
                        this.rotateLeftButton.addEventListener('click', function () { self.rotate(-90); });
                        this.rotateRightButton = createElement('button', 'viewer__button viewer__rotate', { type: 'button', 'aria-label': 'Tourner à droite', title: 'Tourner à droite (R)' });
                        this.rotateRightButton.textContent = '↻';
                        this.rotateRightButton.addEventListener('click', function () { self.rotate(90); });
                        bar.appendChild(this.rotateLeftButton);
                        bar.appendChild(this.rotateRightButton);
                }

                bar.appendChild(this.originalLink);
                bar.appendChild(closeButton);

                this.stage = createElement('div', 'viewer__stage');
                var previousButton = createElement('button', 'viewer__button viewer__nav viewer__nav--previous', { type: 'button', 'aria-label': 'Précédent' });
                previousButton.textContent = '‹';
                var nextButton = createElement('button', 'viewer__button viewer__nav viewer__nav--next', { type: 'button', 'aria-label': 'Suivant' });
                nextButton.textContent = '›';
                this.stage.appendChild(previousButton);
                this.stage.appendChild(nextButton);

                this.caption = createElement('p', 'viewer__caption');

                this.root.appendChild(bar);
                this.root.appendChild(this.stage);
                this.root.appendChild(this.caption);
                document.body.appendChild(this.root);

                closeButton.addEventListener('click', function () { self.close(); });
                previousButton.addEventListener('click', function () { self.step(-1); });
                nextButton.addEventListener('click', function () { self.step(1); });

                // A click on the background (outside the media) closes the viewer
                this.stage.addEventListener('click', function (event) {
                        if (event.target === self.stage) {
                                self.close();
                        }
                });

                document.addEventListener('keydown', function (event) {
                        if (self.root.hidden) {
                                return;
                        }

                        if (event.key === 'ArrowLeft') {
                                self.step(-1);
                        } else if (event.key === 'ArrowRight') {
                                self.step(1);
                        } else if (event.key === 'Escape') {
                                self.close();
                        } else if ((event.key === 'l' || event.key === 'L') && self.likeButton) {
                                self.toggleLike();
                        } else if ((event.key === 'r' || event.key === 'R') && self.rotateUrl && !event.ctrlKey && !event.metaKey) {
                                self.rotate(event.shiftKey ? -90 : 90);
                        }
                });

                this.setupSwipe();

                window.addEventListener('popstate', function () {
                        if (!self.root.hidden && !self.readHash()) {
                                self.hide();
                        } else {
                                self.openFromHash();
                        }
                });
        };

        Viewer.prototype.setupSwipe = function () {
                var self = this;
                var startX = 0;
                var startY = 0;
                var tracking = false;

                this.stage.addEventListener('touchstart', function (event) {
                        if (event.touches.length !== 1) {
                                tracking = false;
                                return;
                        }

                        tracking = true;
                        startX = event.touches[0].clientX;
                        startY = event.touches[0].clientY;
                }, { passive: true });

                this.stage.addEventListener('touchend', function (event) {
                        if (!tracking || event.changedTouches.length !== 1) {
                                return;
                        }

                        tracking = false;
                        var deltaX = event.changedTouches[0].clientX - startX;
                        var deltaY = event.changedTouches[0].clientY - startY;

                        if (Math.abs(deltaX) > 50 && Math.abs(deltaX) > Math.abs(deltaY) * 1.5) {
                                self.step(deltaX < 0 ? 1 : -1);
                        } else if (deltaY > 100 && Math.abs(deltaY) > Math.abs(deltaX) * 1.5) {
                                self.close();
                        }
                }, { passive: true });
        };

        // The address keeps the open media (#media=name): shareable link, back button
        Viewer.prototype.readHash = function () {
                var match = window.location.hash.match(/^#media=(.+)$/);

                return match ? decodeURIComponent(match[1]) : null;
        };

        Viewer.prototype.openFromHash = function () {
                var name = this.readHash();

                if (name === null) {
                        return;
                }

                for (var index = 0; index < this.items.length; index++) {
                        if ((this.items[index].ref || this.items[index].name) === name) {
                                this.show(index);
                                return;
                        }
                }
        };

        Viewer.prototype.open = function (index) {
                // On the home page, media come from several albums: ref is their full key
                var hash = '#media=' + encodeURIComponent(this.items[index].ref || this.items[index].name);

                if (this.root.hidden) {
                        history.pushState({ viewer: true }, '', hash);
                } else {
                        history.replaceState({ viewer: true }, '', hash);
                }

                this.show(index);
        };

        Viewer.prototype.show = function (index) {
                var item = this.items[index];
                var element;

                this.index = index;
                this.stopVideo();

                if (item.kind === 'video') {
                        element = createElement('video', '', { controls: '', playsinline: '', preload: 'metadata' });
                        element.poster = item.poster || '';
                        element.src = item.src;
                } else {
                        element = createElement('img', '', { alt: item.name });
                        element.src = item.src;
                }

                if (this.current) {
                        this.stage.removeChild(this.current);
                }

                this.current = element;
                this.stage.insertBefore(element, this.stage.firstChild);

                this.counter.textContent = (index + 1) + ' / ' + this.items.length;
                this.originalLink.href = item.original;
                this.caption.textContent = '';

                var date = formatDate(item.taken);

                if (item.albumUrl) {
                        var albumLink = createElement('a', 'viewer__album', { href: item.albumUrl });
                        albumLink.textContent = item.album;
                        this.caption.appendChild(albumLink);
                }

                if (date) {
                        var small = document.createElement('small');
                        small.textContent = date;
                        this.caption.appendChild(small);
                }

                this.renderLike();
                this.renderRotate();

                if (this.root.hidden) {
                        this.root.hidden = false;
                        document.body.classList.add('viewer-open');
                }

                this.preload(index + 1);
                this.preload(index - 1);
        };

        Viewer.prototype.renderLike = function () {
                // Viewer closed: click on a thumbnail's heart
                if (!this.likeButton || this.index < 0) {
                        return;
                }

                var item = this.items[this.index];

                this.likeButton.textContent = (item.liked ? '♥' : '♡') + (item.likes > 0 ? ' ' + item.likes : '');
                this.likeButton.setAttribute('aria-pressed', item.liked ? 'true' : 'false');
                this.likeButton.setAttribute('aria-label', item.liked ? 'Je n\'aime plus' : 'J\'aime');
                this.likeButton.classList.toggle('is-liked', item.liked);
        };

        // Same media item, same thumbnails: it can appear in several home page sections
        Viewer.prototype.updateItems = function (key, liked, likes) {
                for (var index = 0; index < this.items.length; index++) {
                        if (this.items[index].key === key) {
                                this.items[index].liked = liked;
                                this.items[index].likes = likes;
                        }
                }

                var badges = document.querySelectorAll('.media-thumb__likes[data-media]');

                for (var badgeIndex = 0; badgeIndex < badges.length; badgeIndex++) {
                        var badge = badges[badgeIndex];

                        if (badge.getAttribute('data-media') !== key) {
                                continue;
                        }

                        badge.classList.toggle('is-liked', liked);
                        badge.setAttribute('aria-pressed', liked ? 'true' : 'false');
                        badge.querySelector('.media-thumb__likes-heart').textContent = liked ? '♥' : '♡';
                        badge.querySelector('.media-thumb__likes-count').textContent = likes > 0 ? likes : '';
                }

                this.renderLike();
        };

        Viewer.prototype.toggleLike = function () {
                this.toggleItemLike(this.items[this.index]);
        };

        // From the viewer or a thumbnail's heart
        Viewer.prototype.toggleItemLike = function (item) {
                var self = this;

                if (!item || this.likeBusy[item.key]) {
                        return;
                }

                // Shown immediately, corrected by the server response
                var wasLiked = item.liked;
                var oldLikes = item.likes;
                this.likeBusy[item.key] = true;
                this.updateItems(item.key, !wasLiked, Math.max(0, oldLikes + (wasLiked ? -1 : 1)));

                var body = new URLSearchParams();
                body.append('media', item.key);

                fetch(this.likesUrl, {
                        method: 'POST',
                        headers: { 'X-Galerie-Like': '1' },
                        body: body,
                        credentials: 'same-origin'
                }).then(function (response) {
                        if (!response.ok) {
                                throw new Error('Response ' + response.status);
                        }

                        return response.json();
                }).then(function (result) {
                        self.updateItems(item.key, result.liked, result.count);
                }).catch(function (error) {
                        console.error('Like not saved.', error);
                        self.updateItems(item.key, wasLiked, oldLikes);
                }).then(function () {
                        self.likeBusy[item.key] = false;
                });
        };

        Viewer.prototype.renderRotate = function () {
                if (!this.rotateUrl || this.index < 0) {
                        return;
                }

                var item = this.items[this.index];
                var busy = !!this.rotateBusy[item.key];

                this.rotateLeftButton.hidden = !item.rotatable;
                this.rotateRightButton.hidden = !item.rotatable;
                this.rotateLeftButton.disabled = busy;
                this.rotateRightButton.disabled = busy;
                this.stage.classList.toggle('is-busy', busy);
        };

        // The server regenerates the thumbnail and preview (a second or two), then we reload them
        Viewer.prototype.rotate = function (degrees) {
                var self = this;
                var item = this.items[this.index];

                if (!item || !item.rotatable || this.rotateBusy[item.key]) {
                        return;
                }

                var key = item.key;
                this.rotateBusy[key] = true;
                this.renderRotate();

                var body = new URLSearchParams();
                body.append('media', key);
                body.append('degrees', String(degrees));

                fetch(this.rotateUrl, {
                        method: 'POST',
                        headers: { 'X-Galerie-Rotate': '1' },
                        body: body,
                        credentials: 'same-origin'
                }).then(function (response) {
                        return response.json().catch(function () {
                                return {};
                        }).then(function (result) {
                                if (!response.ok) {
                                        throw new Error(result.error || ('Response ' + response.status));
                                }

                                return result;
                        });
                }).then(function (result) {
                        self.rotateBusy[key] = false;
                        self.updateRotation(key, result);
                }).catch(function (error) {
                        console.error('Rotation not saved.', error);
                        self.rotateBusy[key] = false;
                        self.renderRotate();
                        self.showError('Rotation impossible. ' + error.message);
                });
        };

        // Error message under the photo, cleared on the next media item
        Viewer.prototype.showError = function (message) {
                var error = createElement('span', 'viewer__error', { role: 'alert' });
                error.textContent = message;
                this.caption.insertBefore(error, this.caption.firstChild);
        };

        // Same media item, same thumbnails: it can appear in several home page sections
        Viewer.prototype.updateRotation = function (key, result) {
                for (var index = 0; index < this.items.length; index++) {
                        var item = this.items[index];

                        if (item.key === key) {
                                item.src = result.item.src;
                                item.original = result.item.original;
                                item.width = result.item.width;
                                item.height = result.item.height;
                        }
                }

                var thumbs = document.querySelectorAll('.media-thumb[data-media]');

                for (var thumbIndex = 0; thumbIndex < thumbs.length; thumbIndex++) {
                        var image = thumbs[thumbIndex].querySelector('img');

                        if (thumbs[thumbIndex].getAttribute('data-media') !== key || !image) {
                                continue;
                        }

                        image.src = result.thumb;
                        image.width = result.thumb_width;
                        image.height = result.thumb_height;
                }

                // Still on this photo: show it rotated
                if (!this.root.hidden && this.items[this.index].key === key) {
                        this.show(this.index);
                } else {
                        this.renderRotate();
                }
        };

        Viewer.prototype.preload = function (index) {
                var item = this.items[(index + this.items.length) % this.items.length];

                if (item && item.kind === 'image') {
                        new Image().src = item.src;
                }
        };

        Viewer.prototype.step = function (direction) {
                if (this.items.length < 2) {
                        return;
                }

                this.open((this.index + direction + this.items.length) % this.items.length);
        };

        Viewer.prototype.stopVideo = function () {
                if (this.current && this.current.tagName === 'VIDEO') {
                        this.current.pause();
                        this.current.removeAttribute('src');
                        this.current.load();
                }
        };

        Viewer.prototype.close = function () {
                if (this.readHash() !== null && history.state && history.state.viewer) {
                        history.back();
                } else {
                        history.replaceState(null, '', window.location.pathname + window.location.search);
                        this.hide();
                }
        };

        Viewer.prototype.hide = function () {
                this.stopVideo();

                if (this.current) {
                        this.stage.removeChild(this.current);
                        this.current = null;
                }

                this.root.hidden = true;
                document.body.classList.remove('viewer-open');

                var thumb = document.querySelector('.media-thumb[data-index="' + this.index + '"]');

                if (thumb) {
                        thumb.focus({ preventScroll: true });
                }
        };

        onReady(function () {
                setupNavToggle();

                var data = document.getElementById('album-media');

                if (!data) {
                        return;
                }

                var items;

                try {
                        items = JSON.parse(data.textContent || '[]');
                } catch (error) {
                        console.error('Unreadable media list.', error);
                        return;
                }

                if (!items.length) {
                        return;
                }

                var viewer = new Viewer(items, data.getAttribute('data-likes'), data.getAttribute('data-rotate'));
                var thumbs = document.querySelectorAll('.media-thumb');

                for (var index = 0; index < thumbs.length; index++) {
                        thumbs[index].addEventListener('click', function () {
                                viewer.open(parseInt(this.getAttribute('data-index'), 10));
                        });
                }

                var likeButtons = document.querySelectorAll('.media-thumb__likes--button');

                for (var buttonIndex = 0; buttonIndex < likeButtons.length; buttonIndex++) {
                        likeButtons[buttonIndex].addEventListener('click', function () {
                                var key = this.getAttribute('data-media');

                                for (var itemIndex = 0; itemIndex < items.length; itemIndex++) {
                                        if (items[itemIndex].key === key) {
                                                viewer.toggleItemLike(items[itemIndex]);
                                                return;
                                        }
                                }
                        });
                }

                viewer.openFromHash();
        });
})();
