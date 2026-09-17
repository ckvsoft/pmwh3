<div class="pmwh3-content">
<?php
/**
 * Sessions / "who's online" view. Inspired by the BBS user list:
 * who is logged in right now, and what page / module they're on.
 *
 * Admin (cid=1) sees everyone; everyone else sees only their own
 * sessions (different browser / device).
 */
$sessions     = (array) ($this->data['sessions']      ?? []);
$cutoff       = (int)   ($this->data['cutoff']        ?? 15);
$isAdmin      = (bool)  ($this->data['is_admin']      ?? false);
$tableMissing = (bool)  ($this->data['table_missing'] ?? false);
?>
<div class="widget">
    <div class="widget-header">
        <h3><?php echo __('Active Sessions'); ?></h3>
    </div>
    <div class="widget-body">

        <?php if ($tableMissing): ?>
            <p><em><?php echo __('The pmwh3_activity table is not present in this database.'); ?></em></p>
            <p><small><?php echo __('Run the module updater (visit any pmwh3 page after deploying this update) to create it. Until the activity middleware writes rows on each request, this view will stay empty even after the table is created.'); ?></small></p>
        <?php else: ?>

        <p>
            <?php
            echo sprintf(
                $isAdmin
                    ? __('Showing all sessions active in the last %d minutes.')
                    : __('Showing your own sessions active in the last %d minutes.'),
                $cutoff
            );
            ?>
        </p>

        <?php if (empty($sessions)): ?>
            <p><em><?php echo __('No active sessions.'); ?></em></p>
        <?php else: ?>
            <table class="widget-table">
                <thead>
                    <tr>
                        <?php if ($isAdmin): ?>
                            <th><?php echo __('Customer'); ?></th>
                        <?php endif; ?>
                        <th><?php echo __('Module'); ?></th>
                        <th><?php echo __('Doing'); ?></th>
                        <th><?php echo __('IP'); ?></th>
                        <th><?php echo __('Started'); ?></th>
                        <th><?php echo __('Last seen'); ?></th>
                        <th><?php echo __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $s):
                        $sid = (int) ($s['id'] ?? 0);
                        $ua  = (string) ($s['user_agent']  ?? '');
                        // Shorten user agent for readability; full
                        // string in title attr.
                        $uaShort = strlen($ua) > 40 ? substr($ua, 0, 37) . '...' : $ua;
                    ?>
                        <tr>
                            <?php if ($isAdmin): ?>
                                <td><?php
                                    echo htmlspecialchars((string) ($s['customer'] ?? ''));
                                    echo ' <small>#' . (int) ($s['cid'] ?? 0) . '</small>';
                                ?></td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars((string) ($s['last_module'] ?? '')); ?></td>
                            <td>
                                <?php echo htmlspecialchars((string) ($s['last_action'] ?? '')); ?>
                                <?php if (!empty($s['last_url'])): ?>
                                    <small><br><?php echo htmlspecialchars((string) $s['last_url']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($s['ip'] ?? '')); ?>
                                <?php if ($ua !== ''): ?>
                                    <small title="<?php echo htmlspecialchars($ua); ?>"><br><?php echo htmlspecialchars($uaShort); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($s['created_at'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($s['updated_at'] ?? '')); ?></td>
                            <td>
                                <?php if ($sid > 0): ?>
                                    <form method="post"
                                          action="<?php echo BASE_URI; ?>pmwh3/general/kick_session/<?php echo $sid; ?>"
                                          class="inline-form"
                                          data-confirm="<?php echo __('Kick this session?'); ?>"
                                          data-confirm-type="delete">
                                        <button type="submit" class="button small-action delete"><?php echo __('Kick'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php endif; // !$tableMissing ?>

    </div>
</div>
</div>
