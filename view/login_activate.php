<style>
    table td:first-child {
        width: 250px;
        white-space: nowrap;
        padding-right: 10px;
        text-align: right;
    }
</style>

<?php
$token = (string) ($this->data['token'] ?? '');
?>

<div class="pmwh3-content" style="margin-left: 250px; transition: margin-left 0.5s;">
    <div class="widget" style="flex: 1 1 0;">
        <div class="entry tool">
            <h2><?= __('Set new password') ?></h2>
            <form action="<?= BASE_URI ?>pmwh3/login/finalize_reset" method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <table>
                    <tr>
                        <td><?= __('New Password') ?>:</td>
                        <td><input type="password" name="new_password" required></td>
                    </tr>
                    <tr>
                        <td><?= __('Confirm') ?>:</td>
                        <td><input type="password" name="new_password2" required></td>
                    </tr>
                    <tr>
                        <td colspan="2" align="right">
                            <button type="submit" class="button small-action save"><?= __('Save') ?></button>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
    </div>
</div>
