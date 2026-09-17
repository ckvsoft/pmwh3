<div class="pmwh3-content">
<?php
$recipient = (string) ($this->data['recipient'] ?? '');
$messages  = (array)  ($this->data['messages']  ?? []);
$mode      = (string) ($this->data['mode']      ?? 'inbox');
$isInbox   = $mode === 'inbox';
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo $isInbox ? __('Inbox') : __('Sent'); ?></h3>
        <span>
            <a href="<?php echo BASE_URI; ?>pmwh3/message/<?php echo $isInbox ? 'sent' : 'inbox'; ?>"
               class="button small-action cancel"><?php echo $isInbox ? __('Sent') : __('Inbox'); ?></a>
            <a href="<?php echo BASE_URI; ?>pmwh3/message/compose"
               class="button small-action create"><?php echo __('Compose'); ?></a>
        </span>
    </div>
    <div class="widget-body">

        <?php if ($recipient === ''): ?>
            <p><em><?php echo __('Could not resolve your customer name.'); ?></em></p>
        <?php elseif (empty($messages)): ?>
            <p><em><?php echo $isInbox ? __('No messages.') : __('Nothing sent.'); ?></em></p>
        <?php else: ?>
            <div class="paginated" data-per-page="20">
<table class="widget-table">
                <thead>
                    <tr>
                        <?php if ($isInbox): ?>
                            <th><?php echo __('Status'); ?></th>
                            <th><?php echo __('From'); ?></th>
                        <?php else: ?>
                            <th><?php echo __('To'); ?></th>
                        <?php endif; ?>
                        <th><?php echo __('Subject'); ?></th>
                        <th><?php echo __('Date'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $m):
                        $id    = (int)    ($m['id']        ?? 0);
                        $isNew = $isInbox && (($m['msg_read'] ?? 'N') === 'N');
                    ?>
                        <tr<?php echo $isNew ? ' class="row-current"' : ''; ?>>
                            <?php if ($isInbox): ?>
                                <td><?php echo $isNew ? '<strong>' . __('NEW') . '</strong>' : __('read'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($m['sender'] ?? '')); ?></td>
                            <?php else: ?>
                                <td><?php echo htmlspecialchars((string) ($m['recipient'] ?? '')); ?></td>
                            <?php endif; ?>
                            <td>
                                <a href="<?php echo BASE_URI; ?>pmwh3/message/view/<?php echo $id; ?>">
                                    <?php echo htmlspecialchars((string) ($m['subject'] ?? '')); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($m['date'] ?? '')); ?></td>
                            <td>
                                <a href="<?php echo BASE_URI; ?>pmwh3/message/view/<?php echo $id; ?>"
                                   class="button small-action edit"><?php echo __('Open'); ?></a>
                                <form method="post"
                                      action="<?php echo BASE_URI; ?>pmwh3/message/delete/<?php echo $id; ?>"
                                      class="inline-form"
                                      data-confirm="<?php echo __('Delete this message?'); ?>"
                                      data-confirm-type="delete">
                                    <button type="submit" class="button small-action delete"><?php echo __('Delete'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
</div>
        <?php endif; ?>

    </div>
</div>
</div>
