(function (window, $) {
    'use strict';

    var brand = window.MyFreemanReportBrand || {};
    var defaults = {
        church_name: 'MyFreeman Church Portal',
        primary_color: '#173F5F',
        accent_color: '#236B45',
        header_text_color: '#FFFFFF',
        footer_line_one: 'Powered By: MyFreeman Digital NetWorks - Evangelism, Through Digitalization!',
        footer_line_two: 'FDN: Extenditque Manum Omni Membri, Ubique!!',
        logo_url: '',
        logo_data_uri: ''
    };

    Object.keys(defaults).forEach(function (key) {
        if (!brand[key]) brand[key] = defaults[key];
    });

    var ignoredFilterKeys = {
        page: true,
        per_page: true,
        export: true,
        format: true,
        submit: true,
        csrf_token: true,
        token: true,
        report: true,
        action: true,
        view: true,
        tab: true,
        sort: true,
        order: true,
        direction: true,
        dir: true,
        offset: true,
        draw: true,
        request_id: true,
        modal: true,
        redirect: true,
        ajax: true,
        _: true
    };

    var filterLabelOverrides = {
        q: 'Search',
        search: 'Search',
        church_id: 'Church',
        member_id: 'Member',
        bible_class_id: 'Bible Class',
        bibleclass_id: 'Bible Class',
        class_id: 'Bible Class',
        organization_id: 'Organization',
        organisation_id: 'Organization',
        organization_unit_id: 'Organization Unit',
        payment_type_id: 'Payment Type',
        type_id: 'Payment Type',
        payment_method: 'Payment Method',
        payment_source: 'Payment Source',
        member_crn: 'Member CRN',
        user_id: 'Responsible User',
        start_date: 'Start Date',
        end_date: 'End Date',
        period_from: 'Period From',
        period_to: 'Period To',
        date_from: 'Date From',
        date_to: 'Date To',
        from_date: 'Date From',
        to_date: 'Date To',
        day_born: 'Day Born',
        role_id: 'Role',
        status: 'Status'
    };

    function normalizeText(value) {
        return String(value || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function titleCase(value) {
        return normalizeText(value).toLowerCase().replace(/\b\w/g, function (letter) {
            return letter.toUpperCase();
        });
    }

    function humanizeFilterKey(key) {
        var cleanKey = String(key || '').replace(/\[\]$/, '').toLowerCase();
        if (filterLabelOverrides[cleanKey]) return filterLabelOverrides[cleanKey];
        cleanKey = cleanKey.replace(/_id$/, '').replace(/_/g, ' ');
        return titleCase(cleanKey)
            .replace(/\bCrn\b/g, 'CRN')
            .replace(/\bSrn\b/g, 'SRN')
            .replace(/\bSms\b/g, 'SMS');
    }

    function associatedControl(key) {
        var controls = document.getElementsByName(key);
        if (controls && controls.length) return controls[0];
        if (!/\[\]$/.test(key)) {
            controls = document.getElementsByName(key + '[]');
            if (controls && controls.length) return controls[0];
        }
        return null;
    }

    function controlLabel(control, key) {
        if (!control) return humanizeFilterKey(key);
        var explicit = control.getAttribute('data-export-label') || control.getAttribute('aria-label');
        var label = explicit ? null : (control.labels && control.labels.length ? control.labels[0] : null);
        if (!label && control.closest) {
            var group = control.closest('.form-group, .filter-group, .form-field');
            if (group) label = group.querySelector('label');
        }
        return normalizeText(explicit || (label ? label.textContent : '') || humanizeFilterKey(key))
            .replace(/[\s:*]+$/, '');
    }

    function formatFilterValue(value) {
        var normalized = normalizeText(value);
        if (/^\d{4}-\d{2}$/.test(normalized)) {
            var monthParts = normalized.split('-');
            var monthDate = new Date(Date.UTC(Number(monthParts[0]), Number(monthParts[1]) - 1, 1));
            return monthDate.toLocaleDateString(undefined, { month: 'short', year: 'numeric', timeZone: 'UTC' });
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(normalized)) {
            var dateParts = normalized.split('-');
            var date = new Date(Date.UTC(Number(dateParts[0]), Number(dateParts[1]) - 1, Number(dateParts[2])));
            return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
        }
        if (/^(true|yes)$/i.test(normalized)) return 'Yes';
        if (/^false$/i.test(normalized)) return 'No';
        if (/^[a-z][a-z0-9_-]*$/i.test(normalized) && normalized.indexOf('_') !== -1) {
            return titleCase(normalized.replace(/_/g, ' '));
        }
        return normalized;
    }

    function displayValueForFilter(key, rawValues) {
        var control = associatedControl(key);
        if (control && control.tagName === 'SELECT') {
            var selected = Array.prototype.filter.call(control.options, function (option) {
                return option.selected && normalizeText(option.value) !== '';
            }).map(function (option) {
                return normalizeText(option.textContent);
            }).filter(function (value) {
                return value && !/^(all|any)(\s|$)/i.test(value);
            });
            return selected.join(', ');
        }
        if (control && /^(checkbox|radio)$/i.test(control.type || '')) {
            var checked = Array.prototype.filter.call(document.getElementsByName(key), function (candidate) {
                return candidate.checked;
            }).map(function (candidate) {
                var label = candidate.labels && candidate.labels.length ? candidate.labels[0] : null;
                return normalizeText(label ? label.textContent : candidate.value);
            });
            if (checked.length) return checked.join(', ');
        }
        return rawValues.map(formatFilterValue).filter(Boolean).join(', ');
    }

    function activeFilterDetails(dataTableApi) {
        var valuesByKey = {};
        var keyOrder = [];
        new URL(window.location.href).searchParams.forEach(function (value, key) {
            var normalizedKey = String(key || '').replace(/\[\]$/, '');
            var normalizedValue = normalizeText(value);
            if (ignoredFilterKeys[normalizedKey.toLowerCase()] || !normalizedValue
                || /^(all|any|none|\*)$/i.test(normalizedValue)) return;
            if (!valuesByKey[normalizedKey]) {
                valuesByKey[normalizedKey] = [];
                keyOrder.push(normalizedKey);
            }
            if (valuesByKey[normalizedKey].indexOf(normalizedValue) === -1) {
                valuesByKey[normalizedKey].push(normalizedValue);
            }
        });

        var consumed = {};
        var details = [];
        function addRange(fromKey, toKey, label) {
            if (!valuesByKey[fromKey] && !valuesByKey[toKey]) return;
            var from = valuesByKey[fromKey] ? displayValueForFilter(fromKey, valuesByKey[fromKey]) : '';
            var to = valuesByKey[toKey] ? displayValueForFilter(toKey, valuesByKey[toKey]) : '';
            var value = from && to
                ? (from === to ? from : from + ' to ' + to)
                : (from ? 'From ' + from : 'Up to ' + to);
            if (value) details.push({ label: label, value: value });
            consumed[fromKey] = true;
            consumed[toKey] = true;
        }

        addRange('period_from', 'period_to', 'Period');
        addRange('start_date', 'end_date', 'Date');
        addRange('date_from', 'date_to', 'Date');
        addRange('from_date', 'to_date', 'Date');

        keyOrder.forEach(function (key) {
            if (consumed[key]) return;
            var value = displayValueForFilter(key, valuesByKey[key]);
            if (!value || /^(all|any)(\s|$)/i.test(value)) return;
            details.push({ label: controlLabel(associatedControl(key), key), value: value });
        });
        if (dataTableApi && typeof dataTableApi.search === 'function') {
            var tableSearch = normalizeText(dataTableApi.search());
            var alreadyHasSearch = details.some(function (filter) {
                return filter.label.toLowerCase() === 'search';
            });
            if (tableSearch && !alreadyHasSearch) details.push({ label: 'Search', value: tableSearch });
        }
        return details;
    }

    function pageReportTitle() {
        var selectors = ['.report-page-header h1', '.content-header h1', 'main h1', '.card-title', 'h2'];
        var heading = null;
        selectors.some(function (selector) {
            heading = document.querySelector(selector);
            return Boolean(heading);
        });
        var title = normalizeText(heading ? heading.textContent : document.title);
        return title || 'Filtered Report';
    }

    function resolveBaseTitle(baseTitle) {
        var resolved = typeof baseTitle === 'function' ? baseTitle() : baseTitle;
        if (!resolved || resolved === '*') return pageReportTitle();
        return normalizeText(resolved);
    }

    function filterTitleSegment(details) {
        return (details || activeFilterDetails()).map(function (filter) {
            return filter.label + ': ' + filter.value;
        }).join(' | ');
    }

    function descriptiveTitle(baseTitle, details) {
        var mainTitle = resolveBaseTitle(baseTitle);
        var resolvedDetails = details || activeFilterDetails();
        var normalizedMainTitle = mainTitle.toLowerCase();
        var missingDetails = resolvedDetails.filter(function (filter) {
            var value = normalizeText(filter.value).toLowerCase();
            var completeFilter = normalizeText(filter.label + ': ' + filter.value).toLowerCase();
            return normalizedMainTitle.indexOf(completeFilter) === -1
                && (value.length < 3 || normalizedMainTitle.indexOf(value) === -1);
        });
        var filterSegment = filterTitleSegment(missingDetails);
        if (!filterSegment) {
            return mainTitle;
        }
        return mainTitle + ' - ' + filterSegment;
    }

    function exportFilename(title) {
        var filename = normalizeText(title)
            .replace(/[\\\/:*?"<>|]+/g, ' ')
            .replace(/\s+/g, ' ')
            .replace(/[. ]+$/, '');
        if (filename.length > 180) filename = filename.slice(0, 180).replace(/[. ]+$/, '');
        return filename || 'Filtered Report';
    }

    function applyExportIdentity(config, dataTableApi) {
        if (!config) return config;
        config._mfExportDataTable = dataTableApi || config._mfExportDataTable || null;
        if (config._mfExportIdentityApplied) return config;
        var baseTitle = config.title;
        config._mfExportIdentityApplied = true;
        config.title = function () {
            return descriptiveTitle(baseTitle, activeFilterDetails(config._mfExportDataTable));
        };
        config.filename = function () {
            return exportFilename(descriptiveTitle(baseTitle, activeFilterDetails(config._mfExportDataTable)));
        };
        return config;
    }

    function escapeXml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&apos;');
    }

    function columnLetters(reference) {
        var match = String(reference || 'A1').match(/[A-Z]+/i);
        return match ? match[0].toUpperCase() : 'A';
    }

    function excelColumnCount(letters) {
        return String(letters || 'A').toUpperCase().split('').reduce(function (count, letter) {
            return (count * 26) + letter.charCodeAt(0) - 64;
        }, 0);
    }

    function importXml(parent, markup, beforeNode) {
        var namespace = parent.namespaceURI || 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        var parsed = new DOMParser().parseFromString('<root xmlns="' + namespace + '">' + markup + '</root>', 'application/xml');
        var parserError = parsed.getElementsByTagName('parsererror')[0];
        if (parserError) throw new Error('Invalid export XML markup: ' + parserError.textContent);
        var children = Array.prototype.slice.call(parsed.documentElement.childNodes);
        children.forEach(function (child) {
            var imported = parent.ownerDocument.importNode(child, true);
            parent.insertBefore(imported, beforeNode || null);
        });
    }

    function brandExcel(xlsx) {
        if (!xlsx || !xlsx.xl || !xlsx.xl['styles.xml']) return;
        var styles = xlsx.xl['styles.xml'];
        var sheet = xlsx.xl.worksheets['sheet1.xml'];
        var $styles = $(styles);
        var $fonts = $styles.find('fonts');
        var $fills = $styles.find('fills');
        var $cellXfs = $styles.find('cellXfs');

        // Preserve each built-in number/date format while centering its
        // presentation. DataTables may select different built-in XFs per
        // column, so replacing them with one generic style would lose types.
        $cellXfs.children('xf').each(function () {
            var $format = $(this);
            $format.attr('applyAlignment', '1');
            var $alignment = $format.children('alignment');
            if ($alignment.length) {
                $alignment.attr({ horizontal: 'center', vertical: 'center', wrapText: '1' });
            } else {
                importXml(this, '<alignment horizontal="center" vertical="center" wrapText="1"/>');
            }
        });

        var titleFontId = $fonts.children().length;
        importXml($fonts[0], '<font><i/><sz val="14"/><color rgb="FF' + brand.primary_color.slice(1) + '"/><name val="Calibri"/></font>');
        var headerFontId = $fonts.children().length;
        importXml($fonts[0], '<font><b/><color rgb="FF' + brand.header_text_color.slice(1) + '"/><sz val="11"/><name val="Calibri"/></font>');
        $fonts.attr('count', $fonts.children().length);

        var primaryFillId = $fills.children().length;
        importXml($fills[0], '<fill><patternFill patternType="solid"><fgColor rgb="FF' + brand.primary_color.slice(1) + '"/><bgColor indexed="64"/></patternFill></fill>');
        var accentFillId = $fills.children().length;
        importXml($fills[0], '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2F8"/><bgColor indexed="64"/></patternFill></fill>');
        $fills.attr('count', $fills.children().length);

        var titleStyleId = $cellXfs.children().length;
        importXml($cellXfs[0], '<xf numFmtId="0" fontId="' + titleFontId + '" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right"/></xf>');
        var headerStyleId = $cellXfs.children().length;
        importXml($cellXfs[0], '<xf numFmtId="0" fontId="' + headerFontId + '" fillId="' + primaryFillId + '" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>');
        var stripeStyleId = $cellXfs.children().length;
        importXml($cellXfs[0], '<xf numFmtId="0" fontId="0" fillId="' + accentFillId + '" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>');
        var footerStyleId = $cellXfs.children().length;
        importXml($cellXfs[0], '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>');
        $cellXfs.attr('count', $cellXfs.children().length);

        var $sheet = $(sheet);
        var $rows = $sheet.find('sheetData row');
        var headerRowIndex = -1;
        $rows.each(function (index) {
            var $row = $(this);
            var $cells = $row.find('c');
            if (index === 0) $cells.attr('s', titleStyleId);
            if ($cells.filter('[s="2"]').length) {
                headerRowIndex = index;
                $cells.attr('s', headerStyleId);
            } else if (headerRowIndex >= 0 && (index - headerRowIndex) % 2 === 0) {
                $cells.each(function () {
                    var $cell = $(this);
                    if (!$cell.attr('s') || $cell.attr('s') === '0') $cell.attr('s', stripeStyleId);
                });
            }
        });

        var $lastRow = $sheet.find('sheetData row').last();
        var lastRowNumber = parseInt($lastRow.attr('r') || '1', 10);
        var lastCellReference = $lastRow.find('c').last().attr('r') || 'A' + lastRowNumber;
        var dimensionReference = String($sheet.find('dimension').attr('ref') || '');
        var dimensionLastCell = dimensionReference ? dimensionReference.split(':').pop() : '';
        var lastColumn = columnLetters(dimensionLastCell || lastCellReference);
        var footerRowOne = lastRowNumber + 2;
        var footerRowTwo = lastRowNumber + 3;
        var footerXml = '<row r="' + footerRowOne + '"><c r="A' + footerRowOne + '" s="' + footerStyleId + '" t="inlineStr"><is><t>'
            + escapeXml(brand.footer_line_one) + '</t></is></c></row>'
            + '<row r="' + footerRowTwo + '"><c r="A' + footerRowTwo + '" s="' + footerStyleId + '" t="inlineStr"><is><t>'
            + escapeXml(brand.footer_line_two) + '</t></is></c></row>';
        importXml($sheet.find('sheetData')[0], footerXml);

        var $mergeCells = $sheet.find('mergeCells');
        if (!$mergeCells.length) {
            var sheetDataNode = $sheet.find('sheetData')[0];
            importXml(sheetDataNode.parentNode, '<mergeCells count="0"></mergeCells>', sheetDataNode.nextSibling);
            $mergeCells = $sheet.find('mergeCells');
        }
        importXml($mergeCells[0], '<mergeCell ref="A' + footerRowOne + ':' + lastColumn + footerRowOne + '"/>');
        importXml($mergeCells[0], '<mergeCell ref="A' + footerRowTwo + ':' + lastColumn + footerRowTwo + '"/>');
        $mergeCells.attr('count', $mergeCells.children().length);
        $sheet.find('dimension').attr('ref', 'A1:' + lastColumn + footerRowTwo);

        // Excel files have no fixed screen orientation, but their worksheet
        // print settings do. Apply the same rule as PDF/print and fit all
        // exported columns to one page wide when the workbook is printed.
        var exportedColumnCount = excelColumnCount(lastColumn);
        var excelOrientation = exportedColumnCount >= 7 ? 'landscape' : 'portrait';
        var worksheet = $sheet.find('worksheet')[0];
        var $sheetPr = $sheet.find('sheetPr');
        if (!$sheetPr.length) {
            importXml(worksheet, '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>', worksheet.firstChild);
        } else if (!$sheetPr.find('pageSetUpPr').length) {
            importXml($sheetPr[0], '<pageSetUpPr fitToPage="1"/>');
        } else {
            $sheetPr.find('pageSetUpPr').attr('fitToPage', '1');
        }

        var $pageMargins = $sheet.find('pageMargins');
        if (!$pageMargins.length) {
            var pageMarginsBefore = $sheet.find('pageSetup, headerFooter, rowBreaks, colBreaks, drawing, legacyDrawing, tableParts').first()[0] || null;
            importXml(worksheet, '<pageMargins left="0.25" right="0.25" top="0.45" bottom="0.45" header="0.15" footer="0.15"/>', pageMarginsBefore);
            $pageMargins = $sheet.find('pageMargins');
        } else {
            $pageMargins.attr({ left: '0.25', right: '0.25', top: '0.45', bottom: '0.45', header: '0.15', footer: '0.15' });
        }

        var $pageSetup = $sheet.find('pageSetup');
        if (!$pageSetup.length) {
            var pageSetupBefore = $sheet.find('headerFooter, rowBreaks, colBreaks, drawing, legacyDrawing, tableParts').first()[0] || null;
            importXml(worksheet, '<pageSetup paperSize="9" orientation="' + excelOrientation + '" fitToWidth="1" fitToHeight="0" horizontalDpi="300" verticalDpi="300"/>', pageSetupBefore);
        } else {
            $pageSetup.attr({
                paperSize: '9',
                orientation: excelOrientation,
                fitToWidth: '1',
                fitToHeight: '0',
                horizontalDpi: '300',
                verticalDpi: '300'
            });
        }
    }

    function findPdfTable(doc) {
        for (var i = 0; i < (doc.content || []).length; i++) {
            if (doc.content[i] && doc.content[i].table && doc.content[i].table.body) return doc.content[i];
        }
        return null;
    }

    function pdfCellText(cell) {
        if (cell === null || cell === undefined) return '';
        if (typeof cell === 'string' || typeof cell === 'number') return normalizeText(cell);
        if (Array.isArray(cell)) return normalizeText(cell.map(pdfCellText).join(' '));
        if (typeof cell === 'object') {
            if (cell.text !== undefined) return pdfCellText(cell.text);
            if (cell.stack) return pdfCellText(cell.stack);
        }
        return '';
    }

    function pdfColumnWeight(label) {
        label = normalizeText(label).toLowerCase();
        if (/^(#|no\.?|id)$/.test(label)) return 4;
        if (/(amount|total|balance|count|quantity|transactions)/.test(label)) return 8;
        if (/(date|period|time|gender|status|age)/.test(label)) return 9;
        if (/(crn|srn|phone|contact|reference|number)/.test(label)) return 11;
        if (/(name|member|church|class|organization|department|address|town|location)/.test(label)) return 16;
        if (/(message|description|details|reason|purpose|notes)/.test(label)) return 22;
        return 12;
    }

    function preparePdfTable(table) {
        if (!table || !table.table || !table.table.body || !table.table.body.length) {
            return { columnCount: 0, rowCount: 0, labels: [] };
        }
        var headerRows = Math.max(1, Number(table.table.headerRows || 1));
        var headerRow = table.table.body[Math.min(headerRows - 1, table.table.body.length - 1)] || [];
        var excluded = [];
        headerRow.forEach(function (cell, index) {
            if (/^(action|actions|manage|options)$/i.test(pdfCellText(cell))) excluded.push(index);
        });
        if (excluded.length) {
            table.table.body.forEach(function (row) {
                for (var index = excluded.length - 1; index >= 0; index -= 1) {
                    row.splice(excluded[index], 1);
                }
            });
        }
        headerRow = table.table.body[Math.min(headerRows - 1, table.table.body.length - 1)] || [];
        return {
            columnCount: headerRow.length,
            rowCount: Math.max(0, table.table.body.length - headerRows),
            labels: headerRow.map(pdfCellText),
            headerRows: headerRows
        };
    }

    function brandPdf(doc, config) {
        if (!doc) return;
        var pdfTitle = resolveBaseTitle(config && config.title);
        var filters = activeFilterDetails(config && config._mfExportDataTable);
        var filterSummary = filterTitleSegment(filters) || 'All records within your authorized scope';
        doc.info = Object.assign({}, doc.info || {}, {
            title: pdfTitle,
            subject: filterSummary,
            creator: brand.church_name
        });
        doc.styles = doc.styles || {};
        doc.styles.title = Object.assign({}, doc.styles.title || {}, {
            alignment: 'right', italics: true, color: brand.primary_color, fontSize: 16
        });
        doc.styles.tableHeader = Object.assign({}, doc.styles.tableHeader || {}, {
            bold: true, color: brand.header_text_color, fillColor: brand.primary_color
        });
        var table = findPdfTable(doc);
        var tableMeta = preparePdfTable(table);
        var columnCount = tableMeta.columnCount;
        var rowCount = tableMeta.rowCount;
        doc.pageOrientation = columnCount >= 7 ? 'landscape' : 'portrait';
        doc.pageSize = 'A4';
        doc.pageMargins = [30, 92, 30, 58];
        var pageContentWidth = doc.pageOrientation === 'landscape' ? 781 : 535;
        var fontSize = columnCount >= 12 ? 6 : (columnCount >= 9 ? 7 : (columnCount >= 7 ? 8 : 9));
        var orientationLabel = doc.pageOrientation.charAt(0).toUpperCase() + doc.pageOrientation.slice(1);
        var metaLine = rowCount.toLocaleString() + ' filtered record' + (rowCount === 1 ? '' : 's')
            + '  |  ' + columnCount + ' columns  |  ' + orientationLabel;
        var generatedAt = new Date().toLocaleString();

        doc.defaultStyle = Object.assign({}, doc.defaultStyle || {}, {
            fontSize: fontSize,
            color: '#243746'
        });

        if (table && columnCount > 0) {
            var totalWeight = tableMeta.labels.reduce(function (total, label) {
                return total + pdfColumnWeight(label);
            }, 0) || 1;
            var usableTableWidth = Math.max(120, pageContentWidth - (columnCount * 8));
            table.table.widths = tableMeta.labels.map(function (label) {
                return Math.max(18, Math.floor((pdfColumnWeight(label) / totalWeight) * usableTableWidth));
            });
            table.table.keepWithHeaderRows = 1;
            table.layout = {
                hLineWidth: function () { return 0.55; },
                vLineWidth: function () { return 0.55; },
                hLineColor: function () { return '#C8D2D9'; },
                vLineColor: function () { return '#C8D2D9'; },
                paddingLeft: function () { return columnCount >= 9 ? 3 : 4; },
                paddingRight: function () { return columnCount >= 9 ? 3 : 4; },
                paddingTop: function () { return columnCount >= 9 ? 3 : 4; },
                paddingBottom: function () { return columnCount >= 9 ? 3 : 4; }
            };
            table.table.body.forEach(function (row, rowIndex) {
                row.forEach(function (rawCell, cellIndex) {
                    var cell = rawCell && typeof rawCell === 'object' && !Array.isArray(rawCell)
                        ? rawCell
                        : { text: rawCell === null || rawCell === undefined ? '' : String(rawCell) };
                    if (cell !== rawCell) row[cellIndex] = cell;
                    if (rowIndex < tableMeta.headerRows) {
                        cell.fillColor = brand.primary_color;
                        cell.color = brand.header_text_color;
                        cell.bold = true;
                        cell.fontSize = Math.max(7, fontSize);
                    } else if ((rowIndex - tableMeta.headerRows) % 2 === 1 && !cell.fillColor) {
                        cell.fillColor = '#F3F7F9';
                    }
                    cell.alignment = 'center';
                    cell.noWrap = false;
                });
            });
        }

        // DataTables adds its own standalone title. The branded repeating
        // header below carries the same title and avoids a duplicate heading.
        doc.content = (doc.content || []).filter(function (item) {
            return !(item && item.style === 'title' && !item.table);
        });

        var filterPanel = {
            table: {
                widths: ['*'],
                body: [[{
                    text: [
                        { text: 'Active filters: ', bold: true, color: brand.primary_color },
                        { text: filterSummary, color: '#425866' }
                    ],
                    fillColor: '#F7F9FA',
                    margin: [7, 5, 7, 5]
                }]]
            },
            layout: {
                hLineWidth: function () { return 0.6; },
                vLineWidth: function (index) { return index === 0 ? 2.5 : 0.6; },
                hLineColor: function () { return '#D9E1E7'; },
                vLineColor: function (index) { return index === 0 ? brand.accent_color : '#D9E1E7'; },
                paddingLeft: function () { return 0; },
                paddingRight: function () { return 0; },
                paddingTop: function () { return 0; },
                paddingBottom: function () { return 0; }
            },
            margin: [0, 0, 0, 9]
        };
        doc.content.unshift(filterPanel);

        var titleFontSize = pdfTitle.length > 140 ? 9.5 : (pdfTitle.length > 90 ? 11 : 15);
        doc.header = function () {
            var brandIdentity = brand.logo_data_uri
                ? {
                    columns: [
                        { image: brand.logo_data_uri, width: 38, margin: [0, 0, 8, 0] },
                        { stack: [
                            { text: brand.church_name, color: brand.primary_color, bold: true, fontSize: 10 },
                            { text: 'Generated ' + generatedAt, color: '#60727F', fontSize: 6.5, margin: [0, 3, 0, 0] }
                        ], width: '*' }
                    ],
                    width: '48%'
                }
                : {
                    stack: [
                        { text: brand.church_name, color: brand.primary_color, bold: true, fontSize: 10 },
                        { text: 'Generated ' + generatedAt, color: '#60727F', fontSize: 6.5, margin: [0, 3, 0, 0] }
                    ],
                    width: '48%'
                };
            return {
                stack: [
                    {
                        columns: [
                            brandIdentity,
                            {
                                width: '52%',
                                stack: [
                                    { text: pdfTitle, alignment: 'right', italics: true, bold: true, color: brand.primary_color, fontSize: titleFontSize },
                                    { text: metaLine, alignment: 'right', color: '#60727F', fontSize: 6.5, margin: [0, 4, 0, 0] }
                                ]
                            }
                        ]
                    },
                    { canvas: [{ type: 'line', x1: 0, y1: 0, x2: pageContentWidth, y2: 0, lineWidth: 2, lineColor: brand.accent_color }], margin: [0, 7, 0, 0] }
                ],
                margin: [30, 13, 30, 0]
            };
        };

        doc.footer = function (currentPage, pageCount) {
            return {
                stack: [
                    { canvas: [{ type: 'line', x1: 0, y1: 0, x2: pageContentWidth, y2: 0, lineWidth: 0.5, lineColor: '#D9E1E7' }], margin: [0, 0, 0, 5] },
                    { text: brand.footer_line_one, bold: true },
                    { text: brand.footer_line_two + '  |  Page ' + currentPage + ' of ' + pageCount, margin: [0, 2, 0, 0] }
                ],
                alignment: 'center', color: brand.accent_color, fontSize: 7, margin: [30, 7, 30, 0]
            };
        };
    }

    function brandPrint(printWindow) {
        if (!printWindow || !printWindow.document) return;
        var doc = printWindow.document;
        if (doc.querySelector('.mf-export-brand-header')) return;
        var style = doc.createElement('style');
        style.textContent = '.mf-export-brand-header{display:flex;align-items:center;border-bottom:3px solid ' + brand.accent_color + ';padding:0 0 10px;margin:0 0 15px}'
            + '.mf-export-brand-header img{max-height:55px;max-width:75px;margin-right:12px}.mf-export-brand-header strong{color:' + brand.primary_color + '}'
            + 'h1{text-align:right!important;font-style:italic!important;color:' + brand.primary_color + '!important}'
            + 'table thead th{background:' + brand.primary_color + '!important;color:' + brand.header_text_color + '!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            + 'table tbody tr:nth-child(even) td{background:#F3F7F9!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            + '.mf-export-brand-footer{text-align:center;color:' + brand.accent_color + ';font-size:10px;margin-top:18px;line-height:1.5}';
        doc.head.appendChild(style);
        var header = doc.createElement('div');
        header.className = 'mf-export-brand-header';
        var printLogo = brand.logo_data_uri || '';
        header.innerHTML = (printLogo ? '<img src="' + printLogo + '" alt="Church logo">' : '')
            + '<strong>' + $('<div>').text(brand.church_name).html() + '</strong>';
        doc.body.insertBefore(header, doc.body.firstChild);
        var footer = doc.createElement('div');
        footer.className = 'mf-export-brand-footer';
        footer.innerHTML = '<strong>' + $('<div>').text(brand.footer_line_one).html() + '</strong><br>'
            + $('<div>').text(brand.footer_line_two).html();
        doc.body.appendChild(footer);
    }

    function installExportIdentity(buttons, buttonName) {
        var definition = buttons && buttons[buttonName];
        if (!definition || typeof definition.action !== 'function' || definition._mfExportIdentityInstalled) return;
        var originalAction = definition.action;
        definition._mfExportIdentityInstalled = true;
        definition.action = function (event, dataTableApi, node, config) {
            applyExportIdentity(config, dataTableApi);
            return originalAction.call(this, event, dataTableApi, node, config);
        };
    }

    function installDataTablesDefaults() {
        if (!$ || !$.fn || !$.fn.dataTable || !$.fn.dataTable.ext || !$.fn.dataTable.ext.buttons) return false;
        var buttons = $.fn.dataTable.ext.buttons;
        if (buttons.excelHtml5) buttons.excelHtml5.customize = brandExcel;
        if (buttons.pdfHtml5) buttons.pdfHtml5.customize = brandPdf;
        if (buttons.print) buttons.print.customize = brandPrint;
        installExportIdentity(buttons, 'csvHtml5');
        installExportIdentity(buttons, 'excelHtml5');
        installExportIdentity(buttons, 'pdfHtml5');
        installExportIdentity(buttons, 'print');
        return true;
    }

    window.MyFreemanExportBranding = {
        brand: brand,
        brandExcel: brandExcel,
        brandPdf: brandPdf,
        brandPrint: brandPrint,
        activeFilterDetails: activeFilterDetails,
        filterTitleSegment: filterTitleSegment,
        descriptiveTitle: descriptiveTitle,
        exportFilename: exportFilename,
        applyExportIdentity: applyExportIdentity,
        installDataTablesDefaults: installDataTablesDefaults
    };
    installDataTablesDefaults();
})(window, window.jQuery);
