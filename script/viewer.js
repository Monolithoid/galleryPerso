/*
 * Visionneuse plein écran des photos et vidéos d'un album, et menu des
 * albums sur téléphone. Les médias viennent du bloc JSON #album-media
 * écrit par RenderAlbum() (functions.php).
 *
 * Navigation : flèches et Échap au clavier, glisser du doigt sur mobile,
 * bouton retour du téléphone pour fermer.
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

        function Viewer(items) {
                this.items = items;
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

                // Un clic sur le fond (hors du média) ferme la visionneuse
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

        // L'adresse garde le média ouvert (#media=nom) : lien partageable, bouton retour
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
                        if (this.items[index].name === name) {
                                this.show(index);
                                return;
                        }
                }
        };

        Viewer.prototype.open = function (index) {
                var hash = '#media=' + encodeURIComponent(this.items[index].name);

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
                        element = createElement('img', '', { alt: item.caption || item.name });
                        element.src = item.src;
                }

                if (this.current) {
                        this.stage.removeChild(this.current);
                }

                this.current = element;
                this.stage.insertBefore(element, this.stage.firstChild);

                this.counter.textContent = (index + 1) + ' / ' + this.items.length;
                this.originalLink.href = item.original;
                this.caption.textContent = item.caption || '';

                var date = formatDate(item.taken);

                if (date) {
                        var small = document.createElement('small');
                        small.textContent = date;
                        this.caption.appendChild(small);
                }

                if (this.root.hidden) {
                        this.root.hidden = false;
                        document.body.classList.add('viewer-open');
                }

                this.preload(index + 1);
                this.preload(index - 1);
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
                        console.error('Liste des médias illisible.', error);
                        return;
                }

                if (!items.length) {
                        return;
                }

                var viewer = new Viewer(items);
                var thumbs = document.querySelectorAll('.media-thumb');

                for (var index = 0; index < thumbs.length; index++) {
                        thumbs[index].addEventListener('click', function () {
                                viewer.open(parseInt(this.getAttribute('data-index'), 10));
                        });
                }

                viewer.openFromHash();
        });
})();
