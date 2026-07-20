// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

// Project implemented by the "Recovery, Transformation and Resilience Plan.
// Funded by the European Union - Next GenerationEU".
//
// Produced by the UNIMOODLE University Group: Universities of
// Valladolid, Complutense de Madrid, UPV/EHU, León, Salamanca,
// Illes Balears, Valencia, Rey Juan Carlos, La Laguna, Zaragoza, Málaga,
// Córdoba, Extremadura, Vigo, Las Palmas de Gran Canaria y Burgos.

/**
 * Executions log: error expanding, filter auto-submit, pagination and
 * auto-refresh while there are active requests.
 *
 * @module     local_coursetransfer/executions_page
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery'
], function($) {
    "use strict";

    var REFRESHSECONDS = 30;

    return {
        /**
         * @param {String} selector
         */
        init: function(selector) {
            var $root = $(selector);

            $root.on('click', '[data-action="refresh"]', function() {
                window.location.reload();
            });

            $root.on('click', '[data-action="toggle-error"]', function() {
                var $button = $(this);
                var $body = $button.closest('.ct-log-error').find('[data-region="error-body"]');
                var expanded = $button.attr('aria-expanded') === 'true';
                $button.attr('aria-expanded', expanded ? 'false' : 'true');
                $body.prop('hidden', expanded);
                $button.find('[data-region="chevron"]').css('transform',
                    expanded ? 'rotate(0deg)' : 'rotate(180deg)');
            });

            // Filters: submit on change, resetting to the first page.
            $root.on('change', '[data-autosubmit]', function() {
                var $form = $(this).closest('form');
                $form.find('[data-input="page"]').val(0);
                $form.trigger('submit');
            });

            // Pagination buttons submit the filter form with the new page.
            $root.on('click', '[data-action="page"]', function() {
                var $form = $root.find('[data-region="filters"]');
                $form.find('[data-input="page"]').val($(this).attr('data-page'));
                $form.trigger('submit');
            });

            // While something is moving, keep the view fresh.
            if ($root.attr('data-hasactive')) {
                setTimeout(function() {
                    window.location.reload();
                }, REFRESHSECONDS * 1000);
            }
        },
    };
});
