/**
 * @file
 * Map viewer for map gallery
 */

(function ($, Drupal) {

  'use strict';

  Drupal.behaviors.MapViewer = {
    attach: function (context, settings) {

      if (navigator.appName == 'Microsoft Internet Explorer' ||
          !!(navigator.userAgent.match(/Trident/) || navigator.userAgent.match(/rv:11/)) ||
          (typeof $.browser !== "undefined" && $.browser.msie == 1))
      {
        document.getElementById("ie").style.display = "block";
        document.getElementById("open-data-map").style.display = "none";
      }

      function getQueryVariable(variable)
      {
        var query = window.location.search.substring(1);
        var vars = query.split("&");
        for (var i=0;i<vars.length;i++) {
          var pair = vars[i].split("=");
          if(pair[0] === variable){return pair[1];}
        }
        return(false);
      }

      function bookmark(){
        return new Promise(function (resolve) {
          var thing = getQueryVariable("rv");
          console.log(thing);
          resolve(thing);
        });
      }

      var keys = drupalSettings.map_keys.split(',');
      RV.getMap('open-data-map').restoreSession(keys);
    }
  };

})(window.jQuery, window.Drupal, window.drupalSettings);
