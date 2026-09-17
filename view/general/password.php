<div class="pmwh3-content">
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Change password'); ?></h3>
    </div>
    <div class="widget-body">

        <form id="changePasswordForm" method="post"
              action="<?php echo BASE_URI; ?>pmwh3/general/savepassword"
              data-confirm="<?php echo __('Change password?'); ?>"
              data-confirm-type="change">
            <table class="widget-table">
                <tr>
                    <td><?php echo __('Old user password'); ?></td>
                    <td><input type="password" name="old_user_password" required></td>
                </tr>
                <tr>
                    <td><?php echo __('New password'); ?></td>
                    <td><input type="password" name="new_password" required></td>
                </tr>
                <tr>
                    <td><?php echo __('Repeat password'); ?></td>
                    <td><input type="password" name="repeat_password" required></td>
                </tr>
            </table>

            <div id="status" class="form-status pmwh3-hidden"></div>

            <div class="form-actions">
                <a href="<?php echo BASE_URI; ?>pmwh3/general"
                   class="button small-action cancel"><?php echo __('Cancel'); ?></a>
                <button type="submit"
                        class="button small-action save"><?php echo __('Save'); ?></button>
            </div>
        </form>

    </div>
</div>
</div>
<script>
    window.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('#changePasswordForm');
        if (!form) {
            return;
        }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.action);
            xhr.onload = function () {
                if (xhr.status !== 200) {
                    alert('<?php echo __("An error occurred. Please try again later."); ?>');
                    return;
                }
                var response;
                try {
                    response = JSON.parse(xhr.responseText);
                } catch (err) {
                    alert('<?php echo __("An error occurred. Please try again later."); ?>');
                    return;
                }
                if (response.success === 1) {
                    window.location.href = '<?php echo BASE_URI; ?>pmwh3/general';
                    return;
                }
                var status = '';
                for (var key in response.errorMessage || {}) {
                    if (Object.prototype.hasOwnProperty.call(response.errorMessage, key)) {
                        status += key + ' ' + response.errorMessage[key] + '<br />';
                    }
                }
                var statusDiv = document.querySelector('#status');
                statusDiv.innerHTML = status;
                statusDiv.style.display = 'block';
            };
            xhr.send(new FormData(form));
        });
    });
</script>
