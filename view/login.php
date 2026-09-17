<style>
    table td:first-child {
        width: 250px; /* gewünschte Breite */
        white-space: nowrap; /* verhindert Zeilenumbruch */
        padding-right: 10px; /* Abstand zum Input */
        text-align: right;
    }
</style>


<div class="pmwh3-content" style="margin-left: 250px; transition: margin-left 0.5s;">
    <!-- Login Widget -->
    <div class="widget" style="flex: 1 1 0;">
        <div class="entry tool">
            <h2>Login</h2>
<?php
// $redirectTarget kommt vom Controller mit server-side gemerktem
// Wert (Session). Wird als Default in das hidden return_to-Feld
// geschrieben. JS überschreibt es, wenn localStorage einen
// genaueren Wert (letzte besuchte URL) hat.
$redirectTarget = (string) ($this->data['redirectTarget'] ?? '');
// CAPTCHA-Kontext vom Controller (null wenn deaktiviert).
$captcha = $this->data['captcha'] ?? null;
$captchaType    = is_array($captcha) ? (string) ($captcha['type'] ?? '') : '';
$captchaSiteKey = is_array($captcha) ? (string) ($captcha['siteKey'] ?? '') : '';
$hasCaptcha     = $captchaType !== '' && $captchaSiteKey !== '';

// Render the CAPTCHA widget (v2 checkbox div or v3 hidden field +
// token-out hook) for a given form id. Returns '' when disabled.
$renderCaptchaField = function (string $formId) use ($captchaType, $captchaSiteKey, $hasCaptcha): string {
    if (!$hasCaptcha) {
        return '';
    }
    if ($captchaType === 'recaptcha_v2') {
        return '<div class="g-recaptcha" data-sitekey="' . htmlspecialchars($captchaSiteKey) . '"></div>';
    }
    return '<input type="hidden" name="g-recaptcha-response" class="g-recaptcha-response" data-captcha-form="' . htmlspecialchars($formId) . '">';
};
?>
            <form id="commentform" action="<?= BASE_URI ?>pmwh3/login/submit" method="post">
                <input type="hidden" name="return_to" id="return_to" value="<?= htmlspecialchars($redirectTarget) ?>">
<script>
    // Before the form submits, copy the last-visited URL from
    // localStorage into the hidden return_to field. The server
    // reads it in Login::submit() to decide where to redirect
    // the user after successful auth.
    //
    // This is a normal form POST (no [data-form] = no AJAX), so
    // the redirect MUST happen server-side -- we cannot drive it
    // from JavaScript after submission.
    (function () {
        try {
            var stored = localStorage.getItem('pmwh3.lastUrl')
                       || localStorage.getItem('activeWidgetLink');
            if (!stored) return;
            // Only pass on in-app pmwh3 URLs, and never the login
            // page itself (that would loop).
            if (stored.indexOf('/pmwh3') === -1) return;
            if (stored.indexOf('/pmwh3/login') !== -1) return;
            var input = document.getElementById('return_to');
            if (input) input.value = stored;
        } catch (e) { /* localStorage blocked */ }
    })();
