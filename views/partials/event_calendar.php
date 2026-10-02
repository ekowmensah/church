<div class="card border-0 shadow-sm h-100">
  <div class="card-header bg-white border-0 pb-0">
    <div class="d-flex align-items-center justify-content-between">
      <h5 class="mb-0 font-weight-bold text-dark">
        <i class="fas fa-calendar-alt mr-2 text-primary"></i>
        Events Calendar
      </h5>
      <div class="d-flex align-items-center">
        <div id="calendarLoading" class="spinner-border spinner-border-sm text-primary mr-2" role="status" style="display: none;">
          <span class="sr-only">Loading...</span>
        </div>
        <small class="text-muted">Interactive View</small>
      </div>
    </div>
  </div>
  <div class="card-body pt-3">
    <div id="eventsCalendar" style="min-height: 450px; background: #fff; border-radius: 8px;"></div>
    <div id="calendarError" class="alert alert-warning mt-3 mb-0 text-center" style="display:none">
      <i class="fas fa-exclamation-triangle mr-2"></i>
      <span id="errorMessage">Failed to load events. Please try again later.</span>
      <button class="btn btn-sm btn-outline-warning ml-2" onclick="location.reload()">
        <i class="fas fa-redo mr-1"></i>Retry
      </button>
    </div>
    <div id="calendarEmpty" class="text-center py-4" style="display:none">
      <div class="mb-3">
        <i class="fas fa-calendar-times text-muted" style="font-size: 3rem;"></i>
      </div>
      <h6 class="text-muted mb-2">No Events Found</h6>
      <p class="text-muted small mb-0">There are no events scheduled for the selected period.</p>
    </div>
  </div>
</div>
<!-- FullCalendar CSS and JS from CDN for better compatibility -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

<script>
// Use document ready instead of jQuery shorthand to avoid conflicts
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('eventsCalendar');
    var errorEl = document.getElementById('calendarError');
    var loadingEl = document.getElementById('calendarLoading');
    var emptyEl = document.getElementById('calendarEmpty');
    var events = <?= json_encode(
        $calendar_events ?? [],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    ) ?>;
    
    if (!calendarEl) return;

    if (loadingEl) loadingEl.style.display = 'none';
    if (events.length === 0) {
        if (emptyEl) emptyEl.style.display = 'block';
        return;
    }

    try {
        if (typeof FullCalendar === 'undefined') {
            throw new Error('The calendar library did not load.');
        }

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                initialDate: <?= json_encode(date('Y-m-d')) ?>,
                height: 'auto',
                events: events,
                
                // Enhanced event styling
                eventDisplay: 'block',
                dayMaxEvents: 3,
                moreLinkClick: 'popover',
                
                // Event interaction
                eventClick: function(info) {
                    if (info.event.url) {
                        info.jsEvent.preventDefault();
                        window.location.href = info.event.url;
                    }
                },
                
                // Enhanced event rendering
                eventContent: function(arg) {
                    var event = arg.event;
                    var photo = event.extendedProps.photo_url;
                    var title = event.title;
                    var location = event.extendedProps.location;
                    var isRegistered = event.extendedProps.is_registered;
                    
                    var wrapper = document.createElement('div');
                    wrapper.className = 'fc-event-content-wrapper';

                    if (photo) {
                        var photoWrapper = document.createElement('div');
                        photoWrapper.className = 'fc-event-photo mb-1 text-center';
                        var image = document.createElement('img');
                        image.src = photo;
                        image.alt = '';
                        image.width = 24;
                        image.height = 24;
                        image.style.objectFit = 'cover';
                        image.style.borderRadius = '4px';
                        photoWrapper.appendChild(image);
                        wrapper.appendChild(photoWrapper);
                    }

                    var titleElement = document.createElement('div');
                    titleElement.className = 'fc-event-title font-weight-bold';
                    titleElement.style.fontSize = '0.85em';
                    titleElement.textContent = title;
                    wrapper.appendChild(titleElement);

                    if (location) {
                        var locationElement = document.createElement('div');
                        locationElement.className = 'fc-event-location';
                        locationElement.style.fontSize = '0.75em';
                        locationElement.textContent = '\uD83D\uDCCD ' + location;
                        wrapper.appendChild(locationElement);
                    }

                    if (isRegistered) {
                        var statusElement = document.createElement('span');
                        statusElement.className = 'badge badge-success badge-sm mt-1';
                        statusElement.textContent = '\u2713 Registered';
                        wrapper.appendChild(statusElement);
                    }

                    return { domNodes: [wrapper] };
                },
                
                // Responsive toolbar
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: window.innerWidth < 768 ? 'dayGridMonth' : 'dayGridMonth,timeGridWeek,listWeek'
                },
                
                // Calendar styling
                nowIndicator: true,
                weekNumbers: false,
                navLinks: true,
                selectable: false,
                selectMirror: true,
                
                // Responsive behavior
                aspectRatio: window.innerWidth < 768 ? 1.0 : 1.35,
                
                // Custom styling for events
                eventClassNames: function(arg) {
                    var classes = ['custom-event'];
                    if (arg.event.extendedProps.is_registered) {
                        classes.push('registered-event');
                    }
                    return classes;
                },
                
                // Window resize handler
                windowResize: function(view) {
                    var isMobile = window.innerWidth < 768;
                    calendar.setOption('aspectRatio', isMobile ? 1.0 : 1.35);
                    calendar.setOption('headerToolbar', {
                        left: 'prev,next today',
                        center: 'title',
                        right: isMobile ? 'dayGridMonth' : 'dayGridMonth,timeGridWeek,listWeek'
                    });
                },
                
                // Loading states
                loading: function(bool) {
                    if (loadingEl) {
                        loadingEl.style.display = bool ? 'inline-block' : 'none';
                    }
                }
            });
            calendar.render();
    } catch (error) {
        if (loadingEl) loadingEl.style.display = 'none';
        if (errorEl) {
            var errorMessage = document.getElementById('errorMessage');
            if (errorMessage) {
                errorMessage.textContent = error.message
                    ? 'Error loading events: ' + error.message
                    : 'Failed to load events. Please check your connection and try again.';
            }
            errorEl.style.display = 'block';
        }
        console.error('Calendar error:', error);
    }
});
</script>

