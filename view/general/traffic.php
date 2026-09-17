<div class="pmwh3-content">
    <div class="widget">
        <h2><?= __('Monthly overview over all your customers') ?></h2>

        <form method="post">
            <table class="widget-table">
                <tr>
                    <th><?= __('Select') ?></th>
                    <th><?= __('Month') ?></th>
                    <th align="right"><?= __('Web') ?></th>
                    <th align="right"><?= __('FTP') ?></th>
                    <th align="right"><?= __('Email') ?></th>
                    <th align="right"><?= __('Overall') ?></th>
                </tr>
                <tr>
                    <td>
                        <select name="date" onchange="this.form.submit()">
                            <option value="" <?= ($this->data['selected'] === '' ? 'selected' : '') ?>>
                                <?= __('All') ?>
                            </option>
                            <?php foreach ($this->data['dates'] as $month): ?>
                                <option value="<?= $month ?>" <?= ($month === $this->data['selected'] ? 'selected' : '') ?>>
                                    <?= $month ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><?= $this->data['selected'] ?: __('All') ?></td>
                    <td align="right"><?= $this->data['sum']['apache_hr'] ?? '0 B' ?></td>
                    <td align="right"><?= $this->data['sum']['ftp_hr'] ?? '0 B' ?></td>
                    <td align="right"><?= $this->data['sum']['mail_hr'] ?? '0 B' ?></td>
                    <td align="right"><?= $this->data['sum']['sum_hr'] ?? '0 B' ?></td>
                </tr>
            </table>
        </form>
    </div>

    <?php if (!empty($this->data['customer'])): ?>
        <div class="widget">
            <h2><?= __('Monthly Overview per Customer') ?></h2>
            <table class="widget-table">
                <tr>
                    <th><?= __('Customer') ?></th>
                    <th><?= __('Month') ?></th>
                    <th><?= __('Web') ?></th>
                    <th><?= __('FTP') ?></th>
                    <th><?= __('Email') ?></th>
                    <th><?= __('Overall') ?></th>
                </tr>
                <?php foreach ($this->data['customer'] as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['name'] ?? '') ?></td>
                        <td><?= $c['month'] ?? '' ?></td>
                        <td align="right"><?= $c['apache_hr'] ?? '0 B' ?></td>
                        <td align="right"><?= $c['ftp_hr'] ?? '0 B' ?></td>
                        <td align="right"><?= $c['mail_hr'] ?? '0 B' ?></td>
                        <td align="right"><?= $c['sum_hr'] ?? '0 B' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($this->data['domains'])): ?>
        <div class="widget">
            <h2><?= __('Monthly Overview per Domain') ?></h2>
            <table class="widget-table">
                <tr>
                    <th><?= __('Domain') ?></th>
                    <th><?= __('Month') ?></th>
                    <th><?= __('Web') ?></th>
                    <th><?= __('FTP') ?></th>
                    <th><?= __('Email') ?></th>
                    <th><?= __('Overall') ?></th>
                </tr>
                <?php foreach ($this->data['domains'] as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['domain']) ?>
                            <a href="traffic_details/<?= $d['domain'] ?>/<?= $d['month'] ?>">(<?= __('Details') ?>)</a>
                        </td>
                        <td><?= $d['month'] ?></td>
                        <td align="right"><?= $d['apache_hr'] ?></td>
                        <td align="right"><?= $d['ftp_hr'] ?></td>
                        <td align="right"><?= $d['mail_hr'] ?></td>
                        <td align="right"><?= $d['sum_hr'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</div>
