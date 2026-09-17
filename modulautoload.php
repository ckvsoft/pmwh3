<?php

\pmwh3\i18n\Pmwh3I18n::init();

// pmwh3 error logging runtime (Options > Errorlog). Fail-safe, no-op
// on CLI; settings in SettingsSchema GROUP_ERRORLOG (ERRORLOG_*).
\pmwh3\Utils\ErrorHandler::register();