<style>
/* Enhanced Calendar Styling */
.fc {
    font-family: 'Source Sans Pro', sans-serif;
}

.fc-toolbar {
    margin-bottom: 1rem;
}

.fc-toolbar-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #495057;
}

.fc-button {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border: none;
    border-radius: 6px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.fc-button:hover {
    background: linear-gradient(135deg, #5a67d8, #6b46c1);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.fc-button:focus {
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.25);
}

.fc-button-active {
    background: linear-gradient(135deg, #4c51bf, #553c9a) !important;
}

.fc-daygrid-day {
    transition: background-color 0.2s ease;
}

.fc-daygrid-day:hover {
    background-color: rgba(102, 126, 234, 0.05);
}

.fc-day-today {
    background-color: rgba(102, 126, 234, 0.1) !important;
}

/* Custom Event Styling */
.fc-event.custom-event {
    border: none;
    border-radius: 6px;
    padding: 2px 6px;
    margin: 1px 0;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.fc-event.custom-event:hover {
    background: linear-gradient(135deg, #5a67d8, #6b46c1);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.fc-event.registered-event {
    background: linear-gradient(135deg, #48bb78, #38a169) !important;
    border-left: 4px solid #22543d;
}

.fc-event.registered-event:hover {
    background: linear-gradient(135deg, #38a169, #2f855a) !important;
    box-shadow: 0 4px 12px rgba(72, 187, 120, 0.3);
}

.fc-event-content-wrapper {
    padding: 2px;
}

.fc-event-title {
    font-weight: 600;
    line-height: 1.2;
}

.fc-event-location {
    opacity: 0.9;
    line-height: 1.1;
}

.fc-event-status .badge {
    font-size: 0.6rem;
    padding: 2px 4px;
}

/* More Link Styling */
.fc-more-link {
    color: #667eea;
    font-weight: 600;
    text-decoration: none;
}

.fc-more-link:hover {
    color: #5a67d8;
    text-decoration: underline;
}

/* Popover Styling */
.fc-popover {
    border: none;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}

.fc-popover-header {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border-radius: 8px 8px 0 0;
    font-weight: 600;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .fc-toolbar {
        flex-direction: column;
        gap: 10px;
    }
    
    .fc-toolbar-chunk {
        display: flex;
        justify-content: center;
    }
    
    .fc-toolbar-title {
        font-size: 1.25rem;
        margin: 0;
    }
    
    .fc-button {
        padding: 0.375rem 0.75rem;
        font-size: 0.875rem;
    }
    
    .fc-event {
        font-size: 0.75rem;
    }
    
    .fc-daygrid-event {
        margin: 1px 2px;
    }
}

/* Loading Animation */
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.fc-event.loading {
    animation: pulse 1.5s infinite;
}

/* Event Type Colors */
.fc-event[data-event-type="worship"] {
    background: linear-gradient(135deg, #667eea, #764ba2);
}

.fc-event[data-event-type="fellowship"] {
    background: linear-gradient(135deg, #f093fb, #f5576c);
}

.fc-event[data-event-type="service"] {
    background: linear-gradient(135deg, #4facfe, #00f2fe);
}

.fc-event[data-event-type="meeting"] {
    background: linear-gradient(135deg, #43e97b, #38f9d7);
}

.fc-event[data-event-type="special"] {
    background: linear-gradient(135deg, #fa709a, #fee140);
}
</style>
