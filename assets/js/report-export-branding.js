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
        importXml($cellXfs[0], '<xf numFmtId="0" fontId="' + headerFontId + '" fillId="' + primaryFillId + '" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>');
        var stripeStyleId = $cellXfs.children().length;
        importXml($cellXfs[0], '<xf numFmtId="0" fontId="0" fillId="' + accentFillId + '" borderId="1" xfId="0" applyFill="1" applyBorder="1"></xf>');
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
        var lastColumn = columnLetters(lastCellReference);
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
    }

    function findPdfTable(doc) {
        for (var i = 0; i < (doc.content || []).length; i++) {
            if (doc.content[i] && doc.content[i].table && doc.content[i].table.body) return doc.content[i];
        }
        return null;
    }

    function brandPdf(doc) {
        if (!doc) return;
        doc.pageMargins = [30, 70, 30, 55];
        doc.styles = doc.styles || {};
        doc.styles.title = Object.assign({}, doc.styles.title || {}, {
            alignment: 'right', italics: true, color: brand.primary_color, fontSize: 16
        });
        doc.styles.tableHeader = Object.assign({}, doc.styles.tableHeader || {}, {
            bold: true, color: brand.header_text_color, fillColor: brand.primary_color
        });
        var table = findPdfTable(doc);
        if (table) {
            var headerRows = (table.table.headerRows || 1);
            table.table.body.forEach(function (row, rowIndex) {
                row.forEach(function (cell) {
                    if (rowIndex < headerRows) {
                        cell.fillColor = brand.primary_color;
                        cell.color = brand.header_text_color;
                        cell.bold = true;
                    } else if ((rowIndex - headerRows) % 2 === 1) {
                        cell.fillColor = '#F3F7F9';
                    }
                });
            });
        }
        doc.header = function () {
            var columns = [];
            if (brand.logo_data_uri) columns.push({ image: brand.logo_data_uri, width: 38 });
            columns.push({ text: brand.church_name, color: brand.primary_color, bold: true, margin: [8, 10, 0, 0] });
            return { columns: columns, margin: [30, 15, 30, 0] };
        };
        doc.footer = function (currentPage, pageCount) {
            return {
                stack: [
                    { text: brand.footer_line_one, bold: true },
                    { text: brand.footer_line_two + '  |  Page ' + currentPage + ' of ' + pageCount }
                ],
                alignment: 'center', color: brand.accent_color, fontSize: 8, margin: [20, 8, 20, 0]
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
        header.innerHTML = (brand.logo_url ? '<img src="' + brand.logo_url + '" alt="Church logo">' : '')
            + '<strong>' + $('<div>').text(brand.church_name).html() + '</strong>';
        doc.body.insertBefore(header, doc.body.firstChild);
        var footer = doc.createElement('div');
        footer.className = 'mf-export-brand-footer';
        footer.innerHTML = '<strong>' + $('<div>').text(brand.footer_line_one).html() + '</strong><br>'
            + $('<div>').text(brand.footer_line_two).html();
        doc.body.appendChild(footer);
    }

    function installDataTablesDefaults() {
        if (!$ || !$.fn || !$.fn.dataTable || !$.fn.dataTable.ext || !$.fn.dataTable.ext.buttons) return false;
        var buttons = $.fn.dataTable.ext.buttons;
        if (buttons.excelHtml5) buttons.excelHtml5.customize = brandExcel;
        if (buttons.pdfHtml5) buttons.pdfHtml5.customize = brandPdf;
        if (buttons.print) buttons.print.customize = brandPrint;
        return true;
    }

    window.MyFreemanExportBranding = {
        brand: brand,
        brandExcel: brandExcel,
        brandPdf: brandPdf,
        brandPrint: brandPrint,
        installDataTablesDefaults: installDataTablesDefaults
    };
    installDataTablesDefaults();
})(window, window.jQuery);
