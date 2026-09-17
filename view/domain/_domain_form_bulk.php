<?php
// $customer, $ips, $start, $end sind direkt verfügbar.
?>
<form action="/domain/insertDomain" method="post" data-confirm="<?php echo __('Import domains?'); ?>" data-confirm-type="change">
    <table class="widget-table">
        <tbody>
            <tr>
                <th align="right">&nbsp;<?php echo __("Accountable"); ?></th>
                <td class="widget-value">
                    <select name="config[cid]">
                        <?php foreach ($customer as $cust) : ?>
                            <option value="<?php echo $cust['cid']; ?>"><?php echo $cust['customer']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th align="right" valign="top">&nbsp;<?php echo __("Domains<br />One per line"); ?></th>
                <td class="widget-value"><textarea cols="45" rows="10" name="config[domain]"></textarea></td>
            </tr>
            <tr>
                <th align="right">&nbsp;<?php echo __("IP Address<br />All domains will share this IP"); ?></th>
                <td class="widget-value">
                    <select name="config[ip]">
                        <?php foreach ($ips as $ip) : ?>
                            <option value="<?php echo $ip; ?>"><?php echo $ip; ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th align="right">&nbsp;<?php echo __("Registration start"); ?></th>
                <td class="widget-value"><input type="text" name="config[reg_start]" value="<?php echo $start; ?>"></td>
            </tr>
            <tr>
                <th align="right">&nbsp;<?php echo __("Registration end"); ?></th>
                <td class="widget-value"><input type="text" name="config[reg_end]" value="<?php echo $end; ?>"></td>
            </tr>
            <tr>
                <th align="right">&nbsp;<?php echo __("Hosting start"); ?></th>
                <td class="widget-value"><input type="text" name="config[host_start]" value="<?php echo $start; ?>"></td>
            </tr>
            <tr>
                <th align="right">&nbsp;<?php echo __("Hosting end"); ?></th>
                <td class="widget-value"><input type="text" name="config[host_end]" value="<?php echo $end; ?>"></td>
            </tr>
            <tr>
                <td align="right" colspan="2" class="widget-value">
                    <input type="hidden" name="config[how]" value="bulk" />
                    <button class="createDomainButton" type="submit"><img src="images/icons/new.png" border="0"/> <?php echo __("Create"); ?></button>
                </td>
            </tr>
        </tbody>
    </table>
</form>