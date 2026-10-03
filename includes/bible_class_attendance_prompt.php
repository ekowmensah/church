<?php
if (empty($due_bible_class_attendance) || !is_array($due_bible_class_attendance)) {
    return;
}
?>
<style>
body.bible-class-attendance-open .modal-backdrop {
    z-index: 20050 !important;
}
.bible-class-attendance-modal {
    z-index: 20060 !important;
}
.bible-class-attendance-modal .modal-content {
    border: 0;
    border-radius: 20px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .34);
    overflow: hidden;
}
</style>
<div class="modal fade bible-class-attendance-modal" id="bibleClassAttendancePrompt" tabindex="-1" role="dialog" aria-labelledby="bibleClassAttendancePromptTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header text-white border-0" style="background:linear-gradient(135deg,#0f766e,#2563eb);">
                <div>
                    <div class="small text-uppercase" style="letter-spacing:.08em;opacity:.8;">Scheduled for today</div>
                    <h5 class="modal-title" id="bibleClassAttendancePromptTitle"><i class="fas fa-clipboard-check mr-2"></i>Bible Class attendance is ready</h5>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body p-0">
                <div class="px-4 py-3 bg-light border-bottom text-muted">
                    Sessions were created from each Class Group's configured meeting day. Select a class to mark attendance.
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($due_bible_class_attendance as $dueSession): ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center px-4 py-3"
                           href="<?= BASE_URL ?>/views/my_bible_class_attendance.php?class_id=<?= (int) $dueSession['class_id'] ?>&amp;session_id=<?= (int) $dueSession['session_id'] ?>">
                            <span>
                                <strong class="d-block text-dark"><?= htmlspecialchars((string) $dueSession['class_name']) ?></strong>
                                <small class="text-muted"><?= htmlspecialchars((string) $dueSession['group_name']) ?> &middot; <?= htmlspecialchars(date('j M Y', strtotime((string) $dueSession['attendance_date']))) ?></small>
                            </span>
                            <span class="btn btn-sm btn-primary ml-3 text-nowrap">Mark now <i class="fas fa-arrow-right ml-1"></i></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Remind me on the next dashboard visit</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    function openBibleClassAttendancePrompt() {
        if (!window.jQuery || typeof window.jQuery.fn.modal !== 'function') {
            window.setTimeout(openBibleClassAttendancePrompt, 100);
            return;
        }

        var $prompt = window.jQuery('#bibleClassAttendancePrompt');
        if (!$prompt.length) return;

        // The layout's content wrapper is a stacking context below Bootstrap's
        // backdrop. Moving the modal to body keeps the dialog above it.
        $prompt.appendTo(document.body);
        $prompt
            .off('.bibleClassAttendancePrompt')
            .on('show.bs.modal.bibleClassAttendancePrompt', function () {
                document.body.classList.add('bible-class-attendance-open');
            })
            .on('hidden.bs.modal.bibleClassAttendancePrompt', function () {
                document.body.classList.remove('bible-class-attendance-open');
            })
            .modal('show');
    }

    function schedulePrompt() {
        window.setTimeout(openBibleClassAttendancePrompt, 80);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var welcome = document.getElementById('loginSuccessModal');
        var continueButton = document.getElementById('loginSuccessOk');
        if (welcome && continueButton && window.getComputedStyle(welcome).display !== 'none') {
            continueButton.addEventListener('click', schedulePrompt, { once: true });
            return;
        }
        schedulePrompt();
    });
})();
</script>
