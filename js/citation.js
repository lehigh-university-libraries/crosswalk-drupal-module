(function (Drupal) {
  'use strict';

  var Cite = require('citation-js');

  // Check if Material Symbols font is loaded.
  function hasMaterialSymbols() {
    var test = document.createElement('span');
    test.className = 'material-symbols-outlined';
    test.style.position = 'absolute';
    test.style.visibility = 'hidden';
    test.textContent = 'check';
    document.body.appendChild(test);
    var width = test.offsetWidth;
    document.body.removeChild(test);
    // Material Symbols renders icons wider than fallback text at default size.
    return width > 0 && width < 40;
  }

  var formats = [
    {selector: '.crosswalk-citation-apa', template: 'apa'},
    {selector: '.crosswalk-citation-mla', template: 'mla'},
    {selector: '.crosswalk-citation-chicago', template: 'chicago-fullnote-bibliography'},
  ];

  Drupal.behaviors.crosswalkCitation = {
    attach: function (context) {
      // Render citation formats from CSL-JSON data.
      var elements = context.querySelectorAll('[data-crosswalk-csl]');
      elements.forEach(function (element) {
        if (element.dataset.crosswalkProcessed) {
          return;
        }
        element.dataset.crosswalkProcessed = 'true';

        var cslData = element.dataset.crosswalkCsl;
        if (!cslData) {
          return;
        }

        try {
          var data = JSON.parse(cslData);
          var cite = new Cite(data);

          var cslJsonPre = element.querySelector('.crosswalk-csl-json');
          if (cslJsonPre) {
            cslJsonPre.textContent = JSON.stringify(data, null, 2);
          }

          formats.forEach(function (fmt) {
            var target = element.querySelector(fmt.selector);
            if (target) {
              target.innerHTML = cite.format('bibliography', {
                format: 'html',
                template: fmt.template,
                lang: 'en-US',
              });
            }
          });
        }
        catch (e) {
          // Fail silently if citation.js cannot parse the data.
        }
      });

      // Pretty-print schema.org JSON.
      var schemaorgPres = context.querySelectorAll('.crosswalk-schemaorg');
      schemaorgPres.forEach(function (pre) {
        if (pre.dataset.crosswalkProcessed) {
          return;
        }
        pre.dataset.crosswalkProcessed = 'true';

        var raw = pre.textContent.trim();
        if (!raw) {
          // Content is in the schemaorg Twig variable, passed server-side.
          // Find it from the pane's sibling data or from a data attribute.
          return;
        }
        try {
          pre.textContent = JSON.stringify(JSON.parse(raw), null, 2);
        }
        catch (e) {
          // Not valid JSON, leave as-is.
        }
      });

      // Copy to clipboard buttons.
      var materialLoaded = hasMaterialSymbols();
      var copyButtons = context.querySelectorAll('.crosswalk-copy');
      copyButtons.forEach(function (btn) {
        if (btn.dataset.crosswalkCopyBound) {
          return;
        }
        btn.dataset.crosswalkCopyBound = 'true';

        var iconSpan = btn.querySelector('.material-symbols-outlined');
        var fallbackSpan = btn.querySelector('.crosswalk-copy-fallback');

        if (materialLoaded) {
          if (fallbackSpan) {
            fallbackSpan.style.display = 'none';
          }
        }
        else {
          if (iconSpan) {
            iconSpan.style.display = 'none';
          }
        }

        btn.addEventListener('click', function () {
          var pane = btn.closest('[data-crosswalk-pane]');
          if (!pane) {
            return;
          }

          var source = pane.querySelector('pre') || pane.querySelector('script') || pane.querySelector('div');
          var text = source ? source.textContent : '';

          var copied = false;
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
            copied = true;
          }
          else {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
              copied = document.execCommand('copy');
            }
            catch (e) {
              // Copy not supported.
            }
            document.body.removeChild(textarea);
          }

          if (copied) {
            if (materialLoaded && iconSpan) {
              iconSpan.textContent = 'check';
              setTimeout(function () {
                iconSpan.textContent = 'content_copy';
              }, 2000);
            }
            else if (fallbackSpan) {
              fallbackSpan.textContent = 'Copied!';
              setTimeout(function () {
                fallbackSpan.textContent = 'Copy';
              }, 2000);
            }
          }
        });
      });

      // Tab switching fallback when Bootstrap JS is not loaded.
      var tabs = context.querySelectorAll('.crosswalk-citation [data-crosswalk-tab]');
      tabs.forEach(function (tab) {
        if (tab.dataset.crosswalkTabBound) {
          return;
        }
        tab.dataset.crosswalkTabBound = 'true';

        tab.addEventListener('click', function () {
          var container = tab.closest('.crosswalk-citation');
          var target = tab.dataset.crosswalkTab;

          container.querySelectorAll('[data-crosswalk-tab]').forEach(function (t) {
            t.classList.remove('active');
            t.setAttribute('aria-selected', 'false');
          });
          tab.classList.add('active');
          tab.setAttribute('aria-selected', 'true');

          container.querySelectorAll('[data-crosswalk-pane]').forEach(function (pane) {
            pane.classList.remove('show', 'active');
          });
          var activePane = container.querySelector('[data-crosswalk-pane="' + target + '"]');
          if (activePane) {
            activePane.classList.add('show', 'active');
          }
        });
      });
    },
  };
})(Drupal);
