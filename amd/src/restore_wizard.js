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
 * Admin restore assistant (SPA-lite orchestrator).
 *
 * Drives the landing + 4-step wizard + done screens rendered by the
 * restore_admin_page template, calling the restore_wizard web services.
 * All values coming from the web services are injected as text nodes
 * (never as HTML) to avoid injection.
 *
 * @module     local_coursetransfer/restore_wizard
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery',
    'core/ajax',
    'core/str'
], function($, Ajax, Str) {
    "use strict";

    var PERPAGE = 10;
    var TOTALSTEPS = 4;
    var SEARCHDEBOUNCE = 350;

    // String keys fetched once at init; filled into S.
    var STRINGKEYS = [
        'rw_selected', 'rw_selected_none', 'rw_step_of', 'rw_next', 'rw_run',
        'rw_pageinfo', 'rw_noresults', 'rw_done_desc', 'rw_users_on', 'rw_users_off',
        'rw_submit_error', 'rw_review_selected', 'rw_review_from', 'rw_review_to',
        'rw_review_ifexists', 'rw_review_users', 'rw_mode_merge', 'rw_mode_replace',
        'rw_kind_course', 'rw_kind_category', 'rw_more'
    ];

    var Wizard = {

        /**
         * Entry point.
         *
         * @param {String} selector Root selector.
         */
        init: function(selector) {
            var self = this;
            this.$root = $(selector);
            if (!this.$root.length) {
                return;
            }
            this.logurl = this.$root.attr('data-logurl') || '#';
            this.S = {};
            this.sites = [];
            this.items = [];
            this.searchtimer = null;
            this.resetState();

            var strrequests = STRINGKEYS.map(function(key) {
                return {key: key, component: 'local_coursetransfer'};
            });
            Str.get_strings(strrequests).then(function(values) {
                STRINGKEYS.forEach(function(key, i) {
                    self.S[key] = values[i];
                });
                return self.bind();
            }).catch(function() {
                // Even without strings the UI must respond.
                self.bind();
            });
        },

        /**
         * Reset the in-memory state.
         */
        resetState: function() {
            this.state = {
                view: 'landing',
                step: 0,
                siteid: null,
                sitename: '',
                type: null,
                checked: {},
                search: '',
                page: 0,
                pages: 1,
                total: 0,
                destcat: 0,
                mode: 'merge',
                confirmdestroy: false,
                includeusers: false
            };
        },

        /**
         * Bind delegated events and render the initial view.
         */
        bind: function() {
            var self = this;
            var $root = this.$root;

            $root.on('click', '[data-action="start"]', function() {
                self.start();
            });
            $root.on('click', '[data-action="cancel"]', function() {
                self.goLanding();
            });
            $root.on('click', '[data-action="go-landing"]', function() {
                self.goLanding();
            });
            $root.on('click', '[data-action="back"]', function() {
                self.back();
            });
            $root.on('click', '[data-action="primary"]', function() {
                self.primary();
            });

            // Step 0.
            $root.on('click', '[data-action="toggle-origin"]', function() {
                self.toggleOrigin($(this));
            });
            $root.on('click', '[data-action="toggle-type"]', function() {
                self.toggleType($(this).attr('data-type'));
            });

            // Step 1.
            $root.on('input', '[data-action="search"]', function() {
                var value = $(this).val();
                window.clearTimeout(self.searchtimer);
                self.searchtimer = window.setTimeout(function() {
                    self.state.search = value;
                    self.state.page = 0;
                    self.loadList();
                }, SEARCHDEBOUNCE);
            });
            $root.on('click', '[data-action="toggle-row"]', function() {
                self.toggleRow($(this));
            });
            $root.on('click', '[data-action="toggle-page"]', function() {
                self.togglePage();
            });
            $root.on('click', '[data-action="clear"]', function() {
                self.state.checked = {};
                self.renderCounter();
                self.renderRowsChecked();
                self.refreshFooter();
            });
            $root.on('click', '[data-action="prev"]', function() {
                if (self.state.page > 0) {
                    self.state.page -= 1;
                    self.loadList();
                }
            });
            $root.on('click', '[data-action="next-page"]', function() {
                if (self.state.page < self.state.pages - 1) {
                    self.state.page += 1;
                    self.loadList();
                }
            });

            // Step 2.
            $root.on('change', '[data-action="destcat"]', function() {
                self.state.destcat = parseInt($(this).val(), 10) || 0;
            });
            $root.on('click', '[data-action="toggle-mode"]', function() {
                self.toggleMode($(this).attr('data-mode'));
            });
            $root.on('click', '[data-action="toggle-confirm"]', function() {
                self.state.confirmdestroy = !self.state.confirmdestroy;
                $(this).attr('aria-checked', self.state.confirmdestroy ? 'true' : 'false');
                self.refreshFooter();
            });
            $root.on('click', '[data-action="toggle-users"]', function() {
                self.state.includeusers = !self.state.includeusers;
                $(this).attr('aria-checked', self.state.includeusers ? 'true' : 'false');
            });

            this.showView('landing');
        },

        /**
         * Switch the visible top-level region.
         *
         * @param {String} view landing|wizard|done
         */
        showView: function(view) {
            this.state.view = view;
            this.region('landing').prop('hidden', view !== 'landing');
            this.region('wizard').prop('hidden', view !== 'wizard');
            this.region('done').prop('hidden', view !== 'done');
        },

        /**
         * Find a data-region node under the root.
         *
         * @param {String} name
         * @return {jQuery}
         */
        region: function(name) {
            return this.$root.find('[data-region="' + name + '"]');
        },

        /**
         * Begin the wizard.
         */
        start: function() {
            this.resetState();
            this.state.view = 'wizard';
            this.showView('wizard');
            this.syncStep2Controls();
            this.goStep(0);
            if (!this.sites.length) {
                this.loadSites();
            }
        },

        /**
         * Return to the landing screen.
         */
        goLanding: function() {
            this.resetState();
            this.showView('landing');
        },

        /**
         * Show a wizard step.
         *
         * @param {Number} n
         */
        goStep: function(n) {
            this.state.step = n;
            this.$root.find('[data-step]').each(function() {
                var idx = parseInt($(this).attr('data-step'), 10);
                $(this).prop('hidden', idx !== n);
            });
            // Stepper markers.
            this.$root.find('.ct-step').each(function() {
                var idx = parseInt($(this).attr('data-step-index'), 10);
                var st = idx < n ? 'done' : (idx === n ? 'current' : 'pending');
                $(this).attr('data-state', st);
            });
            if (n === 1) {
                this.loadList();
            }
            if (n === 3) {
                this.renderReview();
            }
            this.refreshFooter();
        },

        /**
         * Update footer: back state, primary label/enabled, step number.
         */
        refreshFooter: function() {
            var step = this.state.step;
            var self = this;

            this.$root.find('[data-action="back"]').prop('disabled', step === 0);

            var $primary = this.$root.find('[data-action="primary"]');
            var islast = step === TOTALSTEPS - 1;
            var label = islast ? (this.S.rw_run || 'Run') : (this.S.rw_next || 'Next');
            $primary.empty().text(label);
            if (!islast) {
                $primary.append($('<i>').addClass('fa fa-chevron-right').attr('aria-hidden', 'true'));
            } else {
                $primary.prepend($('<i>').addClass('fa fa-play').attr('aria-hidden', 'true'));
            }
            $primary.prop('disabled', !this.canProceed());

            var stepinfo = (this.S.rw_step_of || 'Step {$a->n} of {$a->total}')
                .replace('{$a->n}', step + 1).replace('{$a->total}', TOTALSTEPS);
            this.region('stepinfo').text(stepinfo);

            // Re-evaluate proceed capability lazily on step 2 confirm changes.
            window.setTimeout(function() {
                self.$root.find('[data-action="primary"]').prop('disabled', !self.canProceed());
            }, 0);
        },

        /**
         * Whether the current step is complete enough to advance.
         *
         * @return {Boolean}
         */
        canProceed: function() {
            var s = this.state;
            if (s.step === 0) {
                return !!s.siteid && !!s.type;
            }
            if (s.step === 1) {
                return this.selectedIds().length > 0;
            }
            if (s.step === 2) {
                return s.mode !== 'replace' || s.confirmdestroy;
            }
            return true;
        },

        /**
         * Primary button: advance or, on the last step, submit.
         */
        primary: function() {
            if (!this.canProceed()) {
                return;
            }
            if (this.state.step === TOTALSTEPS - 1) {
                this.submit();
            } else {
                this.goStep(this.state.step + 1);
            }
        },

        /**
         * Back button.
         */
        back: function() {
            if (this.state.step > 0) {
                this.goStep(this.state.step - 1);
            }
        },

        // ---- Step 0: sites ---------------------------------------------

        /**
         * Load the connected origin sites via web service.
         */
        loadSites: function() {
            var self = this;
            Ajax.call([{
                methodname: 'local_coursetransfer_restore_wizard_get_sites',
                args: {}
            }])[0].then(function(resp) {
                self.sites = resp.sites || [];
                return self.renderSites();
            }).catch(function() {
                self.region('sites').empty().append(
                    $('<div>').addClass('ct-selempty').text(self.S.rw_submit_error || 'Error'));
            });
        },

        /**
         * Render site cards (values injected as text).
         */
        renderSites: function() {
            var self = this;
            var $box = this.region('sites').empty();
            if (!this.sites.length) {
                $box.append($('<div>').addClass('ct-selempty').text(self.S.rw_noresults || '—'));
                return;
            }
            this.sites.forEach(function(site) {
                var offline = !site.connected;
                var $card = $('<button>')
                    .attr('type', 'button')
                    .addClass('ct-sitecard' + (offline ? ' ct-sitecard--offline' : ''))
                    .attr('data-action', 'toggle-origin')
                    .attr('data-siteid', site.id)
                    .attr('data-sitename', site.name)
                    .attr('aria-pressed', 'false')
                    .prop('disabled', offline);

                $card.append($('<span>').addClass('ct-sitecard-icon')
                    .append($('<i>').addClass('fa fa-globe').attr('aria-hidden', 'true')));

                var $body = $('<span>').css({flex: 1, 'min-width': 0});
                $body.append($('<span>').addClass('ct-sitecard-name').text(site.name));
                $body.append($('<span>').addClass('ct-sitecard-status '
                    + (offline ? 'ct-sitecard-status--ko' : 'ct-sitecard-status--ok'))
                    .text(site.status || site.host));
                $card.append($body);

                // Selected indicator (ct-sitecard has no aria-pressed style in
                // the shared CSS, so we flag selection with a check + inline
                // brand accents rather than touching styles.css).
                $card.append($('<span>').addClass('ct-sitecard-check').css({
                    width: '26px', height: '26px', 'border-radius': '999px',
                    background: 'var(--color-primary)', color: '#fff', flex: 'none',
                    display: 'none', 'align-items': 'center', 'justify-content': 'center'
                }).append($('<i>').addClass('fa fa-check').attr('aria-hidden', 'true')));

                $box.append($card);
            });
        },

        /**
         * Select an origin site.
         *
         * @param {jQuery} $card
         */
        toggleOrigin: function($card) {
            if ($card.prop('disabled')) {
                return;
            }
            this.state.siteid = parseInt($card.attr('data-siteid'), 10);
            this.state.sitename = $card.attr('data-sitename') || '';
            this.region('sites').find('[data-action="toggle-origin"]').attr('aria-pressed', 'false')
                .css({'border-color': '', background: ''})
                .find('.ct-sitecard-check').css('display', 'none');
            $card.attr('aria-pressed', 'true')
                .css({'border-color': 'var(--color-primary)', background: 'var(--orange-50)'})
                .find('.ct-sitecard-check').css('display', 'flex');
            // Changing the site invalidates any previous selection.
            this.state.checked = {};
            this.state.page = 0;
            this.refreshFooter();
        },

        /**
         * Choose course/category.
         *
         * @param {String} type
         */
        toggleType: function(type) {
            this.state.type = type;
            this.state.checked = {};
            this.state.page = 0;
            this.$root.find('[data-action="toggle-type"]').each(function() {
                $(this).attr('aria-pressed', $(this).attr('data-type') === type ? 'true' : 'false');
            });
            this.region('catnote').prop('hidden', type !== 'category');
            this.refreshFooter();
        },

        // ---- Step 1: origin listing ------------------------------------

        /**
         * Load a page of origin items via web service.
         */
        loadList: function() {
            var self = this;
            if (!this.state.siteid || !this.state.type) {
                return;
            }
            var $rows = this.region('rows').empty();
            $rows.append($('<div>').addClass('ct-selempty').attr('data-region', 'rows-loading')
                .text((self.S.rw_pageinfo && '…') || '…'));

            Ajax.call([{
                methodname: 'local_coursetransfer_restore_wizard_list_origin',
                args: {
                    siteid: this.state.siteid,
                    type: this.state.type,
                    page: this.state.page,
                    perpage: PERPAGE,
                    query: this.state.search
                }
            }])[0].then(function(resp) {
                self.items = resp.items || [];
                self.state.total = resp.total || 0;
                self.state.pages = resp.pages || 1;
                self.state.page = resp.page || 0;
                return self.renderRows();
            }).catch(function() {
                self.region('rows').empty().append(
                    $('<div>').addClass('ct-selempty').text(self.S.rw_submit_error || 'Error'));
            });
        },

        /**
         * Render the current page of rows (values injected as text).
         */
        renderRows: function() {
            var self = this;
            var $rows = this.region('rows').empty();

            if (!this.items.length) {
                $rows.append($('<div>').addClass('ct-selempty').text(self.S.rw_noresults || '—'));
            } else {
                this.items.forEach(function(item) {
                    var on = !!self.state.checked[item.id];
                    var $row = $('<button>')
                        .attr('type', 'button')
                        .addClass('ct-selrow')
                        .attr('data-action', 'toggle-row')
                        .attr('data-id', item.id)
                        .attr('data-name', item.name)
                        .attr('data-meta', item.meta || '')
                        .attr('aria-pressed', on ? 'true' : 'false');

                    $row.append($('<span>').addClass('ct-checkbox')
                        .append($('<i>').addClass('fa fa-check').attr('aria-hidden', 'true')));

                    var $body = $('<span>').addClass('ct-selrow-body');
                    $body.append($('<span>').addClass('ct-selrow-name').text(item.name));
                    if (item.sub) {
                        $body.append($('<span>').addClass('ct-selrow-sub').text(item.sub));
                    }
                    $row.append($body);
                    $row.append($('<span>').addClass('ct-selrow-meta').text(item.meta || ''));
                    $rows.append($row);
                });
            }

            this.renderPager();
            this.renderCounter();
            this.renderPageBox();
        },

        /**
         * Toggle a single row selection.
         *
         * @param {jQuery} $row
         */
        toggleRow: function($row) {
            var id = $row.attr('data-id');
            if (this.state.checked[id]) {
                delete this.state.checked[id];
                $row.attr('aria-pressed', 'false');
            } else {
                this.state.checked[id] = {
                    name: $row.attr('data-name'),
                    meta: $row.attr('data-meta')
                };
                $row.attr('aria-pressed', 'true');
            }
            this.renderCounter();
            this.renderPageBox();
            this.refreshFooter();
        },

        /**
         * Select / deselect every row on the visible page.
         */
        togglePage: function() {
            var self = this;
            var ids = this.items.map(function(i) {
                return String(i.id);
            });
            var allon = ids.length > 0 && ids.every(function(id) {
                return !!self.state.checked[id];
            });
            this.items.forEach(function(item) {
                if (allon) {
                    delete self.state.checked[item.id];
                } else {
                    self.state.checked[item.id] = {name: item.name, meta: item.meta || ''};
                }
            });
            this.renderRowsChecked();
            this.renderCounter();
            this.renderPageBox();
            this.refreshFooter();
        },

        /**
         * Reflect the checked map onto the visible rows.
         */
        renderRowsChecked: function() {
            var self = this;
            this.region('rows').find('[data-action="toggle-row"]').each(function() {
                var id = $(this).attr('data-id');
                $(this).attr('aria-pressed', self.state.checked[id] ? 'true' : 'false');
            });
        },

        /**
         * Update the select-all checkbox state.
         */
        renderPageBox: function() {
            var self = this;
            var ids = this.items.map(function(i) {
                return String(i.id);
            });
            var on = ids.filter(function(id) {
                return !!self.state.checked[id];
            }).length;
            var $box = this.$root.find('[data-action="toggle-page"]');
            $box.removeClass('ct-checkbox--on ct-checkbox--some');
            if (ids.length > 0 && on === ids.length) {
                $box.addClass('ct-checkbox--on');
            } else if (on > 0) {
                $box.addClass('ct-checkbox--some');
            }
        },

        /**
         * Selected ids as an array of ints.
         *
         * @return {Number[]}
         */
        selectedIds: function() {
            return Object.keys(this.state.checked).map(function(id) {
                return parseInt(id, 10);
            });
        },

        /**
         * Update the selection counter bar.
         */
        renderCounter: function() {
            var count = this.selectedIds().length;
            this.region('selcount').text(count);
            var label = count === 0
                ? (this.S.rw_selected_none || '—')
                : (this.S.rw_selected || '{$a}').replace('{$a}', count);
            this.region('sellabel').text(label);
            this.region('selcounter').toggleClass('ct-selcounter--active', count > 0);
            this.$root.find('[data-action="clear"]').prop('hidden', count === 0);
        },

        /**
         * Update the pager.
         */
        renderPager: function() {
            var s = this.state;
            var $pager = this.region('pager');
            if (s.pages <= 1) {
                $pager.prop('hidden', true);
                return;
            }
            $pager.prop('hidden', false);
            var info = (this.S.rw_pageinfo || '{$a->page}/{$a->pages}')
                .replace('{$a->page}', s.page + 1).replace('{$a->pages}', s.pages);
            this.region('pageinfo').text(info);
            this.$root.find('[data-action="prev"]').prop('disabled', s.page <= 0);
            this.$root.find('[data-action="next-page"]').prop('disabled', s.page >= s.pages - 1);
        },

        // ---- Step 2: destination / options -----------------------------

        /**
         * Sync mode/confirm/users controls to the current state.
         */
        syncStep2Controls: function() {
            this.toggleMode(this.state.mode);
            this.$root.find('[data-action="toggle-users"]')
                .attr('aria-checked', this.state.includeusers ? 'true' : 'false');
            this.$root.find('[data-action="destcat"]').val(String(this.state.destcat));
        },

        /**
         * Choose the "already exists" mode.
         *
         * @param {String} mode merge|replace
         */
        toggleMode: function(mode) {
            this.state.mode = mode;
            if (mode !== 'replace') {
                this.state.confirmdestroy = false;
            }
            this.$root.find('.ct-modeopt').each(function() {
                var on = $(this).attr('data-mode') === mode;
                $(this).attr('aria-pressed', on ? 'true' : 'false');
                $(this).find('[data-action="toggle-mode"]').attr('aria-pressed', on ? 'true' : 'false');
            });
            this.region('confirm').prop('hidden', mode !== 'replace');
            this.$root.find('[data-action="toggle-confirm"]')
                .attr('aria-checked', this.state.confirmdestroy ? 'true' : 'false');
            this.refreshFooter();
        },

        // ---- Step 3: review --------------------------------------------

        /**
         * Build the review summary (values injected as text).
         */
        renderReview: function() {
            var s = this.state;
            var items = this.selectedIds();
            var count = items.length;
            var kindlabel = s.type === 'category'
                ? (this.S.rw_kind_category || 'Category')
                : (this.S.rw_kind_course || 'Course');
            var destlabel = this.$root.find('[data-action="destcat"] option:selected').text();

            var $review = this.region('review').empty();

            // Hero.
            var $hero = $('<div>').addClass('ct-review-hero');
            $hero.append($('<span>').addClass('ct-review-hero-icon')
                .append($('<i>').addClass('fa fa-download').attr('aria-hidden', 'true')));
            var $hbody = $('<div>').addClass('ct-review-hero-body');
            $hbody.append($('<div>').addClass('ct-review-eyebrow').text(this.S.rw_review_selected || ''));
            $hbody.append($('<div>').addClass('ct-review-headline').text(count + ' · ' + kindlabel));
            $hbody.append($('<div>').addClass('ct-review-sub')
                .text((this.S.rw_review_from || '') + ' ' + s.sitename + ' → ' + destlabel));
            $hero.append($hbody);
            $hero.append($('<span>').addClass('ct-review-count').text(count));
            $review.append($hero);

            // Selected list (up to 5).
            var $list = $('<div>').addClass('ct-review-list');
            var self = this;
            items.slice(0, 5).forEach(function(id) {
                var info = self.state.checked[id];
                var $it = $('<div>').addClass('ct-review-list-item');
                $it.append($('<i>').addClass('fa fa-check').attr('aria-hidden', 'true'));
                $it.append($('<span>').css({flex: 1, 'min-width': 0}).text(info.name));
                if (info.meta) {
                    $it.append($('<span>').addClass('ct-text-muted').text(info.meta));
                }
                $list.append($it);
            });
            if (count > 5) {
                $list.append($('<div>').addClass('ct-text-muted')
                    .text((this.S.rw_more || '+{$a}').replace('{$a}', count - 5)));
            }
            $review.append($list);

            // Grid: mode + users.
            var $grid = $('<div>').addClass('ct-review-grid');
            var $c1 = $('<div>').addClass('ct-review-grid-cell');
            $c1.append($('<div>').addClass('ct-text-muted').text(this.S.rw_review_ifexists || ''));
            $c1.append($('<div>').text(s.mode === 'replace'
                ? (this.S.rw_mode_replace || 'Replace')
                : (this.S.rw_mode_merge || 'Merge')));
            var $c2 = $('<div>').addClass('ct-review-grid-cell');
            $c2.append($('<div>').addClass('ct-text-muted').text(this.S.rw_review_users || ''));
            $c2.append($('<div>').text(s.includeusers
                ? (this.S.rw_users_on || 'Yes')
                : (this.S.rw_users_off || 'No')));
            $grid.append($c1).append($c2);
            $review.append($grid);

            this.region('review-danger').prop('hidden', s.mode !== 'replace');
        },

        // ---- Submit -----------------------------------------------------

        /**
         * Send the restore request and move to the done screen.
         */
        submit: function() {
            var self = this;
            var s = this.state;
            var $primary = this.$root.find('[data-action="primary"]');
            $primary.prop('disabled', true);

            Ajax.call([{
                methodname: 'local_coursetransfer_restore_wizard_submit',
                args: {
                    siteid: s.siteid,
                    type: s.type,
                    ids: this.selectedIds(),
                    targetcatid: s.destcat,
                    mode: s.mode,
                    removeenrols: false,
                    removegroups: false,
                    includeusers: s.includeusers,
                    schedule: 0
                }
            }])[0].then(function(resp) {
                if (resp && resp.success) {
                    if (resp.data && resp.data.nexturl && resp.data.nexturl !== '#') {
                        self.region('done-log').attr('href', resp.data.nexturl);
                    }
                    self.showView('done');
                } else {
                    self.submitError(resp);
                }
                return resp;
            }).catch(function() {
                self.submitError(null);
            });
        },

        /**
         * Report a submit failure inline on the review step.
         *
         * @param {Object|null} resp
         */
        submitError: function(resp) {
            var msg = this.S.rw_submit_error || 'Error';
            if (resp && resp.errors && resp.errors.length && resp.errors[0].msg) {
                msg = resp.errors[0].msg;
            }
            var $danger = this.region('review-danger').prop('hidden', false);
            $danger.find('p').text(msg);
            this.$root.find('[data-action="primary"]').prop('disabled', false);
        }
    };

    return {
        init: function(selector) {
            Wizard.init(selector);
        }
    };
});
