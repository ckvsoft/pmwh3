<?php
// modules/pmwh3/view/options/email.php
//
// Renders the email options section. All the heavy lifting is done
// by the shared partial _form.php; sections only differ by the section
// id passed in via $data, which is set up in Options::email().
include __DIR__ . '/_form.php';
