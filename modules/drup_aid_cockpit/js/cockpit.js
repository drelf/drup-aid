/**
 * @file
 * Drup-AID agent cockpit behaviour. Applies per-agent accent colours to the
 * roster and renders the conversation theatre. In this V0, a "send" plays a
 * scripted master -> sub-agent demo so the cockpit is visible end-to-end; the
 * live run wires to the AI Agents trace (poll endpoint) next.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  // Pacing for the demo playback.
  var TYPING_MS = 900;
  var GAP_MS = 350;

  // Per-sub-agent demo lines, keyed by agent id (generic fallback otherwise).
  var DEMO_LINES = {
    peak_page_builder: 'Scaffolding the page sections to the playbook — hero, value, proof, CTA.',
    drupaid_content: 'Drafting the copy in your brand voice and slotting it into the sections.',
    peak_security_monitor: 'Scanning permissions, text formats, and exposed fields — no blockers found.',
    drupaid_kb: 'Writing the knowledge-base article and saving it as a draft.',
    drupaid_analytics: 'Pulling the latest numbers and summarising the trend.',
    drupaid_leads: 'Checking the lead desk for anything pending.',
    peak_web_editor: 'Updating the existing copy and saving a new revision.'
  };

  function sleep(ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
  }

  // Avatar image when artwork is installed; colored-initial badge otherwise.
  function avatarHtml(agent) {
    if (agent && agent.avatar) {
      return '<img src="' + agent.avatar + '" alt="">';
    }
    var initial = (agent && agent.initial ? String(agent.initial) : '?').replace(/[<>&"']/g, '');
    return '<span class="dac-initial">' + initial + '</span>';
  }

  Drupal.behaviors.drupAidCockpit = {
    attach: function (context) {
      var roots = once('drup-aid-cockpit', '[data-drup-aid-cockpit]', context);
      roots.forEach(function (root) {
        new Cockpit(root).init();
      });
    }
  };

  function Cockpit(root) {
    this.root = root;
    this.settings = drupalSettings.drupAidCockpit || {};
    this.master = this.settings.master || null;
    this.agents = this.settings.agents || [];
    this.thread = root.querySelector('[data-dac-thread]');
    this.activity = root.querySelector('[data-dac-activity]');
    this.form = root.querySelector('[data-dac-form]');
    this.field = root.querySelector('[data-dac-field]');
    this.taskbar = root.querySelector('[data-dac-taskbar]');
    this.taskbarLabel = root.querySelector('[data-dac-taskbar-label]');
    this.taskbarProgress = root.querySelector('[data-dac-taskbar-progress]');
    this.tasks = root.querySelector('[data-dac-tasks]');
    this.taskTotal = 0;
    this.taskDone = 0;
    this.busy = false;
  }

  Cockpit.prototype.init = function () {
    var self = this;
    this.live = this.settings.live || null;
    // Demo is ALWAYS the default — live runs call the user's real AI provider
    // (and spend their credits), so going live is an explicit, remembered
    // opt-in via the mode badge.
    this.liveOn = !!(this.live && window.localStorage && localStorage.getItem('dacLiveMode') === '1');
    // Paint each roster badge with its accent colour.
    this.root.querySelectorAll('[data-color]').forEach(function (el) {
      el.style.setProperty('--agent-color', el.getAttribute('data-color'));
    });
    // The mode badge: a live/demo toggle when the live transport is available.
    var badge = this.root.querySelector('.dac__mode');
    if (badge && this.live) {
      badge.style.cursor = 'pointer';
      badge.addEventListener('click', function () {
        if (!self.liveOn && !window.confirm(
          'Go live? Messages will run your REAL agents through your configured AI provider ' +
          'and use your API credits (typically well under a cent per message).')) {
          return;
        }
        self.liveOn = !self.liveOn;
        try { localStorage.setItem('dacLiveMode', self.liveOn ? '1' : '0'); } catch (e) {}
        self.paintBadge(badge);
      });
    }
    if (badge) { this.paintBadge(badge); }
    this.form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = self.field.value.trim();
      if (text && !self.busy) {
        self.field.value = '';
        if (self.liveOn) {
          self.runLive(text);
        }
        else {
          self.run(text);
        }
      }
    });
    // Opening line from the master so the pane is never empty.
    if (this.master) {
      this.appendAgent(this.master, 'Hi — I’m your master agent. Tell me what you want done and I’ll put the right specialists on it.', false);
    }
  };

  // Reflect the current mode on the hub badge.
  Cockpit.prototype.paintBadge = function (badge) {
    if (!this.live) {
      badge.textContent = 'Demo mode';
      badge.title = 'Scripted demo. Install the AI Agents Explorer + a default chat provider to enable live runs.';
      return;
    }
    if (this.liveOn) {
      badge.textContent = 'LIVE — real agent runs';
      badge.title = 'Messages run your real agents (uses your AI provider credits). Click for demo mode.';
    }
    else {
      badge.textContent = 'Demo mode — click to go live';
      badge.title = 'Scripted demo, no AI calls. Click to run your real agents (uses your API credits).';
    }
  };

  // ── LIVE MODE ─────────────────────────────────────────────────────────
  // Send the prompt to the real master agent via the AI Agents Explorer
  // start endpoint, and stream its decision log (poll endpoint) into the
  // theatre: entries attributable to a sub-agent render as that droplet's
  // bubble; everything else lands in the activity pane.

  Cockpit.prototype.runLive = function (text) {
    var self = this;
    this.busy = true;
    this.appendUser(text);
    this.setActive(this.master ? this.master.id : null, true);

    var runnerId = (window.crypto && crypto.randomUUID)
      ? crypto.randomUUID()
      : 'dac-' + Date.now() + '-' + Math.random().toString(16).slice(2);

    var label = text.length > 60 ? text.slice(0, 57) + '…' : text;
    this.taskbarLabel.textContent = '“' + label + '”';
    this.taskbarProgress.textContent = 'Running…';
    this.taskbar.hidden = false;
    this.tasks.innerHTML = '<p class="dac__payload-empty">Live run — specialists appear as the master calls them.</p>';

    var typing = this.showTyping(this.master, false);
    var seen = {};
    var pollUrl = this.live.poll.replace('RUNNER_UUID', runnerId);

    var poll = function () {
      fetch(pollUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          (data.entries || []).forEach(function (entry) {
            if (seen[entry.id]) { return; }
            seen[entry.id] = true;
            self.renderDecision(entry);
          });
        })
        .catch(function () { /* polling is best-effort */ });
    };
    var timer = setInterval(poll, 1500);

    var body = new FormData();
    body.append('prompt', text);
    body.append('agent', this.live.agent);
    body.append('model', this.live.model);
    body.append('runner_id', runnerId);

    fetch(this.live.start, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        clearInterval(timer);
        setTimeout(poll, 400); // one final sweep for late entries
        typing.remove();
        var msg = data.message || data.error || 'No response.';
        msg = msg.replace(/^Status:\s*[^,]+,\s*Response:\s*/i, '');
        self.appendAgent(self.master, msg, false);
        self.taskbarProgress.textContent = data.success ? 'Complete ✓' : 'Failed';
        self.busy = false;
      })
      .catch(function (err) {
        clearInterval(timer);
        typing.remove();
        self.appendAgent(self.master, 'Run failed: ' + err, false);
        self.taskbarProgress.textContent = 'Failed';
        self.busy = false;
      });
  };

  // Map one decision-log entry to the theatre: sub-agent entries become that
  // droplet's bubble; master/internal steps go to the activity pane.
  Cockpit.prototype.renderDecision = function (entry) {
    var hay = (entry.label || '') + ' ' + (entry.json || '');
    var agent = null;
    for (var i = 0; i < this.agents.length; i++) {
      if (hay.indexOf(this.agents[i].id) !== -1) {
        agent = this.agents[i];
        break;
      }
    }
    var line = entry.label || 'working…';
    if (agent) {
      this.setActive(agent.id, true);
      this.pushActivity(agent, line);
      var snippet = this.snippet(entry.json);
      this.appendAgent(agent, snippet || line, true);
      this.addLiveTaskRow(agent);
    }
    else {
      this.pushActivity(this.master, line);
    }
  };

  // First readable chunk of a decision payload, for the bubble text.
  Cockpit.prototype.snippet = function (json) {
    if (!json) { return ''; }
    var el = document.createElement('textarea');
    el.innerHTML = String(json);
    var text = el.value.replace(/[{}\[\]"]/g, ' ').replace(/\s+/g, ' ').trim();
    return text.length > 220 ? text.slice(0, 217) + '…' : text;
  };

  // Add (once) a status row for a specialist seen during a live run.
  Cockpit.prototype.addLiveTaskRow = function (agent) {
    var empty = this.tasks.querySelector('.dac__payload-empty');
    if (empty) { empty.remove(); }
    if (this.tasks.querySelector('[data-task-agent="' + agent.id + '"]')) { return; }
    var row = document.createElement('div');
    row.className = 'dac-task dac-task--working';
    row.setAttribute('data-task-agent', agent.id);
    row.innerHTML =
      '<span class="dac-task__icon"></span>' +
      '<span class="dac-task__name"></span>' +
      '<span class="dac-task__pill">Called</span>';
    row.querySelector('.dac-task__name').textContent = agent.label;
    this.tasks.appendChild(row);
  };

  Cockpit.prototype.run = function (text) {
    var self = this;
    this.busy = true;
    this.appendUser(text);
    this.setActive(this.master ? this.master.id : null, true);

    var picks = this.agents.slice(0, 3);
    this.startTask(text, picks);
    var chain = Promise.resolve();

    // Master triages.
    chain = chain.then(function () {
      return self.say(self.master, 'Got it — “' + text + '”. Breaking this down and routing to the team…', false);
    });

    // Each specialist takes its turn.
    picks.forEach(function (agent) {
      chain = chain.then(function () {
        self.setActive(agent.id, true);
        self.setTaskStatus(agent.id, 'working');
        var line = DEMO_LINES[agent.id] || (agent.label + ' is handling its part.');
        self.pushActivity(agent, line);
        return self.say(agent, line, true).then(function () {
          self.setTaskStatus(agent.id, 'done');
        });
      });
    });

    // Master reports back.
    chain = chain.then(function () {
      var names = picks.map(function (a) { return a.label; }).join(', ');
      self.finishTask();
      return self.say(self.master, 'All set — ' + names + ' finished. Want me to publish, or refine anything?', false);
    }).then(function () {
      self.busy = false;
    });
  };

  // Show a typing indicator for `agent`, wait, then append the message.
  Cockpit.prototype.say = function (agent, text, isSub) {
    var self = this;
    var typing = this.showTyping(agent, isSub);
    return sleep(TYPING_MS).then(function () {
      typing.remove();
      self.appendAgent(agent, text, isSub);
      return sleep(GAP_MS);
    });
  };

  Cockpit.prototype.appendUser = function (text) {
    var row = document.createElement('div');
    row.className = 'dac-msg dac-msg--user';
    row.innerHTML =
      '<span class="dac-msg__avatar dac-msg__avatar--user">👤</span>' +
      '<div class="dac-msg__body"><div class="dac-msg__bubble"></div></div>';
    row.querySelector('.dac-msg__bubble').textContent = text;
    this.add(row);
  };

  Cockpit.prototype.appendAgent = function (agent, text, isSub) {
    var row = document.createElement('div');
    row.className = 'dac-msg' + (isSub ? ' dac-msg--sub' : '');
    if (agent && agent.color) {
      row.style.setProperty('--agent-color', agent.color);
    }
    row.innerHTML =
      '<span class="dac-msg__avatar">' + avatarHtml(agent) + '</span>' +
      '<div class="dac-msg__body">' +
        '<div class="dac-msg__name"></div>' +
        '<div class="dac-msg__bubble"></div>' +
      '</div>';
    row.querySelector('.dac-msg__name').textContent = agent ? agent.label : '';
    row.querySelector('.dac-msg__bubble').innerHTML = this.format(text);
    this.add(row);
  };

  // Minimal, safe chat formatting: escape everything first, then allow
  // bold, headers-as-bold, numbered-list line breaks, and paragraphs.
  Cockpit.prototype.format = function (text) {
    var el = document.createElement('div');
    el.textContent = String(text || '');
    var safe = el.innerHTML;
    return safe
      .replace(/---/g, '')
      .replace(/#{2,}\s*([^\n#]+)/g, '<strong>$1</strong>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\s(\d+)\.\s\*?\*?/g, '<br>$1. ')
      .replace(/\n\n/g, '<br><br>')
      .replace(/\n/g, '<br>');
  };

  Cockpit.prototype.showTyping = function (agent, isSub) {
    var row = document.createElement('div');
    row.className = 'dac-msg' + (isSub ? ' dac-msg--sub' : '');
    if (agent && agent.color) {
      row.style.setProperty('--agent-color', agent.color);
    }
    row.innerHTML =
      '<span class="dac-msg__avatar">' + avatarHtml(agent) + '</span>' +
      '<div class="dac-msg__body"><div class="dac-typing"><span></span><span></span><span></span></div></div>';
    this.add(row);
    return row;
  };

  Cockpit.prototype.pushActivity = function (agent, what) {
    if (this.activity.querySelector('.dac__payload-empty')) {
      this.activity.innerHTML = '';
    }
    var row = document.createElement('div');
    row.className = 'dac-activity';
    if (agent && agent.color) {
      row.style.setProperty('--agent-color', agent.color);
    }
    row.innerHTML =
      '<span class="dac-activity__dot"></span>' +
      '<span><span class="dac-activity__who"></span> <span class="dac-activity__what"></span></span>';
    row.querySelector('.dac-activity__who').textContent = agent ? agent.label : '';
    row.querySelector('.dac-activity__what').textContent = what;
    this.activity.appendChild(row);
  };

  // Open a task: show the bar above the chat + a status row per specialist.
  Cockpit.prototype.startTask = function (text, picks) {
    this.taskTotal = picks.length;
    this.taskDone = 0;
    var label = text.length > 60 ? text.slice(0, 57) + '…' : text;
    this.taskbarLabel.textContent = '“' + label + '”';
    this.taskbar.hidden = false;
    this.updateProgress();

    this.tasks.innerHTML = '';
    var self = this;
    picks.forEach(function (agent) {
      var row = document.createElement('div');
      row.className = 'dac-task dac-task--queued';
      row.setAttribute('data-task-agent', agent.id);
      row.innerHTML =
        '<span class="dac-task__icon"></span>' +
        '<span class="dac-task__name"></span>' +
        '<span class="dac-task__pill">Queued</span>';
      row.querySelector('.dac-task__name').textContent = agent.label;
      self.tasks.appendChild(row);
    });
  };

  // Move a specialist's status: 'working' | 'done'.
  Cockpit.prototype.setTaskStatus = function (id, status) {
    var row = this.tasks.querySelector('[data-task-agent="' + id + '"]');
    if (!row) {
      return;
    }
    row.className = 'dac-task dac-task--' + status;
    var pill = row.querySelector('.dac-task__pill');
    pill.textContent = status === 'working' ? 'Working…' : 'Done';
    if (status === 'done') {
      this.taskDone += 1;
      this.updateProgress();
    }
  };

  Cockpit.prototype.updateProgress = function () {
    this.taskbarProgress.textContent = this.taskDone + '/' + this.taskTotal;
  };

  Cockpit.prototype.finishTask = function () {
    this.taskbarProgress.textContent = 'Complete ✓';
  };

  Cockpit.prototype.setActive = function (id, on) {
    if (!id) {
      return;
    }
    var el = this.root.querySelector('.dac-agent[data-agent-id="' + id + '"]');
    if (el) {
      el.classList.toggle('is-active', !!on);
    }
  };

  Cockpit.prototype.add = function (row) {
    this.thread.appendChild(row);
    this.thread.scrollTop = this.thread.scrollHeight;
  };

})(Drupal, drupalSettings, once);
