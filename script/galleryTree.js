(function () {
        'use strict';

        function onReady(callback) {
                if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', callback);
                } else {
                        callback();
                }
        }

        function trimString(value) {
                if (typeof value !== 'string') {
                        return '';
                }

                if (typeof value.trim === 'function') {
                        return value.trim();
                }

                return value.replace(/^\s+|\s+$/g, '');
        }

        function isArray(value) {
                if (typeof Array.isArray === 'function') {
                        return Array.isArray(value);
                }

                return Object.prototype.toString.call(value) === '[object Array]';
        }

        onReady(function initializeGalleryTree() {
                var dataElement = document.getElementById('gallery-tree-data');
                var container = document.getElementById('gallery-tree');

                if (!dataElement || !container) {
                        return;
                }

                var jsonText = dataElement.textContent;

                if (typeof jsonText !== 'string' || jsonText === '') {
                        jsonText = dataElement.innerText || '';
                }

                jsonText = trimString(jsonText);

                var treeData;

                try {
                        treeData = JSON.parse(jsonText || '[]');
                } catch (error) {
                        console.error('Impossible de parser la structure du diaporama.', error);
                        treeData = [];
                }

                if (dataElement.parentNode) {
                        dataElement.parentNode.removeChild(dataElement);
                }

                if (!isArray(treeData) || treeData.length === 0) {
                        container.textContent = 'Aucun diaporama disponible.';
                        return;
                }

                var activePath = container.getAttribute('data-active-path') || '';

                buildTree(container, treeData, activePath);
        });

        function buildTree(container, treeData, activePath) {
                container.innerHTML = '';

                var result = createList(treeData, activePath, container);

                if (result.element) {
                        container.appendChild(result.element);
                }
        }

        function createList(nodes, activePath, container) {
                var listElement = document.createElement('ul');
                listElement.className = 'gallery-tree__list';

                var hasActive = false;

                for (var index = 0; index < nodes.length; index++) {
                        var node = nodes[index];
                        var itemResult = createItem(node, activePath, container);

                        if (itemResult.hasActive) {
                                hasActive = true;
                        }

                        listElement.appendChild(itemResult.element);
                }

                return {
                        element: listElement,
                        hasActive: hasActive
                };
        }

        function createItem(node, activePath, container) {
                var itemElement = document.createElement('li');
                itemElement.className = 'gallery-tree__item';
                itemElement.setAttribute('data-path', node.path || '');

                var rowElement = document.createElement('div');
                rowElement.className = 'gallery-tree__row';
                itemElement.appendChild(rowElement);

                var hasChildren = isArray(node.children) && node.children.length > 0;
                var toggleElement;

                if (hasChildren) {
                        toggleElement = document.createElement('button');
                        toggleElement.type = 'button';
                        toggleElement.className = 'gallery-tree__toggle';
                        toggleElement.setAttribute('aria-label', 'Plier ou déplier ' + (node.label || ''));
                        rowElement.appendChild(toggleElement);
                } else {
                        toggleElement = document.createElement('span');
                        toggleElement.className = 'gallery-tree__toggle gallery-tree__toggle--empty';
                        toggleElement.setAttribute('aria-hidden', 'true');
                        rowElement.appendChild(toggleElement);
                }

                var labelButton = document.createElement('button');
                labelButton.type = 'button';
                labelButton.className = 'gallery-tree__label';
                labelButton.textContent = node.label || '';
                rowElement.appendChild(labelButton);

                var childResult = null;

                if (hasChildren) {
                        childResult = createList(node.children, activePath, container);
                        itemElement.appendChild(childResult.element);
                }

                var isActive = (node.path === activePath);
                var hasActiveDescendant = childResult !== null && childResult.hasActive;

                if (isActive) {
                        itemElement.classList.add('is-active');
                }

                if (hasChildren) {
                        itemElement.__toggleButton = toggleElement;

                        if (isActive || hasActiveDescendant) {
                                itemElement.classList.add('is-expanded');
                        } else {
                                itemElement.classList.add('is-collapsed');
                        }

                        updateToggleState(itemElement, toggleElement);

                        toggleElement.addEventListener('click', function (event) {
                                event.stopPropagation();
                                toggleItem(itemElement, toggleElement);
                        });

                        rowElement.addEventListener('click', function (event) {
                                if (event.target === labelButton) {
                                        return;
                                }

                                toggleItem(itemElement, toggleElement);
                        });
                }

                labelButton.addEventListener('click', function (event) {
                        event.stopPropagation();
                        setActiveItem(container, itemElement, node.path || '');
                        submitDiaporama(node.path || '');
                });

                return {
                        element: itemElement,
                        hasActive: isActive || hasActiveDescendant
                };
        }

        function toggleItem(itemElement, toggleElement) {
                if (itemElement.classList.contains('is-collapsed')) {
                        itemElement.classList.remove('is-collapsed');
                        itemElement.classList.add('is-expanded');
                } else {
                        itemElement.classList.remove('is-expanded');
                        itemElement.classList.add('is-collapsed');
                }

                updateToggleState(itemElement, toggleElement);
        }

        function updateToggleState(itemElement, toggleElement) {
                if (!toggleElement || toggleElement.tagName !== 'BUTTON') {
                        return;
                }

                if (itemElement.classList.contains('is-collapsed')) {
                        toggleElement.textContent = '\u25B6';
                        toggleElement.setAttribute('aria-expanded', 'false');
                } else {
                        toggleElement.textContent = '\u25BC';
                        toggleElement.setAttribute('aria-expanded', 'true');
                }
        }

        function setActiveItem(container, itemElement, path) {
                var activeItems = container.querySelectorAll('.gallery-tree__item.is-active');

                for (var index = 0; index < activeItems.length; index++) {
                        activeItems[index].classList.remove('is-active');
                }

                itemElement.classList.add('is-active');

                expandAncestors(itemElement);

                container.setAttribute('data-active-path', path);
        }

        function expandAncestors(itemElement) {
                var current = itemElement.parentElement;

                while (current && current.classList) {
                        if (current.classList.contains('gallery-tree__item')) {
                                if (current.classList.contains('is-collapsed')) {
                                        current.classList.remove('is-collapsed');
                                        current.classList.add('is-expanded');
                                        updateToggleState(current, current.__toggleButton);
                                }
                        }

                        current = current.parentElement;
                }
        }

        function submitDiaporama(path) {
                var form = document.forms['form_Gallery'];

                if (!form) {
                        return;
                }

                var field = form.elements['Diaporama'];

                if (!field) {
                        return;
                }

                field.value = path;
                form.submit();
        }
})();
