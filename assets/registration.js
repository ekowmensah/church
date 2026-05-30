// registration.js - Handles camera, photo preview, password toggle, dynamic contacts, orgs select, region, baptism/confirmation logic
$(document).ready(function () {
    // 1. Camera/Image Upload
    let stream = null;
    if ($('#camera-btn').length) {
        $('#camera-btn').on('click', function (e) {
            e.preventDefault();
            $('#camera-modal').modal('show');
            if (!stream) {
                navigator.mediaDevices.getUserMedia({ video: true })
                    .then(function (s) {
                        stream = s;
                        document.getElementById('camera-video').srcObject = stream;
                    });
            }
        });
        $('#capture-btn').on('click', function () {
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
        $('#camera-modal').on('hidden.bs.modal', function () {
            if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        });
    }

    // --- PHOTO PREVIEW AND CHANGE PHOTO LOGIC ---
    // Show preview and hide upload section when a file is chosen
    $('#photo').on('change', function () {
        if (this.files && this.files[0]) {
            let reader = new FileReader();
            reader.onload = function (e) {
                $('#photo-preview').attr('src', e.target.result);
                $('#photo-preview-wrap').show();
                $('#photo-upload-section').hide();
                $('#photo-data').val(''); // clear camera
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
    // Show preview and hide upload section when camera photo is captured
    $(document).on('change input', '#photo-data', function () {
        if ($(this).val()) {
            $('#photo-preview').attr('src', $(this).val());
            $('#photo-preview-wrap').show();
            $('#photo-upload-section').hide();
            $('#photo').val('');
        }
    });
    // Reset UI when "Change Photo" is clicked
    $('#photo-upload-group').on('click', '#remove-photo-btn', function () {
        $('#photo-preview').attr('src', '#');
        $('#photo-preview-wrap').hide();
        $('#photo-upload-section').show();
        $('#photo').val('');
        $('#photo-data').val('');
    });

    // 2. Password Preview
    $('.toggle-password').on('click', function () {
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
    function normalizeBaseUrl(base) {
        if (typeof base !== 'string') return '';
        base = base.trim();
        if (base === '' || base === '/') return '';
        return base.replace(/\/+$/, '');
    }

    function joinBasePath(base, path) {
        var cleanBase = normalizeBaseUrl(base);
        var cleanPath = (path || '').replace(/^\/+/, '');
        return (cleanBase ? cleanBase + '/' : '/') + cleanPath;
    }

    function getBaseUrl() {
        if (typeof window.BASE_URL !== 'undefined') {
            return normalizeBaseUrl(window.BASE_URL);
        }
        // Try to infer from script src
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].getAttribute('src');
            if (src && src.match(/\/assets\/registration\.js($|\?)/)) {
                var parts = src.split('/assets/registration.js');
                return normalizeBaseUrl(parts[0] || '');
            }
        }
        // Fallback: use path up to /assets/
        var path = window.location.pathname;
        var idx = path.indexOf('/assets/');
        if (idx !== -1) return normalizeBaseUrl(path.substring(0, idx));
        return '';
    }
    var BASE = getBaseUrl();

    // 3. Populate Regions (Ghana)
    $.getJSON(joinBasePath(BASE, '/assets/regions_ghana.json'), function (regions) {
        let regionSel = $('#region');
        if (regionSel.length && regionSel.prop('tagName') === 'SELECT') {
            regionSel.empty().append('<option value="">-- Select Region --</option>');
            regions.forEach(function (r) {
                regionSel.append('<option value="' + r + '">' + r + '</option>');
            });
            regionSel.val(regionSel.data('selected'));
        }
    });

    // 3b. GPS Address segmented input sync
    function initializeGpsAddressFields() {
        var hiddenField = $('#gps_address');
        var fields = $('.gps-char');
        if (!hiddenField.length || !fields.length) {
            return;
        }

        fields = fields.sort(function (a, b) {
            return Number($(a).data('index')) - Number($(b).data('index'));
        });

        var expectedLength = 10;
        var letterCount = 2;
        var middleStart = 2;
        var middleEnd = 6; // exclusive
        var lastStart = 6;

        function isLetterIndex(index) {
            return index < letterCount;
        }

        function sanitize(index, value) {
            var raw = (value || '').toUpperCase();
            var cleaned = isLetterIndex(index)
                ? raw.replace(/[^A-Z]/g, '')
                : raw.replace(/\D/g, '');
            return cleaned.substring(0, 1);
        }

        function parseInitialValue(value) {
            var source = (value || '').trim().toUpperCase();
            if (source === '') {
                return new Array(expectedLength).fill('');
            }

            var hyphenMatch = source.match(/^([A-Z]{2})-(\d{3,4})-(\d{4})$/);
            if (hyphenMatch) {
                var formattedChars = new Array(expectedLength).fill('');
                formattedChars[0] = hyphenMatch[1].charAt(0);
                formattedChars[1] = hyphenMatch[1].charAt(1);
                for (var m = 0; m < hyphenMatch[2].length; m++) {
                    formattedChars[middleStart + m] = hyphenMatch[2].charAt(m);
                }
                for (var l = 0; l < 4; l++) {
                    formattedChars[lastStart + l] = hyphenMatch[3].charAt(l);
                }
                return formattedChars;
            }

            var lettersOnly = source.replace(/[^A-Z]/g, '').substring(0, 2);
            var digitsOnly = source.replace(/\D/g, '');
            var middleDigits = '';
            var lastDigits = '';
            if (digitsOnly.length >= 8) {
                middleDigits = digitsOnly.substring(0, 4);
                lastDigits = digitsOnly.substring(4, 8);
            } else if (digitsOnly.length >= 7) {
                middleDigits = digitsOnly.substring(0, 3);
                lastDigits = digitsOnly.substring(3, 7);
            } else {
                middleDigits = digitsOnly.substring(0, 4);
                lastDigits = digitsOnly.substring(4, 8);
            }

            var compact = (lettersOnly + middleDigits + lastDigits).replace(/[^A-Z0-9]/g, '');
            var chars = new Array(expectedLength).fill('');
            var cursor = 0;
            for (var i = 0; i < compact.length && cursor < expectedLength; i++) {
                var candidate = sanitize(cursor, compact.charAt(i));
                if (!candidate) {
                    continue;
                }
                chars[cursor] = candidate;
                cursor++;
            }
            return chars;
        }

        function syncHiddenFromParts() {
            var values = [];
            fields.each(function (index, fieldEl) {
                var field = $(fieldEl);
                var clean = sanitize(index, field.val());
                if (field.val() !== clean) {
                    field.val(clean);
                }
                values.push(clean);
            });

            var merged = values.join('').trim();
            if (merged.length === 0) {
                hiddenField.val('');
                return;
            }

            var letters = values.slice(0, 2).join('');
            var middle = values.slice(middleStart, middleEnd).join('');
            var last = values.slice(lastStart, lastStart + 4).join('');
            var middleCompact = middle.replace(/\s/g, '');
            if (letters.length === 2 && /^\d{3,4}$/.test(middleCompact) && /^\d{4}$/.test(last)) {
                hiddenField.val(letters + '-' + middleCompact + '-' + last);
                return;
            }
            hiddenField.val(letters + middle + last);
        }

        var initial = parseInitialValue(hiddenField.val());
        fields.each(function (index, fieldEl) {
            $(fieldEl).val(initial[index] || '');
        });
        syncHiddenFromParts();

        fields.each(function (index, fieldEl) {
            var field = $(fieldEl);
            field.on('input', function () {
                var cleaned = sanitize(index, field.val());
                field.val(cleaned);
                syncHiddenFromParts();
                if (cleaned.length === 1 && index < fields.length - 1) {
                    $(fields[index + 1]).focus().select();
                }
            });
            field.on('keydown', function (event) {
                if (event.key === 'Backspace' && !field.val() && index > 0) {
                    $(fields[index - 1]).focus();
                }
                if (event.key === '-' && (index === 4 || index === 5)) {
                    event.preventDefault();
                    $(fields[lastStart]).focus();
                }
                if (event.key === 'ArrowLeft' && index > 0) {
                    $(fields[index - 1]).focus();
                }
                if (event.key === 'ArrowRight' && index < fields.length - 1) {
                    $(fields[index + 1]).focus();
                }
            });
            field.on('paste', function (event) {
                var pasted = (event.originalEvent || event).clipboardData;
                var raw = pasted ? pasted.getData('text') : '';
                if (!raw) return;
                event.preventDefault();

                var letters = raw.toUpperCase().replace(/[^A-Z]/g, '').substring(0, letterCount);
                var digits = raw.replace(/\D/g, '');
                var middleDigits = '';
                var lastDigits = '';
                if (digits.length >= 8) {
                    middleDigits = digits.substring(0, 4);
                    lastDigits = digits.substring(4, 8);
                } else {
                    middleDigits = digits.substring(0, 3);
                    lastDigits = digits.substring(3, 7);
                }
                var combined = (letters + middleDigits + lastDigits).substring(0, expectedLength);
                var chars = parseInitialValue(combined);
                fields.each(function (i, el) {
                    $(el).val(chars[i] || '');
                });
                syncHiddenFromParts();
                $(fields[Math.min(expectedLength - 1, fields.length - 1)]).focus().select();
            });
        });

        var form = hiddenField.closest('form');
        if (form.length) {
            form.on('submit', function (event) {
                syncHiddenFromParts();
                var values = [];
                fields.each(function (_, el) { values.push($(el).val()); });
                var merged = values.join('');
                var allEmpty = merged.length === 0;
                var letters = values.slice(0, 2).join('');
                var middleVals = values.slice(middleStart, middleEnd);
                var last = values.slice(lastStart, lastStart + 4).join('');
                var middleFirstThree = middleVals[0] + middleVals[1] + middleVals[2];
                var middleFourth = middleVals[3];
                var middleValid = /^\d{3}$/.test(middleFirstThree) && (middleFourth === '' || /^\d$/.test(middleFourth));
                var lastValid = /^\d{4}$/.test(last);
                var allComplete = /^[A-Z]{2}$/.test(letters) && middleValid && lastValid;
                if (!allEmpty && !allComplete) {
                    event.preventDefault();
                    event.stopPropagation();
                    fields.each(function (_, el) {
                        $(el).addClass('is-invalid');
                    });
                    $(fields[0]).focus();
                    return;
                }
                fields.each(function (_, el) {
                    $(el).removeClass('is-invalid');
                });
                var middleValue = middleFirstThree + (middleFourth ? middleFourth : '');
                hiddenField.val(letters + '-' + middleValue + '-' + last);
            });
        }
    }
    initializeGpsAddressFields();

    // 4. Dynamic Emergency Contacts
    function getRelationshipFieldHtml(idx) {
        var relationshipOptionsHtml = (window.RELATIONSHIP_OPTIONS_HTML || '').trim();
        if (relationshipOptionsHtml !== '') {
            return '<select class="form-control emergency-contact-relationship" name="emergency_contacts[' + idx + '][relationship]" required>' +
                relationshipOptionsHtml +
                '</select>';
        }
        return '<input type="text" class="form-control" name="emergency_contacts[' + idx + '][relationship]" placeholder="Relationship" required>';
    }

    $(document).on('click', '#add-emergency-contact', function (e) {
        e.preventDefault();
        let idx = $('.emergency-contact-row').length + 1;
        let row = `<div class="form-row emergency-contact-row">
            <div class="form-group col-md-7">
                <label>Contact Person (Name or Member CRN)</label>
                <select class="form-control emergency-contact-search" name="emergency_contacts[${idx}][crn]" data-idx="${idx}">
                    <option value="">-- Search by CRN/name/phone or type name --</option>
                </select>
                <input type="hidden" class="emergency-contact-name" name="emergency_contacts[${idx}][name]">
                <input type="hidden" class="emergency-contact-mobile" name="emergency_contacts[${idx}][mobile]">
                <small class="form-text text-muted">Search for member or type full name</small>
            </div>
            <div class="form-group col-md-4">
                <label>Relationship</label>
                ${getRelationshipFieldHtml(idx)}
            </div>
            <div class="form-group col-md-1">
                <label>&nbsp;</label>
                <button class="btn btn-danger remove-emergency-contact" type="button"><i class="fa fa-trash"></i></button>
            </div>
        </div>`;
        $('#emergency-contacts-list').append(row);
        // Initialize Select2 on newly added element with a small delay to ensure DOM is ready
        setTimeout(function () {
            let newSelect = $('#emergency-contacts-list').find('.emergency-contact-row:last .emergency-contact-search');
            initializeEmergencyContactSearch(newSelect);
        }, 100);
    });
    $('#emergency-contacts-list').on('click', '.remove-emergency-contact', function () {
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
    $("input[name='baptized']").change(function () { toggleDateField('baptized', 'date_of_baptism'); });
    $("input[name='confirmed']").change(function () { toggleDateField('confirmed', 'date_of_confirmation'); });

    // 6. Organizations - Select2
    if ($('#organizations').length) {
        $('#organizations').select2({ placeholder: 'Select Organization(s)', allowClear: true, width: '100%' });
    }

    // 8. Spouse Search with Select2 - Combined field for search and manual entry
    if ($('#spouse_crn').length) {
        $('#spouse_crn').select2({
            placeholder: 'Search by CRN, name, or phone',
            allowClear: true,
            width: '100%',
            tags: true,  // Allow free text entry
            minimumInputLength: 0,
            ajax: {
                url: joinBasePath(BASE, '/api/search_member_registration.php'),
                dataType: 'json',
                delay: 300,
                data: function (params) {
                    return { q: params.term };
                },
                processResults: function (data) {
                    return { results: data.results };
                }
            }
        });
        // When a member is selected, save to hidden spouse_name field
        $('#spouse_crn').on('select2:select', function (e) {
            var data = e.params.data;
            if (data.full_name) {
                // Member was selected
                $('#spouse_name').val(data.full_name);
            } else {
                // Manual text entry
                $('#spouse_name').val(data.text || data.id);
            }
        });
        // Handle manual text input
        $('#spouse_crn').on('select2:closing', function (e) {
            if (!e.params.data) {
                var text = $(this).data('select2').$dropdown.find('.select2-search__field').val();
                if (text && text.length > 0) {
                    $('#spouse_name').val(text);
                }
            }
        });
    }

    // 9. Marital Status - Show/Hide marriage-specific fields
    function toggleMarriageFields() {
        if ($('#marital_status').val() === 'Married') {
            $('#spouse-group').show();
            $('#marriage-type-group').show();
            $('#marriage_type').prop('required', true);
        } else {
            $('#spouse-group').hide();
            $('#marriage-type-group').hide();
            $('#spouse_crn').val(null).trigger('change');
            $('#spouse_name').val('');
            $('#marriage_type').val('').prop('required', false);
        }
    }
    $('#marital_status').on('change', toggleMarriageFields);
    // Trigger on page load
    toggleMarriageFields();

    // 10. Emergency Contact Search with Select2 - Combined search and manual entry
    function initializeEmergencyContactSearch(element) {
        // Only initialize if not already initialized
        if (element.data('select2')) {
            element.select2('destroy');
        }

        element.select2({
            placeholder: 'Search member by CRN, name, or phone',
            allowClear: true,
            width: '100%',
            tags: true,  // Allow free text entry
            minimumInputLength: 0,
            ajax: {
                url: joinBasePath(BASE, '/api/search_member_registration.php'),
                dataType: 'json',
                delay: 300,
                data: function (params) {
                    // Get all currently selected emergency contact CRNs to exclude them
                    var excludedCrns = [];
                    $('.emergency-contact-search').each(function () {
                        var selectedVal = $(this).val();
                        if (selectedVal && selectedVal !== element.val()) {
                            excludedCrns.push(selectedVal);
                        }
                    });

                    return {
                        q: params.term,
                        exclude: excludedCrns.join(',')
                    };
                },
                processResults: function (data) {
                    return { results: data.results };
                }
            }
        });

        // When a member is selected, populate name and phone
        element.off('select2:select').on('select2:select', function (e) {
            var data = e.params.data;
            var row = element.closest('.emergency-contact-row');
            if (data.full_name) {
                // Member was selected from search
                row.find('.emergency-contact-name').val(data.full_name);
                row.find('.emergency-contact-mobile').val(data.phone);
            } else {
                // Manual text entry
                row.find('.emergency-contact-name').val(data.text || data.id);
                row.find('.emergency-contact-mobile').val('');
            }
        });

        // When cleared, clear hidden fields
        element.off('select2:unselect').on('select2:unselect', function (e) {
            var row = element.closest('.emergency-contact-row');
            row.find('.emergency-contact-name').val('');
            row.find('.emergency-contact-mobile').val('');
        });
    }

    // Initialize existing emergency contact searches on page load
    $('.emergency-contact-search').each(function () {
        initializeEmergencyContactSearch($(this));
    });



    // 11. Click-to-copy CRN logic
    $('#copy-crn-btn').tooltip();
    $('#copy-crn-btn').on('click', function () {
        var crnField = document.getElementById('crn-field');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(crnField.value).then(function () {
                $('#copy-crn-btn').attr('data-original-title', 'Copied!').tooltip('show');
                setTimeout(function () {
                    $('#copy-crn-btn').attr('data-original-title', 'Copy');
                }, 1200);
            });
        } else {
            crnField.select();
            document.execCommand('copy');
            $('#copy-crn-btn').attr('data-original-title', 'Copied!').tooltip('show');
            setTimeout(function () {
                $('#copy-crn-btn').attr('data-original-title', 'Copy');
            }, 1200);
        }
    });
});
