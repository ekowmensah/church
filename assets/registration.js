// registration.js - Handles camera, photo preview, password toggle, dynamic contacts, orgs select, region, baptism/confirmation logic
$(document).ready(function() {
    // 1. Camera/Image Upload
    let stream = null;
    if ($('#camera-btn').length) {
        $('#camera-btn').on('click', function(e) {
            e.preventDefault();
            $('#camera-modal').modal('show');
            if (!stream) {
                navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(s) {
                    stream = s;
                    document.getElementById('camera-video').srcObject = stream;
                });
            }
        });
        $('#capture-btn').on('click', function() {
            let video = document.getElementById('camera-video');
            let canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            let dataUrl = canvas.toDataURL('image/png');
            $('#photo-data').val(dataUrl).trigger('change'); // trigger preview logic
            $('#camera-modal').modal('hide');
            if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        });
        $('#camera-modal').on('hidden.bs.modal', function() {
            if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        });
    }

    // --- PHOTO PREVIEW AND CHANGE PHOTO LOGIC ---
    // Show preview and hide upload section when a file is chosen
    $('#photo').on('change', function(){
        if (this.files && this.files[0]) {
            let reader = new FileReader();
            reader.onload = function(e) {
                $('#photo-preview').attr('src', e.target.result);
                $('#photo-preview-wrap').show();
                $('#photo-upload-section').hide();
                $('#photo-data').val(''); // clear camera
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
    // Show preview and hide upload section when camera photo is captured
    $(document).on('change input', '#photo-data', function(){
        if ($(this).val()) {
            $('#photo-preview').attr('src', $(this).val());
            $('#photo-preview-wrap').show();
            $('#photo-upload-section').hide();
            $('#photo').val('');
        }
    });
    // Reset UI when "Change Photo" is clicked
    $('#photo-upload-group').on('click', '#remove-photo-btn', function(){
        $('#photo-preview').attr('src', '#');
        $('#photo-preview-wrap').hide();
        $('#photo-upload-section').show();
        $('#photo').val('');
        $('#photo-data').val('');
    });

    // 2. Password Preview
    $('.toggle-password').on('click', function() {
        let input = $('#password');
        let icon = $(this);
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    // --- BASE URL DETECTION ---
    function getBaseUrl() {
        if (window.BASE_URL) return window.BASE_URL;
        // Try to infer from script src
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].getAttribute('src');
            if (src && src.match(/\/assets\/registration\.js($|\?)/)) {
                var parts = src.split('/assets/registration.js');
                return parts[0] || '/';
            }
        }
        // Fallback: use path up to /assets/
        var path = window.location.pathname;
        var idx = path.indexOf('/assets/');
        if (idx !== -1) return path.substring(0, idx);
        return '/';
    }
    var BASE = getBaseUrl();

    // 3. Populate Regions (Ghana)
    $.getJSON(BASE + '/assets/regions_ghana.json', function(regions) {
        let regionSel = $('#region');
        if (regionSel.length && regionSel.prop('tagName') === 'SELECT') {
            regionSel.empty().append('<option value="">-- Select Region --</option>');
            regions.forEach(function(r) {
                regionSel.append('<option value="'+r+'">'+r+'</option>');
            });
            regionSel.val(regionSel.data('selected'));
        }
    });

    // 4. Dynamic Emergency Contacts
    $(document).on('click', '#add-emergency-contact', function(e) {
        e.preventDefault();
        let idx = $('.emergency-contact-row').length + 1;
        let row = `<div class="form-row emergency-contact-row">
            <div class="form-group col-md-4">
                <input type="text" class="form-control" name="emergency_contacts[${idx}][name]" placeholder="Contact Name" required>
            </div>
            <div class="form-group col-md-4">
                <input type="text" class="form-control" name="emergency_contacts[${idx}][mobile]" placeholder="Mobile" required>
            </div>
            <div class="form-group col-md-3">
                <input type="text" class="form-control" name="emergency_contacts[${idx}][relationship]" placeholder="Relationship" required>
            </div>
            <div class="form-group col-md-1">
                <button class="btn btn-danger remove-emergency-contact" type="button"><i class="fa fa-trash"></i></button>
            </div>
        </div>`;
        $('#emergency-contacts-list').append(row);
    });
    $('#emergency-contacts-list').on('click', '.remove-emergency-contact', function() {
        $(this).closest('.emergency-contact-row').remove();
    });

    // 5. Baptized/Confirmed logic
    function toggleDateField(radioName, dateId) {
        let v = $(`input[name='${radioName}']:checked`).val();
        if (v === 'Yes') {
            $(`#${dateId}`).closest('.form-group').show();
        } else {
            $(`#${dateId}`).closest('.form-group').hide();
            $(`#${dateId}`).val('');
        }
    }
    toggleDateField('baptized', 'date_of_baptism');
    toggleDateField('confirmed', 'date_of_confirmation');
    $("input[name='baptized']").change(function(){ toggleDateField('baptized', 'date_of_baptism'); });
    $("input[name='confirmed']").change(function(){ toggleDateField('confirmed', 'date_of_confirmation'); });

    // 6. Organizations - Select2
    if ($('#organizations').length) {
        $('#organizations').select2({ placeholder: 'Select Organization(s)', allowClear: true, width: '100%' });
    }

    // 7. Click-to-copy CRN logic
    $('#copy-crn-btn').tooltip();
    $('#copy-crn-btn').on('click', function() {
        var crnField = document.getElementById('crn-field');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(crnField.value).then(function() {
                $('#copy-crn-btn').attr('data-original-title', 'Copied!').tooltip('show');
                setTimeout(function(){
                    $('#copy-crn-btn').attr('data-original-title', 'Copy');
                }, 1200);
            });
        } else {
            crnField.select();
            document.execCommand('copy');
            $('#copy-crn-btn').attr('data-original-title', 'Copied!').tooltip('show');
            setTimeout(function(){
                $('#copy-crn-btn').attr('data-original-title', 'Copy');
            }, 1200);
        }
    });
});
