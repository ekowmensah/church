(function (window, document, $) {
    'use strict';

    if (!$ || !$.fn || !$.fn.dataTable || !$.fn.dataTable.ext) {
        return;
    }

    var EXPORT_PAGE_SIZE = 100;
    var FETCH_CONCURRENCY = 4;
    var progressNotice = null;

    function numberAttribute(element, name) {
        var value = element ? parseInt(element.getAttribute(name) || '0', 10) : 0;
        return Number.isFinite(value) ? value : 0;
    }

    function tableDataRows(table) {
        if (!table || !table.tBodies.length) return [];
        return Array.prototype.filter.call(table.tBodies[0].rows, function (row) {
            return !(row.cells.length === 1 && row.cells[0].hasAttribute('colspan'));
        });
    }

    function paginationMetaFor(table) {
        if (!table) return null;

        var directTotal = numberAttribute(table, 'data-total-rows');
        if (directTotal > 0) {
            return {
                element: table,
                totalRows: directTotal,
                totalPages: numberAttribute(table, 'data-total-pages'),
                currentPage: numberAttribute(table, 'data-current-page'),
                perPage: numberAttribute(table, 'data-per-page')
            };
        }

        var ancestor = table.parentElement;
        while (ancestor) {
            var localMeta = ancestor.querySelector('.report-server-pagination[data-report-pagination-meta="true"]');
            if (localMeta) {
                return {
                    element: localMeta,
                    totalRows: numberAttribute(localMeta, 'data-total-rows'),
                    totalPages: numberAttribute(localMeta, 'data-total-pages'),
                    currentPage: numberAttribute(localMeta, 'data-current-page'),
                    perPage: numberAttribute(localMeta, 'data-per-page')
                };
            }
            ancestor = ancestor.parentElement;
        }

        var tables = Array.prototype.slice.call(document.querySelectorAll('table[data-report-pagination="server"]'));
        var metas = Array.prototype.slice.call(document.querySelectorAll('.report-server-pagination[data-report-pagination-meta="true"]'));
        var index = tables.indexOf(table);
        var meta = index >= 0 ? metas[index] : null;
        return meta ? {
            element: meta,
            totalRows: numberAttribute(meta, 'data-total-rows'),
            totalPages: numberAttribute(meta, 'data-total-pages'),
            currentPage: numberAttribute(meta, 'data-current-page'),
            perPage: numberAttribute(meta, 'data-per-page')
        } : null;
    }

    function shouldLoadAllRows(table) {
        var meta = paginationMetaFor(table);
        return meta && meta.totalRows > tableDataRows(table).length;
    }

    function reportTableIndex(table) {
        return Array.prototype.indexOf.call(
            document.querySelectorAll('table[data-report-pagination="server"]'),
            table
        );
    }

    function findFetchedTable(parsedDocument, table, index) {
        if (table.id) {
            var byId = parsedDocument.getElementById(table.id);
            if (byId) return byId;
        }
        var serverTables = parsedDocument.querySelectorAll('table[data-report-pagination="server"]');
        return serverTables[index] || null;
    }

    function normalizeText(value) {
        return String(value || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function showProgress(message) {
        if (!progressNotice) {
            progressNotice = document.createElement('div');
            progressNotice.setAttribute('role', 'status');
            progressNotice.setAttribute('aria-live', 'polite');
            progressNotice.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:2147483640;max-width:360px;padding:12px 16px;border-radius:9px;background:#173f5f;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.24);font-size:14px;font-weight:600';
            document.body.appendChild(progressNotice);
        }
        progressNotice.textContent = message;
        progressNotice.style.display = 'block';
    }

    function hideProgress() {
        if (progressNotice) progressNotice.style.display = 'none';
    }

    function renderPrintPreparation(targetWindow, reportTitle, onCancel) {
        if (!targetWindow || targetWindow.closed) return;
        var brand = window.MyFreemanReportBrand || {};
        var primary = brand.primary_color || '#173F5F';
        var accent = brand.accent_color || '#236B45';
        var churchName = brand.church_name || 'MyFreeman Church Portal';
        var logo = brand.logo_data_uri || '';
        var logoMarkup = logo
            ? '<img class="prep-logo" src="' + escapeHtml(logo) + '" alt="Church logo">'
            : '<div class="prep-logo-fallback" aria-hidden="true">MF</div>';
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>Preparing ' + escapeHtml(reportTitle) + '</title><style>'
            + ':root{color-scheme:light}*{box-sizing:border-box}html,body{min-height:100%;margin:0}body{display:grid;place-items:center;padding:28px;background:radial-gradient(circle at 16% 12%,rgba(35,107,69,.16),transparent 30%),linear-gradient(145deg,#eef4f7 0%,#f9fbfc 48%,#edf3f1 100%);color:#243746;font-family:Arial,Helvetica,sans-serif}'
            + '.prep-shell{width:min(94vw,560px)}.prep-card{position:relative;overflow:hidden;border:1px solid rgba(23,63,95,.14);border-radius:22px;background:rgba(255,255,255,.96);box-shadow:0 24px 70px rgba(23,63,95,.18);padding:30px}.prep-card:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,' + primary + ',' + accent + ')}'
            + '.prep-brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}.prep-logo{display:block;width:48px;height:48px;object-fit:contain}.prep-logo-fallback{display:grid;place-items:center;width:48px;height:48px;border-radius:14px;background:' + primary + ';color:#fff;font-size:15px;font-weight:800}.prep-brand-copy{min-width:0}.prep-church{overflow:hidden;color:' + primary + ';font-size:15px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}.prep-label{margin-top:3px;color:#73838e;font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}'
            + '.prep-main{display:flex;align-items:flex-start;gap:19px}.prep-spinner{position:relative;flex:0 0 58px;width:58px;height:58px;border:6px solid #dce7ec;border-top-color:' + accent + ';border-radius:50%;animation:prep-spin .85s linear infinite}.prep-spinner:after{content:"";position:absolute;inset:10px;border-radius:50%;background:#f3f8f5}.prep-content{min-width:0;flex:1}.prep-content h1{margin:1px 0 7px;color:' + primary + ';font-size:22px;line-height:1.18}.prep-report{margin:0 0 16px;color:#657783;font-size:13px;line-height:1.45}.prep-report strong{color:#334c5c}'
            + '.prep-track{position:relative;overflow:hidden;height:8px;border-radius:999px;background:#e6edf1}.prep-bar{width:34%;height:100%;border-radius:inherit;background:linear-gradient(90deg,' + primary + ',' + accent + ');animation:prep-slide 1.35s ease-in-out infinite}.prep-track.is-determinate .prep-bar{animation:none;transition:width .25s ease}.prep-status{margin:13px 0 0;color:#334c5c;font-size:13px;font-weight:700}.prep-detail{min-height:18px;margin:4px 0 0;color:#7a8993;font-size:12px}'
            + '.prep-actions{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-top:26px;padding-top:20px;border-top:1px solid #e7edf0}.prep-note{margin:0;color:#7b8992;font-size:11px;line-height:1.4}.prep-cancel{appearance:none;border:1px solid #cbd6dc;border-radius:10px;background:#fff;color:#334c5c;padding:9px 16px;font:700 12px Arial,sans-serif;cursor:pointer}.prep-cancel:hover{border-color:' + primary + ';color:' + primary + ';background:#f6f9fa}.prep-cancel:focus-visible{outline:3px solid rgba(35,107,69,.2);outline-offset:2px}'
            + 'body.is-error .prep-spinner{display:grid;place-items:center;border:0;background:#fbe8e9;color:#a52a32;animation:none}body.is-error .prep-spinner:before{content:"!";font-size:26px;font-weight:800}body.is-error .prep-spinner:after{display:none}body.is-error .prep-bar{width:100%!important;background:#b83b44;animation:none}'
            + '@keyframes prep-spin{to{transform:rotate(360deg)}}@keyframes prep-slide{0%{transform:translateX(-120%)}50%{transform:translateX(195%)}100%{transform:translateX(-120%)}}@media(max-width:520px){.prep-card{padding:24px 20px}.prep-main{gap:14px}.prep-spinner{flex-basis:48px;width:48px;height:48px}.prep-content h1{font-size:19px}.prep-actions{align-items:flex-start;flex-direction:column}.prep-cancel{width:100%}}@media(prefers-reduced-motion:reduce){.prep-spinner,.prep-bar{animation-duration:2.4s}.prep-track.is-determinate .prep-bar{transition:none}}'
            + '</style></head><body><main class="prep-shell"><section class="prep-card">'
            + '<div class="prep-brand">' + logoMarkup + '<div class="prep-brand-copy"><div class="prep-church">' + escapeHtml(churchName) + '</div><div class="prep-label">Report print service</div></div></div>'
            + '<div class="prep-main" role="status" aria-live="polite" aria-atomic="true"><div class="prep-spinner" aria-hidden="true"></div><div class="prep-content"><h1 id="mfPrintPrepHeading">Preparing your report</h1><p class="prep-report">Collecting every record for <strong>' + escapeHtml(reportTitle) + '</strong>.</p>'
            + '<div class="prep-track" id="mfPrintPrepTrack" role="progressbar" aria-label="Report preparation progress"><div class="prep-bar" id="mfPrintPrepBar"></div></div><p class="prep-status" id="mfPrintPrepStatus">Checking the active filters</p><p class="prep-detail" id="mfPrintPrepDetail">This window will open the browser print preview automatically.</p></div></div>'
            + '<div class="prep-actions"><p class="prep-note">You can continue working after the print preview opens.</p><button class="prep-cancel" id="mfPrintPrepCancel" type="button">Cancel</button></div>'
            + '</section></main></body></html>';

        targetWindow.document.open();
        targetWindow.document.write(html);
        targetWindow.document.close();
        var cancelButton = targetWindow.document.getElementById('mfPrintPrepCancel');
        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                if (typeof onCancel === 'function') onCancel();
                targetWindow.close();
            });
        }
    }

    function updatePrintPreparation(targetWindow, status, detail, completed, total) {
        if (!targetWindow || targetWindow.closed) return;
        var doc = targetWindow.document;
        var statusNode = doc.getElementById('mfPrintPrepStatus');
        var detailNode = doc.getElementById('mfPrintPrepDetail');
        var track = doc.getElementById('mfPrintPrepTrack');
        var bar = doc.getElementById('mfPrintPrepBar');
        if (statusNode) statusNode.textContent = status || 'Preparing the report';
        if (detailNode) detailNode.textContent = detail || '';
        if (track && bar && Number.isFinite(completed) && Number.isFinite(total) && total > 0) {
            var percent = Math.max(0, Math.min(100, Math.round((completed / total) * 100)));
            track.classList.add('is-determinate');
            track.setAttribute('aria-valuemin', '0');
            track.setAttribute('aria-valuemax', '100');
            track.setAttribute('aria-valuenow', String(percent));
            bar.style.width = percent + '%';
        }
    }

    function showPrintPreparationError(targetWindow, message) {
        if (!targetWindow || targetWindow.closed) return;
        var doc = targetWindow.document;
        if (doc.body) doc.body.classList.add('is-error');
        var heading = doc.getElementById('mfPrintPrepHeading');
        var cancelButton = doc.getElementById('mfPrintPrepCancel');
        if (heading) heading.textContent = 'Unable to prepare this report';
        if (cancelButton) cancelButton.textContent = 'Close';
        updatePrintPreparation(targetWindow, message, 'Review the message, close this window, and try again.', 1, 1);
    }

    function fetchPage(url, table, tableIndex, signal) {
        return window.fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'Full-Filtered-Report-Export' },
            signal: signal
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('The report server returned HTTP ' + response.status + '.');
            }
            if (/\/login(?:\.php)?(?:\?|$)/i.test(response.url || '')) {
                throw new Error('Your session expired. Sign in again before exporting.');
            }
            return response.text();
        }).then(function (html) {
            var parsed = new window.DOMParser().parseFromString(html, 'text/html');
            var fetchedTable = findFetchedTable(parsed, table, tableIndex);
            if (!fetchedTable) {
                throw new Error('The filtered report table could not be found in a paginated response.');
            }
            return tableDataRows(fetchedTable).map(function (row) {
                return row.cloneNode(true);
            });
        });
    }

    function fetchAllRows(table, dataTableApi, onProgress, signal) {
        var meta = paginationMetaFor(table);
        if (!meta || meta.totalRows <= tableDataRows(table).length) {
            var localRows = tableDataRows(table).map(function (row) { return row.cloneNode(true); });
            if (typeof onProgress === 'function') onProgress(1, 1, localRows.length);
            return Promise.resolve(localRows);
        }

        var totalPages = Math.max(1, Math.ceil(meta.totalRows / EXPORT_PAGE_SIZE));
        var tableIndex = reportTableIndex(table);
        var pageRows = new Array(totalPages);
        var nextPage = 1;
        var completed = 0;
        var searchTerm = dataTableApi && typeof dataTableApi.search === 'function'
            ? normalizeText(dataTableApi.search()).toLowerCase()
            : '';

        function worker() {
            if (nextPage > totalPages) return Promise.resolve();
            var pageNumber = nextPage++;
            var url = new URL(window.location.href);
            url.searchParams.delete('export');
            url.searchParams.set('page', String(pageNumber));
            url.searchParams.set('per_page', String(EXPORT_PAGE_SIZE));

            return fetchPage(url, table, tableIndex, signal).then(function (rows) {
                pageRows[pageNumber - 1] = rows;
                completed += 1;
                if (typeof onProgress === 'function') {
                    onProgress(completed, totalPages, meta.totalRows);
                } else {
                    showProgress('Preparing complete filtered export: page ' + completed + ' of ' + totalPages + '...');
                }
                return worker();
            });
        }

        if (typeof onProgress === 'function') {
            onProgress(0, totalPages, meta.totalRows);
        } else {
            showProgress('Preparing complete filtered export: page 0 of ' + totalPages + '...');
        }
        var workers = [];
        for (var workerIndex = 0; workerIndex < Math.min(FETCH_CONCURRENCY, totalPages); workerIndex += 1) {
            workers.push(worker());
        }

        return Promise.all(workers).then(function () {
            var rows = [].concat.apply([], pageRows);
            if (searchTerm) {
                rows = rows.filter(function (row) {
                    return normalizeText(row.textContent).toLowerCase().indexOf(searchTerm) !== -1;
                });
            }
            return rows;
        });
    }

    function temporaryDataTable(sourceTable, rows) {
        var table = document.createElement('table');
        table.setAttribute('data-report-pagination', 'off');
        table.style.cssText = 'position:fixed;left:-100000px;top:0;visibility:hidden';
        if (sourceTable.tHead) table.appendChild(sourceTable.tHead.cloneNode(true));
        var body = document.createElement('tbody');
        rows.forEach(function (row) { body.appendChild(row); });
        table.appendChild(body);
        document.body.appendChild(table);

        var api = $(table).DataTable({
            paging: false,
            searching: false,
            ordering: false,
            info: false,
            responsive: false,
            autoWidth: false,
            dom: 't'
        });
        return { table: table, api: api };
    }

    function cleanTemporaryTable(temporary) {
        window.setTimeout(function () {
            try { temporary.api.destroy(); } catch (ignore) {}
            if (temporary.table.parentNode) temporary.table.parentNode.removeChild(temporary.table);
        }, 5000);
    }

    function titleFrom(config, dataTableApi) {
        var configured = config && config.title;
        if (typeof configured === 'function') configured = configured();
        var selectors = ['.report-page-header h1', '.content-header h1', 'main h1', '.card-title', 'h2'];
        var heading = null;
        selectors.some(function (selector) {
            heading = document.querySelector(selector);
            return Boolean(heading);
        });
        var baseTitle = configured && configured !== '*'
            ? normalizeText(configured)
            : (normalizeText(heading ? heading.textContent : document.title) || 'Filtered report');
        var branding = window.MyFreemanExportBranding;
        return branding && typeof branding.descriptiveTitle === 'function'
            ? branding.descriptiveTitle(
                baseTitle,
                typeof branding.activeFilterDetails === 'function'
                    ? branding.activeFilterDetails(dataTableApi)
                    : undefined
            )
            : baseTitle;
    }

    function excludedColumnIndexes(table) {
        if (!table.tHead || !table.tHead.rows.length) return [];
        var header = table.tHead.rows[table.tHead.rows.length - 1];
        var excluded = [];
        Array.prototype.forEach.call(header.cells, function (cell, index) {
            var label = normalizeText(cell.textContent).toLowerCase();
            if (cell.matches('[data-export="false"], .no-export') || /^(action|actions|manage|options)$/.test(label)) {
                excluded.push(index);
            }
        });
        return excluded;
    }

    function printableCellText(cell) {
        var clone = cell.cloneNode(true);
        Array.prototype.forEach.call(clone.querySelectorAll('pre[hidden]'), function (evidence) {
            evidence.removeAttribute('hidden');
        });
        Array.prototype.forEach.call(clone.querySelectorAll('button, input, select, textarea, .dropdown-menu, [hidden]'), function (control) {
            control.parentNode.removeChild(control);
        });
        Array.prototype.forEach.call(clone.querySelectorAll('br'), function (lineBreak) {
            lineBreak.parentNode.replaceChild(document.createTextNode(' / '), lineBreak);
        });
        return normalizeText(clone.textContent);
    }

    function printColumnWeight(label) {
        label = normalizeText(label).toLowerCase();
        if (/^(#|no\.?|id)$/.test(label)) return 4;
        if (/(amount|total|balance|count|quantity|transactions)/.test(label)) return 8;
        if (/(date|period|time|gender|status|age)/.test(label)) return 9;
        if (/(crn|srn|phone|contact|reference|number)/.test(label)) return 11;
        if (/(name|member|church|class|organization|department|address|town|location)/.test(label)) return 16;
        if (/(message|description|details|reason|purpose|notes)/.test(label)) return 22;
        return 12;
    }

    function printableTable(sourceTable, rows) {
        var table = document.createElement('table');
        var excluded = excludedColumnIndexes(sourceTable);
        var sourceHeader = sourceTable.tHead && sourceTable.tHead.rows.length
            ? sourceTable.tHead.rows[sourceTable.tHead.rows.length - 1]
            : null;
        var includedIndexes = [];
        var labels = [];
        if (sourceHeader) {
            Array.prototype.forEach.call(sourceHeader.cells, function (cell, index) {
                if (excluded.indexOf(index) !== -1) return;
                includedIndexes.push(index);
                labels.push(printableCellText(cell));
            });
        }

        var colgroup = document.createElement('colgroup');
        var weights = labels.map(printColumnWeight);
        var totalWeight = weights.reduce(function (total, weight) { return total + weight; }, 0) || 1;
        weights.forEach(function (weight) {
            var column = document.createElement('col');
            column.style.width = ((weight / totalWeight) * 100).toFixed(2) + '%';
            colgroup.appendChild(column);
        });
        table.appendChild(colgroup);

        var head = document.createElement('thead');
        var headerRow = document.createElement('tr');
        labels.forEach(function (label) {
            var heading = document.createElement('th');
            heading.textContent = label;
            headerRow.appendChild(heading);
        });
        head.appendChild(headerRow);
        table.appendChild(head);

        var body = document.createElement('tbody');
        rows.forEach(function (row) {
            var cleanRow = document.createElement('tr');
            includedIndexes.forEach(function (cellIndex) {
                var sourceCell = row.cells[cellIndex];
                var cleanCell = document.createElement('td');
                cleanCell.textContent = sourceCell ? printableCellText(sourceCell) : '';
                if (sourceCell && (sourceCell.classList.contains('text-right') || sourceCell.classList.contains('text-center'))) {
                    cleanCell.className = sourceCell.classList.contains('text-right') ? 'numeric' : 'centred';
                }
                cleanRow.appendChild(cleanCell);
            });
            body.appendChild(cleanRow);
        });
        table.appendChild(body);
        return { table: table, columnCount: includedIndexes.length };
    }

    function escapeHtml(value) {
        var node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function activeFilterSummary(dataTableApi) {
        var branding = window.MyFreemanExportBranding;
        if (branding && typeof branding.activeFilterDetails === 'function') {
            var details = branding.activeFilterDetails(dataTableApi);
            if (details.length) {
                return details.map(function (filter) {
                    return filter.label + ': ' + filter.value;
                }).join('  |  ');
            }
        }
        var ignored = ['page', 'per_page', 'export', 'format'];
        var filters = [];
        new URL(window.location.href).searchParams.forEach(function (value, key) {
            if (!value || ignored.indexOf(key) !== -1) return;
            var label = key.replace(/_/g, ' ').replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
            filters.push(label + ': ' + value);
        });
        return filters.length ? filters.join('  |  ') : 'All records within your authorized scope';
    }

    function waitForPrintDocument(targetWindow) {
        var doc = targetWindow.document;
        var imagePromises = Array.prototype.map.call(doc.images, function (image) {
            if (image.complete) return Promise.resolve();
            return new Promise(function (resolve) {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        });
        var fontsReady = doc.fonts && doc.fonts.ready ? doc.fonts.ready.catch(function () {}) : Promise.resolve();
        var assetsReady = Promise.all([fontsReady].concat(imagePromises));
        var safetyTimeout = new Promise(function (resolve) { window.setTimeout(resolve, 1500); });
        return Promise.race([assetsReady, safetyTimeout]).then(function () {
            // Force one synchronous layout pass, then yield briefly. Using a
            // popup requestAnimationFrame here can stall indefinitely when
            // the browser throttles background windows.
            if (doc.body) void doc.body.offsetHeight;
            return new Promise(function (resolve) {
                window.setTimeout(resolve, 100);
            });
        });
    }

    function printRows(sourceTable, rows, title, printWindow, dataTableApi) {
        var targetWindow = printWindow || window.open('', '_blank');
        if (!targetWindow) throw new Error('The print window was blocked by the browser.');
        var printable = printableTable(sourceTable, rows);
        var columnCount = printable.columnCount;
        var orientation = columnCount >= 7 ? 'landscape' : 'portrait';
        var fontSize = columnCount >= 12 ? 6.4 : (columnCount >= 9 ? 7.2 : (columnCount >= 7 ? 8 : 9));
        var brand = window.MyFreemanReportBrand || {};
        var primary = brand.primary_color || '#173F5F';
        var accent = brand.accent_color || '#236B45';
        var headerText = brand.header_text_color || '#FFFFFF';
        var churchName = brand.church_name || 'MyFreeman Church Portal';
        var logo = brand.logo_data_uri || '';
        var generatedAt = new Date().toLocaleString();
        var metaLine = rows.length.toLocaleString() + ' filtered record' + (rows.length === 1 ? '' : 's')
            + '  |  ' + columnCount + ' columns  |  ' + orientation.charAt(0).toUpperCase() + orientation.slice(1);
        var logoMarkup = logo ? '<img class="report-logo" src="' + logo + '" alt="Church logo">' : '';
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + escapeHtml(title) + '</title><style>'
            + '@page{size:A4 ' + orientation + ';margin:' + (orientation === 'landscape' ? '8mm' : '10mm') + '}'
            + '*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#243746;font-family:Arial,Helvetica,sans-serif;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}'
            + '.report-header{display:flex;align-items:center;justify-content:space-between;gap:16px;border-bottom:3px solid ' + accent + ';padding:0 0 9px;margin:0 0 8px;break-after:avoid}'
            + '.report-brand{display:flex;align-items:center;min-width:0;gap:9px}.report-logo{display:block;width:auto;height:auto;max-height:48px;max-width:66px}.report-church{font-size:13px;font-weight:700;color:' + primary + '}.report-generated{font-size:8px;color:#60727f;margin-top:3px}'
            + '.report-heading{text-align:right;min-width:34%}.report-heading h1{margin:0;color:' + primary + ';font-size:' + (orientation === 'landscape' ? '17px' : '19px') + ';font-style:italic;line-height:1.12}.report-heading p{margin:4px 0 0;color:#60727f;font-size:8px}'
            + '.filter-summary{margin:0 0 8px;padding:6px 8px;border:1px solid #d9e1e7;border-left:3px solid ' + accent + ';border-radius:3px;background:#f7f9fa;font-size:7.5px;line-height:1.35;overflow-wrap:anywhere}'
            + 'table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:' + fontSize + 'px;line-height:1.23}thead{display:table-header-group}tfoot{display:table-footer-group}tr{break-inside:avoid;page-break-inside:avoid}th,td{border:1px solid #c8d2d9;padding:' + (columnCount >= 9 ? '3px 4px' : '4px 5px') + ';vertical-align:middle;text-align:center;white-space:normal;overflow-wrap:anywhere;word-break:normal}th{background:' + primary + '!important;color:' + headerText + '!important;font-weight:700}tbody tr:nth-child(even) td{background:#f3f7f9!important}.numeric,.centred{text-align:center;font-variant-numeric:tabular-nums}'
            + '.report-footer{margin-top:9px;padding-top:7px;border-top:1px solid #d9e1e7;text-align:center;color:' + accent + ';font-size:7px;line-height:1.35;break-inside:avoid}.report-footer strong{display:block}'
            + '@media screen{body{padding:18px;max-width:' + (orientation === 'landscape' ? '1120px' : '820px') + ';margin:auto;box-shadow:0 0 28px rgba(0,0,0,.12)}}'
            + '</style></head><body>'
            + '<header class="report-header"><div class="report-brand">' + logoMarkup + '<div><div class="report-church">' + escapeHtml(churchName) + '</div><div class="report-generated">Generated ' + escapeHtml(generatedAt) + '</div></div></div>'
            + '<div class="report-heading"><h1>' + escapeHtml(title) + '</h1><p>' + escapeHtml(metaLine) + '</p></div></header>'
            + '<div class="filter-summary"><strong>Active filters:</strong> ' + escapeHtml(activeFilterSummary(dataTableApi)) + '</div>'
            + printable.table.outerHTML
            + '<footer class="report-footer"><strong>' + escapeHtml(brand.footer_line_one || '') + '</strong>' + escapeHtml(brand.footer_line_two || '') + '</footer>'
            + '</body></html>';

        targetWindow.document.open();
        targetWindow.document.write(html);
        targetWindow.document.close();
        return waitForPrintDocument(targetWindow).then(function () {
            if (targetWindow.closed) throw new Error('The print window was closed before it was ready.');
            targetWindow.addEventListener('afterprint', function () {
                window.setTimeout(function () {
                    if (!targetWindow.closed) targetWindow.close();
                }, 250);
            }, { once: true });
            targetWindow.focus();
            targetWindow.print();
        });
    }

    function setButtonBusy(node, busy) {
        var button = $(node);
        if (!button.length) return;
        if (busy) {
            button.data('full-export-html', button.html());
            button.prop('disabled', true).attr('aria-busy', 'true');
            button.html('<i class="fas fa-spinner fa-spin mr-1"></i>Preparing...');
        } else {
            button.prop('disabled', false).removeAttr('aria-busy');
            if (button.data('full-export-html')) button.html(button.data('full-export-html'));
        }
    }

    function reportExportError(error, printWindow) {
        hideProgress();
        if (error && error.name === 'AbortError') return;
        var message = error && error.message ? error.message : 'The complete filtered export could not be prepared.';
        if (printWindow && !printWindow.closed) {
            showPrintPreparationError(printWindow, message);
        }
        window.alert(message);
    }

    function printProgressUpdater(printWindow) {
        return function (completed, totalPages, totalRows) {
            var recordLabel = Number(totalRows || 0).toLocaleString() + ' filtered record'
                + (Number(totalRows || 0) === 1 ? '' : 's');
            var status = totalPages > 1 ? 'Collecting filtered report pages' : 'Collecting filtered records';
            var detail = totalPages > 1
                ? 'Loaded page ' + completed + ' of ' + totalPages + '  ·  ' + recordLabel
                : recordLabel + ' ready for layout';
            updatePrintPreparation(printWindow, status, detail, completed, totalPages);
        };
    }

    function installCompleteDataTableAction(buttonName, mode) {
        var buttons = $.fn.dataTable.ext.buttons;
        var definition = buttons && buttons[buttonName];
        if (!definition || typeof definition.action !== 'function' || definition._fullFilteredActionInstalled) return;
        var originalAction = definition.action;
        definition._fullFilteredActionInstalled = true;
        definition.action = function (event, dataTableApi, node, config) {
            var sourceTable = dataTableApi.table().node();
            var isServerTable = sourceTable && sourceTable.getAttribute('data-report-pagination') === 'server';
            var needsCompleteServerRows = isServerTable && shouldLoadAllRows(sourceTable);
            if (!sourceTable || (mode !== 'print' && !needsCompleteServerRows)) {
                return originalAction.call(this, event, dataTableApi, node, config);
            }

            var actionContext = this;
            var printWindow = mode === 'print' ? window.open('', '_blank') : null;
            var printAbortController = mode === 'print' && typeof window.AbortController === 'function'
                ? new window.AbortController()
                : null;
            var reportTitle = titleFrom(config, dataTableApi);
            if (printWindow) {
                renderPrintPreparation(printWindow, reportTitle, function () {
                    if (printAbortController) printAbortController.abort();
                });
            }
            setButtonBusy(node, true);

            var onPrintProgress = mode === 'print' ? printProgressUpdater(printWindow) : null;
            var rowsPromise = needsCompleteServerRows
                ? fetchAllRows(sourceTable, dataTableApi, onPrintProgress, printAbortController ? printAbortController.signal : undefined)
                : rowsForPrint(sourceTable, dataTableApi, onPrintProgress, printAbortController ? printAbortController.signal : undefined);
            rowsPromise.then(function (rows) {
                if (mode === 'print') {
                    if (!printWindow) throw new Error('The print window was blocked by the browser. Allow pop-ups for this portal and try again.');
                    if (printWindow.closed) return;
                    updatePrintPreparation(
                        printWindow,
                        'Building the print layout',
                        'Applying branding and choosing the best page orientation…',
                        1,
                        1
                    );
                    return printRows(sourceTable, rows, reportTitle, printWindow, dataTableApi);
                }
                var temporary = temporaryDataTable(sourceTable, rows);
                originalAction.call(actionContext, event, temporary.api, node, config);
                cleanTemporaryTable(temporary);
            }).catch(function (error) {
                reportExportError(error, printWindow);
            }).finally(function () {
                setButtonBusy(node, false);
                hideProgress();
            });
        };
    }

    function bestReportTable(trigger) {
        var container = trigger.closest('.card, .report-page-shell, .container-fluid, main');
        var table = container ? container.querySelector('table[data-report-pagination="server"]') : null;
        if (table && shouldLoadAllRows(table)) return table;
        var serverTables = document.querySelectorAll('table[data-report-pagination="server"]');
        for (var index = 0; index < serverTables.length; index += 1) {
            if (shouldLoadAllRows(serverTables[index])) return serverTables[index];
        }

        var candidates = container ? container.querySelectorAll('table') : document.querySelectorAll('table');
        var best = null;
        var bestCount = 0;
        Array.prototype.forEach.call(candidates, function (candidate) {
            if (!$.fn.DataTable.isDataTable(candidate)) return;
            var api = $(candidate).DataTable();
            var count = api.rows({ search: 'applied' }).count();
            if (count > bestCount) {
                best = candidate;
                bestCount = count;
            }
        });
        return best;
    }

    function rowsForPrint(table, api, onProgress, signal) {
        if (table.getAttribute('data-report-pagination') === 'server' && shouldLoadAllRows(table)) {
            return fetchAllRows(table, api, onProgress, signal);
        }
        if (api) {
            var filteredRows = api.rows({ search: 'applied', order: 'applied' }).nodes().toArray().map(function (row) {
                return row.cloneNode(true);
            });
            if (typeof onProgress === 'function') onProgress(1, 1, filteredRows.length);
            return Promise.resolve(filteredRows);
        }
        var rows = tableDataRows(table).map(function (row) { return row.cloneNode(true); });
        if (typeof onProgress === 'function') onProgress(1, 1, rows.length);
        return Promise.resolve(rows);
    }

    function interceptWindowPrintButtons() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('button[onclick*="window.print"], a[onclick*="window.print"]');
            if (!trigger) return;
            var table = bestReportTable(trigger);
            if (!table) return;

            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            var printWindow = window.open('', '_blank');
            var printAbortController = typeof window.AbortController === 'function'
                ? new window.AbortController()
                : null;
            var api = $.fn.DataTable.isDataTable(table) ? $(table).DataTable() : null;
            var reportTitle = titleFrom({}, api);
            if (printWindow) {
                renderPrintPreparation(printWindow, reportTitle, function () {
                    if (printAbortController) printAbortController.abort();
                });
            }
            setButtonBusy(trigger, true);
            rowsForPrint(
                table,
                api,
                printProgressUpdater(printWindow),
                printAbortController ? printAbortController.signal : undefined
            ).then(function (rows) {
                if (!printWindow) throw new Error('The print window was blocked by the browser. Allow pop-ups for this portal and try again.');
                if (printWindow.closed) return;
                updatePrintPreparation(
                    printWindow,
                    'Building the print layout',
                    'Applying branding and choosing the best page orientation…',
                    1,
                    1
                );
                return printRows(table, rows, reportTitle, printWindow, api);
            }).catch(function (error) {
                reportExportError(error, printWindow);
            }).finally(function () {
                setButtonBusy(trigger, false);
                hideProgress();
            });
        }, true);
    }

    installCompleteDataTableAction('csvHtml5', 'download');
    installCompleteDataTableAction('excelHtml5', 'download');
    installCompleteDataTableAction('pdfHtml5', 'download');
    installCompleteDataTableAction('print', 'print');
    interceptWindowPrintButtons();

    window.MyFreemanFullFilteredExport = {
        fetchAllRows: fetchAllRows,
        paginationMetaFor: paginationMetaFor
    };
})(window, document, window.jQuery);
