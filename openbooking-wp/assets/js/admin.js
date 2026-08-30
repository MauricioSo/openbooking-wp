(() => {
  // resources/assets/admin/js/legacy-admin.js
  (function($) {
    "use strict";
    if (!window.obwpAdmin) return;
    var restUrl = obwpAdmin.restUrl;
    var nonce = obwpAdmin.nonce;
    var strings = obwpAdmin.strings || {};
    var agendaState = {
      view: "week",
      date: getToday(),
      serviceFilter: "",
      statusFilter: ""
    };
    function init() {
      initTabs();
      initForms();
      initDeleteButtons();
      initAgenda();
      initAvailabilityPage();
      initDesign();
      initEmailTemplates();
      initNotificationSettings();
      initFormFieldsEditor();
      initPaymentsPage();
      initAuditLogsPage();
      initGatewaySettings();
      initReadinessChecklist();
      initDashboardData();
    }
    function initTabs() {
      $(document).on("click", ".obwp-tab", function() {
        var tab = $(this).data("tab");
        var $container = $(this).closest(".obwp-wrap");
        $container.find(".obwp-tab").removeClass("active");
        $(this).addClass("active");
        $container.find(".obwp-tab-panel").removeClass("active");
        $container.find("#tab-" + tab).addClass("active");
      });
    }
    function initForms() {
      $(document).on("submit", "#obwp-service-form", function(e) {
        e.preventDefault();
        saveService(this);
      });
      $(document).on("submit", "#obwp-availability-form", function(e) {
        e.preventDefault();
        saveAvailability(this);
      });
      $(document).on("submit", "#obwp-payments-form", function(e) {
        e.preventDefault();
        savePayments(this);
      });
      $(document).on("submit", "#obwp-design-form", function(e) {
        e.preventDefault();
        saveDesign(this);
      });
      $(document).on("submit", "#obwp-settings-form", function(e) {
        e.preventDefault();
        saveSettings(this);
      });
    }
    function saveService(form) {
      var $form = $(form);
      if ($form.data("obwpSubmitting")) {
        return;
      }
      $form.data("obwpSubmitting", true);
      var $submit = $form.find('button[type="submit"], input[type="submit"]').prop("disabled", true);
      var data = $(form).serializeArray();
      var payload = {};
      data.forEach(function(item) {
        payload[item.name] = item.value;
      });
      var serviceId = parseInt(payload.service_id) || 0;
      if (payload.price_minor) {
        payload.price_minor = Math.round(parseFloat(payload.price_minor) * 100);
      }
      var method = serviceId ? "PATCH" : "POST";
      var endpoint = serviceId ? restUrl + "admin/services/" + serviceId : restUrl + "admin/services";
      apiRequest(method, endpoint, payload).done(function(res) {
        if (res.success) {
          var savedMessage = res.message || (serviceId ? "Servicio actualizado correctamente." : "Servicio creado correctamente.");
          var noticeCode = serviceId ? "service_updated" : "service_created";
          showNotice(savedMessage, "success");
        } else {
          showRequestError(res.error || strings.error_generic);
        }
      }).fail(function(xhr) {
        if (xhr && xhr.status === 409 && xhr.responseJSON && xhr.responseJSON.warnings && xhr.responseJSON.warnings.length) {
          showNotice((xhr.responseJSON.error || "No se pudo guardar por cambios con impacto en reservas existentes.") + " " + xhr.responseJSON.warnings.join(" "), "warning");
          return;
        }
        showRequestError(getRequestErrorMessage(xhr));
      }).always(function() {
        $form.data("obwpSubmitting", false);
        $submit.prop("disabled", false);
      });
    }
    function saveAvailability(form) {
      var payload = buildAvailabilityPayload();
      if (payload.scope_type !== "global" && !payload.scope_id) {
        showRequestError("Debes seleccionar un elemento para este alcance.");
        return;
      }
      apiRequest("POST", restUrl + "admin/availability", payload).done(function(res) {
        if (res.success) {
          showNotice("Guardado correctamente.", "success");
          loadSnapshotsForCurrentScope();
        } else {
          showRequestError(res.error || strings.error_generic);
        }
      }).fail(function(xhr) {
        showRequestError(getRequestErrorMessage(xhr));
      });
    }
    function initAvailabilityPage() {
      if (!$("#obwp-availability-form").length) return;
      loadAvailabilityScopeOptions();
      toggleAvailabilityScopeFields();
      $(document).on("change", "#scope_type, #scope_service_id, #scope_resource_id", function() {
        toggleAvailabilityScopeFields();
        loadAvailabilityForCurrentScope();
      });
      $(document).on("click", ".obwp-schedule-row .obwp-add-break", function() {
        var day = $(this).closest(".obwp-schedule-row").data("day");
        addBreakRow(day);
      });
      $(document).on("click", "#obwp-add-break-global", function() {
        addBreakRow(null);
      });
      $(document).on("click", ".obwp-remove-break", function() {
        $(this).closest(".obwp-break-row").remove();
      });
      $(document).on("click", "#obwp-add-exception, #obwp-add-block", function() {
        var type = this.id === "obwp-add-block" ? "block" : "holiday";
        var $row = $(".obwp-exception-row.obwp-template").first().clone(true, true);
        $row.removeClass("obwp-template").show();
        $row.find('[name="exception_type[]"]').val(type);
        $("#obwp-exceptions").append($row);
      });
      $(document).on("click", ".obwp-remove-exception", function() {
        $(this).closest(".obwp-exception-row").remove();
      });
      $(document).on("click", "#obwp-generate-preview", function() {
        generateAvailabilityPreview();
      });
      $(document).on("click", "#obwp-validate-before-save", function() {
        validateBeforeSave();
      });
      $(document).on("click", "#obwp-create-snapshot", function() {
        createSnapshot();
      });
      $(document).on("click", ".obwp-restore-snapshot", function() {
        if (!confirm("Restaurar esta version? La configuracion actual se reemplazara.")) return;
        restoreSnapshot($(this).data("id"));
      });
      $(document).on("click", ".obwp-delete-snapshot", function() {
        if (!confirm("Eliminar esta version guardada?")) return;
        deleteSnapshot($(this).data("id"));
      });
      loadSnapshotsForCurrentScope();
      populatePreviewServiceSelect();
    }
    function addBreakRow(day) {
      var html = '<div class="obwp-break-row" data-day="' + (day || "") + '">';
      if (day) {
        var dayNames = { 1: "Lunes", 2: "Martes", 3: "Miercoles", 4: "Jueves", 5: "Viernes", 6: "Sabado", 7: "Domingo" };
        html += '<span class="obwp-break-day-label">' + (dayNames[day] || "") + "</span> ";
      }
      html += '<input type="time" class="break-from" value="13:00"> <span>a</span> <input type="time" class="break-to" value="14:00"> ';
      html += '<button type="button" class="ob-btn ob-btn-danger ob-btn-sm obwp-remove-break">Eliminar</button>';
      html += "</div>";
      if (day) {
        $('.obwp-schedule-row[data-day="' + day + '"]').append(html);
      } else {
        $("#obwp-breaks-list").append(html);
      }
    }
    function generateAvailabilityPreview() {
      var serviceId = parseInt($("#obwp-preview-service").val(), 10);
      if (!serviceId) {
        showRequestError("Selecciona un servicio para previsualizar.");
        return;
      }
      var mode = $('[name="preview_mode"]:checked').val() || "week";
      var payload = buildAvailabilityPayload();
      payload.service_id = serviceId;
      payload.mode = mode;
      var $result = $("#obwp-preview-result");
      $result.html('<p class="obwp-loading">Generando vista previa...</p>');
      $("#obwp-preview-conflicts").html("");
      apiRequest("POST", restUrl + "admin/availability/preview", payload).done(function(res) {
        renderPreviewResult(res);
      }).fail(function(xhr) {
        $result.html('<p class="obwp-empty">' + getRequestErrorMessage(xhr) + "</p>");
      });
      var validatePayload = buildAvailabilityPayload();
      validatePayload.service_id = serviceId;
      validatePayload.dry_run = true;
      apiRequest("POST", restUrl + "admin/availability/validate", validatePayload).done(function(res) {
        renderPreviewConflicts(res);
      });
    }
    function renderPreviewResult(res) {
      var $result = $("#obwp-preview-result");
      if (res.error) {
        $result.html('<p class="obwp-empty">' + escapeHtml(res.error) + "</p>");
        return;
      }
      var html = '<div class="obwp-preview-grid">';
      html += "<p><strong>" + escapeHtml(res.start) + " \u2014 " + escapeHtml(res.end) + "</strong></p>";
      html += '<table class="widefat striped"><thead><tr><th>Fecha</th><th>Dia</th><th>Slots</th><th>Disponible</th></tr></thead><tbody>';
      var dayNames = { 1: "Lun", 2: "Mar", 3: "Mie", 4: "Jue", 5: "Vie", 6: "Sab", 7: "Dom" };
      (res.days || []).forEach(function(day) {
        var cls = day.has_available ? "obwp-status--ok" : "obwp-status--error";
        html += "<tr>";
        html += "<td>" + escapeHtml(day.date) + "</td>";
        html += "<td>" + escapeHtml(dayNames[day.weekday] || "") + "</td>";
        html += "<td>" + day.slot_count + "</td>";
        html += '<td><span class="obwp-status ' + cls + '">' + (day.has_available ? "Si" : "No") + "</span></td>";
        html += "</tr>";
      });
      html += "</tbody></table></div>";
      $result.html(html);
    }
    function renderPreviewConflicts(res) {
      var $conflicts = $("#obwp-preview-conflicts");
      var conflicts = res.conflicts || [];
      var warnings = res.warnings || [];
      if (!conflicts.length && !warnings.length) {
        $conflicts.html('<div class="notice notice-success"><p>Sin conflictos detectados.</p></div>');
        return;
      }
      var html = "";
      if (conflicts.length) {
        html += '<div class="notice notice-error"><p><strong>' + conflicts.length + " conflicto(s):</strong></p><ul>";
        conflicts.forEach(function(c) {
          html += "<li>" + escapeHtml(c.message) + "</li>";
        });
        html += "</ul></div>";
      }
      if (warnings.length) {
        html += '<div class="notice notice-warning"><p><strong>' + warnings.length + " advertencia(s):</strong></p><ul>";
        warnings.forEach(function(w) {
          html += "<li>" + escapeHtml(w.message) + "</li>";
        });
        html += "</ul></div>";
      }
      $conflicts.html(html);
    }
    function validateBeforeSave() {
      var serviceId = parseInt($("#scope_service_id").val(), 10);
      var scopeType = $("#scope_type").val() || "global";
      if (scopeType !== "global" && !serviceId) {
        showRequestError("Selecciona un servicio o recurso para validar.");
        return;
      }
      var payload = buildAvailabilityPayload();
      payload.service_id = serviceId;
      payload.scope_type = scopeType;
      payload.dry_run = true;
      var $result = $("#obwp-save-validation-result");
      $result.html('<p class="obwp-loading">Validando...</p>');
      apiRequest("POST", restUrl + "admin/availability/validate", payload).done(function(res) {
        var conflicts = res.conflicts || [];
        var warnings = res.warnings || [];
        if (!conflicts.length && !warnings.length) {
          $result.html('<div class="notice notice-success"><p>Validacion OK. Sin conflictos.</p></div>');
        } else {
          var html = "";
          if (conflicts.length) {
            html += '<div class="notice notice-error"><p><strong>' + conflicts.length + " conflicto(s) detectados:</strong></p><ul>";
            conflicts.forEach(function(c) {
              html += "<li>" + escapeHtml(c.message) + "</li>";
            });
            html += "</ul></div>";
          }
          if (warnings.length) {
            html += '<div class="notice notice-warning"><p><strong>' + warnings.length + " advertencia(s):</strong></p><ul>";
            warnings.forEach(function(w) {
              html += "<li>" + escapeHtml(w.message) + "</li>";
            });
            html += "</ul></div>";
          }
          $result.html(html);
        }
      }).fail(function(xhr) {
        $result.html('<div class="notice notice-error"><p>' + getRequestErrorMessage(xhr) + "</p></div>");
      });
    }
    function buildAvailabilityPayload() {
      var scopeType = $("#scope_type").val() || "global";
      var scopeId = 0;
      if (scopeType === "service") scopeId = parseInt($("#scope_service_id").val(), 10) || 0;
      if (scopeType === "resource") scopeId = parseInt($("#scope_resource_id").val(), 10) || 0;
      var payload = { scope_type: scopeType, scope_id: scopeId, rules: [], blocks: [], auto_snapshot: true };
      var enabledDays = {};
      $('#obwp-availability-form [name="enabled_days[]"]').each(function() {
        if ($(this).is(":checked")) enabledDays[$(this).val()] = true;
      });
      for (var day in enabledDays) {
        var tf = $('[name="time_from[' + day + ']"]').val();
        var tt = $('[name="time_to[' + day + ']"]').val();
        if (tf && tt) {
          payload.rules.push({ weekday: parseInt(day), time_from: tf, time_to: tt });
        }
        $('.obwp-break-row[data-day="' + day + '"]').each(function() {
          var bf = $(this).find(".break-from").val();
          var bt = $(this).find(".break-to").val();
          if (bf && bt) {
            payload.rules.push({ weekday: parseInt(day), rule_type: "break", time_from: bf, time_to: bt });
          }
        });
      }
      $('.obwp-break-row:not([data-day]), .obwp-break-row[data-day=""]').each(function() {
        var bf = $(this).find(".break-from").val();
        var bt = $(this).find(".break-to").val();
        if (bf && bt) {
          payload.rules.push({ rule_type: "break", time_from: bf, time_to: bt });
        }
      });
      $(".obwp-exception-row:not(.obwp-template)").each(function() {
        var date = $(this).find('[name="exception_date[]"]').val();
        var reason = $(this).find('[name="exception_reason[]"]').val();
        if (date) {
          payload.blocks.push({ start_at: date + " 00:00:00", end_at: date + " 23:59:59", reason });
        }
      });
      return payload;
    }
    function loadSnapshotsForCurrentScope() {
      var scopeType = $("#scope_type").val() || "global";
      var scopeId = scopeType === "service" ? parseInt($("#scope_service_id").val(), 10) || 0 : scopeType === "resource" ? parseInt($("#scope_resource_id").val(), 10) || 0 : 0;
      var params = "scope_type=" + encodeURIComponent(scopeType) + "&scope_id=" + scopeId + "&limit=10";
      apiRequest("GET", restUrl + "admin/availability/snapshots?" + params).done(function(res) {
        renderSnapshots(res.snapshots || []);
      });
    }
    function renderSnapshots(snapshots) {
      var $list = $("#obwp-snapshots-list");
      if (!snapshots.length) {
        $list.html('<p class="obwp-empty">Sin versiones guardadas.</p>');
        return;
      }
      var html = '<table class="widefat striped"><thead><tr><th>ID</th><th>Etiqueta</th><th>Fecha</th><th></th></tr></thead><tbody>';
      snapshots.forEach(function(s) {
        html += "<tr>";
        html += "<td>" + s.id + "</td>";
        html += "<td>" + escapeHtml(s.label || "-") + "</td>";
        html += "<td>" + escapeHtml(s.created_at || "") + "</td>";
        html += "<td>";
        html += '<button class="ob-btn ob-btn-secondary ob-btn-sm obwp-restore-snapshot" data-id="' + s.id + '">Restaurar</button> ';
        html += '<button class="ob-btn ob-btn-danger ob-btn-sm obwp-delete-snapshot" data-id="' + s.id + '">Eliminar</button>';
        html += "</td>";
        html += "</tr>";
      });
      html += "</tbody></table>";
      $list.html(html);
    }
    function createSnapshot() {
      var scopeType = $("#scope_type").val() || "global";
      var scopeId = scopeType === "service" ? parseInt($("#scope_service_id").val(), 10) || 0 : scopeType === "resource" ? parseInt($("#scope_resource_id").val(), 10) || 0 : 0;
      apiRequest("POST", restUrl + "admin/availability/snapshots", {
        scope_type: scopeType,
        scope_id: scopeId,
        label: "Manual snapshot"
      }).done(function(res) {
        if (res.success) {
          showNotice("Version guardada (#" + res.snapshot_id + ").", "success");
          loadSnapshotsForCurrentScope();
        }
      });
    }
    function restoreSnapshot(id) {
      apiRequest("POST", restUrl + "admin/availability/snapshots/" + id + "/restore", {}).done(function(res) {
        if (res.success) {
          showNotice("Version restaurada.", "success");
          loadAvailabilityForCurrentScope();
          loadSnapshotsForCurrentScope();
        } else {
          showRequestError(res.error || "Error al restaurar.");
        }
      });
    }
    function deleteSnapshot(id) {
      apiRequest("DELETE", restUrl + "admin/availability/snapshots/" + id, {}).done(function(res) {
        if (res.success) {
          showNotice("Version eliminada.", "success");
          loadSnapshotsForCurrentScope();
        }
      });
    }
    function populatePreviewServiceSelect() {
      apiRequest("GET", restUrl + "admin/services").done(function(res) {
        var $sel = $("#obwp-preview-service");
        (res.services || []).forEach(function(s) {
          $sel.append('<option value="' + s.id + '">' + escapeHtml(s.name) + "</option>");
        });
      });
    }
    function loadAvailabilityScopeOptions() {
      apiRequest("GET", restUrl + "admin/services").done(function(res) {
        var $select = $("#scope_service_id");
        (res.services || []).forEach(function(service) {
          $select.append('<option value="' + service.id + '">' + escapeHtml(service.name) + "</option>");
        });
      });
      apiRequest("GET", restUrl + "admin/resources").done(function(res) {
        var $select = $("#scope_resource_id");
        (res.resources || []).forEach(function(resource) {
          $select.append('<option value="' + resource.id + '">' + escapeHtml(resource.name) + "</option>");
        });
        loadAvailabilityForCurrentScope();
      });
    }
    function toggleAvailabilityScopeFields() {
      var scopeType = $("#scope_type").val() || "global";
      $("#obwp-availability-scope-service").toggle(scopeType === "service");
      $("#obwp-availability-scope-resource").toggle(scopeType === "resource");
    }
    function loadAvailabilityForCurrentScope() {
      var scopeType = $("#scope_type").val() || "global";
      var scopeId = scopeType === "service" ? parseInt($("#scope_service_id").val(), 10) || 0 : scopeType === "resource" ? parseInt($("#scope_resource_id").val(), 10) || 0 : 0;
      if (scopeType !== "global" && !scopeId) {
        resetAvailabilityForm();
        return;
      }
      apiRequest("GET", restUrl + "admin/availability?scope_type=" + encodeURIComponent(scopeType) + "&scope_id=" + scopeId).done(function(res) {
        hydrateAvailabilityForm(res.rules || [], res.blocks || []);
      }).fail(function() {
        resetAvailabilityForm();
      });
    }
    function resetAvailabilityForm() {
      $("#obwp-schedule-grid .obwp-schedule-row").each(function() {
        $(this).find('input[type="checkbox"]').prop("checked", false);
        $(this).find(".obwp-break-row").remove();
      });
      $("#obwp-exceptions .obwp-exception-row:not(.obwp-template)").remove();
    }
    function hydrateAvailabilityForm(rules, blocks) {
      resetAvailabilityForm();
      rules.forEach(function(rule) {
        if (rule.rule_type === "weekly" && rule.weekday) {
          var $row = $('.obwp-schedule-row[data-day="' + rule.weekday + '"]');
          $row.find('input[type="checkbox"]').prop("checked", true);
          $row.find('[name="time_from[' + rule.weekday + ']"]').val((rule.time_from || "").slice(0, 5));
          $row.find('[name="time_to[' + rule.weekday + ']"]').val((rule.time_to || "").slice(0, 5));
        }
        if (rule.rule_type === "break" && rule.weekday) {
          var breakHtml = '<div class="obwp-break-row" data-day="' + rule.weekday + '"><input type="time" class="break-from" value="' + escapeHtml((rule.time_from || "").slice(0, 5)) + '"> <span>a</span> <input type="time" class="break-to" value="' + escapeHtml((rule.time_to || "").slice(0, 5)) + '"> <button type="button" class="ob-btn ob-btn-danger ob-btn-sm obwp-remove-break">Eliminar</button></div>';
          $('.obwp-schedule-row[data-day="' + rule.weekday + '"]').append(breakHtml);
        }
      });
      blocks.forEach(function(block) {
        var $row = $(".obwp-exception-row.obwp-template").first().clone(true, true);
        $row.removeClass("obwp-template").show();
        $row.find('[name="exception_date[]"]').val((block.start_at || "").slice(0, 10));
        $row.find('[name="exception_type[]"]').val(block.block_type || "block");
        $row.find('[name="exception_reason[]"]').val(block.reason || "");
        $("#obwp-exceptions").append($row);
      });
    }
    function savePayments(form) {
      var data = $(form).serializeArray();
      var payload = { enabled_gateways: [] };
      data.forEach(function(item) {
        if (item.name === "enabled_gateways[]") {
          payload.enabled_gateways.push(item.value);
        } else {
          payload[item.name] = item.value;
        }
      });
      apiRequest("POST", restUrl + "admin/settings/payments", payload).done(function(res) {
        if (res.success) {
          showNotice("Guardado correctamente.", "success");
        } else {
          showRequestError(res.error || strings.error_generic);
        }
      }).fail(function(xhr) {
        showRequestError(getRequestErrorMessage(xhr));
      });
    }
    function saveDesign(form) {
      var data = $(form).serializeArray();
      var payload = {};
      data.forEach(function(item) {
        payload[item.name] = item.value;
      });
      if (payload.radius) {
        payload.radius = payload.radius + "px";
      }
      apiRequest("POST", restUrl + "admin/settings/design", payload).done(function(res) {
        if (res.success) {
          showNotice("Guardado correctamente.", "success");
          if (res.contrast_warnings && res.contrast_warnings.length) {
            var warnHtml = '<div class="obwp-constrast-warning"><span class="dashicons dashicons-warning"></span><div>';
            warnHtml += "<strong>Advertencia de contraste:</strong><br>";
            res.contrast_warnings.forEach(function(w) {
              warnHtml += escapeHtml(w) + "<br>";
            });
            warnHtml += "</div></div>";
            $("#obwp-design-form").find(".obwp-constrast-warning").remove();
            $("#obwp-design-form .obwp-tabs").before(warnHtml);
          } else {
            $("#obwp-design-form .obwp-constrast-warning").remove();
          }
        } else {
          showRequestError(res.error || strings.error_generic);
        }
      }).fail(function(xhr) {
        showRequestError(getRequestErrorMessage(xhr));
      });
    }
    function saveSettings(form) {
      var data = $(form).serializeArray();
      var payload = {};
      data.forEach(function(item) {
        payload[item.name] = item.value;
      });
      payload.uninstall_remove_data = $('[name="uninstall_remove_data"]').is(":checked") ? "1" : "0";
      apiRequest("POST", restUrl + "admin/settings", payload).done(function(res) {
        if (res.success) {
          showNotice("Guardado correctamente.", "success");
        } else {
          showRequestError(res.error || strings.error_generic);
        }
      }).fail(function(xhr) {
        showRequestError(getRequestErrorMessage(xhr));
      });
    }
    function initDeleteButtons() {
      $(document).on("click", ".obwp-delete-service", function() {
        if (!confirm(strings.confirm_delete)) return;
        var id = $(this).data("id");
        var $card = $(this).closest(".obwp-card");
        apiRequest("DELETE", restUrl + "admin/services/" + id).done(function(res) {
          if (res.success) {
            $card.fadeOut(300, function() {
              $(this).remove();
            });
            showNotice(res.message || "Servicio eliminado correctamente.", "success");
          } else {
            showRequestError(res.error || strings.error_generic);
          }
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        });
      });
      $(document).on("click", ".obwp-delete-resource", function() {
        if (!confirm(strings.confirm_delete)) return;
        var id = $(this).data("id");
        var $row = $(this).closest("tr");
        apiRequest("DELETE", restUrl + "admin/resources/" + id).done(function(res) {
          if (res.success) {
            $row.fadeOut(300, function() {
              $(this).remove();
            });
            showNotice(res.message || "Recurso eliminado correctamente.", "success");
          } else {
            showRequestError(res.error || strings.error_generic);
          }
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        });
      });
    }
    function initAgenda() {
      if (!$("#obwp-agenda-main").length) return;
      apiRequest("GET", restUrl + "admin/services").done(function(res) {
        var $sel = $("#obwp-filter-service");
        (res.services || []).forEach(function(s) {
          $sel.append('<option value="' + s.id + '">' + escapeHtml(s.name) + "</option>");
        });
      });
      $(document).on("click", ".obwp-agenda-view", function() {
        $(".obwp-agenda-view").removeClass("active");
        $(this).addClass("active");
        agendaState.view = $(this).data("view");
        loadAgenda();
      });
      $(document).on("click", "#obwp-agenda-prev", function() {
        agendaState.date = shiftDate(agendaState.date, -1, agendaState.view);
        loadAgenda();
      });
      $(document).on("click", "#obwp-agenda-next", function() {
        agendaState.date = shiftDate(agendaState.date, 1, agendaState.view);
        loadAgenda();
      });
      $(document).on("click", "#obwp-agenda-today", function() {
        agendaState.date = getToday();
        loadAgenda();
      });
      $(document).on("change", "#obwp-filter-service", function() {
        agendaState.serviceFilter = $(this).val();
        loadAgenda();
      });
      $(document).on("change", "#obwp-filter-status", function() {
        agendaState.statusFilter = $(this).val();
        loadAgenda();
      });
      $(document).on("click", ".obwp-agenda-item", function() {
        $(".obwp-agenda-item").removeClass("is-active");
        $(this).addClass("is-active");
        var id = $(this).data("id");
        loadBookingDetail(id);
      });
      $(document).on("click", "#obwp-detail-confirm", function() {
        var id = $(this).data("id");
        bookingAction(id, "confirm");
      });
      $(document).on("click", "#obwp-detail-cancel", function() {
        var id = $(this).data("id");
        if (!confirm(strings.confirm_cancel)) return;
        bookingAction(id, "cancel");
      });
      $(document).on("click", "#obwp-detail-noshow", function() {
        var id = $(this).data("id");
        bookingAction(id, "no_show");
      });
      $(document).on("click", "#obwp-detail-reschedule", function() {
        var id = $(this).data("id");
        var newDateTime = window.prompt("Nueva fecha y hora (YYYY-MM-DD HH:MM):", "");
        if (!newDateTime || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(newDateTime.trim())) {
          if (newDateTime) showRequestError("Formato inv\xE1lido. Use YYYY-MM-DD HH:MM.");
          return;
        }
        apiRequest("PATCH", restUrl + "admin/bookings/" + id, { action: "reschedule", start_at: newDateTime.trim() }).done(function(res) {
          if (res.success) {
            loadAgenda();
            loadBookingDetail(id);
            showNotice("Reserva reprogramada.", "success");
          } else {
            showRequestError(res.error || strings.error_generic);
          }
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        });
      });
      renderAgendaNav();
      loadAgenda();
    }
    function renderAgendaNav() {
      var nav = '<div class="obwp-agenda-nav">';
      nav += '<button id="obwp-agenda-today" class="ob-btn ob-btn-secondary ob-btn-sm">' + (strings.today || "Hoy") + "</button> ";
      nav += '<button id="obwp-agenda-prev" class="ob-btn ob-btn-secondary ob-btn-sm">&larr;</button> ';
      nav += '<button id="obwp-agenda-next" class="ob-btn ob-btn-secondary ob-btn-sm">&rarr;</button>';
      nav += "</div>";
      $(".obwp-agenda-toolbar").prepend(nav);
    }
    function loadAgenda() {
      var $main = $("#obwp-agenda-main");
      $main.html('<p class="obwp-loading">' + (strings.loading || "Cargando...") + "</p>");
      var range = getDateRange(agendaState.date, agendaState.view);
      var params = {
        date_from: range.from + " 00:00:00",
        date_to: range.to + " 23:59:59",
        limit: 200
      };
      if (agendaState.serviceFilter) params.service_id = agendaState.serviceFilter;
      if (agendaState.statusFilter) params.status = agendaState.statusFilter;
      apiRequest("GET", restUrl + "admin/bookings?" + $.param(params)).done(function(res) {
        renderAgenda(res.bookings || [], range);
      }).fail(function() {
        $main.html('<p class="obwp-empty">' + strings.error_generic + "</p>");
      });
    }
    function getDateRange(date, view) {
      if (view === "day") {
        return { from: date, to: date };
      }
      if (view === "week") {
        var d = /* @__PURE__ */ new Date(date + "T00:00:00");
        var dow = d.getDay();
        var monday = new Date(d);
        monday.setDate(d.getDate() - (dow + 6) % 7);
        var sunday = new Date(monday);
        sunday.setDate(monday.getDate() + 6);
        return { from: formatDate(monday), to: formatDate(sunday) };
      }
      var parts = date.split("-");
      var y = parseInt(parts[0]), m = parseInt(parts[1]) - 1;
      var first = new Date(y, m, 1);
      var last = new Date(y, m + 1, 0);
      return { from: formatDate(first), to: formatDate(last) };
    }
    function shiftDate(date, delta, view) {
      var d = /* @__PURE__ */ new Date(date + "T00:00:00");
      if (view === "day") d.setDate(d.getDate() + delta);
      if (view === "week") d.setDate(d.getDate() + delta * 7);
      if (view === "month") d.setMonth(d.getMonth() + delta);
      return formatDate(d);
    }
    function renderAgenda(bookings, range) {
      var $main = $("#obwp-agenda-main");
      var label = range.from === range.to ? range.from : range.from + " \u2014 " + range.to;
      var html = '<div class="obwp-agenda-date-label">' + escapeHtml(label) + "</div>";
      if (!bookings.length) {
        html += '<p class="obwp-empty">' + (strings.no_bookings || "No hay reservas para este per\xEDodo.") + "</p>";
        $main.html(html);
        return;
      }
      html += '<div class="obwp-agenda-timeline">';
      bookings.forEach(function(b) {
        var time = b.start_at ? b.start_at.split(" ")[1].slice(0, 5) : "";
        var dateStr = b.start_at ? b.start_at.split(" ")[0] : "";
        html += '<div class="obwp-agenda-item" data-id="' + escapeHtml(b.id) + '">';
        if (agendaState.view !== "day") {
          html += '<span class="obwp-agenda-date-col">' + escapeHtml(dateStr) + "</span>";
        }
        html += '<span class="obwp-agenda-time">' + escapeHtml(time) + "</span>";
        html += '<span class="obwp-agenda-info">';
        html += "<strong>" + escapeHtml(b.service_name || "") + "</strong>";
        html += " \u2014 " + escapeHtml(b.customer_name || "");
        html += "</span>";
        html += '<span class="obwp-status obwp-status--' + escapeHtml(b.status) + '">' + escapeHtml(b.status) + "</span>";
        html += "</div>";
      });
      html += "</div>";
      $main.html(html);
    }
    function loadBookingDetail(id) {
      var $sidebar = $("#obwp-booking-detail");
      $sidebar.html('<p class="obwp-loading">' + (strings.loading || "Cargando...") + "</p>");
      apiRequest("GET", restUrl + "admin/bookings/" + id).done(function(res) {
        var b = res.booking;
        if (!b) {
          $sidebar.html('<p class="obwp-empty">No encontrada.</p>');
          return;
        }
        var html = '<div class="obwp-detail">';
        html += "<h3>" + escapeHtml(b.service_name || "") + "</h3>";
        html += "<p><strong>" + escapeHtml(b.customer_name || "") + "</strong><br>" + escapeHtml(b.customer_email || "") + "</p>";
        html += "<p>" + escapeHtml(b.start_at || "") + "</p>";
        html += "<p>" + (strings.status || "Estado") + ': <span class="obwp-status obwp-status--' + escapeHtml(b.status) + '">' + escapeHtml(b.status) + "</span></p>";
        if (b.notes_customer) {
          html += "<p><em>" + escapeHtml(b.notes_customer) + "</em></p>";
        }
        html += '<div class="obwp-section"><h4>Historial / Auditoria</h4><div id="obwp-booking-audit-log"><p class="obwp-loading">' + (strings.loading || "Cargando...") + "</p></div></div>";
        html += '<div class="obwp-detail-actions">';
        if (b.status === "pending") {
          html += '<button class="ob-btn ob-btn-primary ob-btn-sm" id="obwp-detail-confirm" data-id="' + escapeHtml(b.id) + '">' + (strings.confirm || "Confirmar") + "</button> ";
        }
        if (b.status === "pending" || b.status === "confirmed") {
          html += '<button class="ob-btn ob-btn-secondary ob-btn-sm" id="obwp-detail-cancel" data-id="' + escapeHtml(b.id) + '">' + (strings.cancel || "Cancelar") + "</button> ";
          html += '<button class="ob-btn ob-btn-secondary ob-btn-sm" id="obwp-detail-noshow" data-id="' + escapeHtml(b.id) + '">' + (strings.no_show || "No asisti\xF3") + "</button> ";
          html += '<button class="ob-btn ob-btn-secondary ob-btn-sm" id="obwp-detail-reschedule" data-id="' + escapeHtml(b.id) + '">' + (strings.reschedule || "Reprogramar") + "</button>";
        }
        html += "</div>";
        html += "</div>";
        $sidebar.html(html);
        loadEntityAuditLogs("booking", b.id, "#obwp-booking-audit-log");
      }).fail(function() {
        $sidebar.html('<p class="obwp-empty">' + strings.error_generic + "</p>");
      });
    }
    function bookingAction(id, action) {
      apiRequest("PATCH", restUrl + "admin/bookings/" + id, { action }).done(function(res) {
        if (res.success) {
          loadAgenda();
          loadBookingDetail(id);
          showNotice("Acci\xF3n realizada correctamente.", "success");
        } else {
          showRequestError(res.error || strings.error_generic);
        }
      }).fail(function(xhr) {
        showRequestError(getRequestErrorMessage(xhr));
      });
    }
    var _previewState = "service";
    function initDesign() {
      if (!$("#obwp-design-form").length) return;
      loadFormFieldsPreview();
      $(document).on("input change", "#design_color_primary, #design_color_bg, #design_color_text, #design_font, #design_radius", function() {
        updateDesignPreview();
      });
      $(document).on("change", '[name="preview_mode"]', function() {
        var mode = $(this).val();
        $("#obwp-design-preview").toggleClass("obwp-preview--mobile", mode === "mobile");
      });
      $(document).on("click", "[data-preview-state]", function() {
        _previewState = $(this).data("preview-state");
        $(".obwp-preview-state-bar button").removeClass("active");
        $(this).addClass("active");
        updateDesignPreview();
      });
      updateDesignPreview();
    }
    function updateDesignPreview() {
      var primary = $("#design_color_primary").val() || "#111111";
      var bg = $("#design_color_bg").val() || "#ffffff";
      var text = $("#design_color_text").val() || "#1f1f1f";
      var font = $("#design_font").val() || "Inter, sans-serif";
      var radiusRaw = $("#design_radius").val() || "12";
      var radius = radiusRaw + "px";
      showLiveContrastWarning(primary, bg, text);
      var cssVars = "--ob-color-primary:" + primary + ";--ob-color-bg:" + bg + ";--ob-color-text:" + text + ";--ob-font-family:" + font + ";--ob-radius:" + radius + ";";
      var stepperHtml = buildStepperHtml(primary, _previewState);
      var bodyHtml = buildPreviewBody(primary, bg, text, font, radius, _previewState);
      var previewHtml = '<div class="obwp-preview-inner" style="' + cssVars + "background:" + bg + ";color:" + text + ";font-family:" + font + ';padding:24px">' + stepperHtml + '<div style="margin-top:20px">' + bodyHtml + "</div></div>";
      $("#obwp-design-preview").html(previewHtml);
    }
    var _stepLabels = ["Servicio", "Fecha", "Datos", "Confirmar"];
    var _stateStep = { service: 0, calendar: 1, form: 2, confirmed: 3, pending: 3, failed: 3 };
    function buildStepperHtml(primary, state) {
      var currentStep = _stateStep[state] || 0;
      var html = '<div style="display:flex;align-items:center;gap:0">';
      for (var i = 0; i < _stepLabels.length; i++) {
        var isActive = i === currentStep;
        var isCompleted = i < currentStep;
        var numBg = isActive || isCompleted ? primary : "#e8e8e8";
        var numColor = isActive || isCompleted ? "#fff" : "#999";
        var labelColor = isActive ? primary : isCompleted ? "#555" : "#bbb";
        html += '<div style="display:flex;flex-direction:column;align-items:center;gap:4px"><div style="width:28px;height:28px;border-radius:50%;background:' + numBg + ";color:" + numColor + ';display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">' + (i + 1) + '</div><span style="font-size:11px;color:' + labelColor + ";white-space:nowrap;font-weight:" + (isActive ? "700" : "400") + '">' + _stepLabels[i] + "</span></div>";
        if (i < _stepLabels.length - 1) {
          html += '<div style="flex:1;height:2px;background:' + (isCompleted ? primary : "#e8e8e8") + ';min-width:20px;margin-bottom:14px"></div>';
        }
      }
      html += "</div>";
      return html;
    }
    function buildPreviewBody(primary, bg, text, font, radius, state) {
      var card = "padding:16px;background:" + bg + ";border:1px solid #e0e0e0;border-radius:" + radius + ";";
      if (state === "service") {
        return '<p style="font-size:13px;color:#888;margin:0 0 12px">Elige un servicio para comenzar</p><div style="' + card + 'display:flex;justify-content:space-between;align-items:center;cursor:pointer"><div><p style="font-weight:700;font-size:15px;margin:0 0 4px">Consulta \u2014 60 min</p><p style="margin:0;font-size:13px;color:#888">Presencial \xB7 $35.000</p></div><button style="background:' + primary + ";color:#fff;border:none;border-radius:" + radius + ";padding:8px 18px;font-family:" + font + ';font-size:13px;cursor:pointer">Elegir</button></div><div style="' + card + 'margin-top:10px;opacity:0.55"><p style="font-weight:700;font-size:15px;margin:0 0 4px">Seguimiento \u2014 30 min</p><p style="margin:0;font-size:13px;color:#888">Online \xB7 $18.000</p></div>';
      }
      if (state === "calendar") {
        var W = "width:calc(100%/7);box-sizing:border-box;text-align:center;";
        var dayLabels = ["L", "M", "M", "J", "V", "S", "D"];
        var calHtml = '<p style="font-weight:700;font-size:14px;margin:0 0 12px">Mayo 2026</p>';
        calHtml += '<div style="display:flex;flex-wrap:nowrap">';
        dayLabels.forEach(function(d) {
          calHtml += '<span style="' + W + 'font-size:11px;color:#bbb;font-weight:600;padding:4px 0">' + d + "</span>";
        });
        calHtml += "</div>";
        var cells = [null, null, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19];
        var avail = [3, 5, 8, 10, 12, 15, 17, 19];
        calHtml += '<div style="display:flex;flex-wrap:wrap">';
        cells.forEach(function(n) {
          if (!n) {
            calHtml += '<span style="' + W + '"></span>';
            return;
          }
          var isSel = n === 15;
          var isAv = avail.indexOf(n) !== -1;
          var cellBg = isSel ? primary : "#fff";
          var clr = isSel ? "#fff" : isAv ? text : "#ccc";
          var border = isAv && !isSel ? "1px solid " + primary : "1px solid transparent";
          calHtml += '<span style="' + W + "padding:7px 2px;font-size:13px;border-radius:" + radius + ";background:" + cellBg + ";color:" + clr + ";border:" + border + ";cursor:" + (isAv ? "pointer" : "default") + ';margin-bottom:4px">' + n + "</span>";
        });
        calHtml += "</div>";
        calHtml += '<p style="font-size:12px;color:#888;margin:12px 0 8px;font-weight:600">Horarios \u2014 15 may</p>';
        calHtml += '<div style="display:flex;flex-wrap:wrap;gap:6px">';
        ["09:00", "10:00", "11:00", "14:00", "15:00"].forEach(function(t, i) {
          var sel = i === 2;
          calHtml += '<span style="padding:6px 12px;border-radius:' + radius + ";font-size:13px;background:" + (sel ? primary : "#fff") + ";color:" + (sel ? "#fff" : text) + ";border:1px solid " + (sel ? primary : "#ddd") + ';cursor:pointer">' + t + "</span>";
        });
        calHtml += "</div>";
        return calHtml;
      }
      if (state === "form") {
        var field = function(label, placeholder) {
          return '<div style="margin-bottom:14px"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:5px;color:#555">' + label + '</label><input style="width:100%;border:1px solid #ddd;border-radius:' + radius + ";padding:9px 12px;font-family:" + font + ";font-size:14px;color:" + text + ";background:" + bg + ';box-sizing:border-box" placeholder="' + placeholder + '" readonly></div>';
        };
        return field("Nombre", "Tu nombre") + field("Correo electr\xF3nico", "correo@ejemplo.com") + field("Tel\xE9fono", "+56 9 1234 5678") + '<div style="margin-top:6px"><button style="width:100%;background:' + primary + ";color:#fff;border:none;border-radius:" + radius + ";padding:12px;font-family:" + font + ';font-size:15px;font-weight:700;cursor:pointer">Confirmar reserva</button></div>';
      }
      var stateMeta = {
        confirmed: { bg: "#1b5e20", label: "Reserva confirmada", icon: "\u2713" },
        pending: { bg: "#e65100", label: "Pago pendiente", icon: "\u23F1" },
        failed: { bg: "#b71c1c", label: "Pago fallido", icon: "\u2715" }
      };
      var meta = stateMeta[state] || stateMeta.confirmed;
      var rows = [
        ["Servicio", "Consulta \u2014 60 min"],
        ["Fecha y hora", "Mi\xE9 15 may \xB7 11:00"],
        ["Profesional", "Dr. Garc\xEDa"],
        ["Referencia", "#OB-2024-0042"]
      ];
      var rowsHtml = rows.map(function(r) {
        return '<div style="display:flex;justify-content:space-between;align-items:baseline;padding:12px 0;border-bottom:1px solid #f3f3f3"><span style="font-size:13px;color:#888">' + r[0] + '</span><span style="font-size:14px;font-weight:700;color:' + text + '">' + r[1] + "</span></div>";
      }).join("");
      return '<div style="border-radius:' + radius + ';overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08)"><div style="background:' + meta.bg + ';color:#fff;padding:24px 20px"><div style="font-size:28px;margin-bottom:8px">' + meta.icon + '</div><div style="font-size:18px;font-weight:700">' + meta.label + "</div>" + (state === "pending" ? '<div style="font-size:13px;opacity:0.85;margin-top:4px">Tu pago est\xE1 siendo verificado. Te enviaremos un correo.</div>' : "") + (state === "failed" ? '<div style="font-size:13px;opacity:0.85;margin-top:4px">No se realiz\xF3 ning\xFAn cobro. Puedes intentar de nuevo.</div>' : "") + '</div><div style="background:#fff;padding:4px 20px 8px">' + rowsHtml + '</div><div style="background:#fafafa;border-top:1px solid #eee;padding:14px 20px;display:flex;justify-content:center;gap:12px">' + (state !== "failed" ? '<button style="padding:9px 20px;border-radius:' + radius + ";border:1px solid #ddd;background:#fff;font-family:" + font + ';font-size:13px;cursor:pointer">Reprogramar</button>' : "") + (state === "failed" ? '<button style="padding:9px 20px;border-radius:' + radius + ";border:none;background:" + primary + ";color:#fff;font-family:" + font + ';font-size:13px;cursor:pointer">Volver a intentar</button>' : "") + (state !== "failed" ? '<button style="padding:9px 20px;border-radius:' + radius + ";border:1px solid #ddd;background:#fff;font-family:" + font + ';font-size:13px;cursor:pointer;color:#c0392b">Cancelar</button>' : "") + "</div></div>";
    }
    function showLiveContrastWarning(primary, bg, text) {
      var $existing = $("#obwp-design-form .obwp-constrast-warning");
      var warnings = [];
      var r1 = contrastRatio(text, bg);
      if (r1 < 4.5) warnings.push("Texto sobre fondo: ratio " + r1.toFixed(1) + ":1");
      var r2 = contrastRatio(primary, bg);
      if (r2 < 3) warnings.push("Color principal sobre fondo: ratio " + r2.toFixed(1) + ":1");
      if (warnings.length) {
        var html = '<div class="obwp-constrast-warning"><span class="dashicons dashicons-warning"></span><div>';
        html += "<strong>Contraste insuficiente:</strong><br>";
        warnings.forEach(function(w) {
          html += escapeHtml(w) + "<br>";
        });
        html += "</div></div>";
        if ($existing.length) {
          $existing.replaceWith(html);
        } else {
          $("#obwp-design-form .obwp-tabs").before(html);
        }
      } else {
        $existing.remove();
      }
    }
    function contrastRatio(hex1, hex2) {
      var l1 = relativeLuminance(hex1);
      var l2 = relativeLuminance(hex2);
      var lighter = Math.max(l1, l2);
      var darker = Math.min(l1, l2);
      return (lighter + 0.05) / (darker + 0.05);
    }
    function relativeLuminance(hex) {
      hex = hex.replace("#", "");
      if (hex.length !== 6) return 0.5;
      var r = linearizeChannel(parseInt(hex.substring(0, 2), 16));
      var g = linearizeChannel(parseInt(hex.substring(2, 4), 16));
      var b = linearizeChannel(parseInt(hex.substring(4, 6), 16));
      return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    }
    function linearizeChannel(srgb) {
      var s = srgb / 255;
      return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    }
    function loadFormFieldsPreview() {
      apiRequest("GET", restUrl + "admin/form-fields").done(function(res) {
        var fields = res.fields || [];
        var $list = $("#obwp-form-fields");
        if (!$list.length) return;
        var html = '<table class="widefat"><thead><tr><th>Campo</th><th>Etiqueta</th><th>Requerido</th><th>Activo</th><th>Orden</th></tr></thead><tbody>';
        fields.forEach(function(f, i) {
          html += '<tr data-key="' + escapeHtml(f.field_key) + '">';
          html += "<td><code>" + escapeHtml(f.field_key) + "</code></td>";
          html += '<td><input type="text" class="ff-label ob-form-table-input" value="' + escapeHtml(f.label) + '"></td>';
          html += '<td><input type="checkbox" class="ff-required"' + (parseInt(f.is_required) ? " checked" : "") + "></td>";
          html += '<td><input type="checkbox" class="ff-enabled"' + (parseInt(f.is_enabled) ? " checked" : "") + "></td>";
          html += '<td><input type="number" class="ff-order ob-form-table-order" value="' + escapeHtml(f.sort_order) + '"></td>';
          html += "</tr>";
        });
        html += "</tbody></table>";
        html += '<button type="button" class="ob-btn ob-btn-primary ob-btn-sm ob-form-save-row" id="obwp-save-form-fields">Guardar campos</button>';
        $list.html(html);
      });
      $(document).on("click", "#obwp-save-form-fields", function() {
        var fields = [];
        $("#obwp-form-fields tr[data-key]").each(function() {
          fields.push({
            field_key: $(this).data("key"),
            label: $(this).find(".ff-label").val(),
            is_required: $(this).find(".ff-required").is(":checked") ? 1 : 0,
            is_enabled: $(this).find(".ff-enabled").is(":checked") ? 1 : 0,
            sort_order: parseInt($(this).find(".ff-order").val()) || 0
          });
        });
        apiRequest("POST", restUrl + "admin/form-fields", { fields }).done(function(res) {
          if (res.success) {
            showNotice("Campos guardados.", "success");
          }
        });
      });
    }
    function initEmailTemplates() {
      var $container = $("#obwp-email-templates");
      if (!$container.length) return;
      apiRequest("GET", restUrl + "admin/email-templates").done(function(res) {
        var templates = res.templates || {};
        var labels = {
          booking_confirmed: "Reserva confirmada",
          booking_cancelled: "Reserva cancelada",
          booking_rescheduled: "Reserva reprogramada",
          payment_received: "Pago recibido",
          payment_failed: "Pago fallido",
          reminder_customer: "Recordatorio al cliente",
          new_booking_admin: "Nueva reserva (admin)"
        };
        var html = "";
        Object.keys(templates).forEach(function(key) {
          var tpl = templates[key] || {};
          html += '<div class="obwp-email-template" data-key="' + escapeHtml(key) + '">';
          html += "<h4>" + escapeHtml(labels[key] || key) + "</h4>";
          html += '<div class="obwp-field"><label>Asunto</label>';
          html += '<input type="text" class="et-subject large-text" value="' + escapeHtml(tpl.subject || "") + '"></div>';
          html += '<div class="obwp-field"><label>Cuerpo</label>';
          html += '<textarea class="et-body large-text" rows="5">' + escapeHtml(tpl.body || "") + "</textarea></div>";
          html += '<button type="button" class="ob-btn ob-btn-secondary ob-btn-sm obwp-save-template">Guardar</button>';
          html += "<hr>";
          html += "</div>";
        });
        $container.html(html);
      });
      $(document).on("click", ".obwp-save-template", function() {
        var $block = $(this).closest(".obwp-email-template");
        var key = $block.data("key");
        var subject = $block.find(".et-subject").val();
        var body = $block.find(".et-body").val();
        apiRequest("POST", restUrl + "admin/email-templates/" + key, { subject, body }).done(function(res) {
          if (res.success) showNotice("Plantilla guardada.", "success");
        });
      });
      $(document).on("click", "#obwp-send-test-email", function() {
        var to = $("#obwp-test-email-to").val().trim();
        if (!to) {
          showRequestError("Ingresa un email.");
          return;
        }
        $(this).prop("disabled", true).text("Enviando...");
        apiRequest("POST", restUrl + "admin/email-test", { to }).done(function(res) {
          if (res.success) showNotice(res.message || "Email enviado.", "success");
          else showRequestError(res.error || "Error al enviar.");
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        }).always(function() {
          $("#obwp-send-test-email").prop("disabled", false).text("Enviar prueba");
        });
      });
    }
    function initNotificationSettings() {
      if (!$("#tab-notifications").length) return;
      function toggleAccentColor() {
        if ($("#notif_email_html_enabled").is(":checked")) {
          $("#obwp-email-accent-row").show();
        } else {
          $("#obwp-email-accent-row").hide();
        }
      }
      function toggleProvider() {
        var provider = $("#notif_whatsapp_provider").val();
        if (provider === "twilio") {
          $("#obwp-wa-twilio").show();
          $("#obwp-wa-meta").hide();
        } else {
          $("#obwp-wa-twilio").hide();
          $("#obwp-wa-meta").show();
        }
      }
      function toggleMetaTplNames() {
        if ($("#notif_whatsapp_meta_use_templates").is(":checked")) {
          $("#obwp-wa-meta-tpl-wrap").show();
        } else {
          $("#obwp-wa-meta-tpl-wrap").hide();
        }
      }
      function loadNotifSettings() {
        apiRequest("GET", restUrl + "admin/settings/notifications").done(function(res) {
          $("#notif_email_html_enabled").prop("checked", !!res.email_html_enabled);
          $("#notif_email_accent_color").val(res.email_accent_color || "#2563eb");
          toggleAccentColor();
          $("#notif_whatsapp_enabled").prop("checked", !!res.whatsapp_enabled);
          $("#notif_whatsapp_provider").val(res.whatsapp_provider || "twilio");
          $("#notif_whatsapp_notify_admin").prop("checked", !!res.whatsapp_notify_admin);
          $("#notif_whatsapp_admin_phone").val(res.whatsapp_admin_phone || "");
          $("#notif_whatsapp_twilio_sid").val(res.whatsapp_twilio_sid || "");
          $("#notif_whatsapp_twilio_token").val(res.whatsapp_twilio_token || "");
          $("#notif_whatsapp_twilio_from").val(res.whatsapp_twilio_from || "");
          $("#notif_whatsapp_meta_token").val(res.whatsapp_meta_token || "");
          $("#notif_whatsapp_meta_phone_id").val(res.whatsapp_meta_phone_id || "");
          $("#notif_whatsapp_meta_use_templates").prop("checked", !!res.whatsapp_meta_use_templates);
          $("#notif_whatsapp_meta_language").val(res.whatsapp_meta_language || "es");
          $("#notif_meta_tpl_booking_confirmed").val(res.whatsapp_meta_tpl_booking_confirmed || "");
          $("#notif_meta_tpl_booking_cancelled").val(res.whatsapp_meta_tpl_booking_cancelled || "");
          $("#notif_meta_tpl_booking_rescheduled").val(res.whatsapp_meta_tpl_booking_rescheduled || "");
          $("#notif_meta_tpl_reminder_customer").val(res.whatsapp_meta_tpl_reminder_customer || "");
          $("#notif_meta_tpl_new_booking_admin").val(res.whatsapp_meta_tpl_new_booking_admin || "");
          toggleProvider();
          toggleMetaTplNames();
          $("#notif_sms_enabled").prop("checked", !!res.sms_enabled);
          $("#notif_sms_twilio_sid").val(res.sms_twilio_sid || "");
          $("#notif_sms_twilio_token").val(res.sms_twilio_token || "");
          $("#notif_sms_twilio_from").val(res.sms_twilio_from || "");
        });
      }
      function loadSmsTemplates() {
        var $container = $("#obwp-sms-templates");
        if (!$container.length) return;
        apiRequest("GET", restUrl + "admin/sms-templates").done(function(res) {
          var templates = res.templates || {};
          var labels = {
            booking_confirmed: "Reserva confirmada",
            booking_cancelled: "Reserva cancelada",
            booking_rescheduled: "Reserva reprogramada",
            booking_expired: "Reserva expirada",
            payment_received: "Pago recibido",
            reminder_customer: "Recordatorio al cliente",
            new_booking_admin: "Nueva reserva (admin)"
          };
          var html = "";
          Object.keys(templates).forEach(function(key) {
            var body = templates[key] || "";
            html += '<div class="obwp-wa-template" data-key="' + escapeHtml(key) + '">';
            html += "<h4>" + escapeHtml(labels[key] || key) + "</h4>";
            html += '<div class="obwp-field">';
            html += '<textarea class="wt-body large-text" rows="3">' + escapeHtml(body) + "</textarea>";
            html += "</div>";
            html += '<button type="button" class="ob-btn ob-btn-secondary ob-btn-sm obwp-save-sms-template">Guardar</button>';
            html += "<hr>";
            html += "</div>";
          });
          $container.html(html || "<p>No hay mensajes disponibles.</p>");
        }).fail(function() {
          $container.html("<p>Error al cargar los mensajes.</p>");
        });
      }
      function loadWaTemplates() {
        var $container = $("#obwp-wa-templates");
        if (!$container.length) return;
        apiRequest("GET", restUrl + "admin/whatsapp-templates").done(function(res) {
          var templates = res.templates || {};
          var labels = {
            booking_confirmed: "Reserva confirmada",
            booking_cancelled: "Reserva cancelada",
            booking_rescheduled: "Reserva reprogramada",
            booking_expired: "Reserva expirada",
            payment_received: "Pago recibido",
            reminder_customer: "Recordatorio al cliente",
            new_booking_admin: "Nueva reserva (admin)"
          };
          var html = "";
          Object.keys(templates).forEach(function(key) {
            var body = templates[key] || "";
            html += '<div class="obwp-wa-template" data-key="' + escapeHtml(key) + '">';
            html += "<h4>" + escapeHtml(labels[key] || key) + "</h4>";
            html += '<div class="obwp-field">';
            html += '<textarea class="wt-body large-text" rows="4">' + escapeHtml(body) + "</textarea>";
            html += "</div>";
            html += '<button type="button" class="ob-btn ob-btn-secondary ob-btn-sm obwp-save-wa-template">Guardar</button>';
            html += "<hr>";
            html += "</div>";
          });
          $container.html(html || "<p>No hay mensajes disponibles.</p>");
        }).fail(function() {
          $container.html("<p>Error al cargar los mensajes.</p>");
        });
      }
      function loadNotifLog() {
        var channel = $("#obwp-notif-log-channel").val();
        var status = $("#obwp-notif-log-status").val();
        var $table = $("#obwp-notif-log-table");
        $table.html('<p class="obwp-loading">Cargando...</p>');
        var params = "per_page=50&page=1";
        if (channel) params += "&channel=" + encodeURIComponent(channel);
        if (status) params += "&status=" + encodeURIComponent(status);
        apiRequest("GET", restUrl + "admin/notification-logs?" + params).done(function(res) {
          var logs = res.logs || [];
          if (!logs.length) {
            $table.html('<p class="obwp-empty">Sin registros.</p>');
            return;
          }
          var html = '<table class="widefat striped"><thead><tr>';
          html += "<th>#</th><th>Reserva</th><th>Canal</th><th>Evento</th><th>Destinatario</th><th>Estado</th><th>Fecha</th>";
          html += "</tr></thead><tbody>";
          logs.forEach(function(log) {
            var sc = log.status === "sent" ? "ok" : log.status === "failed" ? "error" : "warning";
            html += "<tr>";
            html += "<td>" + escapeHtml(log.id) + "</td>";
            html += "<td>" + escapeHtml(log.booking_id) + "</td>";
            html += "<td>" + escapeHtml(log.channel) + "</td>";
            html += "<td>" + escapeHtml(log.template_key) + "</td>";
            html += "<td>" + escapeHtml(log.recipient) + "</td>";
            html += '<td><span class="obwp-status obwp-status--' + sc + '">' + escapeHtml(log.status) + "</span></td>";
            html += "<td>" + escapeHtml(log.sent_at || log.created_at || "") + "</td>";
            html += "</tr>";
          });
          html += "</tbody></table>";
          if (res.total > logs.length) {
            html += '<p class="ob-notif-summary">Mostrando ' + logs.length + " de " + res.total + " registros.</p>";
          }
          $table.html(html);
        }).fail(function() {
          $table.html('<p class="obwp-empty">Error al cargar el historial.</p>');
        });
      }
      loadNotifSettings();
      loadWaTemplates();
      loadSmsTemplates();
      $(document).on("change", "#notif_email_html_enabled", toggleAccentColor);
      $(document).on("change", "#notif_whatsapp_provider", toggleProvider);
      $(document).on("change", "#notif_whatsapp_meta_use_templates", toggleMetaTplNames);
      $(document).on("click", "#obwp-save-email-notif", function() {
        var data = {
          email_html_enabled: $("#notif_email_html_enabled").is(":checked"),
          email_accent_color: $("#notif_email_accent_color").val()
        };
        var $btn = $(this).prop("disabled", true).text("Guardando...");
        apiRequest("POST", restUrl + "admin/settings/notifications", data).done(function(res) {
          if (res.success) showNotice("Ajustes de email guardados.", "success");
          else showRequestError(res.error || strings.error_generic);
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        }).always(function() {
          $btn.prop("disabled", false).text("Guardar ajustes de email");
        });
      });
      $(document).on("click", "#obwp-save-wa-notif", function() {
        var data = {
          whatsapp_enabled: $("#notif_whatsapp_enabled").is(":checked"),
          whatsapp_provider: $("#notif_whatsapp_provider").val(),
          whatsapp_notify_admin: $("#notif_whatsapp_notify_admin").is(":checked"),
          whatsapp_admin_phone: $("#notif_whatsapp_admin_phone").val(),
          // Twilio
          whatsapp_twilio_sid: $("#notif_whatsapp_twilio_sid").val(),
          whatsapp_twilio_token: $("#notif_whatsapp_twilio_token").val(),
          whatsapp_twilio_from: $("#notif_whatsapp_twilio_from").val(),
          // Meta
          whatsapp_meta_token: $("#notif_whatsapp_meta_token").val(),
          whatsapp_meta_phone_id: $("#notif_whatsapp_meta_phone_id").val(),
          whatsapp_meta_use_templates: $("#notif_whatsapp_meta_use_templates").is(":checked"),
          whatsapp_meta_language: $("#notif_whatsapp_meta_language").val(),
          whatsapp_meta_tpl_booking_confirmed: $("#notif_meta_tpl_booking_confirmed").val(),
          whatsapp_meta_tpl_booking_cancelled: $("#notif_meta_tpl_booking_cancelled").val(),
          whatsapp_meta_tpl_booking_rescheduled: $("#notif_meta_tpl_booking_rescheduled").val(),
          whatsapp_meta_tpl_reminder_customer: $("#notif_meta_tpl_reminder_customer").val(),
          whatsapp_meta_tpl_new_booking_admin: $("#notif_meta_tpl_new_booking_admin").val()
        };
        var $btn = $(this).prop("disabled", true).text("Guardando...");
        apiRequest("POST", restUrl + "admin/settings/notifications", data).done(function(res) {
          if (res.success) showNotice("Ajustes de WhatsApp guardados.", "success");
          else showRequestError(res.error || strings.error_generic);
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        }).always(function() {
          $btn.prop("disabled", false).text("Guardar ajustes de WhatsApp");
        });
      });
      $(document).on("click", ".obwp-save-wa-template", function() {
        var $block = $(this).closest(".obwp-wa-template");
        var key = $block.data("key");
        var body = $block.find(".wt-body").val();
        var $btn = $(this).prop("disabled", true).text("Guardando...");
        apiRequest("POST", restUrl + "admin/whatsapp-templates/" + key, { body }).done(function(res) {
          if (res.success) showNotice("Mensaje guardado.", "success");
          else showRequestError(res.error || strings.error_generic);
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        }).always(function() {
          $btn.prop("disabled", false).text("Guardar");
        });
      });
      $(document).on("click", "#obwp-send-test-wa", function() {
        var to = $("#obwp-wa-test-to").val().trim();
        if (!to) {
          showRequestError("Ingresa un n\xFAmero de destino.");
          return;
        }
        var $btn = $(this).prop("disabled", true).text("Enviando...");
        apiRequest("POST", restUrl + "admin/whatsapp-test", { to }).done(function(res) {
          if (res.success) showNotice(res.message || "Mensaje enviado.", "success");
          else showRequestError(res.error || "Error al enviar. Revisa las credenciales y los logs.");
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        }).always(function() {
          $btn.prop("disabled", false).text("Enviar prueba");
        });
      });
      $(document).on("click", "#obwp-save-sms-notif", function() {
        var data = {
          sms_enabled: $("#notif_sms_enabled").is(":checked"),
          sms_twilio_sid: $("#notif_sms_twilio_sid").val(),
          sms_twilio_token: $("#notif_sms_twilio_token").val(),
          sms_twilio_from: $("#notif_sms_twilio_from").val()
        };
        var $btn = $(this).prop("disabled", true).text("Guardando...");
        apiRequest("POST", restUrl + "admin/settings/notifications", data).done(function(res) {
          if (res.success) showNotice("Ajustes de SMS guardados.", "success");
          else showRequestError(res.error || strings.error_generic);
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        }).always(function() {
          $btn.prop("disabled", false).text("Guardar ajustes de SMS");
        });
      });
      $(document).on("click", ".obwp-save-sms-template", function() {
        var $block = $(this).closest(".obwp-wa-template");
        var key = $block.data("key");
        var body = $block.find(".wt-body").val();
        var $btn = $(this).prop("disabled", true).text("Guardando...");
        apiRequest("POST", restUrl + "admin/sms-templates/" + key, { body }).done(function(res) {
          if (res.success) showNotice("Mensaje guardado.", "success");
          else showRequestError(res.error || strings.error_generic);
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        }).always(function() {
          $btn.prop("disabled", false).text("Guardar");
        });
      });
      $(document).on("click", "#obwp-send-test-sms", function() {
        var to = $("#obwp-sms-test-to").val().trim();
        if (!to) {
          showRequestError("Ingresa un n\xFAmero de destino.");
          return;
        }
        var $btn = $(this).prop("disabled", true).text("Enviando...");
        apiRequest("POST", restUrl + "admin/sms-test", { to }).done(function(res) {
          if (res.success) showNotice(res.message || "SMS enviado.", "success");
          else showRequestError(res.error || "Error al enviar. Revisa las credenciales y los logs.");
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        }).always(function() {
          $btn.prop("disabled", false).text("Enviar prueba");
        });
      });
      $(document).on("click", "#obwp-notif-log-load", function() {
        loadNotifLog();
      });
      if ($("#obwp-notif-log-table").length) {
        loadNotifLog();
      }
    }
    function initPaymentsPage() {
      var $gateways = $("#obwp-gateways-list");
      var $recent = $("#obwp-payments-recent");
      var $audit = $("#obwp-payments-audit-log");
      if ($gateways.length) {
        apiRequest("GET", restUrl + "admin/gateways").done(function(res) {
          var gateways = res.gateways || [];
          if (!gateways.length) {
            $gateways.html("<p>No hay medios de pago disponibles para este pa\xEDs.</p>");
            return;
          }
          var html = "";
          gateways.forEach(function(g) {
            var status = g.health && g.health.status ? g.health.status : g.configured ? "ok" : "warning";
            var note = g.configured ? "Configurado" : "Configuraci\xF3n incompleta";
            if (g.health && g.health.missing && g.health.missing.length) {
              note += " (" + g.health.missing.join(", ") + ")";
            }
            html += '<div class="obwp-gateway-row">';
            html += '<label><input type="checkbox" class="obwp-gateway-toggle" data-gateway="' + escapeHtml(g.key) + '" name="enabled_gateways[]" value="' + escapeHtml(g.key) + '"' + (g.enabled ? " checked" : "") + "> " + escapeHtml(g.label) + "</label> ";
            html += '<span class="obwp-status obwp-status--' + escapeHtml(status) + '">' + escapeHtml(note) + "</span>";
            html += "</div>";
          });
          $gateways.html(html);
          document.dispatchEvent(new Event("obwpGatewaysLoaded"));
        });
      }
      if ($recent.length) {
        apiRequest("GET", restUrl + "admin/payments?limit=20").done(function(res) {
          var payments = res.payments || [];
          if (!payments.length) {
            $recent.html('<p class="obwp-empty">Sin pagos registrados.</p>');
            return;
          }
          var html = '<table class="widefat"><thead><tr><th>#</th><th>Reserva</th><th>Gateway</th><th>Monto</th><th>Estado</th><th>Fecha</th><th></th></tr></thead><tbody>';
          payments.forEach(function(p) {
            var amount = (p.amount_minor / 100).toFixed(2) + " " + p.currency;
            html += "<tr>";
            html += "<td>" + p.id + "</td>";
            html += "<td>" + p.booking_id + "</td>";
            html += "<td>" + escapeHtml(p.gateway) + "</td>";
            html += "<td>" + escapeHtml(amount) + "</td>";
            html += '<td><span class="obwp-status obwp-status--' + escapeHtml(p.status) + '">' + escapeHtml(p.status) + "</span></td>";
            html += "<td>" + escapeHtml(p.paid_at || p.created_at || "") + "</td>";
            html += "<td>";
            html += '<button class="ob-btn ob-btn-secondary ob-btn-sm obwp-view-payment-audit" data-id="' + escapeHtml(p.id) + '">Auditoria</button> ';
            html += '<button class="ob-btn ob-btn-secondary ob-btn-sm obwp-change-payment-status" data-id="' + escapeHtml(p.id) + '" data-status="' + escapeHtml(p.status) + '">Cambiar estado</button> ';
            if (p.status === "paid") {
              html += '<button class="ob-btn ob-btn-danger ob-btn-sm obwp-refund-btn" data-id="' + escapeHtml(p.id) + '">Reembolsar</button>';
            }
            html += "</td></tr>";
          });
          html += "</tbody></table>";
          $recent.html(html);
        });
      }
      $(document).on("click", ".obwp-refund-btn", function() {
        if (!confirm("\xBFReembolsar este pago?")) return;
        var id = $(this).data("id");
        var $btn = $(this);
        apiRequest("POST", restUrl + "admin/payments/" + id + "/refund", {}).done(function(res) {
          if (res.success) {
            $btn.replaceWith('<span class="obwp-status obwp-status--refunded">Reembolsado</span>');
            showNotice("Reembolso procesado.", "success");
          } else {
            showRequestError(res.error || strings.error_generic);
          }
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        });
      });
      $(document).on("click", ".obwp-view-payment-audit", function() {
        var id = $(this).data("id");
        if ($audit.length) {
          loadEntityAuditLogs("payment", id, "#obwp-payments-audit-log");
        }
      });
      $(document).on("click", ".obwp-change-payment-status", function() {
        var id = $(this).data("id");
        var current = $(this).data("status");
        var status = window.prompt("Nuevo estado de pago (pending, paid, failed, expired, refunded, partially_paid):", current || "pending");
        if (!status) return;
        var allowedStatuses = ["pending", "paid", "failed", "expired", "refunded", "partially_paid"];
        if (allowedStatuses.indexOf(status.trim().toLowerCase()) === -1) {
          showRequestError("Estado no v\xE1lido. Use: " + allowedStatuses.join(", "));
          return;
        }
        var reason = window.prompt("Motivo del cambio manual:", "manual_review");
        if (!reason) {
          showRequestError("Debes indicar un motivo.");
          return;
        }
        apiRequest("POST", restUrl + "admin/payments/" + id + "/status", { status, reason }).done(function(res) {
          if (res.success) {
            showNotice("Estado de pago actualizado.", "success");
            initPaymentsPage();
            loadEntityAuditLogs("payment", id, "#obwp-payments-audit-log");
          } else {
            showRequestError(res.error || strings.error_generic);
          }
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        });
      });
    }
    function initAuditLogsPage() {
      if (!$("#obwp-audit-logs-app").length) return;
      $(document).on("submit", "#obwp-audit-filters-form", function(e) {
        e.preventDefault();
        loadAuditLogs(0);
      });
      $(document).on("click", "#obwp-audit-clear-filters", function() {
        $("#obwp-audit-filters-form").get(0).reset();
        syncAuditFiltersToUrl({});
        loadAuditLogs(0);
      });
      $(document).on("click", ".obwp-audit-page-btn", function() {
        loadAuditLogs(parseInt($(this).data("offset"), 10) || 0);
      });
      $(document).on("click", ".obwp-audit-view-detail", function() {
        loadAuditLogDetail($(this).data("id"));
      });
      $(document).on("click", ".obwp-audit-link-filter", function() {
        applyAuditQuickFilter($(this).data("filter"), $(this).data("value"));
      });
      hydrateAuditFiltersFromUrl();
      loadAuditLogs(0);
    }
    function loadAuditLogs(offset) {
      var params = $("#obwp-audit-filters-form").serializeArray();
      var query = { limit: 20, offset: offset || 0 };
      params.forEach(function(item) {
        if (item.value !== "") {
          query[item.name] = item.value;
        }
      });
      syncAuditFiltersToUrl(query);
      var $list = $("#obwp-audit-logs-list");
      $list.html('<p class="obwp-loading">' + (strings.loading || "Cargando...") + "</p>");
      apiRequest("GET", restUrl + "admin/audit-logs?" + $.param(query)).done(function(res) {
        renderAuditLogs(res.logs || [], res.pagination || { total: 0, limit: 20, offset: 0 });
      }).fail(function(xhr) {
        $list.html('<p class="obwp-empty">' + getRequestErrorMessage(xhr) + "</p>");
      });
    }
    function renderAuditLogs(logs, pagination) {
      var $list = $("#obwp-audit-logs-list");
      if (!logs.length) {
        $list.html('<p class="obwp-empty">Sin eventos de auditoria.</p>');
        return;
      }
      var html = '<table class="widefat striped"><thead><tr><th>Fecha/Hora</th><th>Accion</th><th>Entidad</th><th>Actor</th><th>Mensaje</th><th></th></tr></thead><tbody>';
      logs.forEach(function(log) {
        var actor = log.actor && log.actor.display_name ? log.actor.display_name : log.actor_type || "-";
        var entity = (log.entity_type || "-") + " #" + (log.entity_id || "-");
        html += "<tr>";
        html += "<td>" + escapeHtml(log.created_at || "") + "</td>";
        html += "<td><code>" + escapeHtml(log.action || "") + "</code></td>";
        html += '<td><button type="button" class="ob-btn ob-btn-ghost ob-btn-sm obwp-audit-link-filter" data-filter="entity_type" data-value="' + escapeHtml(log.entity_type || "") + '">' + escapeHtml(entity) + "</button></td>";
        html += '<td><button type="button" class="ob-btn ob-btn-ghost ob-btn-sm obwp-audit-link-filter" data-filter="actor_type" data-value="' + escapeHtml(log.actor_type || "") + '">' + escapeHtml(actor) + "</button></td>";
        html += "<td>" + escapeHtml(log.message || "") + "</td>";
        html += '<td><button type="button" class="ob-btn ob-btn-secondary ob-btn-sm obwp-audit-view-detail" data-id="' + escapeHtml(log.id) + '">Ver detalle</button></td>';
        html += "</tr>";
      });
      html += "</tbody></table>";
      html += renderAuditPagination(pagination);
      $list.html(html);
    }
    function renderAuditPagination(pagination) {
      var total = parseInt(pagination.total || 0, 10);
      var limit = parseInt(pagination.limit || 20, 10);
      var offset = parseInt(pagination.offset || 0, 10);
      if (total <= limit) return "";
      var html = '<div class="obwp-audit-pagination">';
      if (offset > 0) {
        html += '<button type="button" class="ob-btn ob-btn-secondary ob-btn-sm obwp-audit-page-btn" data-offset="' + Math.max(0, offset - limit) + '">Anterior</button> ';
      }
      if (offset + limit < total) {
        html += '<button type="button" class="ob-btn ob-btn-secondary ob-btn-sm obwp-audit-page-btn" data-offset="' + (offset + limit) + '">Siguiente</button>';
      }
      html += "</div>";
      return html;
    }
    function loadAuditLogDetail(id) {
      var $detail = $("#obwp-audit-log-detail");
      $detail.html('<p class="obwp-loading">' + (strings.loading || "Cargando...") + "</p>");
      apiRequest("GET", restUrl + "admin/audit-logs/" + id).done(function(res) {
        renderAuditLogDetail(res.log || null, $detail);
      }).fail(function(xhr) {
        $detail.html('<p class="obwp-empty">' + getRequestErrorMessage(xhr) + "</p>");
      });
    }
    function renderAuditLogDetail(log, $container) {
      if (!log) {
        $container.html('<p class="obwp-empty">No se encontro el detalle.</p>');
        return;
      }
      var html = '<div class="obwp-detail">';
      html += "<h3>Audit Log #" + escapeHtml(log.id) + "</h3>";
      html += '<div class="obwp-audit-badges">';
      if (log.request_id) html += '<span class="obwp-audit-badge">request_id: ' + escapeHtml(log.request_id) + "</span>";
      if (log.source) html += '<span class="obwp-audit-badge">source: ' + escapeHtml(log.source) + "</span>";
      if (log.severity) html += '<span class="obwp-audit-badge">severity: ' + escapeHtml(log.severity) + "</span>";
      html += "</div>";
      html += '<div class="obwp-audit-actions">';
      if (log.request_id) html += '<button type="button" class="ob-btn ob-btn-secondary ob-btn-sm obwp-audit-link-filter" data-filter="request_id" data-value="' + escapeHtml(log.request_id) + '">Ver mismo request</button>';
      if (log.entity_type && log.entity_id) html += '<button type="button" class="ob-btn ob-btn-secondary ob-btn-sm obwp-audit-link-filter" data-filter="entity_type" data-value="' + escapeHtml(log.entity_type) + '">Filtrar entidad</button>';
      html += "</div>";
      html += '<div class="obwp-audit-kv">';
      html += "<strong>entity_type</strong><code>" + escapeHtml(log.entity_type || "") + "</code>";
      html += "<strong>entity_id</strong><code>" + escapeHtml(log.entity_id || "") + "</code>";
      html += "<strong>action</strong><code>" + escapeHtml(log.action || "") + "</code>";
      html += "<strong>actor_type</strong><code>" + escapeHtml(log.actor_type || "") + "</code>";
      html += "<strong>actor_id</strong><code>" + escapeHtml(log.actor_id || "") + "</code>";
      html += "<strong>timestamp</strong><code>" + escapeHtml(log.created_at || "") + "</code>";
      html += "<strong>route</strong><code>" + escapeHtml(log.route || "-") + "</code>";
      html += "<strong>method</strong><code>" + escapeHtml(log.http_method || "-") + "</code>";
      html += "<strong>message</strong><div>" + escapeHtml(log.message || "") + "</div>";
      html += "</div>";
      html += renderKnownContext(log.context || null);
      html += renderChangedFields(log.changed_fields || null);
      html += renderMetaJson(log.meta || null, "meta");
      html += renderMetaJson(log.context || null, "context");
      html += "</div>";
      $container.html(html);
    }
    function renderKnownContext(context) {
      if (!context) return "<p><strong>context:</strong> null</p>";
      var html = '<div class="obwp-audit-context">';
      ["reason", "gateway", "amount_minor", "refund_result"].forEach(function(key) {
        if (context[key] !== void 0) {
          html += "<p><strong>" + escapeHtml(key) + ":</strong> " + escapeHtml(typeof context[key] === "object" ? JSON.stringify(context[key]) : context[key]) + "</p>";
        }
      });
      html += "</div>";
      return html;
    }
    function renderChangedFields(changedFields) {
      if (!changedFields || !Object.keys(changedFields).length) return "";
      var html = '<div class="obwp-audit-section"><h4>Cambios detectados</h4><table class="widefat striped obwp-audit-diff-table"><thead><tr><th>Campo</th><th>Antes</th><th>Despues</th></tr></thead><tbody>';
      Object.keys(changedFields).forEach(function(key) {
        var row = changedFields[key] || {};
        html += "<tr>";
        html += "<td><code>" + escapeHtml(key) + "</code></td>";
        html += "<td>" + escapeHtml(stringifyAuditValue(row.old)) + "</td>";
        html += "<td>" + escapeHtml(stringifyAuditValue(row.new)) + "</td>";
        html += "</tr>";
      });
      html += "</tbody></table></div>";
      return html;
    }
    function renderMetaJson(value, label) {
      var json = JSON.stringify(value, null, 2);
      return '<div class="obwp-audit-section"><h4>' + escapeHtml(label) + '</h4><pre class="obwp-audit-json">' + escapeHtml(json || "null") + "</pre></div>";
    }
    function stringifyAuditValue(value) {
      if (value === null || value === void 0) return "null";
      if (typeof value === "object") return JSON.stringify(value);
      return String(value);
    }
    function hydrateAuditFiltersFromUrl() {
      var params = new URLSearchParams(window.location.search);
      ["entity_type", "entity_id", "action", "actor_type", "date_from", "date_to", "search", "request_id"].forEach(function(key) {
        var value = params.get(key);
        if (value !== null && $('#obwp-audit-filters-form [name="' + key + '"]').length) {
          $('#obwp-audit-filters-form [name="' + key + '"]').val(value);
        }
      });
    }
    function syncAuditFiltersToUrl(query) {
      if (!$("#obwp-audit-logs-app").length) return;
      var url = new URL(window.location.href);
      ["entity_type", "entity_id", "action", "actor_type", "date_from", "date_to", "search", "request_id", "offset"].forEach(function(key) {
        url.searchParams.delete(key);
      });
      Object.keys(query || {}).forEach(function(key) {
        if (query[key] !== "" && query[key] !== null && query[key] !== void 0 && key !== "limit") {
          url.searchParams.set(key, query[key]);
        }
      });
      window.history.replaceState({}, "", url.toString());
    }
    function applyAuditQuickFilter(filter, value) {
      if (!$("#obwp-audit-filters-form").length) return;
      var $field = $('#obwp-audit-filters-form [name="' + filter + '"]');
      if (!$field.length) return;
      $field.val(value || "");
      loadAuditLogs(0);
    }
    function loadEntityAuditLogs(entityType, entityId, target) {
      var $target = $(target);
      if (!$target.length) return;
      $target.html('<p class="obwp-loading">' + (strings.loading || "Cargando...") + "</p>");
      apiRequest("GET", restUrl + "admin/audit-logs?" + $.param({ entity_type: entityType, entity_id: entityId, limit: 10 })).done(function(res) {
        var logs = res.logs || [];
        if (!logs.length) {
          $target.html('<p class="obwp-empty">Sin eventos.</p>');
          return;
        }
        var html = '<ul class="obwp-audit-inline-list">';
        logs.forEach(function(log) {
          html += "<li><strong>" + escapeHtml(log.created_at || "") + "</strong> - " + escapeHtml(log.action || "") + " - " + escapeHtml(log.message || "") + "</li>";
        });
        html += "</ul>";
        $target.html(html);
      }).fail(function(xhr) {
        $target.html('<p class="obwp-empty">' + getRequestErrorMessage(xhr) + "</p>");
      });
    }
    function initGatewaySettings() {
      $(document).on("submit", ".obwp-gateway-settings-form", function(e) {
        e.preventDefault();
        var key = $(this).data("gateway");
        var data = {};
        $(this).serializeArray().forEach(function(item) {
          data[item.name] = item.value;
        });
        apiRequest("POST", restUrl + "admin/settings/gateway/" + key, data).done(function(res) {
          if (res.success) showNotice("Credenciales guardadas.", "success");
          else showRequestError(res.error || strings.error_generic);
        }).fail(function(xhr) {
          showRequestError(getRequestErrorMessage(xhr));
        });
      });
    }
    function initFormFieldsEditor() {
    }
    function apiRequest(method, url, data) {
      var opts = {
        url,
        method,
        beforeSend: function(xhr) {
          xhr.setRequestHeader("X-WP-Nonce", nonce);
        }
      };
      if (data && method !== "GET") {
        opts.data = JSON.stringify(data);
        opts.contentType = "application/json";
      }
      return $.ajax(opts).fail(function(xhr) {
        if (xhr && (xhr.status === 401 || xhr.status === 403)) {
          showNotice(strings.session_expired || "Tu sesi\xF3n expir\xF3. Recarga la p\xE1gina.", "error");
        }
      });
    }
    function getRequestErrorMessage(xhr) {
      if (xhr && (xhr.status === 401 || xhr.status === 403)) {
        return strings.session_expired || "Tu sesi\xF3n expir\xF3. Recarga la p\xE1gina.";
      }
      if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
        return xhr.responseJSON.error;
      }
      return strings.error_generic;
    }
    function showNotice(msg, type) {
      var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + escapeHtml(msg) + "</p></div>");
      $(".obwp-wrap").prepend($notice);
      setTimeout(function() {
        $notice.fadeOut(400, function() {
          $(this).remove();
        });
      }, 3500);
    }
    function showRequestError(msg) {
      showNotice(msg || strings.error_generic, "error");
    }
    window.obwpNotice = showNotice;
    window.obwpError = showRequestError;
    function escapeHtml(str) {
      if (!str && str !== 0) return "";
      return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }
    function getToday() {
      var d = /* @__PURE__ */ new Date();
      return formatDate(d);
    }
    function formatDate(d) {
      var y = d.getFullYear();
      var m = String(d.getMonth() + 1).padStart(2, "0");
      var dd = String(d.getDate()).padStart(2, "0");
      return y + "-" + m + "-" + dd;
    }
    function initReadinessChecklist() {
      var $section = $("#obwp-readiness-section");
      if (!$section.length) return;
      $.ajax({
        url: restUrl + "admin/onboarding/readiness",
        method: "GET",
        beforeSend: function(xhr) {
          xhr.setRequestHeader("X-WP-Nonce", nonce);
        }
      }).done(function(res) {
        var items = res.checklist || [];
        if (!items.length) return;
        var done = 0;
        var html = '<ul class="ob-readiness-list">';
        items.forEach(function(item) {
          var icon = item.done ? '<span class="dashicons dashicons-yes-alt"></span>' : '<span class="dashicons dashicons-marker"></span>';
          html += '<li class="ob-readiness-item">' + icon + " " + $("<span>").text(item.label).prop("outerHTML") + "</li>";
          if (item.done) done++;
        });
        html += "</ul>";
        var pct = Math.round(done / items.length * 100);
        $("#obwp-readiness-checklist").html(html);
        $("#obwp-readiness-fill").css("width", pct + "%");
        $("#obwp-readiness-label").text(done + " de " + items.length + " completados");
        $section.show();
      });
    }
    function initDashboardData() {
      if (!$("#obwp-dashboard-stats").length) return;
      var $bookingContainer = $("#obwp-today-bookings");
      apiRequest("GET", restUrl + "admin/dashboard").done(function(res) {
        var stats = res.stats || {};
        $("#obwp-stat-today").text(stats.today_bookings || 0);
        $("#obwp-stat-pending").text(stats.pending_bookings || 0);
        $("#obwp-stat-unpaid").text(stats.unpaid_bookings || 0);
        function renderBookingList(bookings) {
          if (!bookings.length) return "";
          var html2 = '<ul class="obwp-booking-list">';
          bookings.forEach(function(b) {
            var datePart = b.start_at ? b.start_at.slice(0, 10) : "";
            var timePart = b.start_at ? b.start_at.split(" ")[1].slice(0, 5) : "";
            var today = getToday();
            var label = datePart === today ? timePart : datePart + " " + timePart;
            var name = (b.first_name || "") + " " + (b.last_name || "");
            if (name.trim() === "") name = b.customer_name || b.email || "";
            html2 += "<li>";
            html2 += "<strong>" + escapeHtml(label.trim()) + "</strong> &mdash; ";
            html2 += escapeHtml(b.service_name || "Servicio") + " &mdash; ";
            html2 += escapeHtml(name.trim());
            html2 += ' <span class="obwp-status obwp-status--' + escapeHtml(b.status) + '">' + escapeHtml(b.status) + "</span>";
            html2 += "</li>";
          });
          html2 += "</ul>";
          return html2;
        }
        var todayBookings = res.today_bookings || [];
        var upcomingBookings = res.upcoming_bookings || [];
        var recentBookings = res.recent_bookings || [];
        var attentionRequired = res.attention_required || [];
        var html = "";
        if (todayBookings.length) {
          html += renderBookingList(todayBookings);
        } else if (upcomingBookings.length) {
          html += '<p class="obwp-note" style="margin:0 0 8px"><strong>Pr\xF3ximas reservas</strong></p>';
          html += renderBookingList(upcomingBookings);
        } else {
          html += '<p class="obwp-empty">No hay reservas pr\xF3ximas.</p><a href="' + escapeHtml(adminUrl("admin.php?page=openbooking-agenda")) + '" class="ob-btn ob-btn-secondary">Ver agenda</a>';
        }
        $bookingContainer.html(html);
        var $recent = $("#obwp-recent-bookings");
        if ($recent.length) {
          if (recentBookings.length) {
            $recent.html(renderBookingList(recentBookings));
          } else {
            $recent.html('<p class="obwp-empty">Sin reservas nuevas en las \xFAltimas 48 h.</p>');
          }
        }
        var urgency = res.urgency || [];
        var uHtml = "";
        if (attentionRequired.length) {
          uHtml = attentionRequired.map(function(b) {
            var datePart = b.start_at ? b.start_at.slice(0, 10) : "";
            var timePart = b.start_at ? b.start_at.split(" ")[1].slice(0, 5) : "";
            var label = datePart + (timePart ? " " + timePart : "");
            var name = (b.first_name || "") + " " + (b.last_name || "");
            if (name.trim() === "") name = b.customer_name || b.email || "";
            var reason = b.urgency_reason === "payment_window_expired" ? "Pago vencido" : b.urgency_reason === "starting_soon" ? "Comienza pronto" : "Revisar";
            return '<li class="ob-urgency-item"><span class="dashicons dashicons-warning"></span><span><strong>' + escapeHtml(label.trim()) + "</strong> &mdash; " + escapeHtml(b.service_name || "Servicio") + " &mdash; " + escapeHtml(name.trim()) + " <em>(" + escapeHtml(reason) + ")</em></span></li>";
          }).join("");
        } else if (urgency.length) {
          uHtml = urgency.map(function(u) {
            return '<li class="ob-urgency-item"><span class="dashicons dashicons-warning"></span><span>' + escapeHtml(u.label) + " (<strong>" + u.count + "</strong>)</span></li>";
          }).join("");
        }
        if (uHtml) {
          $("#obwp-urgency-list").html(uHtml);
          $("#obwp-urgency-section").show();
        } else {
          $("#obwp-urgency-section").hide();
        }
        refreshIntegritySummary(false);
      }).fail(function() {
        $bookingContainer.html('<p class="obwp-empty">Error al cargar reservas. Recarga la p\xE1gina.</p>');
      });
      $(document).on("click", "#obwp-run-reconcile", function() {
        var $btn = $(this).prop("disabled", true).text("Reconciliando...");
        apiRequest("POST", restUrl + "admin/reconcile").done(function(res) {
          showNotice(res.message || "Reconciliacion completada.", res.success ? "success" : "warning");
          $btn.prop("disabled", false).text("Reconciliar ahora");
          if (res.success) $("#obwp-urgency-section").hide();
        }).fail(function(xhr) {
          showNotice(getRequestErrorMessage(xhr), "error");
          $btn.prop("disabled", false).text("Reconciliar ahora");
        });
      });
      $(document).on("click", "#obwp-run-integrity", function() {
        var $btn = $(this).prop("disabled", true).text("Verificando...");
        refreshIntegritySummary(true).always(function() {
          $btn.prop("disabled", false).text("Ver diagn\xF3stico");
        });
      });
    }
    function refreshIntegritySummary(showDetails) {
      var deferred = $.Deferred();
      var $summary = $("#obwp-integrity-summary");
      var $result = $("#obwp-integrity-result");
      apiRequest("GET", restUrl + "admin/integrity-check").done(function(res) {
        var checks = res.checks || [];
        var okCount = 0;
        var warnCount = 0;
        var errCount = 0;
        checks.forEach(function(c) {
          if (c.status === "ok") okCount++;
          else if (c.status === "error") errCount++;
          else warnCount++;
        });
        var summaryText = errCount > 0 ? "Diagn\xF3stico: " + errCount + " error(es) y " + warnCount + " advertencia(s)." : warnCount > 0 ? "Diagn\xF3stico: " + warnCount + " advertencia(s)." : "Diagn\xF3stico: todo en orden.";
        if ((warnCount > 0 || errCount > 0) && $("#obwp-urgency-section").length) {
          $("#obwp-urgency-section").show();
        }
        if ($summary.length) {
          $summary.html("<strong>" + escapeHtml(summaryText) + "</strong>" + (checks.length ? ' <span class="ob-meta">' + okCount + " OK / " + warnCount + " advertencia(s) / " + errCount + " error(es)</span>" : ""));
        }
        var html = "";
        if (showDetails || warnCount > 0 || errCount > 0) {
          html += '<div class="notice ' + (res.success ? "notice-success" : "notice-warning") + '">';
          html += "<p><strong>" + escapeHtml(res.message || "Resultado de diagn\xF3stico") + "</strong></p>";
          html += '<p class="ob-meta">' + okCount + " OK, " + warnCount + " advertencia(s), " + errCount + " error(es).</p>";
          html += "</div>";
          if (checks.length) {
            html += '<div class="obwp-integrity-list" style="margin-top:10px;">';
            checks.forEach(function(c) {
              var cls = c.status === "ok" ? "obwp-status--ok" : c.status === "error" ? "obwp-status--error" : "obwp-status--warning";
              var label = c.label || c.check || "Chequeo";
              html += '<div class="obwp-integrity-row" style="display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-top:1px solid rgba(0,0,0,.06);">';
              html += '<span class="obwp-status ' + cls + '" style="flex:0 0 auto;">' + escapeHtml(c.status || "warning") + "</span>";
              html += "<div>";
              html += "<strong>" + escapeHtml(label) + "</strong>";
              if (c.message) html += '<div class="ob-meta">' + escapeHtml(c.message) + "</div>";
              html += "</div></div>";
            });
            html += "</div>";
          }
          if ($result.length) {
            $result.html(html).show();
          }
        } else if ($result.length) {
          $result.hide().empty();
        }
        deferred.resolve(res);
      }).fail(function(xhr) {
        if ($summary.length) {
          $summary.html('<strong>Diagn\xF3stico no disponible</strong> <span class="ob-meta">' + escapeHtml(getRequestErrorMessage(xhr)) + "</span>");
        }
        if ($result.length) {
          $result.html('<div class="notice notice-error"><p>' + escapeHtml(getRequestErrorMessage(xhr)) + "</p></div>").show();
        }
        deferred.reject(xhr);
      });
      return deferred.promise();
    }
    function adminUrl(path) {
      if (window.obwpAdmin && obwpAdmin.adminUrl) return obwpAdmin.adminUrl + path;
      return "/wp-admin/" + path;
    }
    $(document).ready(init);
  })(jQuery);
})();
