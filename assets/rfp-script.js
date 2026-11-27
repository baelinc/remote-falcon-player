/**
 * Remote Falcon Player — rfp-script.js v3.1.0
 */
(function ($) {
    'use strict';

    var DEFAULT_OFF_AIR = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 260">' +
        '<rect width="400" height="260" fill="#1a1a2e" rx="14"/>' +
        '<text x="200" y="105" text-anchor="middle" font-family="sans-serif" font-size="60">🎄</text>' +
        '<text x="200" y="152" text-anchor="middle" font-family="sans-serif" font-size="22" fill="#e74c3c" font-weight="bold">Show is Off Air</text>' +
        '<text x="200" y="182" text-anchor="middle" font-family="sans-serif" font-size="14" fill="rgba(255,255,255,0.5)">Check back soon!</text>' +
        '</svg>'
    );

    var REASON_MSGS = {
        'ALREADY_VOTED'    : "You've already voted this round.",
        'INVALID_LOCATION' : "You don't appear to be at the show.",
        'QUEUE_FULL'       : 'The request queue is full right now.',
        'SONG_REQUESTED'   : 'That song is already in the queue.',
    };

    $(document).ready(function () {
        $('.rfp-widget').each(function () { new RFPWidget($(this)); });
    });

    function RFPWidget($el) {
        this.$el          = $el;
        this.mode         = $el.data('mode') || rfpData.show_mode || 'voting';
        this.pollInterval = (parseInt($el.data('poll') || 10, 10)) * 1000;
        this.showNow      = !!parseInt($el.data('show-now'),  10);
        this.showNext     = !!parseInt($el.data('show-next'), 10);
        this.showList     = !!parseInt($el.data('show-list'), 10);
        this.offAirImg    = $el.data('off-air-img') || '';
        this.pollTimer    = null;
        this.voted        = {};
        this.lastData     = null; // cache for modal

        this.bindModal();
        this.fetch();
    }

    /* ----------------------------------------------------------
     * Modal binding
     * -------------------------------------------------------- */
    RFPWidget.prototype.bindModal = function () {
        var self     = this;
        var $el      = this.$el;
        var $overlay = $el.find('.rfp-modal-overlay');
        var $modal   = $el.find('.rfp-modal');

        // Open modal
        $el.on('click', '.rfp-open-modal', function () {
            if (!self.lastData) return;
            self.openModal();
        });

        // Close modal — X button
        $el.on('click', '.rfp-modal-close', function () {
            self.closeModal();
        });

        // Close modal — click overlay outside modal box
        $overlay.on('click', function (e) {
            if (!$modal.is(e.target) && $modal.find(e.target).length === 0) {
                self.closeModal();
            }
        });

        // Close modal — Escape key
        $(document).on('keydown.rfp', function (e) {
            if (e.key === 'Escape') self.closeModal();
        });

        // Search filter
        $el.on('input', '.rfp-search-input', function () {
            var term = $(this).val().toLowerCase();
            $el.find('.rfp-modal-list .rfp-seq-row').each(function () {
                var text = $(this).text().toLowerCase();
                $(this).toggle(text.indexOf(term) !== -1);
            });
        });
    };

    RFPWidget.prototype.openModal = function () {
        var $overlay = this.$el.find('.rfp-modal-overlay');
        $overlay.fadeIn(150);
        $('body').addClass('rfp-modal-open');
        this.$el.find('.rfp-search-input').val('').trigger('input').focus();
    };

    RFPWidget.prototype.closeModal = function () {
        this.$el.find('.rfp-modal-overlay').fadeOut(150);
        $('body').removeClass('rfp-modal-open');
    };

    /* ----------------------------------------------------------
     * Fetch GET /showDetails
     * -------------------------------------------------------- */
    RFPWidget.prototype.fetch = function () {
        var self = this;
        clearTimeout(self.pollTimer);

        $.ajax({
            url      : rfpData.ajaxurl,
            type     : 'POST',
            dataType : 'json',
            data     : { action: 'rfp_ajax', nonce: rfpData.nonce, rf_action: 'status' },
            timeout  : 20000
        })
        .done(function (resp) {
            if (resp && resp.success && resp.data) {
                self.lastData = resp.data;
                self.render(resp.data);
                // Refresh modal list if open
                if (self.$el.find('.rfp-modal-overlay').is(':visible')) {
                    self.populateModal(resp.data);
                }
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not load show data.';
                self.showError(msg, '');
            }
        })
        .fail(function (xhr) {
            self.showError('Could not reach Remote Falcon.', 'HTTP ' + xhr.status);
        })
        .always(function () {
            self.pollTimer = setTimeout(function () { self.fetch(); }, self.pollInterval);
        });
    };

    /* ----------------------------------------------------------
     * Render main widget
     * -------------------------------------------------------- */
    RFPWidget.prototype.render = function (data) {
        var $el   = this.$el;
        var prefs = data.preferences || {};

        $el.find('.rfp-loading').hide();
        $el.find('.rfp-error').hide();

        if (!prefs.viewerControlEnabled) {
            this.renderOffAir();
            return;
        }

        this.mode = (prefs.viewerControlMode || this.mode || 'voting').toLowerCase();

        // Vote map
        var voteMap = {};
        if (data.votes && Array.isArray(data.votes)) {
            data.votes.forEach(function (v) {
                var n = v.sequence && v.sequence.name;
                if (n) voteMap[n] = v.votes || 0;
            });
        }

        this.renderLive(data, this.mode, voteMap);
    };

    RFPWidget.prototype.renderOffAir = function () {
        var img = this.offAirImg || DEFAULT_OFF_AIR;
        this.$el.find('.rfp-live,.rfp-modal-overlay').hide();
        this.$el.find('.rfp-off-air').show().find('.rfp-off-air-img').attr('src', img);
        $('body').removeClass('rfp-modal-open');
    };

    RFPWidget.prototype.renderLive = function (data, mode, voteMap) {
        var $el    = this.$el;
        var seqs   = data.sequences || [];
        var nowStr = data.playingNow  || '';
        var nxtStr = data.playingNext || '';

        $el.find('.rfp-off-air').hide();
        $el.find('.rfp-live').show();

        // Update "open modal" button label based on mode
        var btnLabel = (mode === 'voting') ? '🗳 Vote for a Song' : '🎶 Request a Song';
        $el.find('.rfp-open-modal').text(btnLabel);

        // Now Playing
        $el.find('.rfp-now-playing-wrap').toggle(this.showNow);
        if (this.showNow) {
            $el.find('.rfp-now-name').text(this.getDisplayName(seqs, nowStr) || '—');
            $el.find('.rfp-now-artist').text(this.getArtist(seqs, nowStr));
        }

        // Up Next
        $el.find('.rfp-next-up-wrap').toggle(this.showNext);
        if (this.showNext) {
            $el.find('.rfp-next-name').text(this.getDisplayName(seqs, nxtStr) || '—');
            $el.find('.rfp-next-artist').text(this.getArtist(seqs, nxtStr));
        }

        // Show/hide the button
        $el.find('.rfp-list-wrap').toggle(this.showList);

        // Update modal if it's open
        if ($el.find('.rfp-modal-overlay').is(':visible')) {
            this.populateModal(data);
        }
    };

    /* Map a sequence name (as returned by playingNow/playingNext) to its Display Name */
    RFPWidget.prototype.getDisplayName = function (seqs, name) {
        if (!name) return '';
        for (var i = 0; i < seqs.length; i++) {
            var s = seqs[i];
            if (s.name === name || s.displayName === name) return s.displayName || s.name || name;
        }
        return name;
    };

    RFPWidget.prototype.getArtist = function (seqs, name) {
        if (!name) return '';
        for (var i = 0; i < seqs.length; i++) {
            var s = seqs[i];
            if (s.name === name || s.displayName === name) return s.artist || '';
        }
        return '';
    };

    /* ----------------------------------------------------------
     * Modal song list
     * -------------------------------------------------------- */
    RFPWidget.prototype.populateModal = function (data) {
        var self     = this;
        var $el      = this.$el;
        var mode     = this.mode;
        var isVoting = (mode === 'voting');
        var seqs     = data.sequences || [];

        // Vote map
        var voteMap = {};
        if (data.votes && Array.isArray(data.votes)) {
            data.votes.forEach(function (v) {
                var n = v.sequence && v.sequence.name;
                if (n) voteMap[n] = v.votes || 0;
            });
        }

        // Set modal title
        $el.find('.rfp-modal-title-voting,.rfp-modal-title-jukebox').hide();
        $el.find(isVoting ? '.rfp-modal-title-voting' : '.rfp-modal-title-jukebox').show();

        // Filter: visible AND active
        var visible = seqs.filter(function (s) {
            return s.visible !== false && s.active !== false;
        });

        // Sort by votes desc in voting mode
        if (isVoting) {
            visible.sort(function (a, b) {
                return ((voteMap[b.name] || 0) - (voteMap[a.name] || 0));
            });
        }

        var $list  = $el.find('.rfp-modal-list');
        var term   = $el.find('.rfp-search-input').val().toLowerCase();
        var html   = '';

        visible.forEach(function (seq) {
            var raw    = seq.name || '';
            var disp   = seq.displayName || raw;
            var artist = seq.artist || '';
            var vcount = voteMap[raw] || 0;
            var done   = !!self.voted[raw];
            var lbl    = done ? (isVoting ? '✓ Voted' : '✓ Requested') : (isVoting ? 'Vote' : 'Request');
            var cls    = done ? 'rfp-seq-btn rfp-voted' : 'rfp-seq-btn';
            var hidden = term && (disp.toLowerCase() + artist.toLowerCase()).indexOf(term) === -1 ? ' style="display:none;"' : '';

            html += '<div class="rfp-seq-row"' + hidden + '>';
            html +=   '<div class="rfp-seq-info">';
            html +=     '<div class="rfp-seq-display-name">' + esc(disp) + '</div>';
            if (artist) html += '<div class="rfp-seq-artist">' + esc(artist) + '</div>';
            html +=   '</div>';
            if (isVoting) html += '<div class="rfp-vote-count">' + vcount + '</div>';
            html += '<button class="' + cls + '" data-seq="' + esc(raw) + '"' + (done ? ' disabled' : '') + '>' + lbl + '</button>';
            html += '</div>';
        });

        if (!visible.length) {
            html = '<p style="opacity:.6;text-align:center;padding:24px 0;">No songs available right now.</p>';
        }

        $list.html(html);

        // Bind vote/request buttons inside modal
        $list.find('.rfp-seq-btn').off('click').on('click', function () {
            var $btn = $(this);
            if ($btn.prop('disabled')) return;
            var rfAction = isVoting ? 'vote' : 'request';
            self.submitAction(rfAction, $btn.data('seq'), $btn);
        });
    };

    RFPWidget.prototype.openModal = function () {
        var $overlay = this.$el.find('.rfp-modal-overlay');
        if (this.lastData) this.populateModal(this.lastData);
        $overlay.fadeIn(180);
        $('body').addClass('rfp-modal-open');
        this.$el.find('.rfp-search-input').val('').focus();
    };

    /* ----------------------------------------------------------
     * Vote / Request
     * -------------------------------------------------------- */
    RFPWidget.prototype.submitAction = function (rfAction, seqName, $btn) {
        var self = this;
        $btn.prop('disabled', true).text('…');

        $.ajax({
            url      : rfpData.ajaxurl,
            type     : 'POST',
            dataType : 'json',
            data     : { action: 'rfp_ajax', nonce: rfpData.nonce, rf_action: rfAction, sequence_name: seqName },
            timeout  : 20000
        })
        .done(function (resp) {
            if (resp && resp.success) {
                var d = resp.data || {};
                if (d.ok) {
                    self.voted[seqName] = true;
                    $btn.text(rfAction === 'vote' ? '✓ Voted' : '✓ Requested').addClass('rfp-voted');
                    self.showModalStatus('ok', rfAction === 'vote' ? '🗳 Vote recorded!' : '🎶 Request queued!');
                    // Refresh data
                    clearTimeout(self.pollTimer);
                    self.pollTimer = setTimeout(function () { self.fetch(); }, 600);
                } else {
                    $btn.prop('disabled', false).text(rfAction === 'vote' ? 'Vote' : 'Request');
                    var friendly = REASON_MSGS[d.message] || d.message || 'Could not complete your request.';
                    self.showModalStatus('warn', friendly);
                }
            } else {
                $btn.prop('disabled', false).text(rfAction === 'vote' ? 'Vote' : 'Request');
                self.showModalStatus('error', (resp && resp.data && resp.data.message) || 'Something went wrong.');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text(rfAction === 'vote' ? 'Vote' : 'Request');
            self.showModalStatus('error', 'Connection error. Please try again.');
        });
    };

    /* ----------------------------------------------------------
     * UI helpers
     * -------------------------------------------------------- */
    RFPWidget.prototype.showError = function (msg, detail) {
        this.$el.find('.rfp-loading,.rfp-off-air,.rfp-live').hide();
        this.$el.find('.rfp-error-msg').text('⚠️ ' + msg);
        this.$el.find('.rfp-error-detail').text(detail || '');
        this.$el.find('.rfp-error').show();
    };

    RFPWidget.prototype.showModalStatus = function (type, message) {
        var $s = this.$el.find('.rfp-modal-status');
        $s.removeClass('rfp-status-ok rfp-status-warn rfp-status-error')
          .addClass('rfp-status-' + (type === 'ok' ? 'ok' : type))
          .text(message).show();
        clearTimeout(this._mst);
        this._mst = setTimeout(function () { $s.fadeOut(400); }, 5000);
    };

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

}(jQuery));
