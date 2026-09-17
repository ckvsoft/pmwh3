<div class="pmwh3-content">
<?php
/**
 * Shared partial for rendering one Options section form.
 *
 * Layout: tab nav with all accessible sections at the top, then a
 *   shared grid for the whole section (label left, input + help
 *   right) -- columns align across all rows.
 *
 * Expected $this->data keys:
 *   section       - section identifier (system, web, email, ...)
 *   sectionLabel  - human-readable label
 *   settings      - array of {key => def} from Option_Model::getGroup
 *   edit_perm     - boolean: may the user save?
 *   tabs          - [sectionKey => label] of sections the user can see
 */
$section      = $this->data['section']      ?? '';
$sectionLabel = $this->data['sectionLabel'] ?? $section;
$settings     = $this->data['settings']     ?? [];
$edit_perm    = $this->data['edit_perm']    ?? false;
$tabs         = (array) ($this->data['tabs'] ?? []);
?>
<div class="widget options-widget">
    <div class="widget-header">
        <h3><?php echo __('Options'); ?></h3>
    </div>
    <div class="widget-body">

        <?php if (!empty($tabs)): ?>
            <nav class="tab-bar">
                <?php foreach ($tabs as $key => $label): ?>
                    <a href="<?php echo BASE_URI . 'pmwh3/options/' . urlencode($key); ?>"
                       class="tab-link<?php echo $section === $key ? ' active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <div class="tab-content">

        <?php if (empty($settings)): ?>
            <p><?php echo __('No settings to display in this section at the current configuration level.'); ?></p>
        <?php else: ?>

            <form action="<?php echo BASE_URI . 'pmwh3/options/save_' . htmlspecialchars($section); ?>"
                  method="post" class="options-form">

                <?php
                // Conditional visibility for the reCAPTCHA settings group.
                // Only triggered when CAPTCHA_TYPE is present in this section.
                // Rows carry data-captcha="<group>" attributes; a small
                // inline script hides/shows them based on the selected type.
                $captchaSection = isset($settings['CAPTCHA_TYPE']);
                $captchaFields  = [
                    'site'  => ['RECAPTCHA_SITE_KEY', 'RECAPTCHA_SECRET_KEY'],
                    'score' => ['RECAPTCHA_SCORE'],
                ];
                ?>

                <div class="options-grid">
                <?php foreach ($settings as $key => $def):
                    $type     = $def['type']    ?? 'text';
                    $value    = $def['value']   ?? '';
                    $label    = __($def['label'] ?? $key);
                    $help     = isset($def['help']) ? __($def['help']) : '';
                    $name     = 'config[' . htmlspecialchars($key) . ']';
                    $options  = $def['options'] ?? [];
                    $disabled = $edit_perm ? '' : ' disabled';
                    $fieldId  = 'opt_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                    $captchaGroup = '';
                    if ($captchaSection) {
                        foreach ($captchaFields as $grp => $keys) {
                            if (in_array($key, $keys, true)) { $captchaGroup = $grp; break; }
                        }
                    }
                ?>
                    <div class="option-row<?php echo $captchaGroup ? ' captcha-cond' : ''; ?>"
                        <?php echo $captchaGroup ? ' data-captcha="' . $captchaGroup . '"' : ''; ?>>
                        <div class="option-label-col">
                            <label for="<?php echo $fieldId; ?>" class="option-label">
                                <?php echo htmlspecialchars($label); ?>
                            </label>
                        </div>

                        <div class="option-control-col">
                            <div class="option-input">
                                <?php if ($type === 'textarea'): ?>
                                    <textarea id="<?php echo $fieldId; ?>" name="<?php echo $name; ?>"
                                              rows="4"<?php echo $disabled; ?>><?php echo htmlspecialchars((string) $value); ?></textarea>

                                <?php elseif ($type === 'select'): ?>
                                    <select id="<?php echo $fieldId; ?>" name="<?php echo $name; ?>"<?php echo $disabled; ?>>
                                        <?php foreach ($options as $optVal => $optLabel): ?>
                                            <option value="<?php echo htmlspecialchars((string) $optVal); ?>"
                                                <?php echo ((string) $optVal === (string) $value) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars((string) $optLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                <?php elseif ($type === 'multi_select'): ?>
                                    <select id="<?php echo $fieldId; ?>" name="<?php echo $name; ?>[]"
                                            multiple size="4"<?php echo $disabled; ?>>
                                        <?php
                                        $current = is_array($value) ? $value : explode(',', (string) $value);
                                        foreach ($options as $optVal => $optLabel):
                                        ?>
                                            <option value="<?php echo htmlspecialchars((string) $optVal); ?>"
                                                <?php echo in_array((string) $optVal, $current, true) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars((string) $optLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                <?php elseif ($type === 'checkbox'): ?>
                                    <label class="option-checkbox">
                                        <input type="checkbox" id="<?php echo $fieldId; ?>"
                                               name="<?php echo $name; ?>" value="Y"
                                            <?php echo ($value === 'Y') ? 'checked' : ''; ?><?php echo $disabled; ?>>
                                        <span><?php echo __('Enabled'); ?></span>
                                    </label>

                                <?php elseif ($type === 'password'): ?>
                                    <input type="password" id="<?php echo $fieldId; ?>" name="<?php echo $name; ?>"
                                        value="<?php echo htmlspecialchars((string) $value); ?>"<?php echo $disabled; ?>>

                                <?php elseif ($type === 'int'): ?>
                                    <input type="number" id="<?php echo $fieldId; ?>" name="<?php echo $name; ?>"
                                        value="<?php echo htmlspecialchars((string) $value); ?>" class="option-input-int"<?php echo $disabled; ?>>

                                <?php else: ?>
                                    <input type="text" id="<?php echo $fieldId; ?>" name="<?php echo $name; ?>"
                                        value="<?php echo htmlspecialchars((string) $value); ?>"<?php echo $disabled; ?>>
                                <?php endif; ?>
                            </div>

                            <?php if ($help !== ''): ?>
                                <div class="option-help"><?php echo htmlspecialchars($help); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div><!-- /options-grid -->

                <?php if ($edit_perm): ?>
                    <div class="option-actions">
                        <button type="submit" class="button small-action save"><?php echo __('Save'); ?></button>
                    </div>
                <?php endif; ?>
            </form>

            <?php if (!empty($captchaSection)): ?>
            <script>
            (function () {
                var typeSel = document.getElementById('opt_CAPTCHA_TYPE');
                if (!typeSel) { return; }
                function update() {
                    var type = typeSel.value || 'none';
                    var showSite  = (type === 'recaptcha_v2' || type === 'recaptcha_v3');
                    var showScore = (type === 'recaptcha_v3');
                    var rows = document.querySelectorAll('.captcha-cond');
                    for (var i = 0; i < rows.length; i++) {
                        var group = rows[i].getAttribute('data-captcha');
                        var show = (group === 'site' && showSite) || (group === 'score' && showScore);
                        rows[i].style.display = show ? '' : 'none';
                    }
                }
                typeSel.addEventListener('change', update);
                update();
            })();
            </script>
            <?php endif; ?>

        <?php endif; ?>

        </div><!-- /tab-content -->

    </div>
</div>
</div>

