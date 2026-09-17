/* pmwh3 — loaded through the PHP script-loader (loadScript).
 * The controllers pass the confirmation flags via loadScript($script, $data),
 * see render() in modules/pmwh3/controller/*.php.
 */
const PMWH3_CONFIRM_DELETE  = '<?= !empty($pmwh3ConfirmDelete) ? "true" : "false"; ?>';
const PMWH3_CONFIRM_CHANGES = '<?= !empty($pmwh3ConfirmChanges) ? "true" : "false"; ?>';

/* --- Sidebar ----------------------------------------------------------- */

function setSidebarState(open) {
    const sidebar = document.querySelector('.sidebar');
    const contents = document.querySelectorAll('.pmwh3-content');
    const btn = document.querySelector('.openbtn');

    if (btn !== null) {
        if (open) {
            sidebar.style.width = '250px';
            contents.forEach(c => c.style.marginLeft = '250px');
            btn.style.left = '135px';
            localStorage.setItem('sidebar', 'open');
        } else {
            sidebar.style.width = '0';
            contents.forEach(c => c.style.marginLeft = '0');
            btn.style.left = '0';
            localStorage.setItem('sidebar', 'closed');
        }
    }
}

function toggleNav() {
    const sidebar = document.querySelector('.sidebar');
    if (window.getComputedStyle(sidebar).width === '0px') {
        setSidebarState(true);
    } else {
        setSidebarState(false);
    }
}

/* --- Confirm dialogs via data-confirm ---------------------------------- *
 *  PHP renders  data-confirm="message"  and  data-confirm-type="delete|change"
 *  on <form> or <a> elements.  The flags above control whether the dialog
 *  fires; when the setting is off the attribute is simply not rendered.
 *
 *  <form  data-confirm="Delete customer?" data-confirm-type="delete">
 *  <a     data-confirm="Remove this user?" data-confirm-type="delete">
 *  <button data-confirm="Sign this zone?" data-confirm-type="change">
 */
function pmwh3_bindConfirm(root) {
    root.querySelectorAll('[data-confirm]').forEach(el => {
        const msg  = el.getAttribute('data-confirm');
        const kind = el.getAttribute('data-confirm-type') || 'delete';
        const enabled = kind === 'change' ? PMWH3_CONFIRM_CHANGES === 'true' : PMWH3_CONFIRM_DELETE === 'true';
        if (!enabled) return;

        const tag = el.tagName.toLowerCase();
        if (tag === 'form') {
            el.addEventListener('submit', function (e) {
                if (!confirm(msg)) e.preventDefault();
            });
        } else if (tag === 'a' || tag === 'button') {
            el.addEventListener('click', function (e) {
                if (!confirm(msg)) { e.preventDefault(); e.stopImmediatePropagation(); }
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    /* sidebar restore */
    const state = localStorage.getItem('sidebar');
    if (state === 'closed')      setSidebarState(false);
    else if (state === 'open')   setSidebarState(true);
    else                         setSidebarState(true);

    /* confirm binding */
    pmwh3_bindConfirm(document);
})
