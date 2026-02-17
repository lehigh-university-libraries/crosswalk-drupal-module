(function (Drupal) {
  'use strict';

  var Cite = require('citation-js');

  // Check if Material Symbols font is loaded.
  function hasMaterialSymbols() {
    if (document.fonts && document.fonts.check) {
      if (document.fonts.check('16px "Material Symbols Outlined"')) {
        return true;
      }
      if (document.fonts.check('16px "Material Icons"')) {
        return true;
      }
    }

    var test = document.createElement('span');
    test.className = 'material-symbols-outlined';
    test.style.position = 'absolute';
    test.style.visibility = 'hidden';
    test.style.fontSize = '16px';
    test.textContent = 'check';
    document.body.appendChild(test);
    var width = test.offsetWidth;
    document.body.removeChild(test);
    // Material Symbols glyph width at this size is typically narrower than
    // fallback text width for "check".
    return width > 0 && width < 20;
  }

  // CSL templates that need to be fetched and registered with citation-js.
  // The bundled citation.min.js only includes APA by default.
  var cslStyles = [
    {name: 'mla', url: 'https://cdn.jsdelivr.net/gh/citation-style-language/styles@master/modern-language-association.csl'},
    {name: 'chicago-fullnote-bibliography', url: 'https://cdn.jsdelivr.net/gh/citation-style-language/styles@master/chicago-notes-bibliography.csl'},
  ];

  var templatesReady = null;

  // Fetch and register MLA and Chicago CSL templates.
  function ensureTemplates() {
    if (templatesReady) {
      return templatesReady;
    }

    var cslConfig = Cite.plugins.config.get('@csl');

    templatesReady = Promise.all(cslStyles.map(function (style) {
      // Skip if already registered.
      if (cslConfig.templates.has(style.name)) {
        return Promise.resolve();
      }
      return fetch(style.url)
        .then(function (response) { return response.text(); })
        .then(function (xml) {
          cslConfig.templates.add(style.name, xml);
        });
    }));

    return templatesReady;
  }

  var formats = [
    {selector: '.crosswalk-citation-apa', template: 'apa'},
    {selector: '.crosswalk-citation-mla', template: 'mla'},
    {selector: '.crosswalk-citation-chicago', template: 'chicago-fullnote-bibliography'},
  ];

  // Splits a BibTeX entry body on top-level commas only.
  function splitTopLevelBibtex(body) {
    var parts = [];
    var current = '';
    var depth = 0;
    var inQuote = false;

    for (var i = 0; i < body.length; i++) {
      var ch = body[i];
      var prev = i > 0 ? body[i - 1] : '';

      if (ch === '"' && prev !== '\\') {
        inQuote = !inQuote;
        current += ch;
        continue;
      }

      if (!inQuote) {
        if (ch === '{') {
          depth++;
        }
        else if (ch === '}') {
          depth = Math.max(0, depth - 1);
        }
        else if (ch === ',' && depth === 0) {
          parts.push(current);
          current = '';
          continue;
        }
      }

      current += ch;
    }

    if (current.trim()) {
      parts.push(current);
    }

    return parts;
  }

  // Formats compact BibTeX into a readable multi-line entry.
  function formatBibtex(raw) {
    var text = raw.trim();
    if (!text || text[0] !== '@') {
      return raw;
    }

    var open = text.indexOf('{');
    var close = text.lastIndexOf('}');
    if (open < 0 || close <= open) {
      return raw;
    }

    var prefix = text.slice(0, open + 1).trim();
    var inner = text.slice(open + 1, close).trim();
    var segments = splitTopLevelBibtex(inner);
    if (segments.length < 2) {
      return raw;
    }

    var key = segments.shift().trim();
    if (!key) {
      return raw;
    }

    var fields = segments
      .map(function (segment) { return segment.trim().replace(/,+$/, ''); })
      .filter(Boolean);
    if (!fields.length) {
      return raw;
    }

    var lines = [prefix + key + ','];
    fields.forEach(function (field) {
      lines.push('  ' + field + ',');
    });
    lines.push('}');

    return lines.join('\n');
  }

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

          ensureTemplates().then(function () {
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

      // Normalize BibTeX formatting for readability.
      var bibtexPres = context.querySelectorAll('.crosswalk-bibtex');
      bibtexPres.forEach(function (pre) {
        if (pre.dataset.crosswalkProcessed) {
          return;
        }
        pre.dataset.crosswalkProcessed = 'true';

        pre.textContent = formatBibtex(pre.textContent);
      });

      // Copy to clipboard buttons.
      var materialLoaded = hasMaterialSymbols();
      var copyButtons = context.querySelectorAll('.crosswalk-copy');
      function updateCopyButtonIconMode() {
        materialLoaded = hasMaterialSymbols();
        copyButtons.forEach(function (btn) {
          var iconSpan = btn.querySelector('.material-symbols-outlined');
          var fallbackSpan = btn.querySelector('.crosswalk-copy-fallback');

          if (materialLoaded) {
            if (iconSpan) {
              iconSpan.style.display = '';
            }
            if (fallbackSpan) {
              fallbackSpan.style.display = 'none';
            }
          }
          else {
            if (iconSpan) {
              iconSpan.style.display = 'none';
            }
            if (fallbackSpan) {
              fallbackSpan.style.display = '';
            }
          }
        });
      }

      updateCopyButtonIconMode();

      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function () {
          updateCopyButtonIconMode();
        });
      }

      copyButtons.forEach(function (btn) {
        if (btn.dataset.crosswalkCopyBound) {
          return;
        }
        btn.dataset.crosswalkCopyBound = 'true';

        var iconSpan = btn.querySelector('.material-symbols-outlined');
        var fallbackSpan = btn.querySelector('.crosswalk-copy-fallback');

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
            btn.classList.add('is-copied');
            if (materialLoaded && iconSpan) {
              iconSpan.textContent = 'check';
              setTimeout(function () {
                iconSpan.textContent = 'content_copy';
                btn.classList.remove('is-copied');
              }, 2000);
            }
            else if (fallbackSpan) {
              fallbackSpan.textContent = 'Copied!';
              setTimeout(function () {
                fallbackSpan.textContent = 'Copy';
                btn.classList.remove('is-copied');
              }, 2000);
            }
            else {
              setTimeout(function () {
                btn.classList.remove('is-copied');
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
