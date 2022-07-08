/**
 * @file
 * Map viewer for map gallery
 */

(function ($, Drupal) {

  'use strict';

  Drupal.behaviors.MapViewer = {
    attach: function (context, settings) {
      var keys = drupalSettings.map_keys.split(',');
      RV.getMap('fgpmap').restoreSession(keys);
    }
  };

})(window.jQuery, window.Drupal, window.drupalSettings);
