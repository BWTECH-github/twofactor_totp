/* global Backbone, Handlebars, OC */

(function (OC, Handlebars, $) {
    'use strict';

    OC.Settings = OC.Settings || {};
    OC.Settings.TwoFactorTotp = OC.Settings.TwoFactorTotp || {};

    // Einrichtung als Formular: Enter im Codefeld bestaetigt wie der Knopf.
    // Beschriftung ueber dem Feld (css/personal.css), Schluessel als Code.
    var TEMPLATE = '<div class="totp-toggle">'
        + '    <input type="checkbox" class="checkbox" id="totp-enabled">'
        + '    <label for="totp-enabled">' + t('twofactor_totp', 'Activate TOTP') + '</label>'
        + '    <span id="totp-state-msg" class="msg" role="status" aria-live="polite"></span>'
        + '</div>'
        + '{{#if secret}}'
        + '<div class="totp-setup">'
        + '    <p class="totp-secret">' + t('twofactor_totp', 'This is your new TOTP secret:') + ' <code>{{secret}}</code></p>'
        + '    <p>' + t('twofactor_totp', 'Scan this QR code with your TOTP app') + '</p>'
        + '    <img class="totp-qr" src="{{qr}}" alt="' + t('twofactor_totp', 'QR code for TOTP authenticator setup') + '">'
        + '    <p>' + t('twofactor_totp', 'To enable second-factor verify authentication code below.') + '</p>'
        + '    <form class="totp-verify" novalidate>'
        + '        <label for="totp-challenge" class="totp-challenge-label">' + t('twofactor_totp', 'Authentication code') + '</label>'
        + '        <div class="totp-verify-row">'
        + '            <input type="text" id="totp-challenge" required="required" inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6" autocomplete="one-time-code">'
        + '            <button id="totp-verify-secret" type="submit" class="oco-btn-primary">' + t('twofactor_totp', 'Verify') + '</button>'
        + '            <span id="totp-verify-msg" class="msg" role="status" aria-live="polite"></span>'
        + '        </div>'
        + '    </form>'
        + '</div>'
        + '{{/if}}';

    var View = OC.Backbone.View.extend({
        template: Handlebars.compile(TEMPLATE),
        _loading: undefined,
        _enabled: undefined,
        events: {
            'change #totp-enabled': '_onToggleEnabled',
            'submit .totp-verify': '_onSubmitVerify'
        },
        initialize: function () {
            this._load();
        },
        render: function (data) {
            this.$el.html(this.template(data));
        },
        _load: function () {
            this._loading = true;

            var url = OC.generateUrl('/apps/twofactor_totp/settings/state');
            var loading = $.ajax(url, {
                method: 'GET'
            });

            var _this = this;
            $.when(loading).done(function (data) {
                _this._enabled = data.enabled;
                _this.$('#totp-enabled').prop('checked', !!data.enabled);
            });
            $.when(loading).always(function () {
                _this._loading = false;
            });
        },
        _onToggleEnabled: function () {
            if (this._loading) {
                // Ignore event
                return;
            }

            var enabled = this.$('#totp-enabled').is(':checked');

            if (enabled !== this._enabled) {
                this._loading = true;
                var url = OC.generateUrl('/apps/twofactor_totp/settings/enable');
                var updating = $.ajax(url, {
                    method: 'POST',
                    data: {
                        state: enabled
                    }
                });

                var _this = this;
                $.when(updating).done(function(data) {
                    _this._enabled = data.enabled;
                    _this._showQr(data);
                    _this.$('#totp-enabled').prop('checked', !!data.enabled);
                    // Das Kaestchen wurde neu gezeichnet: der Fokus geht an das
                    // Codefeld (Einrichtung) bzw. zurueck an das Kaestchen
                    (data.secret ? _this.$('#totp-challenge') : _this.$('#totp-enabled')).trigger('focus');
                });
                $.when(updating).always(function () {
                    _this._loading = false;
                });
                this._enabled = enabled;
            }
        },
        _onSubmitVerify: function (event) {
            event.preventDefault();
            var challenge = $.trim(this.$('#totp-challenge').val());
            var url = OC.generateUrl('/apps/twofactor_totp/settings/verifyNewSecret');
            var verifying = $.ajax(url, {
                method: 'POST',
                data: {
                    challenge: challenge
                }
            });

            var _this = this;
            $.when(verifying).done(function(data) {
                if(data.verified) {
                    // Eingerichtet: QR-Code und Schluessel verschwinden, das
                    // Kaestchen bleibt gesetzt und meldet den Erfolg
                    _this.render({});
                    _this.$('#totp-enabled').prop('checked', true).trigger('focus');
                    OC.msg.finishedSuccess('#totp-state-msg', t('twofactor_totp', 'Verified'));
                } else {
                    OC.msg.finishedError('#totp-verify-msg', t('twofactor_totp', 'Not verified'));
                    _this.$('#totp-challenge').trigger('focus').trigger('select');
                }
            }).fail(function () {
                OC.msg.finishedError('#totp-verify-msg', t('twofactor_totp', 'Not verified'));
            });
        },
        _showQr: function(data) {
            this.render({
                secret: data.secret,
                qr: data.qr
            });
        }
    });

    OC.Settings.TwoFactorTotp.View = View;

})(OC, Handlebars, $);
