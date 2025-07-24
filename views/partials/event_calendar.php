<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-calendar-alt mr-2"></i>Church Events Calendar</h6>
  </div>
  <div class="card-body">
    <div id="eventsCalendar" style="min-height:400px; background:#fff; border:2px solid #007bff;"></div>
    <div id="calendarError" class="alert alert-danger mt-3 mb-0 text-center" style="display:none"></div>
  </div>
</div>
<link rel="stylesheet" href="/myfreeman/AdminLTE/plugins/fullcalendar/main.min.css">
<script src="/myfreeman/AdminLTE/plugins/fullcalendar/main.min.js"></script>
<script>
$(function() {
    var calendarEl = document.getElementById('eventsCalendar');
    var errorEl = document.getElementById('calendarError');
    if (!calendarEl) return;
    fetch('ajax_events.php')
        .then(function(response) {
            console.log('[Calendar] Fetched response:', response);
            return response.json();
        })
        .then(function(events) {
            console.log('[Calendar] Received events:', events);
            var debugEl = document.getElementById('calendarDebug');
            if (debugEl) {
                debugEl.style.display = '';
                debugEl.textContent = 'Fetched events: ' + JSON.stringify(events, null, 2);
            }
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                initialDate: new Date().toISOString().slice(0,10),
                height: window.innerWidth < 600 ? 350 : 500,
                events: events,
                eventClick: function(info) {
                    var ev = info.event;
                    if (ev.extendedProps.registration_url) {
                        window.open(ev.extendedProps.registration_url, '_blank');
                    }
                },
                eventContent: function(arg) {
                    var photo = arg.event.extendedProps.photo_url;
                    var title = arg.event.title;
                    var innerHtml = '';
                    if (photo) {
                        innerHtml += '<div style="text-align:center;"><img src="' + photo + '" style="height:32px;width:32px;object-fit:cover;border-radius:5px;margin-bottom:2px;"></div>';
                    }
                    innerHtml += '<div>' + title + '</div>';
                    return { html: innerHtml };
                },
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                nowIndicator: true,
                aspectRatio: window.innerWidth < 600 ? 0.9 : 1.8,
                contentHeight: 'auto',
                windowResize: function(view) {
                    calendar.setOption('height', window.innerWidth < 600 ? 350 : 500);
                    calendar.setOption('aspectRatio', window.innerWidth < 600 ? 0.9 : 1.8);
                }
            });
            calendar.render();
        })
        .catch(function(e) {
            if (errorEl) {
                errorEl.textContent = 'Failed to load events. Please try again later.';
                errorEl.style.display = '';
            }
            if (window.console) console.error('Failed to load events:', e);
        });
});
</script>