</script>
                <table>
                    <tr>
                        <td>User name:</td>
                        <td><input name="customername" required></td>
                    </tr>
                    <tr>
                        <td>Password:</td>
                        <td><input type="password" name="password" required ></td>
                    </tr>
                    <?php $captchaField = $renderCaptchaField('commentform'); if ($captchaField !== '') : ?>
                    <tr>
                        <td colspan="2"><?= $captchaField ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td colspan="2" align="right">
                            <span title="Login">
                                <button type="submit" class="button small-action save">Login</button>
                            </span>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
    </div>

    <div style="flex: 1 1 0; display: flex; flex-direction: column; gap: 10px;">

        <!-- Forgot Password Widget -->
        <div class="widget">
            <div class="entry tool">
                <h2>Forgot Password</h2>
                <form id="resetform" action="<?= BASE_URI ?>pmwh3/login/password_reset" method="post" data-title="<?= _('Password reset') ?>" data-message="<?= _('Send email success') ?>" data-redirect="<?= BASE_URI ?>pmwh3/login">
                    <table>
                        <tr>
                            <td>Email:</td>
                            <td><input type="email" name="email" required></td>
                        </tr>
                        <?php $captchaField = $renderCaptchaField('resetform'); if ($captchaField !== '') : ?>
                        <tr>
                            <td colspan="2"><?= $captchaField ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td colspan="2" align="right">
                                <button type="submit" class="button small-action save">Reset</button>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
        </div>

        <!-- Change Email/FTP Password Widget -->
        <div class="widget">
            <div class="entry tool">
                <h2>Change Email/FTP Password</h2>
                <form action="<?= BASE_URI ?>pmwh3/login/change_password" method="post">
                    <table>
                        <tr>
                            <td>Type:</td>
                            <td>
                                <label><input type="radio" name="type" value="email" checked> Email</label>
                                &nbsp;
                                <label><input type="radio" name="type" value="ftp"> FTP</label>
                            </td>
                        </tr>
                        <tr>
                            <td>User:</td>
                            <td><input name="username" required></td>
                        </tr>
                        <tr>
                            <td>Old Password:</td>
                            <td><input type="password" name="old_password" required></td>
                        </tr>
                        <tr>
                            <td>New Password:</td>
                            <td><input type="password" name="new_password" required></td>
                        </tr>
                        <tr>
                            <td>Confirm:</td>
                            <td><input type="password" name="confirm_password" required></td>
                        </tr>
                        <tr>
                            <td colspan="2" align="right">
                                <button type="submit" class="button small-action save">Change</button>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
        </div>

        <!-- News Ticker (G4: SHOW_NEWS + MAX_NEWS) -->
        <?php
        // $this->news = direct view property (View::render() assigns
        // viewData keys as object properties; $this->data is not set
        // on this view).
        $loginNews = (array) ($this->news ?? []);
        ?>
        <?php if ($loginNews !== []) : ?>
        <div class="widget">
            <div class="entry tool">
                <h2><?php echo __('News'); ?></h2>
                <div class="login-news">
                    <?php foreach ($loginNews as $nItem): ?>
                        <div class="login-news-item">
                            <div class="login-news-head">
                                <span class="login-news-date"><?php
                                    echo htmlspecialchars(substr((string) ($nItem['datetime'] ?? ''), 0, 16));
                                ?></span>
                                <?php $nAuthor = trim((string) ($nItem['author'] ?? '')); if ($nAuthor !== ''): ?>
                                <span class="login-news-author"><?php echo htmlspecialchars($nAuthor); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="login-news-text"><?php
                                echo nl2br(htmlspecialchars((string) ($nItem['news'] ?? '')));
                            ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php if ($hasCaptcha) : ?>
<script src="https://www.google.com/recaptcha/api.js<?= $captchaType === 'recaptcha_v3' ? '?render=' . urlencode($captchaSiteKey) : '' ?>" async defer></script>
<?php if ($captchaType === 'recaptcha_v3') : ?>
<script>
    // v3: token in den Hidden-Field des jeweiligen Formulaires
    // steuern, bevor es abgeschickt wird.
    (function () {
        var siteKey = <?= json_encode($captchaSiteKey) ?>;
        var forms = document.querySelectorAll('form');
        forms.forEach(function (form) {
            var field = form.querySelector('.g-recaptcha-response');
            if (!field) { return; } // nur Formulare mit v3-Feld behandeln
            var action = 'submit';
            if (form.id === 'commentform') { action = 'login'; }
            if (form.id === 'resetform') { action = 'reset'; }
            form.addEventListener('submit', function (e) {
                if (!window.grecaptcha || !grecaptcha.ready) { return; } // API noch nicht da → normaler POST, Server entscheidet
                e.preventDefault();
                var f = form;
                var send = function (token) {
                    field.value = token;
                    f.submit();
                };
                grecaptcha.ready(function () {
                    grecaptcha.execute(siteKey, { action: action }).then(
                        function (token) { send(token); },
                        function () { send(''); } // Token-Fehler → Server failt (wie geplant) statt Formular zu blockieren
                    );
                });
            }, false);
        });
    })();
</script>
<?php endif; ?>
<?php endif; ?>
