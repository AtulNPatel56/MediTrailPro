document.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('[data-print]');
    if (button) {
        window.print();
    }
});

// Confirmation prompts for destructive forms: <form data-confirm="Are you sure?">
document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute && event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
