<?php
// modules/pmwh3/view/options/web.php
//
// Renders the web options section. All the heavy lifting is done
// by the shared partial _form.php; sections only differ by the section
// id passed in via $data, which is set up in Options::web().
include __DIR__ . '/_form.php';
