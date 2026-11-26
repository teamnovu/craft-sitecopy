// Craft 6: opens the bulk copy overlay for the "Copy to site" client action.
(function() {
  if (window.sitecopyBulkCopyReady) return;
  window.sitecopyBulkCopyReady = true;

  function showError(message) {
    if (Craft.cp && Craft.cp.displayError) {
      Craft.cp.displayError(message);
    } else {
      console.error(message);
    }
  }

  function openModal(html, params) {
    var $modal = $('<div class="modal sitecopy-bulk-modal"></div>').append(html);
    // Destroyed on close: the overlay uses fixed element ids.
    var modal = new Garnish.Modal($modal, {
      onFadeOut: function() {
        modal.destroy();
      },
    });
    modal.desiredWidth = 700;
    modal.desiredHeight = 600;
    modal.show();

    $modal.find('.cancel').on('click', function() {
      modal.hide();
    });

    $modal.find('form').on('submit', function(e) {
      e.preventDefault();
      var $submit = $modal.find('.submit').prop('disabled', true);
      var formData = new FormData(this);

      Object.keys(params).forEach(function(key) {
        formData.append(key, params[key]);
      });

      Craft.sendActionRequest('POST', 'site-copy-x/api/bulk-copy', { data: formData })
        .then(function(res) {
          if (res.data.success) {
            location.reload();
          } else {
            $submit.prop('disabled', false);
            showError(res.data.error || 'Site Copy X: copy failed');
          }
        })
        .catch(function(err) {
          $submit.prop('disabled', false);
          showError((err.response && err.response.data && err.response.data.message) || err.message);
        });
    });
  }

  // The selection comes from the event detail; Craft.currentElementIndex doesn't exist on Vue index pages.
  window.addEventListener('site-copy-x:bulk-copy', function(e) {
    var detail = e.detail || {};
    var index = detail.indexParams || {};
    var criteria = Object.assign({}, index.baseCriteria || {}, index.criteria || {});
    var params = {
      ids: (detail.elementIds || index.elementIds || []).join(','),
      elementType: detail.elementType || index.elementType || '',
      siteId: criteria.siteId || index.siteId || '',
      sourceKey: index.source || '',
    };

    Craft.sendActionRequest('POST', 'site-copy-x/api/bulk-copy-overlay', { data: params })
      .then(function(res) {
        if (res.data.success && res.data.html) {
          openModal(res.data.html, params);
        }
      })
      .catch(function(err) {
        showError((err.response && err.response.data && err.response.data.message) || err.message);
      });
  });
})();
