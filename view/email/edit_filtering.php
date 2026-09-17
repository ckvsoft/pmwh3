<div class="pmwh3-content">
<?php
/**
 * New / edit a filtering policy row (scope + two thresholds,
 * empty = inherit).
 */
$domain = $this->data['domain'] ?? '';
$row    = $this->data['row'] ?? null;
$isEdit = is_array($row);
$action = $isEdit ? 'pmwh3/filtering/save_filtering/' . (int) ($row['id'] ?? 0)
                  : 'pmwh3/filtering/insert_filtering';
$scope  = $isEdit ? (string) ($row['scope'] ?? '') : '@' . $domain;
$tag    = $isEdit ? (string) ($row['tag_threshold'] ?? '') : '';
$kill   = $isEdit ? (string) ($row['kill_threshold'] ?? '') : '';
$scopehint = $isEdit ? '' : __('@Mailbox or @domain — empty already proposes this domain.');
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo $isEdit ? __('Edit filtering policy') : __('New filtering policy'); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/email/details/filtering"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
    </div>
    <div class="widget-body">
        <form action="<?php echo BASE_URI; ?><?php echo $action; ?>" method="post" data-confirm="<?php echo __('Save changes?'); ?>" data-confirm-type="change">
            <div class="form-group">
                <label for="f_scope"><?php echo __('Scope'); ?></label>
                <?php $scopes = $this->data['scopes'] ?? ['mailboxes' => [], 'aliases' => []]; ?>
                <select id="f_scope_sel" name="scope_pick"
                        onchange="document.getElementById('f_scope').value = this.value;">
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
                <input type="text" id="f_scope" name="scope" required
                       value="<?php echo htmlspecialchars($scope); ?>"
                       placeholder="user@<?php echo htmlspecialchars($domain); ?> or @<?php echo htmlspecialchars($domain); ?>">
                <small><?php echo htmlspecialchars($scopehint); ?></small>
            </div>
            <div class="form-group">
                <label for="f_tag"><?php echo __('Spam tag score (add_header) — empty = inherit'); ?></label>
                <input type="number" step="0.1" min="0" max="99.9" id="f_tag" name="tag_threshold"
                       value="<?php echo htmlspecialchars($tag); ?>" placeholder="(inherit)">
            </div>
            <div class="form-group">
                <label for="f_kill"><?php echo __('Reject score — empty = inherit'); ?></label>
                <input type="number" step="0.1" min="0" max="99.9" id="f_kill" name="kill_threshold"
                       value="<?php echo htmlspecialchars($kill); ?>" placeholder="(inherit)">
            </div>
            <button type="submit" class="button small-action save"><?php echo __('Save'); ?></button>
            <a href="<?php echo BASE_URI; ?>pmwh3/email/details/filtering" class="button small-action cancel"><?php echo __('Cancel'); ?></a>
        </form>
    </div>
</div>
</div>
