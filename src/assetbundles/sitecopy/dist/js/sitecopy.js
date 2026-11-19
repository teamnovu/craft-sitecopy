// Target-site checkboxes of a Site Copy pane, without the "Select all" toggle and
// without the field checkboxes (which live in the same options container).
function sitecopyTargetCheckboxes(sitecopyDiv) {
  return Array.from(
    sitecopyDiv.querySelectorAll('input[type="checkbox"][name="sitecopy[targets][]"]')
  ).filter(function(el) { return !(el.id || '').endsWith('sitecopy-toggle-all'); });
}

// Element IDs of the pane: one in the sidebar (data-element-id), several in the
// bulk copy overlay (data-element-ids, comma-separated).
function sitecopyElementIds(sitecopyDiv) {
  var raw = sitecopyDiv.dataset.elementIds || sitecopyDiv.dataset.elementId || '';

  return raw.split(',')
    .map(function(id) { return parseInt(id, 10); })
    .filter(function(id) { return id > 0; });
}

document.addEventListener('change', function(e) {
  if (e.target.name !== 'sitecopy[targets][]') return;

  var sitecopyDiv = e.target.closest('.sitecopy');
  if (!sitecopyDiv) return;

  var elementIds = sitecopyElementIds(sitecopyDiv);
  var sourceSiteId = parseInt(sitecopyDiv.dataset.sourceSiteId, 10);
  if (!elementIds.length || !sourceSiteId) return;

  var warningEl = sitecopyDiv.querySelector('#sitecopy-unavailable-warning');
  if (!warningEl) return;

  var selectedTargets = sitecopyTargetCheckboxes(sitecopyDiv)
    .filter(function(el) { return el.checked; })
    .map(function(el) { return parseInt(el.value, 10); })
    .filter(function(id) { return id > 0; });

  if (selectedTargets.length === 0) {
    warningEl.classList.add('hidden');
    return;
  }

  fetch(Craft.getActionUrl('site-copy-x/api/check-site-availability'), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-Token': Craft.csrfTokenValue,
    },
    body: JSON.stringify({ elementIds: elementIds, sourceSiteId: sourceSiteId }),
  })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success) return;

      var unavailableSelected = data.unavailableSites.filter(function(s) {
        return selectedTargets.includes(s.id);
      });

      if (unavailableSelected.length > 0) {
        var names = unavailableSelected.map(function(s) { return s.name; }).join(', ');
        var message = elementIds.length > 1
          ? 'Warning: some of the selected elements do not exist on the following sites and will not be copied there: {sites}'
          : 'Warning: the element does not exist on the following sites and will not be copied: {sites}';

        warningEl.textContent = Craft.t('site-copy-x', message, { sites: names });
        warningEl.classList.remove('hidden');
      } else {
        warningEl.classList.add('hidden');
      }
    })
    .catch(function(e) { console.error('sitecopy availability check failed', e); });
});

function toggleSitecopyTargets(source) {
  const sitecopyDiv = source.closest('.sitecopy');

  sitecopyTargetCheckboxes(sitecopyDiv).forEach(function(checkbox) {
    checkbox.checked = source.checked;
  });
}

function updateSitecopyToggleAll(source) {
  const sitecopyDiv = source.closest('.sitecopy');
  const toggleAll = sitecopyDiv.querySelector('[id$="sitecopy-toggle-all"]');

  if (toggleAll) {
    toggleAll.checked = sitecopyTargetCheckboxes(sitecopyDiv).every(function(checkbox) {
      return checkbox.checked;
    });
  }
}
