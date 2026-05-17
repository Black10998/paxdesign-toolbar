/**
 * PaxDesign Dock v8.1 — enterprise AI modules enhancements.
 */
(function () {
  'use strict';
  if (typeof apiFetch !== 'function' || typeof escHtml !== 'function') return;

  window.pdxStreamReply = function (container, role, fullText, onDone) {
    var div = document.createElement('div');
    div.className = 'pdx-chat-msg pdx-chat-msg--' + role;
    var bubble = document.createElement('div');
    bubble.className = 'pdx-chat-bubble';
    div.appendChild(bubble);
    container.appendChild(div);
    var chunks = [], i = 0, step = 48;
    for (var c = 0; c < fullText.length; c += step) chunks.push(fullText.slice(c, c + step));
    function tick() {
      if (i >= chunks.length) {
        bubble.innerHTML = escHtml(fullText).replace(/\n/g, '<br>');
        container.scrollTop = container.scrollHeight;
        if (onDone) onDone();
        return;
      }
      bubble.textContent = (bubble.textContent || '') + chunks[i++];
      container.scrollTop = container.scrollHeight;
      setTimeout(tick, 18);
    }
    tick();
    return div;
  };

  window.pdxLoadSavedFlows = function (type, paneId, onPick) {
    var path = type === 'pipeline' ? '/pipeline/flows' : '/builder/flows';
    apiFetch('GET', path).then(function (data) {
      var pane = document.getElementById(paneId);
      if (!pane || !data) return;
      var flows = data.flows || [];
      if (!flows.length) return;
      var html = '<div class="pdx-section-title pdx-mt-sm">Saved flows</div><div class="pdx-tpl-grid">';
      flows.forEach(function (f) {
        html += '<div class="pdx-tpl-card" data-flow-id="' + escHtml(f.flow_id) + '"><div class="pdx-tpl-name">' + escHtml(f.name) + '</div><button type="button" class="pdx-btn-ghost pdx-btn-sm pdx-load-flow">Load</button></div>';
      });
      html += '</div>';
      pane.insertAdjacentHTML('beforeend', html);
      pane.querySelectorAll('.pdx-load-flow').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.closest('[data-flow-id]').dataset.flowId;
          apiFetch('GET', path + '/' + id).then(function (flow) { if (flow && onPick) onPick(flow); });
        });
      });
    });
  };

  document.addEventListener('click', function (e) {
    var use = e.target.closest('.pdx-use-tpl');
    if (!use) return;
    var card = use.closest('[data-tpl-id]');
    if (!card) return;
    var tplId = card.dataset.tplId;
    var pane = card.closest('#pdx-builder-tpl-pane, #pdx-pipeline-tpl-pane');
    if (!pane) return;
    if (pane.id === 'pdx-builder-tpl-pane') {
      apiFetch('GET', '/builder/templates').then(function (data) {
        var t = (data.templates || []).find(function (x) { return x.id === tplId; });
        if (!t) return;
        var nameEl = document.getElementById('pdx-builder-name');
        if (nameEl) nameEl.value = t.label || tplId;
        var stepsEl = document.getElementById('pdx-builder-steps');
        if (!stepsEl || !t.steps) return;
        stepsEl.innerHTML = '';
        t.steps.forEach(function (s, i) {
          var row = document.createElement('div');
          row.className = 'pdx-step';
          row.dataset.idx = i;
          if (typeof renderStepRow === 'function') row.innerHTML = renderStepRow(i, s.type || 'llm', s.prompt || '');
          stepsEl.appendChild(row);
        });
        if (typeof showNotif === 'function') showNotif('Template loaded', 'info');
      });
    }
    if (pane.id === 'pdx-pipeline-tpl-pane') {
      apiFetch('GET', '/pipeline/templates').then(function (data) {
        var t = (data.templates || []).find(function (x) { return x.id === tplId; });
        if (!t || !t.agents) return;
        var list = document.getElementById('pdx-pipeline-agents');
        if (!list) return;
        list.innerHTML = '';
        t.agents.forEach(function (a) {
          var row = document.createElement('div');
          row.className = 'pdx-agent-row';
          row.innerHTML = '<select class="pdx-select pdx-agent-role"><option value="researcher">Researcher</option><option value="analyst">Analyst</option><option value="writer">Writer</option><option value="critic">Critic</option><option value="coordinator">Coordinator</option><option value="security">Security</option></select><input class="pdx-input pdx-agent-name" />';
          row.querySelector('.pdx-agent-role').value = a.role || 'coordinator';
          row.querySelector('.pdx-agent-name').value = a.name || a.role;
          list.appendChild(row);
        });
        if (typeof showNotif === 'function') showNotif('Pipeline template loaded', 'info');
      });
    }
  });
})();