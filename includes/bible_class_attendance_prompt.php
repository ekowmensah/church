<?php
$attendanceQueue = isset($bible_class_attendance_queue) && is_array($bible_class_attendance_queue)
    ? $bible_class_attendance_queue
    : [
        'date' => date('Y-m-d'),
        'page' => 1,
        'per_page' => 12,
        'total' => count((array) ($due_bible_class_attendance ?? [])),
        'total_pages' => 1,
        'sessions' => (array) ($due_bible_class_attendance ?? []),
    ];

if ((int) ($attendanceQueue['total'] ?? 0) < 1) {
    return;
}

$attendanceQueue['sessions'] = array_values((array) ($attendanceQueue['sessions'] ?? []));
$attendanceQueueEndpoint = BASE_URL . '/views/ajax_bible_class_attendance_queue.php';
$attendanceWorkspaceBase = BASE_URL . '/views/my_bible_class_attendance.php';
?>
<style>
body.bible-class-attendance-open .modal-backdrop { z-index: 20050 !important; }
.bible-class-attendance-modal { z-index: 20060 !important; }
.bible-class-attendance-modal .modal-dialog { max-width: 980px; }
.bible-class-attendance-modal .modal-content {
    border: 0;
    border-radius: 22px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .34);
    overflow: hidden;
}
.attendance-queue-toolbar { background: #f8fafc; }
.attendance-queue-search { position: relative; }
.attendance-queue-search .fas {
    position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
    color: #64748b; pointer-events: none;
}
.attendance-queue-search input {
    min-height: 46px; padding-left: 42px; border-radius: 12px;
    border-color: #cbd5e1;
}
.attendance-queue-list { max-height: 54vh; overflow-y: auto; background: #fff; }
.attendance-queue-item {
    display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 18px;
    align-items: center; padding: 16px 24px; border-bottom: 1px solid #e2e8f0;
    color: inherit; transition: background-color .15s ease;
}
.attendance-queue-item:hover, .attendance-queue-item:focus {
    background: #f0fdfa; color: inherit; text-decoration: none; outline: 0;
}
.attendance-queue-class { font-weight: 750; color: #0f172a; overflow-wrap: anywhere; }
.attendance-queue-meta { color: #64748b; font-size: .86rem; }
.attendance-queue-group {
    display: inline-flex; align-items: center; border-radius: 999px;
    padding: 3px 9px; margin-right: 6px; background: #e0f2fe;
    color: #075985; font-size: .75rem; font-weight: 700;
}
.attendance-queue-empty { padding: 52px 24px; text-align: center; color: #64748b; }
.attendance-queue-loading { opacity: .48; pointer-events: none; }
.attendance-queue-pagination .btn { min-width: 94px; }
@media (max-width: 575.98px) {
    .bible-class-attendance-modal .modal-dialog { margin: .5rem; }
    .attendance-queue-item { grid-template-columns: 1fr; gap: 10px; padding: 15px 18px; }
    .attendance-queue-item .btn { width: 100%; }
    .attendance-queue-list { max-height: 58vh; }
}
</style>
<div class="modal fade bible-class-attendance-modal" id="bibleClassAttendancePrompt" tabindex="-1" role="dialog" aria-labelledby="bibleClassAttendancePromptTitle" aria-describedby="bibleClassAttendancePromptHelp" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header text-white border-0" style="background:linear-gradient(135deg,#0f766e,#2563eb);">
                <div class="pr-3">
                    <div class="small text-uppercase" style="letter-spacing:.08em;opacity:.8;">Today&rsquo;s attendance work queue</div>
                    <h5 class="modal-title" id="bibleClassAttendancePromptTitle"><i class="fas fa-clipboard-check mr-2"></i>Bible Class attendance</h5>
                    <div class="small mt-1" style="opacity:.86;"><span id="attendanceQueueTotal"><?= (int) $attendanceQueue['total'] ?></span> <span id="attendanceQueueNoun">class<?= (int) $attendanceQueue['total'] === 1 ? '' : 'es' ?></span> awaiting attendance</div>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body p-0">
                <div class="attendance-queue-toolbar px-3 px-md-4 py-3 border-bottom">
                    <p id="bibleClassAttendancePromptHelp" class="small text-muted mb-2">Search by Bible Class, Class Group, or class code. Only classes assigned to you—and still awaiting today&rsquo;s attendance—are shown.</p>
                    <div class="attendance-queue-search">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <label class="sr-only" for="attendanceQueueSearch">Search attendance classes</label>
                        <input type="search" class="form-control" id="attendanceQueueSearch" maxlength="100" autocomplete="off" placeholder="Search classes or groups&hellip;">
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center mt-2 small text-muted" aria-live="polite" aria-atomic="true">
                        <span id="attendanceQueueRange"></span>
                        <span id="attendanceQueueStatus">Select a class to begin marking.</span>
                    </div>
                </div>

                <div class="attendance-queue-list" id="attendanceQueueList" role="list" aria-busy="false">
                    <?php foreach ($attendanceQueue['sessions'] as $dueSession): ?>
                        <a role="listitem" class="attendance-queue-item"
                           href="<?= htmlspecialchars($attendanceWorkspaceBase) ?>?class_id=<?= (int) $dueSession['class_id'] ?>&amp;session_id=<?= (int) $dueSession['session_id'] ?>">
                            <span>
                                <span class="attendance-queue-class d-block"><?= htmlspecialchars((string) $dueSession['class_name']) ?></span>
                                <span class="attendance-queue-meta d-block mt-1"><span class="attendance-queue-group"><?= htmlspecialchars((string) $dueSession['group_name']) ?></span><?= htmlspecialchars((string) ($dueSession['attendance_date_label'] ?? date('j M Y', strtotime((string) $dueSession['attendance_date'])))) ?></span>
                            </span>
                            <span class="btn btn-sm btn-primary text-nowrap">Mark attendance <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="attendance-queue-pagination d-flex justify-content-between align-items-center px-3 px-md-4 py-3 border-top bg-light">
                    <button type="button" class="btn btn-outline-secondary" id="attendanceQueuePrevious"><i class="fas fa-chevron-left mr-1" aria-hidden="true"></i> Previous</button>
                    <span class="small font-weight-bold text-muted" id="attendanceQueuePage"></span>
                    <button type="button" class="btn btn-outline-primary" id="attendanceQueueNext">Next <i class="fas fa-chevron-right ml-1" aria-hidden="true"></i></button>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Remind me on the next dashboard visit</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var initialQueue = <?= json_encode($attendanceQueue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var endpoint = <?= json_encode($attendanceQueueEndpoint, JSON_UNESCAPED_SLASHES) ?>;
    var workspace = <?= json_encode($attendanceWorkspaceBase, JSON_UNESCAPED_SLASHES) ?>;
    var queue = initialQueue;
    var searchTimer = null;
    var requestSequence = 0;

    function textElement(tag, className, value) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        element.textContent = value;
        return element;
    }

    function renderQueue(nextQueue) {
        queue = nextQueue;
        var list = document.getElementById('attendanceQueueList');
        var total = Number(queue.total || 0);
        var page = Number(queue.page || 1);
        var perPage = Number(queue.per_page || 12);
        var totalPages = Number(queue.total_pages || 1);
        var sessions = Array.isArray(queue.sessions) ? queue.sessions : [];
        list.textContent = '';

        if (!sessions.length) {
            var empty = document.createElement('div');
            empty.className = 'attendance-queue-empty';
            var emptyIcon = document.createElement('i');
            emptyIcon.className = 'fas fa-search fa-2x mb-3 d-block';
            emptyIcon.setAttribute('aria-hidden', 'true');
            empty.appendChild(emptyIcon);
            empty.appendChild(textElement('strong', 'd-block text-dark mb-1', total ? 'No classes on this page' : 'No matching attendance work'));
            empty.appendChild(textElement('span', 'small', total ? 'Use the page controls to continue.' : 'Try another class or group name.'));
            list.appendChild(empty);
        } else {
            sessions.forEach(function (session) {
                var link = document.createElement('a');
                link.className = 'attendance-queue-item';
                link.setAttribute('role', 'listitem');
                link.href = workspace + '?class_id=' + encodeURIComponent(session.class_id) + '&session_id=' + encodeURIComponent(session.session_id);

                var identity = document.createElement('span');
                identity.appendChild(textElement('span', 'attendance-queue-class d-block', String(session.class_name || 'Bible Class')));
                var meta = document.createElement('span');
                meta.className = 'attendance-queue-meta d-block mt-1';
                meta.appendChild(textElement('span', 'attendance-queue-group', String(session.group_name || 'Class Group')));
                meta.appendChild(document.createTextNode(String(session.attendance_date_label || session.attendance_date || '')));
                identity.appendChild(meta);

                var action = textElement('span', 'btn btn-sm btn-primary text-nowrap', 'Mark attendance ');
                var arrow = document.createElement('i');
                arrow.className = 'fas fa-arrow-right ml-1';
                arrow.setAttribute('aria-hidden', 'true');
                action.appendChild(arrow);
                link.appendChild(identity);
                link.appendChild(action);
                list.appendChild(link);
            });
        }

        document.getElementById('attendanceQueueTotal').textContent = String(total);
        document.getElementById('attendanceQueueNoun').textContent = total === 1 ? 'class' : 'classes';
        document.getElementById('attendanceQueuePage').textContent = total ? 'Page ' + page + ' of ' + totalPages : 'No results';
        document.getElementById('attendanceQueuePrevious').disabled = page <= 1;
        document.getElementById('attendanceQueueNext').disabled = page >= totalPages || total === 0;
        var first = total ? ((page - 1) * perPage) + 1 : 0;
        var last = total ? Math.min(page * perPage, total) : 0;
        document.getElementById('attendanceQueueRange').textContent = total ? 'Showing ' + first + '–' + last + ' of ' + total : '0 classes found';
    }

    function setLoading(loading, message) {
        var list = document.getElementById('attendanceQueueList');
        list.classList.toggle('attendance-queue-loading', loading);
        list.setAttribute('aria-busy', loading ? 'true' : 'false');
        document.getElementById('attendanceQueueStatus').textContent = message || (loading ? 'Loading attendance work…' : 'Select a class to begin marking.');
    }

    function loadQueue(page) {
        var sequence = ++requestSequence;
        var query = document.getElementById('attendanceQueueSearch').value.trim();
        var url = endpoint + '?page=' + encodeURIComponent(page) + '&q=' + encodeURIComponent(query);
        setLoading(true);
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.text().then(function (rawBody) {
                    var body;
                    try {
                        body = JSON.parse(rawBody);
                    } catch (parseError) {
                        var returnedHtml = response.redirected || /<!doctype\s+html|<html[\s>]/i.test(rawBody);
                        throw new Error(returnedHtml
                            ? 'The attendance session returned a web page instead of data. Refresh the dashboard and sign in again.'
                            : 'The attendance session returned an invalid response. Refresh the dashboard and try again.');
                    }
                    if (!response.ok || !body || !body.ok) {
                        throw new Error((body && body.message) || 'Unable to load attendance work.');
                    }
                    return body.queue;
                });
            })
            .then(function (nextQueue) {
                if (sequence !== requestSequence) return;
                renderQueue(nextQueue);
                setLoading(false);
            })
            .catch(function (error) {
                if (sequence !== requestSequence) return;
                setLoading(false, error.message || 'Unable to load attendance work.');
            });
    }

    function openBibleClassAttendancePrompt() {
        if (!window.jQuery || typeof window.jQuery.fn.modal !== 'function') {
            window.setTimeout(openBibleClassAttendancePrompt, 100);
            return;
        }
        var $prompt = window.jQuery('#bibleClassAttendancePrompt');
        if (!$prompt.length) return;
        $prompt.appendTo(document.body);
        $prompt
            .off('.bibleClassAttendancePrompt')
            .on('show.bs.modal.bibleClassAttendancePrompt', function () {
                document.body.classList.add('bible-class-attendance-open');
            })
            .on('shown.bs.modal.bibleClassAttendancePrompt', function () {
                document.getElementById('attendanceQueueSearch').focus();
            })
            .on('hidden.bs.modal.bibleClassAttendancePrompt', function () {
                document.body.classList.remove('bible-class-attendance-open');
            })
            .modal('show');
    }

    function initializeQueue() {
        renderQueue(initialQueue);
        document.getElementById('attendanceQueuePrevious').addEventListener('click', function () {
            if (Number(queue.page || 1) > 1) loadQueue(Number(queue.page) - 1);
        });
        document.getElementById('attendanceQueueNext').addEventListener('click', function () {
            if (Number(queue.page || 1) < Number(queue.total_pages || 1)) loadQueue(Number(queue.page) + 1);
        });
        document.getElementById('attendanceQueueSearch').addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () { loadQueue(1); }, 300);
        });

        var welcome = document.getElementById('loginSuccessModal');
        var continueButton = document.getElementById('loginSuccessOk');
        if (welcome && continueButton && window.getComputedStyle(welcome).display !== 'none') {
            continueButton.addEventListener('click', function () { window.setTimeout(openBibleClassAttendancePrompt, 80); }, { once: true });
            return;
        }
        window.setTimeout(openBibleClassAttendancePrompt, 80);
    }

    document.addEventListener('DOMContentLoaded', initializeQueue);
})();
</script>
