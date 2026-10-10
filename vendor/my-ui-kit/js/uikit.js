/* UIKit: small framework-free component factory API. */
(function (global) {
  'use strict';
  const defaults = { primary: 'primary', secondary: 'secondary', outline: 'outline', danger: 'danger' };
  const text = (value) => value == null ? '' : String(value);
  const englishDefaults = {
    'table.pageSize': 'Show',
    'table.previousPage': 'Previous page',
    'table.page': 'Page {page}',
    'table.nextPage': 'Next page',
    'table.summary': 'Showing {first}–{last} of {total} records',
    'table.searchPlaceholder': 'Search table...',
    'table.searchLabel': 'Search table',
    'table.filterBy': 'Filter by {label}',
    'table.filterActive': ' (filtered)',
    'table.filterDialog': 'Filter {label}',
    'table.emptyValue': '(Empty)',
    'table.clear': 'Clear',
    'table.apply': 'Apply',
    'table.sortBy': 'Sort by {label}',
    'table.sortAscending': 'ascending',
    'table.sortDescending': 'descending',
    'table.showingRows': 'Showing {first}–{last} of {total} rows',
    'table.noData': 'No data found',
    'button.cancel': 'Cancel',
    'button.confirm': 'Confirm',
    'button.close': 'Close'
  };
  function translate(key, values = {}) {
    const message = global.UI18n
      ? global.UI18n.t(key, values)
      : englishDefaults[key] || key;
    return message.replace(/\{(\w+)\}/g, (match, name) => values[name] ?? match);
  }
  function element(tag, className, content) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (content != null) node.textContent = text(content);
    return node;
  }
  function createButton(options = {}) {
    const button = element('button', 'ukit-button');
    button.type = options.type || 'button';
    button.classList.add('ukit-button--' + (defaults[options.variant] || 'primary'));
    if (options.size === 'sm' || options.size === 'lg') button.classList.add('ukit-button--' + options.size);
    button.textContent = text(options.text ?? options.label ?? 'Button');
    if (options.disabled) button.disabled = true;
    if (options.title) button.title = text(options.title);
    if (options.ariaLabel) button.setAttribute('aria-label', text(options.ariaLabel));
    if (typeof options.onClick === 'function') button.addEventListener('click', options.onClick);
    return button;
  }
  function createBadge(options = {}) {
    const allowed = ['success', 'warning', 'danger', 'info'];
    const badge = element('span', 'ukit-badge');
    if (allowed.includes(options.variant)) badge.classList.add('ukit-badge--' + options.variant);
    badge.textContent = text(options.text ?? options.label ?? 'Status');
    if (options.title) badge.title = text(options.title);
    return badge;
  }
  function createCard(options = {}) {
    const card = element('section', 'ukit-card');
    if (options.title != null) card.append(element('header', 'ukit-card__header', options.title));
    const body = element('div', 'ukit-card__body');
    if (options.content instanceof Node) body.append(options.content);
    else if (options.content != null) body.textContent = text(options.content);
    else if (options.body instanceof Node) body.append(options.body);
    else if (options.body != null) body.textContent = text(options.body);
    card.append(body);
    if (options.footer instanceof Node) { const footer = element('footer', 'ukit-card__footer'); footer.append(options.footer); card.append(footer); }
    return card;
  }
  function createModal(options = {}) {
    const backdrop = element('div', 'ukit-modal');
    backdrop.hidden = true;
    backdrop.setAttribute('role', 'presentation');
    const dialog = element('section', 'ukit-modal__dialog');
    dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true');
    const titleId = 'ukit-modal-title-' + Math.random().toString(36).slice(2, 9);
    dialog.setAttribute('aria-labelledby', titleId);
    const header = element('header', 'ukit-modal__header');
    const title = element('h2', 'ukit-modal__title', options.title || translate('button.confirm')); title.id = titleId;
    const close = element('button', 'ukit-modal__close', '×'); close.type = 'button'; close.setAttribute('aria-label', translate('button.close'));
    header.append(title, close);
    const body = element('div', 'ukit-modal__body');
    if (options.content instanceof Node) body.append(options.content); else body.textContent = text(options.message ?? options.content ?? '');
    const footer = element('footer', 'ukit-modal__footer');
    const cancel = createButton({ text: options.cancelText || translate('button.cancel'), variant: 'outline', onClick: closeModal });
    const confirm = createButton({ text: options.confirmText || translate('button.confirm'), variant: options.confirmVariant || 'primary', onClick: () => { if (typeof options.onConfirm === 'function') options.onConfirm(); if (options.closeOnConfirm !== false) closeModal(); } });
    footer.append(cancel, confirm); dialog.append(header, body, footer); backdrop.append(dialog);
    function openModal() { backdrop.hidden = false; close.focus(); document.addEventListener('keydown', onKeydown); }
    function closeModal() { backdrop.hidden = true; document.removeEventListener('keydown', onKeydown); if (options.returnFocus && typeof options.returnFocus.focus === 'function') options.returnFocus.focus(); }
    function onKeydown(event) { if (event.key === 'Escape') closeModal(); }
    close.addEventListener('click', closeModal);
    backdrop.addEventListener('click', event => { if (event.target === backdrop && options.closeOnBackdrop !== false) closeModal(); });
    const onLanguageChange = () => {
      if (options.titleKey) title.textContent = translate(options.titleKey);
      if (options.cancelTextKey) cancel.textContent = translate(options.cancelTextKey);
      if (options.confirmTextKey) confirm.textContent = translate(options.confirmTextKey);
      close.setAttribute('aria-label', translate('button.close'));
    };
    global.document.addEventListener('ui-language-change', onLanguageChange);
    return {
      element: backdrop,
      open: openModal,
      close: closeModal,
      destroy() {
        closeModal();
        global.document.removeEventListener('ui-language-change', onLanguageChange);
        backdrop.remove();
      }
    };
  }
  function createTable(options = {}) {
    const wrapper = element('div', 'ukit-table-wrap');
    const columns = Array.isArray(options.columns) ? options.columns : [];
    let rows = Array.isArray(options.data) ? options.data.slice() : (Array.isArray(options.rows) ? options.rows.slice() : []);
    let emptyText = options.emptyText || translate('table.noData');
    const tableTranslate = (key, values = {}) => typeof options.translate === 'function'
      ? options.translate(key, values)
      : translate(key, values);
    let sortKey = '', sortDirection = 1, query = '', currentPage = 1, openFilter = null;
    const activeFilters = new Map();
    const columnLabel = column => text(global.UI18n && column.labelKey
      ? tableTranslate(column.labelKey)
      : column.label || column.key);
    const pageSizes = [50, 100, 200];
    let pageSize = pageSizes.includes(options.pageSize) ? options.pageSize : 50;
    const toolbar = element('div', 'ukit-table-toolbar');
    const search = element('input', 'ukit-table-search');
    search.type = 'search';
    search.placeholder = options.searchPlaceholder || tableTranslate('table.searchPlaceholder');
    search.setAttribute('aria-label', options.searchLabel || tableTranslate('table.searchLabel'));
    toolbar.append(search); if (options.search !== false) wrapper.append(toolbar);
    const pageSizeControl = element('div', 'ukit-table-page-size');
    const pageSizeLabel = element('label', '', tableTranslate('table.pageSize'));
    const pageSizeSelect = document.createElement('select');
    pageSizeSelect.setAttribute('aria-label', tableTranslate('table.pageSize'));
    pageSizes.forEach(size => {
      const option = document.createElement('option');
      option.value = String(size);
      option.textContent = String(size);
      pageSizeSelect.append(option);
    });
    pageSizeSelect.value = String(pageSize);
    pageSizeLabel.append(pageSizeSelect);
    const paginationPages = element('div', 'ukit-table-pagination-pages');
    pageSizeControl.append(pageSizeLabel, paginationPages);
    wrapper.append(pageSizeControl);
    const table = element('table', 'ukit-table');
    const thead = document.createElement('thead'), headRow = document.createElement('tr');
    const filterButtons = new Map();
    function updateFilterButton(column) {
      const button = filterButtons.get(column.key);
      const isActive = activeFilters.has(column.key);
      button.dataset.active = String(isActive);
      button.setAttribute('aria-label', `${tableTranslate('table.filterBy', {label: columnLabel(column)})}${isActive ? tableTranslate('table.filterActive') : ''}`);
    }
    function closeFilter(restoreFocus = false) {
      if (!openFilter) return;
      const { popover, button, onOutsideClick, onKeydown, onScroll } = openFilter;
      popover.remove();
      button.setAttribute('aria-expanded', 'false');
      document.removeEventListener('pointerdown', onOutsideClick);
      document.removeEventListener('keydown', onKeydown);
      document.removeEventListener('scroll', onScroll, true);
      openFilter = null;
      if (restoreFocus) button.focus();
    }
    columns.forEach(column => {
      const th = document.createElement('th');
      const controls = element('div', 'ukit-table-header-controls');
      if (column.sortable) {
        const label = columnLabel(column);
        const sortButton = element('button', 'ukit-table-sort-button', label);
        const indicator = element('span', 'ukit-table-sort-indicator');
        indicator.setAttribute('aria-hidden', 'true');
        indicator.append(element('span', '', '▲'), element('span', '', '▼'));
        sortButton.type = 'button';
        sortButton.setAttribute('aria-label', tableTranslate('table.sortBy', {label}));
        sortButton.append(indicator);
        sortButton.addEventListener('click', () => {
          sortDirection = sortKey === column.key ? -sortDirection : 1;
          sortKey = column.key;
          currentPage = 1;
          columns.forEach((sortedColumn, index) => {
            if (!sortedColumn.sortable) return;
            const sortedHeader = headRow.children[index];
            const isActive = sortedColumn.key === sortKey;
            sortedHeader.setAttribute('aria-sort', isActive
              ? (sortDirection === 1 ? 'ascending' : 'descending')
              : 'none');
            const sortedButton = sortedHeader.querySelector('button');
            const sortedIndicator = sortedButton.querySelector('.ukit-table-sort-indicator');
            sortedIndicator.dataset.direction = isActive
              ? (sortDirection === 1 ? 'ascending' : 'descending')
              : '';
            sortedButton.setAttribute('aria-label', isActive
              ? `${tableTranslate('table.sortBy', {label: columnLabel(sortedColumn)})} ${tableTranslate(sortDirection === 1 ? 'table.sortDescending' : 'table.sortAscending')}`
              : tableTranslate('table.sortBy', {label: columnLabel(sortedColumn)}));
          });
          render();
        });
        th.setAttribute('aria-sort', 'none');
        controls.append(sortButton);
      }
      else if (!column.filterable) th.textContent = columnLabel(column);
      else controls.append(element('span', 'ukit-table-header-label', columnLabel(column)));
      if (column.filterable) {
        const label = columnLabel(column);
        const filterButton = element('button', 'ukit-table-filter-button');
        filterButton.type = 'button';
        filterButton.setAttribute('aria-label', tableTranslate('table.filterBy', {label}));
        filterButton.setAttribute('aria-expanded', 'false');
        filterButton.setAttribute('aria-haspopup', 'dialog');
        filterButtons.set(column.key, filterButton);
        const filterIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        filterIcon.setAttribute('viewBox', '0 0 24 24');
        filterIcon.setAttribute('aria-hidden', 'true');
        filterIcon.classList.add('ukit-table-filter-icon');
        const filterPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        filterPath.setAttribute('d', 'M3 5h18l-7 8v5l-4 2v-7z');
        filterIcon.append(filterPath);
        filterButton.append(filterIcon);
        const popover = element('div', 'ukit-table-filter-popover');
        popover.setAttribute('role', 'dialog');
        popover.setAttribute('aria-label', tableTranslate('table.filterDialog', {label}));
        filterButton.addEventListener('click', () => {
          if (openFilter?.popover === popover) {
            closeFilter();
            return;
          }
          closeFilter();
          popover.replaceChildren();
          const values = [...new Set(rows.map(row => text(row[column.key])))];
          const selectedValues = new Set(activeFilters.get(column.key) || []);
          values.forEach(value => {
            const option = element('label', 'ukit-table-filter-option');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = value;
            checkbox.checked = selectedValues.has(value);
            option.append(checkbox, element('span', '', value || tableTranslate('table.emptyValue')));
            popover.append(option);
          });
          const actions = element('div', 'ukit-table-filter-actions');
          const clearButton = createButton({
            text: tableTranslate('table.clear'),
            variant: 'outline',
            onClick: () => {
              activeFilters.delete(column.key);
              updateFilterButton(column);
              currentPage = 1;
              closeFilter();
              render();
            }
          });
          const applyButton = createButton({
            text: tableTranslate('table.apply'),
            variant: 'primary',
            onClick: () => {
              const checkedValues = new Set(
                [...popover.querySelectorAll('input:checked')].map(input => input.value)
              );
              if (checkedValues.size) activeFilters.set(column.key, checkedValues);
              else activeFilters.delete(column.key);
              updateFilterButton(column);
              currentPage = 1;
              closeFilter();
              render();
            }
          });
          actions.append(clearButton, applyButton);
          popover.append(actions);
          document.body.append(popover);

          const bounds = filterButton.getBoundingClientRect();
          const left = Math.min(
            bounds.left,
            window.innerWidth - popover.offsetWidth - 8
          );
          popover.style.top = `${bounds.bottom + 4}px`;
          popover.style.left = `${Math.max(8, left)}px`;
          filterButton.setAttribute('aria-expanded', 'true');
          const onOutsideClick = event => {
            if (!popover.contains(event.target) && !filterButton.contains(event.target)) closeFilter();
          };
          const onKeydown = event => {
            if (event.key === 'Escape') closeFilter(true);
          };
          const onScroll = () => closeFilter();
          openFilter = { popover, button: filterButton, onOutsideClick, onKeydown, onScroll };
          document.addEventListener('pointerdown', onOutsideClick);
          document.addEventListener('keydown', onKeydown);
          document.addEventListener('scroll', onScroll, true);
          popover.querySelector('input')?.focus();
        });
        controls.append(filterButton);
      }
      if (controls.childElementCount) th.append(controls);
      headRow.append(th);
    });
    thead.append(headRow); table.append(thead);
    const tbody = document.createElement('tbody'); table.append(tbody); wrapper.append(table);
    const pagination = element('div', 'ukit-table-pagination');
    const paginationSummary = element('span', 'ukit-table-pagination-summary');
    pagination.append(paginationSummary);
    wrapper.append(pagination);
    function renderPagination(pageCount, totalRows, start) {
      const first = totalRows ? start + 1 : 0;
      const last = Math.min(start + pageSize, totalRows);
      paginationSummary.textContent = tableTranslate('table.showingRows', {first,last,total:totalRows});
      paginationPages.replaceChildren();

      const previous = createButton({
        text: '‹',
        variant: 'outline',
        ariaLabel: tableTranslate('table.previousPage'),
        disabled: currentPage === 1,
        onClick: () => { currentPage--; render(); }
      });
      previous.classList.add('ukit-table-page-button');
      paginationPages.append(previous);
      for (let page = 1; page <= pageCount; page++) {
        const button = createButton({
          text: String(page),
          variant: page === currentPage ? 'primary' : 'outline',
          ariaLabel: tableTranslate('table.page', {page}),
          onClick: () => { currentPage = page; render(); }
        });
        button.classList.add('ukit-table-page-button');
        if (page === currentPage) button.setAttribute('aria-current', 'page');
        paginationPages.append(button);
      }
      const next = createButton({
        text: '›',
        variant: 'outline',
        ariaLabel: tableTranslate('table.nextPage'),
        disabled: currentPage === pageCount,
        onClick: () => { currentPage++; render(); }
      });
      next.classList.add('ukit-table-page-button');
      paginationPages.append(next);
    }
    function render() {
      let filtered = rows.filter(row => {
        const matchesQuery = !query || columns.some(column => String(row[column.key] ?? '').toLowerCase().includes(query));
        const matchesFilters = [...activeFilters].every(([key, values]) => values.has(text(row[key])));
        return matchesQuery && matchesFilters;
      });
      if (sortKey) filtered.sort((a, b) => String(a[sortKey] ?? '').localeCompare(String(b[sortKey] ?? ''), undefined, {numeric:true, sensitivity:'base'}) * sortDirection);
      const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
      currentPage = Math.min(currentPage, pageCount);
      const start = (currentPage - 1) * pageSize;
      const pageRows = filtered.slice(start, start + pageSize);
      tbody.replaceChildren();
      if (!pageRows.length) {
        const tr = document.createElement('tr');
        const td = element('td', 'ukit-table-empty', emptyText);
        td.colSpan = Math.max(columns.length, 1);
        tr.append(td);
        tbody.append(tr);
      } else {
        pageRows.forEach(row => {
          const tr = document.createElement('tr');
          columns.forEach(column => {
            const td = document.createElement('td');
            const value = row[column.key];
            if (typeof column.render === 'function') {
              const rendered = column.render(value, row);
              if (rendered instanceof Node) td.append(rendered);
              else td.textContent = text(rendered);
            } else td.textContent = text(value);
            tr.append(td);
          });
          tbody.append(tr);
        });
      }
      renderPagination(pageCount, filtered.length, start);
    }
    search.addEventListener('input', () => { query = search.value.trim().toLowerCase(); currentPage = 1; render(); });
    pageSizeSelect.addEventListener('change', () => { pageSize = Number(pageSizeSelect.value); currentPage = 1; render(); });
    document.addEventListener('ui-language-change', () => {
      closeFilter();
      search.placeholder = options.searchPlaceholder || tableTranslate('table.searchPlaceholder');
      search.setAttribute('aria-label', options.searchLabel || tableTranslate('table.searchLabel'));
      pageSizeLabel.firstChild.nodeValue = tableTranslate('table.pageSize');
      pageSizeSelect.setAttribute('aria-label', tableTranslate('table.pageSize'));
      columns.forEach((column, index) => {
        const header = headRow.children[index];
        const label = columnLabel(column);
        const sortButton = header.querySelector('.ukit-table-sort-button');
        const filterButton = header.querySelector('.ukit-table-filter-button');
        if (sortButton) {
          sortButton.firstChild.nodeValue = label;
          const isActive = column.key === sortKey;
          sortButton.setAttribute('aria-label', isActive
            ? `${tableTranslate('table.sortBy', {label})} ${tableTranslate(sortDirection === 1 ? 'table.sortDescending' : 'table.sortAscending')}`
            : tableTranslate('table.sortBy', {label}));
        } else if (column.filterable) {
          header.querySelector('.ukit-table-header-label').textContent = label;
        } else {
          header.textContent = label;
        }
        if (filterButton) updateFilterButton(column);
      });
      render();
    });
    render();
    return {
      element: wrapper,
      setData(data) { rows = Array.isArray(data) ? data.slice() : []; currentPage = 1; render(); },
      setEmptyText(value) { emptyText = text(value); render(); },
      getData() { return rows.slice(); },
      refresh: render
    };
  }
  global.UIKit = Object.freeze({ createButton, createBadge, createCard, createModal, createTable, version: '1.0.0' });
})(window);
