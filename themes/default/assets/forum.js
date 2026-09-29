/* LibreForum: a few small conveniences. Everything works without this file. */
(function () {
  'use strict';

  // "Are you sure?" before forms that ask for it (data-confirm).
  document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute && event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

  // Times: hover to see the exact moment in the reader's own time zone.
  document.querySelectorAll('time[datetime]').forEach(function (el) {
    var date = new Date(el.getAttribute('datetime'));
    if (!isNaN(date.getTime())) {
      el.title = date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }
  });

  // Text boxes grow with what is typed. Ctrl+Enter (or Cmd+Enter) sends.
  document.querySelectorAll('textarea.lf-textarea').forEach(function (box) {
    function grow() {
      box.style.height = 'auto';
      box.style.height = Math.min(box.scrollHeight + 2, window.innerHeight * 0.7) + 'px';
    }
    box.addEventListener('input', grow);
    box.addEventListener('keydown', function (event) {
      if ((event.ctrlKey || event.metaKey) && event.key === 'Enter' && box.form) {
        event.preventDefault();
        if (box.form.checkValidity()) {
          box.form.requestSubmit();
        } else {
          box.form.reportValidity();
        }
      }
    });
    if (box.value) {
      grow();
    }
  });

  // Copy buttons (the invitation link).
  document.querySelectorAll('button[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var text = button.getAttribute('data-copy');
      var done = function () {
        var old = button.innerHTML;
        button.textContent = 'Copied';
        setTimeout(function () { button.innerHTML = old; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () {});
      } else {
        var field = button.parentNode.querySelector('input');
        if (field) {
          field.select();
          document.execCommand('copy');
          done();
        }
      }
    });
  });
})();
