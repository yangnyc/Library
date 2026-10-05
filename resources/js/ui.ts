/**
 * Interface behaviour built on Bootstrap and jQuery: the collapsing menu,
 * closable notices and the question asked before something is deleted. Like
 * app.ts it only adds to pages that already work without it.
 */
import $ from 'jquery';
import 'bootstrap/js/dist/alert';
import 'bootstrap/js/dist/collapse';
import Modal from 'bootstrap/js/dist/modal';

// Mobile navigation: rendered open, collapsed here once the button can reopen it.
const $nav = $('#site-nav');
const $navToggle = $('[data-nav-toggle]');
if ($nav.length && $navToggle.length) {
    $nav.removeClass('show');
    $navToggle.prop('hidden', false);
}

$('.alert-dismissible .btn-close').prop('hidden', false);

// Deleting asks first. The form is then submitted as if its own button had
// been pressed, so validation and the other submit handlers still run once.
const $dialog = $('[data-confirm-dialog]');
if ($dialog.length) {
    const dialog = new Modal($dialog[0]);
    const $accept = $dialog.find('[data-confirm-accept]');
    let pending: HTMLButtonElement | null = null;

    $(document).on('click', 'button.button--danger', function (event) {
        const button = this as HTMLButtonElement;
        if (!button.form || button.type !== 'submit' || !button.form.checkValidity()) return;
        event.preventDefault();
        pending = button;
        $accept.text($(button).text().trim());
        dialog.show();
    });

    $accept.on('click', () => {
        const button = pending;
        pending = null;
        dialog.hide();
        button?.form?.requestSubmit(button);
    });

    // Bootstrap dispatches native events, which jQuery would read as namespaces.
    $dialog[0].addEventListener('hidden.bs.modal', () => {
        pending?.focus();
        pending = null;
    });
}

// Search suggestions are a React component, fetched only where a search box is.
const searchBoxes = document.querySelectorAll<HTMLElement>('[data-search-suggest]');
if (searchBoxes.length) {
    void import('./search/mount').then(({ mount }) => searchBoxes.forEach(mount));
}
