(function () {
  'use strict';

  window.__lc = window.__lc || {};
  window.__lc.organizationId = 'b37d7d64-69e7-4802-b24f-a28898cffb1f';
  window.__lc.integration_name = 'manual_onboarding';
  window.__lc.product_name = 'text';

  (function (n, t, c) {
    function i(args) { return e._h ? e._h.apply(null, args) : e._q.push(args); }
    var e = {
      _q: [],
      _h: null,
      _v: '2.0',
      on: function () { i(['on', c.call(arguments)]); },
      once: function () { i(['once', c.call(arguments)]); },
      off: function () { i(['off', c.call(arguments)]); },
      get: function () {
        if (!e._h) throw new Error('[LiveChatWidget] You cannot use getters before load.');
        return i(['get', c.call(arguments)]);
      },
      call: function () { i(['call', c.call(arguments)]); },
      init: function () {
        var script = t.createElement('script');
        script.async = true;
        script.type = 'text/javascript';
        script.src = 'https://cdn.livechatinc.com/tracking.js';
        t.head.appendChild(script);
      }
    };
    if (!n.__lc.asyncInit) e.init();
    n.LiveChatWidget = n.LiveChatWidget || e;
  })(window, document, [].slice);

  var API_BASE = (function () {
    var match = window.location.pathname.match(/^(\/[^\/]+\/)/);
    return match ? window.location.protocol + '//' + window.location.host + match[1] + 'public' : 'http://localhost/Hospital/public';
  })();

  function buildUrl(relativePath) {
    var basePath = window.location.pathname.match(/^(\/[^\/]+\/)/);
    var projectRoot = basePath ? window.location.protocol + '//' + window.location.host + basePath[1] : 'http://localhost/Hospital/';
    return projectRoot + relativePath.replace(/^\//, '');
  }

  var KNOWLEDGE_BASE_URL = buildUrl('frontend/text-knowledge.html');

  function request(path) {
    return fetch(API_BASE + path, {
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json; charset=utf-8' }
    }).then(function (response) { return response.json(); }).catch(function () { return null; });
  }

  function compact(items, formatter, limit) {
    if (!Array.isArray(items) || !items.length) return 'No data loaded';
    return items.slice(0, limit || 12).map(formatter).join(' | ').slice(0, 950);
  }

  function safeSetSessionVariables(vars) {
    if (!window.LiveChatWidget || !window.LiveChatWidget.call) return;
    try {
      window.LiveChatWidget.call('set_session_variables', vars);
    } catch (e) {
      // The widget queues calls before it is fully ready, but we keep this silent if Text changes the API.
    }
  }

  function loadHospitalContext() {
    return Promise.all([
      request('/api/auth/me'),
      request('/api/departments'),
      request('/api/doctors'),
      request('/api/services')
    ]).then(function (responses) {
      var me = responses[0];
      var departments = responses[1] && responses[1].success ? responses[1].data : [];
      var doctors = responses[2] && responses[2].success ? responses[2].data : [];
      var services = responses[3] && responses[3].success ? responses[3].data : [];
      var patient = me && me.success ? me.data : null;

      var variables = {
        hospital_name: 'Al Ahli Hospital',
        project_page: window.location.href,
        patient_logged_in: patient ? 'yes' : 'no',
        patient_username: patient ? String(patient.username || patient.full_name || patient.patient_id || '') : 'guest',
        knowledge_base_url: KNOWLEDGE_BASE_URL,
        booking_rule: 'Do not confirm a final appointment unless patient_logged_in is yes. If not logged in, help with information only and ask the patient to log in or create an account first.',
        booking_api_available_slots: API_BASE + '/api/appointments/available?doctor_id={doctor_id}&date=YYYY-MM-DD',
        booking_api_create: API_BASE + '/api/appointments',
        departments_count: String(departments.length),
        doctors_count: String(doctors.length),
        services_count: String(services.length),
        departments_summary: compact(departments, function (d) {
          return (d.name || '') + ' - ' + (d.location || '') + ' - ' + (d.working_hours || '');
        }, 10),
        doctors_summary: compact(doctors, function (d) {
          return (d.full_name || '') + ' - ' + (d.specialty || '') + ' - ' + (d.department_name || '');
        }, 14),
        services_summary: compact(services, function (s) {
          return (s.name || '') + ' - ' + (s.department_name || '') + ' - ' + (s.base_cost || '0') + ' NIS';
        }, 14),
        visitor_instruction: 'Use knowledge_base_url plus departments/doctors/services summaries to answer hospital questions in Arabic. For booking, talk naturally, collect specialty or doctor, date, time, reason, and email, then confirm by an email code before saving. Do not require login for booking.'
      };

      safeSetSessionVariables(variables);
    });
  }

  window.LiveChatWidget.on('ready', function () {
    loadHospitalContext();
  });

  // Also queue context loading shortly after page load in case the ready event fires before this script finishes.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(loadHospitalContext, 1200); });
  } else {
    setTimeout(loadHospitalContext, 1200);
  }
})();
