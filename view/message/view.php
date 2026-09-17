<div class="pmwh3-content">
<?php
$msg  = (array)  ($this->data['message'] ?? []);
$self = (string) ($this->data['self']    ?? '');
$id   = (int)    ($msg['id']             ?? 0);

$isReceived = ($msg['recipient'] ?? '') === $self;
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo htmlspecialchars((string) ($msg['subject'] ?? __('(no subject)'))); ?></h3>
        <a href="<?php echo BASE_URI; ?>pmwh3/message/<?php echo $isReceived ? 'inbox' : 'sent'; ?>"
           class="button small-action cancel"><?php echo __('Back'); ?></a>
    </div>
    <div class="widget-body">

        <table class="widget-table">
            <tr><td><?php echo __('From'); ?></td>
                <td><?php echo htmlspecialchars((string) ($msg['sender'] ?? '')); ?></td></tr>
            <tr><td><?php echo __('To'); ?></td>
                <td><?php echo htmlspecialchars((string) ($msg['recipient'] ?? '')); ?></td></tr>
            <tr><td><?php echo __('Date'); ?></td>
                <td><?php echo htmlspecialchars((string) ($msg['date'] ?? '')); ?></td></tr>
        </table>

        <hr>
        <pre class="message-body"><?php
            echo htmlspecialchars((string) ($msg['text'] ?? ''));
        ?></pre>

        <div class="form-actions">
            <?php if ($isReceived): ?>
                <a href="<?php echo BASE_URI; ?>pmwh3/message/compose?to=<?php echo urlencode((string) $msg['sender']); ?>&subject=<?php echo urlencode('Re: ' . (string) $msg['subject']); ?>"
                   class="button small-action edit"><?php echo __('Reply'); ?></a>
            <?php endif; ?>
            <form method="post"
                  action="<?php echo BASE_URI; ?>pmwh3/message/delete/<?php echo $id; ?>"
                  class="inline-form"
                  data-confirm="<?php echo __('Delete this message?'); ?>"
                  data-confirm-type="delete">
                <button type="submit" class="button small-action delete"><?php echo __('Delete'); ?></button>
            </form>
        </div>

    </div>
</div>
</div>
