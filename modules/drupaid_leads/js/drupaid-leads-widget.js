/**
 * @file
 * Drup-AID floating lead-capture widget — a bottom-right bubble that collects a
 * visitor's name, phone, email, and optional message and sends it to the
 * administrator. No AI, says nothing about the business — just captures + hands
 * off, as simply and reliably as possible.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.drupaidLeadsWidget = {
    attach: function (context) {
      once('drupaid-leads-widget', 'body', context).forEach(function (body) {
        var settings = drupalSettings.drupaidLeads || {};
        var captureUrl = settings.captureUrl || '/api/lead-capture';
        var title = settings.title || 'Get in touch';
        var intro = settings.intro || 'Leave your details and we\'ll get right back to you.';

        // --- Launcher ---
        var launcher = document.createElement('button');
        launcher.type = 'button';
        launcher.className = 'dlw__launcher';
        launcher.setAttribute('aria-label', 'Contact us');
        launcher.setAttribute('aria-expanded', 'false');
        launcher.textContent = '💬';

        // --- Panel ---
        var panel = document.createElement('div');
        panel.className = 'dlw__panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', title);
        panel.hidden = true;
        panel.innerHTML =
          '<div class="dlw__header">' +
            '<span class="dlw__title"></span>' +
            '<button type="button" class="dlw__close" aria-label="Close">×</button>' +
          '</div>' +
          '<div class="dlw__body">' +
            '<p class="dlw__intro"></p>' +
            '<form class="dlw__form">' +
              '<input class="dlw__field" name="name" type="text" placeholder="Your name*" autocomplete="name" aria-label="Your name" required>' +
              '<input class="dlw__field" name="phone" type="tel" placeholder="Phone" autocomplete="tel" aria-label="Phone">' +
              '<input class="dlw__field" name="email" type="email" placeholder="Email" autocomplete="email" aria-label="Email">' +
              '<textarea class="dlw__field dlw__field--area" name="message" rows="3" placeholder="Message (optional)" aria-label="Message"></textarea>' +
              '<div class="dlw__error" role="alert" hidden></div>' +
              '<button type="submit" class="dlw__submit">Send</button>' +
            '</form>' +
            '<p class="dlw__thanks" hidden></p>' +
          '</div>';

        panel.querySelector('.dlw__title').textContent = title;
        panel.querySelector('.dlw__intro').textContent = intro;
        body.appendChild(launcher);
        body.appendChild(panel);

        var form = panel.querySelector('.dlw__form');
        var errorBox = panel.querySelector('.dlw__error');
        var thanks = panel.querySelector('.dlw__thanks');
        var submit = panel.querySelector('.dlw__submit');

        function togglePanel() {
          panel.hidden = !panel.hidden;
          launcher.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
          if (!panel.hidden) {
            var first = form.querySelector('[name="name"]');
            if (first) {
              first.focus();
            }
          }
        }

        launcher.addEventListener('click', togglePanel);
        panel.querySelector('.dlw__close').addEventListener('click', togglePanel);

        form.addEventListener('submit', function (event) {
          event.preventDefault();
          errorBox.hidden = true;
          var payload = {
            name: form.name.value.trim(),
            phone: form.phone.value.trim(),
            email: form.email.value.trim(),
            message: form.message.value.trim()
          };
          if (!payload.name || (!payload.phone && !payload.email)) {
            errorBox.textContent = 'Please add your name and a phone or email.';
            errorBox.hidden = false;
            return;
          }
          submit.disabled = true;
          submit.textContent = 'Sending…';

          fetch(captureUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          })
            .then(function (response) { return response.json(); })
            .then(function (data) {
              if (data && data.ok) {
                form.hidden = true;
                thanks.textContent = data.message || 'Thanks! We\'ll be in touch shortly.';
                thanks.hidden = false;
              }
              else {
                errorBox.textContent = (data && data.error) || 'Something went wrong. Please try again.';
                errorBox.hidden = false;
                submit.disabled = false;
                submit.textContent = 'Send';
              }
            })
            .catch(function () {
              errorBox.textContent = 'Something went wrong. Please try again.';
              errorBox.hidden = false;
              submit.disabled = false;
              submit.textContent = 'Send';
            });
        });
      });
    }
  };
})(Drupal, drupalSettings, once);
