<div class="pmwh3-content">
    <div class="widget">
        <h2>
            <?=
            isset($this->data['domain']) ?
                    sprintf(_('Monthly overview for %s'), htmlspecialchars($this->data['domain'])) : __('Monthly overview per day')
            ?>
        </h2>

        <a href="<?= BASE_URI ?>pmwh3/general/traffic">&larr; <?= __('Back to traffic') ?></a>
        <div id="trafficlist" class="paginated">
            <table class="widget-table">
                <tr>
                    <th><?= __('Date') ?></th>
                    <th><?= __('Web') ?></th>
                    <th><?= __('FTP') ?></th>
                    <th><?= __('Email') ?></th>
                    <th><?= __('Overall') ?></th>
                </tr>
                <?php foreach ($this->data['val'] as $day): ?>
                    <tr>
                        <td><?= htmlspecialchars($day['timestamp']) ?></td>
                        <td align="right"><?= $day['apache_hr'] ?></td>
                        <td align="right"><?= $day['ftp_hr'] ?></td>
                        <td align="right"><?= $day['mail_hr'] ?></td>
                        <td align="right"><?= $day['sum_hr'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
