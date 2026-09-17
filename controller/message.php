<?php

// modules/pmwh3/controller/message.php

use ckvsoft\Session;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\MessageManager;

class Message extends \ckvsoft\mvc\BaseController
{

    public function __construct()
    {
        parent::__construct();
        \pmwh3\Utils\AuthMiddleware::enforceLogin();

        // Settings-driven: respect USE_MESSAGE_SYSTEM. Mirrors the
        // gate in General::messages() so the URL can't be reached
        // when the system is disabled.
        if (\pmwh3\Config\LazyConfig::get('USE_MESSAGE_SYSTEM', 'Y') !== 'Y') {
            $this->location(BASE_URI . 'pmwh3/general/overview');
        }
    }

    private function render($view, $data = null)
    {
        $pmwh3menuHelper = $this->loadHelper("pmwh3/pmwh3menu");
        $pmwh3menu = $pmwh3menuHelper->getMenu($data['activeBox'] ?? null);

        $this->renderPage([
            ['view' => '/inc/header', 'data' => ['title' => __('Messages')]],
            ['view' => 'pmwh3/inc/navigation', 'data' => ['menu' => $pmwh3menu]],
            ['view' => $view, 'data' => ['data' => $data]],
            ['view' => '/inc/footer'],
                ],
                "<style>" . $this->loadHelper("css", ['method' => 'getCss', 'args' => ['inc/css/pmwh3.css']]) . "</style>",
                "<script>" . $this->loadScript("inc/js/pmwh3.js",
                [
                    'pmwh3ConfirmChanges' => \pmwh3\Config\LazyConfig::get('CONFIRM_CHANGES', 'Y') === 'Y',
                    'pmwh3ConfirmDelete'  => \pmwh3\Config\LazyConfig::get('CONFIRM_DELETE', 'Y') === 'Y',
                ]) . "</script>"
        );
    }

    private function flash(string $kind, string $title, string $msg, string $where = ''): void
    {
        $url = $where === ''
                ? BASE_URI . 'pmwh3/general/overview'
                : BASE_URI . 'pmwh3/message/' . $where;
        \ckvsoft\Auth::sendFlashRedirect($url, $kind, $title, $msg);
    }

    private function myName(): string
    {
        $cid = (int) (Session::getNs('pmwh3', 'customer_id') ?? 0);
        return (string) (CustomerUtil::getCustomerNameById($cid) ?? '');
    }

    public function index()
    {
        $this->inbox();
    }

    public function inbox()
    {
        $name = $this->myName();
        $this->render('pmwh3/message/inbox', [
            'activeBox' => 'message/inbox',
            'recipient' => $name,
            'messages'  => MessageManager::inboxFor($name),
            'mode'      => 'inbox',
        ]);
    }

    public function sent()
    {
        $name = $this->myName();
        $this->render('pmwh3/message/inbox', [
            'activeBox' => 'message/inbox',
            'recipient' => $name,
            'messages'  => MessageManager::sentBy($name),
            'mode'      => 'sent',
        ]);
    }

    public function view($id = 0)
    {
        $id = (int) $id;
        $row = MessageManager::getById($id);
        $name = $this->myName();
        if (!$row || ($row['recipient'] !== $name && $row['sender'] !== $name)) {
            $this->flash('error', __('Messages'), __('Message not found'));
            return;
        }
        // Auto-mark as read when the recipient opens it.
        if ($row['recipient'] === $name && ($row['msg_read'] ?? 'N') === 'N') {
            MessageManager::markRead($id);
            $row['msg_read'] = 'Y';
        }
        $this->render('pmwh3/message/view', [
            'activeBox' => 'message/inbox',
            'message'   => $row,
            'self'      => $name,
        ]);
    }

    public function compose()
    {
        $cid = (int) (Session::getNs('pmwh3', 'customer_id') ?? 0);

        // Recipient candidates: customers visible to me (= my own
        // hierarchy), excluding myself. Admin sees everyone.
        $candidates = [];
        foreach (\pmwh3\Utils\CustomerManager::listVisible($cid, 'all', true) as $c) {
            $candidates[] = (string) $c['customer'];
        }
        sort($candidates, SORT_NATURAL | SORT_FLAG_CASE);

        $input = new \ckvsoft\Input();
        $input->get('to')->get('subject');
        $in = $input->fetch();

        $this->render('pmwh3/message/compose', [
            'activeBox'  => 'message/inbox',
            'to'         => (string) ($in['to'] ?? ''),
            'subject'    => (string) ($in['subject'] ?? ''),
            'candidates' => $candidates,
        ]);
    }

    public function send()
    {
        $sender = $this->myName();
        $input = new \ckvsoft\Input();
        $input->post('recipient', true)->post('subject', true)->post('text', true);
        $in = $input->fetch();

        $recipient = trim((string) ($in['recipient'] ?? ''));
        $subject   = trim((string) ($in['subject']   ?? ''));
        $text      = (string) ($in['text']           ?? '');

        if ($recipient === '' || $subject === '' || $text === '') {
            $this->flash('error', __('Messages'), __('Recipient, subject and text are all required'), 'compose');
            return;
        }

        $result = MessageManager::send($sender, $recipient, $subject, $text);
        if ($result === false) {
            $this->flash('error', __('Messages'),
                    sprintf(__('Recipient "%s" does not exist'), $recipient), 'compose');
            return;
        }
        $this->flash('success', __('Messages'), __('Message sent'), 'inbox');
    }

    public function delete($id = 0)
    {
        $id = (int) $id;
        $row = MessageManager::getById($id);
        $name = $this->myName();
        if (!$row || ($row['recipient'] !== $name && $row['sender'] !== $name)) {
            $this->flash('error', __('Messages'), __('Message not found'));
            return;
        }
        MessageManager::delete($id);
        $this->flash('success', __('Messages'), __('Message deleted'));
    }
}
