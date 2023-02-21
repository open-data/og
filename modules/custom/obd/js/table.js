(function ($, Drupal, drupalSettings) {

  'use strict';

  // language settings

  var lang = $('html').attr('lang') || 'en' ;

  const langConfig = {
    en: {
      processing:     "processing...",
      search:         "Search",
      lengthMenu:     "Show _MENU_ entries",
      info:           "Showing _START_ to _END_ of _TOTAL_ entries",
      infoEmpty:      "Showing 0 to 0 of 0 entries",
      infoFiltered:   "(filtered from _MAX_ total entries)",
      loadingRecords: "loading...",
      zeroRecords:    "Showing 0 to 0 of 0 entries",
      emptyTable:     "No data is available in the table",
      paginate: {
          first:      "First",
          previous:   "Previous",
          next:       "Next",
          last:       "Last"
      },
      aria: {
          sortAscending:  ": activate for ascending sort",
          sortDescending: ": activate for descending sort"
      }
    },
    fr: {
      processing:     "traitement...",
      search:         "Recherche",
      lengthMenu:     "Afficher _MENU_ entrées",
      info:           "Affiche _START_ à _END_ de _TOTAL_ entrées",
      infoEmpty:      "Affiche 0 à 0 de 0 entrées",
      infoFiltered:   "(filtré de _MAX_ entrées totales)",
      loadingRecords: "chargement...",
      zeroRecords:    "Affiche 0 à 0 de 0 entrées",
      emptyTable:     "Aucune donnée n'est disponible dans le tableau",
      paginate: {
          first:      "Premier",
          previous:   "Précédent",
          next:       "Suivant",
          last:       "Dernier"
      },
      aria: {
          sortAscending:  " : activer pour tri ascendant",
          sortDescending: " : activer pour tri descendant"
      }
    }
  };

  const resource_columns = {
    en: {
      name: 'Name: ',
      access: 'Access',
      type: 'Type: ',
      format: 'Format: ',
      language: 'Language(s): ',
    },
    fr: {
      name: 'Nom : ',
      access: 'Accès',
      type: 'Type : ',
      format: 'Format : ',
      language: 'Langue(s) : ',
    }
  };


  // format resource list

  function formatResources(data, type, row) {
    var formattedResources = '<ul class="list-unstyled lst-spcd">';
    data.forEach(function(resource) {

      var name = '<li class="bg-info mrgn-tp-lg" style="padding:15px;">'
                     + resource_columns[lang]['name']
                     + resource['name_translated'][lang]
                     + '<a class="btn btn-sm btn-primary pull-right" target="_blank" href="/' + lang + resource['url'] + '">'
                     + resource_columns[lang]['access'] + '</a>'
                     + '</li>';
      formattedResources += name;

      var type = '<li style="padding:0 15px; text-transform: capitalize;">' + resource_columns[lang]['type'] + resource['resource_type'] + '</li>';
      formattedResources += type;

      var format = '<li style="padding:0 15px;">' + resource_columns[lang]['format'] + resource['format'] + '</li>';
      formattedResources += format;

      var res_langs = '<li style="padding:0 15px;">' + resource_columns[lang]['language'];
      resource['language'].forEach(function(res_lang, index) {
        if (index > 0)
          res_langs += ', ';
        switch (res_lang) {
          case 'en': res_langs += (lang == 'fr') ? 'Anglais' : 'English'; break;
          case 'fr': res_langs += (lang == 'fr') ? 'Français' : 'French'; break;
        }
      });
      res_langs += '</li>';
      formattedResources += res_langs;

    });

    formattedResources += '</ul><br/>'

    return formattedResources;
  }


  $(document).ready( function () {

    // checkbox filtering

    var filters = ['dt_department', 'dt_format', 'dt_resource_type', 'dt_language'];

    filters.forEach(function(filter) {
      $.fn.dataTable.ext.search.push(
        function( settings, searchData, index, rowData, counter ) {
          var values = $('input:checkbox[name="' + filter + '"]:checked').map(function() {
            return this.value;
          }).get();

          if (values.length === 0)
            { return true; }

          if (values.indexOf(searchData[$('input:checkbox[name="' + filter + '"]:checked').attr('data-column')]) !== -1)
            { return true; }

          return false;
        }
      );
    });


    // initialize datatable from json source for obd in the language of page

    var table = $('#dataset-filter1').DataTable({
        ordering : true,
        responsive: true,
        ajax: '/profiles/og/modules/custom/obd/data/obd_datasets.json',
        columns: [
          { data: 'title_translated.' + lang, "defaultContent": "" },
          { data: 'notes_translated.' + lang, "defaultContent": "" },
          { data: 'organization.title',
            render: function (data, type, row) { return (lang=='fr') ? data.split(" | ")[1] : data.split(" | ")[0]; } },
          { data: 'resources.0.format',
            render: function (data, type, row) { return '<span class="badge">' + data + '</span>'; } },
          { data: 'resources',
            render: function (data, type, row) { return formatResources(data, type, row); },
            className: 'none', },
          { data: 'metadata_modified',
            render: function (data, type, row) { return data.split("T")[0]; },
            className: 'none mrgn-bttm-lg', },
          { data: 'organization.name', visible: false },
          { data: 'resources.0.resource_type', visible: false },
          { data: 'resources.0.language', visible: false },
        ],
        dom: '<"top"fil<"clear">>rt<"bottom"ip<"clear">>',
        pageLength: 10,
        language: langConfig[lang],
    });

    $('input:checkbox').on('change', function () {
      table.draw();
    });

  });


})(jQuery, Drupal, drupalSettings);
