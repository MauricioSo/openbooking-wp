(function ($) {
    'use strict';

    if (!$('#obwp-onboarding-form').length) return;

    var restUrl = (window.obwpOnboarding || {}).restUrl;
    var nonce   = (window.obwpOnboarding || {}).nonce;
    var presets = {};
    var detected = {};

    $(document).ready(function() {
        loadPresets();
        autoDetect();
    });

    function loadPresets() {
        $.ajax({
            url: restUrl + 'admin/onboarding/presets',
            method: 'GET',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', nonce); }
        }).done(function(res) {
            presets = {};
            (res.presets || []).forEach(function(p) { presets[p.key] = p; });
        });
    }

    function autoDetect() {
        $.ajax({
            url: restUrl + 'admin/onboarding/detect',
            method: 'GET',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', nonce); }
        }).done(function(res) {
            detected = res;
            if (res.country) {
                $('#onb_country').val(res.country);
            }
            if (res.suggested_currency) {
                $('#onb_currency').val(res.suggested_currency);
            }
            if (res.timezone) {
                var tzMap = {
                    'America/Santiago': 'America/Santiago',
                    'America/Bogota': 'America/Bogota',
                    'America/Mexico_City': 'America/Mexico_City',
                    'America/Buenos_Aires': 'America/Buenos_Aires',
                    'America/Lima': 'America/Lima',
                    'America/Sao_Paulo': 'America/Sao_Paulo'
                };
                if (tzMap[res.timezone]) {
                    $('#onb_timezone').val(tzMap[res.timezone]);
                }
            }
            if (res.suggested_language) {
                $('#onb_language').val(res.suggested_language);
            }
            if (res.country || res.suggested_currency) {
                $('#obwp-detect-msg').text('Detectamos tu ubicacion automaticamente. Corrige si es necesario.');
            }
        });
    }

    $(document).on('click', '.obwp-preset-card', function() {
        $('.obwp-preset-card').removeClass('selected');
        $(this).addClass('selected');
        var key = $(this).data('preset');
        $('#obwp-selected-preset').val(key);
        applyPresetToForm(key);
    });

    function applyPresetToForm(key) {
        var data = {
            key: key
        };
        $.ajax({
            url: restUrl + 'admin/onboarding/presets',
            method: 'GET',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', nonce); }
        }).done(function(res) {
            var allPresets = res.presets || [];
            var presetMeta = null;
            allPresets.forEach(function(p) { if (p.key === key) presetMeta = p; });
            if (!presetMeta) return;

            switch (key) {
                case 'health':
                    fillService('Consulta', 30, 'presencial', 1);
                    fillSchedule([{1:'09:00-17:00'},{2:'09:00-17:00'},{3:'09:00-17:00'},{4:'09:00-17:00'},{5:'09:00-17:00'}]);
                    setPaymentMode('manual');
                    break;
                case 'beauty':
                    fillService('Hora de belleza', 60, 'presencial', 1);
                    fillSchedule([{1:'10:00-19:00'},{2:'10:00-19:00'},{3:'10:00-19:00'},{4:'10:00-19:00'},{5:'10:00-19:00'},{6:'10:00-14:00'}]);
                    setPaymentMode('full');
                    break;
                case 'education':
                    fillService('Clase', 60, 'both', 10);
                    fillSchedule([{1:'09:00-18:00'},{2:'09:00-18:00'},{3:'09:00-18:00'},{4:'09:00-18:00'},{5:'09:00-18:00'}]);
                    setPaymentMode('full');
                    break;
                case 'legal':
                    fillService('Consulta', 45, 'both', 1);
                    fillSchedule([{1:'09:00-18:00'},{2:'09:00-18:00'},{3:'09:00-18:00'},{4:'09:00-18:00'},{5:'09:00-18:00'}]);
                    setPaymentMode('manual');
                    break;
                case 'fitness':
                    fillService('Sesion', 60, 'presencial', 8);
                    fillSchedule([{1:'07:00-20:00'},{2:'07:00-20:00'},{3:'07:00-20:00'},{4:'07:00-20:00'},{5:'07:00-20:00'},{6:'09:00-13:00'}]);
                    setPaymentMode('full');
                    break;
                case 'spaces':
                    fillService('Reserva de espacio', 60, 'presencial', 20);
                    fillSchedule([{1:'08:00-20:00'},{2:'08:00-20:00'},{3:'08:00-20:00'},{4:'08:00-20:00'},{5:'08:00-20:00'},{6:'08:00-20:00'}]);
                    setPaymentMode('full');
                    break;
                default:
                    fillService('Cita', 60, 'presencial', 1);
                    fillSchedule([{1:'09:00-18:00'},{2:'09:00-18:00'},{3:'09:00-18:00'},{4:'09:00-18:00'},{5:'09:00-18:00'}]);
                    setPaymentMode('full');
            }
        });
    }

    function fillService(name, duration, mode, capacity) {
        $('#onb_service_name').val(name);
        $('#onb_duration').val(duration);
        $('[name="service_mode"][value="' + mode + '"]').prop('checked', true);
    }

    function fillSchedule(scheduleMap) {
        var allDays = [1,2,3,4,5,6,7];
        allDays.forEach(function(d) {
            var $row = $('[data-day="' + d + '"]');
            var found = null;
            scheduleMap.forEach(function(s) {
                if (s[d]) found = s[d];
            });
            if (found) {
                $row.find('input[type="checkbox"]').prop('checked', true);
                var parts = found.split('-');
                $row.find('[name^="time_from"]').val(parts[0]);
                $row.find('[name^="time_to"]').val(parts[1]);
            } else {
                $row.find('input[type="checkbox"]').prop('checked', false);
            }
        });
    }

    function setPaymentMode(mode) {
        $('[name="payment_mode"][value="' + mode + '"]').prop('checked', true);
    }

    $(document).on('click', '.obwp-onb-next', function (e) {
        e.preventDefault();
        var $steps = $('.obwp-onboarding-step');
        var $current = $steps.filter('.active');
        var idx = $steps.index($current);

        if (idx === 1 && !$('#obwp-selected-preset').val()) {
            showOnbError('Selecciona un tipo de agenda.');
            return;
        }

        if (idx < $steps.length - 1) {
            $current.removeClass('active').hide();
            $steps.eq(idx + 1).addClass('active').show();
            updateProgress(idx + 2, $steps.length);
        }
    });

    $(document).on('click', '.obwp-onb-prev', function (e) {
        e.preventDefault();
        var $steps = $('.obwp-onboarding-step');
        var $current = $steps.filter('.active');
        var idx = $steps.index($current);
        if (idx > 0) {
            $current.removeClass('active').hide();
            $steps.eq(idx - 1).addClass('active').show();
            updateProgress(idx, $steps.length);
        }
    });

    $(document).on('change', '[name="publish_mode"]', function() {
        $('#onb-page-name-field').toggle($(this).val() === 'new_page');
    });

    $('#obwp-onboarding-form').on('submit', function (e) {
        e.preventDefault();

        var presetKey = $('#obwp-selected-preset').val() || 'generic';
        var data = $(this).serializeArray();
        var payload = {};
        data.forEach(function(item) { payload[item.name] = item.value; });

        payload.preset_key = presetKey;

        var schedule = [];
        $('[name="enabled_days[]"]:checked').each(function() {
            var day = $(this).val();
            var tf = $('[name="time_from[' + day + ']"]').val();
            var tt = $('[name="time_to[' + day + ']"]').val();
            if (tf && tt) {
                schedule.push({ scope_type: 'global', scope_id: 0, rule_type: 'weekly', weekday: parseInt(day), time_from: tf, time_to: tt });
            }
        });
        payload.schedule = schedule;

        var $btn = $(this).find('button[type="submit"]').prop('disabled', true).text('Publicando...');

        $.ajax({
            url: restUrl + 'admin/onboarding',
            method: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', nonce); }
        }).done(function (res) {
            if (res.success) {
                window.location.href = res.redirect || '?page=openbooking';
            } else {
                showOnbError(res.error || 'Error al guardar.');
                $btn.prop('disabled', false).text('Publicar agenda');
            }
        }).fail(function () {
            showOnbError('Error de conexion.');
            $btn.prop('disabled', false).text('Publicar agenda');
        });
    });

    function showOnbError(msg) {
        var $existing = $('.obwp-onb-error');
        if ($existing.length) $existing.remove();
        $('#obwp-onboarding-form').prepend('<div class="notice notice-error inline obwp-onb-error"><p>' + $('<span>').text(msg).html() + '</p></div>');
    }

    function updateProgress(step, total) {
        $('#obwp-onboarding-step').text('Paso ' + step + ' de ' + total);
        $('#obwp-progress-fill').css('width', Math.round(step / total * 100) + '%');
    }
})(jQuery);
