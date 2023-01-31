(function ($, Drupal, drupalSettings) {

  'use strict';

// format resource list
function formatResources(data, type, row) {
  var formattedResources = '<ul class="list-unstyled lst-spcd">';
  data.forEach(function(resource, index) {
    var name = '<li class="bg-info mrgn-tp-lg" style="padding:15px;">Name: ' 
                   + resource['name_translated']['en']
                   + '<a class="btn btn-sm btn-primary pull-right" target="_blank" href="' + resource["url"] + '">Access</a>'
                   + '</li>';
    formattedResources += name;
    var type = '<li style="padding:0 15px; text-transform: capitalize;">Type: ' + resource['resource_type'] + '</li>';
    formattedResources += type;
    var format = '<li style="padding:0 15px;">Format: ' + resource['format'] + '</li>';
    formattedResources += format;
    var lang = '<li style="padding:0 15px;">Language: ';
    switch (resource['language'][0]) {
      case 'en': lang += 'English'; break; 
      case 'fr': lang += 'French'; break;
    }
      lang += '</li>';
    formattedResources += lang;
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


  // initialize datatable from json source for obd

  var table = $('#dataset-filter1').DataTable({
      ordering : true,
      responsive: true,
      ajax: '/profiles/og/modules/custom/obd/data/obd_datasets.json',
      columns: [
        { data: 'title_translated.en' },
        { data: 'notes_translated.en' },
        { data: 'organization.title',
          render: function (data, type, row) { return data.split(" | ")[0]; } },
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
  });

  $('input:checkbox').on('change', function () {
    table.draw();
 });

} );


})(jQuery, Drupal, drupalSettings);
