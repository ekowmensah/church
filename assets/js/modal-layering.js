(function ($, window, document) {
    'use strict';

    if (!$ || !$.fn || !$.fn.modal) {
        return;
    }

    var baseModalZIndex = 2100;
    var layerStep = 20;

    function visibleModalCount(excludedModal) {
        return $('body > .modal.show').filter(function () {
            return this !== excludedModal;
        }).length;
    }

    function positionLatestBackdrop(modalZIndex) {
        window.setTimeout(function () {
            $('body > .modal-backdrop').last().css('z-index', modalZIndex - 10);
        }, 0);
    }

    $(document).on('show.bs.modal.mfModalLayering', '.modal', function () {
        var modal = this;
        var $modal = $(modal);

        // A modal inside .content-wrapper inherits that container's z-index
        // and can appear below the fixed header. Body ownership avoids that
        // stacking context for every current and future page.
        if (modal.parentNode !== document.body) {
            $modal.appendTo(document.body);
        }

        var modalZIndex = baseModalZIndex + (visibleModalCount(modal) * layerStep);
        $modal.css('z-index', modalZIndex).attr('data-modal-layer', modalZIndex);
        positionLatestBackdrop(modalZIndex);
    });

    $(document).on('shown.bs.modal.mfModalLayering', '.modal', function () {
        var modalZIndex = Number($(this).attr('data-modal-layer')) || baseModalZIndex;
        positionLatestBackdrop(modalZIndex);
    });

    $(document).on('hidden.bs.modal.mfModalLayering', '.modal', function () {
        $(this).css('z-index', '').removeAttr('data-modal-layer');
        if ($('body > .modal.show').length > 0) {
            $('body').addClass('modal-open');
        }
    });
})(window.jQuery, window, document);
