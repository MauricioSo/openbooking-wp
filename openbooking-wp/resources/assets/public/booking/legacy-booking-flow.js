/**
 * OpenBooking – public booking flow
 *
 * Structured as internal modules inside the IIFE so each concern is isolated
 * and testable without a build step or framework change.
 *
 * Modules:
 *   State      – single source of truth for the current booking session
 *   Api        – REST helpers (GET / POST) with nonce refresh on 401
 *   Steps      – wizard step rendering
 *   Calendar   – month calendar and date selection
 *   TimeSlots  – slot list rendering and selection
 *   Form       – dynamic customer-detail form
 *   Payment    – payment initiation and redirect
 *   Confirm    – final confirmation / error state rendering
 *   TokenFlow  – cancel / reschedule / payment-result URL actions
 *   Events     – global delegated event bindings (boots everything)
 */
(function ($) {
    'use strict';

    if (!window.obwpFront) return;

    var cfg = {
        restUrl : obwpFront.restUrl,
        nonce   : obwpFront.nonce,
        telemetryUrl : obwpFront.telemetryUrl || '',
        strings : obwpFront.strings || {},
        gateway : obwpFront.preferredGateway || 'manual',
        paymentMode : obwpFront.paymentMode || 'full',
        depositPercent : parseInt(obwpFront.depositPercent, 10) || 30,
        businessTimezone : obwpFront.businessTimezone || 'UTC',
        storageKey : 'obwp_booking_draft_v1',
        privacyPolicyUrl : obwpFront.privacyPolicyUrl || '',
        privacyConsentRequired : !!obwpFront.privacyConsentRequired
    };

    // =========================================================================
    // Module: State
    // =========================================================================
    var Baseline = {
        initTs          : 0,
        servicesTs      : 0,
        firstInteraction: false,
        flowCompleted   : false,

        markInit: function () {
            this.initTs = Date.now();
            Api.track('baseline_init', { ts: this.initTs });
        },

        markServicesLoaded: function (count, isError) {
            this.servicesTs = Date.now();
            Api.track('baseline_services_loaded', {
                count: count,
                isError: !!isError,
                elapsed_ms: this.servicesTs - this.initTs
            });
            if (count === 0 && !isError) {
                Api.track('baseline_services_empty', { elapsed_ms: this.servicesTs - this.initTs });
            }
        },

        markFirstInteraction: function (step, meta) {
            if (this.firstInteraction) return;
            this.firstInteraction = true;
            Api.track('baseline_first_interaction', {
                step: step,
                elapsed_ms: Date.now() - this.initTs,
                meta: meta || {}
            });
        },

        markFlowCompleted: function () {
            this.flowCompleted = true;
            Api.track('baseline_flow_completed', { elapsed_ms: Date.now() - this.initTs });
        },

        trackAbandon: function () {
            if (this.flowCompleted) return;
            var elapsed = Date.now() - this.initTs;
            Api.track('baseline_abandon', {
                elapsed_ms: elapsed,
                reached_step: State.step,
                had_service: !!State.serviceId,
                had_date: !!State.date,
                had_slot: !!State.startAt,
                mode: State.mode
            });
        }
    };

    var State = {

        // anti-bot: timestamp when page loaded (used for minimum-time check)
        loadedAt: Math.floor(Date.now() / 1000),

        // persist across page reloads via sessionStorage
        step              : 1,
        serviceId         : null,
        serviceSlug       : null,
        serviceName       : null,
        servicePrice      : null,
        servicePriceMinor : 0,
        serviceCurrency   : '',
        date              : null,
        time              : null,
        startAt           : null,
        endAt             : null,
        resourceId        : null,
        bookingId         : null,
        cancelToken       : null,
        rescheduleToken   : null,
        bookingToken      : null,
        bookingSnapshot   : null,
        mode              : 'booking',   // 'booking' | 'reschedule'
        formFields        : [],
        submitting        : false,
        clientRef         : null,
        customerDraft     : {},

        reset: function () {
            this.submitting = false;
        }
    };

    // =========================================================================
    // Module: Api
    // =========================================================================
    var Api = {
        /**
         * GET helper.  Automatically retries once after refreshing the nonce
         * if the server returns 401 (happens on cached pages after session refresh).
         */
        get: function (endpoint) {
            return this._request('GET', endpoint, null);
        },

        post: function (endpoint, data) {
            return this._request('POST', endpoint, data);
        },

        _request: function (method, endpoint, data) {
            var self   = this;
            var opts   = {
                url    : cfg.restUrl + endpoint,
                method : method,
                timeout: 30000,
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', cfg.nonce);
                }
            };
            if (data !== null) {
                opts.data        = JSON.stringify(data);
                opts.contentType = 'application/json';
            }

            var deferred = $.Deferred();

            $.ajax(opts)
                .done(function (res) { deferred.resolve(res); })
                .fail(function (xhr) {
                    // Refresh nonce and retry once on 401
                    if (xhr.status === 401) {
                        self._refreshNonce(function (newNonce) {
                            if (!newNonce) { deferred.reject(xhr); return; }
                            cfg.nonce = newNonce;
                            opts.beforeSend = function (x) { x.setRequestHeader('X-WP-Nonce', cfg.nonce); };
                            $.ajax(opts)
                                .done(function (r) { deferred.resolve(r); })
                                .fail(function (x) { deferred.reject(x); });
                        });
                    } else {
                        deferred.reject(xhr);
                    }
                });

            return deferred.promise();
        },

        _refreshNonce: function (callback) {
            $.get(obwpFront.nonceRefreshUrl || '', function (res) {
                callback(res && res.nonce ? res.nonce : null);
            }).fail(function () { callback(null); });
        },

        track: function (eventName, meta) {
            if (!cfg.telemetryUrl || !window.fetch) return;
            window.fetch(cfg.telemetryUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event: eventName, meta: meta || {} }),
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function () {});
        },

        /** Extract a human-readable error string from a failed XHR. */
        errorMsg: function (xhr, fallback) {
            fallback = fallback || cfg.strings.error_generic || 'Ha ocurrido un error. Inténtalo de nuevo.';
            if (xhr && xhr.responseJSON) {
                return xhr.responseJSON.error || xhr.responseJSON.message || fallback;
            }
            return fallback;
        }
    };

    var Session = {
        DRAFT_TTL_MS: 24 * 60 * 60 * 1000,

        save: function () {
            if (State.mode !== 'booking' || !window.localStorage) return;
            window.localStorage.setItem(cfg.storageKey, JSON.stringify({
                step: State.step,
                serviceId: State.serviceId,
                serviceName: State.serviceName,
                servicePrice: State.servicePrice,
                servicePriceMinor: State.servicePriceMinor,
                serviceCurrency: State.serviceCurrency,
                date: State.date,
                time: State.time,
                startAt: State.startAt,
                endAt: State.endAt,
                resourceId: State.resourceId,
                clientRef: State.clientRef,
                customerDraft: State.customerDraft || {},
                savedAt: Date.now()
            }));
        },

        clear: function () {
            if (!window.localStorage) return;
            window.localStorage.removeItem(cfg.storageKey);
        },

        restore: function () {
            if (!window.localStorage) return false;
            try {
                var raw = window.localStorage.getItem(cfg.storageKey);
                if (!raw) return false;
                var data = JSON.parse(raw);
                if (!data || !data.serviceId) return false;
                // Expire stale drafts: missing savedAt (pre-TTL format) or older than 24h.
                if (!data.savedAt || (Date.now() - data.savedAt) > this.DRAFT_TTL_MS) {
                    Api.track('booking_draft_expired', { had_saved_at: !!data.savedAt });
                    this.clear();
                    return false;
                }
                State.serviceId = data.serviceId;
                State.serviceName = data.serviceName || '';
                State.servicePrice = data.servicePrice || '';
                State.servicePriceMinor = parseInt(data.servicePriceMinor, 10) || 0;
                State.serviceCurrency = data.serviceCurrency || '';
                State.date = data.date || null;
                State.time = data.time || null;
                State.startAt = data.startAt || null;
                State.endAt = data.endAt || null;
                State.resourceId = data.resourceId || null;
                State.clientRef = data.clientRef || null;
                State.customerDraft = data.customerDraft || {};
                Api.track('booking_restore', { step: data.step || 1 });
                return true;
            } catch (e) {
                return false;
            }
        }
    };

    var Summary = {
        render: function () {
            var $summary = $('#obwp-booking-summary');
            if (!$summary.length) return;

            if (!State.serviceName && !State.date && !State.time) {
                $summary.hide().empty();
                return;
            }

            var html = '<div class="obwp-booking-summary-card">';
            html += '<h3>' + escapeHtml(cfg.strings.summary_title || 'Resumen de tu reserva') + '</h3>';
            if (State.serviceName) {
                html += '<p><span>' + escapeHtml(cfg.strings.summary_service || 'Servicio') + '</span><strong>' + escapeHtml(State.serviceName) + '</strong></p>';
            }
            if (State.date || State.time) {
                html += '<p><span>' + escapeHtml(cfg.strings.summary_datetime || 'Fecha y hora') + '</span><strong>' + escapeHtml(formatDateHuman(State.date)) + (State.time ? ' · ' + escapeHtml(State.time) : '') + '</strong></p>';
            }
            if (cfg.paymentMode === 'deposit' && State.servicePriceMinor > 0) {
                var dueNowMinor = Math.round(State.servicePriceMinor * cfg.depositPercent / 100);
                var dueNowStr = (dueNowMinor / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                html += '<p><span>' + escapeHtml(cfg.strings.summary_payment || 'Cobro') + '</span><strong>' + escapeHtml(State.serviceCurrency) + ' ' + dueNowStr + ' <small>(' + cfg.depositPercent + '% ' + escapeHtml(cfg.strings.pay_now || 'ahora') + ')</small></strong></p>';
            } else {
                html += '<p><span>' + escapeHtml(cfg.strings.summary_payment || 'Cobro') + '</span><strong>' + escapeHtml(this.getPaymentText()) + '</strong></p>';
            }
            html += '<p class="obwp-summary-timezone">' + escapeHtml(cfg.strings.current_timezone || 'Horario mostrado en') + ' ' + escapeHtml(cfg.businessTimezone) + '</p>';
            html += '</div>';

            $summary.html(html).show();
        },

        getPaymentText: function () {
            if ((State.servicePriceMinor || 0) <= 0) {
                return cfg.strings.reserve_without_payment || 'Reservar sin pago';
            }
            if (cfg.paymentMode === 'deposit') {
                return (cfg.strings.pay_now || 'Pagas ahora') + ' ' + cfg.depositPercent + '% · ' + (cfg.strings.pay_later || 'Pagas después');
            }
            return cfg.strings.continue_to_payment || 'Continuar al pago';
        }
    };

    var FlowNotice = {
        show: function (msg, withReset) {
            var $notice = $('#obwp-flow-notice');
            if (!$notice.length) return;
            var html = '<span>' + escapeHtml(msg) + '</span>';
            if (withReset) {
                html += '<button type="button" class="obwp-link-btn" id="obwp-reset-draft">' + escapeHtml(cfg.strings.booking_restore_clear || 'Empezar de nuevo') + '</button>';
            }
            $notice.html(html).show();
        }
    };

    // =========================================================================
    // Module: Steps
    // =========================================================================
    var Steps = {
        show: function (step) {
            State.step = step;
            if (step !== 2) TimeSlots._stopPoll();
            $('.obwp-panel').removeClass('obwp-panel--active');
            $('[data-panel="' + step + '"]').addClass('obwp-panel--active');
            $('.obwp-step').removeClass('obwp-step--active obwp-step--completed').removeAttr('aria-current');
            for (var i = 1; i < step; i++) {
                $('[data-step="' + i + '"]').addClass('obwp-step--completed').attr('aria-current', 'step');
            }
            $('[data-step="' + step + '"]').addClass('obwp-step--active').attr('aria-current', 'step');
            Summary.render();
            Session.save();

            var $activePanel = $('[data-panel="' + step + '"]');
            if ($activePanel.length) {
                var $focusable = $activePanel.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').first();
                if ($focusable.length) $focusable.focus();
            }
        },

        next: function (to) {
            if (to === 4) {
                Form.submit();
            } else {
                this.show(to);
            }
        }
    };

    // =========================================================================
    // Module: Calendar
    // =========================================================================
    var Calendar = {
        month: 0,
        year : 0,
        _requestVersion: 0,

        init: function () {
            var now   = new Date();
            this.month = now.getMonth();
            this.year  = now.getFullYear();
            this.render();
        },

        change: function (dir) {
            this.month += dir;
            if (this.month > 11) { this.month = 0;  this.year++; }
            if (this.month < 0)  { this.month = 11; this.year--; }
            this.render();
        },

        render: function () {
            var self  = this;
            var month = this.year + '-' + String(this.month + 1).padStart(2, '0');
            var version = ++this._requestVersion;

            Api.get('availability/dates?service_id=' + State.serviceId + '&month=' + month)
                .done(function (res) {
                    if (version !== self._requestVersion) return;

                    var available  = res.dates || [];
                    var monthNames = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                                      'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
                    var dayNames   = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
                    var today      = new Date().toISOString().slice(0, 10);

                    var html  = '<div class="obwp-calendar-header">';
                    html += '<button id="obwp-prev-month" aria-label="Mes anterior">&larr;</button>';
                    html += '<span>' + monthNames[self.month] + ' ' + self.year + '</span>';
                    html += '<button id="obwp-next-month" aria-label="Mes siguiente">&rarr;</button>';
                    html += '</div>';
                    html += '<div class="obwp-calendar-grid" role="grid">';
                    dayNames.forEach(function (d) {
                        html += '<div class="obwp-calendar-day-header" role="columnheader">' + d + '</div>';
                    });

                    var firstDay = new Date(self.year, self.month, 1).getDay();
                    var offset   = firstDay === 0 ? 6 : firstDay - 1;
                    var days     = new Date(self.year, self.month + 1, 0).getDate();

                    for (var i = 0; i < offset; i++) {
                        html += '<div class="obwp-calendar-day obwp-calendar-day--disabled" aria-hidden="true"></div>';
                    }

                    var selectedDate = State.startAt ? State.startAt.slice(0, 10) : State.date;
                    for (var d = 1; d <= days; d++) {
                        var ds  = self.year + '-' + String(self.month + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                        var avl = available.indexOf(ds) >= 0;
                        var pst = ds < today;
                        var cls = 'obwp-calendar-day' + (pst || !avl ? ' obwp-calendar-day--disabled' : ' obwp-calendar-day--available');
                        if (ds === selectedDate) cls += ' obwp-calendar-day--selected';
                        var role = (!pst && avl) ? ' role="button" tabindex="0"' : ' aria-hidden="true"';
                        html += '<div class="' + cls + '" data-date="' + ds + '"' + role + ' aria-label="' + formatDateA11y(ds) + '">' + d + '</div>';
                    }
                    html += '</div>';
                    $('.obwp-date-picker-section').html(html);
                });
        },

        selectDate: function (date) {
            if (State.date && State.date !== date) {
                State.startAt    = null;
                State.endAt      = null;
                State.resourceId = null;
                State.time       = null;
            }
            State.date = date;
            $('.obwp-calendar-day').removeClass('obwp-calendar-day--selected');
            $('[data-date="' + date + '"]').addClass('obwp-calendar-day--selected');
            Api.track('date_selected', { service_id: State.serviceId, date: date });
            Session.save();
            TimeSlots.load(date);
        }
    };

    // =========================================================================
    // Module: TimeSlots
    // =========================================================================
    var TimeSlots = {
        _requestVersion: 0,
        _pollTimer: null,
        _pollDate: null,

        load: function (date, opts) {
            opts = opts || {};
            var $section = $('#obwp-time-section');
            var $slots   = $('#obwp-time-slots');
            $section.show();
            $slots.html('<p class="obwp-loading">' + (cfg.strings.loading_slots || 'Buscando horarios disponibles...') + '</p>');

            if (opts.notice) {
                $section.find('.obwp-notice').remove();
                $section.prepend('<div class="obwp-notice obwp-notice--warning" role="alert">' + escapeHtml(opts.notice) + '</div>');
            }

            var version = ++this._requestVersion;
            var self = this;

            Api.get('availability?service_id=' + State.serviceId + '&date=' + date)
                .done(function (res) {
                    if (version !== self._requestVersion) return;

                    $section.find('.obwp-notice').not(opts.notice ? '' : '.obwp-notice').remove();
                    var slots = (res.slots || []).filter(function (s) { return s.available_capacity > 0; });
                    if (!slots.length) {
                        $slots.html(
                            '<p class="obwp-empty">' + (cfg.strings.no_slots || 'No hay horarios disponibles para este día.') + '</p>' +
                            '<p class="obwp-empty-help">' + escapeHtml(cfg.strings.no_slots_help || 'Intenta seleccionar otro día en el calendario.') + '</p>'
                        );
                        self._stopPoll();
                        return;
                    }
                    var html = '';
                    slots.forEach(function (slot) {
                        var time     = slot.start_at.split(' ')[1].slice(0, 5);
                        var cap      = slot.total_capacity > 1 ? ' <small>(' + slot.available_capacity + ')</small>' : '';
                        var selected = State.startAt === slot.start_at ? ' obwp-time-slot--selected' : '';
                        html += '<div class="obwp-time-slot' + selected + '" role="option" tabindex="0"' +
                            ' aria-label="' + time + '"' +
                            ' aria-selected="' + (State.startAt === slot.start_at ? 'true' : 'false') + '"' +
                            ' data-start="' + slot.start_at + '"' +
                            ' data-end="' + slot.end_at + '"' +
                            ' data-resource="' + (slot.resource_id || '') + '">' +
                            time + cap + '</div>';
                    });
                    $slots.html(html);
                    self._startPoll(date);
                })
                .fail(function () {
                    $slots.html(
                        '<div class="obwp-state obwp-state--failed" role="alert">' +
                        '<p class="obwp-error">' + (cfg.strings.error_slots_load || 'No pudimos cargar los horarios.') + '</p>' +
                        '<button class="obwp-btn obwp-btn--secondary" id="obwp-retry-slots">' +
                        (cfg.strings.retry || 'Volver a intentar') + '</button>' +
                        '</div>'
                    );
                    self._stopPoll();
                    $(document).off('click.obwpRetrySlots').on('click.obwpRetrySlots', '#obwp-retry-slots', function (e) {
                        e.preventDefault();
                        TimeSlots.load(date);
                    });
                });
        },

        select: function (startAt, endAt, resourceId) {
            this._stopPoll();
            State.startAt    = startAt;
            State.endAt      = endAt;
            State.resourceId = resourceId || null;
            State.time       = startAt.split(' ')[1].slice(0, 5);
            $('.obwp-time-slot').removeClass('obwp-time-slot--selected').attr('aria-pressed', 'false');
            $('[data-start="' + startAt + '"]').addClass('obwp-time-slot--selected').attr('aria-pressed', 'true');
            Api.track('slot_selected', { service_id: State.serviceId, date: State.date, start_at: startAt });
            Session.save();
            if (State.mode === 'reschedule') return;
            Steps.show(3);
            Form.render();
        },

        _startPoll: function (date) {
            this._stopPoll();
            this._pollDate = date;
            var self = this;
            this._pollTimer = setInterval(function () {
                if (State.step !== 2 || State.date !== self._pollDate) {
                    self._stopPoll();
                    return;
                }
                self._refresh(date);
            }, 30000);
        },

        _stopPoll: function () {
            if (this._pollTimer) {
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
            this._pollDate = null;
        },

        resetForServiceChange: function () {
            this._requestVersion++;
            this._stopPoll();
            $('.obwp-date-picker-section').empty();
            $('#obwp-time-section').hide().find('.obwp-notice').remove();
            $('#obwp-time-slots').empty();
        },

        _refresh: function (date) {
            var version = ++this._requestVersion;
            var self = this;

            Api.get('availability?service_id=' + State.serviceId + '&date=' + date)
                .done(function (res) {
                    if (version !== self._requestVersion) return;

                    var $slots = $('#obwp-time-slots');
                    var slots = (res.slots || []).filter(function (s) { return s.available_capacity > 0; });
                    if (!slots.length) {
                        $slots.html(
                            '<p class="obwp-empty">' + (cfg.strings.no_slots || 'No hay horarios disponibles para este día.') + '</p>' +
                            '<p class="obwp-empty-help">' + escapeHtml(cfg.strings.no_slots_help || 'Intenta seleccionar otro día en el calendario.') + '</p>'
                        );
                        self._stopPoll();
                        return;
                    }

                    var html = '';
                    var selectedGone = true;
                    slots.forEach(function (slot) {
                        var time     = slot.start_at.split(' ')[1].slice(0, 5);
                        var cap      = slot.total_capacity > 1 ? ' <small>(' + slot.available_capacity + ')</small>' : '';
                        var selected = State.startAt === slot.start_at ? ' obwp-time-slot--selected' : '';
                        if (State.startAt === slot.start_at) selectedGone = false;
                        html += '<div class="obwp-time-slot' + selected + '" role="option" tabindex="0"' +
                            ' aria-label="' + time + '"' +
                            ' aria-selected="' + (State.startAt === slot.start_at ? 'true' : 'false') + '"' +
                            ' data-start="' + slot.start_at + '"' +
                            ' data-end="' + slot.end_at + '"' +
                            ' data-resource="' + (slot.resource_id || '') + '">' +
                            time + cap + '</div>';
                    });
                    $slots.html(html);

                    if (selectedGone && State.startAt && State.step === 2) {
                        var notice = cfg.strings.slot_taken || 'Ese horario acaba de ocuparse.';
                        State.startAt    = null;
                        State.endAt      = null;
                        State.resourceId = null;
                        State.time       = null;
                        var $section = $('#obwp-time-section');
                        $section.find('.obwp-notice').remove();
                        $section.prepend('<div class="obwp-notice obwp-notice--warning" role="alert">' + escapeHtml(notice) + '</div>');
                        Api.track('slot_taken_poll', { service_id: State.serviceId, date: State.date });
                    }
                });
        }
    };

    // =========================================================================
    // Module: Form
    // =========================================================================
    var Form = {
        render: function () {
            var $form = $('#obwp-form-fields');
            if (!$form.length) return;

            var fields = State.formFields.length ? State.formFields : this._defaultFields();
            var html   = '';
            fields.forEach(function (f) {
                if (!parseInt(f.is_enabled)) return;
                var req     = parseInt(f.is_required);
                var reqMark = req ? ' <span aria-hidden="true">*</span>' : '';
                var reqAttr = req ? ' required aria-required="true"' : '';
                var ph      = escapeHtml(f.placeholder || '');
                var acMap   = { first_name: 'given-name', last_name: 'family-name', email: 'email', phone: 'tel' };
                var ac      = acMap[f.field_key] || 'off';

                html += '<div class="obwp-field">';
                html += '<label class="obwp-field-label" for="obwp-' + f.field_key + '">' + escapeHtml(f.label) + reqMark + '</label>';
                if (f.field_key === 'notes') {
                    html += '<textarea id="obwp-notes" name="notes" rows="3" placeholder="' + ph + '"' + reqAttr + '>' + escapeHtml(State.customerDraft.notes || '') + '</textarea>';
                } else {
                    var t = f.field_key === 'email' ? 'email' : (f.field_key === 'phone' ? 'tel' : 'text');
                    html += '<input type="' + t + '" id="obwp-' + f.field_key + '" name="' + f.field_key + '"' +
                        ' placeholder="' + ph + '" value="' + escapeHtml(State.customerDraft[f.field_key] || '') + '"' + reqAttr + ' autocomplete="' + ac + '">';
                }
                html += '</div>';
            });
            if (cfg.privacyConsentRequired && cfg.privacyPolicyUrl) {
                var policyLink = '<a href="' + escapeHtml(cfg.privacyPolicyUrl) + '" target="_blank" rel="noopener noreferrer">' +
                    escapeHtml(cfg.strings.privacy_policy || 'política de privacidad') + '</a>';
                html += '<div class="obwp-field obwp-field--privacy">' +
                    '<label class="obwp-field-label obwp-field-label--checkbox">' +
                    '<input type="checkbox" id="obwp-privacy-consent" name="privacy_consent" required aria-required="true"> ' +
                    escapeHtml(cfg.strings.privacy_consent_prefix || 'He leído y acepto la') + ' ' + policyLink +
                    ' <span aria-hidden="true">*</span>' +
                    '</label>' +
                    '</div>';
            }
            $form.html(html);
            this.updateActionLabel();
        },

        updateActionLabel: function () {
            var $btn = $('.obwp-btn-next[data-to="4"]');
            if (!$btn.length) return;
            if ((State.servicePriceMinor || 0) <= 0) {
                $btn.text(cfg.strings.reserve_without_payment || 'Reservar sin pago');
                return;
            }
            $btn.text(cfg.strings.continue_to_payment || 'Continuar al pago');
        },

        _defaultFields: function () {
            return [
                { field_key: 'first_name', label: 'Tu nombre',          placeholder: 'Escribe tu nombre',      is_required: 1, is_enabled: 1 },
                { field_key: 'last_name',  label: 'Apellido',           placeholder: 'Tu apellido',             is_required: 0, is_enabled: 1 },
                { field_key: 'email',      label: 'Correo electrónico', placeholder: 'tu@correo.com',           is_required: 1, is_enabled: 1 },
                { field_key: 'phone',      label: 'Teléfono',           placeholder: '+56 9 1234 5678',         is_required: 0, is_enabled: 1 },
                { field_key: 'notes',      label: 'Notas (opcional)',   placeholder: 'Algo que debas saber...', is_required: 0, is_enabled: 1 },
            ];
        },

        validate: function () {
            $('#obwp-form-fields .obwp-error').remove();
            var valid = true;
            $('#obwp-form-fields [required]').each(function () {
                var el = $(this);
                var isEmpty = el.attr('type') === 'checkbox' ? !el.is(':checked') : !el.val().trim();
                if (isEmpty) {
                    el.after('<div class="obwp-error" role="alert">' + (cfg.strings.required_field || 'Este campo es obligatorio.') + '</div>');
                    if (valid) el.focus();
                    valid = false;
                }
            });
            var $emailEl = $('#obwp-email');
            if ($emailEl.length && $emailEl.val() && !isValidEmail($emailEl.val())) {
                $emailEl.after('<div class="obwp-error" role="alert">' + (cfg.strings.invalid_email || 'Correo no válido.') + '</div>');
                valid = false;
            }
            var $phoneEl = $('#obwp-phone');
            if ($phoneEl.length && $phoneEl.val() && !isValidPhone($phoneEl.val())) {
                $phoneEl.after('<div class="obwp-error" role="alert">' + (cfg.strings.invalid_phone || 'Ingresa un teléfono válido.') + '</div>');
                valid = false;
            }
            return valid;
        },

        submit: function () {
            if (State.submitting) return;
            if (!this.validate()) return;

            State.submitting = true;
            Api.track('booking_submit', { service_id: State.serviceId, date: State.date, payment_mode: cfg.paymentMode });

            var payload = {
                service_id      : State.serviceId,
                start_at        : State.startAt,
                resource_id     : State.resourceId,
                _obwp_hp        : '',
                _obwp_loaded_at : State.loadedAt,
                _price_check    : State.servicePriceMinor,
                _client_ref     : State.clientRef || (Date.now() + '-' + Math.random().toString(36).substr(2, 9))
            };
            State.clientRef = payload._client_ref;

            $('#obwp-form-fields input, #obwp-form-fields textarea').each(function () {
                var n = $(this).attr('name');
                if (n) payload[n] = $(this).val().trim();
            });

            var $btn = $('.obwp-btn-next[data-to="4"]').prop('disabled', true).text(cfg.strings.loading || 'Procesando...');

            Api.post('bookings', payload)
                .done(function (res) {
                    State.submitting = false;
                    Form.updateActionLabel();
                    $btn.prop('disabled', false);

                    if (res.success && res.booking) {
                        State.bookingId       = res.booking_id;
                        State.cancelToken     = res.booking.cancel_token || null;
                        State.rescheduleToken = res.booking.reschedule_token || null;
                        State.bookingToken    = (res.payment && res.payment.token) || null;
                        State.bookingSnapshot = res.booking;
                        Session.clear();
                        Baseline.markFlowCompleted();

                        if (res.payment && res.payment.required) {
                            Payment.initiate(res.booking_id, res.booking);
                        } else {
                            Steps.show(5);
                            Api.track('booking_success', { booking_id: res.booking_id, status: res.booking.status, payment_status: res.booking.payment_status });
                            Confirm.render(res.booking);
                        }
                    } else {
                        Form._showError(res.error || cfg.strings.error_generic);
                        if (res.error && res.error.indexOf('disponible') >= 0) Steps.show(2);
                    }
                })
                .fail(function (xhr) {
                    State.submitting = false;
                    Form.updateActionLabel();
                    $btn.prop('disabled', false);

                    // 409 = slot taken in a race or price changed
                    if (xhr.status === 409) {
                        try {
                            var body = JSON.parse(xhr.responseText);
                            if (body && body.price_changed) {
                                State.startAt    = null;
                                State.endAt      = null;
                                State.resourceId = null;
                                State.time       = null;
                                Steps.show(1);
                                Services.load();
                                Form._showError(body.error || 'El precio del servicio cambio. Recarga la pagina y revisa el nuevo precio antes de reservar.');
                                return;
                            }
                        } catch (e) {}
                        var notice = cfg.strings.slot_taken || 'Ese horario ya fue reservado. Por favor elige otro.';
                        Api.track('slot_conflict', { service_id: State.serviceId, date: State.date });
                        State.startAt    = null;
                        State.endAt      = null;
                        State.resourceId = null;
                        State.time       = null;
                        Steps.show(2);
                        Calendar.render();
                        TimeSlots.load(State.date, { notice: notice });
                        return;
                    }

                    Form._showError(Api.errorMsg(xhr));
                });
        },

        _showError: function (msg) {
            $('#obwp-form-fields .obwp-form-error').remove();
            $('#obwp-form-fields').prepend('<div class="obwp-error obwp-form-error" role="alert">' + escapeHtml(msg) + '</div>');
        }
    };

    // =========================================================================
    // Module: Payment
    // =========================================================================
    var Payment = {
        initiate: function (bookingId, booking) {
            Steps.show(4);
            var $panel = $('[data-panel="4"] #obwp-payment-panel');
            if (!State.bookingToken) {
                $panel.html(Payment._renderFailed(cfg.strings.error_generic || 'No pudimos iniciar el pago. Intenta crear la reserva nuevamente.'));
                Api.track('payment_token_missing', { booking_id: bookingId, gateway: cfg.gateway });
                return;
            }

            $panel.html(
                '<div class="obwp-state obwp-state--redirecting" role="status">' +
                '<p class="obwp-loading">' + escapeHtml(cfg.strings.payment_redirecting || 'Redirigiendo al proveedor de pago…') + '</p>' +
                '<p class="obwp-note">' + escapeHtml(cfg.strings.payment_redirect || 'Te llevaremos al proveedor de pago para completar la operación.') + '</p>' +
                '</div>'
            );
            Api.track('payment_start', { booking_id: bookingId, gateway: cfg.gateway });

            Api.post('payments/create', {
                booking_id   : bookingId,
                gateway      : cfg.gateway,
                public_token : State.bookingToken || ''
            })
                .done(function (res) {
                    if (res.error) {
                        $panel.html(Payment._renderFailed(res.error));
                        return;
                    }
                    if (res.checkout && res.checkout.redirect_url) {
                        window.location.href = res.checkout.redirect_url;
                        return;
                    }
                    // Manual / no-redirect gateway: show confirmation with pending note
                    if (res.booking) State.bookingSnapshot = res.booking;
                    var finalBooking = res.booking || booking || State.bookingSnapshot;
                    Steps.show(5);
                    Confirm.render(finalBooking, {
                        title    : res.type === 'manual_pending' ? (cfg.strings.manual_pending_title || 'Tu reserva quedó registrada') : '',
                        subtitle : res.type === 'manual_pending'
                            ? (cfg.strings.manual_pending_note || 'Seleccionaste pago manual. La reserva quedará confirmada una vez que validemos tu pago.')
                            : (res.type === 'manual' ? (cfg.strings.manual_note || 'Pago manual seleccionado. Tu reserva quedó registrada.') : '')
                    });
                })
                .fail(function (xhr) {
                    $panel.html(Payment._renderFailed(Api.errorMsg(xhr)));
                });
        },

        /** Render a payment-failed panel with a retry-from-start button. */
        _renderFailed: function (msg) {
            return '<div class="obwp-state obwp-state--failed" role="alert">' +
                '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                '<h2>' + escapeHtml(cfg.strings.payment_failed || 'El pago no se completó') + '</h2>' +
                '<p>' + escapeHtml(msg) + '</p>' +
                '<button class="obwp-btn obwp-btn--primary" id="obwp-retry-btn">' +
                escapeHtml(cfg.strings.retry || 'Volver a intentar') + '</button>' +
                '</div>';
        },

        /** Handle the return URL from a payment gateway. */
        verifyResult: function (paymentResult, token) {
            Steps.show(5);
            $('[data-panel="5"]').html('<p class="obwp-loading">' + (cfg.strings.loading || 'Cargando...') + '</p>');

            Api.get('bookings/public/' + token)
                .done(function (res) {
                    var booking = res.booking || null;
                    if (!booking) {
                        Confirm.renderError(cfg.strings.error_generic || 'No pudimos verificar el estado de tu pago.');
                        return;
                    }

                    State.bookingSnapshot   = booking;
                    State.bookingId         = booking.id;
                    State.serviceName       = booking.service_name  || '';
                    State.serviceSlug       = booking.service_slug  || '';
                    State.servicePrice      = booking.service_price || '';
                    State.serviceCurrency   = booking.currency       || '';
                    State.startAt           = booking.start_at       || null;
                    State.endAt             = booking.end_at         || null;
                    State.date              = booking.start_at ? booking.start_at.slice(0, 10) : null;
                    State.time              = booking.start_at ? booking.start_at.slice(11, 16) : null;
                    Api.track('payment_return', { payment_result: paymentResult, booking_id: booking.id || '' });

                    if (booking.payment_status === 'paid' && booking.status === 'confirmed') {
                        Session.clear();
                        Api.track('booking_success', { booking_id: booking.id, status: booking.status, payment_status: booking.payment_status });
                        Confirm.render(booking, { subtitle: cfg.strings.payment_success || 'Pago procesado correctamente. Tu reserva está confirmada.' });
                        return;
                    }

                    // Payment confirmed but booking still processing — show pending instead of failure.
                    if (booking.payment_status === 'paid' && booking.status !== 'confirmed') {
                        Payment._renderReturnState('pending', booking);
                        return;
                    }

                    if (paymentResult === 'success' && booking.payment_status === 'pending') {
                        Payment._renewPendingHold(token, booking);
                        Payment._renderReturnState('pending', booking);
                        return;
                    }

                    if (booking.payment_status === 'pending') {
                        Payment._renewPendingHold(token, booking);
                    }

                    Payment._renderReturnState(paymentResult, booking);
                })
                .fail(function (xhr) {
                    Confirm.renderError(Api.errorMsg(xhr));
                });
        },

        _renderReturnState: function (paymentResult, booking) {
            var isPending = paymentResult === 'pending' || booking.payment_status === 'pending';

            if (isPending) {
                $('[data-panel="5"]').html(
                    '<div class="obwp-state obwp-state--pending" role="status">' +
                    '<div class="obwp-state-icon" aria-hidden="true">&#9202;</div>' +
                    '<h2>' + escapeHtml(cfg.strings.payment_pending_title || 'Pago en proceso') + '</h2>' +
                    '<p>' + escapeHtml(cfg.strings.payment_pending_note || 'Tu pago está siendo verificado por el proveedor. Esto puede tardar unos minutos.') + '</p>' +
                    '<p class="obwp-note">' + escapeHtml(cfg.strings.payment_pending_what_happens || 'No se ha cobrado nada hasta que el pago se confirme. Recibirás un correo con la confirmación.') + '</p>' +
                    '<p class="obwp-note">' + escapeHtml(cfg.strings.payment_pending_status || 'Estado de la reserva: pendiente de confirmación de pago.') + '</p>' +
                    '<button class="obwp-btn obwp-btn--secondary" id="obwp-check-status">' +
                    escapeHtml(cfg.strings.check_status || 'Verificar estado') + '</button>' +
                    '</div>'
                );
                $(document).off('click.obwpCheckStatus').on('click.obwpCheckStatus', '#obwp-check-status', function (e) {
                    e.preventDefault();
                    var token = new URLSearchParams(window.location.search).get('obwp_token');
                    if (token) {
                        Payment.verifyResult('success', token);
                    }
                });
            } else {
                $('[data-panel="5"]').html(
                    '<div class="obwp-state obwp-state--failed" role="alert">' +
                    '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                    '<h2>' + escapeHtml(cfg.strings.payment_failed || 'El pago no se completó') + '</h2>' +
                    '<p>' + escapeHtml(cfg.strings.payment_no_charge || 'No se realizó ningún cobro.') + '</p>' +
                    '<p class="obwp-note">' + escapeHtml(cfg.strings.payment_retry_help || 'Tu reserva sigue registrada. Puedes intentar pagar de nuevo.') + '</p>' +
                    '<button class="obwp-btn obwp-btn--primary" id="obwp-retry-btn">' +
                    escapeHtml(cfg.strings.retry || 'Volver a intentar') + '</button>' +
                    '</div>'
                );
            }
        },

        _renewPendingHold: function (token, booking) {
            if (!token || !booking || booking.payment_status !== 'pending') {
                return;
            }

            Api.post('bookings/public/' + token + '/renew-hold', {})
                .done(function (res) {
                    if (res && res.success && res.renewed) {
                        Api.track('payment_hold_renewed', { booking_id: booking.id || '', expires_at: res.expires_at || '' });
                    }
                })
                .fail(function () {
                    Api.track('payment_hold_renewal_failed', { booking_id: booking.id || '' });
                });
        }
    };

    // =========================================================================
    // Module: Confirm
    // =========================================================================
    var Confirm = {
        render: function (booking, options) {
            options = options || {};
            booking = booking || {};
            var isPendingPayment = booking.status === 'pending' && booking.payment_status === 'pending';
            var isManualPending  = !!(options.subtitle && options.subtitle.length);

            var iconClass = isPendingPayment || isManualPending ? 'obwp-state--pending' : 'obwp-state--success';
            var icon = isPendingPayment || isManualPending
                ? '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>'
                : '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>';
            var title     = options.title ||
                (isPendingPayment
                    ? (cfg.strings.pending_title   || 'Tu reserva está registrada')
                    : (cfg.strings.booking_success || 'Tu reserva está confirmada'));

            var noteText = options.subtitle
                || (isPendingPayment
                    ? (cfg.strings.pending_note || 'La reserva se confirmará una vez que validemos el pago. Te enviaremos un correo.')
                    : '');

            var html = '<div class="obwp-booking-detail">';

            html += '<div class="obwp-state ' + iconClass + '">';
            html += '<div class="obwp-state-icon" aria-hidden="true">' + icon + '</div>';
            html += '<h2 class="obwp-panel-title">' + escapeHtml(title) + '</h2>';
            if (noteText) {
                html += '<p class="obwp-note">' + escapeHtml(noteText) + '</p>';
            }
            html += '</div>';

            html += '<div class="obwp-booking-detail-card">';
            if (State.serviceName) {
                html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.summary_service || 'Servicio') + '</span><span class="obwp-detail-value">' + escapeHtml(State.serviceName) + '</span></div>';
            }
            if (State.date || State.time) {
                html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.summary_datetime || 'Fecha y hora') + '</span><span class="obwp-detail-value">' + escapeHtml(formatDateHuman(State.date)) + (State.time ? ' &middot; ' + escapeHtml(State.time) : '') + '</span></div>';
            }
            if (State.servicePriceMinor > 0) {
                if (cfg.paymentMode === 'deposit' && booking.price_due_now_formatted) {
                    var laterPct = 100 - cfg.depositPercent;
                    html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.pay_now || 'Anticipo') + ' (' + cfg.depositPercent + '%)</span><span class="obwp-detail-value">' + escapeHtml(booking.currency || State.serviceCurrency) + ' ' + escapeHtml(booking.price_due_now_formatted) + '</span></div>';
                    if (booking.price_later_formatted) {
                        html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.pay_later || 'En cita') + ' (' + laterPct + '%)</span><span class="obwp-detail-value">' + escapeHtml(booking.currency || State.serviceCurrency) + ' ' + escapeHtml(booking.price_later_formatted) + '</span></div>';
                    }
                    html += '<div class="obwp-detail-row obwp-detail-row--total"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.total || 'Total') + '</span><span class="obwp-detail-value">' + escapeHtml(State.serviceCurrency) + ' ' + escapeHtml(State.servicePrice) + '</span></div>';
                } else {
                    html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.total || 'Total') + '</span><span class="obwp-detail-value">' + escapeHtml(State.serviceCurrency) + ' ' + escapeHtml(State.servicePrice) + '</span></div>';
                }
            }
            if (booking.id) {
                html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.booking_ref || 'Referencia') + '</span><span class="obwp-detail-value">#' + booking.id + '</span></div>';
            }
            html += '</div>';

            html += '<div class="obwp-confirmation-actions">';
            if (booking.cancel_token) {
                html += '<a href="?obwp_cancel=' + escapeHtml(booking.cancel_token) + '" class="obwp-btn obwp-btn--secondary">' +
                    escapeHtml(cfg.strings.cancel || 'Cancelar') + '</a>';
            }
            if (booking.reschedule_token) {
                html += '<a href="?obwp_reschedule=' + escapeHtml(booking.reschedule_token) + '" class="obwp-btn obwp-btn--primary">' +
                    escapeHtml(cfg.strings.reschedule || 'Reprogramar') + '</a>';
            }
            html += '</div>';

            html += '</div>';

            if (State.mode === 'reschedule') {
                $('#obwp-booking-app').html('<div class="obwp-confirmation" id="obwp-confirmation" aria-live="assertive">' + html + '</div>');
            } else {
                $('[data-panel="5"]').html(html);
            }
        },

        renderError: function (msg) {
            $('[data-panel="5"]').html(
                '<div class="obwp-state obwp-state--failed" role="alert">' +
                '<p class="obwp-error">' + escapeHtml(msg) + '</p>' +
                '</div>'
            );
        }
    };

    // =========================================================================
    // Module: TokenFlow  (cancel / reschedule / payment return from URL)
    // =========================================================================
    var TokenFlow = {
        /**
         * Returns true if a token-based action was detected in the URL.
         * When true the normal booking flow should not start.
         */
        handle: function () {
            var params          = new URLSearchParams(window.location.search);
            var cancelToken     = params.get('obwp_cancel');
            var rescheduleToken = params.get('obwp_reschedule');
            var confirmToken    = params.get('obwp_confirm');
            var paymentResult   = params.get('obwp_payment');
            var paymentToken    = params.get('obwp_token');

            if (cancelToken) {
                this._showCancelModal(cancelToken);
                return true;
            }

            if (rescheduleToken) {
                this._startReschedule(rescheduleToken);
                return true;
            }

            if (confirmToken) {
                this._startConfirmAttendance(confirmToken);
                return true;
            }

            if (paymentResult === 'success' || paymentResult === 'cancel' || paymentResult === 'pending') {
                if (!paymentToken) {
                    Steps.show(5);
                    Confirm.renderError(cfg.strings.error_generic || 'No pudimos verificar el estado de tu pago.');
                } else {
                    Payment.verifyResult(paymentResult, paymentToken);
                }
                return true;
            }

            // obwp_token alone (no payment result) = view booking detail link
            if (paymentToken) {
                this._showBookingDetails(paymentToken);
                return true;
            }

            return false;
        },

        _showCancelModal: function (token) {
            var $app = $('#obwp-booking-app');
            $app.html(
                '<div class="obwp-token-action">' +
                '<h2>' + escapeHtml(cfg.strings.cancel_confirm_title || 'Cancelar reserva') + '</h2>' +
                '<p>' + escapeHtml(cfg.strings.cancel_confirm || '¿Estás seguro de que deseas cancelar esta reserva? Esta acción no se puede deshacer.') + '</p>' +
                '<button class="obwp-btn obwp-btn--primary" id="obwp-confirm-cancel">' +
                escapeHtml(cfg.strings.yes_cancel || 'Sí, cancelar') + '</button> ' +
                '<a href="' + escapeHtml(window.location.pathname) + '" class="obwp-btn obwp-btn--secondary">' +
                escapeHtml(cfg.strings.no || 'No, volver') + '</a>' +
                '</div>'
            );

            $('#obwp-confirm-cancel').on('click', function () {
                var $btn = $(this).prop('disabled', true).text(cfg.strings.loading || 'Procesando...');
                Api.post('bookings/cancel/' + token, {})
                    .done(function (res) {
                        if (res.success) {
                            $app.html('<div class="obwp-token-action obwp-state obwp-state--success">' +
                                '<div class="obwp-state-icon" aria-hidden="true">&#10003;</div>' +
                                '<h2>' + escapeHtml(cfg.strings.cancel_success || 'Tu reserva fue cancelada.') + '</h2>' +
                                '<p class="obwp-note">' + escapeHtml(cfg.strings.cancel_confirmed_note || 'Recibirás un correo confirmando la cancelación.') + '</p>' +
                                '</div>');
                        } else {
                            $app.html('<div class="obwp-token-action obwp-state obwp-state--failed">' +
                                '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                                '<h2>' + escapeHtml(cfg.strings.cancel_failed || 'No se pudo cancelar') + '</h2>' +
                                '<p class="obwp-error">' + escapeHtml(res.error || cfg.strings.error_generic) + '</p>' +
                                '<p class="obwp-note">' + escapeHtml(cfg.strings.cancel_help || 'Si el enlace expiró, contacta al negocio directamente.') + '</p>' +
                                '</div>');
                        }
                    })
                    .fail(function (xhr) {
                        $app.html('<div class="obwp-token-action obwp-state obwp-state--failed">' +
                            '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                            '<h2>' + escapeHtml(cfg.strings.cancel_failed || 'No se pudo cancelar') + '</h2>' +
                            '<p class="obwp-error">' + escapeHtml(Api.errorMsg(xhr)) + '</p>' +
                            '<p class="obwp-note">' + escapeHtml(cfg.strings.cancel_help || 'Si el enlace expiró, contacta al negocio directamente.') + '</p>' +
                            '</div>');
                    });
            });
        },

        _startReschedule: function (token) {
            var $app = $('#obwp-booking-app');
            State.mode = 'reschedule';
            $app.html('<div class="obwp-token-action"><p class="obwp-loading">' + (cfg.strings.loading || 'Cargando...') + '</p></div>');

            Api.get('bookings/public/' + token)
                .done(function (res) {
                    if (!res.booking) {
                        $app.html('<div class="obwp-token-action"><p class="obwp-error">' + escapeHtml(cfg.strings.error_generic) + '</p></div>');
                        return;
                    }

                    var booking = res.booking;
                    State.bookingId       = booking.id;
                    State.bookingSnapshot = booking;
                    State.serviceName     = booking.service_name  || '';
                    State.serviceSlug     = booking.service_slug  || '';
                    State.servicePrice    = booking.service_price || '';
                    State.serviceCurrency = booking.currency       || '';
                    State.rescheduleToken = token;
                    State.resourceId      = booking.resource_id    || null;
                    State.startAt         = booking.start_at       || null;
                    State.endAt           = booking.end_at         || null;
                    State.date            = booking.start_at ? booking.start_at.slice(0, 10) : null;
                    State.time            = booking.start_at ? booking.start_at.slice(11, 16) : null;

                    if (!booking.can_reschedule) {
                        $app.html('<div class="obwp-token-action"><p class="obwp-error">' +
                            escapeHtml(cfg.strings.reschedule_unavailable || 'Esta reserva ya no se puede reprogramar.') + '</p></div>');
                        return;
                    }

                    TokenFlow._renderRescheduleApp(booking);
                    Calendar.init();
                })
                .fail(function (xhr) {
                    var msg;
                    if (xhr.status === 404) {
                        msg = cfg.strings.reschedule_token_expired || 'Este enlace ya no es válido. Es posible que haya expirado o que la reserva haya cambiado de estado.';
                    } else if (xhr.status === 403) {
                        msg = cfg.strings.reschedule_token_wrong_type || 'Este enlace no permite reprogramar esta reserva.';
                    } else {
                        msg = Api.errorMsg(xhr);
                    }
                    $app.html('<div class="obwp-token-action"><p class="obwp-error">' + escapeHtml(msg) + '</p></div>');
                });
        },

        _renderRescheduleApp: function (booking) {
            var $app = $('#obwp-booking-app');
            var when = booking.start_at ? booking.start_at.replace('T', ' ') : '';
            $app.html(
                '<div class="obwp-panel obwp-panel--active" data-panel="reschedule">' +
                    '<h2 class="obwp-panel-title">' + escapeHtml(cfg.strings.reschedule_title || 'Reprograma tu reserva') + '</h2>' +
                    '<p class="obwp-panel-subtitle">' + escapeHtml(booking.service_name || '') + '</p>' +
                    '<p class="obwp-field-label">' + escapeHtml(cfg.strings.current_booking || 'Horario actual: ') + escapeHtml(when) + '</p>' +
                    '<div class="obwp-date-picker-section" aria-label="Calendario"></div>' +
                    '<div class="obwp-time-picker-section" id="obwp-time-section" style="display:none;">' +
                        '<label class="obwp-field-label">' + escapeHtml(cfg.strings.select_time || 'Selecciona un horario') + '</label>' +
                        '<div id="obwp-time-slots" class="obwp-time-grid" role="list" aria-live="polite"></div>' +
                    '</div>' +
                    '<div class="obwp-nav-buttons">' +
                        '<button class="obwp-btn obwp-btn--primary" id="obwp-confirm-reschedule">' +
                        escapeHtml(cfg.strings.reschedule_confirm || 'Confirmar nueva fecha') + '</button> ' +
                        '<a class="obwp-btn obwp-btn--secondary" href="' + escapeHtml(window.location.pathname) + '">' +
                        escapeHtml(cfg.strings.back_home || 'Volver') + '</a>' +
                    '</div>' +
                '</div>'
            );

            $(document).off('click.obwpReschedule').on('click.obwpReschedule', '#obwp-confirm-reschedule', function (e) {
                e.preventDefault();
                TokenFlow._submitReschedule();
            });
        },

        _submitReschedule: function () {
            if (!State.rescheduleToken || !State.startAt || State.submitting) return;
            State.submitting = true;

            Api.post('bookings/reschedule/' + State.rescheduleToken, {
                start_at    : State.startAt,
                resource_id : State.resourceId
            })
                .done(function (res) {
                    State.submitting = false;
                    if (res.success && res.booking) {
                        State.bookingSnapshot = res.booking;
                        Confirm.render(res.booking, { title: cfg.strings.reschedule_success || 'Tu reserva fue reprogramada' });
                    } else {
                        $('#obwp-booking-app').html('<div class="obwp-token-action"><p class="obwp-error">' +
                            escapeHtml(res.error || cfg.strings.error_generic) + '</p></div>');
                    }
                })
                .fail(function (xhr) {
                    State.submitting = false;
                    // 409: the newly chosen slot is also taken — reload slots
                    if (xhr.status === 409) {
                        var notice = cfg.strings.slot_taken || 'Ese horario ya fue reservado. Por favor elige otro.';
                        State.startAt = null;
                        TimeSlots.load(State.date, { notice: notice });
                        return;
                    }
                    var failMsg;
                    if (xhr.status === 404) {
                        failMsg = cfg.strings.reschedule_failed_expired || 'Este enlace ya no es válido. La reserva puede haber cambiado de estado.';
                    } else if (xhr.status === 403) {
                        failMsg = cfg.strings.reschedule_failed_forbidden || 'No tienes permiso para realizar esta acción.';
                    } else {
                        failMsg = cfg.strings.reschedule_failed_generic || 'No se pudo reprogramar. Por favor intenta de nuevo o contacta al negocio.';
                    }
                    $('#obwp-booking-app').html('<div class="obwp-token-action"><p class="obwp-error">' +
                        escapeHtml(failMsg) + '</p></div>');
                });
        },

        _showBookingDetails: function (token) {
            var $app = $('#obwp-booking-app');
            $app.html('<div class="obwp-token-action"><p class="obwp-loading">' + (cfg.strings.loading || 'Cargando...') + '</p></div>');

            Api.get('bookings/public/' + token)
                .done(function (res) {
                    var booking = res.booking || null;
                    if (!booking) {
                        $app.html(
                            '<div class="obwp-token-action obwp-state obwp-state--failed" role="alert">' +
                            '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                            '<h2>' + escapeHtml(cfg.strings.booking_not_found || 'No encontramos esta reserva.') + '</h2>' +
                            '<p class="obwp-note">' + escapeHtml(cfg.strings.booking_link_expired || 'El enlace puede haber expirado o la reserva fue eliminada.') + '</p>' +
                            '</div>'
                        );
                        return;
                    }

                    var isConfirmed = booking.status === 'confirmed';
                    var isCancelled = booking.status === 'cancelled';
                    var statusClass = isConfirmed ? 'obwp-state--success' : (isCancelled ? 'obwp-state--failed' : 'obwp-state--pending');
                    var icon = isConfirmed
                        ? '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>'
                        : (isCancelled
                            ? '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>'
                            : '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>');
                    var statusLabel = isConfirmed
                        ? (cfg.strings.booking_success  || 'Tu reserva está confirmada')
                        : (isCancelled
                            ? (cfg.strings.status_cancelled || 'Reserva cancelada')
                            : (cfg.strings.pending_title    || 'Tu reserva está registrada'));
                    var statusNote = isConfirmed ? '' : (!isCancelled
                        ? (cfg.strings.pending_note || 'La reserva se confirmará una vez que validemos el pago. Te enviaremos un correo.')
                        : '');

                    var startAt   = booking.start_at || '';
                    var dateStr   = startAt ? startAt.slice(0, 10)  : '';
                    var timeStr   = startAt ? startAt.slice(11, 16) : '';
                    var dateHuman = formatDateHuman(dateStr);

                    var html = '<div class="obwp-booking-detail">';

                    html += '<div class="obwp-state ' + statusClass + '" role="status">';
                    html += '<div class="obwp-state-icon">' + icon + '</div>';
                    html += '<h2 class="obwp-panel-title">' + escapeHtml(statusLabel) + '</h2>';
                    if (statusNote) html += '<span class="obwp-note">' + escapeHtml(statusNote) + '</span>';
                    html += '</div>';

                    html += '<div class="obwp-booking-detail-card">';
                    if (booking.service_name) {
                        html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.summary_service || 'Servicio') + '</span><span class="obwp-detail-value">' + escapeHtml(booking.service_name) + '</span></div>';
                    }
                    if (dateHuman || timeStr) {
                        html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.summary_datetime || 'Fecha y hora') + '</span><span class="obwp-detail-value">' + escapeHtml(dateHuman) + (timeStr ? ' &middot; ' + escapeHtml(timeStr) : '') + '</span></div>';
                    }
                    if (booking.customer_name) {
                        html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.patient || 'Paciente') + '</span><span class="obwp-detail-value">' + escapeHtml(booking.customer_name) + '</span></div>';
                    }
                    if (booking.id) {
                        html += '<div class="obwp-detail-row"><span class="obwp-detail-label">' + escapeHtml(cfg.strings.booking_ref || 'Referencia') + '</span><span class="obwp-detail-value obwp-detail-ref">#' + booking.id + '</span></div>';
                    }
                    html += '</div>';

                    var pendingPayment = !isCancelled && booking.payment_status === 'pending'
                        && booking.payment_requires_external_redirect
                        && Array.isArray(booking.available_payment_gateways)
                        && booking.available_payment_gateways.length > 0;

                    if (pendingPayment) {
                        html += '<div class="obwp-confirmation-actions" data-pay-actions>';
                        if (booking.available_payment_gateways.length === 1) {
                            var gw = booking.available_payment_gateways[0];
                            html += '<button type="button" class="obwp-btn obwp-btn--primary" data-pay-now="' + escapeHtml(gw.key) + '">' +
                                escapeHtml(cfg.strings.pay_now || 'Pagar ahora') + ' · ' + escapeHtml(gw.label) + '</button>';
                        } else {
                            html += '<p class="obwp-note">' + escapeHtml(cfg.strings.choose_gateway || 'Elige cómo pagar:') + '</p>';
                            booking.available_payment_gateways.forEach(function (gw) {
                                html += '<button type="button" class="obwp-btn obwp-btn--primary" data-pay-now="' + escapeHtml(gw.key) + '">' +
                                    escapeHtml(gw.label) + '</button> ';
                            });
                        }
                        html += '</div>';
                    }

                    if (!isCancelled && (booking.cancel_token || booking.reschedule_token)) {
                        html += '<div class="obwp-confirmation-actions">';
                        if (booking.reschedule_token) {
                            html += '<a href="?obwp_reschedule=' + escapeHtml(booking.reschedule_token) + '" class="obwp-btn obwp-btn--secondary">' +
                                escapeHtml(cfg.strings.reschedule || 'Reprogramar') + '</a>';
                        }
                        if (booking.cancel_token) {
                            html += '<a href="?obwp_cancel=' + escapeHtml(booking.cancel_token) + '" class="obwp-btn obwp-btn--secondary">' +
                                escapeHtml(cfg.strings.cancel || 'Cancelar reserva') + '</a>';
                        }
                        html += '</div>';
                    }

                    html += '</div>';
                    $app.html(html);

                    // "Pagar ahora" → POST /payments/create with selected gateway, then
                    // redirect to the gateway's hosted checkout (Webpay, MercadoPago, etc).
                    $app.off('click.obwpPayNow').on('click.obwpPayNow', '[data-pay-now]', function () {
                        var $btn = $(this);
                        var gateway = $btn.attr('data-pay-now');
                        $('[data-pay-actions] button').prop('disabled', true);
                        $btn.text(cfg.strings.payment_redirecting || 'Redirigiendo al proveedor de pago…');
                        Api.post('payments/create', {
                            booking_id   : booking.id,
                            gateway      : gateway,
                            public_token : token
                        })
                            .done(function (res) {
                                if (res && res.checkout && res.checkout.redirect_url) {
                                    window.location.href = res.checkout.redirect_url;
                                    return;
                                }
                                $('[data-pay-actions]').html('<p class="obwp-error">' +
                                    escapeHtml((res && res.error) || cfg.strings.error_generic || 'No pudimos iniciar el pago.') + '</p>');
                            })
                            .fail(function (xhr) {
                                $('[data-pay-actions]').html('<p class="obwp-error">' +
                                    escapeHtml(Api.errorMsg(xhr)) + '</p>');
                            });
                    });
                })
                .fail(function (xhr) {
                    var msg = xhr.status === 404
                        ? (cfg.strings.booking_not_found || 'No encontramos esta reserva. El enlace puede haber expirado.')
                        : Api.errorMsg(xhr);
                    $app.html(
                        '<div class="obwp-token-action obwp-state obwp-state--failed" role="alert">' +
                        '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                        '<h2>' + escapeHtml(msg) + '</h2>' +
                        '</div>'
                    );
                });
        },

        _startConfirmAttendance: function (token) {
            var $app = $('#obwp-booking-app');
            $app.html('<div class="obwp-token-action"><p class="obwp-loading">' + (cfg.strings.loading || 'Cargando...') + '</p></div>');

            Api.get('bookings/confirm-attendance/' + token)
                .done(function (res) {
                    if (res.already_confirmed) {
                        var b = res.booking || {};
                        var dateStr = b.start_at ? b.start_at.replace('T', ' ') : '';
                        $app.html('<div class="obwp-token-action obwp-state obwp-state--success">' +
                            '<div class="obwp-state-icon" aria-hidden="true">&#10003;</div>' +
                            '<h2>' + escapeHtml(cfg.strings.attendance_already_confirmed || 'Ya habías confirmado tu asistencia.') + '</h2>' +
                            '<p>' + escapeHtml(dateStr) + '</p>' +
                            '</div>');
                        return;
                    }

                    if (!res.can_confirm_attendance) {
                        $app.html('<div class="obwp-token-action obwp-state obwp-state--failed">' +
                            '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                            '<h2>' + escapeHtml(cfg.strings.attendance_unavailable || 'No se puede confirmar asistencia') + '</h2>' +
                            '</div>');
                        return;
                    }

                    var b = res.booking || {};
                    var dateStr = b.start_at ? b.start_at.replace('T', ' ') : '';
                    $app.html(
                        '<div class="obwp-token-action">' +
                        '<h2>' + escapeHtml(cfg.strings.attendance_title || 'Confirmar asistencia') + '</h2>' +
                        '<p>' + escapeHtml(b.service_name || '') + '</p>' +
                        '<p><strong>' + escapeHtml(dateStr) + '</strong></p>' +
                        '<button class="obwp-btn obwp-btn--primary" id="obwp-confirm-attendance-btn">' +
                        escapeHtml(cfg.strings.attendance_confirm_btn || 'Sí, confirmar asistencia') + '</button> ' +
                        '<a href="' + escapeHtml(window.location.pathname) + '" class="obwp-btn obwp-btn--secondary">' +
                        escapeHtml(cfg.strings.no || 'No, volver') + '</a>' +
                        '</div>'
                    );

                    $('#obwp-confirm-attendance-btn').on('click', function () {
                        var $btn = $(this).prop('disabled', true).text(cfg.strings.loading || 'Procesando...');
                        Api.post('bookings/confirm-attendance/' + token, {})
                            .done(function (postRes) {
                                if (postRes.already_confirmed) {
                                    $app.html('<div class="obwp-token-action obwp-state obwp-state--success">' +
                                        '<div class="obwp-state-icon" aria-hidden="true">&#10003;</div>' +
                                        '<h2>' + escapeHtml(cfg.strings.attendance_already_confirmed || 'Ya habías confirmado tu asistencia.') + '</h2>' +
                                        '</div>');
                                    return;
                                }
                                if (postRes.success) {
                                    var postB = postRes.booking || {};
                                    var postDateStr = postB.start_at ? postB.start_at.replace('T', ' ') : '';
                                    $app.html('<div class="obwp-token-action obwp-state obwp-state--success">' +
                                        '<div class="obwp-state-icon" aria-hidden="true">&#10003;</div>' +
                                        '<h2>' + escapeHtml(cfg.strings.attendance_success || '¡Confirmado! Te esperamos el ') + escapeHtml(postDateStr) + '.</h2>' +
                                        '</div>');
                                } else {
                                    $app.html('<div class="obwp-token-action obwp-state obwp-state--failed">' +
                                        '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                                        '<h2>' + escapeHtml(cfg.strings.attendance_failed || 'No se pudo confirmar') + '</h2>' +
                                        '<p class="obwp-error">' + escapeHtml(postRes.error || cfg.strings.error_generic) + '</p>' +
                                        '</div>');
                                }
                            })
                            .fail(function (xhr) {
                                var msg;
                                if (xhr.status === 404) {
                                    msg = cfg.strings.attendance_invalid || 'Este enlace ya no es válido.';
                                } else if (xhr.status === 410) {
                                    msg = cfg.strings.attendance_past || 'La cita ya ocurrió.';
                                } else if (xhr.status === 409) {
                                    msg = cfg.strings.attendance_not_confirmed || 'La reserva aún no ha sido confirmada.';
                                } else {
                                    msg = Api.errorMsg(xhr);
                                }
                                $app.html('<div class="obwp-token-action obwp-state obwp-state--failed">' +
                                    '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                                    '<h2>' + escapeHtml(cfg.strings.attendance_failed || 'No se pudo confirmar') + '</h2>' +
                                    '<p class="obwp-error">' + escapeHtml(msg) + '</p>' +
                                    '</div>');
                            });
                    });
                })
                .fail(function (xhr) {
                    var msg;
                    if (xhr.status === 404) {
                        msg = cfg.strings.attendance_invalid || 'Este enlace ya no es válido.';
                    } else if (xhr.status === 410) {
                        msg = cfg.strings.attendance_past || 'La cita ya ocurrió.';
                    } else if (xhr.status === 409) {
                        msg = cfg.strings.attendance_not_confirmed || 'La reserva aún no ha sido confirmada.';
                    } else {
                        msg = Api.errorMsg(xhr);
                    }
                    $app.html('<div class="obwp-token-action obwp-state obwp-state--failed">' +
                        '<div class="obwp-state-icon" aria-hidden="true">&#9888;</div>' +
                        '<h2>' + escapeHtml(cfg.strings.attendance_failed || 'No se pudo confirmar') + '</h2>' +
                        '<p class="obwp-error">' + escapeHtml(msg) + '</p>' +
                        '</div>');
                });
        }
    };

    // =========================================================================
    // Module: Services  (Step 1)
    // =========================================================================
    var Services = {
        _loadTimer: null,
        _requestVersion: 0,

        load: function () {
            var self = this;
            var version = ++this._requestVersion;
            var $list = $('#obwp-services-list');

            $list.html('<p class="obwp-loading">' + (cfg.strings.loading || 'Cargando servicios...') + '</p>');

            clearTimeout(this._loadTimer);
            this._loadTimer = setTimeout(function () {
                var current = $list.find('.obwp-loading');
                if (current.length) {
                    $list.html(
                        '<p class="obwp-loading">' + (cfg.strings.loading || 'Cargando servicios...') + '</p>' +
                        '<p class="obwp-note">' + escapeHtml(cfg.strings.load_slow || 'La carga está demorando más de lo habitual.') + '</p>'
                    );
                }
            }, 8000);

            Api.get('services').done(function (res) {
                if (version !== self._requestVersion) return; // stale response — a newer load() is in flight
                clearTimeout(self._loadTimer);
                var services = res.services || [];

                if (State.serviceSlug) {
                    services = services.filter(function (s) { return s.slug === State.serviceSlug; });
                }

                if (!services.length) {
                    $list.html(
                        '<p class="obwp-empty">' + (cfg.strings.no_services || 'No hay servicios disponibles en este momento.') + '</p>' +
                        '<p class="obwp-empty-help">' + escapeHtml(cfg.strings.no_services_help || 'Intenta nuevamente más tarde o contacta al negocio.') + '</p>'
                    );
                    Baseline.markServicesLoaded(0, false);
                    return;
                }

                Baseline.markServicesLoaded(services.length, false);

                if (State.serviceId) {
                    var restored = services.filter(function (s) { return String(s.id) === String(State.serviceId); })[0] || null;
                    if (restored) {
                        Services.select(restored.id, restored.name, restored.formatted_price, restored.price_minor || 0, restored.currency || '');
                        if (State.date) {
                            var parts = State.date.split('-');
                            Calendar.year = parseInt(parts[0], 10);
                            Calendar.month = parseInt(parts[1], 10) - 1;
                            Calendar.render();
                            Calendar.selectDate(State.date);
                            if (State.startAt) {
                                Steps.show(3);
                                Form.render();
                            }
                        }
                        return;
                    }
                }

                if (services.length === 1) {
                    var s0 = services[0];
                    Services.select(s0.id, s0.name, s0.formatted_price, s0.price_minor || 0, s0.currency || '');
                    return;
                }

                var html = '';
                services.forEach(function (s) {
                    html += '<div class="obwp-service-card" role="button" tabindex="0"' +
                        ' data-id="' + s.id + '"' +
                        ' data-name="' + escapeHtml(s.name) + '"' +
                        ' data-price="' + escapeHtml(s.formatted_price || '') + '"' +
                        ' data-price-minor="' + (s.price_minor || 0) + '"' +
                        ' data-currency="' + escapeHtml(s.currency || '') + '">';
                    html += '<h3>' + escapeHtml(s.name) + '</h3>';
                    html += '<p>' + s.duration_minutes + ' min';
                    if (s.formatted_price && parseFloat(s.formatted_price) > 0) {
                        html += ' &middot; ' + escapeHtml(s.currency) + ' ' + escapeHtml(s.formatted_price);
                    }
                    html += '</p></div>';
                });
                $list.html(html);
            }).fail(function () {
                if (version !== self._requestVersion) return;
                clearTimeout(self._loadTimer);
                Baseline.markServicesLoaded(0, true);
                $list.html(
                    '<div class="obwp-state obwp-state--failed" role="alert">' +
                    '<p class="obwp-error">' + (cfg.strings.error_services_load || 'No pudimos cargar los servicios.') + '</p>' +
                    '<button class="obwp-btn obwp-btn--primary" id="obwp-retry-services">' +
                    (cfg.strings.retry || 'Volver a intentar') + '</button>' +
                    '</div>'
                );
                $(document).off('click.obwpRetryServices').on('click.obwpRetryServices', '#obwp-retry-services', function (e) {
                    e.preventDefault();
                    Services.load();
                });
            });
        },

        select: function (id, name, price, priceMinor, currency) {
            if (State.serviceId && String(State.serviceId) !== String(id)) {
                State.startAt    = null;
                State.endAt      = null;
                State.resourceId = null;
                State.time       = null;
                State.date       = null;
                TimeSlots.resetForServiceChange();
            }
            State.serviceId         = id;
            State.serviceName       = name;
            State.servicePrice      = price;
            State.servicePriceMinor = parseInt(priceMinor) || 0;
            State.serviceCurrency   = currency;
            Baseline.markFirstInteraction('service', { service_id: id });
            Api.track('service_selected', { service_id: id });
            Session.save();
            $('#obwp-selected-service-name').text(name);
            Steps.show(2);
            Calendar.init();
        }
    };

    // =========================================================================
    // Module: Events
    // =========================================================================
    var Events = {
        bind: function () {
            // Service selection
            $(document).on('click.obwp keydown.obwp', '.obwp-service-card', function (e) {
                if (e.type === 'keydown' && e.which !== 13 && e.which !== 32) return;
                e.preventDefault();
                Services.select($(this).data('id'), $(this).data('name'), $(this).data('price'), $(this).data('price-minor') || 0, $(this).data('currency') || '');
            });

            // Calendar navigation
            $(document).on('click.obwp', '#obwp-prev-month', function (e) { e.preventDefault(); Calendar.change(-1); });
            $(document).on('click.obwp', '#obwp-next-month', function (e) { e.preventDefault(); Calendar.change(1); });

            // Date selection
            $(document).on('click.obwp keydown.obwp', '.obwp-calendar-day--available', function (e) {
                if (e.type === 'keydown' && e.which !== 13 && e.which !== 32 && e.which !== 37 && e.which !== 38 && e.which !== 39 && e.which !== 40) return;
                if (e.type === 'keydown') {
                    var $days = $('.obwp-calendar-day--available');
                    var idx = $days.index(this);
                    if (e.which === 37 && idx > 0) { e.preventDefault(); $days.eq(idx - 1).focus(); return; }
                    if (e.which === 39 && idx < $days.length - 1) { e.preventDefault(); $days.eq(idx + 1).focus(); return; }
                    if (e.which === 38 && idx >= 7) { e.preventDefault(); $days.eq(idx - 7).focus(); return; }
                    if (e.which === 40 && idx + 7 < $days.length) { e.preventDefault(); $days.eq(idx + 7).focus(); return; }
                    if (e.which !== 13 && e.which !== 32) return;
                }
                e.preventDefault();
                Calendar.selectDate($(this).data('date'));
            });

            // Time slot selection
            $(document).on('click.obwp keydown.obwp', '.obwp-time-slot', function (e) {
                if (e.type === 'keydown' && e.which !== 13 && e.which !== 32 && e.which !== 37 && e.which !== 39) return;
                if (e.type === 'keydown') {
                    var $slots = $('.obwp-time-slot');
                    var idx = $slots.index(this);
                    if (e.which === 37 && idx > 0) { e.preventDefault(); $slots.eq(idx - 1).focus(); return; }
                    if (e.which === 39 && idx < $slots.length - 1) { e.preventDefault(); $slots.eq(idx + 1).focus(); return; }
                    if (e.which !== 13 && e.which !== 32) return;
                }
                e.preventDefault();
                TimeSlots.select($(this).data('start'), $(this).data('end'), $(this).data('resource'));
            });

            // Wizard navigation
            $(document).on('click.obwp keydown.obwp', '.obwp-step[data-step]', function (e) {
                if (e.type === 'keydown' && e.which !== 13 && e.which !== 32) return;
                e.preventDefault();
                Steps.show(parseInt($(this).data('step'), 10));
            });

            $(document).on('click.obwp', '.obwp-btn-next', function (e) {
                e.preventDefault();
                Steps.next(parseInt($(this).data('to')));
            });
            $(document).on('click.obwp', '.obwp-btn-prev', function (e) {
                e.preventDefault();
                Steps.show(parseInt($(this).data('to')));
            });

            // Retry: go back to beginning
            $(document).on('click.obwp', '#obwp-retry-btn', function (e) {
                e.preventDefault();
                window.location.href = window.location.pathname;
            });

            $(document).on('click.obwp', '#obwp-reset-draft', function (e) {
                e.preventDefault();
                Session.clear();
                window.location.href = window.location.pathname;
            });

            $(document).on('input change', '#obwp-form-fields input, #obwp-form-fields textarea', function () {
                var n = $(this).attr('name');
                if (!n) return;
                State.customerDraft[n] = $(this).val();
                Session.save();
            });
        }
    };

    // =========================================================================
    // Utilities
    // =========================================================================
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function isValidPhone(phone) {
        return /^[0-9+()\-\s]{7,20}$/.test(phone);
    }

    function escapeHtml(str) {
        if (!str && str !== 0) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatDateHuman(dateStr) {
        if (!dateStr) return '';
        var parts  = dateStr.split('-');
        var months = ['enero','febrero','marzo','abril','mayo','junio',
                      'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return parseInt(parts[2]) + ' de ' + months[parseInt(parts[1]) - 1] + ', ' + parts[0];
    }

    function formatDateA11y(dateStr) {
        return formatDateHuman(dateStr);
    }

    // =========================================================================
    // Boot
    // =========================================================================
    var _initialized = false;

    function init() {
        var $app = $('#obwp-booking-app');
        if (!$app.length) return;

        // Guard against double-init when the shortcode appears twice on the same
        // page or when a third-party plugin re-triggers document.ready.
        if (_initialized) return;
        _initialized = true;

        Baseline.markInit();

        State.serviceSlug = $app.data('service') || '';

        Events.bind();

        $(window).on('beforeunload.obwp', function () {
            TimeSlots._stopPoll();
            Baseline.trackAbandon();
        });

        if (Session.restore()) {
            FlowNotice.show(cfg.strings.booking_restore || 'Recuperamos tu progreso para que continúes donde lo dejaste.', true);
            Summary.render();
            Baseline.markFirstInteraction('restore', { step: State.step });
        }

        if (TokenFlow.handle()) return;

        Api.get('form-fields-public')
            .done(function (res) { State.formFields = res.fields || []; })
            .fail(function () { State.formFields = []; });

        Services.load();

        Steps.show(1);
    }

    $(document).ready(init);

})(jQuery);
