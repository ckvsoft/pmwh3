<div class="pmwh3-content">
<?php
/**
 * New / edit a WBList entry (scope + W/B + address).
 */
$domain = $this->data['domain'] ?? '';
$row    = $this->data['row'] ?? null;
$isEdit = is_array($row);
$action = $isEdit ? 'pmwh3/filtering/save_wblist/' . (int) ($row['id'] ?? 0)
                  : 'pmwh3/filtering/insert_wblist';
$scope  = $isEdit ? (string) ($row['scope'] ?? '') : '@' . $domain;
$type   = $isEdit ? (($row['list_type'] ?? 'W') === 'B' ? 'B' : 'W') : 'W';
$addr   = $isEdit ? (string) ($row['address'] ?? '') : '';
$comment= $isEdit ? (string) ($row['comment'] ?? '') : '';
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo $isEdit ? __('Edit whitelist / blacklist entry') : __('New whitelist / blacklist entry'); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/email/details/wblist"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
    </div>
    <div class="widget-body">
        <form action="<?php echo BASE_URI; ?><?php echo $action; ?>" method="post" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
            <div class="form-group">
                <label for="w_type"><?php echo __('List'); ?></label>
                <select id="w_type" name="list_type">
                    <option value="W" <?php echo $type === 'W' ? 'selected' : ''; ?>><?php echo __('Whitelist'); ?></option>
                    <option value="B" <?php echo $type === 'B' ? 'selected' : ''; ?>><?php echo __('Blacklist'); ?></option>
                </select>
            </div>
            <div class="form-group">
                <label for="w_scope"><?php echo __('Scope'); ?></label>
                <?php $scopes = $this->data['scopes'] ?? ['mailboxes' => [], 'aliases' => []]; ?>
                <select id="w_scope_sel" name="scope_pick"
                        onchange="document.getElementById('w_scope').value = this.value;">
                    <option value=""><?php echo __('— manual entry —'); ?></option>
                    <option value="@<?php echo htmlspecialchars($domain); ?>">
                        @<?php echo htmlspecialchars($domain); ?> (<?php echo __('whole domain'); ?>)
                    </option>
                    <?php foreach ($scopes['mailboxes'] as $mb): ?>
                        <option value="<?php echo htmlspecialchars((string) $mb['email']); ?>"
                            <?php echo strtolower((string) ($row['scope'] ?? '')) === strtolower((string) $mb['email']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $mb['email']); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (!empty($scopes['aliases'])): ?>
                    <optgroup label="<?php echo __('Aliases / forwards'); ?>">
                        <?php foreach ($scopes['aliases'] as $al): ?>
                            <option value="<?php echo htmlspecialchars((string) $al['source']); ?>"
                                <?php echo strtolower((string) ($row['scope'] ?? '')) === strtolower((string) $al['source']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $al['source']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if ($isEdit): ?>
                        <option value="<?php echo htmlspecialchars($scope); ?>" selected>
                            <?php echo htmlspecialchars($scope); ?> (<?php echo __('current'); ?>)
                        </option>
                    <?php endif; ?>
                </select>
                <input type="text" id="w_scope" name="scope" required
                       value="<?php echo htmlspecialchars($scope); ?>"
                       placeholder="@<?php echo htmlspecialchars($domain); ?> or user@<?php echo htmlspecialchars($domain); ?>">
                <small><?php echo sprintf(__('Per-mailbox / alias entries only apply to THIS address — one user\'s whitelist does not affect the others. Aliases resolve like their own mailbox at rspamd.'), htmlspecialchars($domain)); ?></small>
            </div>
            <div class="form-group">
                <label for="w_addr"><?php echo __('Address / domain / IP/CIDR'); ?></label>
                <input type="text" id="w_addr" name="address" required
                       value="<?php echo htmlspecialchars($addr); ?>"
                       placeholder="user@example.org | @example.org | 1.2.3.0/24">
            </div>
            <div class="form-group">
                <label for="w_comment"><?php echo __('Comment'); ?></label>
                <input type="text" id="w_comment" name="comment" maxlength="255"
                       value="<?php echo htmlspecialchars($comment); ?>">
            </div>
            <button type="submit" class="button small-action save"><?php echo __('Save'); ?></button>
            <a href="<?php echo BASE_URI; ?>pmwh3/email/details/wblist" class="button small-action cancel"><?php echo __('Cancel'); ?></a>
        </form>
    </div>
</div>
</div>
